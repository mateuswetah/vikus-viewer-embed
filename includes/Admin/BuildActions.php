<?php
/**
 * Admin-post handlers for build actions.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Admin;

use VikusViewer\Pipeline\BuildQueue;
use VikusViewer\PostType\Collection;

/**
 * Class BuildActions
 */
final class BuildActions {

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( 'admin_post_vikus_queue_build', array( self::class, 'queue_build' ) );
		add_action( 'admin_post_vikus_cancel_build', array( self::class, 'cancel_build' ) );
	}

	/**
	 * Queue a rebuild from admin UI.
	 */
	public static function queue_build(): void {
		$collection_id = isset( $_GET['collection_id'] ) ? absint( $_GET['collection_id'] ) : 0;
		$force         = ! empty( $_GET['force'] );

		if ( ! $collection_id || Collection::POST_TYPE !== get_post_type( $collection_id ) ) {
			wp_die( esc_html__( 'Invalid collection.', 'vikus-viewer-embed' ) );
		}

		check_admin_referer( 'vikus_queue_build_' . $collection_id );

		if ( ! Collection::user_can_build( $collection_id ) ) {
			wp_die( esc_html__( 'You are not allowed to rebuild this collection.', 'vikus-viewer-embed' ) );
		}

		BuildQueue::queue( $collection_id, $force );

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running texture build slice.
			set_time_limit( 90 );
		}

		// Short first slice in the admin request; continuation is single-worker + debounced.
		BuildQueue::process_collection( $collection_id, BuildQueue::web_time_budget() );

		wp_safe_redirect( App::url( 'edit', array( 'id' => $collection_id ) ) );
		exit;
	}

	/**
	 * Cancel an in-progress build.
	 */
	public static function cancel_build(): void {
		$collection_id = isset( $_GET['collection_id'] ) ? absint( $_GET['collection_id'] ) : 0;

		if ( ! $collection_id || Collection::POST_TYPE !== get_post_type( $collection_id ) ) {
			wp_die( esc_html__( 'Invalid collection.', 'vikus-viewer-embed' ) );
		}

		check_admin_referer( 'vikus_cancel_build_' . $collection_id );

		if ( ! Collection::user_can_build( $collection_id ) ) {
			wp_die( esc_html__( 'You are not allowed to cancel this build.', 'vikus-viewer-embed' ) );
		}

		BuildQueue::cancel( $collection_id );

		wp_safe_redirect( App::url( 'edit', array( 'id' => $collection_id ) ) );
		exit;
	}
}
