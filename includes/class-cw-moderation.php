<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Moderation {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'metabox' ] );
        add_action( 'admin_post_cw_moderate_staged', [ $this, 'handle_action' ] );
    }

    public static function campaign_requires_moderation( $campaign_id ) {
        return get_post_meta( (int) $campaign_id, 'cw_enable_moderation', true ) === 'yes';
    }

    public static function default_moderation_status( $campaign_id ) {
        return self::campaign_requires_moderation( $campaign_id ) ? 'pending' : 'approved';
    }

    public static function is_visible( $staged_row ) {
        $status = $staged_row['moderation_status'] ?? 'approved';
        return 'approved' === $status;
    }

    public function metabox() {
        add_meta_box(
            'cw_moderation_queue',
            __( 'Submission moderation', 'creativewings-core' ),
            [ $this, 'render_queue' ],
            'product',
            'normal',
            'default'
        );
    }

    public function render_queue( $post ) {
        if ( ! current_user_can( 'edit_products' ) ) {
            return;
        }
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE campaign_id = %d AND moderation_status = %s ORDER BY id DESC LIMIT 20',
                (int) $post->ID,
                'pending'
            ),
            ARRAY_A
        );
        if ( empty( $rows ) ) {
            echo '<p>' . esc_html__( 'No pending submissions.', 'creativewings-core' ) . '</p>';
            return;
        }
        echo '<table class="widefat"><thead><tr><th>Code</th><th>Name</th><th>Art</th><th></th></tr></thead><tbody>';
        foreach ( $rows as $r ) {
            $img = ! empty( $r['artwork_attachment_id'] ) ? wp_get_attachment_image( (int) $r['artwork_attachment_id'], [ 60, 60 ] ) : '—';
            echo '<tr>';
            echo '<td>' . esc_html( $r['submission_code'] ) . '</td>';
            echo '<td>' . esc_html( $r['student_name'] ) . '</td>';
            echo '<td>' . $img . '</td>';
            echo '<td>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:inline">';
            wp_nonce_field( 'cw_moderate_staged', 'cw_mod_nonce' );
            echo '<input type="hidden" name="action" value="cw_moderate_staged">';
            echo '<input type="hidden" name="staged_id" value="' . (int) $r['id'] . '">';
            echo '<input type="hidden" name="campaign_id" value="' . (int) $post->ID . '">';
            echo '<button class="button button-small" name="mod_action" value="approve">' . esc_html__( 'Approve', 'creativewings-core' ) . '</button> ';
            echo '<button class="button button-small" name="mod_action" value="reject">' . esc_html__( 'Reject', 'creativewings-core' ) . '</button>';
            echo '</form></td></tr>';
        }
        echo '</tbody></table>';
    }

    public function handle_action() {
        if ( ! current_user_can( 'edit_products' ) || ! wp_verify_nonce( $_POST['cw_mod_nonce'] ?? '', 'cw_moderate_staged' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        $staged_id   = (int) ( $_POST['staged_id'] ?? 0 );
        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $action      = sanitize_text_field( $_POST['mod_action'] ?? '' );
        $status      = 'approve' === $action ? 'approved' : ( 'reject' === $action ? 'rejected' : '' );
        if ( ! $status ) {
            wp_safe_redirect( wp_get_referer() );
            exit;
        }
        CW_Staged_Submissions::update( $staged_id, [ 'moderation_status' => $status ] );
        if ( class_exists( 'CW_Audit_Log' ) ) {
            CW_Audit_Log::log( 'moderation_' . $status, 'staged', $staged_id, [ 'campaign_id' => $campaign_id ] );
        }
        wp_safe_redirect( wp_get_referer() );
        exit;
    }
}
