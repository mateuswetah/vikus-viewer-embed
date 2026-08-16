<?php
/**
 * Mark collections dirty when source content changes.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Support;

use VikusViewer\PostType\Collection;

/**
 * Class DirtyTracker
 */
final class DirtyTracker {

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'save_post', array( self::class, 'on_save_post' ), 20, 2 );
		add_action( 'deleted_post', array( self::class, 'on_deleted_post' ), 10, 1 );
		add_action( 'set_object_terms', array( self::class, 'on_set_object_terms' ), 10, 6 );
		add_action( 'added_post_meta', array( self::class, 'on_meta_change' ), 10, 4 );
		add_action( 'updated_post_meta', array( self::class, 'on_meta_change' ), 10, 4 );
		add_action( 'deleted_post_meta', array( self::class, 'on_meta_deleted' ), 10, 4 );
	}

	/**
	 * When a source post is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post.
	 */
	public static function on_save_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( Collection::POST_TYPE === $post->post_type ) {
			return;
		}
		if ( 'attachment' === $post->post_type ) {
			self::mark_collections_for_attachment( $post );
			return;
		}
		self::mark_collections_for_post_type( $post->post_type );
	}

	/**
	 * When a post is deleted.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function on_deleted_post( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return;
		}
		if ( Collection::POST_TYPE === $post->post_type ) {
			return;
		}
		if ( 'attachment' === $post->post_type ) {
			self::mark_collections_for_attachment( $post );
			return;
		}
		self::mark_collections_for_post_type( $post->post_type );
	}

	/**
	 * Mark collections when an image attachment under a source item changes.
	 *
	 * @param \WP_Post $attachment Attachment post.
	 */
	private static function mark_collections_for_attachment( \WP_Post $attachment ): void {
		if ( 0 !== strpos( (string) $attachment->post_mime_type, 'image/' ) ) {
			return;
		}
		$parent_id = (int) $attachment->post_parent;
		if ( $parent_id <= 0 ) {
			return;
		}
		$parent = get_post( $parent_id );
		if ( ! $parent || Collection::POST_TYPE === $parent->post_type ) {
			return;
		}
		self::mark_collections_for_post_type( $parent->post_type );
	}

	/**
	 * Terms changed.
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Terms.
	 * @param array  $tt_ids     Term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy.
	 * @param bool   $append     Append.
	 * @param array  $old_tt_ids Old TT IDs.
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy, $append, $old_tt_ids ): void {
		unset( $terms, $tt_ids, $append, $old_tt_ids );
		$post = get_post( (int) $object_id );
		if ( ! $post ) {
			return;
		}
		self::mark_collections_using_taxonomy( $post->post_type, (string) $taxonomy );
	}

	/**
	 * Meta added/updated.
	 *
	 * @param int    $meta_id    Meta ID.
	 * @param int    $object_id  Object ID.
	 * @param string $meta_key   Meta key.
	 * @param mixed  $meta_value Meta value.
	 */
	public static function on_meta_change( $meta_id, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_id, $meta_value );
		if ( '_thumbnail_id' === $meta_key || 0 === strpos( (string) $meta_key, '_vikus_' ) ) {
			$post = get_post( (int) $object_id );
			if ( $post && Collection::POST_TYPE !== $post->post_type ) {
				self::mark_collections_for_post_type( $post->post_type );
			}
			return;
		}

		$post = get_post( (int) $object_id );
		if ( ! $post || Collection::POST_TYPE === $post->post_type ) {
			return;
		}
		self::mark_collections_using_meta( $post->post_type, (string) $meta_key );
	}

	/**
	 * Meta deleted.
	 *
	 * @param string[] $meta_ids   Meta IDs.
	 * @param int      $object_id  Object ID.
	 * @param string   $meta_key   Meta key.
	 * @param mixed    $meta_value Meta value.
	 */
	public static function on_meta_deleted( $meta_ids, $object_id, $meta_key, $meta_value ): void {
		unset( $meta_ids );
		self::on_meta_change( 0, $object_id, $meta_key, $meta_value );
	}

	/**
	 * Mark all collections for a post type dirty.
	 *
	 * @param string $post_type Post type.
	 */
	private static function mark_collections_for_post_type( string $post_type ): void {
		$ids = self::collection_ids();
		foreach ( $ids as $id ) {
			$settings = Settings::get( $id );
			if ( $settings['source_post_type'] === $post_type ) {
				Settings::set_dirty( $id, true );
			}
		}
	}

	/**
	 * Mark collections that use a taxonomy.
	 *
	 * @param string $post_type Post type.
	 * @param string $taxonomy  Taxonomy.
	 */
	private static function mark_collections_using_taxonomy( string $post_type, string $taxonomy ): void {
		$ids = self::collection_ids();
		foreach ( $ids as $id ) {
			$settings = Settings::get( $id );
			if ( $settings['source_post_type'] !== $post_type ) {
				continue;
			}
			$used = (array) $settings['keyword_taxonomies'];
			$uses_tax_keywords = ( 'taxonomy' === ( $settings['keyword_source'] ?? 'taxonomy' ) )
				&& in_array( $taxonomy, $used, true )
				&& 'crossfilter' !== ( $settings['filter_type'] ?? 'default' );
			if ( $taxonomy === $settings['year_taxonomy'] || $uses_tax_keywords ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			$layout_hit = false;
			foreach ( $settings['layouts'] ?? array() as $layout ) {
				if ( 'taxonomy:' . $taxonomy === ( $layout['source'] ?? '' ) ) {
					$layout_hit = true;
					break;
				}
			}
			if ( $layout_hit ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			$cross_hit = false;
			foreach ( $settings['crossfilter_dims'] ?? array() as $dim ) {
				if ( is_array( $dim ) && 'taxonomy:' . $taxonomy === ( $dim['source'] ?? '' ) ) {
					$cross_hit = true;
					break;
				}
			}
			if ( $cross_hit ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			foreach ( $settings['detail_fields'] as $field ) {
				if ( 'taxonomy:' . $taxonomy === $field['source'] ) {
					Settings::set_dirty( $id, true );
					break;
				}
			}
		}
	}

	/**
	 * Mark collections that use a meta key.
	 *
	 * @param string $post_type Post type.
	 * @param string $meta_key  Meta key.
	 */
	private static function mark_collections_using_meta( string $post_type, string $meta_key ): void {
		$ids = self::collection_ids();
		foreach ( $ids as $id ) {
			$settings = Settings::get( $id );
			if ( $settings['source_post_type'] !== $post_type ) {
				continue;
			}
			if ( $meta_key === $settings['year_meta_key'] ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			if ( 'meta' === ( $settings['keyword_source'] ?? '' )
				&& $meta_key === ( $settings['keyword_meta_key'] ?? '' )
				&& 'crossfilter' !== ( $settings['filter_type'] ?? 'default' ) ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			$layout_hit = false;
			foreach ( $settings['layouts'] ?? array() as $layout ) {
				if ( 'meta:' . $meta_key === ( $layout['source'] ?? '' ) ) {
					$layout_hit = true;
					break;
				}
			}
			if ( $layout_hit ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			$cross_hit = false;
			foreach ( $settings['crossfilter_dims'] ?? array() as $dim ) {
				if ( is_array( $dim ) && 'meta:' . $meta_key === ( $dim['source'] ?? '' ) ) {
					$cross_hit = true;
					break;
				}
			}
			if ( $cross_hit ) {
				Settings::set_dirty( $id, true );
				continue;
			}
			foreach ( $settings['detail_fields'] as $field ) {
				if ( 'meta:' . $meta_key === $field['source'] ) {
					Settings::set_dirty( $id, true );
					break;
				}
			}
		}
	}

	/**
	 * All collection IDs.
	 *
	 * @return int[]
	 */
	private static function collection_ids(): array {
		$q = new \WP_Query(
			array(
				'post_type'              => Collection::POST_TYPE,
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => -1,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		return array_map( 'intval', $q->posts );
	}
}
