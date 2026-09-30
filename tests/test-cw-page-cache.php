<?php
/**
 * Campaign pages are never cached across an open/close moment.
 * Run: php tests/test-cw-page-cache.php
 */

define( 'ABSPATH', __DIR__ );
date_default_timezone_set( 'UTC' );

function wp_timezone() { return new DateTimeZone( 'Asia/Kuala_Lumpur' ); }
function wp_date( $format, $ts = null ) {
    return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( wp_timezone() )->format( $format );
}

require dirname( __DIR__ ) . '/includes/class-cw-campaign-dates.php';
require dirname( __DIR__ ) . '/includes/class-cw-page-cache.php';

$fail = 0;
$eq   = function ( $label, $got, $want ) use ( &$fail ) {
    if ( $got !== $want ) {
        $fail++;
        fwrite( STDERR, "FAIL: $label — got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n" );
    }
};
$now = ( new DateTimeImmutable( '2026-10-31 23:30', wp_timezone() ) )->getTimestamp();

$eq( 'deadline in 29 min blocks caching', CW_Page_Cache::crosses_milestone( [ 'start' => '2026-09-01', 'deadline' => '2026-10-31 23:59' ], $now, 3600 ), true );
$eq( 'date-only deadline tonight blocks caching', CW_Page_Cache::crosses_milestone( [ 'deadline' => '2026-10-31' ], $now, 3600 ), true );
$eq( 'deadline tomorrow night is fine', CW_Page_Cache::crosses_milestone( [ 'deadline' => '2026-11-01 23:59' ], $now, 3600 ), false );
$eq( 'past milestones are fine', CW_Page_Cache::crosses_milestone( [ 'start' => '2026-09-01', 'deadline' => '2026-10-30 23:59' ], $now, 3600 ), false );
$eq( 'start in 20 min blocks caching', CW_Page_Cache::crosses_milestone( [ 'start' => '2026-10-31 23:50' ], $now, 3600 ), true );
$eq( 'empty values are fine', CW_Page_Cache::crosses_milestone( [ 'start' => '', 'deadline' => '' ], $now, 3600 ), false );

if ( $fail ) {
    exit( 1 );
}
echo "PASS: page cache milestones\n";
