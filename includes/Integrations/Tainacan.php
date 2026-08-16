<?php
/**
 * Tainacan integration: resolve meta labels from metadatum posts.
 *
 * Tainacan item post types look like `tnc_col_{collection_id}_item`. Values are
 * stored under post meta keys that are the metadatum post ID.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Integrations;

/**
 * Class Tainacan
 */
final class Tainacan {

	/**
	 * Register hooks when Tainacan appears to be available.
	 */
	public static function register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'vikus_viewer_meta_keys_for_post_type', array( self::class, 'filter_meta_keys' ), 10, 2 );
		add_filter( 'vikus_viewer_meta_key_label', array( self::class, 'filter_meta_key_label' ), 10, 4 );
		add_filter( 'vikus_viewer_meta_terminology', array( self::class, 'filter_meta_terminology' ), 10, 2 );
		add_filter( 'vikus_viewer_resolve_meta_value', array( self::class, 'filter_resolve_meta_value' ), 10, 5 );
	}

	/**
	 * Whether Tainacan is installed/active enough to resolve labels.
	 */
	public static function is_active(): bool {
		return defined( 'TAINACAN_VERSION' )
			|| class_exists( '\Tainacan\Entities\Metadatum' )
			|| post_type_exists( 'tainacan-collection' )
			|| post_type_exists( 'tainacan-metadatum' );
	}

	/**
	 * Whether a post type is a Tainacan collection items CPT.
	 *
	 * @param string $post_type Post type.
	 */
	public static function is_item_post_type( string $post_type ): bool {
		return 1 === preg_match( '/^tnc_col_\d+_item$/', $post_type );
	}

	/**
	 * Whether a post looks like a Tainacan metadatum definition.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function is_metadatum_post( \WP_Post $post ): bool {
		return 'tainacan-metadatum' === $post->post_type;
	}

	/**
	 * Drop private/non-public Tainacan metadata from the picker.
	 *
	 * WordPress postmeta itself has no visibility; Tainacan stores that as the
	 * metadatum post status (`publish` = public, `private` = private).
	 *
	 * @param string[] $keys      Meta keys.
	 * @param string   $post_type Post type.
	 * @return string[]
	 */
	public static function filter_meta_keys( array $keys, string $post_type ): array {
		if ( ! self::is_item_post_type( $post_type ) ) {
			return $keys;
		}

		$filtered = array();
		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( ! ctype_digit( $key ) ) {
				$filtered[] = $key;
				continue;
			}

			$meta_post = get_post( (int) $key );
			if ( ! $meta_post instanceof \WP_Post || ! self::is_metadatum_post( $meta_post ) ) {
				$filtered[] = $key;
				continue;
			}

			// Only offer publicly published metadata definitions.
			if ( 'publish' === $meta_post->post_status ) {
				$filtered[] = $key;
			}
		}

		return $filtered;
	}

	/**
	 * Provide metadatum titles for numeric meta keys on Tainacan item types.
	 *
	 * @param string               $label     Existing label.
	 * @param string               $key       Meta key.
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $args      Registered meta args.
	 */
	public static function filter_meta_key_label( string $label, string $key, string $post_type, array $args = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( '' !== $label || ! self::is_item_post_type( $post_type ) || ! ctype_digit( $key ) ) {
			return $label;
		}

		$meta_post = get_post( (int) $key );
		if ( ! $meta_post instanceof \WP_Post || '' === $meta_post->post_title ) {
			return $label;
		}

		if ( self::is_metadatum_post( $meta_post ) ) {
			return $meta_post->post_title;
		}

		if ( class_exists( '\Tainacan\Entities\Metadatum' ) ) {
			try {
				$metadatum = new \Tainacan\Entities\Metadatum( (int) $key );
				$name      = method_exists( $metadatum, 'get_name' ) ? (string) $metadatum->get_name() : '';
				if ( '' !== $name ) {
					return $name;
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Ignore invalid metadatum IDs.
			}
		}

		return $label;
	}

	/**
	 * Refer to Tainacan item meta as "metadata" in the admin UI.
	 *
	 * @param array<string, string> $terms     Terminology.
	 * @param string                $post_type Post type.
	 * @return array<string, string>
	 */
	public static function filter_meta_terminology( array $terms, string $post_type ): array {
		if ( ! self::is_item_post_type( $post_type ) ) {
			return $terms;
		}

		$terms['source'] = __( 'Metadata', 'vikus-viewer-embed' );

		return $terms;
	}

	/**
	 * Resolve item metadata via Tainacan for detail/CSV export.
	 *
	 * Uses get_value_as_string() for type "text", otherwise get_value_as_html()
	 * (e.g. markdown sidebar fields). Keyword and year sources still use raw
	 * post meta and do not pass through this filter.
	 *
	 * @param string               $resolved Default raw meta string.
	 * @param \WP_Post             $post     Source post.
	 * @param string               $key      Meta key (metadatum ID for items).
	 * @param array<string, mixed> $settings Collection settings.
	 * @param string               $type     Detail field type.
	 */
	public static function filter_resolve_meta_value( string $resolved, \WP_Post $post, string $key, array $settings = array(), string $type = 'text' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( ! self::is_item_post_type( $post->post_type ) || ! ctype_digit( $key ) ) {
			return $resolved;
		}

		if ( ! class_exists( '\Tainacan\Entities\Item' )
			|| ! class_exists( '\Tainacan\Entities\Metadatum' )
			|| ! class_exists( '\Tainacan\Entities\Item_Metadata_Entity' )
		) {
			return $resolved;
		}

		try {
			$item      = new \Tainacan\Entities\Item( $post->ID );
			$metadatum = new \Tainacan\Entities\Metadatum( (int) $key );
			$entity    = new \Tainacan\Entities\Item_Metadata_Entity( $item, $metadatum );

			if ( method_exists( $entity, 'has_value' ) && ! $entity->has_value() ) {
				return '';
			}

			if ( 'text' === $type ) {
				if ( ! method_exists( $entity, 'get_value_as_string' ) ) {
					return $resolved;
				}
				$value = $entity->get_value_as_string();
				return is_string( $value ) ? $value : $resolved;
			}

			if ( ! method_exists( $entity, 'get_value_as_html' ) ) {
				return $resolved;
			}

			$html = $entity->get_value_as_html();
			return is_string( $html ) ? $html : $resolved;
		} catch ( \Throwable $e ) {
			return $resolved;
		}
	}
}
