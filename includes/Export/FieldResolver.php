<?php
/**
 * Resolve field values from a source post.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Export;

use VikusViewer\Support\Settings;

/**
 * Class FieldResolver
 */
final class FieldResolver {

	/**
	 * Build a CSV row for a post.
	 *
	 * @param \WP_Post             $post     Source post.
	 * @param array<string, mixed> $settings Collection settings.
	 * @return array<string, string>
	 */
	public static function row( \WP_Post $post, array $settings ): array {
		$row = array(
			'id'       => (string) $post->ID,
			'keywords' => self::keywords( $post, $settings ),
			'year'     => self::year( $post, $settings ),
			// Always present so the default sidebar works even if mapping omits them.
			'_title'     => get_the_title( $post ),
			'_permalink' => (string) get_permalink( $post ),
		);

		foreach ( $settings['detail_fields'] as $field ) {
			$column = (string) $field['column'];
			$row[ $column ] = self::resolve_source(
				$post,
				(string) $field['source'],
				$settings,
				(string) ( $field['type'] ?? 'text' )
			);
		}

		foreach ( $settings['layouts'] ?? array() as $layout ) {
			$source = (string) ( $layout['source'] ?? 'year' );
			if ( 'year' === $source ) {
				continue;
			}
			$column = (string) ( $layout['group_key'] ?? Settings::layout_group_key( $source ) );
			if ( '' === $column || isset( $row[ $column ] ) ) {
				continue;
			}
			// Group layouts use year-of-date, same as primary post_date → year.
			if ( 'post_date' === $source ) {
				$row[ $column ] = self::year_from_date( (string) $post->post_date );
			} else {
				$row[ $column ] = self::resolve_source( $post, $source, $settings, 'text' );
			}
		}

		foreach ( $settings['crossfilter_dims'] ?? array() as $dim ) {
			if ( ! is_array( $dim ) ) {
				continue;
			}
			$source = (string) ( $dim['source'] ?? '' );
			$column = Settings::crossfilter_csv_column( $source );
			if ( '' === $column || isset( $row[ $column ] ) ) {
				continue;
			}
			if ( 'post_date' === $source ) {
				$row[ $column ] = self::year_from_date( (string) $post->post_date );
			} else {
				$row[ $column ] = self::resolve_source( $post, $source, $settings, 'text' );
			}
		}

		return $row;
	}

	/**
	 * Build keywords string from taxonomies, post meta, or first crossfilter dim.
	 *
	 * @param \WP_Post             $post     Source post.
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function keywords( \WP_Post $post, array $settings ): string {
		$delimiter   = (string) ( $settings['keyword_delimiter'] ?: ',' );
		$filter_type = (string) ( $settings['filter_type'] ?? 'default' );

		if ( 'crossfilter' === $filter_type ) {
			$first = $settings['crossfilter_dims'][0] ?? null;
			if ( ! is_array( $first ) || empty( $first['source'] ) ) {
				return '';
			}
			return self::keywords_from_crossfilter_source(
				$post,
				(string) $first['source'],
				$settings,
				$delimiter
			);
		}

		$source = (string) ( $settings['keyword_source'] ?? 'taxonomy' );

		if ( 'meta' === $source ) {
			$meta_key = (string) ( $settings['keyword_meta_key'] ?? '' );
			if ( '' === $meta_key ) {
				return '';
			}
			$keywords = self::keywords_from_meta( $post->ID, $meta_key, $delimiter );
			$keywords = array_values( array_unique( array_filter( array_map( 'trim', $keywords ) ) ) );
			return implode( $delimiter, $keywords );
		}

		$keywords     = array();
		$hierarchical = 'hierarchical' === $filter_type;
		$taxonomies   = $settings['keyword_taxonomies'] ?? array();
		if ( ! is_array( $taxonomies ) ) {
			return '';
		}

		foreach ( $taxonomies as $taxonomy ) {
			$terms = get_the_terms( $post, (string) $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				continue;
			}

			foreach ( $terms as $term ) {
				if ( $hierarchical && is_taxonomy_hierarchical( (string) $taxonomy ) ) {
					$keywords[] = self::term_path( $term, (string) $taxonomy );
				} else {
					$keywords[] = $term->name;
				}
			}
		}

		$keywords = array_values( array_unique( array_filter( array_map( 'trim', $keywords ) ) ) );
		return implode( $delimiter, $keywords );
	}

	/**
	 * Build keywords from a crossfilter dimension source.
	 *
	 * @param \WP_Post             $post      Source post.
	 * @param string               $source    post_date | taxonomy:… | meta:….
	 * @param array<string, mixed> $settings  Settings.
	 * @param string               $delimiter Keyword delimiter.
	 */
	public static function keywords_from_crossfilter_source(
		\WP_Post $post,
		string $source,
		array $settings,
		string $delimiter
	): string {
		if ( 'post_date' === $source ) {
			return self::year_from_date( (string) $post->post_date );
		}
		if ( 0 === strpos( $source, 'meta:' ) ) {
			$meta_key = substr( $source, 5 );
			$keywords = self::keywords_from_meta( $post->ID, $meta_key, $delimiter );
			$keywords = array_values( array_unique( array_filter( array_map( 'trim', $keywords ) ) ) );
			return implode( $delimiter, $keywords );
		}
		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			$taxonomy = substr( $source, 9 );
			$terms    = get_the_terms( $post, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				return '';
			}
			$names = array();
			foreach ( $terms as $term ) {
				$names[] = $term->name;
			}
			$names = array_values( array_unique( array_filter( array_map( 'trim', $names ) ) ) );
			return implode( $delimiter, $names );
		}
		return '';
	}

	/**
	 * Extract keyword tokens from a post meta key.
	 *
	 * String values are split on the collection delimiter; array values become
	 * one token per scalar element (also split if a string).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $meta_key  Meta key.
	 * @param string $delimiter Keyword delimiter.
	 * @return string[]
	 */
	public static function keywords_from_meta( int $post_id, string $meta_key, string $delimiter ): array {
		$values = get_post_meta( $post_id, $meta_key, false );
		if ( ! is_array( $values ) || empty( $values ) ) {
			return array();
		}

		$parts = array();
		foreach ( $values as $value ) {
			foreach ( self::flatten_meta_keyword_value( $value, $delimiter ) as $part ) {
				$parts[] = $part;
			}
		}

		return $parts;
	}

	/**
	 * Flatten a meta value into keyword tokens.
	 *
	 * @param mixed  $value     Meta value.
	 * @param string $delimiter Delimiter.
	 * @return string[]
	 */
	private static function flatten_meta_keyword_value( $value, string $delimiter ): array {
		if ( is_array( $value ) ) {
			$parts = array();
			foreach ( $value as $item ) {
				foreach ( self::flatten_meta_keyword_value( $item, $delimiter ) as $part ) {
					$parts[] = $part;
				}
			}
			return $parts;
		}

		if ( is_bool( $value ) || null === $value || is_object( $value ) ) {
			return array();
		}

		$text = trim( (string) $value );
		if ( '' === $text ) {
			return array();
		}

		if ( '' === $delimiter || false === strpos( $text, $delimiter ) ) {
			return array( $text );
		}

		return array_values(
			array_filter(
				array_map( 'trim', explode( $delimiter, $text ) ),
				static function ( string $part ): bool {
					return '' !== $part;
				}
			)
		);
	}

	/**
	 * Ancestor:…:Term path for hierarchical taxonomies.
	 *
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public static function term_path( \WP_Term $term, string $taxonomy ): string {
		$names   = array( $term->name );
		$parent  = (int) $term->parent;
		$guard   = 0;

		while ( $parent > 0 && $guard < 20 ) {
			$parent_term = get_term( $parent, $taxonomy );
			if ( ! $parent_term || is_wp_error( $parent_term ) ) {
				break;
			}
			array_unshift( $names, $parent_term->name );
			$parent = (int) $parent_term->parent;
			++$guard;
		}

		return implode( ':', $names );
	}

	/**
	 * Resolve year value.
	 *
	 * @param \WP_Post             $post     Source post.
	 * @param array<string, mixed> $settings Settings.
	 */
	public static function year( \WP_Post $post, array $settings ): string {
		switch ( $settings['year_source'] ) {
			case 'taxonomy':
				$taxonomy = (string) $settings['year_taxonomy'];
				if ( '' === $taxonomy ) {
					return self::year_from_date( $post->post_date );
				}
				$terms = get_the_terms( $post, $taxonomy );
				if ( empty( $terms ) || is_wp_error( $terms ) ) {
					return '';
				}
				return self::extract_year( (string) $terms[0]->name );

			case 'meta':
				$key = (string) $settings['year_meta_key'];
				if ( '' === $key ) {
					return self::year_from_date( $post->post_date );
				}
				$value = get_post_meta( $post->ID, $key, true );
				return self::extract_year( (string) $value );

			case 'post_date':
			default:
				return self::year_from_date( $post->post_date );
		}
	}

	/**
	 * Turn a raw meta value (scalar, list, or nested) into a single CSV cell string.
	 *
	 * Handles multi-row post meta, serialized arrays, ACF value/label pairs,
	 * post/term-like arrays, and WP_Post objects.
	 *
	 * @param mixed  $value     Meta value.
	 * @param string $separator Join separator for lists.
	 */
	public static function stringify_meta_value( $value, string $separator = ', ' ): string {
		if ( null === $value || is_bool( $value ) ) {
			return '';
		}

		if ( $value instanceof \WP_Post ) {
			return get_the_title( $value );
		}

		if ( is_object( $value ) ) {
			if ( isset( $value->post_title ) && is_scalar( $value->post_title ) ) {
				return trim( (string) $value->post_title );
			}
			if ( isset( $value->name ) && is_scalar( $value->name ) ) {
				return trim( (string) $value->name );
			}
			if ( method_exists( $value, '__toString' ) ) {
				return trim( (string) $value );
			}
			return '';
		}

		if ( is_array( $value ) ) {
			$as_single = self::stringify_associative_meta_item( $value );
			if ( null !== $as_single ) {
				return $as_single;
			}

			$parts = array();
			foreach ( $value as $item ) {
				$part = self::stringify_meta_value( $item, $separator );
				if ( '' !== $part ) {
					$parts[] = $part;
				}
			}
			return implode( $separator, $parts );
		}

		return trim( (string) $value );
	}

	/**
	 * Prefer a human label from known associative shapes (ACF Both, posts, terms).
	 *
	 * @param array<mixed> $value Associative value.
	 * @return string|null Null when the array should be treated as a list of items.
	 */
	private static function stringify_associative_meta_item( array $value ): ?string {
		$keys = array_keys( $value );
		$is_list = $keys === array_keys( $keys );
		if ( $is_list ) {
			return null;
		}

		// ACF Return Format "Both": [ 'value' => …, 'label' => … ].
		if ( array_key_exists( 'label', $value ) && is_scalar( $value['label'] ) && ! isset( $value['ID'], $value['post_title'] ) ) {
			$label = trim( (string) $value['label'] );
			if ( '' !== $label ) {
				return $label;
			}
		}
		if ( array_key_exists( 'value', $value ) && ! isset( $value['ID'], $value['post_type'] ) && ! isset( $value['term_id'] ) ) {
			return self::stringify_meta_value( $value['value'] );
		}

		if ( isset( $value['post_title'] ) && is_scalar( $value['post_title'] ) ) {
			return trim( (string) $value['post_title'] );
		}
		if ( isset( $value['ID'], $value['post_type'] ) && is_numeric( $value['ID'] ) ) {
			return get_the_title( (int) $value['ID'] );
		}
		if ( isset( $value['name'] ) && is_scalar( $value['name'] ) && ( isset( $value['term_id'] ) || isset( $value['slug'] ) || isset( $value['taxonomy'] ) ) ) {
			return trim( (string) $value['name'] );
		}

		return null;
	}

	/**
	 * Resolve a mapped source field.
	 *
	 * Sources:
	 * - title, excerpt, content, permalink, post_date, post_type
	 * - meta:{key}
	 * - taxonomy:{slug}
	 *
	 * @param \WP_Post             $post     Source post.
	 * @param string               $source   Source key.
	 * @param array<string, mixed> $settings Settings.
	 * @param string               $type     Detail field type (text, markdown, …).
	 */
	public static function resolve_source( \WP_Post $post, string $source, array $settings, string $type = 'text' ): string {
		if ( 0 === strpos( $source, 'meta:' ) ) {
			$key    = substr( $source, 5 );
			$values = get_post_meta( $post->ID, $key, false );
			if ( ! is_array( $values ) || empty( $values ) ) {
				$resolved = '';
			} else {
				// One DB row that is already an array (e.g. ACF serialized) stays nested;
				// multiple rows (Pods-style) are joined.
				$resolved = self::stringify_meta_value( 1 === count( $values ) ? $values[0] : $values );
			}

			/**
			 * Filter a resolved post-meta value for detail/CSV export.
			 *
			 * Integrations (e.g. Tainacan, ACF, Pods) may replace the raw meta
			 * string with their display APIs based on $type.
			 *
			 * @param string               $resolved Resolved value.
			 * @param \WP_Post             $post     Source post.
			 * @param string               $key      Meta key.
			 * @param array<string, mixed> $settings Collection settings.
			 * @param string               $type     Detail field type.
			 */
			return (string) apply_filters( 'vikus_viewer_resolve_meta_value', $resolved, $post, $key, $settings, $type );
		}

		if ( 0 === strpos( $source, 'taxonomy:' ) ) {
			$taxonomy = substr( $source, 9 );
			$terms    = get_the_terms( $post, $taxonomy );
			if ( empty( $terms ) || is_wp_error( $terms ) ) {
				return '';
			}
			$hierarchical = 'hierarchical' === ( $settings['filter_type'] ?? 'default' )
				&& is_taxonomy_hierarchical( $taxonomy );
			$names        = array();
			foreach ( $terms as $term ) {
				$names[] = $hierarchical ? self::term_path( $term, $taxonomy ) : $term->name;
			}
			return implode( ', ', $names );
		}

		switch ( $source ) {
			case 'title':
				return get_the_title( $post );
			case 'excerpt':
				return wp_strip_all_tags( get_the_excerpt( $post ) );
			case 'content':
				return wp_strip_all_tags( $post->post_content );
			case 'permalink':
				return (string) get_permalink( $post );
			case 'post_date':
				return (string) $post->post_date;
			case 'post_type':
				return (string) $post->post_type;
			case 'year':
				return self::year( $post, $settings );
			case 'keywords':
				return self::keywords( $post, $settings );
			default:
				return '';
		}
	}

	/**
	 * Year from a MySQL datetime.
	 *
	 * @param string $date Date string.
	 */
	private static function year_from_date( string $date ): string {
		$ts = strtotime( $date );
		return $ts ? gmdate( 'Y', $ts ) : '';
	}

	/**
	 * Normalize a value to the CSV / timeline group key used for `year`.
	 *
	 * Prefers a 4-digit year when present; otherwise returns the trimmed string
	 * (e.g. taxonomy term names without a year).
	 *
	 * @param string $value Raw value.
	 */
	public static function year_group_key( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}

		if ( preg_match( '/\b(1[0-9]{3}|20[0-9]{2}|21[0-9]{2})\b/', $value, $m ) ) {
			return $m[1];
		}

		$ts = strtotime( $value );
		if ( $ts ) {
			return gmdate( 'Y', $ts );
		}

		return $value;
	}

	/**
	 * Extract a 4-digit year from an arbitrary string/date.
	 *
	 * @param string $value Raw value.
	 */
	private static function extract_year( string $value ): string {
		return self::year_group_key( $value );
	}
}
