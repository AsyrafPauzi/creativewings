<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Email {

    public function __construct() {
        add_action( 'cw_staged_claimed', [ $this, 'on_claimed' ], 10, 3 );
        add_action( 'cw_pending_ready_for_claim', [ $this, 'on_pending_ready' ], 10, 3 );
        add_action( 'cw_order_entry_created', [ $this, 'on_order_complete' ], 10, 4 );
        add_action( 'cw_certificate_ready', [ $this, 'on_cert_ready' ], 10, 3 );
    }

    public static function send( $to, $subject, $body ) {
        if ( ! $to || ! is_email( $to ) ) {
            return false;
        }
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        return wp_mail( $to, $subject, wp_kses_post( $body ), $headers );
    }

    public function on_pending_ready( $user_id, $staged_row, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $link = ( function_exists( 'cw_core' ) && cw_core()->claim_flow )
            ? cw_core()->claim_flow->get_link_submission_url( [ 'step' => 'waiting' ] )
            : add_query_arg( 'step', 'waiting', wc_get_account_endpoint_url( 'cw-link-submission' ) );
        $title = get_the_title( $campaign_id );
        $body  = sprintf(
            '<p>%s</p><p><strong>%s</strong> — %s</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Your school has uploaded the artwork for your submission code. You can now confirm the student name and complete registration.', 'creativewings-core' ),
            esc_html( $staged_row['student_name'] ?? '' ),
            esc_html( $staged_row['submission_code'] ?? '' ),
            esc_url( $link ),
            esc_html__( 'Continue linking your code', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Artwork ready — complete your registration', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_claimed( $user_id, $staged_row, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $title = get_the_title( $campaign_id );
        $body  = sprintf(
            '<p>%s</p><p><strong>%s</strong> — %s</p><p>%s</p>',
            esc_html__( 'Your submission code has been linked successfully.', 'creativewings-core' ),
            esc_html( $staged_row['student_name'] ?? '' ),
            esc_html( $staged_row['submission_code'] ?? '' ),
            esc_html( $title )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Submission linked', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_order_complete( $user_id, $entry_id, $campaign_id, $order_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $account = wc_get_account_endpoint_url( 'orders' );
        $body    = sprintf(
            '<p>%s</p><p>%s: #%d</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Thank you — your campaign registration is complete.', 'creativewings-core' ),
            esc_html__( 'Order', 'creativewings-core' ),
            (int) $order_id,
            esc_url( $account ),
            esc_html__( 'View your account', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Registration complete', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_cert_ready( $user_id, $entry_id, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $url  = class_exists( 'CW_Certificate' ) ? CW_Certificate::download_url( $entry_id ) : '';
        $body = sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Your participation certificate is ready to download.', 'creativewings-core' ),
            esc_url( $url ),
            esc_html__( 'Download certificate', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Certificate ready', 'creativewings-core' ) ),
            $body
        );
    }
}
