<?php
/**
 * Export data.csv for a collection.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Export;

use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class CsvExporter
 */
final class CsvExporter {

	/**
	 * Export CSV for a collection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array{path:string,count:int,ids:int[]}
	 */
	public static function export( int $collection_id ): array {
		$settings = Settings::get( $collection_id );
		$dir      = Paths::ensure_collection_dir( $collection_id );
		$path     = $dir . '/data.csv';

		$ids = ItemQuery::all_ids( $collection_id );
		$rows = array();
		$headers = array(
			'id',
			'keywords',
			'year',
			'imagenum',
			'_title',
			'_permalink',
			\VikusViewer\Pipeline\TextureCsv::DETAIL_COLUMN,
			\VikusViewer\Pipeline\TextureCsv::BIG_COLUMN,
		);

		foreach ( $settings['detail_fields'] as $field ) {
			$col = (string) $field['column'];
			if ( '' !== $col && ! in_array( $col, $headers, true ) ) {
				$headers[] = $col;
			}
		}

		foreach ( $settings['layouts'] ?? array() as $layout ) {
			$source = (string) ( $layout['source'] ?? 'year' );
			if ( 'year' === $source ) {
				continue;
			}
			$col = (string) ( $layout['group_key'] ?? Settings::layout_group_key( $source ) );
			if ( '' !== $col && ! in_array( $col, $headers, true ) ) {
				$headers[] = $col;
			}
		}

		foreach ( $settings['crossfilter_dims'] ?? array() as $dim ) {
			if ( ! is_array( $dim ) ) {
				continue;
			}
			$col = Settings::crossfilter_csv_column( (string) ( $dim['source'] ?? '' ) );
			if ( '' !== $col && ! in_array( $col, $headers, true ) ) {
				$headers[] = $col;
			}
		}

		$manifest = \VikusViewer\Pipeline\Manifest::load( $collection_id );

		foreach ( $ids as $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}

			if ( ! empty( $settings['require_thumbnail'] ) && ! has_post_thumbnail( $post ) ) {
				continue;
			}

			$row = FieldResolver::row( $post, $settings );
			$id_key = (string) $post_id;
			$entry  = $manifest[ $id_key ] ?? array();
			$row[ \VikusViewer\Pipeline\TextureCsv::DETAIL_COLUMN ] = isset( $entry['detail_url'] ) ? (string) $entry['detail_url'] : '';
			$row[ \VikusViewer\Pipeline\TextureCsv::BIG_COLUMN ]    = isset( $entry['big_url'] ) ? (string) $entry['big_url'] : '';
			$row['imagenum'] = isset( $entry['imagenum'] )
				? (string) max( 1, (int) $entry['imagenum'] )
				: (string) max(
					1,
					\VikusViewer\Pipeline\ItemPages::imagenum( $post_id, $settings )
				);
			$rows[] = $row;
		}

		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new \RuntimeException( 'Unable to open data.csv for writing.' );
		}

		fputcsv( $handle, $headers );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( $headers as $header ) {
				$line[] = isset( $row[ $header ] ) ? (string) $row[ $header ] : '';
			}
			fputcsv( $handle, $line );
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		$exported_ids = array_map(
			static function ( array $row ): int {
				return (int) $row['id'];
			},
			$rows
		);

		return array(
			'path'  => $path,
			'count' => count( $rows ),
			'ids'   => $exported_ids,
		);
	}
}
