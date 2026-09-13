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

        // IMPORTANT: Do NOT delete Media Library attachments with post_parent=0.
        // Creative Wings uploads (profile, cover, portfolio, partners, mentors,
        // design artwork, etc.) intentionally use media_handle_upload( ..., 0 ).
        // The old orphan sweep deleted real plugin media and blanked profiles.
        //
        // Only remove clearly marked temporary CW uploads that are no longer
        // referenced by an active staged submission row.
        $this->cleanup_temp_cw_uploads();

        delete_expired_transients();
    }

    /**
     * Delete only attachments explicitly marked as temporary CW uploads
     * (_cw_temp_upload=1), older than 7 days, and unused by staged submissions.
     */
    private function cleanup_temp_cw_uploads() {
        global $wpdb;

        $ids = $wpdb->get_col(
            "SELECT p.ID FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_cw_temp_upload' AND pm.meta_value = '1'
             WHERE p.post_type = 'attachment'
             AND p.post_date < DATE_SUB(NOW(), INTERVAL 7 DAY)
             LIMIT 50"
        );

        if ( empty( $ids ) ) {
            return;
        }

        $protected = $this->protected_attachment_ids();
        $staged_table = class_exists( 'CW_Staged_Submissions' ) ? CW_Staged_Submissions::table() : '';

        foreach ( (array) $ids as $aid ) {
            $aid = (int) $aid;
            if ( $aid <= 0 || isset( $protected[ $aid ] ) ) {
                continue;
            }

            if ( $staged_table ) {
                $staged_use = (int) $wpdb->get_var(
                    $wpdb->prepare(
                        "SELECT COUNT(*) FROM {$staged_table} WHERE artwork_attachment_id = %d",
                        $aid
                    )
                );
                if ( $staged_use > 0 ) {
                    continue;
                }
            }

            wp_delete_attachment( $aid, true );
        }
    }

    /**
     * Attachment IDs referenced anywhere by Creative Wings plugin data.
     *
     * Used as a hard safety net for any cleanup path.
     *
     * @return array<int,true>
     */
    private function protected_attachment_ids() {
        global $wpdb;
        $protected = [];

        $mark = static function ( $id ) use ( &$protected ) {
            $id = (int) $id;
            if ( $id > 0 ) {
                $protected[ $id ] = true;
            }
        };

        $collect_from_value = static function ( $val ) use ( &$mark, &$collect_from_value ) {
            if ( is_numeric( $val ) ) {
                $mark( $val );
                return;
            }
            if ( ! is_array( $val ) ) {
                return;
            }
            // Shape: { id: N, url: ... }
            if ( isset( $val['id'] ) || isset( $val['attachment_id'] ) ) {
                $mark( $val['id'] ?? $val['attachment_id'] );
            }
            foreach ( $val as $item ) {
                if ( $item === $val ) {
                    continue;
                }
                $collect_from_value( $item );
            }
        };

        // ── Post meta (campaign / product / entry) ───────────────────────
        $post_keys = [
            'cw_supporting_partners',
            'cw_mentors',
            'cw_template_files',
            'cw_templates',
            'cw_design_variants',
            'cw_variant_images',
            '_product_image_gallery',
            '_thumbnail_id',
            'cw_design_artwork_id',
            'cw_cert_template_id',
            'rank_math_facebook_image_id',
            'rank_math_twitter_image_id',
        ];
        $placeholders = implode( ',', array_fill( 0, count( $post_keys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ($placeholders)",
                ...$post_keys
            )
        );
        foreach ( (array) $rows as $row ) {
            if ( in_array( $row->meta_key, [ '_thumbnail_id', 'cw_design_artwork_id', 'cw_cert_template_id', 'rank_math_facebook_image_id', 'rank_math_twitter_image_id' ], true ) ) {
                $mark( $row->meta_value );
                continue;
            }
            if ( $row->meta_key === '_product_image_gallery' && is_string( $row->meta_value ) ) {
                foreach ( explode( ',', $row->meta_value ) as $token ) {
                    $mark( trim( $token ) );
                }
                continue;
            }
            $collect_from_value( maybe_unserialize( $row->meta_value ) );
        }

        // Multi-slot design artwork arrays + any serialized lists holding attachment ids.
        $extra_post = $wpdb->get_results(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key IN ('cw_design_artwork_ids','cw_school_upload_links')
             LIMIT 5000"
        );
        foreach ( (array) $extra_post as $row ) {
            $collect_from_value( maybe_unserialize( $row->meta_value ) );
        }

        // Entry artwork often stored as attachment URL in upload_document — map back if possible.
        // Also protect numeric upload_document values if any.
        $doc_rows = $wpdb->get_col(
            "SELECT meta_value FROM {$wpdb->postmeta}
             WHERE meta_key = 'upload_document' AND meta_value <> ''
             LIMIT 5000"
        );
        foreach ( (array) $doc_rows as $doc ) {
            if ( is_numeric( $doc ) ) {
                $mark( $doc );
            }
        }

        // ── User meta (profile / business) ───────────────────────────────
        $user_keys = [
            'creator_profile_image',
            'creator_header_image',
            'business_logo',
            'business_cover',
        ];
        $u_placeholders = implode( ',', array_fill( 0, count( $user_keys ), '%s' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $user_rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key IN ($u_placeholders)",
                ...$user_keys
            )
        );
        foreach ( (array) $user_rows as $row ) {
            $collect_from_value( maybe_unserialize( $row->meta_value ) );
        }

        // ── Creator portfolio CCT (image + gallery) ──────────────────────
        $portfolio_table = $wpdb->prefix . 'jet_cct_creator_portfolio';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $portfolio_table ) ) === $portfolio_table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $port_rows = $wpdb->get_results( "SELECT image, gallery FROM {$portfolio_table}" );
            foreach ( (array) $port_rows as $row ) {
                $collect_from_value( maybe_unserialize( $row->image ) );
                $collect_from_value( maybe_unserialize( $row->gallery ) );
            }
        }

        // ── Points merchandise images ───────────────────────────────────
        $rewards_table = $wpdb->prefix . 'cw_points_rewards';
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $rewards_table ) ) === $rewards_table ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $reward_ids = $wpdb->get_col( "SELECT image_id FROM {$rewards_table} WHERE image_id > 0" );
            foreach ( (array) $reward_ids as $rid ) {
                $mark( $rid );
            }
        }

        // ── Staged submission artwork still in play ─────────────────────
        if ( class_exists( 'CW_Staged_Submissions' ) ) {
            $staged = CW_Staged_Submissions::table();
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $staged ) ) === $staged ) {
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $staged_ids = $wpdb->get_col( "SELECT artwork_attachment_id FROM {$staged} WHERE artwork_attachment_id > 0" );
                foreach ( (array) $staged_ids as $sid ) {
                    $mark( $sid );
                }
            }
        }

        // ── Anything already tagged as permanent CW plugin media ────────
        $tagged = $wpdb->get_col(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_cw_plugin_media' AND meta_value = '1'"
        );
        foreach ( (array) $tagged as $tid ) {
            $mark( $tid );
        }

        return $protected;
    }
}
