<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Center cover-crop a PNG file to an exact pixel size.
 *
 * Used by design artwork upload so participants can submit any PNG;
 * the stored print file is always campaign W×H.
 */
class CW_Design_Artwork_Crop {

	/**
	 * Ensure $path is a PNG at exactly $target_w × $target_h.
	 *
	 * Exact match: no rewrite. Otherwise center cover-crop in place.
	 *
	 * @param string $path     Absolute filesystem path to a PNG.
	 * @param int    $target_w Target width in pixels (≥ 1).
	 * @param int    $target_h Target height in pixels (≥ 1).
	 * @return true|string True on success, error message string on failure.
	 */
	public static function ensure_size( $path, $target_w, $target_h ) {
		$path     = (string) $path;
		$target_w = (int) $target_w;
		$target_h = (int) $target_h;

		if ( $path === '' || ! is_readable( $path ) ) {
			return 'Artwork file is missing or unreadable.';
		}
		if ( $target_w < 1 || $target_h < 1 ) {
			return 'Invalid target artwork size.';
		}

		$info = @getimagesize( $path );
		if ( ! is_array( $info ) || empty( $info[0] ) || empty( $info[1] ) ) {
			return 'Could not read artwork dimensions.';
		}

		$src_w = (int) $info[0];
		$src_h = (int) $info[1];

		if ( $src_w === $target_w && $src_h === $target_h ) {
			return true;
		}

		if ( function_exists( 'imagecreatefrompng' ) && function_exists( 'imagecopyresampled' ) ) {
			$ok = self::cover_crop_gd( $path, $src_w, $src_h, $target_w, $target_h );
			if ( $ok === true ) {
				return true;
			}
			if ( is_string( $ok ) ) {
				return $ok;
			}
		}

		if ( class_exists( 'Imagick' ) ) {
			$ok = self::cover_crop_imagick( $path, $target_w, $target_h );
			if ( $ok === true ) {
				return true;
			}
			if ( is_string( $ok ) ) {
				return $ok;
			}
		}

		return 'Server cannot resize artwork (GD/Imagick unavailable). Please upload a PNG at the required size or contact support.';
	}

	/**
	 * @return true|string|false
	 */
	private static function cover_crop_gd( $path, $src_w, $src_h, $target_w, $target_h ) {
		$src = @imagecreatefrompng( $path );
		if ( ! $src ) {
			return 'Could not decode PNG artwork.';
		}

		imagealphablending( $src, true );

		$dst_aspect = $target_w / $target_h;
		$src_aspect = $src_w / $src_h;

		if ( $src_aspect > $dst_aspect ) {
			// Wider than target — crop left/right.
			$crop_h = $src_h;
			$crop_w = (int) max( 1, round( $src_h * $dst_aspect ) );
			$src_x  = (int) max( 0, floor( ( $src_w - $crop_w ) / 2 ) );
			$src_y  = 0;
		} else {
			// Taller / equal — crop top/bottom (or full width).
			$crop_w = $src_w;
			$crop_h = (int) max( 1, round( $src_w / $dst_aspect ) );
			$src_x  = 0;
			$src_y  = (int) max( 0, floor( ( $src_h - $crop_h ) / 2 ) );
		}

		// Clamp crop window inside source.
		if ( $src_x + $crop_w > $src_w ) {
			$src_x = max( 0, $src_w - $crop_w );
		}
		if ( $src_y + $crop_h > $src_h ) {
			$src_y = max( 0, $src_h - $crop_h );
		}

		$dst = imagecreatetruecolor( $target_w, $target_h );
		if ( ! $dst ) {
			unset( $src );
			return 'Could not create cropped artwork canvas.';
		}

		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		$transparent = imagecolorallocatealpha( $dst, 0, 0, 0, 127 );
		imagefilledrectangle( $dst, 0, 0, $target_w, $target_h, $transparent );
		imagealphablending( $dst, true );

		$copied = imagecopyresampled(
			$dst,
			$src,
			0,
			0,
			$src_x,
			$src_y,
			$target_w,
			$target_h,
			$crop_w,
			$crop_h
		);

		unset( $src );

		if ( ! $copied ) {
			unset( $dst );
			return 'Could not crop artwork.';
		}

		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );

		$written = imagepng( $dst, $path, 6 );
		unset( $dst );

		if ( ! $written ) {
			return 'Could not save cropped artwork.';
		}

		$check = @getimagesize( $path );
		if ( ! is_array( $check ) || (int) $check[0] !== $target_w || (int) $check[1] !== $target_h ) {
			return 'Cropped artwork failed size verification.';
		}

		return true;
	}

	/**
	 * @return true|string|false
	 */
	private static function cover_crop_imagick( $path, $target_w, $target_h ) {
		try {
			$im = new Imagick( $path );
			$im->setImageFormat( 'png' );
			// cropThumbnailImage = scale to cover + center crop.
			$im->cropThumbnailImage( $target_w, $target_h );
			$ok = $im->writeImage( $path );
			$im->clear();
			$im->destroy();
			if ( ! $ok ) {
				return 'Could not save cropped artwork.';
			}
			$check = @getimagesize( $path );
			if ( ! is_array( $check ) || (int) $check[0] !== $target_w || (int) $check[1] !== $target_h ) {
				return 'Cropped artwork failed size verification.';
			}
			return true;
		} catch ( Exception $e ) {
			return 'Could not crop artwork with Imagick.';
		}
	}
}
