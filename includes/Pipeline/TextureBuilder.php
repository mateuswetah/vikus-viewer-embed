<?php
/**
 * Generate medium/large textures and sprite cell images.
 *
 * Detail/big tiers reuse uncropped WordPress intermediates when close enough
 * to the configured size (URL recorded in the manifest / data.csv). Sprite
 * cells are always generated locally for atlas packing.
 *
 * When pages_enabled, big textures use Vikus folder naming (`{id}.jpg`,
 * `{id}_1.jpg`, …) so the viewer can flip pages.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class TextureBuilder
 */
final class TextureBuilder {

	/**
	 * Process a batch of post IDs.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param int[] $item_ids      Source post IDs.
	 * @param int   $offset        Start offset into item_ids.
	 * @param int   $batch_size    Batch size.
	 * @param bool  $force         Force regenerate.
	 * @return array{processed:int,skipped:int,errors:int,error_details:array<string,string>,done:bool,next_offset:int}
	 */
	public static function process_batch( int $collection_id, array $item_ids, int $offset, int $batch_size, bool $force = false ): array {
		$settings  = Settings::get( $collection_id );
		$dir       = Paths::ensure_collection_dir( $collection_id );
		$base_url  = untrailingslashit( set_url_scheme( Paths::collection_url( $collection_id ) ) );
		$manifest  = Manifest::load( $collection_id );
		$large     = (int) $settings['large_size'];
		$medium    = (int) $settings['medium_size'];
		$sprite    = (int) $settings['sprite_size'];
		$pages_on  = ! empty( $settings['pages_enabled'] );
		if ( count( $item_ids ) >= 5000 && $sprite > 90 ) {
			$sprite = 90;
		}

		$large_dir  = $dir . '/' . $large;
		$medium_dir = $dir . '/' . $medium;
		$cell_dir   = $dir . '/tmp/' . $sprite;
		wp_mkdir_p( $large_dir );
		wp_mkdir_p( $medium_dir );
		wp_mkdir_p( $cell_dir );

		$slice         = array_slice( $item_ids, $offset, $batch_size );
		$processed     = 0;
		$skipped       = 0;
		$errors        = 0;
		$error_details = array();
		$reuse         = array(
			'detail_wp'        => 0,
			'detail_generated' => 0,
			'big_wp'           => 0,
			'big_generated'    => 0,
		);

		foreach ( $slice as $post_id ) {
			$id_key = (string) $post_id;
			$post_id = (int) $post_id;

			$page_ids = $pages_on
				? ItemPages::attachment_ids( $post_id )
				: array_values(
					array_filter(
						array( (int) get_post_thumbnail_id( $post_id ) )
					)
				);

			if ( empty( $page_ids ) ) {
				++$errors;
				$error_details[ $id_key ] = 'No featured image (_thumbnail_id).';
				continue;
			}

			$attachment_id = (int) $page_ids[0];
			$source        = get_attached_file( $attachment_id );
			if ( ! $source || ! file_exists( $source ) ) {
				++$errors;
				$error_details[ $id_key ] = sprintf(
					'Featured attachment %d file missing on disk (%s).',
					$attachment_id,
					$source ? $source : 'empty path'
				);
				continue;
			}

			$fp         = $pages_on ? ItemPages::fingerprint( $page_ids ) : Manifest::fingerprint( $source );
			$entry      = $manifest[ $id_key ] ?? array();
			$imagenum   = count( $page_ids );
			$large_path = $large_dir . '/' . ItemPages::texture_basename( $post_id, 0 );
			$medium_path = $medium_dir . '/' . ItemPages::texture_basename( $post_id, 0 );
			$cell_path  = $cell_dir . '/' . $id_key . '.png';

			$pages_fresh = true;
			if ( $pages_on ) {
				for ( $p = 0; $p < $imagenum; $p++ ) {
					if ( ! file_exists( $large_dir . '/' . ItemPages::texture_basename( $post_id, $p ) ) ) {
						$pages_fresh = false;
						break;
					}
				}
			}

			if ( Manifest::is_fresh( $entry, $fp, $force )
				&& ! empty( $entry['detail_url'] )
				&& ! empty( $entry['big_url'] )
				&& (int) ( $entry['imagenum'] ?? 0 ) === $imagenum
				&& file_exists( $cell_path )
				&& $pages_fresh
			) {
				++$skipped;
				continue;
			}

			$detail = self::resolve_tier( $attachment_id, $source, $medium, $medium_path, $base_url );
			$ok_cell  = ImageResizer::resize( $source, $cell_path, $sprite, 'png', 100 );
			$err_cell = $ok_cell ? '' : ImageResizer::last_error();

			$big_ok    = true;
			$big_url   = '';
			$big_via   = '';
			$big_error = '';

			if ( $pages_on ) {
				/*
				 * Folder URL mode requires `{id}[_n].jpg` under the collection.
				 * Prefer copying a matching WP intermediate into that path
				 * (no re-encode); fall back to generating from the original.
				 */
				$copied = 0;
				$generated = 0;
				for ( $p = 0; $p < $imagenum; $p++ ) {
					$page_attachment = (int) $page_ids[ $p ];
					$page_source     = get_attached_file( $page_attachment );
					$page_path       = $large_dir . '/' . ItemPages::texture_basename( $post_id, $p );
					if ( ! $page_source || ! file_exists( $page_source ) ) {
						$big_ok    = false;
						$big_error = sprintf( 'page %d attachment %d missing on disk', $p, $page_attachment );
						break;
					}
					$page_result = self::materialize_folder_texture(
						$page_attachment,
						$page_source,
						$large,
						$page_path,
						$base_url
					);
					if ( ! $page_result['ok'] ) {
						$big_ok    = false;
						$big_error = sprintf(
							'page %d: %s',
							$p,
							$page_result['error'] ?: 'unknown'
						);
						break;
					}
					if ( 0 === strpos( $page_result['via'], 'wp:' ) ) {
						++$copied;
					} else {
						++$generated;
					}
				}
				if ( $big_ok ) {
					$big_url = $base_url . '/' . $large . '/' . ItemPages::texture_basename( $post_id, 0 );
					$big_via = $copied > 0 && 0 === $generated
						? 'wp:pages:copy'
						: ( $copied > 0 ? 'mixed:pages' : 'generated:pages' );
					$reuse['big_wp']        += $copied;
					$reuse['big_generated'] += $generated;
					self::cleanup_extra_page_files( $large_dir, $post_id, $imagenum );
				}
			} else {
				$big = self::resolve_tier( $attachment_id, $source, $large, $large_path, $base_url );
				$big_ok    = $big['ok'];
				$big_url   = $big['url'];
				$big_via   = $big['via'];
				$big_error = $big['error'];
				if ( $big_ok ) {
					if ( 0 === strpos( $big_via, 'wp:' ) ) {
						++$reuse['big_wp'];
					} else {
						++$reuse['big_generated'];
					}
				}
			}

			if ( $detail['ok'] && $big_ok && $ok_cell ) {
				$manifest[ $id_key ] = array(
					'fingerprint'   => $fp,
					'attachment_id' => $attachment_id,
					'page_ids'      => $page_ids,
					'imagenum'      => $imagenum,
					'source'        => $source,
					'textures'      => true,
					'detail_url'    => $detail['url'],
					'detail_via'    => $detail['via'],
					'big_url'       => $big_url,
					'big_via'       => $big_via,
					'updated_at'    => time(),
				);
				if ( 0 === strpos( $detail['via'], 'wp:' ) ) {
					++$reuse['detail_wp'];
				} else {
					++$reuse['detail_generated'];
				}
				++$processed;
				continue;
			}

			++$errors;
			$failed = array();
			if ( ! $detail['ok'] ) {
				$failed[] = sprintf( 'detail(%d): %s', $medium, $detail['error'] ?: 'unknown' );
			}
			if ( ! $big_ok ) {
				$failed[] = sprintf( 'big(%d): %s', $large, $big_error ?: 'unknown' );
			}
			if ( ! $ok_cell ) {
				$failed[] = sprintf( 'sprite(%d): %s', $sprite, $err_cell ?: 'unknown' );
			}
			$error_details[ $id_key ] = sprintf(
				'Texture failed for attachment %d (%s). %s',
				$attachment_id,
				$source,
				implode( ' | ', $failed )
			);
		}

		Manifest::save( $collection_id, $manifest );

		$next = $offset + count( $slice );

		return array(
			'processed'     => $processed,
			'skipped'       => $skipped,
			'errors'        => $errors,
			'error_details' => $error_details,
			'reuse'         => $reuse,
			'done'          => $next >= count( $item_ids ),
			'next_offset'   => $next,
		);
	}

	/**
	 * Remove obsolete `{id}_N.jpg` files when page count shrinks.
	 *
	 * @param string $large_dir Large texture directory.
	 * @param int    $post_id   Post ID.
	 * @param int    $imagenum  Current page count.
	 */
	private static function cleanup_extra_page_files( string $large_dir, int $post_id, int $imagenum ): void {
		$pattern = $large_dir . '/' . (int) $post_id . '_*.jpg';
		foreach ( glob( $pattern ) ?: array() as $file ) {
			$base = basename( (string) $file, '.jpg' );
			if ( ! preg_match( '/^' . preg_quote( (string) (int) $post_id, '/' ) . '_(\d+)$/', $base, $m ) ) {
				continue;
			}
			$page = (int) $m[1];
			if ( $page >= $imagenum ) {
				wp_delete_file( (string) $file );
			}
		}
	}

	/**
	 * Place a big texture at a Vikus folder path: copy WP intermediate when
	 * possible, otherwise resize from the original.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        Original file path.
	 * @param int    $target_edge   Target max edge.
	 * @param string $local_path    Destination under the collection.
	 * @param string $base_url      Collection base URL (no trailing slash).
	 * @return array{ok:bool,url:string,via:string,error:string}
	 */
	private static function materialize_folder_texture( int $attachment_id, string $source, int $target_edge, string $local_path, string $base_url ): array {
		$folder = (string) $target_edge;
		$file   = basename( $local_path );
		$url    = $base_url . '/' . $folder . '/' . $file;

		$wp = WpImageSizes::best_file( $attachment_id, $target_edge );
		if ( null !== $wp ) {
			try {
				\VikusViewer\Support\FileWriter::prepare_overwrite( $local_path );
			} catch ( \RuntimeException $e ) {
				return array(
					'ok'    => false,
					'url'   => '',
					'via'   => 'wp:' . $wp['size'] . ':copy',
					'error' => $e->getMessage(),
				);
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- collection artifact copy.
			if ( @copy( $wp['path'], $local_path ) ) {
				\VikusViewer\Support\FileWriter::relax_permissions( $local_path );
				return array(
					'ok'    => true,
					'url'   => $url,
					'via'   => 'wp:' . $wp['size'] . ':copy',
					'error' => '',
				);
			}
		}

		$ok = ImageResizer::resize( $source, $local_path, $target_edge, 'jpg', 60 );
		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'via'   => 'generated',
				'error' => ImageResizer::last_error() ?: 'resize failed',
			);
		}

		return array(
			'ok'    => true,
			'url'   => $url,
			'via'   => 'generated',
			'error' => '',
		);
	}

	/**
	 * Resolve a detail/big texture: reuse WP intermediate URL or generate locally.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source        Original file path.
	 * @param int    $target_edge   Target max edge.
	 * @param string $local_path    Fallback local destination.
	 * @param string $base_url      Collection base URL (no trailing slash).
	 * @return array{ok:bool,url:string,via:string,error:string}
	 */
	private static function resolve_tier( int $attachment_id, string $source, int $target_edge, string $local_path, string $base_url ): array {
		$wp = WpImageSizes::best_url( $attachment_id, $target_edge );
		if ( null !== $wp ) {
			return array(
				'ok'    => true,
				'url'   => $wp['url'],
				'via'   => 'wp:' . $wp['size'],
				'error' => '',
			);
		}

		$ok = ImageResizer::resize( $source, $local_path, $target_edge, 'jpg', 60 );
		if ( ! $ok ) {
			return array(
				'ok'    => false,
				'url'   => '',
				'via'   => 'generated',
				'error' => ImageResizer::last_error() ?: 'resize failed',
			);
		}

		$folder = (string) $target_edge;
		$file   = basename( $local_path );

		return array(
			'ok'    => true,
			'url'   => $base_url . '/' . $folder . '/' . $file,
			'via'   => 'generated',
			'error' => '',
		);
	}
}
