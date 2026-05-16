<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Audit_Log {

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_audit_log';
    }

    public static function log( $action, $object_type, $object_id, $details = [] ) {
        global $wpdb;
        $wpdb->insert(
            self::table(),
            [
                'action'      => sanitize_key( $action ),
                'object_type' => sanitize_key( $object_type ),
                'object_id'   => (int) $object_id,
                'user_id'     => get_current_user_id(),
                'details'     => wp_json_encode( $details ),
                'ip_hash'     => class_exists( 'CW_Security' ) ? CW_Security::ip_hash() : '',
                'created_at'  => current_time( 'mysql' ),
            ],
            [ '%s', '%s', '%d', '%d', '%s', '%s', '%s' ]
        );
    }

    public static function get_recent( $object_type, $object_id, $limit = 50 ) {
        global $wpdb;
        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . self::table() . ' WHERE object_type = %s AND object_id = %d ORDER BY id DESC LIMIT %d',
                sanitize_key( $object_type ),
                (int) $object_id,
                (int) $limit
            ),
            ARRAY_A
        );
    }
}
