<?php
/**
 * Smoke assertions for sponsor tier placeholders.
 * Run: php tests/test-cw-sponsor-placeholders.php
 */

if ( php_sapi_name() !== 'cli' ) {
    exit( 1 );
}

$root = dirname( __DIR__ );
if ( ! defined( 'ABSPATH' ) ) {
    define( 'ABSPATH', $root . '/' );
}

if ( ! function_exists( '__' ) ) {
    function __( $s, $d = null ) { // phpcs:ignore
        return $s;
    }
}
if ( ! function_exists( 'esc_attr' ) ) {
    function esc_attr( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'esc_html' ) ) {
    function esc_html( $s ) {
        return htmlspecialchars( (string) $s, ENT_QUOTES, 'UTF-8' );
    }
}
if ( ! function_exists( 'sanitize_key' ) ) {
    function sanitize_key( $s ) {
        return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $s ) );
    }
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
    function sanitize_text_field( $s ) {
        return trim( strip_tags( (string) $s ) );
    }
}
if ( ! function_exists( 'esc_url_raw' ) ) {
    function esc_url_raw( $s ) {
        return filter_var( (string) $s, FILTER_SANITIZE_URL ) ?: '';
    }
}

require_once $root . '/includes/class-cw-campaign-showcase.php';

$fail  = 0;
$check = function ( $label, $ok ) use ( &$fail ) {
    echo ( $ok ? 'OK  ' : 'FAIL' ) . ' ' . $label . PHP_EOL;
    if ( ! $ok ) {
        $fail++;
    }
};

$p = CW_Campaign_Showcase::placeholder_partner( 'champion', 0 );
$check( 'placeholder has zero attachment', ( $p['attachment_id'] ?? 1 ) === 0 );
$check( 'placeholder is_placeholder flag', ! empty( $p['is_placeholder'] ) );
$check( 'placeholder tier champion', ( $p['tier'] ?? '' ) === 'champion' );

$html = CW_Campaign_Showcase::render_placeholder_html( 'hero' );
$check( 'placeholder html class', str_contains( $html, 'cw-partner-placeholder' ) );
$check( 'placeholder html hero modifier', str_contains( $html, 'cw-partner-placeholder--hero' ) );

exit( $fail > 0 ? 1 : 0 );
