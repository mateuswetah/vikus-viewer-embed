<?php
/**
 * Collection custom post type.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\PostType;

/**
 * Class Collection
 */
final class Collection {

	public const POST_TYPE = 'vikus_collection';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'init', array( self::class, 'register' ) );
		add_action( 'before_delete_post', array( self::class, 'before_delete_post' ), 10, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( self::class, 'columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( self::class, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( self::class, 'row_actions' ), 10, 2 );
	}

	/**
	 * Remove collection artifacts when a collection is permanently deleted.
	 *
	 * Trash is left alone so restoring still has its data package.
	 *
	 * @param int           $post_id Post ID.
	 * @param \WP_Post|null $post    Post object (WP 5.5+).
	 */
	public static function before_delete_post( int $post_id, $post = null ): void {
		if ( $post instanceof \WP_Post ) {
			$post_type = $post->post_type;
		} else {
			$post_obj = get_post( $post_id );
			$post_type = $post_obj ? $post_obj->post_type : '';
		}

		if ( self::POST_TYPE !== $post_type ) {
			return;
		}

		\VikusViewer\Pipeline\BuildQueue::cancel( $post_id );
		\VikusViewer\Support\Paths::delete_collection_dir( $post_id );
	}

	/**
	 * Register the post type.
	 */
	public static function register(): void {
		$labels = array(
			'name'               => __( 'Vikus Collections', 'vikus-viewer-embed' ),
			'singular_name'      => __( 'Vikus Collection', 'vikus-viewer-embed' ),
			'add_new'            => __( 'Add New', 'vikus-viewer-embed' ),
			'add_new_item'       => __( 'Add New Collection', 'vikus-viewer-embed' ),
			'edit_item'          => __( 'Edit Collection', 'vikus-viewer-embed' ),
			'new_item'           => __( 'New Collection', 'vikus-viewer-embed' ),
			'view_item'          => __( 'View Collection', 'vikus-viewer-embed' ),
			'search_items'       => __( 'Search Collections', 'vikus-viewer-embed' ),
			'not_found'          => __( 'No collections found', 'vikus-viewer-embed' ),
			'not_found_in_trash' => __( 'No collections found in Trash', 'vikus-viewer-embed' ),
			'menu_name'          => __( 'Vikus Viewer Embed', 'vikus-viewer-embed' ),
		);

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => $labels,
				'public'              => false,
				'show_ui'             => true,
				// UI lives in Admin\App; keep CPT for REST / caps / shortcodes.
				'show_in_menu'        => false,
				'show_in_rest'        => true,
				'rest_base'           => 'vikus_collection',
				'menu_icon'           => 'dashicons-images-alt2',
				'menu_position'       => 58,
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
				'hierarchical'        => false,
				'supports'            => array( 'title' ),
				'has_archive'         => false,
				'rewrite'             => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
			)
		);
	}

	/**
	 * Admin list columns.
	 *
	 * @param array<string, string> $columns Columns.
	 * @return array<string, string>
	 */
	public static function columns( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['vikus_source'] = __( 'Source', 'vikus-viewer-embed' );
				$new['vikus_status'] = __( 'Build', 'vikus-viewer-embed' );
			}
		}
		return $new;
	}

	/**
	 * Render custom columns.
	 *
	 * @param string $column  Column key.
	 * @param int    $post_id Post ID.
	 */
	public static function render_column( string $column, int $post_id ): void {
		if ( 'vikus_source' === $column ) {
			$settings = \VikusViewer\Support\Settings::get( $post_id );
			$pt       = (string) $settings['source_post_type'];
			$object   = get_post_type_object( $pt );
			$label    = $object && isset( $object->labels->singular_name ) ? (string) $object->labels->singular_name : $pt;
			echo esc_html( $label );
			echo ' <code class="vikus-list-code">' . esc_html( $pt ) . '</code>';
			return;
		}

		if ( 'vikus_status' === $column ) {
			$status = \VikusViewer\Support\Settings::get_build_status( $post_id );
			$state  = (string) $status['status'];
			$dirty  = \VikusViewer\Support\Settings::is_dirty( $post_id );
			$class  = 'vikus-list-status vikus-list-status--' . sanitize_html_class( $state );
			echo '<span class="' . esc_attr( $class ) . '">' . esc_html( $state ) . '</span>';
			if ( $dirty ) {
				echo ' <span class="vikus-dirty">' . esc_html__( 'settings changed', 'vikus-viewer-embed' ) . '</span>';
			}
			if ( 'running' === $state || 'queued' === $state ) {
				printf(
					' <span class="description">%d/%d</span>',
					(int) $status['completed'],
					(int) $status['total']
				);
			}
			if ( 'complete' === $state ) {
				$viewer_url = \VikusViewer\Frontend\Viewer::public_url( $post_id );
				$shortcode  = sprintf( '[vikus_viewer id="%d"]', $post_id );
				echo '<div class="vikus-list-embed">';
				printf(
					'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					esc_url( $viewer_url ),
					esc_html__( 'Open viewer', 'vikus-viewer-embed' )
				);
				echo ' <code class="vikus-list-code" title="' . esc_attr__( 'Copy shortcode', 'vikus-viewer-embed' ) . '">' . esc_html( $shortcode ) . '</code>';
				echo '</div>';
			}
		}
	}

	/**
	 * Row actions on the collections list.
	 *
	 * @param array<string, string> $actions Actions.
	 * @param \WP_Post              $post    Post.
	 * @return array<string, string>
	 */
	public static function row_actions( array $actions, \WP_Post $post ): array {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$id = (int) $post->ID;
		if ( isset( $actions['edit'] ) ) {
			$actions['edit'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( \VikusViewer\Admin\App::url( 'edit', array( 'id' => $id ) ) ),
				esc_html__( 'Edit', 'vikus-viewer-embed' )
			);
		}

		$status    = \VikusViewer\Support\Settings::get_build_status( $id );
		if ( self::user_can_build( $id ) ) {
			$queue_url = wp_nonce_url(
				admin_url( 'admin-post.php?action=vikus_queue_build&collection_id=' . $id ),
				'vikus_queue_build_' . $id
			);
			$actions['vikus_rebuild'] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( $queue_url ),
				esc_html__( 'Rebuild cache', 'vikus-viewer-embed' )
			);

			if ( in_array( (string) $status['status'], array( 'queued', 'running' ), true ) ) {
				$cancel_url = wp_nonce_url(
					admin_url( 'admin-post.php?action=vikus_cancel_build&collection_id=' . $id ),
					'vikus_cancel_build_' . $id
				);
				$actions['vikus_cancel'] = sprintf(
					'<a href="%s">%s</a>',
					esc_url( $cancel_url ),
					esc_html__( 'Cancel build', 'vikus-viewer-embed' )
				);
			}
		}

		if ( 'complete' === $status['status'] ) {
			$viewer_url = \VikusViewer\Frontend\Viewer::public_url( $id );
			$actions['vikus_viewer'] = sprintf(
				'<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
				esc_url( $viewer_url ),
				esc_html__( 'Open viewer', 'vikus-viewer-embed' )
			);
		}

		return $actions;
	}

	/**
	 * Whether the current user may queue or cancel a collection build.
	 *
	 * Builds run Imagick/GD work, so editors need media upload capability
	 * in addition to being able to edit the collection post.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function user_can_build( int $collection_id ): bool {
		return $collection_id > 0
			&& self::POST_TYPE === get_post_type( $collection_id )
			&& current_user_can( 'upload_files' )
			&& current_user_can( 'edit_post', $collection_id );
	}
}
