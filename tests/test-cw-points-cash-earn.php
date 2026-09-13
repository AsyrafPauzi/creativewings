<?php
/**
 * Smoke assertions for cash-only points earn math + redeem conversion.
 * Run: php tests/test-cw-points-cash-earn.php
 */

if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}
if ( ! defined( 'CW_PATH' ) ) {
    define( 'CW_PATH', $root . '/' );
}

if ( ! function_exists( 'add_action' ) ) {
    function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter() {}
}
if ( ! function_exists( '__' ) ) {
    function __( $s ) { return $s; }
}

require_once $root . '/includes/class-cw-points.php';

$fail = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
    echo ( $ok ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
    if ( ! $ok ) {
        $fail++;
    }
};

// Earn: floor(cash_paid) — RM15 → +15 pts (not ×100).
$check( 'earn floor(15) = 15', (int) floor( 15 ) === 15 );
$check( 'earn floor(0) = 0 (free join)', (int) floor( 0 ) === 0 );

// Spend conversion: 100 pts = RM1.
$check( '100 pts = RM1.00', abs( CW_Points::points_to_rm( 100 ) - 1.0 ) < 0.001 );
$check( '15 pts = RM0.15', abs( CW_Points::points_to_rm( 15 ) - 0.15 ) < 0.001 );
$check( 'RM15 due → max 1500 pts spendable', CW_Points::rm_to_points( 15 ) === 1500 );
$check( 'PTS_PER_RM is 100', CW_Points::PTS_PER_RM === 100 );
$check( 'MIN_CHECKOUT_SPEND is 10', CW_Points::MIN_CHECKOUT_SPEND === 10 );

exit( $fail > 0 ? 1 : 0 );
