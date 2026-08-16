<?php
/**
 * Image resize helper (Imagick preferred, GD fallback).
 *
 * @package VikusViewer
 */

declare(strict_types=1);

namespace VikusViewer\Pipeline;

/**
 * Class ImageResizer
 */
final class ImageResizer {

	/**
	 * Last failure reason from resize().
	 *
	 * @var string
	 */
	private static string $last_error = '';

	/**
	 * Whether Imagick is available.
	 */
	public static function has_imagick(): bool {
		return class_exists( '\Imagick' );
	}

	/**
	 * Whether GD is available.
	 */
	public static function has_gd(): bool {
		return function_exists( 'imagecreatetruecolor' );
	}

	/**
	 * Last resize failure message (empty after success).
	 */
	public static function last_error(): string {
		return self::$last_error;
	}

	/**
	 * Resize an image to fit within max edge, writing JPEG (or PNG for sprites).
	 *
	 * @param string $source   Source file path.
	 * @param string $dest     Destination file path.
	 * @param int    $max_edge Max width/height.
	 * @param string $format   jpg|png.
	 * @param int    $quality  0-100.
	 * @return bool
	 */
	public static function resize( string $source, string $dest, int $max_edge, string $format = 'jpg', int $quality = 70 ): bool {
		self::$last_error = '';

		if ( ! file_exists( $source ) ) {
			self::$last_error = 'Source file does not exist: ' . $source;
			return false;
		}

		$dir = dirname( $dest );
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		if ( self::has_imagick() ) {
			return self::resize_imagick( $source, $dest, $max_edge, $format, $quality );
		}

		if ( self::has_gd() ) {
			return self::resize_gd( $source, $dest, $max_edge, $format, $quality );
		}

		self::$last_error = 'Neither Imagick nor GD is available.';
		return false;
	}

	/**
	 * Imagick resize.
	 *
	 * @param string $source   Source.
	 * @param string $dest     Dest.
	 * @param int    $max_edge Max edge.
	 * @param string $format   Format.
	 * @param int    $quality  Quality.
	 */
	private static function resize_imagick( string $source, string $dest, int $max_edge, string $format, int $quality ): bool {
		try {
			\VikusViewer\Support\FileWriter::prepare_overwrite( $dest );

			$image = new \Imagick( $source );

			if ( defined( 'Imagick::ORIENTATION_TOPLEFT' ) ) {
				$orientation = $image->getImageOrientation();
				switch ( $orientation ) {
					case \Imagick::ORIENTATION_RIGHTTOP:
						$image->rotateImage( new \ImagickPixel( '#00000000' ), 90 );
						break;
					case \Imagick::ORIENTATION_BOTTOMRIGHT:
						$image->rotateImage( new \ImagickPixel( '#00000000' ), 180 );
						break;
					case \Imagick::ORIENTATION_LEFTBOTTOM:
						$image->rotateImage( new \ImagickPixel( '#00000000' ), -90 );
						break;
				}
				$image->setImageOrientation( \Imagick::ORIENTATION_TOPLEFT );
			}

			$image->thumbnailImage( $max_edge, $max_edge, true );

			if ( 'png' === $format ) {
				$image->setImageFormat( 'png' );
			} else {
				$has_alpha = method_exists( $image, 'getImageAlphaChannel' ) && $image->getImageAlphaChannel();
				if ( $has_alpha ) {
					$flattened = new \Imagick();
					$flattened->newImage( $image->getImageWidth(), $image->getImageHeight(), new \ImagickPixel( 'white' ) );
					$flattened->compositeImage( $image, \Imagick::COMPOSITE_OVER, 0, 0 );
					$image->clear();
					$image->destroy();
					$image = $flattened;
				}
				$image->setImageFormat( 'jpeg' );
				$image->setImageCompression( \Imagick::COMPRESSION_JPEG );
				$image->setImageCompressionQuality( $quality );
			}

			$ok = $image->writeImage( $dest );
			$image->clear();
			$image->destroy();
			if ( $ok ) {
				\VikusViewer\Support\FileWriter::relax_permissions( $dest );
				self::$last_error = '';
				return true;
			}
			self::$last_error = 'Imagick writeImage returned false for ' . $dest;
			return false;
		} catch ( \Throwable $e ) {
			self::$last_error = 'Imagick: ' . $e->getMessage();
			return false;
		}
	}

	/**
	 * GD resize.
	 *
	 * @param string $source   Source.
	 * @param string $dest     Dest.
	 * @param int    $max_edge Max edge.
	 * @param string $format   Format.
	 * @param int    $quality  Quality.
	 */
	private static function resize_gd( string $source, string $dest, int $max_edge, string $format, int $quality ): bool {
		$info = @getimagesize( $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info ) {
			self::$last_error = 'GD could not read image info: ' . $source;
			return false;
		}

		[ $width, $height, $type ] = $info;

		switch ( $type ) {
			case IMAGETYPE_JPEG:
				$src = imagecreatefromjpeg( $source );
				break;
			case IMAGETYPE_PNG:
				$src = imagecreatefrompng( $source );
				break;
			case IMAGETYPE_GIF:
				$src = imagecreatefromgif( $source );
				break;
			case IMAGETYPE_WEBP:
				if ( function_exists( 'imagecreatefromwebp' ) ) {
					$src = imagecreatefromwebp( $source );
					break;
				}
				self::$last_error = 'GD WebP support is not available.';
				return false;
			default:
				self::$last_error = 'GD unsupported image type: ' . (string) $type;
				return false;
		}

		if ( ! $src ) {
			self::$last_error = 'GD failed to open source image: ' . $source;
			return false;
		}

		$scale = min( $max_edge / max( 1, $width ), $max_edge / max( 1, $height ), 1.0 );
		$new_w = max( 1, (int) round( $width * $scale ) );
		$new_h = max( 1, (int) round( $height * $scale ) );

		$dst = imagecreatetruecolor( $new_w, $new_h );
		if ( ! $dst ) {
			imagedestroy( $src );
			self::$last_error = 'GD imagecreatetruecolor failed.';
			return false;
		}

		if ( 'png' === $format ) {
			imagealphablending( $dst, false );
			imagesavealpha( $dst, true );
			$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
			imagefilledrectangle( $dst, 0, 0, $new_w, $new_h, $transparent );
		} else {
			$white = imagecolorallocate( $dst, 255, 255, 255 );
			imagefilledrectangle( $dst, 0, 0, $new_w, $new_h, $white );
		}

		imagecopyresampled( $dst, $src, 0, 0, 0, 0, $new_w, $new_h, $width, $height );

		try {
			\VikusViewer\Support\FileWriter::prepare_overwrite( $dest );
		} catch ( \Throwable $e ) {
			imagedestroy( $src );
			imagedestroy( $dst );
			self::$last_error = 'Cannot write destination: ' . $e->getMessage();
			return false;
		}

		$ok = false;
		if ( 'png' === $format ) {
			$ok = imagepng( $dst, $dest, 6 );
		} else {
			$ok = imagejpeg( $dst, $dest, $quality );
		}

		imagedestroy( $src );
		imagedestroy( $dst );

		if ( $ok ) {
			\VikusViewer\Support\FileWriter::relax_permissions( $dest );
			self::$last_error = '';
			return true;
		}

		self::$last_error = 'GD failed to write ' . $dest;
		return false;
	}

	/**
	 * Read image dimensions.
	 *
	 * @param string $path Path.
	 * @return array{0:int,1:int}|null
	 */
	public static function dimensions( string $path ): ?array {
		$info = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $info ) {
			return null;
		}
		return array( (int) $info[0], (int) $info[1] );
	}
}
