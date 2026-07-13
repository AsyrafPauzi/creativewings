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

    public static function cart_has_cw_campaign() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            $pid = (int) ( $item['product_id'] ?? 0 );
            if ( $pid && get_post_type( $pid ) === 'product' ) {
                return true;
            }
        }
        return false;
    }

    public static function is_guest_checkout_context() {
        return ! is_user_logged_in() && self::cart_has_cw_campaign();
    }

    // Stubs implemented in later tasks:
    public function render_guest_dob_field() {}
    public function validate_guest_checkout() {}
    public function save_guest_checkout_meta( $order_id ) {}
    public function maybe_send_complete_registration_email( $order_id ) {}
}
