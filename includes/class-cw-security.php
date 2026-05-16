<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Rate limits, claim sessions, upload validation.
 */
class CW_Security {

    const RATE_PIC_UPLOAD   = 'cw_rate_pic_';
    const RATE_REGISTRATION = 'cw_rate_reg_';
    const CLAIM_TRANSIENT   = 'cw_claim_sess_';

    public static function client_ip() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        return sanitize_text_field( $ip );
    }

    public static function ip_hash() {
        return hash( 'sha256', self::client_ip() . wp_salt( 'auth' ) );
    }

    /**
     * @return true|WP_Error
     */
    public static function rate_limit( $key, $max_attempts = 30, $window_seconds = 3600 ) {
        $bucket = $key . md5( self::client_ip() );
        $data   = get_transient( $bucket );
        if ( ! is_array( $data ) ) {
            $data = [ 'count' => 0, 'start' => time() ];
        }
        if ( time() - (int) $data['start'] > $window_seconds ) {
            $data = [ 'count' => 0, 'start' => time() ];
        }
        $data['count']++;
        set_transient( $bucket, $data, $window_seconds );
        if ( $data['count'] > $max_attempts ) {
            return new WP_Error( 'rate_limit', __( 'Too many attempts. Please try again later.', 'creativewings-core' ) );
        }
        return true;
    }

    public static function set_claim_session( $user_id, $staged_id, $campaign_id ) {
        $token = wp_generate_password( 32, false, false );
        set_transient(
            self::CLAIM_TRANSIENT . (int) $user_id,
            [
                'token'       => $token,
                'staged_id'   => (int) $staged_id,
                'campaign_id' => (int) $campaign_id,
            ],
            15 * MINUTE_IN_SECONDS
        );
        return $token;
    }

    public static function get_claim_session( $user_id ) {
        $data = get_transient( self::CLAIM_TRANSIENT . (int) $user_id );
        return is_array( $data ) ? $data : null;
    }

    public static function verify_claim_token( $user_id, $token ) {
        $data = self::get_claim_session( $user_id );
        return $data && ! empty( $data['token'] ) && hash_equals( $data['token'], (string) $token );
    }

    public static function clear_claim_session( $user_id ) {
        delete_transient( self::CLAIM_TRANSIENT . (int) $user_id );
    }

    /**
     * @return int|WP_Error Attachment ID.
     */
    public static function handle_image_upload( $file_key = 'artwork', $max_bytes = 5242880 ) {
        if ( empty( $_FILES[ $file_key ]['name'] ) ) {
            return new WP_Error( 'no_file', __( 'No file uploaded.', 'creativewings-core' ) );
        }

        $file = $_FILES[ $file_key ];
        if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_bytes ) {
            return new WP_Error( 'file_large', __( 'Image must be 5MB or smaller.', 'creativewings-core' ) );
        }

        $check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
        $allowed = [ 'jpg', 'jpeg', 'png', 'gif', 'webp' ];
        if ( empty( $check['ext'] ) || ! in_array( strtolower( $check['ext'] ), $allowed, true ) ) {
            return new WP_Error( 'file_type', __( 'Only JPG, PNG, GIF, or WebP images are allowed.', 'creativewings-core' ) );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $aid = media_handle_upload( $file_key, 0 );
        return is_wp_error( $aid ) ? $aid : (int) $aid;
    }
}
