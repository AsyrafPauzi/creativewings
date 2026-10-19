<?php
/**
 * B1: Guest school-coupon → register/login popup (cart + checkout).
 */
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Coupon_Auth_Popup {

    const NONCE_ACTION = 'cw_coupon_auth_popup';

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 35 );
        add_action( 'wp_footer', [ $this, 'render_modal_markup' ], 40 );

        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'reject_guest_school_coupon' ], 5, 3 );

        add_action( 'wp_ajax_cw_coupon_requires_auth', [ $this, 'ajax_coupon_requires_auth' ] );
        add_action( 'wp_ajax_nopriv_cw_coupon_requires_auth', [ $this, 'ajax_coupon_requires_auth' ] );

        add_action( 'wp_ajax_cw_checkout_register', [ $this, 'ajax_checkout_register' ] );
        add_action( 'wp_ajax_nopriv_cw_checkout_register', [ $this, 'ajax_checkout_register' ] );

        add_action( 'wp_ajax_cw_checkout_login', [ $this, 'ajax_checkout_login' ] );
        add_action( 'wp_ajax_nopriv_cw_checkout_login', [ $this, 'ajax_checkout_login' ] );
    }

    /**
     * Cart or checkout (not thank-you), guest only.
     */
    private function should_load_popup() {
        if ( is_user_logged_in() ) {
            return false;
        }
        if ( ! function_exists( 'is_cart' ) || ! function_exists( 'is_checkout' ) ) {
            return false;
        }
        if ( is_cart() ) {
            return true;
        }
        if ( is_checkout() && ( ! function_exists( 'is_order_received_page' ) || ! is_order_received_page() ) ) {
            return true;
        }
        return false;
    }

    /**
     * Server safety net: guests cannot apply CW school coupons without auth.
     *
     * @param bool      $valid
     * @param WC_Coupon $coupon
     * @param mixed     $discount
     * @return bool
     */
    public function reject_guest_school_coupon( $valid, $coupon, $discount ) {
        if ( ! $valid || is_user_logged_in() || ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $valid;
        }
        $code = $coupon->get_code();
        if ( ! class_exists( 'CW_Sponsor_Coupons' ) || ! CW_Sponsor_Coupons::is_cw_school_coupon( $code ) ) {
            return $valid;
        }
        $login = wp_login_url();
        $reg   = home_url( '/registration/' );
        throw new Exception(
            sprintf(
                /* translators: 1: login URL 2: register URL */
                __( 'School coupons require an account. Please <a href="%1$s">log in</a> or <a href="%2$s">register</a>, then apply your coupon.', 'creativewings-core' ),
                esc_url( $login ),
                esc_url( $reg )
            )
        );
    }

    public function enqueue_assets() {
        if ( ! $this->should_load_popup() ) {
            return;
        }

        wp_enqueue_script(
            'cw-coupon-auth-popup',
            CW_URL . 'assets/js/cw-coupon-auth-popup.js',
            [ 'jquery' ],
            defined( 'CW_VERSION' ) ? CW_VERSION : '1.0.0',
            true
        );

        $pdpa_page = get_page_by_path( 'pdpa' );
        $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );

        wp_localize_script(
            'cw-coupon-auth-popup',
            'cwCouponAuth',
            [
                'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
                'nonce'       => wp_create_nonce( self::NONCE_ACTION ),
                'isLoggedIn'  => false,
                'loginUrl'    => wp_login_url(),
                'registerUrl' => home_url( '/registration/' ),
                'pdpaUrl'     => $pdpa_url,
                'i18n'        => [
                    'titleRegister'  => __( 'Register to use this school coupon', 'creativewings-core' ),
                    'titleLogin'     => __( 'Log in to use this school coupon', 'creativewings-core' ),
                    'submitRegister' => __( 'Create account & apply coupon', 'creativewings-core' ),
                    'submitLogin'    => __( 'Log in & apply coupon', 'creativewings-core' ),
                    'switchLogin'    => __( 'Already have an account? Log in', 'creativewings-core' ),
                    'switchRegister' => __( 'Need an account? Register', 'creativewings-core' ),
                    'emailExists'    => __( 'Account found — please log in.', 'creativewings-core' ),
                    'genericError'   => __( 'Something went wrong. Please try again.', 'creativewings-core' ),
                    'close'          => __( 'Close', 'creativewings-core' ),
                ],
            ]
        );
    }

    public function render_modal_markup() {
        if ( ! $this->should_load_popup() ) {
            return;
        }

        $pdpa_page = get_page_by_path( 'pdpa' );
        $pdpa_url  = $pdpa_page ? get_permalink( $pdpa_page ) : home_url( '/pdpa/' );
        $pdpa_link = sprintf(
            /* translators: %s: link to PDPA notice page */
            __( 'I have read and agree to the %s.', 'creativewings-core' ),
            '<a href="' . esc_url( $pdpa_url ) . '" target="_blank" rel="noopener">' . esc_html__( 'Personal Data Protection (PDPA) Notice', 'creativewings-core' ) . '</a>'
        );
        ?>
        <div id="cw-coupon-auth-modal" class="cw-coupon-auth" hidden>
            <div class="cw-coupon-auth__backdrop" data-cw-close></div>
            <div class="cw-coupon-auth__dialog" role="dialog" aria-modal="true" aria-labelledby="cw-coupon-auth-title">
                <button type="button" class="cw-coupon-auth__close" data-cw-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
                <h2 id="cw-coupon-auth-title" class="cw-coupon-auth__title"><?php esc_html_e( 'Register to use this school coupon', 'creativewings-core' ); ?></h2>
                <p class="cw-coupon-auth__msg" id="cw-coupon-auth-msg" hidden></p>

                <form id="cw-coupon-auth-register" class="cw-coupon-auth__form">
                    <input type="text" name="cw_hp_check" value="" tabindex="-1" autocomplete="off" class="cw-coupon-auth__hp" aria-hidden="true">
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Email', 'creativewings-core' ); ?></span>
                        <input type="email" name="email" required autocomplete="email">
                    </label>
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Password', 'creativewings-core' ); ?></span>
                        <input type="password" name="password" required autocomplete="new-password" minlength="6">
                    </label>
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Full name', 'creativewings-core' ); ?></span>
                        <input type="text" name="name" required autocomplete="name">
                    </label>
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Date of birth', 'creativewings-core' ); ?></span>
                        <input type="text" name="dob" required placeholder="DD/MM/YYYY" inputmode="numeric" autocomplete="bday">
                    </label>
                    <label class="cw-coupon-auth__pdpa">
                        <input type="checkbox" name="pdpa" value="1" required>
                        <span><?php echo wp_kses_post( $pdpa_link ); ?></span>
                    </label>
                    <button type="submit" class="cw-coupon-auth__submit"><?php esc_html_e( 'Create account & apply coupon', 'creativewings-core' ); ?></button>
                    <button type="button" class="cw-coupon-auth__switch" data-cw-mode="login"><?php esc_html_e( 'Already have an account? Log in', 'creativewings-core' ); ?></button>
                </form>

                <form id="cw-coupon-auth-login" class="cw-coupon-auth__form" hidden>
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Email', 'creativewings-core' ); ?></span>
                        <input type="email" name="email" required autocomplete="email">
                    </label>
                    <label class="cw-coupon-auth__field">
                        <span><?php esc_html_e( 'Password', 'creativewings-core' ); ?></span>
                        <input type="password" name="password" required autocomplete="current-password">
                    </label>
                    <button type="submit" class="cw-coupon-auth__submit"><?php esc_html_e( 'Log in & apply coupon', 'creativewings-core' ); ?></button>
                    <button type="button" class="cw-coupon-auth__switch" data-cw-mode="register"><?php esc_html_e( 'Need an account? Register', 'creativewings-core' ); ?></button>
                </form>
            </div>
        </div>
        <?php
    }

    public function ajax_coupon_requires_auth() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $code      = isset( $_POST['code'] ) ? sanitize_text_field( wp_unslash( $_POST['code'] ) ) : '';
        $is_school = class_exists( 'CW_Sponsor_Coupons' ) && CW_Sponsor_Coupons::is_cw_school_coupon( $code );
        $requires  = $is_school && ! is_user_logged_in();
        wp_send_json_success(
            [
                'is_cw_school'  => $is_school,
                'requires_auth' => $requires,
            ]
        );
    }

    public function ajax_checkout_register() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( is_user_logged_in() ) {
            wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION, 10, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_send_json_error( [ 'code' => 'rate_limit', 'message' => $rl->get_error_message() ], 429 );
            }
        }

        if ( ! empty( $_POST['cw_hp_check'] ) ) {
            wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
        }

        $email     = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $pass      = (string) ( $_POST['password'] ?? '' );
        $full_name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
        $birthdate = sanitize_text_field( wp_unslash( $_POST['dob'] ?? '' ) );
        $coupon    = $this->posted_coupon();

        if ( $email === '' || $pass === '' || $full_name === '' || $birthdate === '' ) {
            wp_send_json_error(
                [
                    'code'    => 'missing_fields',
                    'message' => __( 'Please fill in all fields.', 'creativewings-core' ),
                ],
                400
            );
        }
        if ( empty( $_POST['pdpa'] ) ) {
            wp_send_json_error(
                [
                    'code'    => 'pdpa_required',
                    'message' => __( 'Please accept the PDPA notice.', 'creativewings-core' ),
                ],
                400
            );
        }
        if ( email_exists( $email ) ) {
            wp_send_json_error(
                [
                    'code'    => 'email_exists',
                    'message' => __( 'Account found — please log in.', 'creativewings-core' ),
                ],
                409
            );
        }

        $blocked_domains = [ '.website', '.top', '.xyz', '.pw', '.click', '.monster', '.live' ];
        foreach ( $blocked_domains as $domain ) {
            if ( false !== strpos( strtolower( $email ), $domain ) ) {
                wp_send_json_error(
                    [
                        'code'    => 'generic',
                        'message' => __( 'Could not create account. Please try again.', 'creativewings-core' ),
                    ],
                    400
                );
            }
        }

        $username = explode( '@', $email )[0] . wp_rand( 10, 999 );
        if ( strlen( $username ) < 4 ) {
            wp_send_json_error(
                [
                    'code'    => 'generic',
                    'message' => __( 'Could not create account. Please try again.', 'creativewings-core' ),
                ],
                400
            );
        }

        $user_id = wp_create_user( $username, $pass, $email );
        if ( is_wp_error( $user_id ) ) {
            wp_send_json_error(
                [
                    'code'    => 'generic',
                    'message' => __( 'Could not create account. Please try again.', 'creativewings-core' ),
                ],
                500
            );
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

        wp_update_user(
            [
                'ID'           => $user_id,
                'display_name' => $full_name,
                'first_name'   => $first,
                'last_name'    => $last,
            ]
        );

        $user = new WP_User( $user_id );
        $user->set_role( 'contestant' );
        if ( class_exists( 'CW_Auth' ) ) {
            CW_Auth::mark_contestant_onboarded( $user_id );
        }

        wp_set_current_user( $user_id );
        wp_set_auth_cookie( $user_id, true );

        wp_send_json_success( [ 'ok' => true, 'coupon' => $coupon ] );
    }

    public function ajax_checkout_login() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        if ( is_user_logged_in() ) {
            wp_send_json_success( [ 'ok' => true, 'coupon' => $this->posted_coupon() ] );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( 'cw_rate_coupon_login_', 20, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_send_json_error( [ 'code' => 'rate_limit', 'message' => $rl->get_error_message() ], 429 );
            }
        }

        $email  = sanitize_email( wp_unslash( $_POST['email'] ?? '' ) );
        $pass   = (string) ( $_POST['password'] ?? '' );
        $coupon = $this->posted_coupon();

        $user = wp_authenticate( $email, $pass );
        if ( is_wp_error( $user ) ) {
            wp_send_json_error(
                [
                    'code'    => 'invalid_credentials',
                    'message' => __( 'Incorrect email or password.', 'creativewings-core' ),
                ],
                401
            );
        }

        wp_set_current_user( $user->ID );
        wp_set_auth_cookie( $user->ID, true );

        wp_send_json_success( [ 'ok' => true, 'coupon' => $coupon ] );
    }

    private function posted_coupon() {
        return isset( $_POST['coupon'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon'] ) ) : '';
    }
}
