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

    /**
     * List all sponsor coupons attached to a given campaign, decorated with
     * usage counts and the matching school metadata stored on the campaign
     * (so we can show "School 002 - Sekolah X" alongside the code).
     *
     * @param int $campaign_id
     * @return array<int, array{
     *     id:int, code:string, school_code:string, school_name:string,
     *     amount:float, discount_type:string, usage_count:int, usage_limit:?int,
     *     edit_url:string, expires:?string
     * }>
     */
    public static function get_coupons_for_campaign( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id || ! class_exists( 'WC_Coupon' ) ) {
            return [];
        }

        $ids = get_posts( [
            'post_type'      => 'shop_coupon',
            'post_status'    => [ 'publish', 'draft', 'pending', 'private' ],
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
            'meta_query'     => [
                [
                    'key'     => '_cw_campaign_id',
                    'value'   => $campaign_id,
                    'compare' => '=',
                    'type'    => 'NUMERIC',
                ],
            ],
        ] );

        if ( empty( $ids ) ) {
            return [];
        }

        // Lookup table of school metadata from the campaign so we can attach
        // a human-readable school name to each coupon row in one shot.
        $schools = get_post_meta( $campaign_id, 'cw_school_sponsors', true );
        $school_map = [];
        if ( is_array( $schools ) ) {
            foreach ( $schools as $row ) {
                if ( empty( $row['school_code'] ) ) continue;
                $code = str_pad( preg_replace( '/\D/', '', $row['school_code'] ), 3, '0', STR_PAD_LEFT );
                $school_map[ $code ] = (string) ( $row['school_name'] ?? '' );
            }
        }

        $out = [];
        foreach ( $ids as $id ) {
            $coupon = new WC_Coupon( (int) $id );
            $school_code = (string) get_post_meta( $id, '_cw_school_code', true );
            if ( $school_code !== '' ) {
                $school_code = str_pad( preg_replace( '/\D/', '', $school_code ), 3, '0', STR_PAD_LEFT );
            }
            $expires    = $coupon->get_date_expires();
            $expires_at = $expires ? $expires->date( 'Y-m-d' ) : null;

            $out[] = [
                'id'            => (int) $id,
                'code'          => (string) $coupon->get_code(),
                'school_code'   => $school_code,
                'school_name'   => $school_map[ $school_code ] ?? '',
                'amount'        => (float) $coupon->get_amount(),
                'discount_type' => (string) $coupon->get_discount_type(),
                'usage_count'   => (int) $coupon->get_usage_count(),
                'usage_limit'   => $coupon->get_usage_limit() ? (int) $coupon->get_usage_limit() : null,
                'edit_url'      => get_edit_post_link( (int) $id, 'raw' ) ?: '',
                'expires'       => $expires_at,
            ];
        }

        // Sort by school code so the table mirrors the campaign's sponsor list.
        usort( $out, static function ( $a, $b ) {
            return strcmp( (string) $a['school_code'], (string) $b['school_code'] );
        } );

        return $out;
    }
}
