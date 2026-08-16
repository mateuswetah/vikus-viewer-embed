<?php
/**
 * Activation / deactivation hooks.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer;

use VikusViewer\PostType\Collection;

/**
 * Class Lifecycle
 */
final class Lifecycle {

	/**
	 * Plugin activation.
	 */
	public static function activate(): void {
		Collection::register();
		Frontend\Viewer::register_rewrite_rules();
		flush_rewrite_rules();
	}

	/**
	 * Plugin deactivation.
	 */
	public static function deactivate(): void {
		flush_rewrite_rules();
	}
}
