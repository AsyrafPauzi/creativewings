<?php
/**
 * Plugin Name: CreativeWings Core Platform
 * Description: Complete ecosystem: Auth, Onboarding, Campaigns, Tournaments, and Business Logic.
 * Version: 11.0.80
 * Author: CreativeWings Dev
 * Text Domain: creativewings-core
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly
}

// Composer autoload (PhpSpreadsheet + Dompdf for Reports exports).
if ( file_exists( __DIR__ . '/vendor/autoload.php' ) ) {
    require_once __DIR__ . '/vendor/autoload.php';
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
            define( 'CW_VERSION', '11.0.81' );
        }

        /**
         * Resolve a build-pipeline entry (e.g. 'assets/css/cw-style-general.css')
         * to its hashed dist file. Falls back to the source path when no manifest
         * is found (i.e. dev environments that haven't run `npm run build`).
         *
         * @return array { url: string, version: string }
         */
        public static function asset( $entry_path ) {
            static $manifest = null;

            if ( $manifest === null ) {
                $manifest_file = CW_PATH . 'assets/dist/.vite/manifest.json';
                if ( ! file_exists( $manifest_file ) ) {
                    // Fallback for older Vite versions / mis-located manifests.
                    $alt = CW_PATH . 'assets/dist/manifest.json';
                    if ( file_exists( $alt ) ) {
                        $manifest_file = $alt;
                    }
                }
                $manifest = file_exists( $manifest_file )
                    ? json_decode( (string) file_get_contents( $manifest_file ), true )
                    : [];
                if ( ! is_array( $manifest ) ) {
                    $manifest = [];
                }
            }

            if ( isset( $manifest[ $entry_path ] ) ) {
                // CSS-only entries surface the built CSS via the `css` array; JS entries via `file`.
                $entry = $manifest[ $entry_path ];
                if ( ! empty( $entry['file'] ) && substr( $entry['file'], -3 ) === 'css' ) {
                    return [ 'url' => CW_URL . 'assets/dist/' . $entry['file'], 'version' => null ];
                }
                if ( ! empty( $entry['css'][0] ) ) {
                    return [ 'url' => CW_URL . 'assets/dist/' . $entry['css'][0], 'version' => null ];
                }
                if ( ! empty( $entry['file'] ) ) {
                    return [ 'url' => CW_URL . 'assets/dist/' . $entry['file'], 'version' => null ];
                }
            }

            return [ 'url' => CW_URL . $entry_path, 'version' => CW_VERSION ];
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

            // Honour `wp_script_add_data( $h, 'defer', true )` on the frontend by
            // injecting the attribute into the rendered script tag.
            add_filter( 'script_loader_tag', [ $this, 'maybe_defer_script_tag' ], 10, 3 );
        }

        /**
         * Add `defer` to <script> tags when their handle was marked deferrable.
         */
        public function maybe_defer_script_tag( $tag, $handle, $src ) {
            if ( is_admin() ) {
                return $tag;
            }
            $defer = wp_scripts()->get_data( $handle, 'defer' );
            if ( $defer && false === strpos( $tag, ' defer' ) ) {
                $tag = preg_replace( '#<script\s+#', '<script defer ', $tag, 1 );
            }
            return $tag;
        }

        /**
         * Remove any *foreign* Chart.js script from the page (anything whose handle
         * isn't `chart-js` but whose src points at a chart.js / chart.min.js file).
         * Our dashboards build against Chart.js v4 — a stale v2.x library injected
         * by another plugin/theme overrides `window.Chart` and breaks linkScales().
         */
        public function dequeue_foreign_chart_js() {
            $wp_scripts = wp_scripts();
            if ( ! $wp_scripts || empty( $wp_scripts->registered ) ) return;
            foreach ( $wp_scripts->registered as $handle => $script ) {
                if ( $handle === 'chart-js' ) continue;
                $src = is_object( $script ) ? (string) $script->src : '';
                if ( $src === '' ) continue;
                // Match: chart.js, chart.min.js, chart.umd.js, chart.umd.min.js, Chart.js, etc.
                if ( preg_match( '#/chart(?:\.umd)?(?:\.min)?\.js(?:$|\?)#i', $src ) ) {
                    wp_dequeue_script( $handle );
                }
            }
        }

        /**
         * Belt-and-braces: rewrite any leftover chart.js tag (some themes inject
         * raw <script> via wp_footer instead of the enqueue API) so the browser
         * never even fetches the wrong version.
         */
        public function strip_foreign_chart_tag( $tag, $handle, $src ) {
            if ( is_admin() ) return $tag;
            if ( $handle === 'chart-js' ) return $tag;
            if ( ! is_string( $src ) || $src === '' ) return $tag;
            if ( preg_match( '#/chart(?:\.umd)?(?:\.min)?\.js(?:$|\?)#i', $src ) ) {
                return ''; // Drop the conflicting tag entirely.
            }
            return $tag;
        }

        /**
         * Preload the latin subset of Montserrat early so the browser fetches the
         * font as soon as the document head is parsed. Eliminates FOUT/FOIT on the
         * first paint without paying the cost of preloading every subset.
         */
        public function preload_brand_font() {
            $url = CW_URL . 'assets/vendor/montserrat/montserrat-latin.woff2';
            echo '<link rel="preload" as="font" type="font/woff2" href="' . esc_url( $url ) . '" crossorigin>' . "\n";
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
                new CW_Image_Bulk_Optimizer();
                if ( class_exists( 'CW_Badges_Admin' ) ) {
                    new CW_Badges_Admin();
                }
                if ( class_exists( 'CW_Sync_Center' ) ) {
                    new CW_Sync_Center();
                }
            }

            // Badges: register the CPT instance and wire engine hooks.
            new CW_Badges_CPT();
            CW_Badges_Engine::register_hooks();
            // Render any pending award toasts inside the WP footer on the front-end.
            add_action( 'wp_footer', [ 'CW_Badges_Display', 'maybe_render_toast' ], 50 );

            // Flash notices (SweetAlert2 popups for ?error / ?success / ?warning / etc).
            new CW_Flash_Notices();
            $this->shop       = new CW_Shop();
            new CW_Design_Submission();
            new CW_Checkout();
            $this->shortcodes = new CW_Shortcodes();
            new CW_Organizer_Profile();
            new CW_Directory();
            $this->ajax       = new CW_Ajax();
            $this->auth       = new CW_Auth();
            $this->wallet     = new CW_Wallet();
            $this->admin      = new CW_Admin();
            new CW_Report_Export();
            $this->dashboard_manager = new CW_Dashboard_Manager();
        }

        /**
         * Enqueue Assets (conditionally loaded based on context)
         */
        public function enqueue_assets() {
            $is_account = function_exists('is_account_page') && is_account_page();
            $is_product = is_singular('product');
            $is_logged_in = is_user_logged_in();

            // 1a. Brand typography — Montserrat (self-hosted variable font per CW Brand Guide v2.0).
            // Variable font, so weights 100..900 are covered by one .woff2 per Unicode subset.
            $mont_local = CW_PATH . 'assets/vendor/montserrat/montserrat.css';
            if ( file_exists( $mont_local ) ) {
                wp_enqueue_style( 'cw-montserrat', CW_URL . 'assets/vendor/montserrat/montserrat.css', [], CW_VERSION );
                // Preload the latin subset on every page (covers EN + BM) so the font is requested
                // alongside the CSS rather than discovered after CSS parses → cuts FOIT/CLS.
                add_action( 'wp_head', [ $this, 'preload_brand_font' ], 1 );
            }

            // 1b. FontAwesome (self-hosted subset preferred; falls back to CDN).
            $fa_local = CW_PATH . 'assets/vendor/fontawesome/css/all.min.css';
            if ( file_exists( $fa_local ) ) {
                wp_enqueue_style( 'cw-fontawesome', CW_URL . 'assets/vendor/fontawesome/css/all.min.css', [], CW_VERSION );
            } else {
                wp_enqueue_style( 'cw-fontawesome', 'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css', [], '5.15.4' );
            }

            if ( is_admin() || $is_logged_in ) {
                wp_enqueue_style( 'dashicons' );
            }

            // 2. CSS Files - General (core chunk) always loads; role-specific chunks only on the dashboard.
            $core_css = self::asset( 'assets/css/cw-style-general.css' );
            $core_deps = ['cw-fontawesome'];
            if ( wp_style_is( 'cw-montserrat', 'registered' ) || wp_style_is( 'cw-montserrat', 'enqueued' ) ) {
                $core_deps[] = 'cw-montserrat';
            }
            wp_enqueue_style( 'cw-style-general', $core_css['url'], $core_deps, $core_css['version'] );

            // Organizer profile: registered always, enqueued only on its own page (shortcode or /organizer/{slug}/).
            $org_css = self::asset( 'assets/css/cw-style-organizer.css' );
            wp_register_style( 'cw-style-organizer', $org_css['url'], ['cw-fontawesome'], $org_css['version'] );
            $needs_org_css = (bool) get_query_var( 'cw_organizer' );
            if ( ! $needs_org_css && is_singular() ) {
                $post = get_post();
                if ( $post && has_shortcode( (string) $post->post_content, 'cw_organizer_profile' ) ) {
                    $needs_org_css = true;
                }
            }
            if ( $needs_org_css ) {
                wp_enqueue_style( 'cw-style-organizer' );
            }

            // Public directory shortcodes: register always, enqueue when a singular page contains either shortcode.
            $dir_css = self::asset( 'assets/css/cw-style-directory.css' );
            wp_register_style( 'cw-style-directory', $dir_css['url'], ['cw-fontawesome'], $dir_css['version'] );
            if ( is_singular() ) {
                $post = get_post();
                if ( $post ) {
                    $content = (string) $post->post_content;
                    if ( has_shortcode( $content, 'cw_organizers_directory' ) || has_shortcode( $content, 'cw_creators_directory' ) ) {
                        wp_enqueue_style( 'cw-style-directory' );
                    }
                }
            }

            // Badges CSS — register always; enqueue when dashboards, organizer profile,
            // directory shortcodes, or any logged-in front-end view is rendered.
            $badge_css = self::asset( 'assets/css/cw-style-badges.css' );
            wp_register_style( 'cw-style-badges', $badge_css['url'], ['cw-style-general'], $badge_css['version'] );

            $is_creator_profile_page = (bool) get_query_var( 'profile_nickname' );
            if ( $needs_org_css || $is_account || $is_logged_in || $is_creator_profile_page ) {
                wp_enqueue_style( 'cw-style-badges' );
            } elseif ( is_singular() ) {
                $post = get_post();
                if ( $post ) {
                    $content = (string) $post->post_content;
                    if ( has_shortcode( $content, 'cw_organizers_directory' ) || has_shortcode( $content, 'cw_creators_directory' ) ) {
                        wp_enqueue_style( 'cw-style-badges' );
                    }
                }
            }

            if ( $is_account && $is_logged_in ) {
                $user = wp_get_current_user();
                if ( class_exists( 'CW_Roles' ) && CW_Roles::is_business_user( $user ) ) {
                    $biz_css  = self::asset( 'assets/css/cw-style-business.css' );
                    $wiz_css  = self::asset( 'assets/css/cw-style-wizard.css' );
                    wp_enqueue_style( 'cw-style-business', $biz_css['url'], ['cw-style-general'], $biz_css['version'] );
                    wp_enqueue_style( 'cw-style-wizard',   $wiz_css['url'], ['cw-style-business'], $wiz_css['version'] );
                } elseif ( in_array( 'creator_role', (array) $user->roles ) ) {
                    $cr_css = self::asset( 'assets/css/cw-style-creator.css' );
                    wp_enqueue_style( 'cw-style-creator', $cr_css['url'], ['cw-style-general'], $cr_css['version'] );
                } else {
                    $ct_css = self::asset( 'assets/css/cw-style-contestant.css' );
                    $cr_css = self::asset( 'assets/css/cw-style-creator.css' );
                    wp_enqueue_style( 'cw-style-contestant', $ct_css['url'], ['cw-style-general'], $ct_css['version'] );
                    wp_enqueue_style( 'cw-style-creator',    $cr_css['url'], ['cw-style-contestant'], $cr_css['version'] );
                }
            }

            // 3. JS Libraries - load only where needed
            $js_deps = ['jquery'];

            if ( $is_account || $is_product || is_page(['registration', 'login', 'get-started', 'complete-profile']) ) {
                wp_enqueue_style( 'jquery-ui-css', 'https://cdnjs.cloudflare.com/ajax/libs/jqueryui/1.12.1/jquery-ui.min.css' );
                wp_enqueue_script( 'jquery-ui-datepicker' );
                $js_deps[] = 'jquery-ui-datepicker';
            }

            if ( $is_logged_in ) {
                // Self-hosted SweetAlert2 if present (Phase 4), CDN fallback otherwise.
                // The local .min.js does NOT inject styles (unlike the CDN's @all
                // bundle), so the matching .min.css must be enqueued explicitly —
                // otherwise the modal renders as unstyled inline content at the
                // bottom of the page.
                $sa_local_js  = CW_PATH . 'assets/vendor/sweetalert2/sweetalert2.min.js';
                $sa_local_css = CW_PATH . 'assets/vendor/sweetalert2/sweetalert2.min.css';
                if ( file_exists( $sa_local_js ) ) {
                    if ( file_exists( $sa_local_css ) ) {
                        wp_enqueue_style( 'sweetalert2', CW_URL . 'assets/vendor/sweetalert2/sweetalert2.min.css', [], CW_VERSION );
                    }
                    wp_enqueue_script( 'sweetalert2', CW_URL . 'assets/vendor/sweetalert2/sweetalert2.min.js', [], CW_VERSION, true );
                } else {
                    wp_enqueue_script( 'sweetalert2', 'https://cdn.jsdelivr.net/npm/sweetalert2@11', [], '11.0', true );
                }
                $js_deps[] = 'sweetalert2';
            }

            if ( $is_account ) {
                // Self-hosted Chart.js if present (Phase 4), CDN fallback otherwise.
                $cj_local = CW_PATH . 'assets/vendor/chart.js/chart.umd.min.js';
                if ( file_exists( $cj_local ) ) {
                    wp_enqueue_script( 'chart-js', CW_URL . 'assets/vendor/chart.js/chart.umd.min.js', [], CW_VERSION, true );
                } else {
                    wp_enqueue_script( 'chart-js', 'https://cdn.jsdelivr.net/npm/chart.js', [], '4.4.0', true );
                }
                $js_deps[] = 'chart-js';

                // Defensive: dequeue any other registered chart.js script on this page
                // so our v4 always wins (some themes/plugins ship a stale v2 build that
                // overrides window.Chart and breaks our v4-API dashboard charts).
                add_action( 'wp_print_scripts', [ $this, 'dequeue_foreign_chart_js' ], 999 );
                add_filter( 'script_loader_tag', [ $this, 'strip_foreign_chart_tag' ], 10, 3 );
            }

            // 4. Main Script (deferred for non-blocking parse).
            $app_js = self::asset( 'assets/js/cw-script.js' );
            wp_enqueue_script( 'cw-core-script', $app_js['url'], $js_deps, $app_js['version'], true );
            wp_script_add_data( 'cw-core-script', 'defer', true );

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