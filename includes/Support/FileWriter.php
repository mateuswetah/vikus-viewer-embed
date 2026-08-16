<?php
/**
 * Helpers for writing collection artifact files with mixed CLI/web ownership.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

/**
 * Class FileWriter
 */
final class FileWriter {

	/**
	 * Prepare a destination path for overwrite (CLI vs www-data safe).
	 *
	 * Imagick/GD fail with "Permission denied" when replacing a file owned by
	 * another user. Unlinking first works when the collection directory is
	 * writable by the current process.
	 *
	 * @param string $path Absolute destination path.
	 * @throws \RuntimeException When the path cannot be prepared.
	 */
	public static function prepare_overwrite( string $path ): void {
		$dir = dirname( $path );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- upload dir checks outside WP_Filesystem bootstrap.
		if ( ! is_dir( $dir ) || ! is_writable( $dir ) ) {
			throw new \RuntimeException(
				esc_html(
					sprintf(
						/* translators: %s: directory path */
						__( 'Collection directory is not writable: %s', 'vikus-viewer-embed' ),
						$dir
					)
				)
			);
		}

		if ( file_exists( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- see above.
			if ( is_writable( $path ) ) {
				return;
			}
			if ( ! wp_delete_file( $path ) && file_exists( $path ) ) {
				throw new \RuntimeException(
					esc_html(
						sprintf(
							/* translators: %s: file path */
							__( 'Cannot overwrite %s (owned by another user). Re-run the build via WP-CLI or fix uploads ownership so the web server can write.', 'vikus-viewer-embed' ),
							$path
						)
					)
				);
			}
		}
	}

	/**
	 * Relax permissions so either CLI or the web server can rewrite later.
	 *
	 * @param string $path Absolute path.
	 */
	public static function relax_permissions( string $path ): void {
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( is_dir( $path ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- CLI/web shared uploads.
			@chmod( $path, 0775 );
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.PHP.NoSilencedErrors.Discouraged -- CLI/web shared uploads.
		@chmod( $path, 0664 );
	}

	/**
	 * Write file contents after prepare_overwrite.
	 *
	 * @param string $path     Absolute path.
	 * @param string $contents Contents.
	 * @return bool
	 */
	public static function put_contents( string $path, string $contents ): bool {
		self::prepare_overwrite( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$ok = false !== file_put_contents( $path, $contents );
		if ( $ok ) {
			self::relax_permissions( $path );
		}
		return $ok;
	}
}
