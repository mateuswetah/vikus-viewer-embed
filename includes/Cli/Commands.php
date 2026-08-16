<?php
/**
 * WP-CLI commands.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Cli;

use VikusViewer\Pipeline\BuildQueue;
use VikusViewer\Support\Settings;

/**
 * Class Commands
 */
final class Commands {

	/**
	 * Register WP-CLI commands.
	 */
	public static function register(): void {
		\WP_CLI::add_command( 'vikus', self::class );
	}

	/**
	 * Build a collection.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Collection post ID.
	 *
	 * [--force]
	 * : Force regenerate textures even if unchanged.
	 *
	 * [--step=<step>]
	 * : Run only one step: csv, textures, or sprites.
	 *
	 * [--csv-only]
	 * : Alias for --step=csv.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vikus build 12
	 *     wp vikus build 12 --force
	 *     wp vikus build 12 --step=csv
	 *
	 * @param array<int, string>    $args       Positional.
	 * @param array<string, string> $assoc_args Flags.
	 */
	public function build( array $args, array $assoc_args ): void {
		$collection_id = (int) ( $args[0] ?? 0 );
		if ( $collection_id <= 0 || 'vikus_collection' !== get_post_type( $collection_id ) ) {
			\WP_CLI::error( 'Invalid collection ID.' );
		}

		$force = isset( $assoc_args['force'] );
		$step  = isset( $assoc_args['step'] ) ? (string) $assoc_args['step'] : null;
		if ( isset( $assoc_args['csv-only'] ) ) {
			$step = 'csv';
		}
		if ( $step && ! in_array( $step, array( 'csv', 'textures', 'sprites' ), true ) ) {
			\WP_CLI::error( 'Invalid step. Use csv, textures, or sprites.' );
		}

		\WP_CLI::log( sprintf( 'Building collection %d…', $collection_id ) );

		BuildQueue::queue( $collection_id, $force, $step, false );

		$progress     = null;
		$progress_at  = 0;
		$progress_max = 0;
		$last_step    = '';
		$lock_notice  = false;

		$status = BuildQueue::run_until_complete(
			$collection_id,
			static function ( array $current ) use ( &$progress, &$progress_at, &$progress_max, &$last_step, &$lock_notice ): void {
				if ( ! empty( $current['_waiting_lock'] ) ) {
					if ( ! $lock_notice ) {
						\WP_CLI::log( 'Waiting for build lock (cancel the UI build if stuck)…' );
						$lock_notice = true;
					}
					return;
				}

				$step = (string) ( $current['step'] ?? '' );

				if ( $step !== $last_step ) {
					if ( $progress ) {
						$progress->finish();
						$progress     = null;
						$progress_at  = 0;
						$progress_max = 0;
					}

					if ( 'csv' === $step ) {
						\WP_CLI::log( 'Exporting data.csv and config.json…' );
					} elseif ( 'sprites' === $step ) {
						\WP_CLI::log( 'Building spritesheets…' );
					} elseif ( 'textures' === $step && (int) ( $current['total'] ?? 0 ) <= 0 ) {
						\WP_CLI::log( 'Preparing texture list…' );
					}

					$last_step = $step;
				}

				if ( 'textures' !== $step ) {
					return;
				}

				$total = max( 0, (int) ( $current['total'] ?? 0 ) );
				if ( $total <= 0 ) {
					return;
				}

				if ( ! $progress && function_exists( '\\WP_CLI\\Utils\\make_progress_bar' ) ) {
					$progress_max = $total;
					$progress     = \WP_CLI\Utils\make_progress_bar( 'Generating textures', $progress_max );
					$progress_at  = 0;
				} elseif ( ! $progress ) {
					\WP_CLI::log( sprintf( 'Generating textures (%d items)…', $total ) );
					return;
				}

				$completed = min( (int) ( $current['completed'] ?? 0 ), $progress_max );
				while ( $progress_at < $completed ) {
					$progress->tick();
					++$progress_at;
				}
			}
		);

		if ( $progress ) {
			while ( $progress_at < $progress_max ) {
				$progress->tick();
				++$progress_at;
			}
			$progress->finish();
		}

		if ( 'complete' === $status['status'] ) {
			\WP_CLI::success( (string) $status['message'] );
			$reuse = isset( $status['texture_reuse'] ) && is_array( $status['texture_reuse'] )
				? $status['texture_reuse']
				: array();
			$detail_wp  = (int) ( $reuse['detail_wp'] ?? 0 );
			$detail_gen = (int) ( $reuse['detail_generated'] ?? 0 );
			$big_wp     = (int) ( $reuse['big_wp'] ?? 0 );
			$big_gen    = (int) ( $reuse['big_generated'] ?? 0 );
			if ( ( $detail_wp + $detail_gen + $big_wp + $big_gen ) > 0 ) {
				\WP_CLI::log(
					sprintf(
						'Texture sources — medium/detail: %d WP reused, %d generated | large/big: %d WP reused, %d generated',
						$detail_wp,
						$detail_gen,
						$big_wp,
						$big_gen
					)
				);
			}
			$texture_errors = isset( $status['texture_errors'] ) && is_array( $status['texture_errors'] )
				? $status['texture_errors']
				: array();
			if ( ! empty( $texture_errors ) ) {
				\WP_CLI::warning(
					sprintf(
						'%d item(s) missing from manifest (texture failures). See uploads/vikus/%d/texture-errors.json',
						count( $texture_errors ),
						$collection_id
					)
				);
				$i = 0;
				foreach ( $texture_errors as $item_id => $reason ) {
					\WP_CLI::log( sprintf( '  #%s — %s', $item_id, $reason ) );
					if ( ++$i >= 20 ) {
						\WP_CLI::log( '  …' );
						break;
					}
				}
			}
			return;
		}

		if ( 'cancelled' === $status['status'] ) {
			\WP_CLI::warning( (string) ( $status['message'] ?: 'Build cancelled.' ) );
			return;
		}

		\WP_CLI::error( (string) ( $status['last_error'] ?: $status['message'] ?: 'Build failed.' ) );
	}

	/**
	 * Cancel an in-progress build.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Collection post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp vikus cancel 12
	 *     wp vikus cancel 12 && wp vikus build 12
	 *
	 * @param array<int, string> $args Positional.
	 */
	public function cancel( array $args ): void {
		$collection_id = (int) ( $args[0] ?? 0 );
		if ( $collection_id <= 0 || 'vikus_collection' !== get_post_type( $collection_id ) ) {
			\WP_CLI::error( 'Invalid collection ID.' );
		}

		$was_active = BuildQueue::cancel( $collection_id );
		if ( $was_active ) {
			\WP_CLI::success( sprintf( 'Cancelled build for collection %d. You can now run: wp vikus build %d', $collection_id, $collection_id ) );
			return;
		}

		\WP_CLI::success( sprintf( 'No active build for collection %d (status set to cancelled, lock cleared).', $collection_id ) );
	}

	/**
	 * Show build status.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Collection post ID.
	 *
	 * @param array<int, string> $args Positional.
	 */
	public function status( array $args ): void {
		$collection_id = (int) ( $args[0] ?? 0 );
		if ( $collection_id <= 0 || 'vikus_collection' !== get_post_type( $collection_id ) ) {
			\WP_CLI::error( 'Invalid collection ID.' );
		}

		$status = Settings::get_build_status( $collection_id );
		\WP_CLI::log( wp_json_encode( $status, JSON_PRETTY_PRINT ) );
	}
}
