<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Guest_Join {

    const ORDER_META_DOB           = 'cw_guest_dob';
    const ORDER_META_TOKEN_HASH    = 'cw_guest_complete_token_hash';
    const ORDER_META_TOKEN_EXPIRES = 'cw_guest_complete_token_expires';
    const ORDER_META_COMPLETED     = 'cw_guest_account_completed';
    const SESSION_RESUME_KEY       = 'cw_guest_resume_after_login';
    const TOKEN_TTL_DAYS           = 14;

    public function __construct() {
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_guest_dob_field' ], 20 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_guest_checkout' ], 20 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_guest_checkout_meta' ], 20 );
        add_action( 'woocommerce_payment_complete', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_filter( 'woocommerce_login_redirect', [ $this, 'filter_woocommerce_login_redirect' ], 20, 2 );
        add_action( 'template_redirect', [ $this, 'maybe_redirect_resume_join' ], 5 );
    }

    /**
     * Whether a cart line represents a CW campaign registration (not a bare WC product or school claim).
     *
     * @param array<string, mixed> $item
     */
    private static function cart_item_is_cw_registration( $item ) {
        if ( ! is_array( $item ) ) {
            return false;
        }

        // School claim lines require login; do not treat them as guest registration checkout.
        if ( ! empty( $item['cw_staged_id'] ) || ! empty( $item['cw_claim_code'] ) ) {
            return false;
        }

        if ( isset( $item['cw_participants'] ) && is_array( $item['cw_participants'] ) && count( $item['cw_participants'] ) > 0 ) {
            return true;
        }

        if ( ! empty( $item['cw_addons_meta'] ) && is_array( $item['cw_addons_meta'] ) && count( $item['cw_addons_meta'] ) > 0 ) {
            return true;
        }

        if ( class_exists( 'CW_Design_Submission' ) && ! empty( $item[ CW_Design_Submission::CART_FLAG ] ) ) {
            return true;
        }

        return false;
    }

    public static function cart_has_cw_campaign() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( self::cart_item_is_cw_registration( $item ) ) {
                return true;
            }
        }
        return false;
    }

    public static function is_guest_checkout_context() {
        return ! is_user_logged_in() && self::cart_has_cw_campaign();
    }

    /**
     * First CW registration campaign product ID in the cart, if any.
     */
    public static function get_campaign_id_from_cart() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! self::cart_item_is_cw_registration( $item ) ) {
                continue;
            }

            $campaign_id = (int) ( $item['product_id'] ?? 0 );
            if ( $campaign_id ) {
                return $campaign_id;
            }
        }

        return 0;
    }

    /**
     * Whether a URL targets guest-join resume (checkout or campaign flag).
     *
     * @param string $url
     */
    public static function url_is_guest_resume_target( $url ) {
        if ( ! is_string( $url ) || '' === $url ) {
            return false;
        }

        if ( function_exists( 'wc_get_checkout_url' ) ) {
            $checkout = wc_get_checkout_url();
            if ( $checkout && untrailingslashit( $url ) === untrailingslashit( $checkout ) ) {
                return true;
            }
        }

        $query = wp_parse_url( $url, PHP_URL_QUERY );
        if ( ! is_string( $query ) || '' === $query ) {
            return false;
        }

        parse_str( $query, $args );
        return isset( $args['cw_resume_join'] ) && '1' === (string) $args['cw_resume_join'];
    }

    /**
     * Post-login destination: checkout when cart still has registration, else campaign modal URL.
     *
     * @param int $campaign_id Optional campaign product ID fallback.
     */
    public static function build_post_login_resume_url( $campaign_id = 0 ) {
        if ( self::cart_has_cw_campaign() && function_exists( 'wc_get_checkout_url' ) ) {
            return wc_get_checkout_url();
        }

        if ( ! $campaign_id && function_exists( 'WC' ) && WC()->session ) {
            $resume = WC()->session->get( self::SESSION_RESUME_KEY );
            if ( is_array( $resume ) ) {
                $campaign_id = (int) ( $resume['campaign_id'] ?? 0 );
            }
        }

        if ( $campaign_id ) {
            return add_query_arg( 'cw_resume_join', '1', get_permalink( $campaign_id ) );
        }

        return '';
    }

    /**
     * Login page URL that returns the user to checkout or the campaign join modal after auth.
     *
     * @param int $campaign_id
     */
    public static function get_resume_login_url( $campaign_id = 0 ) {
        $resume_url = self::build_post_login_resume_url( $campaign_id );
        if ( ! $resume_url ) {
            return home_url( '/login' );
        }

        return add_query_arg( 'redirect_to', rawurlencode( $resume_url ), home_url( '/login' ) );
    }

    /**
     * Clear stored resume context from the WooCommerce session.
     */
    public static function consume_resume_session() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return null;
        }

        $resume = WC()->session->get( self::SESSION_RESUME_KEY );
        if ( ! is_array( $resume ) ) {
            return null;
        }

        WC()->session->set( self::SESSION_RESUME_KEY, null );
        return $resume;
    }

    /**
     * Resolve login redirect for guest join resume. Returns null when not applicable.
     *
     * @param string   $redirect Requested redirect URL.
     * @param WP_User  $user     Authenticated user.
     * @return string|null
     */
    public static function resolve_login_redirect( $redirect, $user ) {
        if ( is_wp_error( $user ) || ! ( $user instanceof WP_User ) || ! $user->exists() ) {
            return null;
        }

        $has_resume_session = false;
        if ( function_exists( 'WC' ) && WC()->session ) {
            $stored = WC()->session->get( self::SESSION_RESUME_KEY );
            $has_resume_session = is_array( $stored ) && ! empty( $stored );
        }

        if ( ! $has_resume_session && ! self::url_is_guest_resume_target( $redirect ) ) {
            return null;
        }

        $resume_url = self::build_post_login_resume_url();
        if ( $resume_url ) {
            self::consume_resume_session();
            return $resume_url;
        }

        if ( $has_resume_session ) {
            self::consume_resume_session();
        }

        return null;
    }

    /**
     * WooCommerce login forms (checkout / my account).
     *
     * @param string  $redirect Default redirect URL.
     * @param WP_User $user     Authenticated user.
     */
    public function filter_woocommerce_login_redirect( $redirect, $user ) {
        $resolved = self::resolve_login_redirect( $redirect, $user );
        return null !== $resolved ? $resolved : $redirect;
    }

    /**
     * Logged-in user with cart + cw_resume_join should go straight to checkout.
     */
    public function maybe_redirect_resume_join() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        if ( ! isset( $_GET['cw_resume_join'] ) || '1' !== (string) wp_unslash( $_GET['cw_resume_join'] ) ) {
            return;
        }

        if ( ! self::cart_has_cw_campaign() || ! function_exists( 'wc_get_checkout_url' ) ) {
            return;
        }

        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    public function render_guest_dob_field() {
        if ( ! self::is_guest_checkout_context() ) {
            return;
        }

        $value = WC()->checkout->get_value( 'cw_guest_dob' );
        if ( ! is_string( $value ) ) {
            $value = '';
        }

        echo '<div class="cw-checkout-message-section cw-guest-dob-section">';
        echo '<h3 class="cw-checkout-message-heading">' . esc_html__( 'Date of birth', 'creativewings-core' ) . ' <abbr class="required" title="' . esc_attr__( 'required', 'creativewings-core' ) . '">*</abbr></h3>';
        woocommerce_form_field(
            'cw_guest_dob',
            [
                'type'         => 'text',
                'class'        => [ 'form-row-wide', 'cw-guest-dob-field' ],
                'label'        => __( 'Date of birth', 'creativewings-core' ),
                'required'     => true,
                'placeholder'  => 'dd/mm/yyyy',
                'autocomplete' => 'bday',
            ],
            $value
        );
        echo '</div>';
    }

    public function validate_guest_checkout() {
        if ( ! self::is_guest_checkout_context() ) {
            return;
        }

        $email = sanitize_email( wp_unslash( $_POST['billing_email'] ?? '' ) );
        if ( $email && email_exists( $email ) ) {
            $campaign_id = self::get_campaign_id_from_cart();

            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set(
                    self::SESSION_RESUME_KEY,
                    [
                        'campaign_id'  => $campaign_id,
                        'checkout_url' => wc_get_checkout_url(),
                    ]
                );
            }

            $login_url = self::get_resume_login_url( $campaign_id );
            wc_add_notice(
                sprintf(
                    /* translators: %s: login URL */
                    __( 'This email already has an account. Please <a href="%s">log in</a> to continue — your registration details will be kept.', 'creativewings-core' ),
                    esc_url( $login_url )
                ),
                'error'
            );

            return;
        }

        $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
        if ( '' === trim( $dob ) || null === CW_Staged_Submissions::age_from_birthdate( $dob ) ) {
            wc_add_notice( __( 'Please enter a valid date of birth (dd/mm/yyyy).', 'creativewings-core' ), 'error' );
            return;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! self::cart_item_is_cw_registration( $item ) ) {
                continue;
            }

            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( ! $pid || get_post_meta( $pid, 'cw_enable_age_brackets', true ) !== 'yes' ) {
                continue;
            }

            $result = CW_Staged_Submissions::resolve_age_bracket( $pid, $dob );
            if ( is_wp_error( $result ) ) {
                if ( $result->get_error_code() === 'no_match' ) {
                    wc_add_notice( $result->get_error_message(), 'error' );
                    return;
                }
                continue;
            }

            if ( function_exists( 'WC' ) && WC()->session ) {
                WC()->session->set( 'cw_guest_age_bracket_' . $pid, $result );
            }
        }
    }

    public function save_guest_checkout_meta( $order_id ) {
        if ( is_user_logged_in() ) {
            return;
        }

        $dob = isset( $_POST['cw_guest_dob'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_guest_dob'] ) ) : '';
        if ( ! $dob ) {
            return;
        }

        update_post_meta( $order_id, self::ORDER_META_DOB, $dob );

        $order = wc_get_order( $order_id );
        if ( $order ) {
            $order->update_meta_data( self::ORDER_META_DOB, $dob );
            $order->save();
        }
    }

    public function maybe_send_complete_registration_email( $order_id ) {}
}
