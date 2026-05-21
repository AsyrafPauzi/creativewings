<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Export {

    public function __construct() {
        add_action( 'admin_post_cw_export_submissions', [ $this, 'export_csv' ] );
    }

    public function export_csv() {
        check_admin_referer( 'cw_export_submissions' );

        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        $school      = sanitize_text_field( $_GET['school_code'] ?? '' );

        // Admin/WC manager can export any campaign; otherwise only the owning business user.
        $is_admin   = current_user_can( 'manage_woocommerce' );
        $is_owner   = $campaign_id && class_exists( 'CW_Roles' ) && CW_Roles::user_owns_campaign( $campaign_id, get_current_user_id() );
        if ( ! $is_admin && ! $is_owner ) {
            wp_die( 'Unauthorized', 403 );
        }

        global $wpdb;
        $table = CW_Staged_Submissions::table();
        $sql   = "SELECT * FROM {$table} WHERE campaign_id = %d";
        $args  = [ $campaign_id ];
        if ( $school ) {
            $sql   .= ' AND school_code = %s';
            $args[] = str_pad( preg_replace( '/\D/', '', $school ), 3, '0', STR_PAD_LEFT );
        }
        $sql  .= ' ORDER BY submission_code ASC';
        $rows  = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

        $filename = 'cw-submissions-' . $campaign_id . ( $school ? '-' . $school : '' ) . '-' . gmdate( 'Y-m-d' ) . '.csv';
        header( 'Content-Type: text/csv; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename=' . $filename );
        $out = fopen( 'php://output', 'w' );
        fputcsv( $out, [ 'submission_code', 'student_name', 'school_code', 'status', 'moderation', 'claimed_user', 'order_id', 'created_at' ] );
        foreach ( (array) $rows as $r ) {
            fputcsv( $out, [
                $r['submission_code'],
                $r['student_name'],
                $r['school_code'],
                $r['status'],
                $r['moderation_status'] ?? '',
                $r['claimed_by_user_id'] ?? '',
                $r['order_id'] ?? '',
                $r['created_at'],
            ] );
        }
        fclose( $out );
        exit;
    }
}
