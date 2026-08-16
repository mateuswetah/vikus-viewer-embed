<?php
/**
 * Texture / sprite source hash manifest for incremental builds.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

use VikusViewer\Support\Paths;

/**
 * Class Manifest
 */
final class Manifest {

	/**
	 * Load manifest.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array<string, array<string, mixed>>
	 */
	public static function load( int $collection_id ): array {
		$file = Paths::file( $collection_id, 'manifest.json' );
		if ( ! file_exists( $file ) ) {
			return array();
		}
		$raw = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save manifest.
	 *
	 * @param int                                  $collection_id Collection post ID.
	 * @param array<string, array<string, mixed>> $manifest      Manifest data.
	 */
	public static function save( int $collection_id, array $manifest ): void {
		Paths::ensure_collection_dir( $collection_id );
		$file = Paths::file( $collection_id, 'manifest.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, wp_json_encode( $manifest, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Fingerprint a source image file.
	 *
	 * @param string $path Absolute path.
	 */
	public static function fingerprint( string $path ): string {
		if ( ! file_exists( $path ) ) {
			return '';
		}
		$size = filesize( $path );
		$mtime = filemtime( $path );
		return md5( $path . '|' . (string) $size . '|' . (string) $mtime );
	}

	/**
	 * Whether textures for an item are up to date.
	 *
	 * @param array<string, mixed> $entry       Manifest entry.
	 * @param string               $fingerprint Current fingerprint.
	 * @param bool                 $force       Force rebuild.
	 */
	public static function is_fresh( array $entry, string $fingerprint, bool $force ): bool {
		if ( $force ) {
			return false;
		}
		return isset( $entry['fingerprint'] ) && $entry['fingerprint'] === $fingerprint && ! empty( $entry['textures'] );
	}
}
