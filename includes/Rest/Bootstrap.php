<?php
/**
 * REST API for admin bootstrap and build actions.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Rest;

use VikusViewer\Admin\App;
use VikusViewer\Frontend\Viewer;
use VikusViewer\Pipeline\BuildQueue;
use VikusViewer\PostType\Collection;
use VikusViewer\Support\MetaKeys;
use VikusViewer\Support\MetaTerminology;
use VikusViewer\Support\Settings;

/**
 * Class Bootstrap
 */
final class Bootstrap {

	public const NAMESPACE = 'vikus-viewer-embed/v1';

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'rest_api_init', array( self::class, 'register_routes' ) );
		add_action( 'rest_api_init', array( self::class, 'register_fields' ) );
	}

	/**
	 * REST routes.
	 */
	public static function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/admin-bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( self::class, 'admin_bootstrap' ),
				'permission_callback' => static function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'post_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
						'default'           => '',
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/collections/(?P<id>\d+)/build',
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( self::class, 'queue_build' ),
					'permission_callback' => array( self::class, 'can_build_collection' ),
					'args'                => array(
						'id'    => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
						'force' => array(
							'type'    => 'boolean',
							'default' => false,
						),
					),
				),
				array(
					'methods'             => 'DELETE',
					'callback'            => array( self::class, 'cancel_build' ),
					'permission_callback' => array( self::class, 'can_build_collection' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Expose settings / build status on the collection REST entity.
	 */
	public static function register_fields(): void {
		register_rest_field(
			Collection::POST_TYPE,
			'vikus_settings',
			array(
				'get_callback'    => static function ( array $post ): array {
					return Settings::get( (int) $post['id'] );
				},
				'update_callback' => static function ( $value, \WP_Post $post ): bool {
					if ( ! is_array( $value ) ) {
						return false;
					}
					Settings::update( (int) $post->ID, $value );
					Settings::set_dirty( (int) $post->ID, true );
					return true;
				},
				'schema'          => array(
					'description' => __( 'Vikus collection settings.', 'vikus-viewer-embed' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
				),
			)
		);

		register_rest_field(
			Collection::POST_TYPE,
			'vikus_build_status',
			array(
				'get_callback' => static function ( array $post ): array {
					$status = Settings::get_build_status( (int) $post['id'] );
					// Trim heavy fields for list/edit payloads.
					unset( $status['item_ids'], $status['texture_errors'] );
					$status['dirty']      = Settings::is_dirty( (int) $post['id'] );
					$status['viewer_url'] = Viewer::public_url( (int) $post['id'] );
					$status['shortcode']  = sprintf( '[vikus_viewer id="%d"]', (int) $post['id'] );
					$status['can_build']  = Collection::user_can_build( (int) $post['id'] );
					return $status;
				},
				'schema'       => array(
					'description' => __( 'Cache build status.', 'vikus-viewer-embed' ),
					'type'        => 'object',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}

	/**
	 * Permission: edit this collection and upload media (required for builds).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public static function can_build_collection( \WP_REST_Request $request ): bool {
		return Collection::user_can_build( (int) $request['id'] );
	}

	/**
	 * Admin bootstrap payload (post types, taxonomies, meta, terminology).
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function admin_bootstrap( \WP_REST_Request $request ): \WP_REST_Response {
		$focus_pt = (string) $request->get_param( 'post_type' );
		$types    = self::public_source_post_types();

		$post_types = array();
		foreach ( $types as $pt ) {
			$post_types[] = array(
				'name'        => $pt->name,
				'label'       => $pt->labels->singular_name ? (string) $pt->labels->singular_name : $pt->name,
				'description' => isset( $pt->description ) ? (string) $pt->description : '',
			);
		}

		$taxonomies = array();
		$meta_keys  = array();
		$terms      = MetaTerminology::defaults();

		if ( '' !== $focus_pt && isset( $types[ $focus_pt ] ) ) {
			$taxonomies = self::taxonomies_for_post_type( $focus_pt );
			$meta_keys  = MetaKeys::for_post_type( $focus_pt );
			$terms      = MetaTerminology::for_post_type( $focus_pt );
		}

		return new \WP_REST_Response(
			array(
				'postTypes'   => $post_types,
				'taxonomies'  => $taxonomies,
				'metaKeys'    => $meta_keys,
				'terminology' => $terms,
				'defaults'    => Settings::defaults(),
				'urls'        => array(
					'list'   => App::url( 'list' ),
					'create' => App::url( 'create' ),
				),
			)
		);
	}

	/**
	 * Queue a build.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function queue_build( \WP_REST_Request $request ) {
		$id    = (int) $request['id'];
		$force = (bool) $request->get_param( 'force' );

		$status = Settings::get_build_status( $id );
		if ( in_array( (string) $status['status'], array( 'queued', 'running' ), true ) ) {
			return new \WP_Error(
				'vikus_build_active',
				__( 'A build is already in progress.', 'vikus-viewer-embed' ),
				array( 'status' => 409 )
			);
		}

		BuildQueue::queue( $id, $force );

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged
			set_time_limit( 90 );
		}
		BuildQueue::process_collection( $id, BuildQueue::web_time_budget() );

		$fresh = Settings::get_build_status( $id );
		unset( $fresh['item_ids'], $fresh['texture_errors'] );
		$fresh['dirty']      = Settings::is_dirty( $id );
		$fresh['viewer_url'] = Viewer::public_url( $id );
		$fresh['shortcode']  = sprintf( '[vikus_viewer id="%d"]', $id );
		$fresh['can_build']  = Collection::user_can_build( $id );

		return new \WP_REST_Response( $fresh );
	}

	/**
	 * Cancel a build.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function cancel_build( \WP_REST_Request $request ): \WP_REST_Response {
		$id = (int) $request['id'];
		BuildQueue::cancel( $id );

		$fresh = Settings::get_build_status( $id );
		unset( $fresh['item_ids'], $fresh['texture_errors'] );
		$fresh['dirty']      = Settings::is_dirty( $id );
		$fresh['viewer_url'] = Viewer::public_url( $id );
		$fresh['shortcode']  = sprintf( '[vikus_viewer id="%d"]', $id );
		$fresh['can_build']  = Collection::user_can_build( $id );

		return new \WP_REST_Response( $fresh );
	}

	/**
	 * Public source post types.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function public_source_post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types[ Collection::POST_TYPE ] );
		return $types;
	}

	/**
	 * Taxonomies for a post type.
	 *
	 * @param string $post_type Post type.
	 * @return array<int, array{name:string,label:string,hierarchical:bool}>
	 */
	public static function taxonomies_for_post_type( string $post_type ): array {
		$out = array();
		foreach ( get_object_taxonomies( $post_type, 'objects' ) as $taxonomy ) {
			if ( empty( $taxonomy->public ) ) {
				continue;
			}
			$out[] = array(
				'name'         => $taxonomy->name,
				'label'        => $taxonomy->labels->singular_name ? (string) $taxonomy->labels->singular_name : $taxonomy->name,
				'hierarchical' => ! empty( $taxonomy->hierarchical ),
			);
		}
		return $out;
	}
}
