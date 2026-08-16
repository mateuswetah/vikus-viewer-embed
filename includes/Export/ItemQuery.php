<?php
/**
 * Query source posts for a collection.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Export;

use VikusViewer\Support\Settings;

/**
 * Class ItemQuery
 */
final class ItemQuery {

	/**
	 * Count eligible posts.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function count( int $collection_id ): int {
		$settings = Settings::get( $collection_id );
		$args     = self::base_args( $settings );
		$args['fields']         = 'ids';
		$args['posts_per_page'] = 1;
		$args['no_found_rows']  = false;

		$query = new \WP_Query( $args );
		return (int) $query->found_posts;
	}

	/**
	 * Fetch a page of eligible post IDs.
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $page          1-based page.
	 * @param int $per_page      Page size.
	 * @return int[]
	 */
	public static function page_ids( int $collection_id, int $page, int $per_page ): array {
		$settings = Settings::get( $collection_id );
		$args     = self::base_args( $settings );
		$args['fields']         = 'ids';
		$args['posts_per_page'] = $per_page;
		$args['paged']          = max( 1, $page );
		$args['no_found_rows']  = true;

		$query = new \WP_Query( $args );
		return array_map( 'intval', $query->posts );
	}

	/**
	 * Fetch all eligible post IDs (batched).
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $per_page      Batch size.
	 * @return int[]
	 */
	public static function all_ids( int $collection_id, int $per_page = 200 ): array {
		$ids  = array();
		$page = 1;

		do {
			$batch = self::page_ids( $collection_id, $page, $per_page );
			$ids   = array_merge( $ids, $batch );
			++$page;
		} while ( count( $batch ) === $per_page );

		return $ids;
	}

	/**
	 * Base WP_Query args from settings.
	 *
	 * @param array<string, mixed> $settings Settings.
	 * @return array<string, mixed>
	 */
	private static function base_args( array $settings ): array {
		$args = array(
			'post_type'              => $settings['source_post_type'],
			'post_status'            => 'publish',
			'orderby'                => 'ID',
			'order'                  => 'ASC',
			'ignore_sticky_posts'    => true,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
		);

		if ( ! empty( $settings['require_thumbnail'] ) ) {
			$args['meta_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				array(
					'key'     => '_thumbnail_id',
					'compare' => 'EXISTS',
				),
			);
		}

		return $args;
	}
}
