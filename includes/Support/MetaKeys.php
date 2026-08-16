<?php
/**
 * Discover post meta keys used by a post type (for admin mapping UI).
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

/**
 * Class MetaKeys
 */
final class MetaKeys {

	/**
	 * Meta keys that are almost never useful as Vikus field sources.
	 *
	 * @var string[]
	 */
	private const NOISE_KEYS = array(
		'_edit_lock',
		'_edit_last',
		'_wp_old_slug',
		'_wp_desired_post_slug',
		'_wp_trash_meta_status',
		'_wp_trash_meta_time',
		'_encloseme',
		'_pingme',
		'_wp_page_template',
		'_dp_original',
		'_wp_attached_file',
		'_wp_attachment_metadata',
		'_wp_attachment_image_alt',
		'_wp_attachment_context',
	);

	/**
	 * Meta keys observed for a post type (registered + used in DB).
	 *
	 * @param string $post_type Post type name.
	 * @return array<int, array{key:string,label:string}> Sorted unique entries.
	 */
	public static function for_post_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		if ( '' === $post_type || ! post_type_exists( $post_type ) ) {
			return array();
		}

		$cache_key = 'vikus_meta_keys_v3_' . $post_type;
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$registered = array();
		foreach ( array( $post_type, '' ) as $object_subtype ) {
			foreach ( get_registered_meta_keys( 'post', $object_subtype ) as $key => $args ) {
				$registered[ (string) $key ] = is_array( $args ) ? $args : array();
			}
		}

		$keys = array();
		foreach ( array_keys( $registered ) as $key ) {
			$keys[ $key ] = true;
		}
		foreach ( self::distinct_from_database( $post_type ) as $key ) {
			$keys[ $key ] = true;
		}

		/**
		 * Filter discovered meta keys for a post type.
		 *
		 * Integrations may add or remove keys before labels are resolved.
		 *
		 * @param string[] $keys      Meta keys.
		 * @param string   $post_type Post type.
		 */
		$key_list = apply_filters( 'vikus_viewer_meta_keys_for_post_type', array_keys( $keys ), $post_type );
		$key_list = array_values(
			array_filter(
				array_map( 'strval', (array) $key_list ),
				static function ( string $key ): bool {
					return '' !== $key && ! in_array( $key, self::NOISE_KEYS, true );
				}
			)
		);

		$entries = array();
		foreach ( $key_list as $key ) {
			$args      = $registered[ $key ] ?? array();
			$entries[] = array(
				'key'   => $key,
				'label' => self::resolve_label( $key, $post_type, $args ),
			);
		}

		usort(
			$entries,
			static function ( array $a, array $b ): int {
				$la = '' !== $a['label'] ? $a['label'] : $a['key'];
				$lb = '' !== $b['label'] ? $b['label'] : $b['key'];
				return strnatcasecmp( $la, $lb );
			}
		);

		set_transient( $cache_key, $entries, 5 * MINUTE_IN_SECONDS );

		return $entries;
	}

	/**
	 * Map of public source post types → labeled meta keys.
	 *
	 * @return array<string, array<int, array{key:string,label:string}>>
	 */
	public static function by_post_type(): array {
		$map   = array();
		$types = get_post_types( array( 'public' => true ), 'names' );
		unset( $types[ \VikusViewer\PostType\Collection::POST_TYPE ] );
		foreach ( array_keys( $types ) as $post_type ) {
			$map[ $post_type ] = self::for_post_type( $post_type );
		}
		return $map;
	}

	/**
	 * Resolve a human label for a meta key (core register_meta + integrations).
	 *
	 * Core only uses WordPress-registered meta metadata. Third-party plugins
	 * should hook `vikus_viewer_meta_key_label`.
	 *
	 * @param string               $key       Meta key.
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $args      Registered meta args (if any).
	 */
	public static function resolve_label( string $key, string $post_type, array $args = array() ): string {
		$label = '';

		if ( ! empty( $args['label'] ) && is_string( $args['label'] ) ) {
			$label = $args['label'];
		} elseif ( ! empty( $args['description'] ) && is_string( $args['description'] ) ) {
			$label = $args['description'];
		} elseif ( isset( $args['show_in_rest'] ) && is_array( $args['show_in_rest'] ) ) {
			$title = $args['show_in_rest']['schema']['title'] ?? '';
			if ( is_string( $title ) && '' !== $title ) {
				$label = $title;
			}
		}

		/**
		 * Filter the admin UI label for a meta key.
		 *
		 * Return a non-empty string to provide or override the label. Core may
		 * already set a label from register_meta(); integrations should usually
		 * only fill when $label is empty.
		 *
		 * @param string               $label     Label from register_meta (may be empty).
		 * @param string               $key       Meta key.
		 * @param string               $post_type Source post type.
		 * @param array<string, mixed> $args      Registered meta args for this key.
		 */
		$filtered = apply_filters( 'vikus_viewer_meta_key_label', $label, $key, $post_type, $args );

		return is_string( $filtered ) ? $filtered : $label;
	}

	/**
	 * Distinct meta_key values from posts of this type (sampled via SQL).
	 *
	 * @param string $post_type Post type.
	 * @return string[]
	 */
	private static function distinct_from_database( string $post_type ): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$keys = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT pm.meta_key
				FROM {$wpdb->postmeta} pm
				INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE p.post_type = %s
					AND p.post_status IN ('publish','private','draft','pending')
					AND pm.meta_key NOT LIKE %s
				ORDER BY pm.meta_key ASC
				LIMIT 400",
				$post_type,
				'\_wp\_%'
			)
		);

		if ( ! is_array( $keys ) ) {
			return array();
		}

		return array_map( 'strval', $keys );
	}
}
