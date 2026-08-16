<?php
/**
 * Serve vendored Vikus static assets through WordPress.
 *
 * Direct /wp-content/plugins/... URLs sometimes return HTML 404/500 pages in
 * Docker/nginx setups, which browsers reject (MIME text/html for CSS/JS).
 *
 * CSS is served from a site-root query URL, so relative url(../img/…) and
 * url(../font/…) are rewritten to absolute ?vikus_asset=… URLs when the
 * stylesheet is served.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Frontend;

/**
 * Class Assets
 */
final class Assets {

	public const QUERY_VAR = 'vikus_asset';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register_rewrite_rules' ) );
		add_filter( 'query_vars', array( self::class, 'query_vars' ) );
		add_action( 'template_redirect', array( self::class, 'maybe_serve' ), 0 );
		add_action( 'init', array( self::class, 'maybe_flush_rewrites' ), 99 );
	}

	/**
	 * Path-style asset URLs so CSS relative paths can resolve under /vikus-asset/.
	 */
	public static function register_rewrite_rules(): void {
		add_rewrite_rule(
			'^vikus-asset/(.+)$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	/**
	 * Flush rewrites once per plugin version (new asset path).
	 */
	public static function maybe_flush_rewrites(): void {
		$option = 'vikus_viewer_asset_rewrite_version';
		if ( get_option( $option ) === VIKUS_VIEWER_VERSION ) {
			return;
		}
		self::register_rewrite_rules();
		flush_rewrite_rules( false );
		update_option( $option, VIKUS_VIEWER_VERSION );
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
	 * Public URL for a vendor-relative asset path (e.g. css/style.css).
	 *
	 * Uses a query-arg URL so assets work even before pretty-permalink
	 * rewrites are flushed. CSS url() references are rewritten to these
	 * absolute URLs when stylesheets are served.
	 *
	 * @param string $relative Relative path inside vendor-vikus.
	 */
	public static function url( string $relative ): string {
		$relative = ltrim( str_replace( '\\', '/', $relative ), '/' );

		// WordPress.org disallows shipping libraries already in core.
		if ( 'js/lodash.min.js' === $relative || 'js/lodash.js' === $relative ) {
			return includes_url( 'js/dist/vendor/lodash.js' );
		}

		return add_query_arg(
			array(
				self::QUERY_VAR => $relative,
				'ver'             => VIKUS_VIEWER_VERSION,
			),
			home_url( '/' )
		);
	}

	/**
	 * Absolute filesystem path to vendor-vikus.
	 */
	public static function vendor_dir(): string {
		return VIKUS_VIEWER_PATH . 'vendor-vikus/';
	}

	/**
	 * Whether vendor assets are present on disk.
	 */
	public static function vendor_available(): bool {
		return is_readable( self::vendor_dir() . 'index.html' )
			&& is_readable( self::vendor_dir() . 'css/style.css' )
			&& is_readable( self::vendor_dir() . 'js/viz.js' );
	}

	/**
	 * Serve a vendor asset when ?vikus_asset= / /vikus-asset/… is present.
	 */
	public static function maybe_serve(): void {
		$relative = get_query_var( self::QUERY_VAR );
		if ( '' === $relative || null === $relative ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public asset URL.
			if ( empty( $_GET[ self::QUERY_VAR ] ) ) {
				return;
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public asset URL.
			$relative = sanitize_text_field( wp_unslash( (string) $_GET[ self::QUERY_VAR ] ) );
		}

		$relative = str_replace( '\\', '/', (string) $relative );
		$relative = ltrim( $relative, '/' );
		// Pretty permalinks may include a trailing slash.
		$relative = untrailingslashit( $relative );

		if ( '' === $relative || false !== strpos( $relative, '..' ) ) {
			status_header( 400 );
			exit;
		}

		// Only allow known vendor subtrees.
		if ( ! preg_match( '#^(css|js|font|img)/#', $relative ) ) {
			status_header( 404 );
			exit;
		}

		$path        = self::vendor_dir() . $relative;
		$real_vendor = realpath( self::vendor_dir() );
		$real_file   = realpath( $path );

		if ( false === $real_vendor || false === $real_file || 0 !== strpos( $real_file, $real_vendor ) || ! is_file( $real_file ) ) {
			status_header( 404 );
			header( 'Content-Type: text/plain; charset=UTF-8' );
			echo 'Vikus asset not found. Ensure the vendor-vikus directory was included when installing the plugin.';
			exit;
		}

		$mime = self::mime_type( $real_file );
		nocache_headers();
		header( 'Content-Type: ' . $mime );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Cache-Control: public, max-age=86400' );

		$ext = strtolower( pathinfo( $real_file, PATHINFO_EXTENSION ) );
		if ( 'css' === $ext ) {
			$css = file_get_contents( $real_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $css ) {
				status_header( 500 );
				exit;
			}
			$css = self::rewrite_css_urls( $css, $relative );
			header( 'Content-Length: ' . (string) strlen( $css ) );
			echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw CSS asset.
			exit;
		}

		// Sanitize sidebars.js before output (neutralize upstream eval; keep vendor file on disk untouched).
		if ( 'js/sidebars.js' === $relative ) {
			$js = file_get_contents( $real_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			if ( false === $js ) {
				status_header( 500 );
				exit;
			}
			$js = self::sanitize_sidebars_js( $js );
			header( 'Content-Length: ' . (string) strlen( $js ) );
			echo $js; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitized vendor JS.
			exit;
		}

		header( 'Content-Length: ' . (string) filesize( $real_file ) );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $real_file );
		exit;
	}

	/**
	 * Neutralize upstream detail type "function" (eval) in sidebars.js.
	 *
	 * WordPress.org Plugin Check and reviewers flag eval() in shipped/served JS.
	 * Upstream Vikus uses eval for config-driven detail fields of type "function".
	 * This plugin never emits that type; viewer-compat.js also blocks it. Serving a
	 * sanitized copy keeps vendor-vikus/js/sidebars.js unmodified on disk while
	 * the public asset response contains no eval().
	 *
	 * If upstream removes or rewrites the branch, this is a no-op when patterns miss.
	 *
	 * @param string $js Raw sidebars.js contents.
	 */
	public static function sanitize_sidebars_js( string $js ): string {
		// Prefer replacing the whole type === 'function' branch (current upstream shape).
		$out = preg_replace(
			'#if\s*\(\s*entry\.type\s*===\s*[\'"]function[\'"]\s*\)\s*\{[^{}]*try\s*\{[^{}]*eval\s*\([^)]*\)[^{}]*\}\s*catch\s*\([^)]*\)\s*\{[^{}]*\}[^{}]*\}#s',
			"if (entry.type === 'function') {\n"
			. "            // WordPress plugin: detail type \"function\" disabled (upstream used eval).\n"
			. "            return '';\n"
			. '          }',
			$js,
			1
		);

		if ( ! is_string( $out ) ) {
			$out = $js;
		}

		// Fallback: replace return eval(...) with empty string if the branch shape changed.
		if ( false !== strpos( $out, 'eval(' ) ) {
			$scrubbed = preg_replace( '#return\s+eval\s*\([^)]*\)#', "return ''", $out );
			if ( is_string( $scrubbed ) ) {
				$out = $scrubbed;
			}
		}

		return $out;
	}

	/**
	 * Rewrite relative CSS url(...) to absolute vikus-asset URLs.
	 *
	 * Needed because CSS is not served from /vendor-vikus/css/, so ../img and
	 * ../font would otherwise resolve against the site root.
	 *
	 * @param string $css           CSS contents.
	 * @param string $css_relative  Vendor-relative CSS path (e.g. css/style.css).
	 */
	public static function rewrite_css_urls( string $css, string $css_relative ): string {
		$css_dir = dirname( str_replace( '\\', '/', $css_relative ) );

		return (string) preg_replace_callback(
			'#url\(\s*([\'"]?)([^\'")]+)\1\s*\)#i',
			static function ( array $m ) use ( $css_dir ): string {
				$quote = $m[1];
				$url   = trim( $m[2] );

				if ( '' === $url || str_starts_with( $url, '#' ) || preg_match( '#^(?:https?:|data:|//)#i', $url ) ) {
					return $m[0];
				}

				$resolved = self::resolve_vendor_path( $css_dir, $url );
				if ( null === $resolved ) {
					return $m[0];
				}

				return 'url(' . $quote . self::url( $resolved ) . $quote . ')';
			},
			$css
		);
	}

	/**
	 * Resolve a path relative to a vendor file into a vendor-root path.
	 *
	 * @param string $from_dir Vendor-relative directory of the referencing file.
	 * @param string $ref      Relative URL from CSS.
	 */
	private static function resolve_vendor_path( string $from_dir, string $ref ): ?string {
		$ref = str_replace( '\\', '/', $ref );
		$ref = strtok( $ref, '?#' );
		if ( ! is_string( $ref ) || '' === $ref ) {
			return null;
		}

		$stack = ( '.' === $from_dir || '' === $from_dir ) ? array() : explode( '/', $from_dir );
		foreach ( explode( '/', $ref ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $part;
		}

		$resolved = implode( '/', $stack );
		if ( '' === $resolved || ! preg_match( '#^(css|js|font|img)/#', $resolved ) ) {
			return null;
		}

		return $resolved;
	}

	/**
	 * Guess MIME type for a vendor file.
	 *
	 * @param string $path Absolute path.
	 */
	private static function mime_type( string $path ): string {
		$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		$map = array(
			'css'   => 'text/css; charset=UTF-8',
			'js'    => 'application/javascript; charset=UTF-8',
			'mjs'   => 'application/javascript; charset=UTF-8',
			'json'  => 'application/json; charset=UTF-8',
			'map'   => 'application/json; charset=UTF-8',
			'svg'   => 'image/svg+xml',
			'png'   => 'image/png',
			'jpg'   => 'image/jpeg',
			'jpeg'  => 'image/jpeg',
			'gif'   => 'image/gif',
			'webp'  => 'image/webp',
			'woff'  => 'font/woff',
			'woff2' => 'font/woff2',
			'ttf'   => 'font/ttf',
			'eot'   => 'application/vnd.ms-fontobject',
			'html'  => 'text/html; charset=UTF-8',
		);

		if ( isset( $map[ $ext ] ) ) {
			return $map[ $ext ];
		}

		if ( function_exists( 'mime_content_type' ) ) {
			$detected = mime_content_type( $path );
			if ( is_string( $detected ) && '' !== $detected ) {
				return $detected;
			}
		}

		return 'application/octet-stream';
	}
}
