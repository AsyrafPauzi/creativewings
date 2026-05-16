<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Sponsor_Coupons {

    public function __construct() {
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'validate_school_coupon' ], 10, 3 );
        add_action( 'woocommerce_coupon_options', [ $this, 'coupon_admin_fields' ], 10, 2 );
        add_action( 'woocommerce_coupon_options_save', [ $this, 'save_coupon_admin_fields' ], 10, 2 );
    }

    public static function sync_campaign_coupons( $campaign_id ) {
        if ( ! class_exists( 'WC_Coupon' ) ) {
            return;
        }

        $schools = get_post_meta( $campaign_id, 'cw_school_sponsors', true );
        if ( ! is_array( $schools ) ) {
            return;
        }

        foreach ( $schools as $school ) {
            $code = $school['coupon_code'] ?? '';
            if ( ! $code ) {
                continue;
            }

            $coupon_id = wc_get_coupon_id_by_code( $code );
            if ( ! $coupon_id ) {
                $coupon = new WC_Coupon();
                $coupon->set_code( $code );
                $coupon->set_discount_type( 'percent' );
                $coupon->set_amount( 100 );
                $coupon->set_individual_use( true );
                $coupon->save();
                $coupon_id = $coupon->get_id();
            }

            update_post_meta( $coupon_id, '_cw_campaign_id', (int) $campaign_id );
            update_post_meta( $coupon_id, '_cw_school_code', str_pad( preg_replace( '/\D/', '', $school['school_code'] ), 3, '0', STR_PAD_LEFT ) );

            $product_ids = [ (int) $campaign_id ];
            update_post_meta( $coupon_id, 'product_ids', $product_ids );
        }
    }

    public function validate_school_coupon( $valid, $coupon, $discount ) {
        if ( ! $valid || ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $valid;
        }

        $campaign_id = (int) get_post_meta( $coupon->get_id(), '_cw_campaign_id', true );
        if ( ! $campaign_id ) {
            return $valid;
        }

        if ( ! WC()->cart ) {
            return $valid;
        }

        $has_claim = false;
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['cw_staged_id'] ) && (int) $item['product_id'] === $campaign_id ) {
                $has_claim = true;
                $school_from_code = '';
                $staged = CW_Staged_Submissions::get_by_code( $item['cw_claim_code'] ?? '', $campaign_id );
                if ( $staged ) {
                    $school_from_code = $staged['school_code'];
                }
                $coupon_school = get_post_meta( $coupon->get_id(), '_cw_school_code', true );
                if ( $coupon_school && $school_from_code && $coupon_school !== $school_from_code ) {
                    throw new Exception( __( 'This coupon is not valid for your school submission code.', 'creativewings-core' ) );
                }
            }
        }

        return $valid;
    }

    public function coupon_admin_fields( $coupon_id, $coupon ) {
        woocommerce_wp_text_input( [
            'id'          => '_cw_campaign_id',
            'label'       => 'CW Campaign ID',
            'description' => 'Creative Wings campaign product ID',
            'value'       => get_post_meta( $coupon_id, '_cw_campaign_id', true ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_cw_school_code',
            'label'       => 'CW School Code (3 digits)',
            'value'       => get_post_meta( $coupon_id, '_cw_school_code', true ),
        ] );
    }

    public function save_coupon_admin_fields( $coupon_id, $coupon ) {
        if ( isset( $_POST['_cw_campaign_id'] ) ) {
            update_post_meta( $coupon_id, '_cw_campaign_id', absint( $_POST['_cw_campaign_id'] ) );
        }
        if ( isset( $_POST['_cw_school_code'] ) ) {
            update_post_meta( $coupon_id, '_cw_school_code', sanitize_text_field( $_POST['_cw_school_code'] ) );
        }
    }
}
