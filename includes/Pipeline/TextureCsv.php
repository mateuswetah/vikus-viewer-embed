<?php
/**
 * Sync texture URLs from the manifest into data.csv.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

use VikusViewer\Support\Paths;

/**
 * Class TextureCsv
 */
final class TextureCsv {

	public const DETAIL_COLUMN = '_detail_url';
	public const BIG_COLUMN    = '_big_url';

	/**
	 * Write detail/big texture URLs from manifest into data.csv.
	 *
	 * @param int $collection_id Collection ID.
	 */
	public static function sync_from_manifest( int $collection_id ): void {
		$path = Paths::file( $collection_id, 'data.csv' );
		if ( ! is_readable( $path ) ) {
			return;
		}

		$manifest = Manifest::load( $collection_id );
		$handle   = fopen( $path, 'r' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			return;
		}

		$headers = fgetcsv( $handle );
		if ( ! is_array( $headers ) || empty( $headers ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return;
		}

		$rows = array();
		while ( ( $row = fgetcsv( $handle ) ) !== false ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$assoc = array();
			foreach ( $headers as $i => $header ) {
				$assoc[ (string) $header ] = $row[ $i ] ?? '';
			}
			$rows[] = $assoc;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		foreach ( array( self::DETAIL_COLUMN, self::BIG_COLUMN, 'imagenum' ) as $col ) {
			if ( ! in_array( $col, $headers, true ) ) {
				$headers[] = $col;
			}
		}

		foreach ( $rows as &$assoc ) {
			$id    = (string) ( $assoc['id'] ?? '' );
			$entry = $manifest[ $id ] ?? array();
			$assoc[ self::DETAIL_COLUMN ] = isset( $entry['detail_url'] ) ? (string) $entry['detail_url'] : '';
			$assoc[ self::BIG_COLUMN ]    = isset( $entry['big_url'] ) ? (string) $entry['big_url'] : '';
			if ( isset( $entry['imagenum'] ) ) {
				$assoc['imagenum'] = (string) max( 1, (int) $entry['imagenum'] );
			} elseif ( ! isset( $assoc['imagenum'] ) || '' === (string) $assoc['imagenum'] ) {
				$assoc['imagenum'] = '1';
			}
		}
		unset( $assoc );

		$out = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $out ) {
			return;
		}
		fputcsv( $out, $headers );
		foreach ( $rows as $assoc ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = isset( $assoc[ $header ] ) ? (string) $assoc[ $header ] : '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
	}
}
