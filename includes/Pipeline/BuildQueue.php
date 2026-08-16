<?php
/**
 * Step-based build queue for collections.
 *
 * Designed for constrained hosts (Docker/PHP-FPM): one worker per collection,
 * debounced continuations, and capped web batch sizes so builds do not exhaust
 * max_children / MaxRequestWorkers.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

use VikusViewer\Export\ConfigBuilder;
use VikusViewer\Export\CsvExporter;
use VikusViewer\Support\FileWriter;
use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class BuildQueue
 */
final class BuildQueue {

	public const CRON_HOOK   = 'vikus_viewer_process_build';
	public const ACTION_HOOK = 'vikus_viewer_run_build_batch';

	/** @var array<int, string> Lock tokens held by this request. */
	private static $lock_tokens = array();

	/**
	 * Register hooks.
	 */
	public static function register_hooks(): void {
		add_action( self::CRON_HOOK, array( self::class, 'cron_tick' ), 10, 1 );
		add_action( self::ACTION_HOOK, array( self::class, 'process_collection' ), 10, 1 );
		add_action( 'admin_init', array( self::class, 'maybe_continue_on_admin' ) );
		add_action( 'admin_post_vikus_continue_build', array( self::class, 'continue_build_request' ) );
		add_action( 'wp_ajax_vikus_continue_build', array( self::class, 'continue_build_request' ) );
		add_action( 'wp_ajax_nopriv_vikus_continue_build', array( self::class, 'continue_build_request' ) );
	}

	/**
	 * Queue a rebuild.
	 *
	 * @param int         $collection_id Collection post ID.
	 * @param bool        $force         Force regenerate textures.
	 * @param string|null $only_step     Optional single step: csv|textures|sprites.
	 * @param bool        $schedule      Whether to schedule async/cron processing.
	 */
	public static function queue( int $collection_id, bool $force = false, ?string $only_step = null, bool $schedule = true ): void {
		$status = array(
			'status'           => 'queued',
			'step'             => $only_step ? $only_step : 'csv',
			'force'            => $force,
			'completed'        => 0,
			'total'            => 0,
			'processed'        => 0,
			'errors'           => 0,
			'texture_errors'   => array(),
			'texture_reuse'    => self::empty_texture_reuse(),
			'message'          => __( 'Build queued.', 'vikus-viewer-embed' ),
			'last_error'       => '',
			'started_at'       => 0,
			'finished_at'      => 0,
			'cursor'           => 0,
			'item_ids'         => array(),
			'only_step'        => $only_step,
			'cancel_requested' => false,
		);
		Settings::update_build_status( $collection_id, $status );

		if ( $schedule ) {
			self::schedule_continuation( $collection_id, true );
		}
	}

	/**
	 * Request cancellation of an in-progress (or queued) build.
	 *
	 * Cooperative: the current texture batch may finish, then workers stop.
	 * Clears locks and scheduled continuations so CLI can take over.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return bool True if a build was active and is now cancelled.
	 */
	public static function cancel( int $collection_id ): bool {
		$status = Settings::get_build_status( $collection_id );
		$was_active = in_array( $status['status'], array( 'queued', 'running' ), true );

		Settings::update_build_status(
			$collection_id,
			array(
				'status'           => 'cancelled',
				'cancel_requested' => true,
				'message'          => __( 'Build cancelled.', 'vikus-viewer-embed' ),
				'finished_at'      => time(),
			)
		);

		self::clear_scheduled_continuations( $collection_id );
		self::force_release_lock( $collection_id );
		delete_transient( 'vikus_build_sched_' . $collection_id );

		return $was_active;
	}

	/**
	 * Drop cron / Action Scheduler continuations for a collection.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function clear_scheduled_continuations( int $collection_id ): void {
		wp_clear_scheduled_hook( self::CRON_HOOK, array( $collection_id ) );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::ACTION_HOOK, array( $collection_id ), 'vikus-viewer-embed' );
		}
	}

	/**
	 * Delete the build lock regardless of which request holds it.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function force_release_lock( int $collection_id ): void {
		delete_option( 'vikus_build_lock_' . $collection_id );
		unset( self::$lock_tokens[ $collection_id ] );
	}

	/**
	 * Cron callback.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function cron_tick( int $collection_id ): void {
		self::process_collection( $collection_id, self::web_time_budget() );
	}

	/**
	 * Continue builds when an admin visits wp-admin (helps Docker without reliable cron).
	 * At most one short slice per request, and only if no worker already holds the lock.
	 */
	public static function maybe_continue_on_admin(): void {
		if ( ! is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
			return;
		}

		// Avoid stacking work on every admin page load during heavy builds.
		if ( get_transient( 'vikus_admin_continue_gate' ) ) {
			return;
		}
		set_transient( 'vikus_admin_continue_gate', 1, 15 );

		$ids = self::active_build_ids();
		if ( empty( $ids ) ) {
			return;
		}

		// One collection per admin request.
		self::process_collection( (int) $ids[0], min( 8, self::web_time_budget() ) );
	}

	/**
	 * Non-blocking admin-ajax continuation for long builds.
	 */
	public static function continue_build_request(): void {
		$collection_id = isset( $_REQUEST['collection_id'] ) ? absint( $_REQUEST['collection_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$token         = isset( $_REQUEST['token'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['token'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $collection_id || ! self::verify_continue_token( $collection_id, $token ) ) {
			status_header( 403 );
			exit;
		}

		if ( 'vikus_collection' !== get_post_type( $collection_id ) ) {
			status_header( 404 );
			exit;
		}

		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged -- long-running texture build slice.
			set_time_limit( 90 );
		}

		self::process_collection( $collection_id, self::web_time_budget() );

		status_header( 204 );
		exit;
	}

	/**
	 * HMAC token for unauthenticated loopback continuation (cron-like).
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function continue_token( int $collection_id ): string {
		$bucket = (string) (int) floor( time() / HOUR_IN_SECONDS );
		return hash_hmac( 'sha256', 'vikus_continue|' . $collection_id . '|' . $bucket, wp_salt( 'auth' ) );
	}

	/**
	 * Verify loopback token (current or previous hour).
	 *
	 * @param int    $collection_id Collection post ID.
	 * @param string $token         Token.
	 */
	public static function verify_continue_token( int $collection_id, string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		$current  = self::continue_token( $collection_id );
		$previous = hash_hmac(
			'sha256',
			'vikus_continue|' . $collection_id . '|' . (string) ( (int) floor( time() / HOUR_IN_SECONDS ) - 1 ),
			wp_salt( 'auth' )
		);
		return hash_equals( $current, $token ) || hash_equals( $previous, $token );
	}

	/**
	 * Schedule at most one continuation for a collection (debounced).
	 *
	 * Prefers Action Scheduler / WP-Cron with a short delay. HTTP loopback is
	 * only used when cron is disabled or explicitly requested, so Docker hosts
	 * with DISABLE_WP_CRON still progress without forking dozens of workers.
	 *
	 * @param int  $collection_id Collection post ID.
	 * @param bool $immediate     Skip delay (initial queue kick).
	 */
	public static function schedule_continuation( int $collection_id, bool $immediate = false ): void {
		$status = Settings::get_build_status( $collection_id );
		if ( ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
			return;
		}

		$debounce_key = 'vikus_build_sched_' . $collection_id;
		if ( ! $immediate && get_transient( $debounce_key ) ) {
			return;
		}

		/**
		 * Seconds between continuation schedules (debounce window).
		 *
		 * @param int $seconds       Debounce seconds.
		 * @param int $collection_id Collection ID.
		 */
		$debounce = (int) apply_filters( 'vikus_viewer_continuation_debounce', 8, $collection_id );
		$debounce = max( 3, $debounce );
		set_transient( $debounce_key, 1, $debounce );

		/**
		 * Delay before the next build slice runs.
		 *
		 * @param int $seconds       Delay seconds.
		 * @param int $collection_id Collection ID.
		 */
		$delay = $immediate ? 0 : (int) apply_filters( 'vikus_viewer_continuation_delay', 3, $collection_id );
		$delay = max( 0, $delay );
		$when  = time() + $delay;

		$scheduled = false;

		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::ACTION_HOOK, array( $collection_id ), 'vikus-viewer-embed' ) ) {
			$scheduled = true;
		} elseif ( function_exists( 'as_schedule_single_action' ) && $delay > 0 ) {
			as_schedule_single_action( $when, self::ACTION_HOOK, array( $collection_id ), 'vikus-viewer-embed', true );
			$scheduled = true;
		} elseif ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::ACTION_HOOK, array( $collection_id ), 'vikus-viewer-embed', true );
			$scheduled = true;
		}

		if ( ! wp_next_scheduled( self::CRON_HOOK, array( $collection_id ) ) ) {
			wp_schedule_single_event( $when > time() ? $when : time() + 1, self::CRON_HOOK, array( $collection_id ) );
			$scheduled = true;
		}

		$cron_disabled = defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON;

		/**
		 * Whether to fire a non-blocking HTTP loopback to continue the build.
		 * Default: only when WP-Cron is disabled (typical in Docker).
		 *
		 * @param bool $use_loopback   Whether to use HTTP loopback.
		 * @param int  $collection_id  Collection ID.
		 * @param bool $cron_disabled  Whether DISABLE_WP_CRON is set.
		 */
		$use_loopback = (bool) apply_filters( 'vikus_viewer_use_loopback', $cron_disabled, $collection_id, $cron_disabled );

		if ( $use_loopback || ! $scheduled ) {
			self::fire_loopback( $collection_id );
		} elseif ( ! $cron_disabled ) {
			spawn_cron( $when );
		}
	}

	/**
	 * Fire a single non-blocking admin-ajax continuation request.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	private static function fire_loopback( int $collection_id ): void {
		$url = add_query_arg(
			array(
				'action'        => 'vikus_continue_build',
				'collection_id' => $collection_id,
				'token'         => self::continue_token( $collection_id ),
			),
			admin_url( 'admin-ajax.php' )
		);

		wp_remote_get(
			$url,
			array(
				'timeout'   => 0.01,
				'blocking'  => false,
				// Core filter for local HTTPS loopbacks (same as WP cron).
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);
	}

	/**
	 * Soft time budget for web/cron workers (seconds).
	 */
	public static function web_time_budget(): int {
		/**
		 * Seconds each web/cron worker may spend on a build before yielding.
		 *
		 * @param int $seconds Time budget.
		 */
		return max( 5, (int) apply_filters( 'vikus_viewer_web_time_budget', 15 ) );
	}

	/**
	 * Cap texture batch size on web requests to limit memory/CPU spikes.
	 *
	 * @param int $batch_size Configured batch size.
	 */
	public static function effective_batch_size( int $batch_size ): int {
		$batch_size = max( 1, $batch_size );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return $batch_size;
		}

		/**
		 * Max items resized per tick on web/cron (not WP-CLI).
		 *
		 * @param int $cap Cap.
		 */
		$cap = (int) apply_filters( 'vikus_viewer_web_batch_cap', 4 );
		return max( 1, min( $batch_size, max( 1, $cap ) ) );
	}

	/**
	 * Acquire an exclusive build lock for a collection.
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $ttl           Lock TTL seconds.
	 */
	public static function acquire_lock( int $collection_id, int $ttl = 120 ): bool {
		$key     = 'vikus_build_lock_' . $collection_id;
		$now     = time();
		$expires = $now + max( 30, $ttl );
		$token   = wp_generate_password( 16, false, false );

		$current = get_option( $key, null );
		if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] >= $now ) {
			return false;
		}
		if ( null !== $current ) {
			delete_option( $key );
		}

		$payload = array(
			'token'   => $token,
			'expires' => $expires,
		);

		// add_option is atomic when the option does not exist.
		if ( ! add_option( $key, $payload, '', 'no' ) ) {
			$current = get_option( $key, null );
			if ( is_array( $current ) && isset( $current['expires'] ) && (int) $current['expires'] < $now ) {
				delete_option( $key );
				if ( ! add_option( $key, $payload, '', 'no' ) ) {
					return false;
				}
			} else {
				return false;
			}
		}

		self::$lock_tokens[ $collection_id ] = $token;
		return true;
	}

	/**
	 * Release a lock held by this request.
	 *
	 * @param int $collection_id Collection post ID.
	 */
	public static function release_lock( int $collection_id ): void {
		$key   = 'vikus_build_lock_' . $collection_id;
		$token = self::$lock_tokens[ $collection_id ] ?? '';
		$current = get_option( $key, null );
		if ( is_array( $current ) && $token && ( $current['token'] ?? '' ) === $token ) {
			delete_option( $key );
		}
		unset( self::$lock_tokens[ $collection_id ] );
	}

	/**
	 * Collection IDs with an in-progress build.
	 *
	 * @return int[]
	 */
	private static function active_build_ids(): array {
		$q = new \WP_Query(
			array(
				'post_type'              => 'vikus_collection',
				'post_status'            => array( 'publish', 'draft', 'private' ),
				'posts_per_page'         => 20,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => false,
				'meta_query'             => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => Settings::BUILD_META_KEY,
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$ids = array();
		foreach ( $q->posts as $id ) {
			$id     = (int) $id;
			$status = Settings::get_build_status( $id );
			if ( in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	/**
	 * Run build until done or time budget exhausted (for web/cron).
	 * CLI can call run_until_complete().
	 *
	 * @param int $collection_id Collection post ID.
	 * @param int $time_budget   Seconds.
	 */
	public static function process_collection( int $collection_id, int $time_budget = 20 ): void {
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$ttl = max( 60, $time_budget + 45 );
		if ( ! self::acquire_lock( $collection_id, $ttl ) ) {
			// Another worker is already resizing images for this collection.
			self::schedule_continuation( $collection_id );
			return;
		}

		try {
			$deadline = time() + max( 5, $time_budget );

			while ( time() < $deadline ) {
				$status = Settings::get_build_status( $collection_id );
				if ( ! empty( $status['cancel_requested'] ) || ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
					return;
				}

				$result = self::tick( $collection_id );
				if ( empty( $result['continue'] ) ) {
					return;
				}
			}

			$status = Settings::get_build_status( $collection_id );
			if ( empty( $status['cancel_requested'] ) && in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
				self::schedule_continuation( $collection_id );
			}
		} finally {
			self::release_lock( $collection_id );
		}
	}

	/**
	 * Run until complete (CLI).
	 *
	 * @param int           $collection_id Collection post ID.
	 * @param callable|null $on_progress   Optional callback( array $status ): void after each tick.
	 * @return array<string, mixed>
	 */
	public static function run_until_complete( int $collection_id, ?callable $on_progress = null ): array {
		$attempts = 0;
		while ( ! self::acquire_lock( $collection_id, HOUR_IN_SECONDS ) ) {
			++$attempts;
			if ( $attempts > 30 ) {
				Settings::update_build_status(
					$collection_id,
					array(
						'message' => __( 'Could not acquire build lock (another worker is busy). Stop the web build or retry.', 'vikus-viewer-embed' ),
					)
				);
				return Settings::get_build_status( $collection_id );
			}
			if ( $on_progress ) {
				$on_progress(
					array_merge(
						Settings::get_build_status( $collection_id ),
						array(
							'_waiting_lock' => true,
							'_lock_attempt' => $attempts,
						)
					)
				);
			}
			sleep( 2 );
		}

		try {
			$guard = 0;
			do {
				if ( $on_progress ) {
					$on_progress( Settings::get_build_status( $collection_id ) );
				}
				$result = self::tick( $collection_id );
				if ( $on_progress ) {
					$on_progress( Settings::get_build_status( $collection_id ) );
				}
				++$guard;
				if ( $guard > 100000 ) {
					Settings::update_build_status(
						$collection_id,
						array(
							'status'      => 'failed',
							'last_error'  => 'Build aborted: too many ticks.',
							'finished_at' => time(),
						)
					);
					break;
				}
			} while ( ! empty( $result['continue'] ) );
		} finally {
			self::release_lock( $collection_id );
		}

		return Settings::get_build_status( $collection_id );
	}

	/**
	 * Single pipeline tick.
	 *
	 * @param int $collection_id Collection post ID.
	 * @return array{continue:bool}
	 */
	public static function tick( int $collection_id ): array {
		$status = Settings::get_build_status( $collection_id );
		if ( ! empty( $status['cancel_requested'] ) || ! in_array( $status['status'], array( 'queued', 'running' ), true ) ) {
			return array( 'continue' => false );
		}

		$force     = ! empty( $status['force'] );
		$only_step = isset( $status['only_step'] ) ? $status['only_step'] : null;
		$step      = (string) ( $status['step'] ?: 'csv' );
		$settings  = Settings::get( $collection_id );

		if ( empty( $status['started_at'] ) ) {
			$status = Settings::update_build_status(
				$collection_id,
				array(
					'status'     => 'running',
					'started_at' => time(),
					'message'    => __( 'Build started.', 'vikus-viewer-embed' ),
				)
			);
		} else {
			Settings::update_build_status( $collection_id, array( 'status' => 'running' ) );
		}

		try {
			if ( 'csv' === $step ) {
				return self::step_csv( $collection_id, $only_step );
			}
			if ( 'textures' === $step ) {
				$batch = self::effective_batch_size( (int) $settings['batch_size'] );
				return self::step_textures( $collection_id, $force, $only_step, $batch );
			}
			if ( 'sprites' === $step ) {
				return self::step_sprites( $collection_id, $only_step );
			}

			throw new \RuntimeException( 'Unknown build step: ' . $step );
		} catch ( \Throwable $e ) {
			Settings::update_build_status(
				$collection_id,
				array(
					'status'      => 'failed',
					'last_error'  => $e->getMessage(),
					'message'     => __( 'Build failed.', 'vikus-viewer-embed' ),
					'finished_at' => time(),
				)
			);
			return array( 'continue' => false );
		}
	}

	/**
	 * CSV step.
	 *
	 * @param int         $collection_id Collection ID.
	 * @param string|null $only_step     Only step.
	 * @return array{continue:bool}
	 */
	private static function step_csv( int $collection_id, ?string $only_step ): array {
		Settings::update_build_status(
			$collection_id,
			array(
				'step'    => 'csv',
				'message' => __( 'Exporting data.csv and config.json…', 'vikus-viewer-embed' ),
			)
		);

		$result = CsvExporter::export( $collection_id );
		ConfigBuilder::write( $collection_id, (int) $result['count'] );

		if ( 'csv' === $only_step ) {
			Settings::update_build_status(
				$collection_id,
				array(
					'status'      => 'complete',
					'step'        => 'csv',
					'total'       => (int) $result['count'],
					'completed'   => (int) $result['count'],
					'item_ids'    => $result['ids'],
					'message'     => __( 'CSV export complete.', 'vikus-viewer-embed' ),
					'finished_at' => time(),
				)
			);
			Settings::set_dirty( $collection_id, false );
			return array( 'continue' => false );
		}

		Settings::update_build_status(
			$collection_id,
			array(
				'step'      => 'textures',
				'total'     => (int) $result['count'],
				'completed' => 0,
				'cursor'    => 0,
				'item_ids'  => $result['ids'],
				'message'   => sprintf(
					/* translators: %d: item count */
					__( 'CSV export complete (%d items). Generating textures…', 'vikus-viewer-embed' ),
					(int) $result['count']
				),
			)
		);

		return array( 'continue' => true );
	}

	/**
	 * Textures step (batched).
	 *
	 * @param int         $collection_id Collection ID.
	 * @param bool        $force         Force.
	 * @param string|null $only_step     Only step.
	 * @param int         $batch_size    Batch size.
	 * @return array{continue:bool}
	 */
	private static function step_textures( int $collection_id, bool $force, ?string $only_step, int $batch_size ): array {
		$status   = Settings::get_build_status( $collection_id );
		$item_ids = isset( $status['item_ids'] ) && is_array( $status['item_ids'] ) ? $status['item_ids'] : array();
		$cursor   = (int) ( $status['cursor'] ?? 0 );

		if ( empty( $item_ids ) ) {
			$result   = CsvExporter::export( $collection_id );
			$item_ids = $result['ids'];
			ConfigBuilder::write( $collection_id, (int) $result['count'] );
		}

		$batch = TextureBuilder::process_batch( $collection_id, $item_ids, $cursor, $batch_size, $force );

		$completed = (int) ( $status['completed'] ?? 0 ) + $batch['processed'] + $batch['skipped'];
		$errors    = (int) ( $status['errors'] ?? 0 ) + $batch['errors'];

		$texture_errors = isset( $status['texture_errors'] ) && is_array( $status['texture_errors'] )
			? $status['texture_errors']
			: array();
		foreach ( $batch['error_details'] as $item_id => $reason ) {
			$texture_errors[ (string) $item_id ] = (string) $reason;
		}
		self::write_texture_errors_sidecar( $collection_id, $texture_errors );

		$texture_reuse = self::merge_texture_reuse(
			isset( $status['texture_reuse'] ) && is_array( $status['texture_reuse'] ) ? $status['texture_reuse'] : array(),
			isset( $batch['reuse'] ) && is_array( $batch['reuse'] ) ? $batch['reuse'] : array()
		);

		Settings::update_build_status(
			$collection_id,
			array(
				'step'           => 'textures',
				'cursor'         => $batch['next_offset'],
				'completed'      => $completed,
				'total'          => count( $item_ids ),
				'item_ids'       => $item_ids,
				'errors'         => $errors,
				'texture_errors' => $texture_errors,
				'texture_reuse'  => $texture_reuse,
				'processed'      => (int) ( $status['processed'] ?? 0 ) + $batch['processed'],
				'message'        => sprintf(
					/* translators: 1: completed, 2: total */
					__( 'Textures: %1$d / %2$d', 'vikus-viewer-embed' ),
					min( $completed, count( $item_ids ) ),
					count( $item_ids )
				),
			)
		);

		if ( ! $batch['done'] ) {
			return array( 'continue' => true );
		}

		TextureCsv::sync_from_manifest( $collection_id );

		if ( 'textures' === $only_step ) {
			$reuse_summary = self::format_texture_reuse_summary( $texture_reuse );
			$message       = __( 'Texture generation complete.', 'vikus-viewer-embed' );
			if ( '' !== $reuse_summary ) {
				$message .= ' ' . $reuse_summary;
			}
			ConfigBuilder::write( $collection_id, count( $item_ids ) );
			Settings::update_build_status(
				$collection_id,
				array(
					'status'      => 'complete',
					'message'     => $message,
					'finished_at' => time(),
				)
			);
			Settings::set_dirty( $collection_id, false );
			return array( 'continue' => false );
		}

		$processed = (int) ( $status['processed'] ?? 0 ) + $batch['processed'];
		// Meta/taxonomy-only rebuilds: textures unchanged and atlas already matches → skip sprites.
		if ( ! $force && 0 === $processed && SpritesheetBuilder::covers_items( $collection_id, $item_ids ) ) {
			ConfigBuilder::write( $collection_id, count( $item_ids ) );
			Settings::update_build_status(
				$collection_id,
				array(
					'status'      => 'complete',
					'step'        => 'csv',
					'completed'   => count( $item_ids ),
					'total'       => count( $item_ids ),
					'message'     => __( 'Data updated (CSV/config). Textures and sprites were unchanged.', 'vikus-viewer-embed' ),
					'finished_at' => time(),
				)
			);
			Settings::set_dirty( $collection_id, false );
			return array( 'continue' => false );
		}

		Settings::update_build_status(
			$collection_id,
			array(
				'step'    => 'sprites',
				'message' => __( 'Building spritesheets…', 'vikus-viewer-embed' ),
			)
		);

		return array( 'continue' => true );
	}

	/**
	 * Sprites step.
	 *
	 * @param int         $collection_id Collection ID.
	 * @param string|null $only_step     Only step.
	 * @return array{continue:bool}
	 */
	private static function step_sprites( int $collection_id, ?string $only_step ): array {
		$status   = Settings::get_build_status( $collection_id );
		$item_ids = isset( $status['item_ids'] ) && is_array( $status['item_ids'] ) ? $status['item_ids'] : array();

		if ( empty( $item_ids ) ) {
			$result   = CsvExporter::export( $collection_id );
			$item_ids = $result['ids'];
		}

		// Always refresh config so detail.structure matches current mapping.
		ConfigBuilder::write( $collection_id, count( $item_ids ) );

		$stats = SpritesheetBuilder::build( $collection_id, $item_ids );

		$texture_errors = isset( $status['texture_errors'] ) && is_array( $status['texture_errors'] )
			? $status['texture_errors']
			: array();
		$error_count = count( $texture_errors );
		$message     = sprintf(
			/* translators: 1: sheet count, 2: sprite count */
			__( 'Build complete (%1$d sheets, %2$d sprites).', 'vikus-viewer-embed' ),
			(int) $stats['sheets'],
			(int) $stats['sprites']
		);
		$reuse_summary = self::format_texture_reuse_summary(
			isset( $status['texture_reuse'] ) && is_array( $status['texture_reuse'] ) ? $status['texture_reuse'] : array()
		);
		if ( '' !== $reuse_summary ) {
			$message .= ' ' . $reuse_summary;
		}
		if ( $error_count > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of items that failed texture generation */
				_n(
					'%d item failed texture generation — see texture-errors.json.',
					'%d items failed texture generation — see texture-errors.json.',
					$error_count,
					'vikus-viewer-embed'
				),
				$error_count
			);
		}

		Settings::update_build_status(
			$collection_id,
			array(
				'status'      => 'complete',
				'step'        => 'sprites',
				'completed'   => count( $item_ids ),
				'total'       => count( $item_ids ),
				'message'     => $message,
				'finished_at' => time(),
			)
		);
		Settings::set_dirty( $collection_id, false );

		unset( $only_step );
		return array( 'continue' => false );
	}

	/**
	 * Empty texture reuse counters.
	 *
	 * @return array{detail_wp:int,detail_generated:int,big_wp:int,big_generated:int}
	 */
	public static function empty_texture_reuse(): array {
		return array(
			'detail_wp'        => 0,
			'detail_generated' => 0,
			'big_wp'           => 0,
			'big_generated'    => 0,
		);
	}

	/**
	 * Accumulate batch reuse counters into the build status totals.
	 *
	 * @param array<string,mixed> $current Current status counters.
	 * @param array<string,mixed> $batch   Batch counters.
	 * @return array{detail_wp:int,detail_generated:int,big_wp:int,big_generated:int}
	 */
	public static function merge_texture_reuse( array $current, array $batch ): array {
		$out = self::empty_texture_reuse();
		foreach ( array_keys( $out ) as $key ) {
			$out[ $key ] = (int) ( $current[ $key ] ?? 0 ) + (int) ( $batch[ $key ] ?? 0 );
		}
		return $out;
	}

	/**
	 * Human-readable WP reuse summary for messages / CLI / UI.
	 *
	 * @param array<string,mixed> $reuse Counters.
	 */
	public static function format_texture_reuse_summary( array $reuse ): string {
		$detail_wp  = (int) ( $reuse['detail_wp'] ?? 0 );
		$detail_gen = (int) ( $reuse['detail_generated'] ?? 0 );
		$big_wp     = (int) ( $reuse['big_wp'] ?? 0 );
		$big_gen    = (int) ( $reuse['big_generated'] ?? 0 );
		$detail_tot = $detail_wp + $detail_gen;
		$big_tot    = $big_wp + $big_gen;

		if ( $detail_tot <= 0 && $big_tot <= 0 ) {
			return '';
		}

		return sprintf(
			/* translators: 1: WP-reused detail count, 2: detail total, 3: WP-reused big count, 4: big total */
			__( 'WP image reuse — medium/detail: %1$d/%2$d, large/big: %3$d/%4$d.', 'vikus-viewer-embed' ),
			$detail_wp,
			$detail_tot,
			$big_wp,
			$big_tot
		);
	}

	/**
	 * Persist texture-errors.json next to collection assets.
	 *
	 * @param int                  $collection_id Collection ID.
	 * @param array<string,string> $errors        Map of item id => reason.
	 */
	private static function write_texture_errors_sidecar( int $collection_id, array $errors ): void {
		Paths::ensure_collection_dir( $collection_id );
		$file = Paths::file( $collection_id, 'texture-errors.json' );
		if ( empty( $errors ) ) {
			if ( file_exists( $file ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
				unlink( $file );
			}
			return;
		}
		ksort( $errors, SORT_NATURAL );
		FileWriter::put_contents(
			$file,
			(string) wp_json_encode( $errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);
	}
}
