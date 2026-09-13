<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rate limits, claim sessions, upload validation, hardening, lockout, WAF, page cache helpers.
 */
class CW_Security {

    const RATE_PIC_UPLOAD   = 'cw_rate_pic_';
    const RATE_REGISTRATION = 'cw_rate_reg_';
    const CLAIM_TRANSIENT   = 'cw_claim_sess_';

    const LOGIN_FAIL_PREFIX = 'cw_login_fail_';
    const LOGIN_LOCK_PREFIX = 'cw_login_lock_';
    const LOGIN_MAX_FAILS   = 8;
    const LOGIN_FAIL_WINDOW = 900;  // 15 min
    const LOGIN_LOCK_TTL    = 900;  // 15 min lock (friendlier for real users)

    public static function register_hooks() {
        // Auth / enumeration hardening.
        add_filter( 'xmlrpc_enabled', '__return_false' );
        add_filter( 'wp_headers', [ __CLASS__, 'security_headers' ] );
        add_filter( 'rest_endpoints', [ __CLASS__, 'lock_rest_users' ] );
        add_filter( 'rest_prepare_user', [ __CLASS__, 'strip_user_rest_fields' ], 10, 3 );
        add_action( 'template_redirect', [ __CLASS__, 'block_author_enumeration' ] );
        add_filter( 'the_generator', '__return_empty_string' );
        remove_action( 'wp_head', 'wp_generator' );

        // Login lockout (wp-login, WooCommerce, wp_signon).
        add_filter( 'authenticate', [ __CLASS__, 'block_locked_login' ], 5, 3 );
        add_action( 'wp_login_failed', [ __CLASS__, 'record_login_failure' ], 5 );
        add_action( 'wp_login', [ __CLASS__, 'clear_login_failures' ], 5 );
        add_filter( 'woocommerce_process_login_errors', [ __CLASS__, 'woocommerce_login_lockout' ], 5, 3 );

        // App-level WAF — run immediately (register_hooks already runs during plugin load).
        self::waf_inspect_request();
        add_action( 'init', [ __CLASS__, 'waf_inspect_request' ], 0 );

        // Performance: strip unused front-end weight for guests on non-commerce pages.
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'trim_front_assets' ], 999 );
        add_action( 'wp_print_scripts', [ __CLASS__, 'trim_front_assets' ], 999 );
        add_action( 'wp_print_styles', [ __CLASS__, 'trim_front_assets' ], 999 );
        add_action( 'wp_default_scripts', [ __CLASS__, 'remove_jquery_migrate' ] );

        // Keep guest marketing pages cacheable (no empty WC session cookies).
        add_filter( 'woocommerce_set_cart_cookies', [ __CLASS__, 'maybe_skip_cart_cookies' ] );
        add_action( 'template_redirect', [ __CLASS__, 'maybe_disable_wc_session_cookie' ], 0 );

        // Lightweight guest HTML page cache.
        if ( class_exists( 'CW_Page_Cache' ) ) {
            CW_Page_Cache::register_hooks();
        }
    }

    public static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        // Prefer edge IP when behind a trusted proxy header set by the host.
        if ( ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ) {
            $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip    = trim( $parts[0] );
        }
        return sanitize_text_field( $ip );
    }

    public static function ip_hash() {
        return hash( 'sha256', self::client_ip() . wp_salt( 'auth' ) );
    }

    /**
     * @return true|WP_Error
     */
    public static function rate_limit( $key, $max_attempts = 30, $window_seconds = 3600 ) {
        $bucket = $key . md5( self::client_ip() );
        $data   = get_transient( $bucket );
        if ( ! is_array( $data ) ) {
            $data = [ 'count' => 0, 'start' => time() ];
        }
        if ( time() - (int) $data['start'] > $window_seconds ) {
            $data = [ 'count' => 0, 'start' => time() ];
        }
        $data['count']++;
        set_transient( $bucket, $data, $window_seconds );
        if ( $data['count'] > $max_attempts ) {
            return new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again later.', 'creativewings-core' ) );
        }
        return true;
    }

    /* ── Login lockout ─────────────────────────────────────────────── */

    private static function login_fail_key() {
        return self::LOGIN_FAIL_PREFIX . self::ip_hash();
    }

    private static function login_lock_key() {
        return self::LOGIN_LOCK_PREFIX . self::ip_hash();
    }

    public static function is_login_locked() {
        return (bool) get_transient( self::login_lock_key() );
    }

    public static function block_locked_login( $user, $username, $password ) {
        if ( self::is_login_locked() ) {
            return new WP_Error(
                'cw_login_locked',
                __( 'Too many failed login attempts. Please wait 15 minutes and try again.', 'creativewings-core' )
            );
        }
        return $user;
    }

    public static function record_login_failure( $username = '' ) {
        if ( self::is_login_locked() ) {
            return;
        }
        $key  = self::login_fail_key();
        $data = get_transient( $key );
        if ( ! is_array( $data ) ) {
            $data = [ 'count' => 0 ];
        }
        $data['count'] = (int) $data['count'] + 1;
        $data['last']  = sanitize_user( (string) $username );
        set_transient( $key, $data, self::LOGIN_FAIL_WINDOW );

        if ( $data['count'] >= self::LOGIN_MAX_FAILS ) {
            set_transient( self::login_lock_key(), 1, self::LOGIN_LOCK_TTL );
            delete_transient( $key );
        }
    }

    public static function clear_login_failures() {
        delete_transient( self::login_fail_key() );
        delete_transient( self::login_lock_key() );
    }

    public static function woocommerce_login_lockout( $validation_error, $username, $password ) {
        if ( self::is_login_locked() ) {
            return new WP_Error(
                'cw_login_locked',
                __( 'Too many failed login attempts. Please wait 15 minutes and try again.', 'creativewings-core' )
            );
        }
        return $validation_error;
    }

    /* ── App-level WAF ─────────────────────────────────────────────── */

    public static function waf_inspect_request() {
        if ( defined( 'WP_CLI' ) && WP_CLI ) {
            return;
        }
        if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
            return;
        }
        // Never interrupt logged-in customers / creators / organizers.
        if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
            return;
        }

        $uri   = (string) ( $_SERVER['REQUEST_URI'] ?? '' );
        $query = (string) ( $_SERVER['QUERY_STRING'] ?? '' );
        $blob  = strtolower( rawurldecode( $uri . '?' . $query ) );

        // Skip assets + critical commerce/AJAX endpoints (visitor join/checkout).
        $path = strtolower( (string) ( parse_url( $uri, PHP_URL_PATH ) ?: '' ) );
        if ( preg_match( '#\.(css|js|jpg|jpeg|png|gif|webp|svg|woff2?|ttf|ico|map)(\?|$)#', $uri ) ) {
            return;
        }
        if ( false !== strpos( $path, '/wp-admin/admin-ajax.php' )
            || false !== strpos( $path, '/wp-json/wc/' )
            || false !== strpos( $path, '/checkout' )
            || false !== strpos( $path, '/cart' )
            || false !== strpos( $path, '/my-account' )
        ) {
            return;
        }

        $patterns = [
            'union select',
            'information_schema',
            'sleep(',
            'benchmark(',
            'into outfile',
            'load_file(',
            'base64_decode(',
            '<script',
            'wp-config.php',
            '/etc/passwd',
            'php://input',
            'expect://',
            'file://',
        ];
        // Path traversal only when clearly probing upward (avoid false positives).
        if ( false !== strpos( $blob, '../' ) || false !== strpos( $blob, '..\\' ) ) {
            if ( false !== strpos( $blob, 'wp-config' )
                || false !== strpos( $blob, 'etc/passwd' )
                || false !== strpos( $blob, '.env' )
            ) {
                self::waf_deny( 'traversal' );
            }
        }

        foreach ( $patterns as $needle ) {
            if ( false !== strpos( $blob, $needle ) ) {
                self::waf_deny( 'pattern' );
            }
        }

        $probe_paths = [
            '/xmlrpc.php',
            '/wp-config.php',
            '/.env',
            '/vendor/phpunit',
            '/wp-content/debug.log',
            '/readme.html',
            '/license.txt',
        ];
        foreach ( $probe_paths as $probe ) {
            if ( $path === $probe || str_ends_with( $path, $probe ) ) {
                self::waf_deny( 'probe' );
            }
        }
    }

    private static function waf_deny( $reason ) {
        status_header( 403 );
        header( 'Content-Type: text/plain; charset=UTF-8' );
        header( 'X-CW-WAF: blocked' );
        echo 'Forbidden';
        exit;
    }

    public static function set_claim_session( $user_id, $staged_id, $campaign_id ) {
        $token = wp_generate_password( 32, false, false );
        set_transient(
            self::CLAIM_TRANSIENT . (int) $user_id,
            [
                'token'       => $token,
                'staged_id'   => (int) $staged_id,
                'campaign_id' => (int) $campaign_id,
            ],
            15 * MINUTE_IN_SECONDS
        );
        return $token;
    }

    public static function get_claim_session( $user_id ) {
        $data = get_transient( self::CLAIM_TRANSIENT . (int) $user_id );
        return is_array( $data ) ? $data : null;
    }

    public static function verify_claim_token( $user_id, $token ) {
        $data = self::get_claim_session( $user_id );
        return $data && ! empty( $data['token'] ) && hash_equals( $data['token'], (string) $token );
    }

    public static function clear_claim_session( $user_id ) {
        delete_transient( self::CLAIM_TRANSIENT . (int) $user_id );
    }

    /**
     * Security response headers for front-end + login.
     *
     * @param array $headers
     * @return array
     */
    public static function security_headers( $headers ) {
        if ( is_admin() ) {
            return $headers;
        }
        $headers['X-Content-Type-Options'] = 'nosniff';
        $headers['X-Frame-Options']        = 'SAMEORIGIN';
        $headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
        $headers['Permissions-Policy']     = 'geolocation=(), microphone=(), camera=()';
        if ( is_ssl() ) {
            $headers['Strict-Transport-Security'] = 'max-age=15552000; includeSubDomains';
        }
        return $headers;
    }

    /**
     * Hide /wp/v2/users from anonymous callers (stops admin username harvest).
     *
     * @param array $endpoints
     * @return array
     */
    public static function lock_rest_users( $endpoints ) {
        if ( is_user_logged_in() ) {
            return $endpoints;
        }
        foreach ( array_keys( $endpoints ) as $route ) {
            if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
                unset( $endpoints[ $route ] );
            }
        }
        return $endpoints;
    }

    /**
     * Extra belt: never expose elevated flags to REST consumers who shouldn't see them.
     *
     * @param WP_REST_Response $response
     * @param WP_User          $user
     * @param WP_REST_Request  $request
     * @return WP_REST_Response
     */
    public static function strip_user_rest_fields( $response, $user, $request ) {
        if ( current_user_can( 'list_users' ) ) {
            return $response;
        }
        $data = $response->get_data();
        if ( ! is_array( $data ) ) {
            return $response;
        }
        unset( $data['email'], $data['roles'], $data['capabilities'], $data['extra_capabilities'], $data['is_super_admin'] );
        $response->set_data( $data );
        return $response;
    }

    /**
     * Block ?author=N redirects that leak usernames.
     */
    public static function block_author_enumeration() {
        if ( is_admin() || is_user_logged_in() ) {
            return;
        }
        if ( isset( $_GET['author'] ) && (string) $_GET['author'] !== '' ) {
            wp_safe_redirect( home_url( '/' ), 301 );
            exit;
        }
    }

    /**
     * Drop jQuery Migrate on the public front (legacy noise).
     *
     * @param WP_Scripts $scripts
     */
    public static function remove_jquery_migrate( $scripts ) {
        if ( is_admin() || ! isset( $scripts->registered['jquery'] ) ) {
            return;
        }
        $deps = $scripts->registered['jquery']->deps;
        $scripts->registered['jquery']->deps = array_values( array_diff( $deps, [ 'jquery-migrate' ] ) );
    }

    /**
     * Whether the current front request is a guest marketing page (cacheable).
     */
    public static function is_guest_marketing_request() {
        if ( is_admin() || is_user_logged_in() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
            return false;
        }
        if ( 'GET' !== strtoupper( (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) ) {
            return false;
        }
        if ( ! function_exists( 'is_woocommerce' ) ) {
            return true;
        }
        if ( is_cart() || is_checkout() || is_account_page() ) {
            return false;
        }
        if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() ) {
            return false;
        }
        return true;
    }

    public static function maybe_skip_cart_cookies( $set ) {
        if ( self::is_guest_marketing_request() ) {
            if ( function_exists( 'WC' ) && WC()->cart && WC()->cart->get_cart_contents_count() > 0 ) {
                return $set;
            }
            return false;
        }
        return $set;
    }

    public static function maybe_disable_wc_session_cookie() {
        if ( ! self::is_guest_marketing_request() ) {
            return;
        }
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        if ( WC()->cart && WC()->cart->get_cart_contents_count() > 0 ) {
            return;
        }
        // Empty guest session on marketing pages — avoids uncacheable Set-Cookie.
        if ( method_exists( WC()->session, 'set_customer_session_cookie' ) ) {
            WC()->session->set_customer_session_cookie( false );
        }
    }

    /**
     * Dequeue WooCommerce cart/gallery scripts on pages that don't need them.
     */
    public static function trim_front_assets() {
        if ( is_admin() || is_user_logged_in() ) {
            return;
        }
        if ( ! function_exists( 'is_woocommerce' ) ) {
            return;
        }

        $needs_wc = is_woocommerce()
            || is_cart()
            || is_checkout()
            || is_account_page()
            || ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url() );

        if ( $needs_wc ) {
            return;
        }

        wp_dequeue_script( 'wc-cart-fragments' );
        wp_dequeue_script( 'woocommerce' );
        wp_dequeue_script( 'wc-add-to-cart' );
        wp_dequeue_script( 'wc-add-to-cart-variation' );
        wp_dequeue_script( 'zoom' );
        wp_dequeue_script( 'flexslider' );
        wp_dequeue_script( 'photoswipe' );
        wp_dequeue_script( 'photoswipe-ui-default' );
        wp_dequeue_style( 'photoswipe' );
        wp_dequeue_style( 'photoswipe-default-skin' );
        wp_dequeue_style( 'woocommerce-general' );
        wp_dequeue_style( 'woocommerce-layout' );
        wp_dequeue_style( 'woocommerce-smallscreen' );
        wp_dequeue_script( 'sourcebuster-js' );
        wp_dequeue_script( 'wc-order-attribution' );
    }

    /**
     * @return int|WP_Error Attachment ID.
     */
    public static function handle_image_upload( $file_key = 'artwork', $max_bytes = 5242880 ) {
        if ( empty( $_FILES[ $file_key ]['name'] ) ) {
            return new WP_Error( 'no_file', __( 'No file uploaded.', 'creativewings-core' ) );
        }

        $file = $_FILES[ $file_key ];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
            return new WP_Error( 'file_large', __( 'Image must be 5MB or smaller.', 'creativewings-core' ) );
        }

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $allowed = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
        if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), $allowed, true ) ) {
            return new WP_Error( 'file_type', __( 'Only JPG, PNG, GIF, or WebP images are allowed.', 'creativewings-core' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $aid = media_handle_upload( $file_key, 0 );
        if ( ! is_wp_error( $aid ) && class_exists( 'CW_Image_Optimizer' ) ) {
            CW_Image_Optimizer::optimize_attachment( (int) $aid, 'attachment' );
        }
        return is_wp_error( $aid ) ? $aid : (int) $aid;
    }

    /**
     * @return int|WP_Error Attachment ID.
     */
    public static function handle_field_upload( $file_key, $field_type = 'media', $max_bytes = 5242880 ) {
        $type = strtolower( (string) $field_type );
        if ( 'media' === $type ) {
            return self::handle_image_upload( $file_key, $max_bytes );
        }
        if ( empty( $_FILES[ $file_key ]['name'] ) ) {
            return new WP_Error( 'no_file', __( 'No file uploaded.', 'creativewings-core' ) );
        }
        $file = $_FILES[ $file_key ];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
            return new WP_Error( 'file_large', __( 'File must be 5MB or smaller.', 'creativewings-core' ) );
        }
        $check   = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $allowed = [ 'pdf', 'doc', 'docx', 'zip', 'jpg', 'jpeg', 'png' ];
        if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), $allowed, true ) ) {
            return new WP_Error( 'file_type', __( 'Invalid file type for this field.', 'creativewings-core' ) );
        }
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        $aid = media_handle_upload( $file_key, 0 );
        if ( ! is_wp_error( $aid ) && class_exists( 'CW_Image_Optimizer' ) ) {
            $ext_lc = strtolower( pathinfo( (string) get_attached_file( (int) $aid ), PATHINFO_EXTENSION ) );
            if ( in_array( $ext_lc, [ 'jpg', 'jpeg', 'png' ], true ) ) {
                CW_Image_Optimizer::optimize_attachment( (int) $aid, 'attachment' );
            }
        }
        return is_wp_error( $aid ) ? $aid : (int) $aid;
    }
}
