<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Guest_Join {

    const ORDER_META_DOB           = 'cw_guest_dob';
    const ORDER_META_TOKEN_HASH    = 'cw_guest_complete_token_hash';
    const ORDER_META_TOKEN_EXPIRES = 'cw_guest_complete_token_expires';
    const ORDER_META_COMPLETED     = 'cw_guest_account_completed';
    const TOKEN_TTL_DAYS           = 14;

    public function __construct() {
        add_action( 'woocommerce_after_checkout_billing_form', [ $this, 'render_guest_dob_field' ], 20 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_guest_checkout' ], 20 );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_guest_checkout_meta' ], 20 );
        add_action( 'woocommerce_payment_complete', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'maybe_send_complete_registration_email' ], 30 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'maybe_send_complete_registration_email' ], 30 );
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
            wc_add_notice(
                __( 'This email already has an account. Please log in to continue — your registration details will be kept.', 'creativewings-core' ),
                'error'
            );

            if ( function_exists( 'WC' ) && WC()->session ) {
                $campaign_id = 0;
                foreach ( WC()->cart->get_cart() as $item ) {
                    if ( ! self::cart_item_is_cw_registration( $item ) ) {
                        continue;
                    }
                    $campaign_id = (int) ( $item['product_id'] ?? 0 );
                    break;
                }
                WC()->session->set(
                    'cw_guest_resume_after_login',
                    [
                        'campaign_id'  => $campaign_id,
                        'checkout_url' => wc_get_checkout_url(),
                    ]
                );
            }

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
