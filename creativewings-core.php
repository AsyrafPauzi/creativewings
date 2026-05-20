<?php
/**
 * Plugin Name: CreativeWings Core Platform
 * Description: Complete ecosystem: Auth, Onboarding, Campaigns, Tournaments, and Business Logic.
 * Version: 11.0.15
 * Author: CreativeWings Dev
 * Text Domain: creativewings-core
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Define Main Class
if ( ! class_exists( 'CW_Core_Platform' ) ) :

    final class CW_Core_Platform {

        /**
         * The unique instance of the plugin.
         */
        private static $instance;

        /**
         * Module Instances
         */
        public $roles;
        public $post_types; // NEW
        public $auth;
        public $users;
        public $onboarding;
        public $business;
        public $shop;
        public $shortcodes;
        public $ajax;
        public $wallet;
        public $admin;
        public $dashboard_manager;
        public $claim_flow;

        /**
         * Gets the main instance.
         */
        public static function instance() {
            if ( is_null( self::$instance ) ) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        /**
         * Constructor.
         */
        private function __construct() {
            $this->define_constants();
            $this->includes();
            $this->init_hooks();
        }

        /**
         * Define Constants
         */
        private function define_constants() {
            define( 'CW_PATH', plugin_dir_path( __FILE__ ) );
            define( 'CW_URL', plugin_dir_url( __FILE__ ) );
            define( 'CW_VERSION', '11.0.15' );
        }

        /**
         * Include required core files.
         */
        private function includes() {
            require_once CW_PATH . 'includes/class-cw-loader.php';
            CW_Loader::init_core();
        }

        /**
         * Initialize Hooks
         */
        private function init_hooks() {
            register_activation_hook( __FILE__, [ 'CW_Activator', 'activate' ] );
            register_deactivation_hook( __FILE__, [ 'CW_Activator', 'deactivate' ] );

            add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );

            add_action( 'init', [ 'CW_Activator', 'register_rewrite_rules' ], 10 );
            add_action( 'init', [ 'CW_Activator', 'maybe_flush_rewrite_rules' ], 99 );
        }

        /**
         * Instantiate Classes on plugins_loaded
         */
        public function init_plugin() {
            // Load Text Domain
            load_plugin_textdomain( 'creativewings-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

            CW_Activator::maybe_upgrade();

            // WooCommerce Dependency Check
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error"><p><strong>CreativeWings Core</strong> requires WooCommerce to be installed and active.</p></div>';
                });
                return;
            }

            if ( ! CW_Loader::init_woocommerce() ) {
                return;
            }

            $this->roles      = new CW_Roles();
            $this->post_types = new CW_Post_Types();
            $this->users      = new CW_Users();
            $this->onboarding = new CW_Onboarding();
            $this->business   = new CW_Business();
            new CW_Sponsor_Coupons();
            new CW_Moderation();
            new CW_Email();
            new CW_Cron();
            new CW_Export();
            new CW_Campaign_Admin();
            new CW_Certificate();
            new CW_REST_API();
            new CW_School_Upload();
            $this->claim_flow = new CW_Claim_Flow();
            if ( is_admin() ) {
                new CW_Campaign_Import();
            }
            $this->shop       = new CW_Shop();
            new CW_Checkout();
            $this->shortcodes = new CW_Shortcodes();
            $this->ajax       = new CW_Ajax();
            $this->auth       = new CW_Auth();
            $this->wallet     = new CW_Wallet();
            $this->admin      = new CW_Admin();
            $this->dashboard_manager = new CW_Dashboard_Manager();
        }

        /**
         * Enqueue Assets (conditionally loaded based on context)
         */
        public function enqueue_assets() {
            $is_account = function_exists('is_account_page') && is_account_page();
            $is_product = is_singular('product');
            $is_logged_in = is_user_logged_in();

            // 1. FontAwesome 5 (always needed for icons across the site)
            wp_enqueue_style( 'cw-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4' );

            if ( is_admin() || $is_logged_in ) {
                wp_enqueue_style( 'dashicons' );
            }

            // 2. CSS Files - General always loads, role-specific only on dashboard
            wp_enqueue_style( 'cw-style-general', CW_URL . 'assets/css/cw-style-general.css', ['cw-fontawesome'], CW_VERSION );

            if ( $is_account && $is_logged_in ) {
                $user = wp_get_current_user();
                if ( class_exists( 'CW_Roles' ) && CW_Roles::is_business_user( $user ) ) {
                    wp_enqueue_style( 'cw-style-business', CW_URL . 'assets/css/cw-style-business.css', ['cw-style-general'], CW_VERSION );
                    wp_enqueue_style( 'cw-style-wizard', CW_URL . 'assets/css/cw-style-wizard.css', ['cw-style-business'], CW_VERSION );
                } elseif ( in_array( 'creator_role', (array) $user->roles ) ) {
                    wp_enqueue_style( 'cw-style-creator', CW_URL . 'assets/css/cw-style-creator.css', ['cw-style-general'], CW_VERSION );
                } else {
                    wp_enqueue_style( 'cw-style-contestant', CW_URL . 'assets/css/cw-style-contestant.css', ['cw-style-general'], CW_VERSION );
                }
            }

            // 3. JS Libraries - load only where needed
            $js_deps = ['jquery'];

            if ( $is_account || $is_product || is_page(['registration', 'login', 'get-started']) ) {
                wp_enqueue_style( 'jquery-ui-css', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css' );
                wp_enqueue_script( 'jquery-ui-datepicker' );
                $js_deps[] = 'jquery-ui-datepicker';
            }

            if ( $is_logged_in ) {
                wp_enqueue_script( 'sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11.0', true );
                $js_deps[] = 'sweetalert2';
            }

            if ( $is_account ) {
                wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true );
                $js_deps[] = 'chart-js';
            }

            // 4. Main Script
            wp_enqueue_script( 'cw-core-script', CW_URL . 'assets/js/cw-script.js', $js_deps, CW_VERSION, true );

            // 5. Localize
            $reg_msg = get_transient('registration_message');
            $reg_type = get_transient('registration_message_type');

            $uid = get_current_user_id();
            if (!$reg_msg && $uid) {
                $reg_msg = get_transient('cw_popup_msg_uid_' . $uid);
                $reg_type = get_transient('cw_popup_type_uid_' . $uid);

                if ($reg_msg) {
                    delete_transient('cw_popup_msg_uid_' . $uid);
                    delete_transient('cw_popup_type_uid_' . $uid);
                }
            }

            if ( $reg_msg ) {
                delete_transient('registration_message');
                delete_transient('registration_message_type');
            }

            wp_localize_script( 'cw-core-script', 'cw_vars', [
                'ajax_url'   => admin_url( 'admin-ajax.php' ),
                'popup_msg'  => $reg_msg,
                'popup_type' => $reg_type,
                'nonce'      => wp_create_nonce( 'cw_core_nonce' )
            ]);
        }
        
    }

    /**
     * Helper function to retrieve the main instance.
     */
    function cw_core() {
        return CW_Core_Platform::instance();
    }

    // Kick it off
    cw_core();

endif;