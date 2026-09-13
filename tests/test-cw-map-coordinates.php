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

$slots = CW_Map_Coordinates::get_all_slots();
cw_assert( count( $slots ) >= 650, 'Malaysia slot data loads near 700 target' );
cw_assert( count( $slots ) <= 850, 'slot grid stays uncrowded' );

foreach ( array_slice( $slots, 0, 50 ) as $point ) {
    cw_assert( $point[0] >= 0 && $point[0] <= 100, 'slot x stays within map' );
    cw_assert( $point[1] >= 0 && $point[1] <= 100, 'slot y stays within map' );
}

cw_assert(
    CW_Map_Coordinates::total_slots_for_display( 10000, 12 ) === CW_Map_Coordinates::max_slots(),
    'KPI target does not inflate mosaic slots'
);
cw_assert(
    CW_Map_Coordinates::total_slots_for_display( 0, 0 ) === CW_Map_Coordinates::max_slots(),
    'empty campaign still renders the full readable grid'
);
cw_assert(
    CW_Map_Coordinates::total_slots_for_display( 0, 42 ) === CW_Map_Coordinates::max_slots(),
    'filled count does not shrink the land grid'
);

$display = CW_Map_Coordinates::slots_for_display( 120 );
cw_assert( count( $display ) === 120, 'display slots trim to requested count' );

$first  = CW_Map_Coordinates::point_for_entry( 12345 );
$repeat = CW_Map_Coordinates::point_for_entry( 12345 );
cw_assert( $first === $repeat, 'deprecated point_for_entry stays stable' );
cw_assert( $first['country'] === 'Malaysia', 'deprecated country is Malaysia' );

$assign_a = CW_Map_Coordinates::random_slot_assignments( [ 10, 20, 30 ], 100, 42 );
$assign_b = CW_Map_Coordinates::random_slot_assignments( [ 10, 20, 30 ], 100, 42 );
cw_assert( $assign_a === $assign_b, 'random slot assignment is stable per campaign seed' );
cw_assert( count( array_unique( array_values( $assign_a ) ) ) === 3, 'random slots are unique' );

$points = CW_Map_Coordinates::points_for_entries( range( 1, 12000 ) );
cw_assert( count( $points ) === CW_Map_Coordinates::max_slots(), 'deprecated points_for_entries caps to slots' );

echo "PASS: CW map coordinates\n";
