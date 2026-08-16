<?php
/**
 * Match WordPress registered image sizes for Vikus texture tiers.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

/**
 * Class WpImageSizes
 */
final class WpImageSizes {

	/**
	 * Minimum edge ratio vs target (no upscale; must be "close enough").
	 */
	private const MIN_RATIO = 0.75;

	/**
	 * Find the best uncropped WP intermediate (or full) for a target max edge.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $target_edge   Desired max edge (e.g. 1024).
	 * @return array{url:string,size:string,edge:int}|null
	 */
	public static function best_url( int $attachment_id, int $target_edge ): ?array {
		$best = self::best_candidate( $attachment_id, $target_edge );
		if ( null === $best ) {
			return null;
		}
		return array(
			'url'  => $best['url'],
			'size' => $best['size'],
			'edge' => $best['edge'],
		);
	}

	/**
	 * Same as best_url, plus a readable filesystem path when available.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $target_edge   Desired max edge.
	 * @return array{url:string,size:string,edge:int,path:string}|null
	 */
	public static function best_file( int $attachment_id, int $target_edge ): ?array {
		$best = self::best_candidate( $attachment_id, $target_edge );
		if ( null === $best || '' === $best['path'] || ! is_readable( $best['path'] ) ) {
			return null;
		}
		return $best;
	}

	/**
	 * @param int $attachment_id Attachment ID.
	 * @param int $target_edge   Desired max edge.
	 * @return array{url:string,size:string,edge:int,path:string}|null
	 */
	private static function best_candidate( int $attachment_id, int $target_edge ): ?array {
		if ( $attachment_id <= 0 || $target_edge <= 0 ) {
			return null;
		}

		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}

		$attached = get_attached_file( $attachment_id );
		$base_dir = is_string( $attached ) && '' !== $attached ? dirname( $attached ) : '';

		$candidates = array();

		foreach ( wp_get_registered_image_subsizes() as $name => $size ) {
			if ( ! is_array( $size ) ) {
				continue;
			}
			if ( ! empty( $size['crop'] ) ) {
				continue;
			}
			if ( empty( $meta['sizes'][ $name ] ) || ! is_array( $meta['sizes'][ $name ] ) ) {
				continue;
			}
			$generated = $meta['sizes'][ $name ];
			$edge      = max( (int) ( $generated['width'] ?? 0 ), (int) ( $generated['height'] ?? 0 ) );
			if ( ! self::edge_is_usable( $edge, $target_edge ) ) {
				continue;
			}
			$url = wp_get_attachment_image_url( $attachment_id, $name );
			if ( ! is_string( $url ) || '' === $url ) {
				continue;
			}
			$file = isset( $generated['file'] ) ? (string) $generated['file'] : '';
			$path = ( '' !== $base_dir && '' !== $file ) ? $base_dir . '/' . ltrim( $file, '/\\' ) : '';
			$candidates[] = array(
				'url'  => $url,
				'size' => (string) $name,
				'edge' => $edge,
				'path' => $path,
			);
		}

		// Full original when it already fits the target band.
		$full_w = (int) ( $meta['width'] ?? 0 );
		$full_h = (int) ( $meta['height'] ?? 0 );
		$full_e = max( $full_w, $full_h );
		if ( self::edge_is_usable( $full_e, $target_edge ) ) {
			$url = wp_get_attachment_url( $attachment_id );
			if ( is_string( $url ) && '' !== $url ) {
				$candidates[] = array(
					'url'  => $url,
					'size' => 'full',
					'edge' => $full_e,
					'path' => is_string( $attached ) ? $attached : '',
				);
			}
		}

		if ( empty( $candidates ) ) {
			return null;
		}

		usort(
			$candidates,
			static function ( array $a, array $b ): int {
				return $b['edge'] <=> $a['edge'];
			}
		);

		$best = $candidates[0];
		return array(
			'url'  => (string) $best['url'],
			'size' => (string) $best['size'],
			'edge' => (int) $best['edge'],
			'path' => (string) ( $best['path'] ?? '' ),
		);
	}

	/**
	 * Whether an edge length is acceptable for a Vikus target.
	 *
	 * @param int $edge        Actual max edge.
	 * @param int $target_edge Target max edge.
	 */
	private static function edge_is_usable( int $edge, int $target_edge ): bool {
		if ( $edge <= 0 || $target_edge <= 0 ) {
			return false;
		}
		if ( $edge > $target_edge ) {
			return false;
		}
		return $edge >= (int) round( $target_edge * self::MIN_RATIO );
	}
}
