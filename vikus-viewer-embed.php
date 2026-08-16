<?php
/**
 * Plugin Name:       Vikus Viewer Embed
 * Plugin URI:        https://github.com/mateuswetah/vikus-viewer-embed
 * Description:       Map WordPress posts into a Vikus visualization and embed it with a block or shortcode.
 * Version:           0.3.0
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Author:            wetah
 * Author URI:        https://github.com/mateuswetah
 * License:           GPL-3.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       vikus-viewer-embed
 * Domain Path:       /languages
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'VIKUS_VIEWER_VERSION', '0.3.0' );
define( 'VIKUS_VIEWER_FILE', __FILE__ );
define( 'VIKUS_VIEWER_PATH', plugin_dir_path( __FILE__ ) );
define( 'VIKUS_VIEWER_URL', plugin_dir_url( __FILE__ ) );
define( 'VIKUS_VIEWER_BASENAME', plugin_basename( __FILE__ ) );

require_once VIKUS_VIEWER_PATH . 'includes/Autoloader.php';

Autoloader::register();

/**
 * Bootstrap the plugin.
 */
function vikus_viewer(): Plugin {
	return Plugin::instance();
}

register_activation_hook( __FILE__, array( Lifecycle::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Lifecycle::class, 'deactivate' ) );

add_action(
	'plugins_loaded',
	static function (): void {
		vikus_viewer()->init();
	}
);
