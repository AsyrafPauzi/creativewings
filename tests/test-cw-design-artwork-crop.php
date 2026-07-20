<?php
/**
 * Unit tests for CW_Design_Artwork_Crop (no WordPress bootstrap).
 */

define( 'ABSPATH', __DIR__ . '/' );

$class_file = dirname( __DIR__ ) . '/includes/class-cw-design-artwork-crop.php';
if ( ! file_exists( $class_file ) ) {
	fwrite( STDERR, "FAIL: CW_Design_Artwork_Crop class file does not exist\n" );
	exit( 1 );
}
require_once $class_file;

function cw_crop_assert( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

function cw_crop_make_png( $path, $w, $h, $r = 200, $g = 40, $b = 80 ) {
	$im = imagecreatetruecolor( $w, $h );
	$color = imagecolorallocate( $im, $r, $g, $b );
	imagefilledrectangle( $im, 0, 0, $w - 1, $h - 1, $color );
	imagepng( $im, $path );
	unset( $im );
}

$tmp = sys_get_temp_dir() . '/cw-crop-' . uniqid( '', true );
mkdir( $tmp );

$target_w = 40;
$target_h = 200;

// Exact size — pass through.
$exact = $tmp . '/exact.png';
cw_crop_make_png( $exact, $target_w, $target_h, 10, 20, 30 );
$before = md5_file( $exact );
$result = CW_Design_Artwork_Crop::ensure_size( $exact, $target_w, $target_h );
cw_crop_assert( $result === true, 'exact size returns true' );
cw_crop_assert( md5_file( $exact ) === $before, 'exact size does not rewrite file' );

// Wider than target aspect — cover-crop to exact pixels.
$wide = $tmp . '/wide.png';
cw_crop_make_png( $wide, 400, 200, 255, 0, 0 );
$result = CW_Design_Artwork_Crop::ensure_size( $wide, $target_w, $target_h );
cw_crop_assert( $result === true, 'wide image crops successfully' );
$info = getimagesize( $wide );
cw_crop_assert( is_array( $info ), 'wide output readable' );
cw_crop_assert( (int) $info[0] === $target_w && (int) $info[1] === $target_h, 'wide output is exact target size' );

// Taller / different aspect.
$tall = $tmp . '/tall.png';
cw_crop_make_png( $tall, 20, 500, 0, 255, 0 );
$result = CW_Design_Artwork_Crop::ensure_size( $tall, $target_w, $target_h );
cw_crop_assert( $result === true, 'tall image crops successfully' );
$info = getimagesize( $tall );
cw_crop_assert( (int) $info[0] === $target_w && (int) $info[1] === $target_h, 'tall output is exact target size' );

// Tiny upscale.
$tiny = $tmp . '/tiny.png';
cw_crop_make_png( $tiny, 8, 8, 0, 0, 255 );
$result = CW_Design_Artwork_Crop::ensure_size( $tiny, $target_w, $target_h );
cw_crop_assert( $result === true, 'tiny image upscales successfully' );
$info = getimagesize( $tiny );
cw_crop_assert( (int) $info[0] === $target_w && (int) $info[1] === $target_h, 'tiny output is exact target size' );

// Missing file.
$missing = CW_Design_Artwork_Crop::ensure_size( $tmp . '/nope.png', $target_w, $target_h );
cw_crop_assert( is_string( $missing ) && $missing !== '', 'missing file returns error string' );

// Cleanup.
foreach ( glob( $tmp . '/*' ) as $f ) {
	@unlink( $f );
}
@rmdir( $tmp );

echo "PASS: CW design artwork crop\n";
