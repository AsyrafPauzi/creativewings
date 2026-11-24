<?php
/**
 * Light sponsor logo click tracking (B7).
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Partner_Clicks {

    const META_TOTAL = '_cw_partner_logo_clicks';
    const OPTION_MAP = 'cw_partner_logo_click_map'; // campaign_id => [ partner_key => count ]

    public static function register_hooks() {
        add_action( 'wp_ajax_cw_partner_logo_click', [ __CLASS__, 'ajax_click' ] );
        add_action( 'wp_ajax_nopriv_cw_partner_logo_click', [ __CLASS__, 'ajax_click' ] );
        add_action( 'wp_enqueue_scripts', [ __CLASS__, 'maybe_enqueue_script' ], 40 );
    }

    /**
     * Stable key for a partner row.
     *
     * @param array<string,mixed> $partner
     * @return string
     */
    public static function partner_key( array $partner ) {
        $aid = (int) ( $partner['attachment_id'] ?? 0 );
        $raw = $aid . '|' . (string) ( $partner['name'] ?? '' ) . '|' . (string) ( $partner['url'] ?? '' );
        return substr( 'p' . md5( $raw ), 0, 40 );
    }

    /**
     * @param int $campaign_id
     * @return int
     */
    public static function get_campaign_total( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id <= 0 ) {
            return 0;
        }
        return max( 0, (int) get_post_meta( $campaign_id, self::META_TOTAL, true ) );
    }

    public static function ajax_click() {
        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        $partner_key = isset( $_POST['partner_key'] ) ? sanitize_key( wp_unslash( $_POST['partner_key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( strlen( $partner_key ) > 64 ) {
            $partner_key = substr( $partner_key, 0, 64 );
        }

        if ( $campaign_id <= 0 || $partner_key === '' ) {
            wp_send_json_error( [ 'message' => 'bad request' ], 400 );
        }

        // Soft rate limit per IP.
        $ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
        $rl = 'cw_plclick_' . md5( $ip . '|' . $campaign_id . '|' . $partner_key );
        if ( get_transient( $rl ) ) {
            wp_send_json_success( [ 'ok' => true, 'rate_limited' => true ] );
        }
        set_transient( $rl, 1, 20 );

        $total = self::get_campaign_total( $campaign_id ) + 1;
        update_post_meta( $campaign_id, self::META_TOTAL, $total );

        $map = get_option( self::OPTION_MAP, [] );
        if ( ! is_array( $map ) ) {
            $map = [];
        }
        if ( ! isset( $map[ $campaign_id ] ) || ! is_array( $map[ $campaign_id ] ) ) {
            $map[ $campaign_id ] = [];
        }
        $map[ $campaign_id ][ $partner_key ] = (int) ( $map[ $campaign_id ][ $partner_key ] ?? 0 ) + 1;
        update_option( self::OPTION_MAP, $map, false );

        wp_send_json_success( [ 'ok' => true, 'total' => $total ] );
    }

    public static function maybe_enqueue_script() {
        $need = ( function_exists( 'is_product' ) && is_product() ) || is_page( 'sponsors' );
        if ( ! $need ) {
            return;
        }

        $handle = 'cw-partner-clicks';
        $src    = CW_URL . 'assets/js/cw-partner-clicks.js';
        wp_enqueue_script( $handle, $src, [], CW_VERSION, true );
        wp_localize_script(
            $handle,
            'cwPartnerClicks',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'action'  => 'cw_partner_logo_click',
            ]
        );
    }
}
