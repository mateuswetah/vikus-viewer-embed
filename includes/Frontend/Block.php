<?php
/**
 * Gutenberg block registration.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Frontend;

/**
 * Class Block
 */
final class Block {

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
	}

	/**
	 * Register the block from metadata.
	 */
	public static function register(): void {
		$build_dir = VIKUS_VIEWER_PATH . 'build';
		$src_dir   = VIKUS_VIEWER_PATH . 'src/block';

		// Prefer built assets; fall back to src metadata for first boot before npm build.
		$block_dir = file_exists( $build_dir . '/block.json' ) ? $build_dir : $src_dir;

		if ( ! file_exists( $block_dir . '/block.json' ) ) {
			return;
		}

		register_block_type( $block_dir );
	}
}
