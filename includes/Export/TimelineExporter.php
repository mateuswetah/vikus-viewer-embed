<?php
/**
 * Build timeline.csv for a collection (descriptions under group columns).
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Export;

use VikusViewer\Support\FileWriter;
use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class TimelineExporter
 */
final class TimelineExporter {

	/**
	 * Write timeline.csv. Taxonomy year sources use term descriptions; otherwise empty header.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return string Path to timeline.csv.
	 */
	public static function write( int $collection_id ): string {
		$settings = Settings::get( $collection_id );
		$dir      = Paths::ensure_collection_dir( $collection_id );
		$path     = $dir . '/timeline.csv';

		$headers = array( 'year', 'titel', 'text', 'extra' );
		$rows    = self::rows_from_settings( $settings, $collection_id );

		FileWriter::prepare_overwrite( $path );

		$handle = fopen( $path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( false === $handle ) {
			throw new \RuntimeException( 'Unable to open timeline.csv for writing.' );
		}

		fputcsv( $handle, $headers );
		foreach ( $rows as $row ) {
			fputcsv(
				$handle,
				array(
					$row['year'],
					$row['titel'],
					$row['text'],
					$row['extra'],
				)
			);
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		FileWriter::relax_permissions( $path );

		return $path;
	}

	/**
	 * Timeline rows for the current year-source settings.
	 *
	 * Used terms: row only when the term has a description (column comes from data).
	 * Unused terms: included when `timeline_include_unused` is on (may have empty
	 * text so a column exists; the viewer only draws cards when text is set).
	 *
	 * @param array<string, mixed> $settings      Collection settings.
	 * @param int                  $collection_id Collection post ID.
	 * @return list<array{year:string,titel:string,text:string,extra:string}>
	 */
	public static function rows_from_settings( array $settings, int $collection_id = 0 ): array {
		if ( 'taxonomy' !== ( $settings['year_source'] ?? '' ) ) {
			return array();
		}

		$taxonomy = (string) ( $settings['year_taxonomy'] ?? '' );
		if ( '' === $taxonomy || ! taxonomy_exists( $taxonomy ) ) {
			return array();
		}

		$include_unused = ! empty( $settings['timeline_include_unused'] );
		$used_ids       = array();
		if ( $collection_id > 0 ) {
			$used_ids = self::term_ids_used_by_collection( $collection_id, $taxonomy );
		}

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
			)
		);
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$rows_by_key = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}

			$term_id = (int) $term->term_id;
			$is_used = isset( $used_ids[ $term_id ] );
			$text    = self::term_description_text( $term );

			/*
			 * Used terms: only emit a timeline row when there is a description
			 * (the group column already comes from data.csv).
			 * Unused terms: only when include_unused is on — may have empty
			 * text so the viewer can still open a column; cards need text.
			 */
			if ( $is_used ) {
				if ( '' === $text ) {
					continue;
				}
			} elseif ( ! $include_unused ) {
				continue;
			}

			$key = FieldResolver::year_group_key( (string) $term->name );
			if ( '' === $key ) {
				continue;
			}

			// Prefer the first term that maps to a key; keep a richer description if we see one later.
			if ( isset( $rows_by_key[ $key ] ) ) {
				if ( strlen( $text ) > strlen( $rows_by_key[ $key ]['text'] ) ) {
					$rows_by_key[ $key ]['text']  = $text;
					$rows_by_key[ $key ]['titel'] = (string) $term->name;
				}
				continue;
			}

			$rows_by_key[ $key ] = array(
				'year'  => $key,
				'titel' => (string) $term->name,
				'text'  => $text,
				'extra' => '',
			);
		}

		$rows = array_values( $rows_by_key );
		usort(
			$rows,
			static function ( array $a, array $b ): int {
				$ka = $a['year'];
				$kb = $b['year'];
				if ( is_numeric( $ka ) && is_numeric( $kb ) ) {
					return ( (float) $ka <=> (float) $kb );
				}
				return strnatcasecmp( $ka, $kb );
			}
		);

		return $rows;
	}

	/**
	 * Term IDs assigned to eligible collection items for a taxonomy.
	 *
	 * @param int    $collection_id Collection post ID.
	 * @param string $taxonomy      Taxonomy name.
	 * @return array<int, true>
	 */
	private static function term_ids_used_by_collection( int $collection_id, string $taxonomy ): array {
		$post_ids = ItemQuery::all_ids( $collection_id );
		if ( empty( $post_ids ) ) {
			return array();
		}

		$terms = wp_get_object_terms(
			$post_ids,
			$taxonomy,
			array(
				'fields' => 'ids',
			)
		);
		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			return array();
		}

		$map = array();
		foreach ( $terms as $term_id ) {
			$map[ (int) $term_id ] = true;
		}
		return $map;
	}

	/**
	 * Plain-text term description for the timeline card body.
	 *
	 * @param \WP_Term $term Term.
	 */
	private static function term_description_text( \WP_Term $term ): string {
		$raw = (string) $term->description;
		if ( '' === trim( $raw ) ) {
			return '';
		}
		$text = wp_strip_all_tags( $raw );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		return trim( $text );
	}
}
