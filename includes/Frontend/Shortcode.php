<?php
/**
 * Shortcode embed.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Frontend;

/**
 * Class Shortcode
 */
final class Shortcode {

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_shortcode( 'vikus_viewer', array( self::class, 'render' ) );
	}

	/**
	 * Render shortcode.
	 *
	 * @param array<string, string>|string $atts Attributes.
	 */
	public static function render( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'     => 0,
				'height' => '80vh',
				'ui'     => '',
			),
			$atts,
			'vikus_viewer'
		);

		$id = absint( $atts['id'] );
		if ( ! $id || 'vikus_collection' !== get_post_type( $id ) ) {
			return '<div class="vikus-viewer-embed-placeholder">' . esc_html__( 'Invalid Vikus collection.', 'vikus-viewer-embed' ) . '</div>';
		}

		return Viewer::iframe_html(
			$id,
			array(
				'height' => sanitize_text_field( (string) $atts['height'] ),
				'ui'     => sanitize_text_field( (string) $atts['ui'] ),
			)
		);
	}
}
