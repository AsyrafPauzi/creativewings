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
        add_shortcode( 'cw_complete_profile', [ $this, 'render_complete_profile_form' ] );
        add_shortcode( 'cw_reset_password', [ $this, 'render_reset_password_form' ] );
        add_shortcode( 'cw_complete_guest_registration', [ $this, 'render_complete_guest_registration_form' ] );
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

        // 3b. Outgoing email branding (only overrides the default WordPress from values)
        add_filter( 'wp_mail_from',      [ $this, 'mail_from_address' ] );
        add_filter( 'wp_mail_from_name', [ $this, 'mail_from_name' ] );

        // 3c. Branded HTML for WP native password reset emails (wp-login.php?action=lostpassword, WC "Lost password?", etc.)
        add_filter( 'retrieve_password_notification_email', [ $this, 'customize_password_reset_email' ], 10, 4 );

        // 3d. Admin notice: warn if required auth pages are missing
        add_action( 'admin_notices', [ $this, 'maybe_show_missing_pages_notice' ] );
        
        // 4. Form Handlers (Admin Post)
        add_action( 'admin_post_nopriv_handle_forgot_password', [ $this, 'process_forgot_password' ] );
        add_action( 'admin_post_handle_forgot_password', [ $this, 'process_forgot_password' ] );
        add_action( 'admin_post_cw_complete_profile', [ $this, 'process_complete_profile' ] );
        add_action( 'admin_post_nopriv_cw_reset_password', [ $this, 'process_reset_password' ] );
        add_action( 'admin_post_cw_reset_password',       [ $this, 'process_reset_password' ] );
        add_action( 'admin_post_nopriv_cw_complete_guest_registration', [ $this, 'process_complete_guest_registration' ] );
        add_action( 'admin_post_cw_complete_guest_registration',       [ $this, 'process_complete_guest_registration' ] );
        
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
                        <small style="color:#555555;font-size:12px;">Used for age category when joining campaigns.</small>
                    </div>

                    <?php
                    $pdpa_page = get_page_by_path( 'pdpa' );
                    $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );
                    ?>
                    <div class="cw-pdpa-consent">
                        <label class="cw-pdpa-consent-label" for="cw_pdpa_consent">
                            <input type="checkbox" id="cw_pdpa_consent" name="cw_pdpa_consent" value="1" required>
                            <span class="cw-pdpa-consent-text">
                                <?php
                                printf(
                                    /* translators: %s: link to PDPA notice page */
                                    esc_html__( 'I have read and agree to the %s. For applicants under 18, a parent or guardian gives permission to collect and use the information provided.', 'creativewings-core' ),
                                    '<a href="' . esc_url( $pdpa_url ) . '" target="_blank" rel="noopener" class="cw-pdpa-consent-link">' . esc_html__( 'Personal Data Protection (PDPA) Notice', 'creativewings-core' ) . '</a>'
                                );
                                ?>
                                <span class="cw-pdpa-req" aria-hidden="true">*</span>
                            </span>
                        </label>
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

        if ( empty( $_POST['cw_pdpa_consent'] ) ) {
            $this->redirect_with_error( 'reg_error', 'pdpa_required' );
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

            update_user_meta( $user_id, 'cw_pdpa_consent', 'yes' );
            update_user_meta( $user_id, 'cw_pdpa_consent_at', current_time( 'mysql' ) );
            update_user_meta( $user_id, 'cw_pdpa_consent_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

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

        $requested = $redirect;
        if ( empty( $requested ) && ! empty( $req ) ) {
            $requested = $req;
        }

        if ( class_exists( 'CW_Guest_Join' ) ) {
            $guest_redirect = CW_Guest_Join::resolve_login_redirect( $requested, $user );
            if ( null !== $guest_redirect ) {
                return $guest_redirect;
            }
        }

        // --- NEW FIX: Read the Onboarding Complete Flag ---
        $is_onboarded = get_user_meta($user->ID, 'cw_onboarding_complete', true) === 'true';
        
        // Business (incl. administrator) / Creator / ONBOARDED Contestant -> Dashboard
        if ( class_exists( 'CW_Roles' ) && CW_Roles::is_business_user( $user ) ) {
            return home_url( '/my-account' );
        }

        if ( in_array( 'creator_role', $user->roles, true ) || $is_onboarded ) {
            return home_url( '/my-account' ); // Allowed to go to the dashboard
        }

        // Contestant (NOT Onboarded) -> Force 'Get Started'
        if ( in_array( 'contestant', $user->roles ) && ! $is_onboarded ) {
            return home_url( '/get-started' ); // Redirect to onboarding page
        }

        return home_url( '/my-account' ); // Fallback to dashboard
    }

    public function enforce_onboarding_access() {
        // Skip non-front-end contexts (AJAX, REST, cron, admin)
        if ( is_admin() || wp_doing_ajax()
            || ( defined( 'REST_REQUEST' ) && REST_REQUEST )
            || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
            return;
        }

        // 0. General protection for login/register pages
        if ( is_user_logged_in() && ( is_page( 'login' ) || is_page( 'registration' ) || is_page( 'complete-guest-registration' ) || $this->request_matches_path( [ '/complete-guest-registration' ] ) ) ) {
            wp_redirect( home_url( '/my-account' ) );
            exit;
        }

        if ( ! is_user_logged_in() ) return;

        $user = wp_get_current_user();

        // ── A) PROFILE-COMPLETION GATE ─────────────────────────────────────────
        // Forces any user missing birthdate or PDPA consent to /complete-profile
        // before they can access any other page. Skipped for staff (edit_posts+).
        $is_staff = user_can( $user, 'edit_posts' );

        if ( ! $is_staff && $this->user_needs_profile_completion( $user ) ) {
            $is_logout = ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'customer-logout' ) )
                      || ( isset( $_GET['action'] ) && $_GET['action'] === 'logout' );

            // URL-based whitelist (works even if the admin hasn't created the page yet — prevents redirect loops)
            $on_allowed_page = $this->request_matches_path( [ '/complete-profile', '/pdpa' ] )
                            || is_page( 'complete-profile' )
                            || is_page( 'pdpa' );

            if ( ! $is_logout && ! $on_allowed_page ) {
                wp_safe_redirect( home_url( '/complete-profile' ) );
                exit;
            }
            // On /complete-profile or /pdpa — let them stay so they can finish.
            return;
        }

        // If profile is complete, don't let them sit on /complete-profile.
        if ( ! $is_staff && ( is_page( 'complete-profile' ) || $this->request_matches_path( [ '/complete-profile' ] ) ) ) {
            wp_safe_redirect( home_url( '/my-account' ) );
            exit;
        }

        // ── B) ROLE / ONBOARDING FLOW ──────────────────────────────────────────
        $is_onboarded = get_user_meta($user->ID, 'cw_onboarding_complete', true) === 'true';
        
        // --- LOGIC 1: Enforce redirection TO /get-started/ ---
        // If the user is a Contestant AND has NOT yet completed the onboarding (no flag set)
        if ( in_array( 'contestant', (array) $user->roles, true ) && ! $is_onboarded
            && ( ! class_exists( 'CW_Roles' ) || ! CW_Roles::is_business_user( $user ) ) ) {
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

    /* ==========================================================================
       PROFILE-COMPLETION GATE — used for Google/Facebook signups and any
       legacy account that pre-dates the PDPA + birthdate requirements.
       ========================================================================== */
    private function user_needs_profile_completion( $user ) {
        if ( empty( $user ) || ! ( $user instanceof WP_User ) || ! $user->exists() ) {
            return false;
        }
        $birthdate = get_user_meta( $user->ID, 'birthdate', true );
        $pdpa      = get_user_meta( $user->ID, 'cw_pdpa_consent', true );
        return ( empty( $birthdate ) || empty( $pdpa ) );
    }

    /**
     * Check whether the current request path matches any of the given paths.
     * URL-based so it works even if a corresponding WP page doesn't exist yet
     * (prevents redirect loops while the admin hasn't created the page).
     *
     * @param string[] $paths e.g. [ '/complete-profile', '/pdpa' ]
     */
    private function request_matches_path( $paths ) {
        if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
            return false;
        }
        $path = wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
        $path = '/' . trim( (string) $path, '/' );

        foreach ( (array) $paths as $target ) {
            $target = '/' . trim( (string) $target, '/' );
            if ( $path === $target || strpos( $path, $target . '/' ) === 0 ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Admin notice: warn the admin when shortcode-required pages don't exist.
     * Prevents the redirect loop you'd otherwise hit on /complete-profile.
     */
    public function maybe_show_missing_pages_notice() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $required = [
            'complete-profile' => [
                'shortcode' => '[cw_complete_profile]',
                'title'     => __( 'Complete Profile', 'creativewings-core' ),
                'why'       => __( 'Used to collect birthdate + PDPA consent from social-signup users. Without this page, those users will hit a redirect loop on login.', 'creativewings-core' ),
            ],
            'reset-password' => [
                'shortcode' => '[cw_reset_password]',
                'title'     => __( 'Reset Password', 'creativewings-core' ),
                'why'       => __( 'Used as the destination for password-reset email links. Without this page, users can\'t reset their password via the new flow.', 'creativewings-core' ),
            ],
            'complete-guest-registration' => [
                'shortcode' => '[cw_complete_guest_registration]',
                'title'     => __( 'Complete Guest Registration', 'creativewings-core' ),
                'why'       => __( 'Used for guest joiners to finish account setup after payment via the email link.', 'creativewings-core' ),
            ],
            'pdpa' => [
                'shortcode' => '',
                'title'     => __( 'PDPA Notice', 'creativewings-core' ),
                'why'       => __( 'Linked from the signup form and the profile-completion page so users can read the consent terms before agreeing.', 'creativewings-core' ),
            ],
        ];

        $missing = [];
        foreach ( $required as $slug => $info ) {
            $page = get_page_by_path( $slug );
            if ( ! $page || $page->post_status !== 'publish' ) {
                $missing[ $slug ] = $info;
            }
        }

        if ( empty( $missing ) ) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Creative Wings:', 'creativewings-core' ) . '</strong> ' . esc_html__( 'The following pages are required but missing. Please create them in Pages → Add New.', 'creativewings-core' ) . '</p>';
        echo '<ul style="margin-left:18px;list-style:disc;">';
        foreach ( $missing as $slug => $info ) {
            $line = sprintf(
                /* translators: 1: page title, 2: page slug, 3: shortcode (optional) */
                __( '%1$s — slug <code>%2$s</code>', 'creativewings-core' ),
                '<strong>' . esc_html( $info['title'] ) . '</strong>',
                esc_html( $slug )
            );
            if ( ! empty( $info['shortcode'] ) ) {
                $line .= sprintf(
                    ' — ' . __( 'put %s in the content', 'creativewings-core' ),
                    '<code>' . esc_html( $info['shortcode'] ) . '</code>'
                );
            }
            echo '<li>' . $line . '<br><em style="color:#555555;">' . esc_html( $info['why'] ) . '</em></li>';
        }
        echo '</ul></div>';
    }

    /* ==========================================================================
       SHORTCODE: COMPLETE PROFILE FORM
       Renders only the fields the user is still missing (birthdate, PDPA).
       Used for users created via Nextend Social Login (Google/Facebook) and
       any legacy accounts without the data.
       ========================================================================== */
    public function render_complete_profile_form() {
        if ( ! is_user_logged_in() ) {
            return '<script>window.location.href="' . esc_url( home_url( '/login' ) ) . '";</script>';
        }

        $user              = wp_get_current_user();
        $missing_birthdate = empty( get_user_meta( $user->ID, 'birthdate', true ) );
        $missing_pdpa      = empty( get_user_meta( $user->ID, 'cw_pdpa_consent', true ) );

        if ( ! $missing_birthdate && ! $missing_pdpa ) {
            return '<script>window.location.href="' . esc_url( home_url( '/my-account' ) ) . '";</script>';
        }

        $pdpa_page = get_page_by_path( 'pdpa' );
        $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );

        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <h1 class="cw-auth-heading"><?php esc_html_e( 'One more step', 'creativewings-core' ); ?></h1>
                <p class="cw-complete-profile-subtitle">
                    <?php
                    printf(
                        /* translators: %s: user display name or email */
                        esc_html__( 'Welcome, %s! We just need a couple more details before you can continue.', 'creativewings-core' ),
                        '<strong>' . esc_html( $user->display_name ?: $user->user_email ) . '</strong>'
                    );
                    ?>
                </p>

                <?php $this->display_profile_messages(); ?>

                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-auth-form-v2">
                    <?php wp_nonce_field( 'cw_complete_profile_action', 'cw_complete_profile_nonce' ); ?>
                    <input type="hidden" name="action" value="cw_complete_profile">

                    <?php if ( $missing_birthdate ) : ?>
                        <div class="cw-input-group-v2">
                            <label class="cw-label" for="birthdate"><?php esc_html_e( 'Date of birth', 'creativewings-core' ); ?></label>
                            <div class="cw-input-icon-v2">
                                <i class="fas fa-calendar"></i>
                                <input type="text" id="birthdate" name="birthdate" required readonly placeholder="dd/mm/yyyy" class="cw-input-text-v2" autocomplete="bday">
                            </div>
                            <small style="color:#555555;font-size:12px;"><?php esc_html_e( 'Used for age category when joining campaigns.', 'creativewings-core' ); ?></small>
                        </div>
                    <?php endif; ?>

                    <?php if ( $missing_pdpa ) : ?>
                        <div class="cw-pdpa-consent">
                            <label class="cw-pdpa-consent-label" for="cw_pdpa_consent">
                                <input type="checkbox" id="cw_pdpa_consent" name="cw_pdpa_consent" value="1" required>
                                <span class="cw-pdpa-consent-text">
                                    <?php
                                    printf(
                                        /* translators: %s: link to PDPA notice page */
                                        esc_html__( 'I have read and agree to the %s. For applicants under 18, a parent or guardian gives permission to collect and use the information provided.', 'creativewings-core' ),
                                        '<a href="' . esc_url( $pdpa_url ) . '" target="_blank" rel="noopener" class="cw-pdpa-consent-link">' . esc_html__( 'Personal Data Protection (PDPA) Notice', 'creativewings-core' ) . '</a>'
                                    );
                                    ?>
                                    <span class="cw-pdpa-req" aria-hidden="true">*</span>
                                </span>
                            </label>
                        </div>
                    <?php endif; ?>

                    <button type="submit" class="cw-btn-primary cw-btn-full"><?php esc_html_e( 'Continue', 'creativewings-core' ); ?></button>

                    <div class="cw-auth-links-v2">
                        <a href="<?php echo esc_url( wp_logout_url( home_url() ) ); ?>" class="cw-link-sub"><?php esc_html_e( 'Log out', 'creativewings-core' ); ?></a>
                    </div>
                </form>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       HANDLER: COMPLETE PROFILE — saves birthdate + PDPA consent record
       ========================================================================== */
    public function process_complete_profile() {
        if ( ! is_user_logged_in() ) {
            wp_safe_redirect( home_url( '/login' ) );
            exit;
        }

        if ( ! isset( $_POST['cw_complete_profile_nonce'] )
            || ! wp_verify_nonce( $_POST['cw_complete_profile_nonce'], 'cw_complete_profile_action' ) ) {
            wp_safe_redirect( add_query_arg( 'profile_error', 'security', home_url( '/complete-profile' ) ) );
            exit;
        }

        $user_id           = get_current_user_id();
        $user              = wp_get_current_user();
        $missing_birthdate = empty( get_user_meta( $user_id, 'birthdate', true ) );
        $missing_pdpa      = empty( get_user_meta( $user_id, 'cw_pdpa_consent', true ) );

        // Validate birthdate only if it was actually needed
        if ( $missing_birthdate ) {
            $birthdate = isset( $_POST['birthdate'] ) ? sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) : '';
            if ( empty( $birthdate ) ) {
                wp_safe_redirect( add_query_arg( 'profile_error', 'birthdate_required', home_url( '/complete-profile' ) ) );
                exit;
            }
            update_user_meta( $user_id, 'birthdate', $birthdate );
        }

        // Validate PDPA only if it was actually needed
        if ( $missing_pdpa ) {
            if ( empty( $_POST['cw_pdpa_consent'] ) ) {
                wp_safe_redirect( add_query_arg( 'profile_error', 'pdpa_required', home_url( '/complete-profile' ) ) );
                exit;
            }
            update_user_meta( $user_id, 'cw_pdpa_consent', 'yes' );
            update_user_meta( $user_id, 'cw_pdpa_consent_at', current_time( 'mysql' ) );
            update_user_meta( $user_id, 'cw_pdpa_consent_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );
        }

        // Route the user onward: contestants without onboarding → /get-started, otherwise → /my-account
        $is_onboarded = get_user_meta( $user_id, 'cw_onboarding_complete', true ) === 'true';
        if ( in_array( 'contestant', (array) $user->roles, true ) && ! $is_onboarded
            && ( ! class_exists( 'CW_Roles' ) || ! CW_Roles::is_business_user( $user ) ) ) {
            wp_safe_redirect( home_url( '/get-started' ) );
        } else {
            wp_safe_redirect( home_url( '/my-account' ) );
        }
        exit;
    }

    private function display_profile_messages() {
        if ( ! isset( $_GET['profile_error'] ) ) {
            return;
        }

        switch ( $_GET['profile_error'] ) {
            case 'birthdate_required':
                $msg = __( 'Please enter your date of birth.', 'creativewings-core' );
                break;
            case 'pdpa_required':
                $msg = __( 'Please tick the PDPA consent box to continue. For applicants under 18, a parent or guardian must give consent.', 'creativewings-core' );
                break;
            case 'security':
                $msg = __( 'Security check failed. Please try again.', 'creativewings-core' );
                break;
            default:
                $msg = __( 'An error occurred. Please try again.', 'creativewings-core' );
        }

        if ( $msg ) {
            echo '<div class="cw-alert error">' . esc_html( $msg ) . '</div>';
        }
    }

    public function login_page_redirect() {
        global $pagenow;
        if ( 'wp-login.php' != $pagenow ) {
            return;
        }

        // /wp-login.php with no action → custom login page
        if ( $_SERVER['REQUEST_METHOD'] == 'GET' && ! isset( $_GET['action'] ) ) {
            wp_redirect( home_url( '/login' ) );
            exit;
        }

        // /wp-login.php?action=rp|resetpass → custom reset-password page
        // (handles old email links pointing to wp-login.php as well as WP's own redirects)
        if ( $_SERVER['REQUEST_METHOD'] == 'GET'
            && isset( $_GET['action'] )
            && in_array( $_GET['action'], [ 'rp', 'resetpass' ], true ) ) {

            $key   = isset( $_GET['key'] )   ? sanitize_text_field( wp_unslash( $_GET['key'] ) )       : '';
            $login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ), true )     : '';

            $target = home_url( '/reset-password' );
            if ( $key !== '' && $login !== '' ) {
                $target = add_query_arg( [
                    'key'   => $key,
                    'login' => rawurlencode( $login ),
                ], $target );
            }

            wp_safe_redirect( $target );
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
            if ( is_wp_error( $key ) ) {
                wp_redirect( add_query_arg( 'reset', 'invalid', wp_get_referer() ) );
                exit;
            }
            $reset_url = add_query_arg(
                [
                    'key'   => $key,
                    'login' => rawurlencode( $user->user_login ),
                ],
                home_url( '/reset-password' )
            );

            $subject = sprintf( __( 'Reset your %s password', 'creativewings-core' ), 'Creative Wings' );
            $message = $this->build_reset_password_email_html( $user, $reset_url );
            $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

            wp_mail( $email, $subject, $message, $headers );

            wp_redirect( add_query_arg( 'reset', 'success', wp_get_referer() ) );
        } else {
            wp_redirect( add_query_arg( 'reset', 'invalid', wp_get_referer() ) );
        }
        exit;
    }

    /* ==========================================================================
       EMAIL BRANDING — From / From Name / HTML template
       Overrides only the default WordPress values so plugins with custom
       from addresses (WooCommerce etc.) keep working.
       ========================================================================== */
    public function mail_from_address( $email ) {
        $default_prefix = 'wordpress@';
        if ( is_string( $email ) && strpos( $email, $default_prefix ) === 0 ) {
            $host = wp_parse_url( home_url(), PHP_URL_HOST );
            $host = preg_replace( '/^www\./i', '', (string) $host );
            return 'no-reply@' . $host;
        }
        return $email;
    }

    public function mail_from_name( $name ) {
        if ( $name === 'WordPress' || $name === '' ) {
            return 'Creative Wings';
        }
        return $name;
    }

    /**
     * Branded HTML for WP's native password reset email
     * (fires for wp-login.php?action=lostpassword and WC "Lost password?")
     */
    /* ==========================================================================
       SHORTCODE: SET NEW PASSWORD (replaces wp-login.php?action=rp)
       Validates the key from the URL, renders pass1 + pass2 form, then
       saves the new password via reset_password().
       ========================================================================== */
    public function render_reset_password_form() {
        $key   = isset( $_GET['key'] )   ? sanitize_text_field( wp_unslash( $_GET['key'] ) )     : '';
        $login = isset( $_GET['login'] ) ? sanitize_user( wp_unslash( $_GET['login'] ), true )   : '';

        // Wrap-and-return helper so every error path renders the same shell
        $card = function( $heading, $alert_html, $links_html = '' ) {
            return
                '<div class="cw-auth-wrapper"><div class="cw-auth-card cw-card-nova">'
                . '<h1 class="cw-auth-heading">' . esc_html( $heading ) . '</h1>'
                . $alert_html
                . ( $links_html ? '<div class="cw-auth-links-v2">' . $links_html . '</div>' : '' )
                . '</div></div>';
        };

        if ( $key === '' || $login === '' ) {
            return $card(
                __( 'Invalid Reset Link', 'creativewings-core' ),
                '<div class="cw-alert error">' . esc_html__( 'This password reset link is missing required information. Please request a new one.', 'creativewings-core' ) . '</div>',
                '<a href="' . esc_url( home_url( '/forgot-password' ) ) . '" class="cw-link-main">' . esc_html__( 'Request new link', 'creativewings-core' ) . '</a>'
            );
        }

        $user_or_error = check_password_reset_key( $key, $login );
        if ( is_wp_error( $user_or_error ) ) {
            return $card(
                __( 'Link Expired', 'creativewings-core' ),
                '<div class="cw-alert error">' . esc_html__( 'This password reset link is invalid or has expired. Please request a new one.', 'creativewings-core' ) . '</div>',
                '<a href="' . esc_url( home_url( '/forgot-password' ) ) . '" class="cw-link-main">' . esc_html__( 'Request new link', 'creativewings-core' ) . '</a>'
            );
        }

        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <h1 class="cw-auth-heading"><?php esc_html_e( 'Set New Password', 'creativewings-core' ); ?></h1>

                <?php $this->display_reset_messages(); ?>

                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-auth-form-v2" id="cw-reset-password-form">
                    <?php wp_nonce_field( 'cw_reset_pwd_action', 'cw_reset_pwd_nonce' ); ?>
                    <input type="hidden" name="action" value="cw_reset_password">
                    <input type="hidden" name="key"   value="<?php echo esc_attr( $key );   ?>">
                    <input type="hidden" name="login" value="<?php echo esc_attr( $login ); ?>">

                    <div class="cw-input-group-v2">
                        <label class="cw-label" for="cw_pass1"><?php esc_html_e( 'New Password', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="cw_pass1" name="pass1" required minlength="8" placeholder="<?php esc_attr_e( 'Enter new password', 'creativewings-core' ); ?>" class="cw-input-text-v2" autocomplete="new-password">
                        </div>
                        <small style="color:#555555;font-size:12px;"><?php esc_html_e( 'Use 8+ chars, numbers & symbols.', 'creativewings-core' ); ?></small>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label" for="cw_pass2"><?php esc_html_e( 'Confirm New Password', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="cw_pass2" name="pass2" required minlength="8" placeholder="<?php esc_attr_e( 'Re-enter new password', 'creativewings-core' ); ?>" class="cw-input-text-v2" autocomplete="new-password">
                        </div>
                        <small id="cw_pass_match_msg" style="display:none;color:#dc2626;font-size:12px;"><?php esc_html_e( 'Passwords do not match.', 'creativewings-core' ); ?></small>
                    </div>

                    <button type="submit" class="cw-btn-primary cw-btn-full"><?php esc_html_e( 'Reset Password', 'creativewings-core' ); ?></button>

                    <div class="cw-auth-links-v2">
                        <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="cw-link-sub"><?php esc_html_e( 'Back to Login', 'creativewings-core' ); ?></a>
                    </div>
                </form>

                <script>
                (function(){
                    var form  = document.getElementById('cw-reset-password-form');
                    if (!form) return;
                    var p1    = document.getElementById('cw_pass1');
                    var p2    = document.getElementById('cw_pass2');
                    var note  = document.getElementById('cw_pass_match_msg');
                    function check() {
                        if (p2.value === '') { note.style.display = 'none'; p2.setCustomValidity(''); return; }
                        if (p1.value !== p2.value) {
                            note.style.display = 'block';
                            p2.setCustomValidity('<?php echo esc_js( __( 'Passwords do not match.', 'creativewings-core' ) ); ?>');
                        } else {
                            note.style.display = 'none';
                            p2.setCustomValidity('');
                        }
                    }
                    p1.addEventListener('input', check);
                    p2.addEventListener('input', check);
                })();
                </script>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       HANDLER: SAVE NEW PASSWORD
       ========================================================================== */
    public function process_reset_password() {
        if ( ! isset( $_POST['cw_reset_pwd_nonce'] )
            || ! wp_verify_nonce( $_POST['cw_reset_pwd_nonce'], 'cw_reset_pwd_action' ) ) {
            wp_safe_redirect( add_query_arg( 'reset', 'expired', home_url( '/login' ) ) );
            exit;
        }

        $key   = isset( $_POST['key'] )   ? sanitize_text_field( wp_unslash( $_POST['key'] ) )       : '';
        $login = isset( $_POST['login'] ) ? sanitize_user( wp_unslash( $_POST['login'] ), true )     : '';

        if ( $key === '' || $login === '' ) {
            wp_safe_redirect( add_query_arg( 'reset', 'expired', home_url( '/login' ) ) );
            exit;
        }

        $user_or_error = check_password_reset_key( $key, $login );
        if ( is_wp_error( $user_or_error ) ) {
            wp_safe_redirect( add_query_arg( 'reset', 'expired', home_url( '/login' ) ) );
            exit;
        }
        $user = $user_or_error;

        // Don't sanitize the passwords — that can mangle valid characters.
        $pass1 = isset( $_POST['pass1'] ) ? (string) wp_unslash( $_POST['pass1'] ) : '';
        $pass2 = isset( $_POST['pass2'] ) ? (string) wp_unslash( $_POST['pass2'] ) : '';

        $back_to_form = function( $code ) use ( $key, $login ) {
            wp_safe_redirect( add_query_arg(
                [
                    'reset_error' => $code,
                    'key'         => $key,
                    'login'       => rawurlencode( $login ),
                ],
                home_url( '/reset-password' )
            ) );
            exit;
        };

        if ( $pass1 === '' ) { $back_to_form( 'pass_empty' ); }
        if ( strlen( $pass1 ) < 8 ) { $back_to_form( 'pass_short' ); }
        if ( $pass1 !== $pass2 ) { $back_to_form( 'pass_mismatch' ); }

        reset_password( $user, $pass1 );

        wp_safe_redirect( add_query_arg( 'reset', 'complete', home_url( '/login' ) ) );
        exit;
    }

    private function display_reset_messages() {
        if ( ! isset( $_GET['reset_error'] ) ) {
            return;
        }

        switch ( $_GET['reset_error'] ) {
            case 'pass_empty':
                $msg = __( 'Please enter your new password.', 'creativewings-core' );
                break;
            case 'pass_short':
                $msg = __( 'Password must be at least 8 characters long.', 'creativewings-core' );
                break;
            case 'pass_mismatch':
                $msg = __( 'Passwords do not match. Please re-enter them.', 'creativewings-core' );
                break;
            default:
                $msg = __( 'An error occurred. Please try again.', 'creativewings-core' );
        }

        echo '<div class="cw-alert error">' . esc_html( $msg ) . '</div>';
    }

    /* ==========================================================================
       SHORTCODE: COMPLETE GUEST REGISTRATION (post-payment email link)
       ========================================================================== */
    public function render_complete_guest_registration_form() {
        if ( is_user_logged_in() ) {
            return '<script>window.location.href="' . esc_url( home_url( '/my-account' ) ) . '";</script>';
        }

        $order_id = isset( $_GET['cw_guest_order'] ) ? absint( $_GET['cw_guest_order'] ) : 0;
        $token    = isset( $_GET['cw_guest_token'] ) ? sanitize_text_field( wp_unslash( $_GET['cw_guest_token'] ) ) : '';

        if ( ! $order_id || ! $token || ! class_exists( 'CW_Guest_Join' ) || ! CW_Guest_Join::verify_completion_token( $order_id, $token ) ) {
            return $this->render_static_auth_message(
                __( 'Invalid or expired link', 'creativewings-core' ),
                __( 'This registration link is invalid, expired, or already used.', 'creativewings-core' ),
                '<a href="' . esc_url( home_url( '/login' ) ) . '" class="cw-link-main">' . esc_html__( 'Log in', 'creativewings-core' ) . '</a>'
            );
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return $this->render_static_auth_message(
                __( 'Invalid or expired link', 'creativewings-core' ),
                __( 'This registration link is invalid, expired, or already used.', 'creativewings-core' )
            );
        }

        $email = sanitize_email( $order->get_billing_email() );
        $name  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $dob   = (string) $order->get_meta( CW_Guest_Join::ORDER_META_DOB );

        if ( $email && email_exists( $email ) ) {
            return $this->render_static_auth_message(
                __( 'Account already exists', 'creativewings-core' ),
                __( 'This email already has an account. Please log in to access your registration.', 'creativewings-core' ),
                '<a href="' . esc_url( home_url( '/login' ) ) . '" class="cw-link-main">' . esc_html__( 'Log in', 'creativewings-core' ) . '</a>'
            );
        }

        $pdpa_page = get_page_by_path( 'pdpa' );
        $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );

        ob_start();
        ?>
        <div class="cw-auth-wrapper">
            <div class="cw-auth-card cw-card-nova">
                <h1 class="cw-auth-heading"><?php esc_html_e( 'Complete your registration', 'creativewings-core' ); ?></h1>
                <p class="cw-complete-profile-subtitle">
                    <?php esc_html_e( 'Your campaign entry is already submitted. Choose a password to finish setting up your account.', 'creativewings-core' ); ?>
                </p>

                <?php $this->display_guest_complete_messages(); ?>

                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-auth-form-v2" id="cw-guest-complete-form">
                    <?php wp_nonce_field( 'cw_complete_guest_reg_action', 'cw_complete_guest_reg_nonce' ); ?>
                    <input type="hidden" name="action" value="cw_complete_guest_registration">
                    <input type="hidden" name="cw_guest_order" value="<?php echo esc_attr( (string) $order_id ); ?>">
                    <input type="hidden" name="cw_guest_token" value="<?php echo esc_attr( $token ); ?>">

                    <div class="cw-input-group-v2">
                        <label class="cw-label"><?php esc_html_e( 'Email address', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-envelope"></i>
                            <input type="email" value="<?php echo esc_attr( $email ); ?>" readonly class="cw-input-text-v2 cw-input-readonly">
                        </div>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label"><?php esc_html_e( 'Full name', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-user"></i>
                            <input type="text" value="<?php echo esc_attr( $name ); ?>" readonly class="cw-input-text-v2 cw-input-readonly">
                        </div>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label"><?php esc_html_e( 'Date of birth', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-calendar"></i>
                            <input type="text" value="<?php echo esc_attr( $dob ); ?>" readonly class="cw-input-text-v2 cw-input-readonly">
                        </div>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label" for="cw_guest_password"><?php esc_html_e( 'Password', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="cw_guest_password" name="cw_password" required minlength="8" placeholder="<?php esc_attr_e( 'Choose a password', 'creativewings-core' ); ?>" class="cw-input-text-v2" autocomplete="new-password">
                        </div>
                        <small style="color:#555555;font-size:12px;"><?php esc_html_e( 'Use 8+ chars, numbers & symbols.', 'creativewings-core' ); ?></small>
                    </div>

                    <div class="cw-input-group-v2">
                        <label class="cw-label" for="cw_guest_password_confirm"><?php esc_html_e( 'Confirm password', 'creativewings-core' ); ?></label>
                        <div class="cw-input-icon-v2">
                            <i class="fas fa-lock"></i>
                            <input type="password" id="cw_guest_password_confirm" name="cw_password_confirm" required minlength="8" placeholder="<?php esc_attr_e( 'Re-enter password', 'creativewings-core' ); ?>" class="cw-input-text-v2" autocomplete="new-password">
                        </div>
                        <small id="cw_guest_pass_match_msg" style="display:none;color:#dc2626;font-size:12px;"><?php esc_html_e( 'Passwords do not match.', 'creativewings-core' ); ?></small>
                    </div>

                    <div class="cw-pdpa-consent">
                        <label class="cw-pdpa-consent-label" for="cw_pdpa_consent">
                            <input type="checkbox" id="cw_pdpa_consent" name="cw_pdpa_consent" value="1" required>
                            <span class="cw-pdpa-consent-text">
                                <?php
                                printf(
                                    /* translators: %s: link to PDPA notice page */
                                    esc_html__( 'I have read and agree to the %s. For applicants under 18, a parent or guardian gives permission to collect and use the information provided.', 'creativewings-core' ),
                                    '<a href="' . esc_url( $pdpa_url ) . '" target="_blank" rel="noopener" class="cw-pdpa-consent-link">' . esc_html__( 'Personal Data Protection (PDPA) Notice', 'creativewings-core' ) . '</a>'
                                );
                                ?>
                                <span class="cw-pdpa-req" aria-hidden="true">*</span>
                            </span>
                        </label>
                    </div>

                    <button type="submit" class="cw-btn-primary cw-btn-full"><?php esc_html_e( 'Create account', 'creativewings-core' ); ?></button>

                    <div class="cw-auth-links-v2">
                        <a href="<?php echo esc_url( home_url( '/login' ) ); ?>" class="cw-link-sub"><?php esc_html_e( 'Already have an account? Log in', 'creativewings-core' ); ?></a>
                    </div>
                </form>

                <script>
                (function(){
                    var form  = document.getElementById('cw-guest-complete-form');
                    if (!form) return;
                    var p1    = document.getElementById('cw_guest_password');
                    var p2    = document.getElementById('cw_guest_password_confirm');
                    var note  = document.getElementById('cw_guest_pass_match_msg');
                    function check() {
                        if (p2.value === '') { note.style.display = 'none'; p2.setCustomValidity(''); return; }
                        if (p1.value !== p2.value) {
                            note.style.display = 'block';
                            p2.setCustomValidity('<?php echo esc_js( __( 'Passwords do not match.', 'creativewings-core' ) ); ?>');
                        } else {
                            note.style.display = 'none';
                            p2.setCustomValidity('');
                        }
                    }
                    p1.addEventListener('input', check);
                    p2.addEventListener('input', check);
                })();
                </script>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       HANDLER: COMPLETE GUEST REGISTRATION
       ========================================================================== */
    public function process_complete_guest_registration() {
        if ( is_user_logged_in() ) {
            wp_safe_redirect( home_url( '/my-account' ) );
            exit;
        }

        $form_url = $this->get_complete_guest_registration_url();

        if ( ! isset( $_POST['cw_complete_guest_reg_nonce'] )
            || ! wp_verify_nonce( $_POST['cw_complete_guest_reg_nonce'], 'cw_complete_guest_reg_action' ) ) {
            wp_safe_redirect( add_query_arg(
                [
                    'guest_reg_error' => 'security',
                    'cw_guest_order'  => isset( $_POST['cw_guest_order'] ) ? absint( $_POST['cw_guest_order'] ) : 0,
                    'cw_guest_token'  => isset( $_POST['cw_guest_token'] ) ? rawurlencode( sanitize_text_field( wp_unslash( $_POST['cw_guest_token'] ) ) ) : '',
                ],
                $this->get_complete_guest_registration_url()
            ) );
            exit;
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION, 10, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_safe_redirect( add_query_arg(
                    [
                        'guest_reg_error' => 'rate_limit',
                        'cw_guest_order'  => isset( $_POST['cw_guest_order'] ) ? absint( $_POST['cw_guest_order'] ) : 0,
                        'cw_guest_token'  => isset( $_POST['cw_guest_token'] ) ? rawurlencode( sanitize_text_field( wp_unslash( $_POST['cw_guest_token'] ) ) ) : '',
                    ],
                    $this->get_complete_guest_registration_url()
                ) );
                exit;
            }
        }

        $order_id = isset( $_POST['cw_guest_order'] ) ? absint( $_POST['cw_guest_order'] ) : 0;
        $token    = isset( $_POST['cw_guest_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_token'] ) ) : '';

        if ( ! $order_id || ! $token || ! class_exists( 'CW_Guest_Join' ) || ! CW_Guest_Join::verify_completion_token( $order_id, $token ) ) {
            wp_safe_redirect( add_query_arg( 'guest_reg_error', 'invalid_link', $form_url ) );
            exit;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            wp_safe_redirect( add_query_arg( 'guest_reg_error', 'invalid_link', $form_url ) );
            exit;
        }

        $email = sanitize_email( $order->get_billing_email() );
        if ( ! $email || ! is_email( $email ) ) {
            wp_safe_redirect( add_query_arg( 'guest_reg_error', 'invalid_link', $form_url ) );
            exit;
        }

        if ( email_exists( $email ) ) {
            wp_safe_redirect( add_query_arg( 'login', 'guest_email_exists', home_url( '/login' ) ) );
            exit;
        }

        $pass  = isset( $_POST['cw_password'] ) ? (string) wp_unslash( $_POST['cw_password'] ) : '';
        $pass2 = isset( $_POST['cw_password_confirm'] ) ? (string) wp_unslash( $_POST['cw_password_confirm'] ) : '';

        $back_to_form = function( $code ) use ( $order_id, $token ) {
            wp_safe_redirect( add_query_arg(
                [
                    'guest_reg_error' => $code,
                    'cw_guest_order'  => $order_id,
                    'cw_guest_token'  => rawurlencode( $token ),
                ],
                $this->get_complete_guest_registration_url()
            ) );
            exit;
        };

        if ( '' === $pass ) {
            $back_to_form( 'pass_empty' );
        }
        if ( strlen( $pass ) < 8 ) {
            $back_to_form( 'pass_short' );
        }
        if ( $pass !== $pass2 ) {
            $back_to_form( 'pass_mismatch' );
        }

        if ( empty( $_POST['cw_pdpa_consent'] ) ) {
            $back_to_form( 'pdpa_required' );
        }

        $full_name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        $birthdate = sanitize_text_field( (string) $order->get_meta( CW_Guest_Join::ORDER_META_DOB ) );

        $username = explode( '@', $email )[0] . rand( 10, 999 );
        if ( strlen( $username ) < 4 ) {
            $back_to_form( 'generic' );
        }

        $user_id = wp_create_user( $username, $pass, $email );
        if ( is_wp_error( $user_id ) ) {
            $back_to_form( 'generic' );
        }

        $parts = preg_split( '/\s+/', trim( $full_name ), 2 );
        $first = $parts[0] ?? $full_name;
        $last  = $parts[1] ?? '';

        update_user_meta( $user_id, 'cw_full_name', $full_name );
        update_user_meta( $user_id, 'birthdate', $birthdate );
        update_user_meta( $user_id, 'first_name', $first );
        update_user_meta( $user_id, 'last_name', $last );
        update_user_meta( $user_id, 'account_type', 'contestant' );
        update_user_meta( $user_id, 'cw_pdpa_consent', 'yes' );
        update_user_meta( $user_id, 'cw_pdpa_consent_at', current_time( 'mysql' ) );
        update_user_meta( $user_id, 'cw_pdpa_consent_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        wp_update_user( [
            'ID'           => $user_id,
            'display_name' => $full_name,
            'first_name'   => $first,
            'last_name'    => $last,
        ] );

        $user = new WP_User( $user_id );
        $user->set_role( 'contestant' );

        if ( ! CW_Guest_Join::attach_order_to_user( $order_id, $user_id ) ) {
            wp_delete_user( $user_id );
            $back_to_form( 'generic' );
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id );

        $account_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'myaccount' ) : home_url( '/my-account' );
        wp_safe_redirect( $account_url );
        exit;
    }

    private function get_complete_guest_registration_url() {
        $page = get_page_by_path( 'complete-guest-registration' );
        if ( $page && 'publish' === $page->post_status ) {
            return get_permalink( $page );
        }

        return home_url( '/complete-guest-registration/' );
    }

    private function render_static_auth_message( $heading, $message, $links_html = '' ) {
        return
            '<div class="cw-auth-wrapper"><div class="cw-auth-card cw-card-nova">'
            . '<h1 class="cw-auth-heading">' . esc_html( $heading ) . '</h1>'
            . '<div class="cw-alert error">' . esc_html( $message ) . '</div>'
            . ( $links_html ? '<div class="cw-auth-links-v2">' . $links_html . '</div>' : '' )
            . '</div></div>';
    }

    private function display_guest_complete_messages() {
        if ( ! isset( $_GET['guest_reg_error'] ) ) {
            return;
        }

        switch ( sanitize_key( wp_unslash( $_GET['guest_reg_error'] ) ) ) {
            case 'pass_empty':
                $msg = __( 'Please enter your password.', 'creativewings-core' );
                break;
            case 'pass_short':
                $msg = __( 'Password must be at least 8 characters long.', 'creativewings-core' );
                break;
            case 'pass_mismatch':
                $msg = __( 'Passwords do not match. Please re-enter them.', 'creativewings-core' );
                break;
            case 'pdpa_required':
                $msg = __( 'Please tick the PDPA consent box to continue. For applicants under 18, a parent or guardian must give consent.', 'creativewings-core' );
                break;
            case 'security':
                $msg = __( 'Security check failed. Please try again.', 'creativewings-core' );
                break;
            case 'rate_limit':
                $msg = __( 'Too many attempts. Please wait and try again.', 'creativewings-core' );
                break;
            case 'invalid_link':
                $msg = __( 'This registration link is invalid, expired, or already used.', 'creativewings-core' );
                break;
            default:
                $msg = __( 'An error occurred. Please try again.', 'creativewings-core' );
        }

        echo '<div class="cw-alert error">' . esc_html( $msg ) . '</div>';
    }

    public function customize_password_reset_email( $defaults, $key, $user_login, $user_data ) {
        $reset_url = add_query_arg(
            [
                'key'   => $key,
                'login' => rawurlencode( $user_login ),
            ],
            home_url( '/reset-password' )
        );

        $defaults['subject'] = sprintf( __( 'Reset your %s password', 'creativewings-core' ), 'Creative Wings' );
        $defaults['message'] = $this->build_reset_password_email_html( $user_data, $reset_url );

        // Ensure HTML content-type header
        $headers = isset( $defaults['headers'] ) ? (array) $defaults['headers'] : [];
        $has_content_type = false;
        foreach ( $headers as $h ) {
            if ( stripos( (string) $h, 'content-type:' ) === 0 ) {
                $has_content_type = true;
                break;
            }
        }
        if ( ! $has_content_type ) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        }
        $defaults['headers'] = $headers;

        return $defaults;
    }

    /**
     * Build the password-reset email body wrapped in the Creative Wings template.
     */
    private function build_reset_password_email_html( $user, $reset_url ) {
        $name      = '';
        if ( $user instanceof WP_User ) {
            $name = $user->display_name ? $user->display_name : $user->user_login;
        } elseif ( is_object( $user ) && isset( $user->user_login ) ) {
            $name = $user->display_name ?? $user->user_login;
        }
        $name_esc = esc_html( $name );
        $url_esc  = esc_url( $reset_url );

        $hi_line    = sprintf( __( 'Hi %s,', 'creativewings-core' ), '<strong>' . $name_esc . '</strong>' );
        $intro      = esc_html__( 'We received a request to reset the password for your Creative Wings account. Click the button below to choose a new password.', 'creativewings-core' );
        $cta_label  = esc_html__( 'Reset Password', 'creativewings-core' );
        $fallback   = esc_html__( "If the button doesn't work, copy this link into your browser:", 'creativewings-core' );
        $disclaimer = esc_html__( "If you didn't request this, you can safely ignore this email — your password will stay the same.", 'creativewings-core' );

        $body  = '<p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">' . $hi_line . '</p>';
        $body .= '<p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">' . $intro . '</p>';
        $body .= '<p style="text-align:center;margin:28px 0;"><a href="' . $url_esc . '" style="display:inline-block;background:#125B9A;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:10px;font-weight:700;font-size:15px;line-height:1;">' . $cta_label . '</a></p>';
        $body .= '<p style="margin:0 0 10px;font-size:13px;line-height:1.65;color:#555555;">' . $fallback . '</p>';
        $body .= '<p style="margin:0 0 22px;font-size:13px;line-height:1.65;color:#125B9A;word-break:break-all;"><a href="' . $url_esc . '" style="color:#125B9A;">' . esc_html( $reset_url ) . '</a></p>';
        $body .= '<p style="margin:24px 0 0;padding:14px 16px;background:#f1f5f9;border-radius:10px;font-size:13px;line-height:1.6;color:#555555;">' . $disclaimer . '</p>';

        return $this->email_template_wrap( [
            'heading' => __( 'Reset your password', 'creativewings-core' ),
            'body'    => $body,
        ] );
    }

    /**
     * Reusable Creative Wings email shell.
     * Accepts: ['heading' => string, 'body' => HTML]
     * Uses table-based layout + inline styles for max email-client compatibility.
     */
    private function email_template_wrap( $args ) {
        $heading  = isset( $args['heading'] ) ? $args['heading'] : '';
        $body     = isset( $args['body'] )    ? $args['body']    : '';
        $year     = date( 'Y' );
        $site_url = esc_url( home_url() );
        $heading_esc = esc_html( $heading );
        $brand    = 'Creative Wings';

        $footer_copy = sprintf(
            /* translators: 1: year, 2: brand name */
            esc_html__( '© %1$d %2$s. All rights reserved.', 'creativewings-core' ),
            $year,
            $brand
        );
        $footer_note = esc_html__( "This is an automated message — please don't reply directly.", 'creativewings-core' );

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$heading_esc}</title>
</head>
<body style="margin:0;padding:0;background:#F8F9FB;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1A1A1A;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#F8F9FB;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8edf2;">
                    <tr>
                        <td style="background:#125B9A;padding:28px 32px;text-align:center;">
                            <a href="{$site_url}" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:800;letter-spacing:-0.3px;">{$brand}</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px 32px 28px;">
                            <h2 style="margin:0 0 18px;font-size:22px;font-weight:800;color:#1A1A1A;letter-spacing:-0.2px;">{$heading_esc}</h2>
                            {$body}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fafc;padding:20px 32px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e8edf2;">
                            <p style="margin:0;">{$footer_copy}</p>
                            <p style="margin:6px 0 0;">{$footer_note}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
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
                case 'pdpa_required':  $msg = 'Please tick the PDPA consent box to continue. For applicants under 18, a parent or guardian must give consent.'; break;
                case 'generic':        $msg = 'An error occurred. Please try again.'; break;
            }
            if ( $msg ) echo '<div class="cw-alert error">' . esc_html( $msg ) . '</div>';
        }

        if ( isset( $_GET['login'] ) && $_GET['login'] === 'failed' ) {
            echo '<div class="cw-alert error">' . __('Invalid email or password.', 'creativewings-core') . '</div>';
        }

        if ( isset( $_GET['login'] ) && $_GET['login'] === 'guest_email_exists' ) {
            echo '<div class="cw-alert error">' . esc_html__( 'This email already has an account. Please log in with your existing password.', 'creativewings-core' ) . '</div>';
        }

        if ( isset( $_GET['reset'] ) ) {
            if ( $_GET['reset'] === 'success' ) {
                echo '<div class="cw-alert success">' . __('Check your email for the reset link.', 'creativewings-core') . '</div>';
            } elseif ( $_GET['reset'] === 'invalid' ) {
                echo '<div class="cw-alert error">' . __('Email not found.', 'creativewings-core') . '</div>';
            } elseif ( $_GET['reset'] === 'complete' ) {
                echo '<div class="cw-alert success">' . __('Your password has been reset. Please log in with your new password.', 'creativewings-core') . '</div>';
            } elseif ( $_GET['reset'] === 'expired' ) {
                echo '<div class="cw-alert error">' . __('Your password reset link has expired. Please request a new one.', 'creativewings-core') . '</div>';
            }
        }
    }
}