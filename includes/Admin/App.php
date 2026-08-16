<?php
/**
 * Dedicated React admin app mount (collections list / create / edit).
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Admin;

use VikusViewer\Frontend\Viewer;
use VikusViewer\PostType\Collection;
use VikusViewer\Support\Settings;

/**
 * Class App
 */
final class App {

	public const PAGE_SLUG = 'vikus-viewer-embed';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_menu', array( self::class, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'enqueue' ) );
		add_filter( 'admin_body_class', array( self::class, 'admin_body_class' ) );
		add_action( 'load-edit.php', array( self::class, 'redirect_classic_list' ) );
		add_action( 'load-post.php', array( self::class, 'redirect_classic_edit' ) );
		add_action( 'load-post-new.php', array( self::class, 'redirect_classic_new' ) );
	}

	/**
	 * Admin URL for a screen.
	 *
	 * @param string               $screen Screen: list|create|edit.
	 * @param array<string, mixed> $args   Extra query args.
	 */
	public static function url( string $screen = 'list', array $args = array() ): string {
		$query = array_merge(
			array(
				'page'   => self::PAGE_SLUG,
				'screen' => $screen,
			),
			$args
		);
		if ( 'list' === $screen ) {
			unset( $query['screen'] );
		}
		return add_query_arg( $query, admin_url( 'admin.php' ) );
	}

	/**
	 * Register top-level menu (replaces CPT menu).
	 */
	public static function register_menu(): void {
		add_menu_page(
			__( 'Vikus Viewer Embed', 'vikus-viewer-embed' ),
			__( 'Vikus Viewer Embed', 'vikus-viewer-embed' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( self::class, 'render' ),
			'dashicons-images-alt2',
			58
		);
	}

	/**
	 * Render the app root.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You are not allowed to manage Vikus collections.', 'vikus-viewer-embed' ) );
		}
		echo '<div id="vikus-viewer-embed-admin" class="vikus-admin-app"></div>';
	}

	/**
	 * Mark the admin body for full-bleed canvas styles (core DataViews pattern).
	 *
	 * @param string $classes Body classes.
	 */
	public static function admin_body_class( string $classes ): string {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'toplevel_page_' . self::PAGE_SLUG === $screen->id ) {
			$classes .= ' vikus-viewer-embed-admin-page';
		}
		return $classes;
	}

	/**
	 * Enqueue built admin script on our page only.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function enqueue( string $hook ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}

		$asset_file = VIKUS_VIEWER_PATH . 'build/admin.asset.php';
		$asset      = is_readable( $asset_file )
			? require $asset_file
			: array(
				'dependencies' => array(
					'wp-element',
					'wp-components',
					'wp-i18n',
					'wp-api-fetch',
					'wp-data',
					'wp-core-data',
					'wp-url',
				),
				'version'      => VIKUS_VIEWER_VERSION,
			);

		$script_path = VIKUS_VIEWER_PATH . 'build/admin.js';
		if ( ! is_readable( $script_path ) ) {
			return;
		}

		// Core does not register wp-dataviews; strip CSS / unknown paths that
		// dependency-extraction may emit when styles were imported from JS.
		$script_deps = array_values(
			array_filter(
				(array) ( $asset['dependencies'] ?? array() ),
				static function ( $dep ): bool {
					$dep = (string) $dep;
					if ( '' === $dep || false !== strpos( $dep, '.css' ) ) {
						return false;
					}
					if ( 0 === strpos( $dep, 'wp-dataviews' ) ) {
						return false;
					}
					return true;
				}
			)
		);

		wp_enqueue_script(
			'vikus-viewer-embed-admin-app',
			VIKUS_VIEWER_URL . 'build/admin.js',
			$script_deps,
			$asset['version'],
			true
		);

		/*
		 * WPDS tokens: prefer core `wp-theme` stylesheet (WP 7.1+). On 7.0 Boot
		 * pages inject tokens via JS; plugin admin pages need an explicit enqueue.
		 */
		$tokens_handle = 'wp-theme';
		if ( ! wp_style_is( 'wp-theme', 'registered' ) ) {
			$tokens_path = VIKUS_VIEWER_PATH . 'build/design-tokens.css';
			if ( is_readable( $tokens_path ) ) {
				$tokens_handle = 'vikus-viewer-embed-design-tokens';
				wp_enqueue_style(
					$tokens_handle,
					VIKUS_VIEWER_URL . 'build/design-tokens.css',
					array(),
					$asset['version']
				);
			} else {
				$tokens_handle = '';
			}
		} else {
			wp_enqueue_style( 'wp-theme' );
		}

		// DataViews styles ship with the plugin (see bin/copy-block-assets.js).
		$dataviews_built = VIKUS_VIEWER_PATH . 'build/dataviews.css';
		if ( is_readable( $dataviews_built ) ) {
			$dataviews_deps = array( 'wp-components' );
			if ( $tokens_handle ) {
				$dataviews_deps[] = $tokens_handle;
			}
			wp_enqueue_style(
				'vikus-viewer-embed-dataviews',
				VIKUS_VIEWER_URL . 'build/dataviews.css',
				$dataviews_deps,
				$asset['version']
			);
		}

		$style_deps = array( 'wp-components' );
		if ( $tokens_handle ) {
			$style_deps[] = $tokens_handle;
		}
		if ( wp_style_is( 'vikus-viewer-embed-dataviews', 'enqueued' ) || wp_style_is( 'vikus-viewer-embed-dataviews', 'registered' ) ) {
			$style_deps[] = 'vikus-viewer-embed-dataviews';
		}

		$style_path = VIKUS_VIEWER_PATH . 'build/style-admin.css';
		if ( is_readable( $style_path ) ) {
			wp_enqueue_style(
				'vikus-viewer-embed-admin-app',
				VIKUS_VIEWER_URL . 'build/style-admin.css',
				$style_deps,
				$asset['version']
			);
		}

		wp_enqueue_style( 'wp-components' );

		$screen = isset( $_GET['screen'] ) ? sanitize_key( (string) wp_unslash( $_GET['screen'] ) ) : 'list'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$id     = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		wp_add_inline_script(
			'vikus-viewer-embed-admin-app',
			'window.vikusViewerAdminApp = ' . wp_json_encode(
				array(
					'restUrl'    => esc_url_raw( rest_url( 'vikus-viewer-embed/v1' ) ),
					'rootUrl'    => self::url( 'list' ),
					'screen'     => $screen,
					'collectionId' => $id,
					'defaults'   => Settings::defaults(),
					'pluginUrl'      => VIKUS_VIEWER_URL,
					'homeUrl'        => home_url( '/' ),
					'colorPalettes'  => self::theme_color_palettes(),
					'fontFamilies'   => Settings::font_family_options(),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Redirect classic CPT list to the app.
	 */
	public static function redirect_classic_list(): void {
		if ( ! isset( $_GET['post_type'] ) || Collection::POST_TYPE !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::url( 'list' ) );
		exit;
	}

	/**
	 * Redirect classic CPT edit to the app.
	 */
	public static function redirect_classic_edit(): void {
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! $post_id || Collection::POST_TYPE !== get_post_type( $post_id ) ) {
			return;
		}
		wp_safe_redirect( self::url( 'edit', array( 'id' => $post_id ) ) );
		exit;
	}

	/**
	 * Redirect classic CPT new to create wizard.
	 */
	public static function redirect_classic_new(): void {
		if ( ! isset( $_GET['post_type'] ) || Collection::POST_TYPE !== $_GET['post_type'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		wp_safe_redirect( self::url( 'create' ) );
		exit;
	}

	/**
	 * Public viewer URL helper for REST (avoids circular imports in Rest).
	 *
	 * @param int $collection_id Collection ID.
	 */
	public static function viewer_url( int $collection_id ): string {
		return Viewer::public_url( $collection_id );
	}

	/**
	 * Theme / global-styles color palettes for ColorPalette (same source as Gutenberg).
	 *
	 * @return list<array{name:string,colors:list<array{name:string,slug:string,color:string}>}>
	 */
	private static function theme_color_palettes(): array {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$raw = wp_get_global_settings( array( 'color', 'palette' ) );
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$origin_labels = array(
			'theme'   => __( 'Theme', 'vikus-viewer-embed' ),
			'default' => __( 'Default', 'vikus-viewer-embed' ),
			'custom'  => __( 'Custom', 'vikus-viewer-embed' ),
		);

		$out = array();
		foreach ( $origin_labels as $origin => $label ) {
			$entries = $raw[ $origin ] ?? null;
			if ( ! is_array( $entries ) || empty( $entries ) ) {
				continue;
			}
			$colors = array();
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$color = isset( $entry['color'] ) ? (string) $entry['color'] : '';
				$hex   = self::resolve_palette_hex( $color );
				if ( '' === $hex ) {
					continue;
				}
				$colors[] = array(
					'name'  => sanitize_text_field( (string) ( $entry['name'] ?? $entry['slug'] ?? $hex ) ),
					'slug'  => sanitize_title( (string) ( $entry['slug'] ?? '' ) ),
					'color' => $hex,
				);
			}
			if ( empty( $colors ) ) {
				continue;
			}
			$out[] = array(
				'name'   => $label,
				'colors' => $colors,
			);
		}

		return $out;
	}

	/**
	 * Resolve a palette color to a hex string Vikus can consume.
	 *
	 * @param string $color Palette color (hex or CSS variable).
	 */
	private static function resolve_palette_hex( string $color ): string {
		$color = trim( $color );
		$hex   = sanitize_hex_color( $color );
		if ( $hex ) {
			return $hex;
		}
		if ( preg_match( '/^#?[0-9a-fA-F]{3}$/', $color ) ) {
			$digits = ltrim( $color, '#' );
			$hex    = sanitize_hex_color( '#' . $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2] );
			return $hex ? $hex : '';
		}
		if ( preg_match( '/var\(\s*--wp--preset--color--([a-z0-9-]+)\s*\)/i', $color, $m ) ) {
			$slug    = sanitize_title( $m[1] );
			$palette = wp_get_global_settings( array( 'color', 'palette' ) );
			if ( is_array( $palette ) ) {
				foreach ( $palette as $entries ) {
					if ( ! is_array( $entries ) ) {
						continue;
					}
					foreach ( $entries as $entry ) {
						if ( ! is_array( $entry ) || (string) ( $entry['slug'] ?? '' ) !== $slug ) {
							continue;
						}
						$nested = sanitize_hex_color( (string) ( $entry['color'] ?? '' ) );
						if ( $nested ) {
							return $nested;
						}
					}
				}
			}
		}
		return '';
	}
}
