<?php
/**
 * Lightweight guest HTML page cache (marketing pages only).
 *
 * Not a full CDN — stores rendered HTML for anonymous GET requests without
 * cart/checkout/account cookies. Busts on post save / CW homepage cache group.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Page_Cache {

    const TTL     = 300; // 5 minutes
    const VERSION = '1';

    public static function register_hooks() {
        if ( defined( 'CW_DISABLE_PAGE_CACHE' ) && CW_DISABLE_PAGE_CACHE ) {
            return;
        }

        add_action( 'template_redirect', [ __CLASS__, 'maybe_serve' ], 0 );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_start_buffer' ], 1 );

        add_action( 'save_post', [ __CLASS__, 'bust_all' ] );
        add_action( 'deleted_post', [ __CLASS__, 'bust_all' ] );
        add_action( 'switch_theme', [ __CLASS__, 'bust_all' ] );
        add_action( 'activated_plugin', [ __CLASS__, 'bust_all' ] );
        add_action( 'deactivated_plugin', [ __CLASS__, 'bust_all' ] );
    }

    private static function cache_dir() {
        $dir = WP_CONTENT_DIR . '/cache/cw-page';
        if ( ! is_dir( $dir ) ) {
            wp_mkdir_p( $dir );
        }
        $deny = $dir . '/.htaccess';
        if ( is_dir( $dir ) && ! file_exists( $deny ) ) {
            @file_put_contents( $deny, "Deny from all\n" );
        }
        $index = $dir . '/index.php';
        if ( is_dir( $dir ) && ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
        return $dir;
    }

    private static function request_key() {
        $host = (string) ( $_SERVER['HTTP_HOST'] ?? 'localhost' );
        $uri  = (string) ( $_SERVER['REQUEST_URI'] ?? '/' );
        // Ignore common marketing params so UTM variants share a cache entry.
        $parts = wp_parse_url( $uri );
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
        $norm = $path;
        if ( $query ) {
            ksort( $query );
            $norm .= '?' . http_build_query( $query );
        }
        return 'cwpc_' . self::VERSION . '_' . md5( strtolower( $host . $norm ) );
    }

    private static function file_path( $key ) {
        return trailingslashit( self::cache_dir() ) . $key . '.html';
    }

    public static function is_cacheable() {
        if ( ! empty( $_GET['elementor-preview'] ) || ! empty( $_GET['preview'] ) || ! empty( $_GET['customize_changeset_uuid'] ) ) {
            return false;
        }
        if ( is_user_logged_in() || is_admin() || wp_doing_ajax() || is_preview() || is_feed() || is_search() || is_404() ) {
            return false;
        }
        if ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) {
            return false;
        }
        if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
            return false;
        }
        // Any auth / session cookie → skip.
        foreach ( array_keys( $_COOKIE ) as $name ) {
            if ( str_starts_with( $name, 'wordpress_logged_in_' )
                || str_starts_with( $name, 'wp-postpass_' )
                || 'woocommerce_items_in_cart' === $name
                || 'woocommerce_cart_hash' === $name
                || str_starts_with( $name, 'wp_woocommerce_session_' )
            ) {
                return false;
            }
        }
        if ( function_exists( 'is_cart' ) && ( is_cart() || is_checkout() || is_account_page() ) ) {
            return false;
        }
        // Cache home + public pages; skip products (stock/price can change) and My Account.
        if ( is_front_page() || is_home() ) {
            return true;
        }
        if ( is_page() && ! is_page( [ 'cart', 'checkout', 'my-account', 'login', 'registration', 'get-started' ] ) ) {
            return true;
        }
        return false;
    }

    public static function maybe_serve() {
        if ( ! self::is_cacheable() ) {
            return;
        }
        $file = self::file_path( self::request_key() );
        if ( ! is_readable( $file ) ) {
            return;
        }
        $mtime = filemtime( $file );
        if ( ! $mtime || ( time() - $mtime ) > self::TTL ) {
            @unlink( $file );
            return;
        }
        $html = file_get_contents( $file );
        if ( ! is_string( $html ) || $html === '' ) {
            return;
        }

        header( 'Content-Type: text/html; charset=UTF-8' );
        header( 'X-CW-Page-Cache: HIT' );
        header( 'Cache-Control: public, max-age=60, s-maxage=' . self::TTL );
        header( 'Expires: ' . gmdate( 'D, d M Y H:i:s', time() + 60 ) . ' GMT' );
        echo $html;
        exit;
    }

    public static function maybe_start_buffer() {
        if ( ! self::is_cacheable() ) {
            return;
        }
        $file = self::file_path( self::request_key() );
        if ( is_readable( $file ) && filemtime( $file ) && ( time() - filemtime( $file ) ) <= self::TTL ) {
            return; // maybe_serve should have exited; avoid double-buffer.
        }
        ob_start( [ __CLASS__, 'store_buffer' ] );
        header( 'X-CW-Page-Cache: MISS' );
    }

    public static function store_buffer( $html ) {
        if ( ! is_string( $html ) || strlen( $html ) < 500 ) {
            return $html;
        }
        $code = http_response_code();
        if ( $code && (int) $code >= 400 ) {
            return $html;
        }
        if ( ! self::is_cacheable() ) {
            return $html;
        }
        $file = self::file_path( self::request_key() );
        $tmp  = $file . '.' . getmypid() . '.tmp';
        if ( false !== file_put_contents( $tmp, $html, LOCK_EX ) ) {
            @rename( $tmp, $file );
        }
        return $html;
    }

    public static function bust_all() {
        $dir = self::cache_dir();
        if ( ! is_dir( $dir ) ) {
            return;
        }
        foreach ( glob( $dir . '/cwpc_*.html' ) ?: [] as $file ) {
            @unlink( $file );
        }
    }
}
