<?php
/**
 * Smoke: CW_Sponsor_Coupons::is_cw_school_coupon
 * Run: php tests/test-cw-is-cw-school-coupon.php
 */
if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

$GLOBALS['cw_test_coupons'] = [
    'SCHOOL-100' => [ 'id' => 10, 'campaign_id' => 55 ],
    'PROMO10'    => [ 'id' => 11, 'campaign_id' => 0 ],
];

if ( ! function_exists( 'add_action' ) ) {
    function add_action() {}
}
if ( ! function_exists( 'add_filter' ) ) {
    function add_filter() {}
}
if ( ! function_exists( '__' ) ) {
    function __( $s ) {
        return $s;
    }
}
if ( ! function_exists( 'wc_get_coupon_id_by_code' ) ) {
    function wc_get_coupon_id_by_code( $code ) {
        $code = strtoupper( trim( (string) $code ) );
        return isset( $GLOBALS['cw_test_coupons'][ $code ] )
            ? (int) $GLOBALS['cw_test_coupons'][ $code ]['id']
            : 0;
    }
}
if ( ! function_exists( 'get_post_meta' ) ) {
    function get_post_meta( $post_id, $key, $single = false ) {
        foreach ( $GLOBALS['cw_test_coupons'] as $row ) {
            if ( (int) $row['id'] === (int) $post_id && $key === '_cw_campaign_id' ) {
                return (int) $row['campaign_id'];
            }
        }
        return '';
    }
}

require_once $root . '/includes/class-cw-sponsor-coupons.php';

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
    echo ( $ok ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
    if ( ! $ok ) {
        $fail++;
    }
};

$check( 'school coupon true', CW_Sponsor_Coupons::is_cw_school_coupon( 'SCHOOL-100' ) === true );
$check( 'promo without campaign false', CW_Sponsor_Coupons::is_cw_school_coupon( 'PROMO10' ) === false );
$check( 'unknown code false', CW_Sponsor_Coupons::is_cw_school_coupon( 'NOPE' ) === false );
$check( 'empty code false', CW_Sponsor_Coupons::is_cw_school_coupon( '' ) === false );
$check( 'case/space tolerant', CW_Sponsor_Coupons::is_cw_school_coupon( '  school-100  ' ) === true );

exit( $fail > 0 ? 1 : 0 );
