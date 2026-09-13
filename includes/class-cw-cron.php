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

        // Never delete media still referenced by campaigns (partners, mentors,
        // templates, gallery). Older partner logos vanished because uploads
        // sometimes land with post_parent=0 and this job treated them as orphans.
        $protected = $this->protected_attachment_ids();

        $orphans = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_cw_staged_parent'
             WHERE p.post_type = 'attachment'
             AND p.post_parent = 0
             AND p.post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 100"
        );
        foreach ( (array) $orphans as $aid ) {
            $aid = (int) $aid;
            if ( $aid <= 0 || isset( $protected[ $aid ] ) ) {
                continue;
            }
            $staged_use = $wpdb->get_var(
                $wpdb->prepare(
                    'SELECT COUNT(*) FROM ' . CW_Staged_Submissions::table() . ' WHERE artwork_attachment_id = %d',
                    $aid
                )
            );
            if ( ! $staged_use ) {
                wp_delete_attachment( $aid, true );
            }
        }

        delete_expired_transients();
    }

    /**
     * Attachment IDs referenced by campaign feature meta.
     *
     * @return array<int,true>
     */
    private function protected_attachment_ids() {
        global $wpdb;
        $protected = [];

        $keys = [
            'cw_supporting_partners',
            'cw_mentors',
            'cw_template_files',
            'cw_templates',
            '_product_image_gallery',
            '_thumbnail_id',
        ];
        $placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
                ...$keys
            )
        );

        foreach ( (array) $rows as $row ) {
            $val = maybe_unserialize( $row->meta_value );
            if ( $row->meta_key === '_thumbnail_id' ) {
                $id = (int) $row->meta_value;
                if ( $id > 0 ) {
                    $protected[ $id ] = true;
                }
                continue;
            }
            if ( $row->meta_key === '_product_image_gallery' && is_string( $row->meta_value ) ) {
                foreach ( explode( ',', $row->meta_value ) as $token ) {
                    $id = (int) trim( $token );
                    if ( $id > 0 ) {
                        $protected[ $id ] = true;
                    }
                }
                continue;
            }
            if ( ! is_array( $val ) ) {
                continue;
            }
            foreach ( $val as $item ) {
                if ( is_array( $item ) ) {
                    $id = (int) ( $item['attachment_id'] ?? $item['id'] ?? 0 );
                } else {
                    $id = (int) $item;
                }
                if ( $id > 0 ) {
                    $protected[ $id ] = true;
                }
            }
        }

        return $protected;
    }
}
