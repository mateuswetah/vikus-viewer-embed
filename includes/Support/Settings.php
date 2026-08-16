<?php
/**
 * Collection settings stored as post meta.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

/**
 * Class Settings
 */
final class Settings {

	public const META_KEY = '_vikus_collection_settings';
	public const BUILD_META_KEY = '_vikus_build_status';
	public const DIRTY_META_KEY = '_vikus_needs_rebuild';

	/**
	 * Default settings for a new collection.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'source_post_type'   => 'post',
			'require_thumbnail'  => true,
			'keyword_source'     => 'taxonomy', // taxonomy | meta
			'keyword_taxonomies' => array( 'post_tag' ),
			'keyword_meta_key'   => '',
			'keyword_delimiter'  => ',',
			'year_source'        => 'post_date', // post_date | taxonomy | meta — fills CSV `year` (group axis)
			'year_taxonomy'      => '',
			'year_meta_key'      => '',
			// Taxonomy year source: also emit timeline rows for terms with no items (empty columns).
			'timeline_include_unused' => false,
			// Viewer navigation layouts (config.loader.layouts). Group only for now.
			'layouts'            => array(
				array(
					'title'   => 'Time',
					'type'    => 'group',
					'source'  => 'year',
					'columns' => 0, // 0 = automatic from collection size
				),
			),
			'filter_type'        => 'default', // default | hierarchical | crossfilter
			'crossfilter_dims'   => array(),
			'detail_fields'      => array(
				array(
					'name'    => 'Title',
					'source'  => 'title',
					'column'  => '_title',
					'type'    => 'text',
					'display' => 'column',
				),
				array(
					'name'    => 'Permalink',
					'source'  => 'permalink',
					'column'  => '_permalink',
					'type'    => 'link',
					'display' => 'wide',
				),
			),
			'project_name'       => '',
			'info_markdown'      => '',
			'sprite_size'        => 128,
			'large_size'         => 4096,
			'medium_size'        => 1024,
			'sheet_dimension'    => 2048,
			'batch_size'         => 15,
			'search_enabled'     => true,
			'pages_enabled'      => false,
			'sort_keywords'      => 'alphabetical', // alphabetical | alphabetical-reverse | count | count-reverse
			'viewer_style'       => self::default_viewer_style(),
		);
	}

	/**
	 * Default config.style colors (neutral, WCAG AAA+).
	 *
	 * Near-black / white pairs stay above 7:1 without pure-black halation.
	 * Search input text is hardcoded white in stock Vikus CSS, so the
	 * search bar background stays dark. Detail sidebar text is similarly
	 * hardcoded dark, so that panel stays light.
	 *
	 * @return array<string, string>
	 */
	public static function default_viewer_style(): array {
		return array(
			'fontColor'           => '#111111',
			'fontColorActive'     => '#ffffff',
			'fontBackground'      => '#111111',
			'textShadow'          => '1px 1px 0px #f0f0f1',
			'canvasBackground'    => '#f0f0f1',
			'timelineBackground'  => '#ffffff',
			'timelineFontColor'   => '#111111',
			'detailBackground'    => '#ffffff',
			'infoBackground'      => '#111111',
			'infoFontColor'       => '#ffffff',
			'searchbarBackground' => '#111111',
			'fontFamily'          => 'lato',
		);
	}

	/**
	 * Sanitize config.style values written into the viewer config.
	 *
	 * @param mixed $raw Raw style map.
	 * @return array<string, string>
	 */
	public static function sanitize_viewer_style( $raw ): array {
		$defaults = self::default_viewer_style();
		$in       = is_array( $raw ) ? $raw : array();
		$out      = array();

		foreach ( $defaults as $key => $default ) {
			$value = isset( $in[ $key ] ) ? (string) $in[ $key ] : $default;
			if ( 'textShadow' === $key ) {
				$clean = sanitize_text_field( $value );
				$out[ $key ] = '' !== $clean ? $clean : $default;
				continue;
			}
			if ( 'fontFamily' === $key ) {
				$out[ $key ] = self::sanitize_font_family_slug( $value );
				continue;
			}
			$hex = sanitize_hex_color( $value );
			if ( ! $hex && is_string( $value ) && preg_match( '/^#?[0-9a-fA-F]{3}$/', $value ) ) {
				$digits = ltrim( $value, '#' );
				$hex    = sanitize_hex_color( '#' . $digits[0] . $digits[0] . $digits[1] . $digits[1] . $digits[2] . $digits[2] );
			}
			$out[ $key ] = $hex ? $hex : $default;
		}

		return $out;
	}

	/**
	 * Theme / global-styles font families plus the bundled Vikus default.
	 *
	 * @return list<array{slug:string,name:string,fontFamily:string}>
	 */
	public static function font_family_options(): array {
		$out = array(
			array(
				'slug'       => 'lato',
				'name'       => __( 'Lato (Vikus default)', 'vikus-viewer-embed' ),
				'fontFamily' => 'Lato, sans-serif',
			),
		);

		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return $out;
		}

		$raw = wp_get_global_settings( array( 'typography', 'fontFamilies' ) );
		if ( ! is_array( $raw ) ) {
			return $out;
		}

		$origin_labels = array(
			'theme'   => __( 'Theme', 'vikus-viewer-embed' ),
			'default' => __( 'Default', 'vikus-viewer-embed' ),
			'custom'  => __( 'Custom', 'vikus-viewer-embed' ),
		);

		$seen = array( 'lato' => true );
		foreach ( $origin_labels as $origin => $label ) {
			$entries = $raw[ $origin ] ?? null;
			if ( ! is_array( $entries ) ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$slug  = sanitize_title( (string) ( $entry['slug'] ?? '' ) );
				$stack = trim( (string) ( $entry['fontFamily'] ?? '' ) );
				if ( '' === $slug || '' === $stack || isset( $seen[ $slug ] ) ) {
					continue;
				}
				$seen[ $slug ] = true;
				$name          = sanitize_text_field( (string) ( $entry['name'] ?? $slug ) );
				$out[]         = array(
					'slug'       => $slug,
					'name'       => sprintf(
						/* translators: 1: font name, 2: origin (Theme/Default/Custom). */
						__( '%1$s (%2$s)', 'vikus-viewer-embed' ),
						$name,
						$label
					),
					'fontFamily' => $stack,
				);
			}
		}

		return $out;
	}

	/**
	 * CSS font-family stack for a stored slug. Empty string keeps bundled Lato.
	 *
	 * @param string $slug Font family slug.
	 */
	public static function font_family_stack( string $slug ): string {
		$slug = self::sanitize_font_family_slug( $slug );
		if ( 'lato' === $slug ) {
			return '';
		}
		foreach ( self::font_family_options() as $option ) {
			if ( $option['slug'] === $slug ) {
				return (string) $option['fontFamily'];
			}
		}
		return '';
	}

	/**
	 * @param string $slug Raw slug.
	 */
	private static function sanitize_font_family_slug( string $slug ): string {
		$slug = sanitize_title( $slug );
		if ( '' === $slug || 'lato' === $slug ) {
			return 'lato';
		}
		foreach ( self::font_family_options() as $option ) {
			if ( $option['slug'] === $slug ) {
				return $slug;
			}
		}
		return 'lato';
	}

	/**
	 * Get settings for a collection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array<string, mixed>
	 */
	public static function get( int $collection_id ): array {
		$stored = get_post_meta( $collection_id, self::META_KEY, true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$settings = array_merge( self::defaults(), $stored );

		// Empty array overwrites defaults via array_merge string keys — restore sensible detail fields.
		if ( empty( $settings['detail_fields'] ) || ! is_array( $settings['detail_fields'] ) ) {
			$settings['detail_fields'] = self::defaults()['detail_fields'];
		}

		if ( empty( $settings['layouts'] ) || ! is_array( $settings['layouts'] ) ) {
			$settings['layouts'] = self::defaults()['layouts'];
		}
		$settings['layouts'] = self::sanitize_layouts(
			$settings['layouts'],
			array()
		);

		if ( '' === (string) $settings['project_name'] ) {
			$post = get_post( $collection_id );
			$settings['project_name'] = $post ? $post->post_title : 'Vikus Collection';
		}

		$settings['viewer_style'] = self::sanitize_viewer_style( $settings['viewer_style'] ?? null );

		return $settings;
	}

	/**
	 * Save settings for a collection.
	 *
	 * @param int                  $collection_id Collection post ID.
	 * @param array<string, mixed> $settings      Settings array.
	 */
	public static function update( int $collection_id, array $settings ): void {
		$clean = self::sanitize( $settings );
		update_post_meta( $collection_id, self::META_KEY, $clean );
	}

	/**
	 * Sanitize settings from admin input.
	 *
	 * @param array<string, mixed> $settings Raw settings.
	 * @return array<string, mixed>
	 */
	public static function sanitize( array $settings ): array {
		$defaults = self::defaults();
		$out      = $defaults;

		$source_pt = sanitize_key( (string) ( $settings['source_post_type'] ?? $defaults['source_post_type'] ) );
		$public_pts = get_post_types( array( 'public' => true ), 'names' );
		unset( $public_pts[ \VikusViewer\PostType\Collection::POST_TYPE ] );
		if ( ! isset( $public_pts[ $source_pt ] ) ) {
			$source_pt = isset( $public_pts['post'] ) ? 'post' : (string) ( array_key_first( $public_pts ) ?: 'post' );
		}
		$out['source_post_type']  = $source_pt;
		$out['require_thumbnail'] = ! empty( $settings['require_thumbnail'] );

		$allowed_taxes = array();
		foreach ( get_object_taxonomies( $source_pt, 'objects' ) as $taxonomy ) {
			if ( ! empty( $taxonomy->public ) ) {
				$allowed_taxes[ $taxonomy->name ] = true;
			}
		}

		$taxonomies = $settings['keyword_taxonomies'] ?? $defaults['keyword_taxonomies'];
		if ( is_string( $taxonomies ) ) {
			$taxonomies = array_filter( array_map( 'trim', explode( ',', $taxonomies ) ) );
		}
		if ( ! is_array( $taxonomies ) ) {
			$taxonomies = array();
		}
		$clean_taxes = array_values(
			array_filter(
				array_map( 'sanitize_key', $taxonomies ),
				static function ( string $tax ) use ( $allowed_taxes ): bool {
					return isset( $allowed_taxes[ $tax ] );
				}
			)
		);

		$meta_keys               = self::sanitize_meta_key_list( $settings['keyword_meta_key'] ?? '' );
		$out['keyword_meta_key'] = $meta_keys[0] ?? '';

		$keyword_source = sanitize_key( (string) ( $settings['keyword_source'] ?? 'taxonomy' ) );
		if ( ! in_array( $keyword_source, array( 'taxonomy', 'meta' ), true ) ) {
			$keyword_source = 'taxonomy';
		}
		$out['keyword_source'] = $keyword_source;

		$delimiter = (string) ( $settings['keyword_delimiter'] ?? ',' );
		$out['keyword_delimiter'] = in_array( $delimiter, array( ',', ';' ), true ) ? $delimiter : ',';

		$year_source = sanitize_key( (string) ( $settings['year_source'] ?? 'post_date' ) );
		if ( ! in_array( $year_source, array( 'post_date', 'taxonomy', 'meta' ), true ) ) {
			$year_source = 'post_date';
		}
		$out['year_source']   = $year_source;
		$year_taxonomy        = sanitize_key( (string) ( $settings['year_taxonomy'] ?? '' ) );
		$out['year_taxonomy'] = ( '' !== $year_taxonomy && isset( $allowed_taxes[ $year_taxonomy ] ) ) ? $year_taxonomy : '';
		$year_meta_keys       = self::sanitize_meta_key_list( array( (string) ( $settings['year_meta_key'] ?? '' ) ) );
		$out['year_meta_key'] = $year_meta_keys[0] ?? '';

		$out['timeline_include_unused'] = ! empty( $settings['timeline_include_unused'] );

		$filter = sanitize_key( (string) ( $settings['filter_type'] ?? 'default' ) );
		if ( ! in_array( $filter, array( 'default', 'hierarchical', 'crossfilter' ), true ) ) {
			$filter = 'default';
		}
		$out['filter_type'] = $filter;

		// Keyword taxonomies: at most one; hierarchical mode requires a hierarchical taxonomy.
		if ( 'hierarchical' === $filter ) {
			$clean_taxes = array_values(
				array_filter(
					$clean_taxes,
					static function ( string $tax ): bool {
						return is_taxonomy_hierarchical( $tax );
					}
				)
			);
			$out['keyword_source'] = 'taxonomy';
			$out['keyword_meta_key'] = '';
		}
		if ( in_array( $filter, array( 'default', 'hierarchical' ), true ) ) {
			$clean_taxes = array_slice( $clean_taxes, 0, 1 );
		}
		$out['keyword_taxonomies'] = $clean_taxes;

		$dims = $settings['crossfilter_dims'] ?? array();
		if ( ! is_array( $dims ) ) {
			$dims = array();
		}
		$clean_dims = array();
		foreach ( $dims as $dim ) {
			if ( ! is_array( $dim ) ) {
				continue;
			}
			$label  = sanitize_text_field( (string) ( $dim['label'] ?? '' ) );
			$source = self::sanitize_crossfilter_source(
				(string) ( $dim['source'] ?? '' ),
				$allowed_taxes
			);
			if ( '' === $label || '' === $source ) {
				continue;
			}
			$clean_dims[] = array(
				'label'  => $label,
				'source' => $source,
			);
		}
		$out['crossfilter_dims'] = $clean_dims;

		$fields = $settings['detail_fields'] ?? $defaults['detail_fields'];
		if ( ! is_array( $fields ) ) {
			$fields = $defaults['detail_fields'];
		}
		$clean_fields = array();
		$used_columns = array();
		foreach ( $fields as $field ) {
			if ( ! is_array( $field ) ) {
				continue;
			}
			$name   = sanitize_text_field( (string) ( $field['name'] ?? '' ) );
			$source = sanitize_text_field( (string) ( $field['source'] ?? '' ) );
			if ( '' === $name || '' === $source ) {
				continue;
			}

			// Column is an internal CSV/detail key — derived from source (not user-facing).
			$column = self::detail_column_for_source( $source, $name );
			$base   = $column;
			$suffix = 2;
			while ( isset( $used_columns[ $column ] ) ) {
				$column = $base . '_' . $suffix;
				++$suffix;
			}
			$used_columns[ $column ] = true;

			$type = sanitize_key( (string) ( $field['type'] ?? 'text' ) );
			if ( ! in_array( $type, array( 'text', 'markdown', 'link', 'keywords' ), true ) ) {
				$type = 'text';
			}
			$display = sanitize_key( (string) ( $field['display'] ?? 'column' ) );
			if ( ! in_array( $display, array( 'column', 'wide' ), true ) ) {
				$display = 'column';
			}
			$clean_fields[] = array(
				'name'    => $name,
				'source'  => $source,
				'column'  => $column,
				'type'    => $type,
				'display' => $display,
			);
		}
		$out['detail_fields'] = $clean_fields;
		$out['layouts']       = self::sanitize_layouts(
			$settings['layouts'] ?? null,
			$allowed_taxes
		);

		$out['project_name']  = sanitize_text_field( (string) ( $settings['project_name'] ?? '' ) );
		$out['info_markdown'] = sanitize_textarea_field( (string) ( $settings['info_markdown'] ?? '' ) );

		$out['sprite_size']     = max( 32, min( 256, (int) ( $settings['sprite_size'] ?? 128 ) ) );
		$out['large_size']      = max( 512, min( 8192, (int) ( $settings['large_size'] ?? 4096 ) ) );
		$out['medium_size']     = max( 256, min( 2048, (int) ( $settings['medium_size'] ?? 1024 ) ) );
		$out['sheet_dimension'] = max( 512, min( 4096, (int) ( $settings['sheet_dimension'] ?? 2048 ) ) );
		$out['batch_size']      = max( 1, min( 100, (int) ( $settings['batch_size'] ?? 15 ) ) );

		$out['search_enabled'] = array_key_exists( 'search_enabled', $settings )
			? (bool) $settings['search_enabled']
			: true;

		$out['pages_enabled'] = ! empty( $settings['pages_enabled'] );

		$sort_keywords = sanitize_key( (string) ( $settings['sort_keywords'] ?? 'alphabetical' ) );
		if ( ! in_array(
			$sort_keywords,
			array( 'alphabetical', 'alphabetical-reverse', 'count', 'count-reverse' ),
			true
		) ) {
			$sort_keywords = 'alphabetical';
		}
		$out['sort_keywords'] = $sort_keywords;
		$out['viewer_style']  = self::sanitize_viewer_style( $settings['viewer_style'] ?? null );

		return $out;
	}

	/**
	 * Sanitize viewer layout rows (group layouts only for now).
	 *
	 * Index 0 is always the primary layout bound to the CSV `year` column
	 * (configured via year_source / year_taxonomy / year_meta_key). Extra
	 * layouts may group by post_date (year), taxonomy, or meta.
	 *
	 * @param mixed              $layouts       Raw layouts.
	 * @param array<string,bool> $allowed_taxes Public taxonomies for the source post type.
	 * @return list<array{title:string,type:string,source:string,group_key:string,columns:int}>
	 */
	public static function sanitize_layouts( $layouts, array $allowed_taxes = array() ): array {
		$default_primary = self::with_layout_group_keys( self::defaults()['layouts'] )[0];

		$primary = null;
		$extra   = array();
		$seen_titles  = array();
		$seen_sources = array( 'year' => true );

		if ( is_array( $layouts ) ) {
			foreach ( $layouts as $layout ) {
				if ( ! is_array( $layout ) ) {
					continue;
				}

				$type = sanitize_key( (string) ( $layout['type'] ?? 'group' ) );
				if ( 'group' !== $type ) {
					continue;
				}

				$title = sanitize_text_field( (string) ( $layout['title'] ?? '' ) );
				$source = self::sanitize_layout_source(
					(string) ( $layout['source'] ?? '' ),
					$allowed_taxes
				);

				$columns = max( 0, min( 120, (int) ( $layout['columns'] ?? 0 ) ) );

				// First year layout becomes the primary; later year rows are dropped.
				if ( 'year' === $source ) {
					if ( null !== $primary ) {
						continue;
					}
					if ( '' === $title ) {
						$title = (string) $default_primary['title'];
					}
					$primary = array(
						'title'     => $title,
						'type'      => 'group',
						'source'    => 'year',
						'group_key' => 'year',
						'columns'   => $columns,
					);
					$seen_titles[ strtolower( $title ) ] = true;
					continue;
				}

				if ( '' === $title ) {
					continue;
				}

				// Each additional group source may only appear once.
				if ( isset( $seen_sources[ $source ] ) ) {
					continue;
				}
				$seen_sources[ $source ] = true;

				$base   = $title;
				$suffix = 2;
				while ( isset( $seen_titles[ strtolower( $title ) ] ) ) {
					$title = $base . ' ' . $suffix;
					++$suffix;
				}
				$seen_titles[ strtolower( $title ) ] = true;

				$extra[] = array(
					'title'     => $title,
					'type'      => 'group',
					'source'    => $source,
					'group_key' => self::layout_group_key( $source ),
					'columns'   => $columns,
				);
			}
		}

		if ( null === $primary ) {
			$primary = $default_primary;
			$seen_titles[ strtolower( (string) $primary['title'] ) ] = true;
			// Re-unique extra titles against the restored primary title.
			$renamed = array();
			foreach ( $extra as $row ) {
				$title  = (string) $row['title'];
				$base   = $title;
				$suffix = 2;
				while ( isset( $seen_titles[ strtolower( $title ) ] ) ) {
					$title = $base . ' ' . $suffix;
					++$suffix;
				}
				$seen_titles[ strtolower( $title ) ] = true;
				$row['title'] = $title;
				$renamed[]    = $row;
			}
			$extra = $renamed;
		}

		return array_merge( array( $primary ), $extra );
	}

	/**
	 * Ensure default layouts include derived group_key.
	 *
	 * @param list<array<string,mixed>> $layouts Layouts.
	 * @return list<array<string,mixed>>
	 */
	private static function with_layout_group_keys( array $layouts ): array {
		$out = array();
		foreach ( $layouts as $layout ) {
			$source             = (string) ( $layout['source'] ?? 'year' );
			$layout['source']   = $source;
			$layout['group_key'] = self::layout_group_key( $source );
			$out[]              = $layout;
		}
		return $out;
	}

	/**
	 * Normalize a layout source (year | post_date | taxonomy:… | meta:…).
	 *
	 * @param string             $source        Preferred source.
	 * @param array<string,bool> $allowed_taxes Allowed taxonomies.
	 */
	public static function sanitize_layout_source(
		string $source,
		array $allowed_taxes = array()
	): string {
		$source = trim( $source );

		if ( in_array( $source, array( 'year', 'post_date' ), true ) ) {
			return $source;
		}

		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			$tax = sanitize_key( substr( $source, 9 ) );
			if ( '' !== $tax && ( empty( $allowed_taxes ) || isset( $allowed_taxes[ $tax ] ) ) ) {
				return 'taxonomy:' . $tax;
			}
			return 'year';
		}

		if ( 0 === strpos( $source, 'meta:' ) ) {
			$keys = self::sanitize_meta_key_list( array( substr( $source, 5 ) ) );
			if ( ! empty( $keys[0] ) ) {
				return 'meta:' . $keys[0];
			}
			return 'year';
		}

		return 'year';
	}

	/**
	 * CSV column used as Vikus groupKey for a layout source.
	 *
	 * @param string $source Layout source.
	 */
	public static function layout_group_key( string $source ): string {
		$source = trim( $source );
		if ( 'year' === $source ) {
			return 'year';
		}
		return self::detail_column_for_source( $source );
	}

	/**
	 * Sanitize a crossfilter dimension source (post_date | taxonomy:… | meta:…).
	 *
	 * @param string             $source        Raw source.
	 * @param array<string,bool> $allowed_taxes Allowed taxonomies.
	 */
	public static function sanitize_crossfilter_source( string $source, array $allowed_taxes = array() ): string {
		$source = trim( $source );
		if ( 'post_date' === $source ) {
			return 'post_date';
		}
		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			$tax = sanitize_key( substr( $source, 9 ) );
			return ( '' !== $tax && isset( $allowed_taxes[ $tax ] ) ) ? 'taxonomy:' . $tax : '';
		}
		if ( 0 === strpos( $source, 'meta:' ) ) {
			$key = self::sanitize_meta_key_list( array( substr( $source, 5 ) ) );
			return ! empty( $key[0] ) ? 'meta:' . $key[0] : '';
		}
		return '';
	}

	/**
	 * CSV column name for a crossfilter dimension WP source.
	 *
	 * @param string $source Sanitized crossfilter source.
	 */
	public static function crossfilter_csv_column( string $source ): string {
		$source = trim( $source );
		if ( 'post_date' === $source ) {
			return '_post_date';
		}
		$col = self::detail_column_for_source( $source );
		return '' !== $col ? $col : '';
	}

	/**
	 * Derive a stable CSV / detail.structure column from a field source.
	 *
	 * Prefer meta/taxonomy identifiers over the display label so renaming the
	 * label does not change the column. Built-in sources map to conventional keys.
	 *
	 * @param string $source Field source (title, meta:key, taxonomy:slug, …).
	 * @param string $name   Display label (fallback for unknown sources).
	 */
	public static function detail_column_for_source( string $source, string $name = '' ): string {
		$source = trim( $source );

		if ( 0 === strpos( $source, 'meta:' ) ) {
			return '_' . self::slug_for_detail_column( substr( $source, 5 ) );
		}
		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			return '_' . self::slug_for_detail_column( substr( $source, 9 ) );
		}

		$builtins = array(
			'title'     => '_title',
			'excerpt'   => '_excerpt',
			'content'   => '_content',
			'permalink' => '_permalink',
			'post_date' => '_post_date',
			'post_type' => '_post_type',
			'year'      => '_year',
			'keywords'  => '_keywords',
		);
		if ( isset( $builtins[ $source ] ) ) {
			return $builtins[ $source ];
		}

		$fallback = '' !== trim( $name ) ? $name : 'field';
		return '_' . self::slug_for_detail_column( $fallback );
	}

	/**
	 * Slugify a string for use as a detail CSV column segment.
	 *
	 * Unlike sanitize_key(), this keeps leading digits (Tainacan metadatum IDs).
	 *
	 * @param string $raw Raw identifier.
	 */
	private static function slug_for_detail_column( string $raw ): string {
		$raw = strtolower( trim( $raw ) );
		$raw = preg_replace( '/[^a-z0-9_]+/', '_', $raw ) ?? '';
		$raw = trim( $raw, '_' );
		return '' !== $raw ? $raw : 'field';
	}

	/**
	 * Sanitize a list of post meta key names (preserves leading underscores).
	 *
	 * @param mixed $raw Array, newline/comma-separated string, or single key.
	 * @return string[]
	 */
	public static function sanitize_meta_key_list( $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = preg_split( '/\r\n|\r|\n|,/', $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$keys = array();
		foreach ( $raw as $key ) {
			if ( ! is_scalar( $key ) ) {
				continue;
			}
			$key = sanitize_text_field( (string) $key );
			$key = trim( $key );
			if ( '' === $key ) {
				continue;
			}
			// Meta keys must be plain identifiers; reject path-like values.
			if ( ! preg_match( '/^[A-Za-z0-9_.:-]+$/', $key ) ) {
				continue;
			}
			$keys[ $key ] = true;
		}

		return array_keys( $keys );
	}

	/**
	 * Default / empty build status.
	 *
	 * @return array<string, mixed>
	 */
	public static function default_build_status(): array {
		return array(
			'status'           => 'idle', // idle|queued|running|complete|failed|cancelled
			'step'             => '',
			'force'            => false,
			'completed'        => 0,
			'total'            => 0,
			'processed'        => 0,
			'errors'           => 0,
			'texture_errors'   => array(),
			'texture_reuse'    => array(
				'detail_wp'        => 0,
				'detail_generated' => 0,
				'big_wp'           => 0,
				'big_generated'    => 0,
			),
			'message'          => '',
			'last_error'       => '',
			'started_at'       => 0,
			'updated_at'       => 0,
			'finished_at'      => 0,
			'cursor'           => 0,
			'item_ids'         => array(),
			'cancel_requested' => false,
		);
	}

	/**
	 * Get build status.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array<string, mixed>
	 */
	public static function get_build_status( int $collection_id ): array {
		$stored = get_post_meta( $collection_id, self::BUILD_META_KEY, true );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		return array_merge( self::default_build_status(), $stored );
	}

	/**
	 * Update build status.
	 *
	 * @param int                  $collection_id Collection post ID.
	 * @param array<string, mixed> $status        Status partial.
	 * @return array<string, mixed>
	 */
	public static function update_build_status( int $collection_id, array $status ): array {
		$current = self::get_build_status( $collection_id );

		$clearing_cancel = array_key_exists( 'cancel_requested', $status ) && ! $status['cancel_requested'];
		$is_new_queue    = $clearing_cancel && isset( $status['status'] ) && 'queued' === $status['status'];

		// Cooperative cancel: ignore "running" progress writes until a fresh queue().
		if ( ! empty( $current['cancel_requested'] ) && ! $is_new_queue ) {
			if ( isset( $status['status'] ) && 'running' === $status['status'] ) {
				unset( $status['status'] );
			}
			$merged                       = array_merge( $current, $status );
			$merged['status']             = 'cancelled';
			$merged['cancel_requested']   = true;
			$merged['updated_at']         = time();
			update_post_meta( $collection_id, self::BUILD_META_KEY, $merged );
			self::write_build_status_sidecar( $collection_id, $merged );
			return $merged;
		}

		$merged               = array_merge( $current, $status );
		$merged['updated_at'] = time();
		update_post_meta( $collection_id, self::BUILD_META_KEY, $merged );
		self::write_build_status_sidecar( $collection_id, $merged );

		return $merged;
	}

	/**
	 * Persist build-status.json next to collection assets.
	 *
	 * @param int                  $collection_id Collection ID.
	 * @param array<string, mixed> $merged        Status.
	 */
	private static function write_build_status_sidecar( int $collection_id, array $merged ): void {
		Paths::ensure_collection_dir( $collection_id );
		$file = Paths::file( $collection_id, 'build-status.json' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $file, wp_json_encode( $merged, JSON_PRETTY_PRINT ) );
	}

	/**
	 * Whether the collection is marked dirty.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function is_dirty( int $collection_id ): bool {
		return (bool) get_post_meta( $collection_id, self::DIRTY_META_KEY, true );
	}

	/**
	 * Mark collection dirty / clean.
	 *
	 * @param int  $collection_id Collection post ID.
	 * @param bool $dirty         Dirty flag.
	 */
	public static function set_dirty( int $collection_id, bool $dirty = true ): void {
		if ( $dirty ) {
			update_post_meta( $collection_id, self::DIRTY_META_KEY, 1 );
		} else {
			delete_post_meta( $collection_id, self::DIRTY_META_KEY );
		}
	}
}
