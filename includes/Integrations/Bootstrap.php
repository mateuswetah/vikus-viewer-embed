<?php
/**
 * Register optional third-party integrations.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Integrations;

/**
 * Class Bootstrap
 */
final class Bootstrap {

	/**
	 * Register integrations that detect their host plugin at runtime.
	 */
	public static function register_hooks(): void {
		Tainacan::register_hooks();
		Acf::register_hooks();
		Pods::register_hooks();
	}
}
