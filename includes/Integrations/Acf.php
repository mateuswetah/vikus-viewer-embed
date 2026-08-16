<?php
/**
 * Advanced Custom Fields integration: resolve meta labels via acf_get_field().
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Integrations;

use VikusViewer\Export\FieldResolver;

/**
 * Class Acf
 */
final class Acf {

	/**
	 * Register hooks when ACF is available.
	 */
	public static function register_hooks(): void {
		if ( ! self::is_active() ) {
			return;
		}

		add_filter( 'vikus_viewer_meta_key_label', array( self::class, 'filter_meta_key_label' ), 10, 4 );
		add_filter( 'vikus_viewer_meta_terminology', array( self::class, 'filter_meta_terminology' ), 20, 2 );
		add_filter( 'vikus_viewer_resolve_meta_value', array( self::class, 'filter_resolve_meta_value' ), 10, 5 );
	}

	/**
	 * Whether ACF (free or Pro) is loaded.
	 */
	public static function is_active(): bool {
		return function_exists( 'acf_get_field' ) || class_exists( 'ACF' );
	}

	/**
	 * Whether this post type has ACF field groups assigned.
	 *
	 * @param string $post_type Post type.
	 */
	public static function applies_to_post_type( string $post_type ): bool {
		if ( '' === $post_type || ! function_exists( 'acf_get_field_groups' ) ) {
			return false;
		}
		// Prefer Tainacan wording on Tainacan item types.
		if ( Tainacan::is_item_post_type( $post_type ) ) {
			return false;
		}
		$groups = acf_get_field_groups( array( 'post_type' => $post_type ) );
		return is_array( $groups ) && ! empty( $groups );
	}

	/**
	 * Provide ACF field labels when core register_meta has none.
	 *
	 * @param string               $label     Existing label.
	 * @param string               $key       Meta key.
	 * @param string               $post_type Post type.
	 * @param array<string, mixed> $args      Registered meta args.
	 */
	public static function filter_meta_key_label( string $label, string $key, string $post_type, array $args = array() ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( '' !== $label || ! function_exists( 'acf_get_field' ) ) {
			return $label;
		}

		$candidates = array( $key );
		if ( 0 === strpos( $key, '_' ) ) {
			$candidates[] = substr( $key, 1 );
		}

		foreach ( $candidates as $candidate ) {
			$field = acf_get_field( $candidate );
			if ( ! is_array( $field ) ) {
				continue;
			}
			if ( ! empty( $field['label'] ) && is_string( $field['label'] ) ) {
				return $field['label'];
			}
		}

		return $label;
	}

	/**
	 * Refer to ACF post meta as "fields" in the admin UI.
	 *
	 * @param array<string, string> $terms     Terminology.
	 * @param string                $post_type Post type.
	 * @return array<string, string>
	 */
	public static function filter_meta_terminology( array $terms, string $post_type ): array {
		if ( ! self::applies_to_post_type( $post_type ) ) {
			return $terms;
		}

		$terms['source'] = __( 'Custom fields', 'vikus-viewer-embed' );

		return $terms;
	}

	/**
	 * Resolve detail/CSV meta via ACF get_field() so multi-value fields join correctly.
	 *
	 * Checkbox, multi-select, and similar fields return arrays from get_field();
	 * we stringify them (preferring labels when Return Format is Both).
	 *
	 * @param string               $resolved Default raw meta string.
	 * @param \WP_Post             $post     Source post.
	 * @param string               $key      Meta / field name.
	 * @param array<string, mixed> $settings Collection settings.
	 * @param string               $type     Detail field type.
	 */
	public static function filter_resolve_meta_value( string $resolved, \WP_Post $post, string $key, array $settings = array(), string $type = 'text' ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		if ( Tainacan::is_item_post_type( $post->post_type ) || ! function_exists( 'get_field' ) || ! function_exists( 'acf_get_field' ) ) {
			return $resolved;
		}

		$field_name = self::resolve_field_name( $key );
		if ( null === $field_name ) {
			return $resolved;
		}

		try {
			$value = get_field( $field_name, $post->ID );
		} catch ( \Throwable $e ) {
			return $resolved;
		}

		if ( null === $value || false === $value ) {
			return '';
		}

		// Markdown/HTML detail types: keep WYSIWYG/HTML strings intact; still join arrays.
		$separator = ( 'text' === $type ) ? ', ' : '<br>';
		return FieldResolver::stringify_meta_value( $value, $separator );
	}

	/**
	 * Map a stored meta key to an ACF field name when the field exists.
	 *
	 * @param string $key Meta key (may be field name or underscored reference).
	 */
	private static function resolve_field_name( string $key ): ?string {
		$candidates = array( $key );
		if ( 0 === strpos( $key, '_' ) ) {
			$candidates[] = substr( $key, 1 );
		}

		foreach ( $candidates as $candidate ) {
			$field = acf_get_field( $candidate );
			if ( is_array( $field ) && ! empty( $field['name'] ) && is_string( $field['name'] ) ) {
				return $field['name'];
			}
		}

		return null;
	}
}
