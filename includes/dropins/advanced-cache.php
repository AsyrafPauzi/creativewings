<?php
/**
 * Creative Wings early page cache drop-in.
 * Requires define('WP_CACHE', true) in wp-config.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
    return;
}

if ( ! empty( $_GET['elementor-preview'] ) || ! empty( $_GET['preview'] ) ) {
    return;
}

$method = strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) );
if ( 'GET' !== $method ) {
    return;
}

foreach ( array_keys( $_COOKIE ) as $name ) {
    if ( str_starts_with( $name, 'wordpress_logged_in_' )
        || str_starts_with( $name, 'wp-postpass_' )
        || 'woocommerce_items_in_cart' === $name
        || 'woocommerce_cart_hash' === $name
        || str_starts_with( $name, 'wp_woocommerce_session_' )
    ) {
        return;
    }
}

$host  = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
$uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
$parts = parse_url( $uri );
$path  = $parts['path'] ?? '/';
$query = [];
if ( ! empty( $parts['query'] ) ) {
    parse_str( $parts['query'], $query );
    foreach ( array_keys( $query ) as $k ) {
        if ( preg_match( '/^(utm_|fbclid|gclid|mc_)/i', $k ) ) {
            unset( $query[ $k ] );
        }
    }
}
// Only home + simple pages without leftover query (product filters etc.).
if ( $query ) {
    return;
}
if ( ! preg_match( '#^/(?:[a-z0-9\-\_/]*)?$#i', $path ) ) {
    return;
}

$norm = $path;
$key  = 'cwpc_1_' . md5( strtolower( $host . $norm ) );
$file = WP_CONTENT_DIR . '/cache/cw-page/' . $key . '.html';

if ( ! is_readable( $file ) ) {
    return;
}
$mtime = filemtime( $file );
if ( ! $mtime || ( time() - $mtime ) > 300 ) {
    return;
}

$html = file_get_contents( $file );
if ( ! is_string( $html ) || strlen( $html ) < 500 ) {
    return;
}

header( 'Content-Type: text/html; charset=UTF-8' );
header( 'X-CW-Page-Cache: HIT-EARLY' );
header( 'Cache-Control: public, max-age=60, s-maxage=300' );
header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );
echo $html;
exit;
