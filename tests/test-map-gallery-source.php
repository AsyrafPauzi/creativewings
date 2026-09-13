<?php

$root   = dirname( __DIR__ );
$source = file_get_contents( $root . '/includes/class-cw-shortcodes.php' );
$js     = file_get_contents( $root . '/assets/js/cw-map-gallery.js' );

function source_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

source_assert( strpos( $source, 'cwd-map-canvas' ) !== false, 'shortcode renders canvas layer' );
source_assert(
    (bool) preg_match( "/'fields'\\s*=>\\s*'ids'/", $source ),
    'map query fetches compact IDs'
);
source_assert( strpos( $source, 'CW_Map_Coordinates::max_slots()' ) !== false, 'map query uses Malaysia slot cap' );
source_assert( strpos( $source, 'malaysia-map.svg' ) !== false, 'shortcode uses Malaysia map asset' );
source_assert( strpos( $source, 'slots_for_display' ) !== false, 'shortcode passes slot mosaic data' );
source_assert( strpos( $source, '$map_interactive_cap' ) === false, '150-pin cap removed' );
source_assert( strpos( $js, 'getContext(\'2d\')' ) !== false, 'JavaScript draws through Canvas 2D' );
source_assert( strpos( $js, 'requestAnimationFrame' ) !== false, 'resize redraw is frame-debounced' );
source_assert( strpos( $js, 'cwdMapOpenPin' ) !== false, 'filled smiley click opens lightbox' );
source_assert( strpos( $source, 'data-cwd-map-zoom-in' ) !== false, 'shortcode renders zoom controls' );
source_assert( strpos( $source, 'data-cwd-map-viewport' ) !== false, 'shortcode wraps map in zoom viewport' );
source_assert( strpos( $source, 'filledSlots' ) !== false, 'shortcode passes random filled slot placements' );
source_assert( strpos( $source, 'random_slot_assignments' ) !== false, 'shortcode uses random slot assignment' );
source_assert( strpos( $js, 'filledLookup' ) !== false, 'JavaScript renders random filled slots' );
source_assert( strpos( $js, 'clampPan' ) !== false, 'JavaScript clamps pan while zoomed' );
source_assert( strpos( $js, 'setInterval' ) === false, 'canvas has no animation loop' );

echo "PASS: map gallery source checks\n";
