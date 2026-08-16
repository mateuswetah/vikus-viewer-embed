<?php
/**
 * Main plugin bootstrap.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer;

use VikusViewer\Admin\App as AdminApp;
use VikusViewer\Admin\BuildActions;
use VikusViewer\Cli\Commands;
use VikusViewer\Frontend\Block;
use VikusViewer\Frontend\Shortcode;
use VikusViewer\Frontend\Viewer;
use VikusViewer\Frontend\Assets;
use VikusViewer\Pipeline\BuildQueue;
use VikusViewer\PostType\Collection;
use VikusViewer\Rest\Bootstrap as RestBootstrap;
use VikusViewer\Support\DirtyTracker;
use VikusViewer\Integrations\Bootstrap as Integrations;

/**
 * Class Plugin
 */
final class Plugin {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static ?self $instance = null;

	/**
	 * Get singleton.
	 */
	public static function instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function init(): void {
		Collection::register_hooks();
		Assets::register_hooks();
		Viewer::register_hooks();
		Shortcode::register_hooks();
		Block::register_hooks();
		BuildQueue::register_hooks();
		DirtyTracker::register_hooks();
		Integrations::register_hooks();
		RestBootstrap::register_hooks();

		if ( is_admin() ) {
			AdminApp::register_hooks();
			BuildActions::register_hooks();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			Commands::register();
		}
	}
}
