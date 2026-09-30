<?php
/**
 * Campaign dates accept an optional time and stay compatible with date-only values.
 * Run: php tests/test-cw-campaign-dates.php
 */

define( 'ABSPATH', __DIR__ );
define( 'DAY_IN_SECONDS', 86400 );
date_default_timezone_set( 'UTC' );

function wp_timezone() { return new DateTimeZone( 'Asia/Kuala_Lumpur' ); }
function wp_date( $format, $ts = null ) {
    return ( new DateTimeImmutable( '@' . ( $ts ?? time() ) ) )->setTimezone( wp_timezone() )->format( $format );
}
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function wp_unslash( $s ) { return is_string( $s ) ? stripslashes( $s ) : $s; }

require dirname( __DIR__ ) . '/includes/class-cw-campaign-dates.php';

$fail = 0;
$eq   = function ( $label, $got, $want ) use ( &$fail ) {
    if ( $got !== $want ) {
        $fail++;
        fwrite( STDERR, "FAIL: $label — got " . var_export( $got, true ) . ', want ' . var_export( $want, true ) . "\n" );
    }
};
$myt = function ( $s ) { return ( new DateTimeImmutable( $s, wp_timezone() ) )->getTimestamp(); };

$eq( 'has_time date-only', CW_Campaign_Dates::has_time( '2026-10-31' ), false );
$eq( 'has_time datetime', CW_Campaign_Dates::has_time( '2026-10-31 17:00' ), true );
$eq( 'time_part', CW_Campaign_Dates::time_part( '2026-10-31T09:05' ), '09:05' );
$eq( 'date_part', CW_Campaign_Dates::date_part( '2026-10-31 17:00' ), '2026-10-31' );

$eq( 'ts date-only start', CW_Campaign_Dates::timestamp( '2026-10-31' ), $myt( '2026-10-31 00:00:00' ) );
$eq( 'ts date-only end', CW_Campaign_Dates::timestamp( '2026-10-31', true ), $myt( '2026-10-31 23:59:59' ) );
$eq( 'ts datetime ignores end flag', CW_Campaign_Dates::timestamp( '2026-10-31 17:00', true ), $myt( '2026-10-31 17:00:00' ) );
$eq( 'ts empty', CW_Campaign_Dates::timestamp( '' ), false );

$eq( 'normalize date only', CW_Campaign_Dates::normalize( '2026-10-31', '' ), '2026-10-31' );
$eq( 'normalize date + time', CW_Campaign_Dates::normalize( '2026-10-31', '17:30' ), '2026-10-31 17:30' );
$eq( 'normalize datetime-local', CW_Campaign_Dates::normalize( '2026-10-31T08:15', '' ), '2026-10-31 08:15' );
$eq( 'normalize bad time dropped', CW_Campaign_Dates::normalize( '2026-10-31', '25:00' ), '2026-10-31' );
$eq( 'normalize empty date', CW_Campaign_Dates::normalize( '', '10:00' ), '' );
$eq( 'normalize invalid date', CW_Campaign_Dates::normalize( '2026-02-31', '10:00' ), '' );

$eq( 'end_of_day date-only', CW_Campaign_Dates::end_of_day( '2026-10-31' ), '2026-10-31 23:59' );
$eq( 'end_of_day keeps time', CW_Campaign_Dates::end_of_day( '2026-10-31 17:30' ), '2026-10-31 17:30' );
$eq( 'end_of_day empty', CW_Campaign_Dates::end_of_day( '' ), '' );
$eq( 'end_of_day unknown format untouched', CW_Campaign_Dates::end_of_day( '31/10/2026' ), '31/10/2026' );

$eq( 'format date-only', CW_Campaign_Dates::format( '2026-10-31' ), '31 Oct 2026' );
$eq( 'format datetime', CW_Campaign_Dates::format( '2026-10-31 17:30' ), '31 Oct 2026, 5:30 PM' );
$eq( 'format custom', CW_Campaign_Dates::format( '2026-10-31 09:00', 'd M Y', 'g:i A', ' · ' ), '31 Oct 2026 · 9:00 AM' );
$eq( 'format_time none', CW_Campaign_Dates::format_time( '2026-10-31' ), '' );
$eq( 'format_time set', CW_Campaign_Dates::format_time( '2026-10-31 17:30' ), '5:30 PM' );
$eq( 'format empty', CW_Campaign_Dates::format( '' ), '' );

$eq( 'schema_date date-only', CW_Campaign_Dates::schema_date( '2026-11-07' ), '2026-11-07' );
$eq( 'schema_date midnight is date-only', CW_Campaign_Dates::schema_date( '2026-06-21 00:00' ), '2026-06-21' );
$eq( 'schema_date with time', CW_Campaign_Dates::schema_date( '2026-10-31 23:59' ), '2026-10-31T23:59:00+08:00' );
$eq( 'schema_date empty', CW_Campaign_Dates::schema_date( '' ), '' );

$now = time();
$today    = wp_date( 'Y-m-d', $now );
$tomorrow = wp_date( 'Y-m-d', $now + DAY_IN_SECONDS );
$yday     = wp_date( 'Y-m-d', $now - DAY_IN_SECONDS );
$eq( 'date-only deadline today still open', CW_Campaign_Dates::is_past( $today, true ), false );
$eq( 'date-only deadline yesterday closed', CW_Campaign_Dates::is_past( $yday, true ), true );
$eq( 'deadline one minute ago closed', CW_Campaign_Dates::is_past( wp_date( 'Y-m-d H:i', $now - 120 ), true ), true );
$eq( 'start tomorrow is future', CW_Campaign_Dates::is_future( $tomorrow ), true );
$eq( 'start today date-only not future', CW_Campaign_Dates::is_future( $today ), false );
$eq( 'days_until today', CW_Campaign_Dates::days_until( $today . ' 23:59' ), 0 );
$eq( 'days_until tomorrow', CW_Campaign_Dates::days_until( $tomorrow ), 1 );
$eq( 'days_until yesterday', CW_Campaign_Dates::days_until( $yday ), -1 );

if ( $fail ) {
    exit( 1 );
}
echo "PASS: campaign dates\n";
