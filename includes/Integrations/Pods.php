<?php
/**
 * Pods Framework integration: resolve meta labels from pod field definitions.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Integrations;

/**
 * Class Pods
 */
final class Pods {

	/**
	 * Register hooks when Pods is available.
	 */
	public static function register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'vikus_viewer_meta_key_label', array( self::class, 'filter_meta_key_label' ), 10, 4 );
		add_filter( 'vikus_viewer_meta_terminology', array( self::class, 'filter_meta_terminology' ), 20, 2 );
		add_filter( 'vikus_viewer_resolve_meta_value', array( self::class, 'filter_resolve_meta_value' ), 20, 5 );
	}

	/**
	 * Whether Pods is loaded.
	 */
	public static function is_active(): bool {
		return function_exists( 'pods' ) || defined( 'PODS_VERSION' );
	}

	/**
	 * Whether this post type is managed as a Pod.
	 *
	 * @param string $post_type Post type.
	 */
	public static function applies_to_post_type( string $post_type ): bool {
		if ( '' === $post_type || Tainacan::is_item_post_type( $post_type ) ) {
			return false;
		}
		if ( function_exists( 'pods_api' ) ) {
			try {
				$api = pods_api();
				if ( is_object( $api ) && method_exists( $api, 'pod_exists' ) ) {
					return (bool) $api->pod_exists( array( 'name' => $post_type ) );
				}
			} catch ( \Throwable $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch
				// Fall through.
			}
		}
		if ( ! function_exists( 'pods' ) ) {
			return false;
		}
		try {
			$pod = pods( $post_type );
			return is_object( $pod ) && method_exists( $pod, 'valid' ) ? (bool) $pod->valid() : is_object( $pod );
		} catch ( \Throwable $e ) {
			return false;
		}
	}

	/**
	 * Provide Pods field labels when core register_meta has none.
	 *
	 * @param string               $label     Existing label.
	 * @param string               $key       Meta key.
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $args      Registered meta args.
	 */
	public static function filter_meta_key_label( string $label, string $key, string $post_type, array $args = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( '' !== $label || ! function_exists( 'pods' ) || '' === $post_type ) {
			return $label;
		}

		try {
			$pod = pods( $post_type );
		} catch ( \Throwable $e ) {
			return $label;
		}

		if ( ! is_object( $pod ) || ! method_exists( $pod, 'fields' ) ) {
			return $label;
		}

		$candidates = array( $key );
		if ( 0 === strpos( $key, '_' ) ) {
			$candidates[] = substr( $key, 1 );
		}

		foreach ( $candidates as $candidate ) {
			$field_label = $pod->fields( $candidate, 'label' );
			if ( is_string( $field_label ) && '' !== $field_label ) {
				return $field_label;
			}
		}

		return $label;
	}

	/**
	 * Refer to Pods post meta as "fields" in the admin UI.
	 *
	 * @param array<string, string> $terms     Terminology.
	 * @param string                $post_type Post type.
	 * @return array<string, string>
	 */
	public static function filter_meta_terminology( array $terms, string $post_type ): array {
		if ( ! self::applies_to_post_type( $post_type ) ) {
			return $terms;
		}
		// Prefer ACF wording when both ACF and Pods apply to the same type.
		if ( Acf::applies_to_post_type( $post_type ) ) {
			return $terms;
		}

		$terms['source'] = __( 'Pods fields', 'vikus-viewer-embed' );

		return $terms;
	}

	/**
	 * Resolve detail/CSV meta via Pods display() for multi-value / relationship fields.
	 *
	 * display() already joins arrays into a human-readable string (serial comma).
	 * Runs after ACF (priority 20) so ACF wins when both define the same key.
	 *
	 * @param string               $resolved Default raw meta string (or ACF result).
	 * @param \WP_Post             $post     Source post.
	 * @param string               $key      Meta / field name.
	 * @param array<string, mixed> $settings Collection settings.
	 * @param string               $type     Detail field type.
	 */
	public static function filter_resolve_meta_value( string $resolved, \WP_Post $post, string $key, array $settings = array(), string $type = 'text' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( Tainacan::is_item_post_type( $post->post_type ) || ! function_exists( 'pods' ) ) {
			return $resolved;
		}

		// Prefer ACF when it already resolved this key.
		if ( function_exists( 'acf_get_field' ) ) {
			$acf_candidates = array( $key );
			if ( 0 === strpos( $key, '_' ) ) {
				$acf_candidates[] = substr( $key, 1 );
			}
			foreach ( $acf_candidates as $candidate ) {
				$field = acf_get_field( $candidate );
				if ( is_array( $field ) && ! empty( $field['name'] ) ) {
					return $resolved;
				}
			}
		}

		try {
			$pod = pods( $post->post_type, $post->ID );
		} catch ( \Throwable $e ) {
			return $resolved;
		}

		if ( ! is_object( $pod ) || ! method_exists( $pod, 'fields' ) || ! method_exists( $pod, 'display' ) ) {
			return $resolved;
		}

		$field_name = null;
		$candidates = array( $key );
		if ( 0 === strpos( $key, '_' ) ) {
			$candidates[] = substr( $key, 1 );
		}
		foreach ( $candidates as $candidate ) {
			$label = $pod->fields( $candidate, 'label' );
			if ( is_string( $label ) && '' !== $label ) {
				$field_name = $candidate;
				break;
			}
			// fields() may return false for unknown; also accept when field config exists.
			$field = $pod->fields( $candidate );
			if ( is_array( $field ) && ! empty( $field ) ) {
				$field_name = $candidate;
				break;
			}
		}

		if ( null === $field_name ) {
			return $resolved;
		}

		try {
			$displayed = $pod->display( $field_name );
		} catch ( \Throwable $e ) {
			return $resolved;
		}

		if ( false === $displayed || null === $displayed ) {
			return '';
		}

		return is_string( $displayed ) ? $displayed : (string) $displayed;
	}
}
