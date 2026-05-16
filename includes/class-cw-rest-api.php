<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_REST_API {

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    public static function verify_request( WP_REST_Request $request ) {
        if ( current_user_can( 'manage_woocommerce' ) ) {
            return true;
        }
        $secret = get_option( 'cw_webhook_secret', '' );
        if ( $secret && $request->get_header( 'x-cw-webhook-secret' ) === $secret ) {
            return true;
        }
        return new WP_Error( 'rest_forbidden', __( 'Invalid API credentials.', 'creativewings-core' ), [ 'status' => 403 ] );
    }

    public function register_routes() {
        register_rest_route(
            'creativewings/v1',
            '/campaigns/(?P<id>\d+)/submissions',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'list_submissions' ],
                'permission_callback' => [ $this, 'verify_request' ],
            ]
        );

        register_rest_route(
            'creativewings/v1',
            '/campaigns/(?P<id>\d+)/kpis',
            [
                'methods'             => 'GET',
                'callback'            => [ $this, 'get_kpis' ],
                'permission_callback' => [ $this, 'verify_request' ],
            ]
        );

        register_rest_route(
            'creativewings/v1',
            '/webhook/test',
            [
                'methods'             => 'POST',
                'callback'            => [ $this, 'webhook_test' ],
                'permission_callback' => [ $this, 'verify_request' ],
            ]
        );
    }

    public function list_submissions( WP_REST_Request $request ) {
        global $wpdb;
        $campaign_id = (int) $request['id'];
        $rows        = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT id, submission_code, student_name, school_code, status, moderation_status, created_at FROM ' . CW_Staged_Submissions::table() . ' WHERE campaign_id = %d ORDER BY id DESC LIMIT 500',
                $campaign_id
            ),
            ARRAY_A
        );
        return rest_ensure_response( [ 'campaign_id' => $campaign_id, 'submissions' => $rows ] );
    }

    public function get_kpis( WP_REST_Request $request ) {
        $campaign_id = (int) $request['id'];
        return rest_ensure_response( CW_Campaign_Admin::get_kpis( $campaign_id ) );
    }

    public function webhook_test( WP_REST_Request $request ) {
        return rest_ensure_response(
            [
                'ok'      => true,
                'message' => 'Creative Wings webhook reachable',
                'time'    => current_time( 'mysql' ),
            ]
        );
    }
}
