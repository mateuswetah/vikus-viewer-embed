<?php
/**
 * Uninstall cleanup.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$vikus_viewer_cleanup = get_option( 'vikus_viewer_cleanup_on_uninstall', false );
if ( ! $vikus_viewer_cleanup ) {
	return;
}

$vikus_viewer_upload = wp_upload_dir();
$vikus_viewer_dir    = trailingslashit( $vikus_viewer_upload['basedir'] ) . 'vikus';

if ( is_dir( $vikus_viewer_dir ) ) {
	vikus_viewer_rrmdir( $vikus_viewer_dir );
}

/**
 * Recursively remove a directory.
 *
 * @param string $path Path.
 */
function vikus_viewer_rrmdir( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = scandir( $path );
	if ( false === $items ) {
		return;
	}
	foreach ( $items as $item ) {
		if ( '.' === $item || '..' === $item ) {
			continue;
		}
		$full = $path . '/' . $item;
		if ( is_dir( $full ) ) {
			vikus_viewer_rrmdir( $full );
		} else {
			wp_delete_file( $full );
		}
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir -- recursive uninstall cleanup.
	rmdir( $path );
}

$vikus_viewer_collections = get_posts(
	array(
		'post_type'      => 'vikus_collection',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	)
);

foreach ( $vikus_viewer_collections as $vikus_viewer_id ) {
	wp_delete_post( (int) $vikus_viewer_id, true );
}

delete_option( 'vikus_viewer_cleanup_on_uninstall' );
