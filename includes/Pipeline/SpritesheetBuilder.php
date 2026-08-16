<?php
/**
 * Build sharpsheet-compatible spritesheets from sprite cell images.
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

use VikusViewer\Support\Paths;
use VikusViewer\Support\Settings;

/**
 * Class SpritesheetBuilder
 */
final class SpritesheetBuilder {

	/**
	 * Build spritesheets for all cells belonging to item IDs.
	 *
	 * @param int   $collection_id Collection post ID.
	 * @param int[] $item_ids      Item IDs.
	 * @return array{sheets:int,sprites:int}
	 */
	public static function build( int $collection_id, array $item_ids ): array {
		$settings = Settings::get( $collection_id );
		$dir      = Paths::ensure_collection_dir( $collection_id );
		$sprite   = (int) $settings['sprite_size'];
		if ( count( $item_ids ) >= 5000 && $sprite > 90 ) {
			$sprite = 90;
		}
		$sheet_dim = (int) $settings['sheet_dimension'];
		$cell_dir  = $dir . '/tmp/' . $sprite;
		$out_dir   = $dir . '/sprites';
		wp_mkdir_p( $out_dir );
		\VikusViewer\Support\FileWriter::relax_permissions( $out_dir );

		$images = array();
		foreach ( $item_ids as $post_id ) {
			$path = $cell_dir . '/' . $post_id . '.png';
			if ( ! file_exists( $path ) ) {
				// Fallback: try jpg cell.
				$alt = $cell_dir . '/' . $post_id . '.jpg';
				if ( file_exists( $alt ) ) {
					$path = $alt;
				} else {
					continue;
				}
			}
			$dims = ImageResizer::dimensions( $path );
			if ( ! $dims ) {
				continue;
			}
			$images[] = array(
				'name'   => (string) $post_id,
				'path'   => $path,
				'width'  => $dims[0],
				'height' => $dims[1],
			);
		}

		if ( empty( $images ) ) {
			throw new \RuntimeException( 'No sprite cell images found to pack.' );
		}

		// Sort by height descending (shelf-pack heuristic).
		usort(
			$images,
			static function ( array $a, array $b ): int {
				return $b['height'] <=> $a['height'];
			}
		);

		$border = 1;
		$packs  = self::shelf_pack( $images, $sheet_dim, $border );

		$spritesheets = array();
		$index        = 0;
		$total_sprites = 0;

		foreach ( $packs as $pack ) {
			$filename = sprintf( 'sprite-%d-%d.jpg', $sheet_dim, $index );
			$dest     = $out_dir . '/' . $filename;
			self::compose_sheet( $pack, $sheet_dim, $dest, 70 );

			$sprites = array();
			foreach ( $pack as $item ) {
				$sprites[] = array(
					'name'      => $item['name'],
					'position'  => array(
						'x' => $item['x'],
						'y' => $item['y'],
					),
					'dimension' => array(
						'w' => $item['width'],
						'h' => $item['height'],
					),
				);
				++$total_sprites;
			}

			$spritesheets[] = array(
				'image'   => $filename,
				'sprites' => $sprites,
			);
			++$index;
		}

		$atlas = array(
			'meta' => array(
				'type'    => 'sharpsheet',
				'version' => '1',
				'app'     => 'wordpress-vikus-viewer',
			),
			'spritesheets' => $spritesheets,
		);

		\VikusViewer\Support\FileWriter::put_contents(
			$out_dir . '/spritesheet.json',
			(string) wp_json_encode( $atlas, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
		);

		return array(
			'sheets'  => count( $spritesheets ),
			'sprites' => $total_sprites,
		);
	}

	/**
	 * Whether existing sprites already cover the given item set (skip rebuild).
	 *
	 * @param int   $collection_id Collection ID.
	 * @param int[] $item_ids      Expected item IDs.
	 */
	public static function covers_items( int $collection_id, array $item_ids ): bool {
		$atlas_path = Paths::file( $collection_id, 'sprites/spritesheet.json' );
		if ( ! file_exists( $atlas_path ) ) {
			return false;
		}

		$raw  = file_get_contents( $atlas_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$data = json_decode( (string) $raw, true );
		if ( ! is_array( $data ) || empty( $data['spritesheets'] ) || ! is_array( $data['spritesheets'] ) ) {
			return false;
		}

		$found = array();
		foreach ( $data['spritesheets'] as $sheet ) {
			if ( empty( $sheet['image'] ) || ! is_string( $sheet['image'] ) ) {
				return false;
			}
			$image_path = Paths::file( $collection_id, 'sprites/' . $sheet['image'] );
			if ( ! file_exists( $image_path ) ) {
				return false;
			}
			if ( empty( $sheet['sprites'] ) || ! is_array( $sheet['sprites'] ) ) {
				continue;
			}
			foreach ( $sheet['sprites'] as $sprite ) {
				if ( isset( $sprite['name'] ) ) {
					$found[ (string) $sprite['name'] ] = true;
				}
			}
		}

		$expected = array();
		foreach ( $item_ids as $id ) {
			$expected[ (string) $id ] = true;
		}

		if ( count( $expected ) !== count( $found ) ) {
			return false;
		}

		foreach ( $expected as $id => $_true ) {
			if ( ! isset( $found[ $id ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Shelf-pack images into sheets.
	 *
	 * @param array<int, array<string, mixed>> $images    Images.
	 * @param int                              $sheet_dim Sheet size.
	 * @param int                              $border    Border.
	 * @return array<int, array<int, array<string, mixed>>>
	 */
	private static function shelf_pack( array $images, int $sheet_dim, int $border ): array {
		$queue = $images;
		$packs = array();

		while ( ! empty( $queue ) ) {
			$pack       = array();
			$shelf_x    = $border;
			$shelf_y    = $border;
			$shelf_h    = 0;
			$remaining  = array();

			foreach ( $queue as $img ) {
				$w = (int) $img['width'];
				$h = (int) $img['height'];
				$bw = $w + 2 * $border;
				$bh = $h + 2 * $border;

				if ( $bw > $sheet_dim || $bh > $sheet_dim ) {
					// Skip oversized — should not happen after resize.
					continue;
				}

				if ( $shelf_x + $w + $border > $sheet_dim ) {
					// New shelf.
					$shelf_y += $shelf_h + $border;
					$shelf_x  = $border;
					$shelf_h  = 0;
				}

				if ( $shelf_y + $h + $border > $sheet_dim ) {
					$remaining[] = $img;
					continue;
				}

				$pack[] = array(
					'name'   => $img['name'],
					'path'   => $img['path'],
					'width'  => $w,
					'height' => $h,
					'x'      => $shelf_x,
					'y'      => $shelf_y,
				);

				$shelf_x += $w + $border;
				$shelf_h  = max( $shelf_h, $h );
			}

			if ( empty( $pack ) ) {
				// Safety: avoid infinite loop if nothing fits.
				break;
			}

			$packs[] = $pack;
			$queue   = $remaining;
		}

		return $packs;
	}

	/**
	 * Compose one spritesheet JPEG.
	 *
	 * @param array<int, array<string, mixed>> $pack      Packed sprites.
	 * @param int                              $sheet_dim Dimension.
	 * @param string                           $dest      Destination path.
	 * @param int                              $quality   JPEG quality.
	 */
	private static function compose_sheet( array $pack, int $sheet_dim, string $dest, int $quality ): void {
		if ( ImageResizer::has_imagick() ) {
			self::compose_imagick( $pack, $sheet_dim, $dest, $quality );
			return;
		}

		self::compose_gd( $pack, $sheet_dim, $dest, $quality );
	}

	/**
	 * Compose with Imagick.
	 *
	 * @param array<int, array<string, mixed>> $pack      Pack.
	 * @param int                              $sheet_dim Dim.
	 * @param string                           $dest      Dest.
	 * @param int                              $quality   Quality.
	 */
	private static function compose_imagick( array $pack, int $sheet_dim, string $dest, int $quality ): void {
		\VikusViewer\Support\FileWriter::prepare_overwrite( $dest );

		$canvas = new \Imagick();
		$canvas->newImage( $sheet_dim, $sheet_dim, new \ImagickPixel( 'white' ) );
		$canvas->setImageFormat( 'jpeg' );

		foreach ( $pack as $item ) {
			$sprite = new \Imagick( (string) $item['path'] );
			$canvas->compositeImage( $sprite, \Imagick::COMPOSITE_OVER, (int) $item['x'], (int) $item['y'] );
			$sprite->clear();
			$sprite->destroy();
		}

		$canvas->setImageCompression( \Imagick::COMPRESSION_JPEG );
		$canvas->setImageCompressionQuality( $quality );
		$canvas->writeImage( $dest );
		$canvas->clear();
		$canvas->destroy();
		\VikusViewer\Support\FileWriter::relax_permissions( $dest );
	}

	/**
	 * Compose with GD.
	 *
	 * @param array<int, array<string, mixed>> $pack      Pack.
	 * @param int                              $sheet_dim Dim.
	 * @param string                           $dest      Dest.
	 * @param int                              $quality   Quality.
	 */
	private static function compose_gd( array $pack, int $sheet_dim, string $dest, int $quality ): void {
		\VikusViewer\Support\FileWriter::prepare_overwrite( $dest );

		$canvas = imagecreatetruecolor( $sheet_dim, $sheet_dim );
		$white  = imagecolorallocate( $canvas, 255, 255, 255 );
		imagefilledrectangle( $canvas, 0, 0, $sheet_dim, $sheet_dim, $white );

		foreach ( $pack as $item ) {
			$path = (string) $item['path'];
			$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $info ) {
				continue;
			}
			switch ( $info[2] ) {
				case IMAGETYPE_PNG:
					$src = imagecreatefrompng( $path );
					break;
				case IMAGETYPE_JPEG:
					$src = imagecreatefromjpeg( $path );
					break;
				default:
					$src = false;
			}
			if ( ! $src ) {
				continue;
			}
			imagecopy( $canvas, $src, (int) $item['x'], (int) $item['y'], 0, 0, (int) $item['width'], (int) $item['height'] );
			imagedestroy( $src );
		}

		imagejpeg( $canvas, $dest, $quality );
		imagedestroy( $canvas );
		\VikusViewer\Support\FileWriter::relax_permissions( $dest );
	}
}
