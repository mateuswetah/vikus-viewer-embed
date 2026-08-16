<?php
/**
 * Dynamic block render.
 *
 * @package VikusViewer
 *
 * @var array    $attributes Block attributes.
 * @var string   $content    Block default content.
 * @var WP_Block $block      Block instance.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use VikusViewer\Frontend\Viewer;

$vikus_viewer_id = isset( $attributes['collectionId'] ) ? absint( $attributes['collectionId'] ) : 0;
if ( ! $vikus_viewer_id ) {
	echo '<div class="vikus-viewer-embed-placeholder">' . esc_html__( 'Select a Vikus collection.', 'vikus-viewer-embed' ) . '</div>';
	return;
}

$vikus_viewer_height = isset( $attributes['height'] ) ? sanitize_text_field( (string) $attributes['height'] ) : '80vh';
$vikus_viewer_html   = Viewer::iframe_html(
	$vikus_viewer_id,
	array(
		'height' => $vikus_viewer_height,
	)
);

// iframe_html() returns markup with esc_attr / esc_url applied.
echo $vikus_viewer_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
