<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Auth {

    public function __construct() {
        // 1. Shortcodes
        add_shortcode( 'custom_login_form', [ $this, 'render_login_form' ] );
        add_shortcode( 'custom_creator_registration_form', [ $this, 'render_register_form' ] );
        add_shortcode( 'custom_forgot_password', [ $this, 'render_forgot_form' ] );
        add_shortcode( 'logout_url', [ $this, 'shortcode_logout_url' ] );
        
        // 2. Logic Hooks
        add_action( 'init', [ $this, 'handle_registration' ] );
        add_action( 'init', [ $this, 'login_page_redirect' ] ); // Redirect wp-login.php
        
        // PAGE PROTECTION & REDIRECTS
        add_action( 'template_redirect', [ $this, 'enforce_onboarding_access' ] );
        
        add_action( 'wp_login_failed', [ $this, 'handle_login_failed' ] );
        add_action( 'wp_logout', [ $this, 'redirect_after_logout' ] );
        
        // 3. Filters
        add_filter( 'authenticate', [ $this, 'check_empty_credentials' ], 30, 3 );
        add_filter( 'login_redirect', [ $this, 'custom_login_redirect' ], 10, 3 );
        
        // 4. Form Handlers (Admin Post)
        add_action( 'admin_post_nopriv_handle_forgot_password', [ $this, 'process_forgot_password' ] );
        add_action( 'admin_post_handle_forgot_password', [ $this, 'process_forgot_password' ] );
        
        // 5. Social Login Integration
        add_action( 'nextend_social_login_register', [ $this, 'social_register_metadata' ], 10, 3 );
    }

    /* ==========================================================================
       SHORTCODE: REGISTER FORM (Updated with New UI Classes)
       ========================================================================== */
    public function render_register_form() {
        if ( is_user_logged_in() ) {
            return '<script>window.location.href="' . home_url('/my-account') . '";</script>';
        }
        
        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <?php $this->display_auth_messages(); ?>

                <form method="POST" action="" class="cw-auth-form-v2">
                    <?php wp_nonce_field('creator_reg_action', 'creator_reg_nonce'); ?>
                    <input type="hidden" name="form_timestamp" value="<?php echo time(); ?>" />
                    
                    <!-- Honeypot -->
                    <div class="cw-hp-field" style="display:none;" aria-hidden="true">
                        <input type="text" name="cw_hp_check" value="" autocomplete="off" tabindex="-1">
                    </div>
                    
                    <div class="cw-input-group-v2">
                        <label class="cw-label">Full name</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-user"></i>
                            <input type="text" name="cw_full_name" required placeholder="As shown on certificate" class="cw-input-text-v2" autocomplete="name">
                        </div>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label">Email Address</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="creator_email" required placeholder="me@example.com" class="cw-input-text-v2">
                        </div>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label">Password</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="creator_password" name="creator_password" required placeholder="••••••••" class="cw-input-text-v2">
                        </div>
                        
                        <!-- PASSWORD STRENGTH METER HTML -->
                        <div class="strength-bar" style="margin-top: 5px;">
                            <div class="strength-fill" id="strength-fill"></div>
                        </div>
                        <small style="float:right; color:#888; font-size:11px; margin-top:4px;">
                            Use 8+ chars, numbers & symbols
                        </small>
                    </div>

                

                    <div class="cw-input-group-v2">
                        <label class="cw-label">Date of birth</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-calendar"></i>
                            <input type="text" id="birthdate" name="birthdate" required readonly placeholder="dd/mm/yyyy" class="cw-input-text-v2">
                        </div>
                        <small style="color:#64748b;font-size:12px;">Used for age category when joining campaigns.</small>
                    </div>

                    <button type="submit" class="cw-btn-primary cw-btn-full">Sign Up</button>
                    
                    <div class="cw-auth-links-v2">
                        Already a member? <a href="<?php echo home_url('/login'); ?>" class="cw-link-main">Log in</a>
                    </div>
                    
                    <div class="cw-social-login-v2">
                        <div class="cw-divider-v2"><span>Or register with</span></div>
                        <div class="cw-social-btns-v2">
                            <?php echo do_shortcode('[nextend_social_login provider="google" redirect="/"]'); ?>
                            <?php echo do_shortcode('[nextend_social_login provider="facebook" redirect="/"]'); ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php 
        return ob_get_clean();
    }

    /* ==========================================================================
       SHORTCODE: LOGIN FORM (Updated with New UI Classes)
       ========================================================================== */
    public function render_login_form() {
        if ( is_user_logged_in() ) {
            return '<script>window.location.href="' . home_url('/my-account') . '";</script>';
        }

        $redirect = isset($_REQUEST['redirect_to']) ? esc_url_raw($_REQUEST['redirect_to']) : home_url('/my-account');
        
        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <h1 class="cw-auth-heading">Log In</h1>
                
                <?php $this->display_auth_messages(); ?>

                <form action="<?php echo esc_url( site_url( 'wp-login.php', 'login_post' ) ); ?>" method="post" class="cw-auth-form-v2">
                    
                    <div class="cw-input-group-v2">
                        <label class="cw-label">Email</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-envelope"></i>
                            <input type="text" name="log" required placeholder="Enter Email" class="cw-input-text-v2">
                        </div>
                    </div>
                    
                    <div class="cw-input-group-v2">
                        <label class="cw-label">Password</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" name="pwd" required placeholder="Enter Password" class="cw-input-text-v2">
                        </div>
                    </div>

                    <button type="submit" class="cw-btn-primary cw-btn-full">Sign In</button>
                    
                    <div class="cw-auth-links-v2">
                        <a href="<?php echo home_url('/forgot-password'); ?>" class="cw-link-sub">Forgot Password?</a>
                        <a href="<?php echo home_url('/registration'); ?>" class="cw-link-main">Create a new account</a>
                    </div>
                    
                    <input type="hidden" name="redirect_to" value="<?php echo esc_attr($redirect); ?>">

                    <div class="cw-social-login-v2">
                        <div class="cw-divider-v2"><span>Or sign in with</span></div>
                        <div class="cw-social-btns-v2">
                            <?php echo do_shortcode('[nextend_social_login provider="google" redirect="/"]'); ?>
                            <?php echo do_shortcode('[nextend_social_login provider="facebook" redirect="/"]'); ?>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <?php 
        return ob_get_clean();
    }

    /* ==========================================================================
       SHORTCODE: FORGOT PASSWORD (Updated with New UI Classes)
       ========================================================================== */
    public function render_forgot_form() {
        if ( is_user_logged_in() ) {
            return '<script>window.location.href="' . home_url('/my-account') . '";</script>';
        }

        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <h1 class="cw-auth-heading">Reset Password</h1>
                
                <?php $this->display_auth_messages(); ?>

                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-auth-form-v2">
                    <?php wp_nonce_field('forgot_pass_action', 'forgot_pass_nonce'); ?>
                    
                    <div class="cw-input-group-v2">
                        <label class="cw-label">Enter your email address</label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-envelope"></i>
                            <input type="email" name="user_login" placeholder="Email address" required class="cw-input-text-v2">
                        </div>
                    </div>

                    <button type="submit" class="cw-btn-primary cw-btn-full">Send Reset Link</button>
                    
                    <div class="cw-auth-links-v2">
                        <a href="<?php echo home_url('/login'); ?>" class="cw-link-sub">Back to Login</a>
                    </div>
                    
                    <input type="hidden" name="action" value="handle_forgot_password">
                </form>
            </div>
        </div>
        <?php 
        return ob_get_clean();
    }

    /* ==========================================================================
       LOGIC: REGISTRATION
       ========================================================================== */
    public function handle_registration() {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' || ! isset( $_POST['creator_email'] ) ) {
            return;
        }

        // 1. Verify Nonce
        if ( ! isset( $_POST['creator_reg_nonce'] ) || ! wp_verify_nonce( $_POST['creator_reg_nonce'], 'creator_reg_action' ) ) {
            wp_die( __('Security Check Failed', 'creativewings-core') );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION, 10, 3600 );
            if ( is_wp_error( $rl ) ) {
                $this->redirect_with_error( 'reg_error', 'rate_limit' );
                exit;
            }
        }

        // 2. Honeypot Check (Must be empty)
        if ( ! empty( $_POST['cw_hp_check'] ) ) {
            // Bots often fill this invisible field. If filled, we just pretend it worked but do nothing.
            wp_redirect( home_url('/registration?reg_success=1') ); 
            exit;
        }

        // 3. SPAM DOMAIN BLOCKER (New Fix)
        $email = sanitize_email( $_POST['creator_email'] );
        $blocked_domains = ['.website', '.top', '.xyz', '.pw', '.click', '.monster', '.live'];
        foreach ($blocked_domains as $domain) {
            if (strpos(strtolower($email), $domain) !== false) {
                $this->redirect_with_error( 'reg_error', 'generic' );
                exit;
            }
        }

        // 4. Pattern Check (Bots often use Name + Number patterns)
        $pass      = $_POST['creator_password'];
        $full_name = sanitize_text_field( $_POST['cw_full_name'] ?? '' );
        $birthdate = sanitize_text_field( $_POST['birthdate'] ?? '' );

        if ( empty( $email ) || empty( $pass ) || empty( $full_name ) || empty( $birthdate ) ) {
            $this->redirect_with_error( 'reg_error', 'missing_fields' );
        }

        if ( email_exists( $email ) ) {
            $this->redirect_with_error( 'reg_error', 'email_exists' );
        }

        // 5. Create User
        $username = explode( '@', $email )[0] . rand( 10, 999 );
        
        // Final sanity check on username length/bot patterns
        if (strlen($username) < 4) {
             $this->redirect_with_error( 'reg_error', 'generic' );
        }

        $user_id  = wp_create_user( $username, $pass, $email );

        if ( ! is_wp_error( $user_id ) ) {
            $parts = preg_split( '/\s+/', trim( $full_name ), 2 );
            $first = $parts[0] ?? $full_name;
            $last  = $parts[1] ?? '';

            update_user_meta( $user_id, 'cw_full_name', $full_name );
            update_user_meta( $user_id, 'birthdate', $birthdate );
            update_user_meta( $user_id, 'first_name', $first );
            update_user_meta( $user_id, 'last_name', $last );
            update_user_meta( $user_id, 'account_type', 'contestant' );

            wp_update_user( [
                'ID'           => $user_id,
                'display_name' => $full_name,
                'first_name'   => $first,
                'last_name'    => $last,
            ] );

            $user = new WP_User( $user_id );
            $user->set_role( 'contestant' ); 

            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id );
            wp_redirect( home_url( '/get-started' ) );
            exit;
        } else {
            $this->redirect_with_error( 'reg_error', 'generic' );
        }
    }

    /* ==========================================================================
       LOGIC: LOGIN & REDIRECTS (With Role Enforcement)
       ========================================================================== */
    
    public function custom_login_redirect( $redirect, $req, $user ) {
        if ( ! empty( $req ) && strpos( $req, 'wp-admin' ) !== false ) return $req;
        if ( is_wp_error( $user ) || ! isset( $user->roles ) ) return $redirect;

        // --- NEW FIX: Read the Onboarding Complete Flag ---
        $is_onboarded = get_user_meta($user->ID, 'cw_onboarding_complete', true) === 'true';
        
        // Admin -> Admin Panel
        if ( in_array( 'administrator', $user->roles ) ) return admin_url();

        // Business / Creator / ONBOARDED Contestant -> Dashboard
        if ( in_array( 'business_role', $user->roles ) || in_array( 'creator_role', $user->roles ) || $is_onboarded ) {
            return home_url( '/my-account' ); // Allowed to go to the dashboard
        }

        // Contestant (NOT Onboarded) -> Force 'Get Started'
        if ( in_array( 'contestant', $user->roles ) && ! $is_onboarded ) {
            return home_url( '/get-started' ); // Redirect to onboarding page
        }

        return home_url( '/my-account' ); // Fallback to dashboard
    }

    public function enforce_onboarding_access() {
        // 0. General protection for login/register pages
        if ( is_user_logged_in() && ( is_page('login') || is_page('registration') ) ) {
            wp_redirect( home_url( '/my-account' ) );
            exit;
        }

        if ( ! is_user_logged_in() ) return;

        $user = wp_get_current_user();
        $is_onboarded = get_user_meta($user->ID, 'cw_onboarding_complete', true) === 'true';
        
        // --- LOGIC 1: Enforce redirection TO /get-started/ ---
        // If the user is a Contestant AND has NOT yet completed the onboarding (no flag set)
        if ( in_array('contestant', (array) $user->roles) && ! $is_onboarded ) {
            // If they try to access the dashboard endpoint, send them to Get Started
            if ( function_exists('is_account_page') && is_account_page() && ! is_wc_endpoint_url('customer-logout') ) {
                wp_redirect( home_url( '/get-started' ) );
                exit;
            }
        }

        // --- LOGIC 2: Enforce redirection FROM /get-started/ ---
        // If the user has completed onboarding (by Skip, Creator, or Business)
        if ( $is_onboarded ) {
            if ( is_page( 'get-started' ) ) {
                wp_redirect( home_url( '/my-account' ) );
                exit;
            }
        }
    }

    public function login_page_redirect() {
        global $pagenow;
        if ( 'wp-login.php' == $pagenow && $_SERVER['REQUEST_METHOD'] == 'GET' && ! isset( $_GET['action'] ) ) {
            wp_redirect( home_url( '/login' ) );
            exit;
        }
    }

    public function handle_login_failed() {
        $referrer = $_SERVER['HTTP_REFERER'];
        if ( $referrer && ! strstr( $referrer, 'login=failed' ) ) {
            wp_redirect( add_query_arg( 'login', 'failed', home_url( '/login' ) ) );
            exit;
        }
    }

    public function check_empty_credentials( $user, $username, $password ) {
        if ( empty( $username ) || empty( $password ) ) {
            if ( is_wp_error( $user ) ) {
                wp_redirect( home_url( '/login?login=empty' ) );
                exit;
            }
        }
        return $user;
    }

    public function redirect_after_logout() {
        wp_safe_redirect( home_url() );
        exit;
    }

    public function shortcode_logout_url() {
        return wp_logout_url( home_url() );
    }

    /* ==========================================================================
       LOGIC: FORGOT PASSWORD
       ========================================================================== */
    public function process_forgot_password() {
        if ( ! isset( $_POST['forgot_pass_nonce'] ) || ! wp_verify_nonce( $_POST['forgot_pass_nonce'], 'forgot_pass_action' ) ) {
            wp_die( 'Security Error' );
        }

        $email = sanitize_email( $_POST['user_login'] );
        $user  = get_user_by( 'email', $email );

        if ( $user ) {
            $key = get_password_reset_key( $user );
            $reset_url = network_site_url( "wp-login.php?action=rp&key=$key&login=" . rawurlencode( $user->user_login ), 'login' );
            
            $message = __('Someone requested a password reset for:', 'creativewings-core') . "\r\n\r\n";
            $message .= network_home_url( '/' ) . "\r\n\r\n";
            $message .= sprintf(__('Username: %s', 'creativewings-core'), $user->user_login) . "\r\n\r\n";
            $message .= __('To reset your password, visit:', 'creativewings-core') . "\r\n\r\n";
            $message .= $reset_url . "\r\n";

            wp_mail( $email, __('Password Reset', 'creativewings-core'), $message );

            wp_redirect( add_query_arg( 'reset', 'success', wp_get_referer() ) );
        } else {
            wp_redirect( add_query_arg( 'reset', 'invalid', wp_get_referer() ) );
        }
        exit;
    }

    /* ==========================================================================
       LOGIC: SOCIAL LOGIN
       ========================================================================== */
    public function social_register_metadata( $user_id, $provider_name, $user_data ) {
        update_user_meta( $user_id, 'account_type', 'contestant' );
        update_user_meta( $user_id, 'social_provider', $provider_name );
        
        $user = new WP_User($user_id);
        $user->set_role('contestant');
    }

    /* ==========================================================================
       HELPER: MESSAGES
       ========================================================================== */
    private function redirect_with_error( $key, $code ) {
        wp_redirect( add_query_arg( $key, $code, $_SERVER['REQUEST_URI'] ) );
        exit;
    }

    private function display_auth_messages() {
        if ( isset( $_GET['reg_error'] ) ) {
            $msg = '';
            switch ( $_GET['reg_error'] ) {
                case 'missing_fields': $msg = 'Please fill in all fields.'; break;
                case 'email_exists':   $msg = 'Email already exists.'; break;
                case 'generic':        $msg = 'An error occurred. Please try again.'; break;
            }
            if ( $msg ) echo '<div class="cw-alert error">' . esc_html( $msg ) . '</div>';
        }

        if ( isset( $_GET['login'] ) && $_GET['login'] === 'failed' ) {
            echo '<div class="cw-alert error">' . __('Invalid email or password.', 'creativewings-core') . '</div>';
        }

        if ( isset( $_GET['reset'] ) ) {
            if ( $_GET['reset'] === 'success' ) {
                echo '<div class="cw-alert success">' . __('Check your email for the reset link.', 'creativewings-core') . '</div>';
            } elseif ( $_GET['reset'] === 'invalid' ) {
                echo '<div class="cw-alert error">' . __('Email not found.', 'creativewings-core') . '</div>';
            }
        }
    }
}