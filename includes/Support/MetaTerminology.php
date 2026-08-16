<?php
/**
 * Admin UI wording for post-meta-like sources (meta / metadata / fields).
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

/**
 * Class MetaTerminology
 */
final class MetaTerminology {

	/**
	 * Default English/core terminology (post meta).
	 *
	 * @return array<string, string>
	 */
	public static function defaults(): array {
		return array(
			'source' => __( 'Post meta', 'vikus-viewer-embed' ),
		);
	}

	/**
	 * Terminology for a source post type.
	 *
	 * Integrations should hook `vikus_viewer_meta_terminology` and return a full
	 * (or partial) set of strings when the post type belongs to their ecosystem.
	 *
	 * @param string $post_type Source post type.
	 * @return array<string, string>
	 */
	public static function for_post_type( string $post_type ): array {
		$post_type = sanitize_key( $post_type );
		$defaults  = self::defaults();

		/**
		 * Filter how the admin UI refers to post-meta-like sources.
		 *
		 * Expected keys: source.
		 *
		 * @param array<string, string> $terms     Terminology strings.
		 * @param string                $post_type Source post type.
		 */
		$filtered = apply_filters( 'vikus_viewer_meta_terminology', $defaults, $post_type );
		if ( ! is_array( $filtered ) ) {
			return $defaults;
		}

		$out = $defaults;
		foreach ( $defaults as $key => $default ) {
			if ( isset( $filtered[ $key ] ) && is_string( $filtered[ $key ] ) && '' !== $filtered[ $key ] ) {
				$out[ $key ] = $filtered[ $key ];
			}
		}

		return $out;
	}

	/**
	 * Map of public source post types → terminology.
	 *
	 * @return array<string, array<string, string>>
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
}
