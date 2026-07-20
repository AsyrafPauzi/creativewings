<?php
/**
 * Fired during plugin activation.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class CW_Activator {

    public static function activate() {
        // 1. Initialize Roles
        self::init_roles();

        // 2. Register Post Types (Crucial for the new architecture)
        self::register_post_types();

        // 3. Create Custom Database Tables
        self::create_tables();

        // 3b. Badge ledger table + default catalog (badges system).
        if ( ! class_exists( 'CW_Badges_CPT' ) && file_exists( CW_PATH . 'includes/badges/class-cw-badges-cpt.php' ) ) {
            require_once CW_PATH . 'includes/badges/class-cw-badges-cpt.php';
        }
        if ( ! class_exists( 'CW_Badges_Installer' ) && file_exists( CW_PATH . 'includes/badges/class-cw-badges-installer.php' ) ) {
            require_once CW_PATH . 'includes/badges/class-cw-badges-installer.php';
        }
        if ( ! class_exists( 'CW_Points' ) && file_exists( CW_PATH . 'includes/class-cw-points.php' ) ) {
            require_once CW_PATH . 'includes/class-cw-points.php';
        }
        if ( class_exists( 'CW_Badges_Installer' ) ) {
            CW_Badges_Installer::maybe_install();
        }
        if ( class_exists( 'CW_Points' ) ) {
            CW_Points::maybe_install();
        }

        // 4. Add Rewrite Endpoints
        add_rewrite_endpoint( 'cw-profile', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-portfolio', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-saved', EP_ROOT | EP_PAGES );
        
        // Dashboard Endpoints
        add_rewrite_endpoint( 'cw-my-competitions', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-my-activities', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-biz-campaigns', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-biz-wallet', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-biz-info', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-activities', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-settings', EP_ROOT | EP_PAGES );
        add_rewrite_endpoint( 'cw-upgrade', EP_ROOT | EP_PAGES );

        // 5. Public Profile Rewrite Rule
        add_rewrite_rule( '^profile/([^/]+)/?$', 'index.php?cw_profile_id=$matches[1]', 'top' );

        add_rewrite_rule( '^cw-school-upload/([^/]+)/?$', 'index.php?cw_school_upload_token=$matches[1]', 'top' );
        add_rewrite_tag( '%cw_school_upload_token%', '([^&]+)' );

        // 6. Flush Rules
        update_option( 'cw_needs_rewrite_flush', '1' );
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    /**
     * Run on every load until DB version matches.
     */
    public static function maybe_upgrade() {
        $target = '1.4.0';
        $ver    = get_option( 'cw_db_version', '0' );
        if ( version_compare( $ver, $target, '>=' ) ) {
            // Even when the core DB is current, badge schema/seed may still need work.
            if ( class_exists( 'CW_Badges_Installer' ) ) {
                CW_Badges_Installer::maybe_install();
            }
            if ( class_exists( 'CW_Points' ) ) {
                CW_Points::maybe_install();
            }
            return;
        }
        self::create_tables();
        if ( class_exists( 'CW_Badges_Installer' ) ) {
            CW_Badges_Installer::maybe_install();
        }
        if ( class_exists( 'CW_Points' ) ) {
            CW_Points::maybe_install();
        }
        update_option( 'cw_db_version', $target );
        if ( ! get_option( 'cw_webhook_secret' ) ) {
            update_option( 'cw_webhook_secret', wp_generate_password( 32, false, false ) );
        }
        // Rewrites must run on init (not plugins_loaded) — flag flush for next request.
        update_option( 'cw_needs_rewrite_flush', '1' );
    }

    /**
     * Register pretty permalinks (safe on init only).
     */
    public static function register_rewrite_rules() {
        global $wp_rewrite;
        if ( ! $wp_rewrite instanceof WP_Rewrite ) {
            return;
        }

        add_rewrite_tag( '%cw_school_upload_token%', '([^&]+)' );
        add_rewrite_rule(
            '^cw-school-upload/([^/]+)/?$',
            'index.php?cw_school_upload_token=$matches[1]',
            'top'
        );
        add_rewrite_endpoint( 'cw-link-submission', EP_ROOT | EP_PAGES );
    }

    /**
     * One-time flush after DB upgrade or activation.
     */
    public static function maybe_flush_rewrite_rules() {
        if ( ! get_option( 'cw_needs_rewrite_flush' ) ) {
            return;
        }
        flush_rewrite_rules();
        delete_option( 'cw_needs_rewrite_flush' );
    }

    private static function init_roles() {
        if ( ! class_exists( 'CW_Roles' ) ) {
            if ( file_exists( CW_PATH . 'includes/class-cw-roles.php' ) ) {
                require_once CW_PATH . 'includes/class-cw-roles.php';
            }
        }
        if ( class_exists( 'CW_Roles' ) ) {
            $roles = new CW_Roles();
            $roles->register_roles();
        }
    }
    
    

    /**
     * Register Custom Post Types during activation for rewrite flush
     */
    private static function register_post_types() {
        register_post_type( 'cw_competition_entry', [
            'labels' => [
                'name' => 'Competition Entries',
                'singular_name' => 'Competition Entry'
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'custom-fields', 'author'],
            'menu_icon' => 'dashicons-trophy',
            'rewrite' => false
        ]);

        register_post_type( 'cw_activity_entry', [
            'labels' => [
                'name' => 'Activity Participants',
                'singular_name' => 'Participant'
            ],
            'public' => false,
            'show_ui' => true,
            'supports' => ['title', 'custom-fields', 'author'],
            'menu_icon' => 'dashicons-groups',
            'rewrite' => false
        ]);
    }

    /**
     * Create custom database tables via dbDelta
     */
    private static function create_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'cw_profile_views';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            creator_id bigint(20) NOT NULL,
            view_date date NOT NULL,
            ip_address varchar(45) DEFAULT '' NOT NULL,
            PRIMARY KEY  (id),
            KEY creator_id (creator_id),
            KEY view_date (view_date)
        ) $charset_collate;";

        $staged = $wpdb->prefix . 'cw_staged_submissions';
        $sql2   = "CREATE TABLE $staged (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            submission_code varchar(20) NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            school_code varchar(3) NOT NULL,
            month_code varchar(2) NOT NULL,
            seq_code varchar(6) NOT NULL,
            student_name varchar(255) NOT NULL,
            artwork_attachment_id bigint(20) unsigned DEFAULT 0,
            status varchar(20) NOT NULL DEFAULT 'staged',
            age_bracket_key varchar(64) DEFAULT '',
            claimed_by_user_id bigint(20) unsigned DEFAULT NULL,
            order_id bigint(20) unsigned DEFAULT NULL,
            entry_id bigint(20) unsigned DEFAULT NULL,
            checkout_message text,
            moderation_status varchar(20) NOT NULL DEFAULT 'approved',
            claim_reserved_by bigint(20) unsigned DEFAULT NULL,
            claim_reserved_until datetime DEFAULT NULL,
            field_data longtext,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_campaign (submission_code, campaign_id),
            KEY campaign_id (campaign_id),
            KEY status (status),
            KEY claimed_user_campaign (claimed_by_user_id, campaign_id),
            KEY moderation_status (moderation_status)
        ) $charset_collate;";

        $tokens = $wpdb->prefix . 'cw_upload_tokens';
        $sql3   = "CREATE TABLE $tokens (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            token varchar(64) NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            school_code varchar(3) NOT NULL,
            expires_at datetime DEFAULT NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY token (token),
            KEY campaign_school (campaign_id, school_code)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $audit = $wpdb->prefix . 'cw_audit_log';
        $sql4  = "CREATE TABLE $audit (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            action varchar(64) NOT NULL,
            object_type varchar(32) NOT NULL,
            object_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned DEFAULT 0,
            details longtext,
            ip_hash varchar(64) DEFAULT '',
            created_at datetime NOT NULL,
            PRIMARY KEY (id),
            KEY action (action),
            KEY object_lookup (object_type, object_id)
        ) $charset_collate;";

        $pending = $wpdb->prefix . 'cw_pending_parent_links';
        $sql5    = "CREATE TABLE $pending (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            submission_code varchar(20) NOT NULL,
            campaign_id bigint(20) unsigned NOT NULL,
            school_code varchar(3) NOT NULL,
            month_code varchar(2) NOT NULL,
            seq_code varchar(6) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY submission_campaign (submission_code, campaign_id),
            UNIQUE KEY user_campaign (user_id, campaign_id),
            KEY campaign_id (campaign_id)
        ) $charset_collate;";

        dbDelta( $sql );
        dbDelta( $sql2 );
        dbDelta( $sql3 );
        dbDelta( $sql4 );
        dbDelta( $sql5 );

        if ( false === get_option( 'cw_default_age_brackets', false ) ) {
            update_option( 'cw_default_age_brackets', [
                [ 'label' => 'Primary', 'min_age' => 7, 'max_age' => 12, 'product_cat_slug' => 'primary', 'key' => 'primary' ],
                [ 'label' => 'Secondary', 'min_age' => 13, 'max_age' => 17, 'product_cat_slug' => 'secondary', 'key' => 'secondary' ],
                [ 'label' => 'University', 'min_age' => 18, 'max_age' => 24, 'product_cat_slug' => 'university', 'key' => 'university' ],
                [ 'label' => 'Public', 'min_age' => 0, 'max_age' => 99, 'product_cat_slug' => 'public', 'key' => 'public' ],
            ] );
        }
    }
}

add_action('registration_errors', function($errors) {
    if (!isset($_POST['creator_reg_nonce'])) {
        wp_die('Direct registration is disabled. Please use the official sign-up page.');
    }
    return $errors;
});