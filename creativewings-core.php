<?php
/**
 * Plugin Name: CreativeWings Core Platform
 * Description: Complete ecosystem: Auth, Onboarding, Campaigns, Tournaments, and Business Logic.
 * Version: 10.3.9
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
            define( 'CW_VERSION', '10.6.3' );
        }

        /**
         * Include required core files.
         */
        private function includes() {
            // 1. Base Logic
            require_once CW_PATH . 'includes/class-cw-activator.php';
            require_once CW_PATH . 'includes/class-cw-roles.php';
            require_once CW_PATH . 'includes/class-cw-post-types.php'; // NEW: Registers CPTs on init
            require_once CW_PATH . 'includes/class-cw-auth.php';
            require_once CW_PATH . 'includes/class-cw-users.php';
            require_once CW_PATH . 'includes/class-cw-onboarding.php';
            require_once CW_PATH . 'includes/class-cw-business.php';
            require_once CW_PATH . 'includes/class-cw-shop.php';
            require_once CW_PATH . 'includes/class-cw-shortcodes.php';
            require_once CW_PATH . 'includes/class-cw-ajax.php';
            require_once CW_PATH . 'includes/class-cw-wallet.php'; 
            
            // 2. Admin Management
            require_once CW_PATH . 'includes/class-cw-admin.php'; 

            // 3. Dashboard Modules
            require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-manager.php';
            require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-creator.php';
            require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-business.php';
            require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-contestant.php';
        }

        /**
         * Initialize Hooks
         */
        private function init_hooks() {
            register_activation_hook( __FILE__, [ 'CW_Activator', 'activate' ] );
            register_deactivation_hook( __FILE__, [ 'CW_Activator', 'deactivate' ] );

            add_action( 'plugins_loaded', [ $this, 'init_plugin' ] );
            add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ] );
            
            // CRITICAL FIX: Hook for certificate download handler
            add_action( 'admin_post_cw_download_cert', [ $this, 'process_certificate_download' ] );
        }

        /**
         * Instantiate Classes on plugins_loaded
         */
        public function init_plugin() {
            // Load Text Domain
            load_plugin_textdomain( 'creativewings-core', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

            // WooCommerce Dependency Check
            if ( ! class_exists( 'WooCommerce' ) ) {
                add_action( 'admin_notices', function() {
                    echo '<div class="notice notice-error"><p><strong>CreativeWings Core</strong> requires WooCommerce to be installed and active.</p></div>';
                });
                return;
            }

            // Instantiate Core Modules
            $this->roles      = new CW_Roles();
            $this->post_types = new CW_Post_Types(); // Registers menus immediately
            $this->users      = new CW_Users();
            $this->onboarding = new CW_Onboarding();
            $this->business   = new CW_Business();
            $this->shop       = new CW_Shop();
            $this->shortcodes = new CW_Shortcodes();
            $this->ajax       = new CW_Ajax();
            $this->auth       = new CW_Auth();
            $this->wallet     = new CW_Wallet();
            $this->admin      = new CW_Admin(); // Handles Admin Columns/Metaboxes

            // Initialize Dashboard Manager
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
                if ( in_array( 'business_role', (array) $user->roles ) ) {
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
        
       /**
         * Process Certificate Download (DYNAMIC PDF GENERATION)
         */
        public function process_certificate_download() {
            $entry_id = intval($_GET['entry_id'] ?? 0);
            $user_id = get_current_user_id();
            
            if (!$entry_id || !$user_id) wp_die('Missing Entry ID or not logged in.');
            
            $entry = get_post($entry_id);
            $product_id = get_post_meta($entry_id, 'product_id', true);
            
            // Security Check: Does the user own the entry?
            if (!$entry || (int)$entry->post_author !== (int)$user_id) wp_die('Access denied to this certificate.', 'Access Denied', ['response' => 403]);
            
            // Feature Check: Is the certificate enabled AND is the entry complete?
            $cert_enabled = get_post_meta($product_id, 'cw_enable_certificate', true) === 'yes';
            $is_completed = get_post_meta($entry_id, 'judge_score', true) !== '';
            
            if (!$cert_enabled || !$is_completed) {
                wp_die('Certificate is not yet available or not enabled for this event.', 'Not Available', ['response' => 404]);
            }
            
            // --- Download Variables ---
            $participant_name = get_post_meta($entry_id, 'cw_participant_name', true) ?: 'Valued Participant';
            $cert_url = get_post_meta($product_id, 'cw_cert_template', true);
            
            // Convert URL to file path
            $file_path = str_replace(site_url('/'), ABSPATH, $cert_url);
            
            // Determine file name for the download
            $ext = pathinfo($file_path, PATHINFO_EXTENSION);
            $base_name = sanitize_file_name($participant_name . '_Certificate');
            $file_name = $base_name . '.' . $ext; // Use the actual file extension

            // Check if the file exists on the server
            if (!file_exists($file_path)) {
                wp_die('Certificate template file not found on server.', 'File Not Found', ['response' => 404]);
            }
            
            // --- FINAL DOWNLOAD LOGIC (WORKING STATIC FILE) ---
            
            // Aggressively clean all output buffers
            if (ob_get_level()) ob_end_clean();
            
            // Set headers for a forced download
            // Assume PDF is the safest Content-Type for a certificate, but check file extension
            $mime_type = 'application/pdf';
            if (strtolower($ext) === 'png') $mime_type = 'image/png';
            if (strtolower($ext) === 'jpg' || strtolower($ext) === 'jpeg') $mime_type = 'image/jpeg';
            
            header('Content-Type: ' . $mime_type); 
            header('Content-Disposition: attachment; filename="' . $file_name . '"');
            header('Content-Length: ' . filesize($file_path));
            header('Expires: 0');
            header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
            header('Pragma: public');

            // Send the file content
            readfile($file_path);
            exit;
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