<?php
/**
 * Upload path helpers for collection artifacts.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

/**
 * Class Paths
 */
final class Paths {

	/**
	 * Relative uploads subdirectory for all collections.
	 */
	public const ROOT_DIR = 'vikus';

	/**
	 * Absolute filesystem path for a collection's data directory.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function collection_dir( int $collection_id ): string {
		$upload = wp_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::ROOT_DIR . '/' . $collection_id;

		return $dir;
	}

	/**
	 * Public URL for a collection's data directory.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function collection_url( int $collection_id ): string {
		$upload = wp_upload_dir();
		return trailingslashit( $upload['baseurl'] ) . self::ROOT_DIR . '/' . $collection_id;
	}

	/**
	 * Ensure the collection directory (and common subdirs) exist.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return string Absolute path to the collection directory.
	 */
	public static function ensure_collection_dir( int $collection_id ): string {
		$dir = self::collection_dir( $collection_id );

		$subdirs = array(
			$dir,
			$dir . '/1024',
			$dir . '/4096',
			$dir . '/sprites',
			$dir . '/tmp',
			$dir . '/tmp/128',
		);

		foreach ( $subdirs as $subdir ) {
			if ( ! is_dir( $subdir ) ) {
				wp_mkdir_p( $subdir );
			}
			FileWriter::relax_permissions( $subdir );
		}

		// Protect from direct PHP execution; assets are still publicly readable.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			\VikusViewer\Support\FileWriter::put_contents( $htaccess, "Options -Indexes\n<FilesMatch \"\\.(php|phtml)$\">\nDeny from all\n</FilesMatch>\n" );
		}

		$index = $dir . '/index.php';
		if ( ! file_exists( $index ) ) {
			\VikusViewer\Support\FileWriter::put_contents( $index, "<?php\n// Silence is golden.\n" );
		}

		return $dir;
	}

	/**
	 * Path to a named file in the collection directory.
	 *
	 * @param int    $collection_id Collection post ID.
	 * @param string $relative      Relative path inside the collection dir.
	 */
	public static function file( int $collection_id, string $relative ): string {
		return trailingslashit( self::collection_dir( $collection_id ) ) . ltrim( $relative, '/' );
	}

	/**
	 * URL to a named file in the collection directory.
	 *
	 * @param int    $collection_id Collection post ID.
	 * @param string $relative      Relative path inside the collection dir.
	 */
	public static function file_url( int $collection_id, string $relative ): string {
		return trailingslashit( self::collection_url( $collection_id ) ) . ltrim( $relative, '/' );
	}

	/**
	 * Permanently remove a collection's uploads directory (and contents).
	 *
	 * Only deletes paths that resolve under uploads/vikus/{id}/.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return bool True when the directory is gone (or never existed).
	 */
	public static function delete_collection_dir( int $collection_id ): bool {
		if ( $collection_id <= 0 ) {
			return false;
		}

		$upload = wp_upload_dir();
		if ( ! empty( $upload['error'] ) ) {
			return false;
		}

		$root = wp_normalize_path( trailingslashit( $upload['basedir'] ) . self::ROOT_DIR );
		$dir  = wp_normalize_path( self::collection_dir( $collection_id ) );

		// Must be exactly uploads/vikus/{numeric-id}.
		if ( $dir !== $root . '/' . $collection_id ) {
			return false;
		}

		if ( ! file_exists( $dir ) ) {
			return true;
		}

		if ( ! is_dir( $dir ) ) {
			return false;
		}

		$real_root = realpath( $root );
		$real_dir  = realpath( $dir );
		if ( false === $real_root || false === $real_dir ) {
			return false;
		}
		$real_root = wp_normalize_path( $real_root );
		$real_dir  = wp_normalize_path( $real_dir );
		if ( $real_dir !== $real_root . '/' . $collection_id ) {
			return false;
		}

		return self::rmdir_recursive( $real_dir );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Absolute directory path.
	 */
	private static function rmdir_recursive( string $dir ): bool {
		$items = scandir( $dir );
		if ( false === $items ) {
			return false;
		}

		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				if ( ! self::rmdir_recursive( $path ) ) {
					return false;
				}
				continue;
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			if ( ! unlink( $path ) ) {
				return false;
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		return rmdir( $dir );
	}
}
