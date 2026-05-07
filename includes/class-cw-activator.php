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

        // 6. Flush Rules
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
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

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        dbDelta( $sql );
    }
}

add_action('registration_errors', function($errors) {
    if (!isset($_POST['creator_reg_nonce'])) {
        wp_die('Direct registration is disabled. Please use the official sign-up page.');
    }
    return $errors;
});