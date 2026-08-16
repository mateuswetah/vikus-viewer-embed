<?php
/**
 * Public Vikus viewer route.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Frontend;

use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class Viewer
 */
final class Viewer {

	public const QUERY_VAR = 'vikus_viewer';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_render' ) );
	}

	/**
	 * Rewrite rules.
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^vikus/([0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Register query var.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public static function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * Public viewer URL for a collection.
	 *
	 * Vikus reads `?config=` from the query string (see vendor-vikus/js/utils.js).
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function public_url( int $collection_id ): string {
		$permalink = home_url( user_trailingslashit( 'vikus/' . $collection_id ) );
		return add_query_arg( 'config', self::config_url( $collection_id ), $permalink );
	}

	/**
	 * Config URL for a collection.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function config_url( int $collection_id ): string {
		return Paths::file_url( $collection_id, 'config.json' );
	}

	/**
	 * Render viewer when query var is present.
	 */
	public static function maybe_render(): void {
		$id = get_query_var( self::QUERY_VAR );
		if ( '' === $id || null === $id ) {
			return;
		}

		$collection_id = absint( $id );
		if ( ! $collection_id || 'vikus_collection' !== get_post_type( $collection_id ) ) {
			status_header( 404 );
			nocache_headers();
			wp_die( esc_html__( 'Vikus collection not found.', 'vikus-viewer-embed' ), '', array( 'response' => 404 ) );
		}

		$status = Settings::get_build_status( $collection_id );
		if ( 'complete' !== $status['status'] ) {
			status_header( 503 );
			nocache_headers();
			wp_die(
				esc_html__( 'This Vikus collection has not been built yet. Open it in wp-admin and queue a rebuild.', 'vikus-viewer-embed' ),
				esc_html__( 'Collection not ready', 'vikus-viewer-embed' ),
				array( 'response' => 503 )
			);
		}

		$config_file = Paths::file( $collection_id, 'config.json' );
		if ( ! file_exists( $config_file ) ) {
			status_header( 503 );
			wp_die( esc_html__( 'Collection config is missing. Please rebuild.', 'vikus-viewer-embed' ) );
		}

		// Ensure ?config= is present (Vikus reads window.location.search).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public viewer GET args.
		if ( empty( $_GET['config'] ) ) {
			$target = self::public_url( $collection_id );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public viewer GET args.
			if ( isset( $_GET['ui'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- sanitized below; public viewer.
				$ui = sanitize_text_field( wp_unslash( (string) $_GET['ui'] ) );
				$target = add_query_arg( 'ui', $ui, $target );
			}
			wp_safe_redirect( $target );
			exit;
		}

		self::render_shell( $collection_id );
		exit;
	}

	/**
	 * Output the Vikus HTML shell.
	 *
	 * Config is already in the request query string when this runs.
	 * Vendor CSS/JS are loaded via the plugin asset endpoint so Docker/nginx
	 * setups that mishandle static plugin files still get correct MIME types.
	 */
	public static function render_shell( int $collection_id = 0 ): void {
		if ( ! Assets::vendor_available() ) {
			wp_die(
				esc_html__( 'Vikus viewer assets are missing. Make sure the vendor-vikus folder was copied with the plugin (css/, js/, font/, img/).', 'vikus-viewer-embed' ),
				esc_html__( 'Missing vendor assets', 'vikus-viewer-embed' ),
				array( 'response' => 500 )
			);
		}

		$index = VIKUS_VIEWER_PATH . 'vendor-vikus/index.html';
		$html  = file_get_contents( $index ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( false === $html ) {
			wp_die( esc_html__( 'Vikus viewer assets are missing.', 'vikus-viewer-embed' ) );
		}

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- vendored HTML shell with escaped URL injection.
		echo self::prepare_shell_html( $html, $collection_id );
	}

	/**
	 * Apply WordPress boundary transforms to upstream index.html.
	 *
	 * Mirrors the PDF.js Viewer pattern (read vendor HTML → rewrite → inject WP
	 * helpers) without placing a viewer.php inside vendor-vikus/.
	 *
	 * @param string $html           Raw vendor index.html.
	 * @param int    $collection_id  Collection ID (0 skips typography).
	 */
	public static function prepare_shell_html( string $html, int $collection_id = 0 ): string {
		$html = self::strip_remote_html5shiv( $html );
		$html = self::strip_remote_script_tags( $html );

		// Rewrite plain href/src to the WP-served asset endpoint.
		// Do NOT match Vue bindings (:href, v-bind:src) — that corrupts the
		// detail template and Vue fails to mount the sidebar.
		$rewritten = preg_replace_callback(
			'#(?<![:\w])(href|src)="(?!https?:|//|data:)([^"]+)"#',
			static function ( array $m ): string {
				$attr = $m[1];
				$rel  = $m[2];
				return $attr . '="' . esc_url( Assets::url( $rel ) ) . '"';
			},
			$html
		);
		if ( is_string( $rewritten ) ) {
			$html = $rewritten;
		}

		$typo = $collection_id ? self::typography_markup( $collection_id ) : '';
		if ( '' !== $typo ) {
			$html = preg_replace( '#</head>#i', $typo . '</head>', $html, 1 ) ?? ( $typo . $html );
		}

		// WP compatibility layer (keeps vendor-vikus unmodified).
		$compat_path = VIKUS_VIEWER_PATH . 'assets/js/viewer-compat.js';
		if ( is_readable( $compat_path ) ) {
			$compat = file_get_contents( $compat_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false !== $compat && '' !== $compat ) {
				$tag  = "<script>\n" . $compat . "\n</script>\n";
				$html = preg_replace( '#</body>#i', $tag . '</body>', $html, 1 ) ?? ( $html . $tag );
			}
		}

		return $html;
	}

	/**
	 * Remove upstream remote html5shiv from the shell HTML.
	 *
	 * WordPress.org Guideline 7 forbids loading executable code from third-party
	 * servers. Upstream vendor-vikus/index.html still ships an IE conditional that
	 * pulls html5shiv from html5shiv.googlecode.com. That polyfill only mattered
	 * for IE &lt; 9 (unsupported by WordPress and unused by this viewer), so we
	 * strip it at serve time instead of forking vendor-vikus.
	 *
	 * Keep this helper even if a future upstream release drops the tag: the
	 * replacements are no-ops when the patterns are absent. If upstream moves
	 * the script (local file, different CDN, no conditional comment), extend the
	 * patterns here rather than editing vendor-vikus.
	 *
	 * @param string $html Raw vendor index.html.
	 */
	private static function strip_remote_html5shiv( string $html ): string {
		// Current upstream shape: <!--[if lt IE 9]><script src="http://html5shiv…">…
		$out = preg_replace(
			'#<!--\[if[^\]]*\]>.*?html5shiv.*?<!\[endif\]-->#is',
			"<!-- Vikus Viewer: stripped remote html5shiv (WP.org forbids remote executable JS; IE < 9 unsupported). -->\n",
			$html,
			1
		);
		if ( ! is_string( $out ) ) {
			$out = $html;
		}

		// Fallback if upstream drops the conditional but keeps a remote html5shiv/html5.js tag.
		$out = preg_replace(
			'#<script[^>]+src=["\'][^"\']*(?:html5shiv|html5\.js)[^"\']*["\'][^>]*>\s*</script>\s*#i',
			"<!-- Vikus Viewer: stripped remote html5shiv script tag. -->\n",
			$out
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Strip any remaining remote script tags from the shell (defense in depth).
	 *
	 * After html5shiv removal, upstream should have no http(s)/protocol-relative
	 * script src. If a future release adds one, drop it here rather than loading it.
	 *
	 * @param string $html Shell HTML.
	 */
	private static function strip_remote_script_tags( string $html ): string {
		$out = preg_replace(
			'#<script\b[^>]*\bsrc=["\'](?:https?:)?//[^"\']+["\'][^>]*>\s*</script>\s*#i',
			"<!-- Vikus Viewer: stripped remote script tag (WP.org Guideline 7). -->\n",
			$html
		);

		return is_string( $out ) ? $out : $html;
	}

	/**
	 * Theme font-face tags + font-family override for the standalone shell.
	 *
	 * @param int $collection_id Collection ID.
	 */
	private static function typography_markup( int $collection_id ): string {
		$settings = Settings::get( $collection_id );
		$style    = is_array( $settings['viewer_style'] ?? null ) ? $settings['viewer_style'] : array();
		$slug     = (string) ( $style['fontFamily'] ?? 'lato' );
		$stack    = Settings::font_family_stack( $slug );
		if ( '' === $stack ) {
			return '';
		}

		$safe = str_replace( array( '{', '}', '<', '>', ';' ), '', $stack );
		if ( '' === $safe ) {
			return '';
		}

		$out = '';
		if ( function_exists( 'wp_print_font_faces' ) ) {
			ob_start();
			wp_print_font_faces();
			$out .= (string) ob_get_clean();
		}

		$selectors = implode(
			',',
			array(
				'body',
				'.tagcloud',
				'.tagcloud .tag',
				'.timeline',
				'.timeline .entry',
				'.timeline .year',
				'.infobar',
				'.infobar .outer',
				'.sidebar',
				'.sidebar .outer',
				'.detail',
				'.searchbar',
				'.searchbar input',
				'.crossfilter',
			)
		);

		$out .= '<style id="vikus-viewer-embed-font">' . $selectors . '{font-family:' . $safe . ' !important;}</style>';

		return $out;
	}

	/**
	 * Iframe embed markup.
	 *
	 * @param int                $collection_id Collection ID.
	 * @param array<string,mixed> $args         Args.
	 */
	public static function iframe_html( int $collection_id, array $args = array() ): string {
		$status = Settings::get_build_status( $collection_id );
		if ( 'complete' !== $status['status'] ) {
			return '<div class="vikus-viewer-embed-placeholder">' . esc_html__( 'Vikus collection is not built yet.', 'vikus-viewer-embed' ) . '</div>';
		}

		$height = isset( $args['height'] ) ? (string) $args['height'] : '80vh';
		$url    = self::public_url( $collection_id );
		if ( ! empty( $args['ui'] ) ) {
			$url = add_query_arg( 'ui', (string) $args['ui'], $url );
		}

		return sprintf(
			'<div class="vikus-viewer-embed" style="position:relative;width:100%%;height:%1$s;"><iframe src="%2$s" title="%3$s" style="border:0;width:100%%;height:100%%;" loading="lazy" allowfullscreen></iframe></div>',
			esc_attr( $height ),
			esc_url( $url ),
			esc_attr__( 'Vikus Viewer Embed', 'vikus-viewer-embed' )
		);
	}
}
