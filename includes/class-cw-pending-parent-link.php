<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parent pre-registers a submission code before the school uploads artwork.
 */
class CW_Pending_Parent_Link {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_pending_parent_links';
    }

    public static function save( $user_id, array $parsed, $campaign_id ) {
        global $wpdb;
        $now = current_time( 'mysql' );
        $data = [
            'user_id'         => (int) $user_id,
            'submission_code' => $parsed['normalized'],
            'campaign_id'     => (int) $campaign_id,
            'school_code'     => $parsed['school'],
            'month_code'      => $parsed['month'],
            'seq_code'        => $parsed['seq'],
            'updated_at'      => $now,
        ];

        $existing = self::get_for_user_campaign( $user_id, $campaign_id );
        if ( $existing ) {
            $wpdb->update(
                self::table(),
                $data,
                [ 'id' => (int) $existing['id'] ],
                [ '%d', '%s', '%d', '%s', '%s', '%s', '%s' ],
                [ '%d' ]
            );
            return (int) $existing['id'];
        }

        $data['created_at'] = $now;
        $wpdb->insert(
            self::table(),
            $data,
            [ '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );
        return (int) $wpdb->insert_id;
    }

    public static function get_by_code( $code, $campaign_id ) {
        global $wpdb;
        $parsed = CW_Submission_Code::parse( $code );
        if ( ! $parsed['valid'] ) {
            return null;
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE submission_code = %s AND campaign_id = %d',
            $parsed['normalized'],
            (int) $campaign_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public static function get_for_user_campaign( $user_id, $campaign_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d AND campaign_id = %d',
            (int) $user_id,
            (int) $campaign_id
        ), ARRAY_A );
        return $row ?: null;
    }

    public static function user_has_pending( $user_id, $campaign_id ) {
        return (bool) self::get_for_user_campaign( $user_id, $campaign_id );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function list_for_user( $user_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE user_id = %d ORDER BY updated_at DESC',
            (int) $user_id
        ), ARRAY_A );
        return $rows ?: [];
    }

    public static function delete_for_user_campaign( $user_id, $campaign_id ) {
        global $wpdb;
        $wpdb->delete(
            self::table(),
            [
                'user_id'     => (int) $user_id,
                'campaign_id' => (int) $campaign_id,
            ],
            [ '%d', '%d' ]
        );
    }

    /**
     * Remove a pending link only if it belongs to the user (wrong code entered).
     */
    public static function delete_for_user_by_id( $user_id, $pending_id ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE id = %d AND user_id = %d',
            (int) $pending_id,
            (int) $user_id
        ), ARRAY_A );
        if ( ! $row ) {
            return false;
        }
        $wpdb->delete( self::table(), [ 'id' => (int) $row['id'] ], [ '%d' ] );
        return true;
    }

    public static function delete_by_code( $code, $campaign_id ) {
        global $wpdb;
        $parsed = CW_Submission_Code::parse( $code );
        if ( ! $parsed['valid'] ) {
            return;
        }
        $wpdb->delete(
            self::table(),
            [
                'submission_code' => $parsed['normalized'],
                'campaign_id'     => (int) $campaign_id,
            ],
            [ '%s', '%d' ]
        );
    }

    /**
     * Notify parents who pre-linked when school uploads artwork.
     */
    public static function on_staged_uploaded( $staged_id, $campaign_id, $submission_code ) {
        global $wpdb;
        $parsed = CW_Submission_Code::parse( $submission_code );
        if ( ! $parsed['valid'] ) {
            return;
        }

        $row = CW_Staged_Submissions::get_by_code( $parsed['normalized'], $campaign_id );
        $ready = $row && (
            class_exists( 'CW_Campaign_Fields' )
                ? CW_Campaign_Fields::staged_has_required_uploads( $row, $campaign_id )
                : (int) ( $row['artwork_attachment_id'] ?? 0 ) > 0
        );
        if ( ! $ready ) {
            return;
        }
        if ( ( $row['status'] ?? '' ) === 'claimed' ) {
            self::delete_by_code( $parsed['normalized'], $campaign_id );
            return;
        }

        $pending_rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT * FROM ' . self::table() . ' WHERE submission_code = %s AND campaign_id = %d',
            $parsed['normalized'],
            (int) $campaign_id
        ), ARRAY_A );

        if ( empty( $pending_rows ) ) {
            return;
        }

        foreach ( $pending_rows as $pending ) {
            do_action( 'cw_pending_ready_for_claim', (int) $pending['user_id'], $row, (int) $campaign_id );
        }
    }
}
