<?php
/**
 * Resolve multi-page image attachments for a source item.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

/**
 * Class ItemPages
 */
final class ItemPages {

	/**
	 * Ordered attachment IDs for detail pages: featured image first, then other
	 * image attachments parented to the post (excluding the featured duplicate).
	 *
	 * @param int $post_id Source post ID.
	 * @return int[]
	 */
	public static function attachment_ids( int $post_id ): array {
		$post_id = (int) $post_id;
		if ( $post_id <= 0 ) {
			return array();
		}

		$featured = (int) get_post_thumbnail_id( $post_id );
		$ids      = array();
		if ( $featured > 0 ) {
			$ids[] = $featured;
		}

		$children = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'image',
				'post_parent'    => $post_id,
				'post_status'    => 'inherit',
				'posts_per_page' => -1,
				'orderby'        => 'menu_order ID',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		foreach ( $children as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			if ( $attachment_id <= 0 || $attachment_id === $featured ) {
				continue;
			}
			$ids[] = $attachment_id;
		}

		return array_values( array_unique( $ids ) );
	}

	/**
	 * Page count for CSV `imagenum` (at least 1 when a featured image exists).
	 *
	 * @param int                  $post_id  Source post ID.
	 * @param array<string, mixed> $settings Collection settings.
	 */
	public static function imagenum( int $post_id, array $settings ): int {
		if ( empty( $settings['pages_enabled'] ) ) {
			return get_post_thumbnail_id( $post_id ) ? 1 : 0;
		}
		$count = count( self::attachment_ids( $post_id ) );
		return max( 0, $count );
	}

	/**
	 * Local texture basename for a 0-based page index (Vikus folder URL mode).
	 *
	 * Page 0 → `{id}.jpg`; page N → `{id}_N.jpg`.
	 *
	 * @param int|string $post_id Post ID.
	 * @param int        $page    0-based page index.
	 */
	public static function texture_basename( $post_id, int $page ): string {
		$id = (string) (int) $post_id;
		if ( $page <= 0 ) {
			return $id . '.jpg';
		}
		return $id . '_' . $page . '.jpg';
	}

	/**
	 * Combined fingerprint for all page source files.
	 *
	 * @param int[] $attachment_ids Attachment IDs.
	 */
	public static function fingerprint( array $attachment_ids ): string {
		$parts = array();
		foreach ( $attachment_ids as $attachment_id ) {
			$path = get_attached_file( (int) $attachment_id );
			$parts[] = (string) (int) $attachment_id . ':' . Manifest::fingerprint( is_string( $path ) ? $path : '' );
		}
		return md5( implode( '|', $parts ) );
	}
}
