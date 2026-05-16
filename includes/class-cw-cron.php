<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Cron {

    const HOOK_CLEANUP = 'cw_daily_cleanup';

    public function __construct() {
        add_action( self::HOOK_CLEANUP, [ $this, 'run_cleanup' ] );
        add_action( 'init', [ $this, 'schedule' ] );
    }

    public function schedule() {
        if ( ! wp_next_scheduled( self::HOOK_CLEANUP ) ) {
            wp_schedule_event( time(), 'daily', self::HOOK_CLEANUP );
        }
    }

    public function run_cleanup() {
        global $wpdb;
        $tokens = CW_Staged_Submissions::tokens_table();
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$tokens} WHERE expires_at IS NOT NULL AND expires_at < %s",
                current_time( 'mysql' )
            )
        );

        $orphans = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_cw_staged_parent'
             WHERE p.post_type = 'attachment'
             AND p.post_parent = 0
             AND p.post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 100"
        );
        foreach ( (array) $orphans as $aid ) {
            $staged_use = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . CW_Staged_Submissions::table() . ' WHERE artwork_attachment_id = %d',
                    (int) $aid
                )
            );
            if ( ! $staged_use ) {
                wp_delete_attachment( (int) $aid, true );
            }
        }

        delete_expired_transients();
    }
}
