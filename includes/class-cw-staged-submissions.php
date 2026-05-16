<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Staged_Submissions {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_staged_submissions';
    }

    public static function tokens_table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_upload_tokens';
    }

    public static function get_by_code( $code, $campaign_id = 0 ) {
        global $wpdb;
        $parsed = CW_Submission_Code::parse( $code );
        if ( ! $parsed['valid'] ) {
            return null;
        }
        $normalized = $parsed['normalized'];
        $table      = self::table();
        $sql        = "SELECT * FROM {$table} WHERE submission_code = %s";
        $params     = [ $normalized ];
        if ( $campaign_id ) {
            $sql     .= ' AND campaign_id = %d';
            $params[] = (int) $campaign_id;
        }
        $row = $wpdb->get_row( $wpdb->prepare( $sql, $params ), ARRAY_A );
        return $row ?: null;
    }

    public static function insert( array $data ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $campaign_id = (int) $data['campaign_id'];
        $mod         = $data['moderation_status'] ?? null;
        if ( ! $mod && class_exists( 'CW_Moderation' ) ) {
            $mod = CW_Moderation::default_moderation_status( $campaign_id );
        }
        $mod = $mod ?: 'approved';

        $field_data = isset( $data['field_data'] ) ? $data['field_data'] : '';
        if ( is_array( $field_data ) ) {
            $field_data = wp_json_encode( array_values( $field_data ) );
        }

        $wpdb->insert(
            self::table(),
            [
                'submission_code'       => $data['submission_code'],
                'campaign_id'           => $campaign_id,
                'school_code'           => $data['school_code'],
                'month_code'            => $data['month_code'],
                'seq_code'              => $data['seq_code'],
                'student_name'          => $data['student_name'],
                'artwork_attachment_id' => (int) ( $data['artwork_attachment_id'] ?? 0 ),
                'field_data'            => $field_data,
                'status'                => 'staged',
                'age_bracket_key'       => $data['age_bracket_key'] ?? '',
                'moderation_status'     => sanitize_key( $mod ),
                'created_at'            => $now,
                'updated_at'            => $now,
            ],
            [ '%s', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s' ]
        );
        return $wpdb->insert_id;
    }

    public static function update( $id, array $data ) {
        global $wpdb;
        if ( isset( $data['field_data'] ) && is_array( $data['field_data'] ) ) {
            $data['field_data'] = wp_json_encode( array_values( $data['field_data'] ) );
        }
        $allowed = [ 'student_name', 'artwork_attachment_id', 'field_data', 'status', 'claimed_by_user_id', 'order_id', 'entry_id', 'checkout_message', 'age_bracket_key', 'moderation_status', 'claim_reserved_by', 'claim_reserved_until' ];
        $update  = [ 'updated_at' => current_time( 'mysql' ) ];
        $formats = [ '%s' ];

        foreach ( $allowed as $key ) {
            if ( array_key_exists( $key, $data ) ) {
                $update[ $key ] = $data[ $key ];
                $formats[]      = in_array( $key, [ 'artwork_attachment_id', 'claimed_by_user_id', 'order_id', 'entry_id', 'claim_reserved_by' ], true ) ? '%d' : '%s';
            }
        }

        return $wpdb->update( self::table(), $update, [ 'id' => (int) $id ], $formats, [ '%d' ] );
    }

    public static function user_has_claimed_campaign( $user_id, $campaign_id ) {
        global $wpdb;
        $table = self::table();
        $count = $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE claimed_by_user_id = %d AND campaign_id = %d AND status = 'claimed'",
            (int) $user_id,
            (int) $campaign_id
        ) );
        return (int) $count > 0;
    }

    public static function get_token( $token ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::tokens_table() . ' WHERE token = %s AND (expires_at IS NULL OR expires_at > %s)',
            sanitize_text_field( $token ),
            current_time( 'mysql' )
        ), ARRAY_A );
    }

    /**
     * Reserve staged row for checkout (prevents double-claim race).
     */
    public static function reserve_for_claim( $staged_id, $user_id ) {
        global $wpdb;
        $table   = self::table();
        $until   = gmdate( 'Y-m-d H:i:s', time() + 900 );
        $now     = current_time( 'mysql' );
        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$table} SET claim_reserved_by = %d, claim_reserved_until = %s, updated_at = %s
                 WHERE id = %d AND status = 'staged'
                 AND (claim_reserved_until IS NULL OR claim_reserved_until < %s OR claim_reserved_by = %d)
                 AND (moderation_status IS NULL OR moderation_status = '' OR moderation_status = 'approved')",
                (int) $user_id,
                $until,
                $now,
                (int) $staged_id,
                $now,
                (int) $user_id
            )
        );
        return $updated > 0;
    }

    public static function create_token( $campaign_id, $school_code, $expires_days = 90 ) {
        global $wpdb;
        $school_code = str_pad( preg_replace( '/\D/', '', (string) $school_code ), 3, '0', STR_PAD_LEFT );
        $token       = wp_generate_password( 32, false, false );
        $wpdb->insert(
            self::tokens_table(),
            [
                'token'       => $token,
                'campaign_id' => (int) $campaign_id,
                'school_code' => $school_code,
                'expires_at'  => gmdate( 'Y-m-d H:i:s', time() + ( (int) $expires_days * DAY_IN_SECONDS ) ),
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%d', '%s', '%s', '%s' ]
        );
        return $token;
    }

    public static function get_or_create_token( $campaign_id, $school_code ) {
        global $wpdb;
        $school_code = str_pad( preg_replace( '/\D/', '', (string) $school_code ), 3, '0', STR_PAD_LEFT );
        $table       = self::tokens_table();
        $existing    = $wpdb->get_var( $wpdb->prepare(
            "SELECT token FROM {$table} WHERE campaign_id = %d AND school_code = %s AND (expires_at IS NULL OR expires_at > %s) ORDER BY id DESC LIMIT 1",
            (int) $campaign_id,
            $school_code,
            current_time( 'mysql' )
        ) );
        if ( $existing ) {
            return $existing;
        }
        return self::create_token( $campaign_id, $school_code );
    }

    public static function get_upload_url( $campaign_id, $school_code ) {
        $token = self::get_or_create_token( $campaign_id, $school_code );
        return home_url( '/cw-school-upload/' . $token . '/' );
    }

    /**
     * Ensure upload tokens exist for all configured schools; cache on product meta.
     *
     * @return array<int, array{school_code:string, school_name:string, url:string}>
     */
    public static function sync_school_upload_tokens( $campaign_id ) {
        $schools = get_post_meta( $campaign_id, 'cw_school_sponsors', true );
        if ( ! is_array( $schools ) ) {
            return [];
        }
        $links = [];
        foreach ( $schools as $s ) {
            if ( empty( $s['school_code'] ) ) {
                continue;
            }
            $code    = str_pad( preg_replace( '/\D/', '', (string) $s['school_code'] ), 3, '0', STR_PAD_LEFT );
            $links[] = [
                'school_code' => $code,
                'school_name' => sanitize_text_field( $s['school_name'] ?? '' ),
                'url'         => self::get_upload_url( $campaign_id, $code ),
            ];
        }
        update_post_meta( $campaign_id, 'cw_school_upload_links', $links );
        return $links;
    }

    public static function get_user_birthdate( $user_id ) {
        $dob = get_user_meta( $user_id, 'birthdate', true );
        if ( ! $dob ) {
            $dob = get_user_meta( $user_id, 'child_birthdate', true );
        }
        return $dob;
    }

    /**
     * Resolve age bracket from user birthdate and campaign brackets.
     *
     * @return array{key:string, label:string}|WP_Error
     */
    public static function resolve_age_bracket( $campaign_id, $birthdate ) {
        $brackets = get_post_meta( $campaign_id, 'cw_age_brackets', true );
        if ( ! is_array( $brackets ) || empty( $brackets ) ) {
            return new WP_Error( 'no_brackets', __( 'This campaign has no age categories configured.', 'creativewings-core' ) );
        }

        $age = self::age_from_birthdate( $birthdate );
        if ( $age === null ) {
            return new WP_Error( 'no_dob', __( 'Please add your date of birth in account settings before claiming.', 'creativewings-core' ) );
        }

        foreach ( $brackets as $b ) {
            $min = (int) ( $b['min_age'] ?? 0 );
            $max = (int) ( $b['max_age'] ?? 99 );
            if ( $age >= $min && $age <= $max ) {
                $key = ! empty( $b['key'] ) ? $b['key'] : sanitize_key( $b['label'] );
                return [ 'key' => $key, 'label' => $b['label'] ];
            }
        }

        return new WP_Error( 'no_match', __( 'Your age does not match any category for this campaign.', 'creativewings-core' ) );
    }

    public static function age_from_birthdate( $birthdate ) {
        if ( ! $birthdate ) {
            return null;
        }
        $ts = strtotime( str_replace( '/', '-', $birthdate ) );
        if ( ! $ts ) {
            return null;
        }
        $today = new DateTime();
        $born  = new DateTime( '@' . $ts );
        $born->setTimezone( wp_timezone() );
        return (int) $born->diff( $today )->y;
    }
}
