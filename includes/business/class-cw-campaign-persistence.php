<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Shared campaign save logic for wizard POST and JSON import.
 */
class CW_Campaign_Persistence {

    public static function get_sdg_map() {
        return class_exists( 'CW_Business' ) ? CW_Business::get_sdg_map() : [];
    }

    /**
     * @param array $data Campaign fields.
     * @param int   $product_id 0 = create draft.
     * @param int   $author_id Post author / organizer.
     * @return int|WP_Error Product ID.
     */
    public static function save_from_array( array $data, $product_id = 0, $author_id = 0 ) {
        if ( ! $author_id ) {
            $author_id = get_current_user_id();
        }

        $title   = sanitize_text_field( $data['post_title'] ?? '' );
        $content = isset( $data['post_content'] ) ? wp_kses_post( $data['post_content'] ) : '';

        if ( ! $title ) {
            return new WP_Error( 'cw_missing_title', 'Campaign title is required.' );
        }

        $args = [
            'post_title'   => $title,
            'post_content' => $content,
            'post_type'    => 'product',
            'post_author'  => (int) $author_id,
        ];

        $is_new = ( (int) $product_id === 0 );
        if ( $is_new ) {
            $args['post_status'] = isset( $data['post_status'] ) ? sanitize_text_field( $data['post_status'] ) : 'draft';
            $product_id          = wp_insert_post( $args, true );
        } else {
            $args['ID'] = (int) $product_id;
            $product_id = wp_update_post( $args, true );
        }

        if ( is_wp_error( $product_id ) ) {
            return $product_id;
        }

        $product_id = (int) $product_id;

        // Category: slug or term id.
        if ( ! empty( $data['product_cat'] ) ) {
            wp_set_object_terms( $product_id, (int) $data['product_cat'], 'product_cat' );
        } elseif ( ! empty( $data['product_cat_slug'] ) ) {
            $term = get_term_by( 'slug', sanitize_title( $data['product_cat_slug'] ), 'product_cat' );
            if ( $term && ! is_wp_error( $term ) ) {
                wp_set_object_terms( $product_id, (int) $term->term_id, 'product_cat' );
            }
        }

        $visibility = $data['cw_visibility'] ?? 'visible';
        if ( $visibility === 'hidden' ) {
            wp_set_object_terms( $product_id, [ 'exclude-from-catalog', 'exclude-from-search' ], 'product_visibility' );
        } else {
            wp_set_object_terms( $product_id, [], 'product_visibility' );
        }

        $price = isset( $data['regular_price'] ) ? sanitize_text_field( $data['regular_price'] ) : '0';
        update_post_meta( $product_id, '_regular_price', $price );
        update_post_meta( $product_id, '_price', $price );
        update_post_meta( $product_id, '_virtual', 'yes' );
        update_post_meta( $product_id, 'organizer_id', (int) ( $data['organizer_id'] ?? $author_id ) );

        $scalar_keys = [
            'submission_deadline', 'cw_total_prize_value', 'cw_min_participants', 'cw_max_participants',
            'cw_submission_start', 'cw_review_start', 'cw_final_event_date', 'cw_location_details',
            'cw_enable_certificate', 'cw_judging_criteria', 'cw_talk_speaker', 'cw_talk_type',
            'cw_cert_x', 'cw_cert_y', 'cw_cert_font_size', 'cw_cert_font_color', 'cw_cert_max_width', 'cw_cert_align',
            'cw_event_mode', 'cw_online_link', 'cw_multi_min', 'cw_multi_max',
            'cw_campaign_serial', 'cw_checkout_message_label',
        ];
        foreach ( $scalar_keys as $k ) {
            if ( array_key_exists( $k, $data ) ) {
                update_post_meta( $product_id, $k, sanitize_text_field( $data[ $k ] ) );
            }
        }

        if ( ! get_post_meta( $product_id, 'cw_campaign_serial', true ) ) {
            update_post_meta( $product_id, 'cw_campaign_serial', str_pad( (string) $product_id, 3, '0', STR_PAD_LEFT ) );
        } elseif ( ! empty( $data['cw_campaign_serial'] ) ) {
            update_post_meta( $product_id, 'cw_campaign_serial', sanitize_text_field( $data['cw_campaign_serial'] ) );
        }

        update_post_meta( $product_id, 'cw_enable_voting', ( ! empty( $data['cw_enable_voting'] ) && $data['cw_enable_voting'] !== 'no' ) ? 'yes' : 'no' );
        update_post_meta( $product_id, 'multiple_submissions', ! empty( $data['multiple_submissions'] ) && $data['multiple_submissions'] !== 'false' ? 'true' : 'false' );

        update_post_meta( $product_id, 'cw_enable_checkout_message', ( ! empty( $data['cw_enable_checkout_message'] ) && $data['cw_enable_checkout_message'] !== 'no' ) ? 'yes' : 'no' );
        update_post_meta( $product_id, 'cw_checkout_message_required', ! empty( $data['cw_checkout_message_required'] ) ? 'yes' : 'no' );
        $sco = $data['cw_school_coupons_optional'] ?? 'yes';
        update_post_meta( $product_id, 'cw_school_coupons_optional', ( $sco === 'yes' || $sco === true || $sco === '1' ) ? 'yes' : 'no' );

        if ( isset( $data['faq'] ) ) {
            self::save_repeater_meta( $product_id, 'faq', $data['faq'], 'question' );
        }
        if ( isset( $data['prizes'] ) || isset( $data['cw_prizes'] ) ) {
            self::save_repeater_meta( $product_id, 'prizes', $data['prizes'] ?? $data['cw_prizes'], 'prize_title' );
        }
        if ( isset( $data['addons'] ) || isset( $data['cw_addons'] ) ) {
            self::save_addons( $product_id, $data['addons'] ?? $data['cw_addons'] );
        }
        if ( isset( $data['sdg_goals'] ) ) {
            self::save_sdg_goals( $product_id, $data['sdg_goals'] );
        }
        if ( isset( $data['custom_fields'] ) ) {
            self::save_custom_fields( $product_id, $data['custom_fields'] );
        }
        if ( isset( $data['age_brackets'] ) || isset( $data['cw_age_brackets'] ) ) {
            $brackets = $data['age_brackets'] ?? $data['cw_age_brackets'];
            update_post_meta( $product_id, 'cw_age_brackets', self::sanitize_age_brackets( $brackets ) );
        }
        if ( isset( $data['schools'] ) || isset( $data['cw_school_sponsors'] ) ) {
            $schools = $data['schools'] ?? $data['cw_school_sponsors'];
            update_post_meta( $product_id, 'cw_school_sponsors', self::sanitize_schools( $schools ) );
            if ( class_exists( 'CW_Sponsor_Coupons' ) ) {
                CW_Sponsor_Coupons::sync_campaign_coupons( $product_id );
            }
            if ( class_exists( 'CW_Staged_Submissions' ) ) {
                CW_Staged_Submissions::sync_school_upload_tokens( $product_id );
            }
        }

        if ( ! empty( $data['banner_attachment_id'] ) ) {
            set_post_thumbnail( $product_id, (int) $data['banner_attachment_id'] );
        }
        if ( ! empty( $data['cw_cert_template'] ) ) {
            update_post_meta( $product_id, 'cw_cert_template', esc_url_raw( $data['cw_cert_template'] ) );
        }

        if ( ! empty( $data['cw_enable_moderation'] ) && 'no' !== $data['cw_enable_moderation'] ) {
            update_post_meta( $product_id, 'cw_enable_moderation', 'yes' );
        } elseif ( array_key_exists( 'cw_enable_moderation', $data ) ) {
            delete_post_meta( $product_id, 'cw_enable_moderation' );
        }

        if ( class_exists( 'CW_Campaign_Resolver' ) ) {
            CW_Campaign_Resolver::flush_serial_cache( $product_id );
        }

        return $product_id;
    }

    /**
     * Build array from wizard $_POST.
     */
    public static function array_from_post() {
        $data = [
            'post_title'                    => $_POST['post_title'] ?? '',
            'post_content'                  => $_POST['post_content'] ?? '',
            'product_cat'                   => $_POST['product_cat'] ?? '',
            'cw_visibility'                 => $_POST['cw_visibility'] ?? 'visible',
            'regular_price'                 => $_POST['regular_price'] ?? '0',
            'organizer_id'                  => get_current_user_id(),
            'submission_deadline'           => $_POST['submission_deadline'] ?? '',
            'cw_total_prize_value'          => $_POST['cw_total_prize_value'] ?? '',
            'cw_min_participants'           => $_POST['cw_min_participants'] ?? '',
            'cw_max_participants'           => $_POST['cw_max_participants'] ?? '',
            'cw_submission_start'           => $_POST['cw_submission_start'] ?? '',
            'cw_review_start'               => $_POST['cw_review_start'] ?? '',
            'cw_final_event_date'           => $_POST['cw_final_event_date'] ?? '',
            'cw_location_details'           => $_POST['cw_location_details'] ?? '',
            'cw_enable_certificate'         => $_POST['cw_enable_certificate'] ?? '',
            'cw_judging_criteria'           => $_POST['cw_judging_criteria'] ?? '',
            'cw_talk_speaker'               => $_POST['cw_talk_speaker'] ?? '',
            'cw_event_mode'                 => $_POST['cw_event_mode'] ?? '',
            'cw_online_link'                => $_POST['cw_online_link'] ?? '',
            'cw_multi_min'                  => $_POST['cw_multi_min'] ?? '',
            'cw_multi_max'                  => $_POST['cw_multi_max'] ?? '',
            'cw_enable_voting'              => isset( $_POST['cw_enable_voting'] ) ? 'yes' : 'no',
            'multiple_submissions'          => isset( $_POST['multiple_submissions'] ) ? 'true' : 'false',
            'cw_enable_checkout_message'    => isset( $_POST['cw_enable_checkout_message'] ) ? 'yes' : 'no',
            'cw_checkout_message_label'     => $_POST['cw_checkout_message_label'] ?? '',
            'cw_checkout_message_required'  => isset( $_POST['cw_checkout_message_required'] ) ? 'yes' : 'no',
            'cw_school_coupons_optional'    => isset( $_POST['cw_school_coupons_optional'] ) ? 'yes' : 'no',
            'cw_campaign_serial'            => $_POST['cw_campaign_serial'] ?? '',
        ];

        if ( isset( $_POST['cw_faq'] ) ) {
            $data['faq'] = $_POST['cw_faq'];
        }
        if ( isset( $_POST['cw_prizes'] ) ) {
            $data['prizes'] = $_POST['cw_prizes'];
        }
        if ( isset( $_POST['cw_addons'] ) ) {
            $data['addons'] = $_POST['cw_addons'];
        }
        if ( isset( $_POST['sdg_goals'] ) ) {
            $data['sdg_goals'] = $_POST['sdg_goals'];
        }
        if ( isset( $_POST['custom_fields'] ) ) {
            $data['custom_fields'] = $_POST['custom_fields'];
        }
        if ( isset( $_POST['cw_age_brackets'] ) ) {
            $data['age_brackets'] = $_POST['cw_age_brackets'];
        }
        if ( isset( $_POST['cw_school_sponsors'] ) ) {
            $data['schools'] = $_POST['cw_school_sponsors'];
        }

        return $data;
    }

    private static function save_repeater_meta( $pid, $meta_key, $rows, $required_field ) {
        $out = [];
        $i   = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            if ( ! empty( $row[ $required_field ] ) ) {
                $out[ 'item-' . $i ] = array_map( 'sanitize_text_field', $row );
                $i++;
            }
        }
        update_post_meta( $pid, $meta_key, $out );
    }

    private static function save_addons( $pid, $rows ) {
        $allowed_at = [ 'checkbox', 'text', 'textarea', 'number', 'email', 'phone', 'file', 'media', 'select' ];
        $a          = [];
        $i          = 0;
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['addon_title'] ) ) {
                continue;
            }
            $at = strtolower( trim( sanitize_text_field( $row['addon_type'] ?? 'checkbox' ) ) );
            if ( ! in_array( $at, $allowed_at, true ) ) {
                $at = 'checkbox';
            }
            $row['addon_type']  = $at;
            $row['addon_label'] = sanitize_text_field( $row['addon_label'] ?? '' );
            $row['addon_opts']  = sanitize_text_field( $row['addon_opts'] ?? '' );
            $a[ 'item-' . $i ]  = $row;
            $i++;
        }
        update_post_meta( $pid, 'addon_products', $a );
    }

    private static function save_sdg_goals( $pid, $selected ) {
        $map = self::get_sdg_map();
        if ( is_array( $selected ) && isset( $selected[0] ) && is_string( $selected[0] ) && ! is_numeric( $selected[0] ) ) {
            $bool = [];
            foreach ( $map as $id => $name ) {
                $bool[ $name ] = in_array( $name, $selected, true ) ? 'true' : 'false';
            }
            update_post_meta( $pid, 'sdg_goals', $bool );
            return;
        }
        $selected_ids = array_map( 'intval', (array) $selected );
        $bool         = [];
        foreach ( $map as $id => $name ) {
            $bool[ $name ] = in_array( (int) $id, $selected_ids, true ) ? 'true' : 'false';
        }
        update_post_meta( $pid, 'sdg_goals', $bool );
    }

    private static function save_custom_fields( $pid, $rows ) {
        $allowed_cf = [ 'text', 'textarea', 'number', 'email', 'phone', 'file', 'media', 'select', 'wysiwyg' ];
        $fields     = [];
        foreach ( (array) $rows as $f ) {
            if ( empty( $f['label'] ) ) {
                continue;
            }
            $t = strtolower( trim( sanitize_text_field( $f['type'] ?? 'text' ) ) );
            if ( ! in_array( $t, $allowed_cf, true ) ) {
                $t = 'text';
            }
            $fields[] = [
                'label'    => sanitize_text_field( $f['label'] ),
                'type'     => $t,
                'opts'     => isset( $f['opts'] ) ? sanitize_text_field( $f['opts'] ) : '',
                'required' => ! empty( $f['required'] ) ? 1 : 0,
            ];
        }
        update_post_meta( $pid, 'cw_custom_fields', array_values( $fields ) );
    }

    public static function sanitize_age_brackets( $rows ) {
        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['label'] ) ) {
                continue;
            }
            $out[] = [
                'label'            => sanitize_text_field( $row['label'] ),
                'min_age'          => (int) ( $row['min_age'] ?? 0 ),
                'max_age'          => (int) ( $row['max_age'] ?? 99 ),
                'product_cat_slug' => sanitize_title( $row['product_cat_slug'] ?? '' ),
                'key'              => sanitize_key( $row['key'] ?? sanitize_title( $row['label'] ) ),
            ];
        }
        return $out;
    }

    public static function sanitize_schools( $rows ) {
        $out = [];
        foreach ( (array) $rows as $row ) {
            if ( ! is_array( $row ) || empty( $row['school_code'] ) ) {
                continue;
            }
            $out[] = [
                'school_code'  => preg_replace( '/\D/', '', $row['school_code'] ),
                'school_name'  => sanitize_text_field( $row['school_name'] ?? '' ),
                'coupon_code'  => sanitize_text_field( $row['coupon_code'] ?? '' ),
            ];
        }
        return $out;
    }
}
