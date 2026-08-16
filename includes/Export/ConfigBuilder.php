<?php
/**
 * Build config.json and info.md for a collection.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Export;

use VikusViewer\Support\FileWriter;
use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class ConfigBuilder
 */
final class ConfigBuilder {

	/**
	 * Write config.json and info.md.
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $item_count    Number of items in data.csv.
	 * @return string Path to config.json.
	 */
	public static function write( int $collection_id, int $item_count ): string {
		$settings = Settings::get( $collection_id );
		$dir      = Paths::ensure_collection_dir( $collection_id );
		$base_url = untrailingslashit( set_url_scheme( Paths::collection_url( $collection_id ) ) );

		$sprite_size = (int) $settings['sprite_size'];
		if ( $item_count >= 5000 && $sprite_size > 90 ) {
			$sprite_size = 90;
		}

		$columns = self::projection_columns( $item_count );

		$layouts = self::build_layouts( $settings, $columns );

		$structure = array();
		foreach ( $settings['detail_fields'] as $field ) {
			if ( empty( $field['column'] ) || empty( $field['name'] ) ) {
				continue;
			}
			$structure[] = array(
				'name'    => (string) $field['name'],
				'source'  => (string) $field['column'],
				'display' => (string) ( $field['display'] ?: 'column' ),
				'type'    => (string) ( $field['type'] ?: 'text' ),
			);
		}

		// Always expose year/keywords in the sidebar (present on every CSV row).
		$sources = array();
		foreach ( $structure as $entry ) {
			$sources[ $entry['source'] ] = true;
		}
		if ( empty( $sources['_title'] ) ) {
			array_unshift(
				$structure,
				array(
					'name'    => 'Title',
					'source'  => '_title',
					'display' => 'column',
					'type'    => 'text',
				)
			);
		}
		if ( empty( $sources['_year'] ) ) {
			$structure[] = array(
				'name'    => 'Year',
				'source'  => '_year',
				'display' => 'column',
				'type'    => 'text',
			);
		}
		if ( empty( $sources['_keywords'] ) ) {
			$structure[] = array(
				'name'    => 'Keywords',
				'source'  => '_keywords',
				'display' => 'wide',
				'type'    => 'keywords',
			);
		}
		if ( empty( $sources['_permalink'] ) ) {
			$structure[] = array(
				'name'    => 'Permalink',
				'source'  => '_permalink',
				'display' => 'wide',
				'type'    => 'link',
			);
		}

		TimelineExporter::write( $collection_id );

		$config = array(
			'project' => array(
				'name'    => $settings['project_name'],
				'quality' => 1,
			),
			'loader'  => array(
				'info'     => $base_url . '/info.md',
				'timeline' => $base_url . '/timeline.csv',
				'items'    => $base_url . '/data.csv',
				'layouts'  => $layouts,
				'textures' => array(
					'medium' => array(
						'size' => $sprite_size,
						'url'  => $base_url . '/sprites/spritesheet.json',
					),
					'detail' => array(
						'size' => (int) $settings['medium_size'],
						'csv'  => \VikusViewer\Pipeline\TextureCsv::DETAIL_COLUMN,
					),
					'big'    => ! empty( $settings['pages_enabled'] )
						? array(
							'size' => (int) $settings['large_size'],
							'url'  => $base_url . '/' . (int) $settings['large_size'] . '/',
						)
						: array(
							'size' => (int) $settings['large_size'],
							'csv'  => \VikusViewer\Pipeline\TextureCsv::BIG_COLUMN,
						),
				),
			),
			'style'   => self::config_style( $settings ),
			'projection' => array(
				'columns' => $columns,
			),
			'detail'  => array(
				'structure' => $structure,
			),
			'delimiter'     => (string) $settings['keyword_delimiter'],
			'sortKeywords'  => (string) ( $settings['sort_keywords'] ?? 'alphabetical' ),
			'searchEnabled' => ! array_key_exists( 'search_enabled', $settings )
				|| ! empty( $settings['search_enabled'] ),
		);

		if ( 'hierarchical' === $settings['filter_type'] ) {
			$config['filter'] = array( 'type' => 'hierarchical' );
		} elseif ( 'crossfilter' === $settings['filter_type'] && ! empty( $settings['crossfilter_dims'] ) ) {
			$dimensions = array();
			foreach ( $settings['crossfilter_dims'] as $dim ) {
				if ( ! is_array( $dim ) ) {
					continue;
				}
				$label  = (string) ( $dim['label'] ?? '' );
				$source = (string) ( $dim['source'] ?? '' );
				$column = Settings::crossfilter_csv_column( $source );
				if ( '' === $label || '' === $column ) {
					continue;
				}
				$dimensions[] = array(
					'label'  => $label,
					'source' => $column,
				);
			}
			if ( ! empty( $dimensions ) ) {
				$config['filter'] = array(
					'type'       => 'crossfilter',
					'dimensions' => $dimensions,
				);
			}
		}

		$path = $dir . '/config.json';
		FileWriter::put_contents( $path, (string) wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );

		$info = (string) $settings['info_markdown'];
		if ( '' === trim( $info ) ) {
			$info = '# ' . $settings['project_name'] . "\n\n" . __( 'A Vikus Viewer collection generated from WordPress.', 'vikus-viewer-embed' );
		}
		FileWriter::put_contents( $dir . '/info.md', $info );

		// Instance sidecar (IIIF-generator inspired).
		$instance = array(
			'id'             => $collection_id,
			'label'          => $settings['project_name'],
			'item_count'     => $item_count,
			'sprite_size'    => $sprite_size,
			'updated'        => time(),
			'plugin'         => 'vikus-viewer-embed',
			'plugin_version' => VIKUS_VIEWER_VERSION,
		);
		FileWriter::put_contents( $dir . '/instance.json', (string) wp_json_encode( $instance, JSON_PRETTY_PRINT ) );

		return $path;
	}

	/**
	 * Build config.loader.layouts from collection settings.
	 *
	 * @param array<string, mixed> $settings Collection settings.
	 * @param int                  $columns  Default / projection columns.
	 * @return list<array<string, mixed>>
	 */
	public static function build_layouts( array $settings, int $columns ): array {
		$raw = $settings['layouts'] ?? null;
		if ( ! is_array( $raw ) || empty( $raw ) ) {
			$raw = array(
				array(
					'title'   => 'Time',
					'type'    => 'group',
					'source'  => 'year',
					'columns' => 0,
				),
			);
		}

		$layouts = array();
		foreach ( $raw as $layout ) {
			if ( ! is_array( $layout ) ) {
				continue;
			}

			$title = sanitize_text_field( (string) ( $layout['title'] ?? '' ) );
			if ( '' === $title ) {
				continue;
			}

			$type = sanitize_key( (string) ( $layout['type'] ?? 'group' ) );
			if ( 'group' !== $type ) {
				continue;
			}

			$source    = (string) ( $layout['source'] ?? 'year' );
			$group_key = (string) ( $layout['group_key'] ?? Settings::layout_group_key( $source ) );
			if ( '' === $group_key ) {
				$group_key = 'year';
			}

			$layout_columns = (int) ( $layout['columns'] ?? 0 );
			$entry          = array(
				'title'    => $title,
				'type'     => 'group',
				'groupKey' => $group_key,
				'columns'  => $layout_columns > 0
					? max( 1, min( 120, $layout_columns ) )
					: $columns,
			);

			$layouts[] = $entry;
		}

		if ( empty( $layouts ) ) {
			$layouts[] = array(
				'title'    => 'Time',
				'type'     => 'group',
				'groupKey' => 'year',
				'columns'  => $columns,
			);
		}

		return $layouts;
	}

	/**
	 * config.style colors only (font family is applied in the WP viewer shell).
	 *
	 * @param array<string, mixed> $settings Collection settings.
	 * @return array<string, string>
	 */
	private static function config_style( array $settings ): array {
		$style = Settings::sanitize_viewer_style( $settings['viewer_style'] ?? null );
		unset( $style['fontFamily'] );
		return $style;
	}

	/**
	 * Heuristic for projection columns (from IIIF generator idea).
	 *
	 * @param int $item_count Item count.
	 */
	public static function projection_columns( int $item_count ): int {
		if ( $item_count <= 0 ) {
			return 6;
		}
		$cols = (int) floor( sqrt( $item_count * 3 ) );
		return max( 4, min( 120, $cols ) );
	}
}
