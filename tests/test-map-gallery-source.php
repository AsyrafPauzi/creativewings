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
    'canvas query fetches compact IDs'
);
source_assert( strpos( $source, 'CW_Map_Coordinates::MAX_POINTS' ) !== false, 'canvas query uses 10,000 safety cap' );
source_assert( strpos( $source, '$map_interactive_cap = 150' ) !== false, 'interactive pins remain capped at 150' );
source_assert( strpos( $js, 'getContext(\'2d\')' ) !== false, 'JavaScript draws through Canvas 2D' );
source_assert( strpos( $js, 'requestAnimationFrame' ) !== false, 'resize redraw is frame-debounced' );
source_assert( strpos( $js, 'setInterval' ) === false, 'canvas has no animation loop' );

echo "PASS: map gallery source checks\n";
