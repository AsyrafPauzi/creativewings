<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Sponsor_Coupons {

    public function __construct() {
        add_filter( 'woocommerce_coupon_is_valid', [ $this, 'validate_school_coupon' ], 10, 3 );
        add_action( 'woocommerce_coupon_options', [ $this, 'coupon_admin_fields' ], 10, 2 );
        add_action( 'woocommerce_coupon_options_save', [ $this, 'save_coupon_admin_fields' ], 10, 2 );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_coupon_addresses' ], 25 );
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

            if ( ! empty( $school['coupon_restrict_postcodes'] ) ) {
                update_post_meta( $coupon_id, '_cw_restrict_postcodes', sanitize_text_field( $school['coupon_restrict_postcodes'] ) );
            } else {
                delete_post_meta( $coupon_id, '_cw_restrict_postcodes' );
            }
            if ( ! empty( $school['coupon_restrict_keywords'] ) ) {
                update_post_meta( $coupon_id, '_cw_restrict_keywords', sanitize_text_field( $school['coupon_restrict_keywords'] ) );
            } else {
                delete_post_meta( $coupon_id, '_cw_restrict_keywords' );
            }
        }

        self::sync_campaign_address_promo_coupons( (int) $campaign_id );
    }

    /**
     * Sync address-restricted resident promo codes configured on the campaign.
     */
    public static function sync_campaign_address_promo_coupons( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id <= 0 || ! class_exists( 'WC_Coupon' ) ) {
            return;
        }

        $codes_raw = (string) get_post_meta( $campaign_id, 'cw_address_promo_codes', true );
        $postcodes = (string) get_post_meta( $campaign_id, 'cw_address_promo_postcodes', true );
        $keywords  = (string) get_post_meta( $campaign_id, 'cw_address_promo_keywords', true );
        $message   = (string) get_post_meta( $campaign_id, 'cw_address_promo_message', true );

        $codes = self::split_csv( $codes_raw );
        if ( empty( $codes ) ) {
            return;
        }

        foreach ( $codes as $code ) {
            $coupon_id = wc_get_coupon_id_by_code( $code );
            if ( $coupon_id ) {
                $owner = (int) get_post_meta( $coupon_id, '_cw_campaign_id', true );
                // Never mutate unrelated store coupons (hijack prevention).
                if ( $owner && $owner !== $campaign_id ) {
                    continue;
                }
                if ( ! $owner && ! current_user_can( 'manage_woocommerce' ) ) {
                    // Create a campaign-scoped code instead of taking over a global coupon.
                    $scoped = $code . '-c' . $campaign_id;
                    $scoped_id = wc_get_coupon_id_by_code( $scoped );
                    if ( ! $scoped_id ) {
                        $coupon = new WC_Coupon();
                        $coupon->set_code( $scoped );
                        $coupon->set_discount_type( 'percent' );
                        $coupon->set_amount( 100 );
                        $coupon->set_individual_use( true );
                        $coupon->save();
                        $scoped_id = $coupon->get_id();
                    }
                    $coupon_id = $scoped_id;
                }
                // Shop managers may re-bind an unowned coupon deliberately.
            } else {
                $coupon = new WC_Coupon();
                $coupon->set_code( $code );
                $coupon->set_discount_type( 'percent' );
                $coupon->set_amount( 100 );
                $coupon->set_individual_use( true );
                $coupon->save();
                $coupon_id = $coupon->get_id();
            }

            if ( ! $coupon_id ) {
                continue;
            }

            update_post_meta( $coupon_id, '_cw_campaign_id', $campaign_id );
            update_post_meta( $coupon_id, 'product_ids', [ $campaign_id ] );

            if ( $postcodes !== '' ) {
                update_post_meta( $coupon_id, '_cw_restrict_postcodes', $postcodes );
            } else {
                delete_post_meta( $coupon_id, '_cw_restrict_postcodes' );
            }
            if ( $keywords !== '' ) {
                update_post_meta( $coupon_id, '_cw_restrict_keywords', $keywords );
            } else {
                delete_post_meta( $coupon_id, '_cw_restrict_keywords' );
            }
            if ( $message !== '' ) {
                update_post_meta( $coupon_id, '_cw_restrict_message', $message );
            } else {
                delete_post_meta( $coupon_id, '_cw_restrict_message' );
            }
        }
    }

    public function validate_school_coupon( $valid, $coupon, $discount ) {
        if ( ! $valid || ! is_a( $coupon, 'WC_Coupon' ) ) {
            return $valid;
        }

        $coupon_id = (int) $coupon->get_id();

        if ( self::coupon_has_address_rules( $coupon_id ) ) {
            self::assert_billing_matches_coupon_address_rules( $coupon_id, $coupon->get_code() );
        }

        $campaign_id = (int) get_post_meta( $coupon_id, '_cw_campaign_id', true );
        if ( ! $campaign_id ) {
            return $valid;
        }

        if ( ! WC()->cart ) {
            return $valid;
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['cw_staged_id'] ) && (int) $item['product_id'] === $campaign_id ) {
                $school_from_code = '';
                $staged = CW_Staged_Submissions::get_by_code( $item['cw_claim_code'] ?? '', $campaign_id );
                if ( $staged ) {
                    $school_from_code = $staged['school_code'];
                }
                $coupon_school = get_post_meta( $coupon_id, '_cw_school_code', true );
                if ( $coupon_school && $school_from_code && $coupon_school !== $school_from_code ) {
                    throw new Exception( __( 'This coupon is not valid for your school submission code.', 'creativewings-core' ) );
                }
            }
        }

        return $valid;
    }

    public function validate_checkout_coupon_addresses() {
        if ( ! WC()->cart ) {
            return;
        }

        foreach ( WC()->cart->get_applied_coupons() as $code ) {
            $coupon = new WC_Coupon( $code );
            if ( ! $coupon->get_id() ) {
                continue;
            }
            if ( ! self::coupon_has_address_rules( $coupon->get_id() ) ) {
                continue;
            }
            try {
                self::assert_billing_matches_coupon_address_rules( $coupon->get_id(), $code );
            } catch ( Exception $e ) {
                wc_add_notice( $e->getMessage(), 'error' );
            }
        }
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
        woocommerce_wp_text_input( [
            'id'          => '_cw_restrict_postcodes',
            'label'       => 'CW Allowed postcodes',
            'description' => 'Comma-separated billing postcodes (e.g. 40170,40180). Leave blank for no postcode rule.',
            'value'       => get_post_meta( $coupon_id, '_cw_restrict_postcodes', true ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_cw_restrict_keywords',
            'label'       => 'CW Address keywords',
            'description' => 'Comma-separated words searched in billing address/city (e.g. U13, Setia Alam Impian, Setia Alam). At least one must match when set.',
            'value'       => get_post_meta( $coupon_id, '_cw_restrict_keywords', true ),
        ] );
        woocommerce_wp_text_input( [
            'id'          => '_cw_restrict_message',
            'label'       => 'CW Address rejection message',
            'description' => 'Shown when billing address does not match the rules above.',
            'value'       => get_post_meta( $coupon_id, '_cw_restrict_message', true ),
        ] );
    }

    public function save_coupon_admin_fields( $coupon_id, $coupon ) {
        if ( isset( $_POST['_cw_campaign_id'] ) ) {
            update_post_meta( $coupon_id, '_cw_campaign_id', absint( $_POST['_cw_campaign_id'] ) );
        }
        if ( isset( $_POST['_cw_school_code'] ) ) {
            update_post_meta( $coupon_id, '_cw_school_code', sanitize_text_field( wp_unslash( $_POST['_cw_school_code'] ) ) );
        }
        if ( isset( $_POST['_cw_restrict_postcodes'] ) ) {
            update_post_meta( $coupon_id, '_cw_restrict_postcodes', sanitize_text_field( wp_unslash( $_POST['_cw_restrict_postcodes'] ) ) );
        }
        if ( isset( $_POST['_cw_restrict_keywords'] ) ) {
            update_post_meta( $coupon_id, '_cw_restrict_keywords', sanitize_text_field( wp_unslash( $_POST['_cw_restrict_keywords'] ) ) );
        }
        if ( isset( $_POST['_cw_restrict_message'] ) ) {
            update_post_meta( $coupon_id, '_cw_restrict_message', sanitize_text_field( wp_unslash( $_POST['_cw_restrict_message'] ) ) );
        }
    }

    /**
     * @return array{postcode:string,city:string,state:string,address:string}
     */
    public static function get_checkout_billing_snapshot() {
        $out = [
            'postcode' => '',
            'city'     => '',
            'state'    => '',
            'address'  => '',
        ];

        if ( function_exists( 'WC' ) && WC()->customer ) {
            $out['postcode'] = (string) WC()->customer->get_billing_postcode();
            $out['city']     = (string) WC()->customer->get_billing_city();
            $out['state']     = (string) WC()->customer->get_billing_state();
            $out['address']   = trim( WC()->customer->get_billing_address_1() . ' ' . WC()->customer->get_billing_address_2() );
        }

        $map = [
            'billing_postcode'  => 'postcode',
            'billing_city'      => 'city',
            'billing_state'     => 'state',
            'billing_address_1' => 'address_1',
            'billing_address_2' => 'address_2',
        ];
        foreach ( $map as $post_key => $target ) {
            if ( empty( $_POST[ $post_key ] ) ) {
                continue;
            }
            $value = sanitize_text_field( wp_unslash( (string) $_POST[ $post_key ] ) );
            if ( $target === 'address_1' || $target === 'address_2' ) {
                $line = $target === 'address_1' ? $value : $value;
                $out['address'] = trim( $out['address'] . ' ' . $line );
            } else {
                $out[ $target ] = $value;
            }
        }

        return $out;
    }

    public static function coupon_has_address_rules( $coupon_id ) {
        $coupon_id = (int) $coupon_id;
        if ( $coupon_id <= 0 ) {
            return false;
        }
        $postcodes = trim( (string) get_post_meta( $coupon_id, '_cw_restrict_postcodes', true ) );
        $keywords  = trim( (string) get_post_meta( $coupon_id, '_cw_restrict_keywords', true ) );
        return $postcodes !== '' || $keywords !== '';
    }

    /**
     * @throws Exception
     */
    public static function assert_billing_matches_coupon_address_rules( $coupon_id, $coupon_code = '' ) {
        $coupon_id = (int) $coupon_id;
        if ( ! self::coupon_has_address_rules( $coupon_id ) ) {
            return;
        }

        $billing   = self::get_checkout_billing_snapshot();
        $postcodes = self::split_csv( (string) get_post_meta( $coupon_id, '_cw_restrict_postcodes', true ) );
        $keywords  = self::split_csv( (string) get_post_meta( $coupon_id, '_cw_restrict_keywords', true ) );

        $custom = trim( (string) get_post_meta( $coupon_id, '_cw_restrict_message', true ) );
        $fail   = $custom !== ''
            ? $custom
            : __( 'This promo code is only available to residents in the eligible area (U13 / Setia Alam Impian, Shah Alam). Please check your billing address.', 'creativewings-core' );

        $has_address = $billing['postcode'] !== '' || $billing['city'] !== '' || $billing['address'] !== '';
        if ( ! $has_address ) {
            throw new Exception(
                __( 'Please enter your billing address before applying this promo code.', 'creativewings-core' )
            );
        }

        $postcode_ok = null;
        $keyword_ok  = null;

        if ( ! empty( $postcodes ) ) {
            $postcode_ok = false;
            $entered     = strtoupper( preg_replace( '/\s+/', '', $billing['postcode'] ) );
            foreach ( $postcodes as $pc ) {
                if ( $entered !== '' && $entered === strtoupper( preg_replace( '/\s+/', '', $pc ) ) ) {
                    $postcode_ok = true;
                    break;
                }
            }
        }

        if ( ! empty( $keywords ) ) {
            $keyword_ok = false;
            $haystack   = strtolower( implode( ' ', array_filter( [ $billing['address'], $billing['city'], $billing['state'] ] ) ) );
            foreach ( $keywords as $keyword ) {
                if ( self::keyword_matches( $haystack, $keyword ) ) {
                    $keyword_ok = true;
                    break;
                }
            }
        }

        $passed = ( $postcode_ok === true ) || ( $keyword_ok === true );
        if ( ! $passed ) {
            throw new Exception( $fail );
        }
    }

    /**
     * Split a comma/semicolon list into phrases. Spaces inside a phrase are kept
     * so "Setia Alam Impian" stays one keyword (not "Setia" + "Alam" + "Impian").
     *
     * @return string[]
     */
    public static function split_csv( $raw ) {
        if ( ! is_string( $raw ) || trim( $raw ) === '' ) {
            return [];
        }
        $parts = preg_split( '/[,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
        if ( ! is_array( $parts ) ) {
            return [];
        }
        $out = [];
        foreach ( $parts as $part ) {
            $part = trim( (string) $part );
            if ( $part === '' ) {
                continue;
            }
            $out[] = $part;
        }
        return array_values( array_unique( $out ) );
    }

    /**
     * True if $needle appears in $haystack as a contiguous phrase (case-insensitive).
     */
    public static function keyword_matches( $haystack, $keyword ) {
        $haystack = strtolower( trim( (string) $haystack ) );
        $keyword  = strtolower( trim( (string) $keyword ) );
        if ( $haystack === '' || $keyword === '' ) {
            return false;
        }
        return str_contains( $haystack, $keyword );
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

        $schools = get_post_meta( $campaign_id, 'cw_school_sponsors', true );
        $school_map = [];
        if ( is_array( $schools ) ) {
            foreach ( $schools as $row ) {
                if ( empty( $row['school_code'] ) ) {
                    continue;
                }
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

        usort( $out, static function ( $a, $b ) {
            return strcmp( (string) $a['school_code'], (string) $b['school_code'] );
        } );

        return $out;
    }
}
