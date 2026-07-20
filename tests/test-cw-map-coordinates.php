<?php

define( 'ABSPATH', __DIR__ . '/' );

$class_file = dirname( __DIR__ ) . '/includes/class-cw-map-coordinates.php';
if ( ! file_exists( $class_file ) ) {
    fwrite( STDERR, "FAIL: CW_Map_Coordinates class file does not exist\n" );
    exit( 1 );
}
require_once $class_file;

function cw_assert( $condition, $message ) {
    if ( ! $condition ) {
        fwrite( STDERR, "FAIL: {$message}\n" );
        exit( 1 );
    }
}

$first  = CW_Map_Coordinates::point_for_entry( 12345 );
$repeat = CW_Map_Coordinates::point_for_entry( 12345 );

cw_assert( $first === $repeat, 'same entry must map to the same point' );
cw_assert( isset( $first['country'], $first['x'], $first['y'] ), 'point shape is complete' );
cw_assert( $first['country'] !== '', 'country is named' );
cw_assert( $first['x'] >= 0 && $first['x'] <= 100, 'x stays within map' );
cw_assert( $first['y'] >= 0 && $first['y'] <= 100, 'y stays within map' );

$countries = [];
for ( $id = 1; $id <= 250; $id++ ) {
    $point = CW_Map_Coordinates::point_for_entry( $id );
    $countries[ $point['country'] ] = true;
}
cw_assert( count( $countries ) >= 30, 'entries distribute across many countries' );

$ids    = range( 1, 12000 );
$points = CW_Map_Coordinates::points_for_entries( $ids );
cw_assert( count( $points ) === 10000, 'default canvas point cap is 10,000' );
cw_assert( count( $points[0] ) === 2, 'compact points contain x and y only' );

$five_thousand = CW_Map_Coordinates::points_for_entries( range( 1, 5000 ) );
cw_assert( count( $five_thousand ) === 5000, 'KPI-sized submission set keeps one point per entry' );

echo "PASS: CW map coordinates\n";
