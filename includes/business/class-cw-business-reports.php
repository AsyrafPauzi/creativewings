<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Business reporting service.
 *
 * Single source of truth for KPI rollups, time-series, campaign comparison,
 * participant roster, and staged-submission data used by both the Reports
 * UI and the multi-format export handler (CSV / Excel / PDF).
 *
 * Always scopes by the calling business user's owned campaigns.
 */
class CW_Business_Reports {

    const DEFAULT_RANGE = '30';

    /**
     * Allowed range slugs and the number of days they cover.
     * 'all' has no day cap.
     *
     * @return array<string, int|null>
     */
    public static function range_options() {
        return [
            '7'   => 7,
            '30'  => 30,
            '90'  => 90,
            '180' => 180,
            '365' => 365,
            'all' => null,
        ];
    }

    public static function range_label( $range ) {
        $labels = [
            '7'   => __( 'Last 7 days', 'creativewings-core' ),
            '30'  => __( 'Last 30 days', 'creativewings-core' ),
            '90'  => __( 'Last 90 days', 'creativewings-core' ),
            '180' => __( 'Last 6 months', 'creativewings-core' ),
            '365' => __( 'Last 1 year', 'creativewings-core' ),
            'all' => __( 'All time', 'creativewings-core' ),
        ];
        return $labels[ $range ] ?? $labels[ self::DEFAULT_RANGE ];
    }

    public static function sanitize_range( $range ) {
        $range = (string) $range;
        return array_key_exists( $range, self::range_options() ) ? $range : self::DEFAULT_RANGE;
    }

    /**
     * Owned campaign IDs (publish, pending, draft) for the user.
     *
     * @return int[]
     */
    public static function owned_campaign_ids( $user_id ) {
        $user_id = (int) $user_id;
        if ( ! $user_id ) {
            return [];
        }

        $args = class_exists( 'CW_Roles' )
            ? CW_Roles::get_business_campaign_query_args( $user_id )
            : [
                'post_type'      => 'product',
                'post_status'    => [ 'publish', 'pending', 'draft' ],
                'author'         => $user_id,
                'posts_per_page' => -1,
            ];
        $args['fields'] = 'ids';

        $ids = get_posts( $args );
        return array_map( 'intval', (array) $ids );
    }

    /**
     * Verify the given campaign ID is owned by the user (admin overrides).
     */
    public static function user_can_view_campaign( $campaign_id, $user_id ) {
        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id ) {
            return true; // 0 means "all campaigns"
        }
        if ( ! class_exists( 'CW_Roles' ) ) {
            return (int) get_post_field( 'post_author', $campaign_id ) === (int) $user_id;
        }
        return CW_Roles::user_owns_campaign( $campaign_id, $user_id );
    }

    /**
     * Resolve the inclusive UTC date string used as the lower bound of the range.
     */
    private static function range_start_date( $range ) {
        $days = self::range_options()[ $range ] ?? null;
        if ( ! $days ) {
            return null;
        }
        $ts = current_time( 'timestamp' ) - ( ( (int) $days - 1 ) * DAY_IN_SECONDS );
        return date_i18n( 'Y-m-d 00:00:00', $ts );
    }

    /**
     * Resolve which post IDs the report should operate on.
     *
     * @return int[]
     */
    private static function resolve_target_ids( $user_id, $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( $campaign_id > 0 ) {
            return self::user_can_view_campaign( $campaign_id, $user_id ) ? [ $campaign_id ] : [];
        }
        return self::owned_campaign_ids( $user_id );
    }

    /**
     * Build the full report context.
     *
     * @return array<string, mixed>
     */
    public static function get_context( $user_id, $campaign_id = 0, $range = self::DEFAULT_RANGE ) {
        $user_id     = (int) $user_id;
        $campaign_id = (int) $campaign_id;
        $range       = self::sanitize_range( $range );

        $ids = self::resolve_target_ids( $user_id, $campaign_id );

        $context = [
            'user_id'        => $user_id,
            'campaign_id'    => $campaign_id,
            'range'          => $range,
            'range_label'    => self::range_label( $range ),
            'range_start'    => self::range_start_date( $range ),
            'generated_at'   => current_time( 'mysql' ),
            'campaign_ids'   => $ids,
            'is_all'         => ( $campaign_id === 0 ),
            'campaign_title' => $campaign_id > 0 ? get_the_title( $campaign_id ) : __( 'All campaigns', 'creativewings-core' ),
            'business_name'  => get_user_meta( $user_id, 'business_name', true ) ?: wp_get_current_user()->display_name,
            'kpis'           => self::empty_kpis(),
            'campaigns'      => [],
            'roster'         => [],
            'staged'         => [],
            'timeseries'     => [
                'entries' => [ 'labels' => [], 'data' => [] ],
                'revenue' => [ 'labels' => [], 'data' => [] ],
            ],
            'breakdowns'     => [
                'category' => [],
                'status'   => [],
                'school'   => [],
                'scores'   => [],
            ],
            'has_competitions' => false,
            'has_staged'       => false,
        ];

        if ( empty( $ids ) ) {
            return $context;
        }

        $context['kpis']             = self::compute_kpis( $ids, $range );
        $context['campaigns']        = self::compute_campaigns( $ids );
        $context['roster']           = self::compute_roster( $ids, $range );
        $context['staged']           = self::compute_staged( $ids, $range );
        $context['timeseries']       = self::compute_timeseries( $ids, $range );
        $context['breakdowns']       = self::compute_breakdowns( $ids );
        $context['has_competitions'] = ! empty( array_filter( $context['campaigns'], static function ( $c ) {
            return ( $c['type_key'] ?? '' ) === 'competition';
        } ) );
        $context['has_staged']       = ! empty( $context['staged'] );

        return $context;
    }

    /**
     * Slim "context" tailored to the dashboard view — same aggregates as
     * get_context(), but skips the eager loads of full roster + staged tables.
     * The view fetches paged slices via get_roster_page() / get_staged_page() /
     * get_campaigns_page() so we never materialise more than ~25 rows at a time
     * regardless of total volume.
     *
     * Exports continue to use the eager-loading get_context() so the CSV/XLSX/PDF
     * pipeline still receives the complete data set.
     */
    public static function get_dashboard_context( $user_id, $campaign_id = 0, $range = self::DEFAULT_RANGE ) {
        $user_id     = (int) $user_id;
        $campaign_id = (int) $campaign_id;
        $range       = self::sanitize_range( $range );
        $ids         = self::resolve_target_ids( $user_id, $campaign_id );

        $context = [
            'user_id'        => $user_id,
            'campaign_id'    => $campaign_id,
            'range'          => $range,
            'range_label'    => self::range_label( $range ),
            'range_start'    => self::range_start_date( $range ),
            'generated_at'   => current_time( 'mysql' ),
            'campaign_ids'   => $ids,
            'is_all'         => ( $campaign_id === 0 ),
            'campaign_title' => $campaign_id > 0 ? get_the_title( $campaign_id ) : __( 'All campaigns', 'creativewings-core' ),
            'business_name'  => get_user_meta( $user_id, 'business_name', true ) ?: wp_get_current_user()->display_name,
            'kpis'           => self::empty_kpis(),
            'timeseries'     => [
                'entries' => [ 'labels' => [], 'data' => [] ],
                'revenue' => [ 'labels' => [], 'data' => [] ],
            ],
            'breakdowns'     => [
                'category' => [],
                'status'   => [],
                'school'   => [],
                'scores'   => [],
            ],
            'has_competitions' => false,
            'has_staged'       => false,
            'campaigns_count'  => 0,
        ];

        if ( empty( $ids ) ) {
            return $context;
        }

        $context['kpis']       = self::compute_kpis( $ids, $range );
        $context['timeseries'] = self::compute_timeseries( $ids, $range );
        $context['breakdowns'] = self::compute_breakdowns( $ids );

        // Lightweight presence flags — used only for show/hide gating in the view.
        $context['campaigns_count']  = count( $ids );
        $context['has_competitions'] = self::any_competition_in( $ids );
        $context['has_staged']       = ( (int) $context['kpis']['staged'] + (int) $context['kpis']['claimed'] ) > 0;

        return $context;
    }

    /**
     * Returns true if at least one campaign in $ids is a competition.
     * Cheap O(N) loop over already-resolved IDs (no extra DB calls beyond
     * the term cache primed by get_the_terms).
     */
    private static function any_competition_in( array $ids ) {
        foreach ( $ids as $pid ) {
            if ( ( self::campaign_type( (int) $pid )['key'] ?? '' ) === 'competition' ) {
                return true;
            }
        }
        return false;
    }

    private static function empty_kpis() {
        return [
            'campaigns_total'    => 0,
            'campaigns_active'   => 0,
            'campaigns_past'     => 0,
            'campaigns_pending'  => 0,
            'participants'       => 0,
            'revenue'            => 0.0,
            'staged'             => 0,
            'claimed'            => 0,
            'moderation_pending' => 0,
            'avg_revenue'        => 0.0,
        ];
    }

    /**
     * KPI aggregation over the target campaign set.
     *
     * @param int[]  $ids
     * @param string $range
     */
    private static function compute_kpis( array $ids, $range ) {
        global $wpdb;
        $kpis = self::empty_kpis();
        if ( empty( $ids ) ) {
            return $kpis;
        }

        $kpis['campaigns_total'] = count( $ids );

        $now_ts = current_time( 'timestamp' );
        foreach ( $ids as $pid ) {
            $status = get_post_status( $pid );
            if ( 'publish' !== $status ) {
                $kpis['campaigns_pending']++;
                continue;
            }
            $deadline = get_post_meta( $pid, 'submission_deadline', true );
            $deadline_ts = $deadline ? strtotime( $deadline ) : 0;
            if ( $deadline_ts && $deadline_ts < $now_ts ) {
                $kpis['campaigns_past']++;
            } else {
                $kpis['campaigns_active']++;
            }
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        $entry_types = self::entry_post_types_sql_list();
        $kpis['participants'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
             WHERE p.post_type IN ({$entry_types})
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})",
            ...$ids
        ) );

        $staged_table = CW_Staged_Submissions::table();
        $kpis['staged'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$staged_table} WHERE campaign_id IN ({$placeholders}) AND status = 'staged'",
            ...$ids
        ) );
        $kpis['claimed'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$staged_table} WHERE campaign_id IN ({$placeholders}) AND status = 'claimed'",
            ...$ids
        ) );
        $kpis['moderation_pending'] = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$staged_table} WHERE campaign_id IN ({$placeholders}) AND moderation_status = 'pending'",
            ...$ids
        ) );

        $kpis['revenue']     = self::revenue_total( $ids );
        $kpis['avg_revenue'] = $kpis['campaigns_total'] > 0
            ? round( $kpis['revenue'] / $kpis['campaigns_total'], 2 )
            : 0.0;

        return $kpis;
    }

    /**
     * Per-campaign comparison rows.
     *
     * @param int[] $ids
     * @return array<int, array<string, mixed>>
     */
    private static function compute_campaigns( array $ids ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $entry_types  = self::entry_post_types_sql_list();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS pid, COUNT(p.ID) AS c
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
             WHERE p.post_type IN ({$entry_types})
               AND p.post_status = 'publish'
               AND pm.meta_value IN ({$placeholders})
             GROUP BY pm.meta_value",
            ...$ids
        ), ARRAY_A );

        $entries_by_pid = [];
        foreach ( (array) $rows as $r ) {
            $entries_by_pid[ (int) $r['pid'] ] = (int) $r['c'];
        }

        $staged_table = CW_Staged_Submissions::table();
        $staged_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT campaign_id, status, COUNT(*) AS c
             FROM {$staged_table}
             WHERE campaign_id IN ({$placeholders})
             GROUP BY campaign_id, status",
            ...$ids
        ), ARRAY_A );
        $staged_by_pid = [];
        foreach ( (array) $staged_rows as $r ) {
            $pid    = (int) $r['campaign_id'];
            $status = (string) $r['status'];
            $staged_by_pid[ $pid ][ $status ] = (int) $r['c'];
        }

        $now_ts = current_time( 'timestamp' );
        $out    = [];

        foreach ( $ids as $pid ) {
            $pid      = (int) $pid;
            $post     = get_post( $pid );
            if ( ! $post ) {
                continue;
            }
            $deadline    = get_post_meta( $pid, 'submission_deadline', true );
            $deadline_ts = $deadline ? strtotime( $deadline ) : 0;
            $status      = get_post_status( $pid );

            if ( 'publish' !== $status ) {
                $state = 'draft';
            } elseif ( $deadline_ts && $deadline_ts < $now_ts ) {
                $state = 'past';
            } else {
                $state = 'active';
            }

            $type        = self::campaign_type( $pid );
            $revenue     = class_exists( 'CW_Wallet' ) ? (float) CW_Wallet::get_product_earnings( $pid ) : 0.0;
            $participants = $entries_by_pid[ $pid ] ?? 0;
            $staged      = $staged_by_pid[ $pid ]['staged'] ?? 0;
            $claimed     = $staged_by_pid[ $pid ]['claimed'] ?? 0;

            $out[] = [
                'id'           => $pid,
                'title'        => get_the_title( $pid ),
                'type_label'   => $type['label'],
                'type_key'     => $type['key'],
                'status'       => $status,
                'state'        => $state,
                'state_label'  => self::state_label( $state ),
                'deadline'     => $deadline_ts ? date_i18n( get_option( 'date_format', 'd M Y' ), $deadline_ts ) : '',
                'deadline_ts'  => $deadline_ts,
                'participants' => (int) $participants,
                'revenue'      => $revenue,
                'staged'       => (int) $staged,
                'claimed'      => (int) $claimed,
                'permalink'    => get_permalink( $pid ),
            ];
        }

        usort( $out, static function ( $a, $b ) {
            return ( $b['revenue'] <=> $a['revenue'] )
                ?: ( $b['participants'] <=> $a['participants'] );
        } );

        return $out;
    }

    /**
     * Public paginated wrapper around compute_roster() — returns a page slice plus
     * total row count for the same filter set, so the view can render proper
     * cw-pagination controls without loading every row into memory.
     *
     * @param int    $user_id
     * @param int    $campaign_id  0 = "all owned"
     * @param string $range
     * @param int    $page         1-indexed
     * @param int    $per_page
     * @return array{ rows: array<int, array<string, mixed>>, total: int }
     */
    public static function get_roster_page( $user_id, $campaign_id, $range, $page = 1, $per_page = 25 ) {
        $ids   = self::resolve_target_ids( (int) $user_id, (int) $campaign_id );
        $range = self::sanitize_range( $range );
        $page  = max( 1, (int) $page );
        $per   = max( 1, min( 200, (int) $per_page ) );
        if ( empty( $ids ) ) {
            return [ 'rows' => [], 'total' => 0 ];
        }
        return self::compute_roster( $ids, $range, $page, $per );
    }

    /**
     * Paginated wrapper around the staged-submissions table.
     *
     * @return array{ rows: array<int, array<string, mixed>>, total: int }
     */
    public static function get_staged_page( $user_id, $campaign_id, $range, $page = 1, $per_page = 25 ) {
        $ids   = self::resolve_target_ids( (int) $user_id, (int) $campaign_id );
        $range = self::sanitize_range( $range );
        $page  = max( 1, (int) $page );
        $per   = max( 1, min( 200, (int) $per_page ) );
        if ( empty( $ids ) ) {
            return [ 'rows' => [], 'total' => 0 ];
        }
        return self::compute_staged( $ids, $range, $page, $per );
    }

    /**
     * Paginated wrapper around the campaign-comparison rows.
     *
     * @return array{ rows: array<int, array<string, mixed>>, total: int }
     */
    public static function get_campaigns_page( $user_id, $page = 1, $per_page = 25 ) {
        $ids  = self::owned_campaign_ids( (int) $user_id );
        $page = max( 1, (int) $page );
        $per  = max( 1, min( 200, (int) $per_page ) );
        if ( empty( $ids ) ) {
            return [ 'rows' => [], 'total' => 0 ];
        }
        $all = self::compute_campaigns( $ids );
        return [
            'rows'  => array_slice( $all, ( $page - 1 ) * $per, $per ),
            'total' => count( $all ),
        ];
    }

    /**
     * Participant roster — one row per entry post.
     *
     * Now supports DB-level pagination so we never have to materialise more than
     * one page worth of rows in PHP memory. When $page is null, returns the full
     * legacy (capped) result for the export pipeline.
     *
     * @param int[]    $ids
     * @param string   $range
     * @param int|null $page      1-indexed, or null for legacy full-load
     * @param int      $per_page
     * @return array<int, array<string, mixed>>|array{ rows: array<int, array<string, mixed>>, total: int }
     */
    private static function compute_roster( array $ids, $range, $page = null, $per_page = 25 ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return $page === null ? [] : [ 'rows' => [], 'total' => 0 ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $entry_types  = self::entry_post_types_sql_list();
        $range_start  = self::range_start_date( $range );

        $base_where  = "p.post_type IN ({$entry_types})
                          AND p.post_status = 'publish'
                          AND pm.meta_value IN ({$placeholders})";
        $where_args  = $ids;
        if ( $range_start ) {
            $base_where .= ' AND p.post_date >= %s';
            $where_args[] = $range_start;
        }

        $total = 0;
        if ( $page !== null ) {
            $count_sql  = "SELECT COUNT(p.ID)
                             FROM {$wpdb->posts} p
                             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                            WHERE {$base_where}";
            $total      = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) );
        }

        $sql  = "SELECT p.ID, p.post_type, p.post_date, p.post_author, pm.meta_value AS product_id
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                WHERE {$base_where}
                ORDER BY p.post_date DESC";

        $args = $where_args;
        if ( $page !== null ) {
            $sql  .= ' LIMIT %d OFFSET %d';
            $args[] = (int) $per_page;
            $args[] = (int) ( ( $page - 1 ) * $per_page );
        } else {
            $sql .= ' LIMIT 5000';
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        if ( ! $rows ) {
            return $page === null ? [] : [ 'rows' => [], 'total' => $total ];
        }

        $entry_ids = array_map( static function ( $r ) {
            return (int) $r['ID'];
        }, $rows );

        // Bulk-load meta for performance.
        $meta_map = self::bulk_post_meta( $entry_ids );

        // Cache campaign titles + user info.
        $campaign_titles = [];
        foreach ( $ids as $pid ) {
            $campaign_titles[ (int) $pid ] = get_the_title( (int) $pid );
        }

        $out = [];
        foreach ( $rows as $r ) {
            $eid = (int) $r['ID'];
            $meta = $meta_map[ $eid ] ?? [];

            $details = isset( $meta['participant_details'] ) ? maybe_unserialize( $meta['participant_details'] ) : [];
            if ( ! is_array( $details ) ) {
                $details = [];
            }

            $custom = [];
            $email_from_fields = '';
            foreach ( $details as $field ) {
                if ( ! is_array( $field ) ) {
                    continue;
                }
                $label = isset( $field['label'] ) ? (string) $field['label'] : '';
                $value = isset( $field['value'] ) ? (string) $field['value'] : '';
                if ( $label === '' ) {
                    continue;
                }
                $custom[ $label ] = $value;
                if ( $email_from_fields === '' && is_email( $value ) ) {
                    $email_from_fields = $value;
                }
            }

            $author    = (int) $r['post_author'];
            $user      = $author ? get_userdata( $author ) : null;
            $email     = $user ? $user->user_email : $email_from_fields;
            $order_id  = isset( $meta['order_id'] ) ? (int) $meta['order_id'] : 0;
            $amount    = '';
            if ( $order_id && function_exists( 'wc_get_order' ) ) {
                $order = wc_get_order( $order_id );
                if ( $order ) {
                    $amount = (float) $order->get_total();
                }
            }

            $score   = isset( $meta['judge_score'] ) ? $meta['judge_score'] : '';
            $comment = isset( $meta['judge_comment'] ) ? (string) $meta['judge_comment'] : '';
            $winner  = isset( $meta['winner_status'] ) && $meta['winner_status'] === 'yes';

            $staged_code = '';
            $school_code = '';
            $staged_id = isset( $meta['cw_staged_id'] ) ? (int) $meta['cw_staged_id'] : 0;
            if ( $staged_id ) {
                $staged_row = self::get_staged_row( $staged_id );
                if ( $staged_row ) {
                    $staged_code = (string) $staged_row['submission_code'];
                    $school_code = (string) $staged_row['school_code'];
                }
            }

            $out[] = [
                'entry_id'      => $eid,
                'staged_id'     => isset( $meta['cw_staged_id'] ) ? (int) $meta['cw_staged_id'] : 0,
                'campaign_id'   => (int) $r['product_id'],
                'campaign'      => $campaign_titles[ (int) $r['product_id'] ] ?? '',
                'date'          => $r['post_date'],
                'entry_type'    => $r['post_type'] === 'cw_competition_entry' ? __( 'Competition', 'creativewings-core' ) : __( 'Activity', 'creativewings-core' ),
                'participant'   => (string) ( $meta['cw_participant_name'] ?? '' ),
                'email'         => (string) $email,
                'user_login'    => $user ? $user->user_login : '',
                'order_id'      => $order_id,
                'amount'        => $amount,
                'age_label'     => (string) ( $meta['cw_age_bracket_label'] ?? '' ),
                'age_key'       => (string) ( $meta['cw_age_bracket_key'] ?? '' ),
                'submission_code' => $staged_code,
                'school_code'   => $school_code,
                'score'         => $score === '' ? '' : (string) $score,
                'comment'       => $comment,
                'winner'        => $winner,
                'custom'        => $custom,
            ];
        }

        return $page === null ? $out : [ 'rows' => $out, 'total' => $total ];
    }

    /**
     * Staged submissions (school/PIC flow).
     *
     * @param int[]    $ids
     * @param string   $range
     * @param int|null $page      1-indexed, or null for legacy full-load
     * @param int      $per_page
     * @return array<int, array<string, mixed>>|array{ rows: array<int, array<string, mixed>>, total: int }
     */
    private static function compute_staged( array $ids, $range, $page = null, $per_page = 25 ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return $page === null ? [] : [ 'rows' => [], 'total' => 0 ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $range_start  = self::range_start_date( $range );
        $table        = CW_Staged_Submissions::table();

        $where = "campaign_id IN ({$placeholders})";
        $where_args = $ids;
        if ( $range_start ) {
            $where       .= ' AND created_at >= %s';
            $where_args[] = $range_start;
        }

        $total = 0;
        if ( $page !== null ) {
            $count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where}";
            $total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $where_args ) );
        }

        $sql  = "SELECT id, campaign_id, submission_code, student_name, school_code, status, moderation_status, claimed_by_user_id, order_id, created_at
                FROM {$table}
                WHERE {$where}
                ORDER BY created_at DESC";

        $args = $where_args;
        if ( $page !== null ) {
            $sql   .= ' LIMIT %d OFFSET %d';
            $args[] = (int) $per_page;
            $args[] = (int) ( ( $page - 1 ) * $per_page );
        } else {
            $sql .= ' LIMIT 5000';
        }

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : [];

        return $page === null ? $rows : [ 'rows' => $rows, 'total' => $total ];
    }

    /**
     * Time-series for entries created and revenue per day.
     *
     * @param int[]  $ids
     * @param string $range
     */
    private static function compute_timeseries( array $ids, $range ) {
        global $wpdb;
        $entries = [ 'labels' => [], 'data' => [] ];
        $revenue = [ 'labels' => [], 'data' => [] ];

        if ( empty( $ids ) ) {
            return [ 'entries' => $entries, 'revenue' => $revenue ];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $entry_types  = self::entry_post_types_sql_list();
        $range_start  = self::range_start_date( $range );

        // Entries by day.
        $entry_sql = "SELECT DATE(p.post_date) AS d, COUNT(*) AS c
                      FROM {$wpdb->posts} p
                      INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                      WHERE p.post_type IN ({$entry_types})
                        AND p.post_status = 'publish'
                        AND pm.meta_value IN ({$placeholders})";
        $entry_args = $ids;
        if ( $range_start ) {
            $entry_sql   .= ' AND p.post_date >= %s';
            $entry_args[] = $range_start;
        }
        $entry_sql .= ' GROUP BY d ORDER BY d ASC';
        $entry_rows = $wpdb->get_results( $wpdb->prepare( $entry_sql, $entry_args ), ARRAY_A );

        $entries_map = [];
        foreach ( (array) $entry_rows as $r ) {
            $entries_map[ $r['d'] ] = (int) $r['c'];
        }

        // Revenue by day (matches Wallet's earnings SQL).
        $revenue_sql  = "SELECT DATE(p.post_date) AS d, SUM(total_meta.meta_value) AS revenue
                        FROM {$wpdb->prefix}woocommerce_order_itemmeta AS total_meta
                        JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON total_meta.order_item_id = woi.order_item_id
                        JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid_meta ON woi.order_item_id = pid_meta.order_item_id AND pid_meta.meta_key = '_product_id'
                        JOIN {$wpdb->posts} AS p ON woi.order_id = p.ID
                        WHERE woi.order_item_type = 'line_item'
                          AND total_meta.meta_key = '_line_total'
                          AND p.post_status IN ('wc-completed', 'wc-processing')
                          AND pid_meta.meta_value IN ({$placeholders})";
        $rev_args = $ids;
        if ( $range_start ) {
            $revenue_sql .= ' AND p.post_date >= %s';
            $rev_args[]   = $range_start;
        }
        $revenue_sql .= ' GROUP BY d ORDER BY d ASC';
        $revenue_rows = $wpdb->get_results( $wpdb->prepare( $revenue_sql, $rev_args ), ARRAY_A );

        $revenue_map = [];
        foreach ( (array) $revenue_rows as $r ) {
            $revenue_map[ $r['d'] ] = (float) $r['revenue'];
        }

        // Fill the date axis so the chart has no gaps.
        $days = self::range_options()[ $range ] ?? null;
        if ( $days ) {
            $labels = [];
            $today  = current_time( 'timestamp' );
            for ( $i = $days - 1; $i >= 0; $i-- ) {
                $ts = $today - ( $i * DAY_IN_SECONDS );
                $labels[] = date_i18n( 'Y-m-d', $ts );
            }
        } else {
            $labels = array_unique( array_merge( array_keys( $entries_map ), array_keys( $revenue_map ) ) );
            sort( $labels );
        }

        $entries['labels'] = $labels;
        $entries['data']   = array_map( static function ( $d ) use ( $entries_map ) {
            return $entries_map[ $d ] ?? 0;
        }, $labels );

        $revenue['labels'] = $labels;
        $revenue['data']   = array_map( static function ( $d ) use ( $revenue_map ) {
            return round( $revenue_map[ $d ] ?? 0, 2 );
        }, $labels );

        return [ 'entries' => $entries, 'revenue' => $revenue ];
    }

    /**
     * Public time-series helper for the Business dashboard chart.
     *
     * Supports the dashboard's granular day ranges (1, 3, 7, 14, 30, 180, 365, all)
     * — wider than the strict {@see range_options()} whitelist used by the Reports
     * tab. Always scoped to the user's owned campaigns.
     *
     * @param int        $user_id     Business user ID.
     * @param int|string $days_or_all Positive integer days, or the string "all".
     * @return array{
     *     labels: string[],
     *     revenue: float[],
     *     participants: int[],
     *     range: string
     * }
     */
    public static function get_chart_series( $user_id, $days_or_all = '30' ) {
        $user_id = (int) $user_id;
        $range   = $days_or_all === 'all' ? 'all' : max( 1, (int) $days_or_all );

        $empty = [
            'labels'       => [],
            'revenue'      => [],
            'participants' => [],
            'range'        => (string) $range,
        ];

        if ( ! $user_id ) {
            return $empty;
        }

        // Short cache hides repeated calls on dashboard render + AJAX range switch.
        if ( class_exists( 'CW_Cache' ) ) {
            return CW_Cache::remember(
                "chart:{$user_id}:{$range}",
                'reports',
                5 * MINUTE_IN_SECONDS,
                function () use ( $user_id, $range, $empty ) {
                    return self::compute_chart_series_uncached( $user_id, $range, $empty );
                }
            );
        }
        return self::compute_chart_series_uncached( $user_id, $range, $empty );
    }

    /**
     * Same body as the original get_chart_series, extracted so the cache wrapper
     * above can produce on miss.
     */
    private static function compute_chart_series_uncached( $user_id, $range, array $empty ) {
        $ids = self::owned_campaign_ids( $user_id );
        if ( empty( $ids ) ) {
            return $empty;
        }

        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $entry_types  = self::entry_post_types_sql_list();

        // Resolve range start (inclusive midnight). null => no lower bound (all-time).
        $range_start = null;
        if ( $range !== 'all' ) {
            $days        = (int) $range;
            $ts          = current_time( 'timestamp' ) - ( ( $days - 1 ) * DAY_IN_SECONDS );
            $range_start = date_i18n( 'Y-m-d 00:00:00', $ts );
        }

        // Participants per day (any entry CPT linked to a target product_id).
        $entry_sql  = "SELECT DATE(p.post_date) AS d, COUNT(*) AS c
                       FROM {$wpdb->posts} p
                       INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                       WHERE p.post_type IN ({$entry_types})
                         AND p.post_status = 'publish'
                         AND pm.meta_value IN ({$placeholders})";
        $entry_args = $ids;
        if ( $range_start ) {
            $entry_sql   .= ' AND p.post_date >= %s';
            $entry_args[] = $range_start;
        }
        $entry_sql .= ' GROUP BY d ORDER BY d ASC';
        $entry_rows = $wpdb->get_results( $wpdb->prepare( $entry_sql, $entry_args ), ARRAY_A );

        $entries_map = [];
        foreach ( (array) $entry_rows as $r ) {
            $entries_map[ $r['d'] ] = (int) $r['c'];
        }

        // Revenue per day (line items on completed/processing orders for target products).
        $revenue_sql  = "SELECT DATE(p.post_date) AS d, SUM(total_meta.meta_value) AS revenue
                         FROM {$wpdb->prefix}woocommerce_order_itemmeta AS total_meta
                         JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON total_meta.order_item_id = woi.order_item_id
                         JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid_meta ON woi.order_item_id = pid_meta.order_item_id AND pid_meta.meta_key = '_product_id'
                         JOIN {$wpdb->posts} AS p ON woi.order_id = p.ID
                         WHERE woi.order_item_type = 'line_item'
                           AND total_meta.meta_key = '_line_total'
                           AND p.post_status IN ('wc-completed', 'wc-processing')
                           AND pid_meta.meta_value IN ({$placeholders})";
        $rev_args = $ids;
        if ( $range_start ) {
            $revenue_sql .= ' AND p.post_date >= %s';
            $rev_args[]   = $range_start;
        }
        $revenue_sql .= ' GROUP BY d ORDER BY d ASC';
        $revenue_rows = $wpdb->get_results( $wpdb->prepare( $revenue_sql, $rev_args ), ARRAY_A );

        $revenue_map = [];
        foreach ( (array) $revenue_rows as $r ) {
            $revenue_map[ $r['d'] ] = (float) $r['revenue'];
        }

        // Build the date axis. For short windows (≤ 90 days) bucket per day; otherwise
        // bucket per month to keep the chart readable.
        $bucket_monthly = ( $range === 'all' ) || ( (int) $range > 90 );

        if ( ! $bucket_monthly ) {
            $days   = (int) $range;
            $today  = current_time( 'timestamp' );
            $labels = [];
            for ( $i = $days - 1; $i >= 0; $i-- ) {
                $ts       = $today - ( $i * DAY_IN_SECONDS );
                $labels[] = date_i18n( 'Y-m-d', $ts );
            }

            return [
                'labels'       => $labels,
                'revenue'      => array_map( static function ( $d ) use ( $revenue_map ) {
                    return round( $revenue_map[ $d ] ?? 0, 2 );
                }, $labels ),
                'participants' => array_map( static function ( $d ) use ( $entries_map ) {
                    return (int) ( $entries_map[ $d ] ?? 0 );
                }, $labels ),
                'range'        => (string) $range,
            ];
        }

        // Monthly buckets.
        if ( $range === 'all' ) {
            $first_dates = [];
            if ( ! empty( $revenue_map ) ) { $first_dates[] = min( array_keys( $revenue_map ) ); }
            if ( ! empty( $entries_map ) ) { $first_dates[] = min( array_keys( $entries_map ) ); }
            $start_ym = empty( $first_dates )
                ? date_i18n( 'Y-m', current_time( 'timestamp' ) )
                : substr( min( $first_dates ), 0, 7 );
        } else {
            $months   = max( 1, (int) ceil( (int) $range / 30 ) );
            $start_ts = current_time( 'timestamp' ) - ( ( $months - 1 ) * 30 * DAY_IN_SECONDS );
            $start_ym = date_i18n( 'Y-m', $start_ts );
        }

        $end_ym  = date_i18n( 'Y-m', current_time( 'timestamp' ) );
        $cursor  = strtotime( $start_ym . '-01' );
        $end_ts  = strtotime( $end_ym . '-01' );
        $month_labels = [];
        while ( $cursor <= $end_ts ) {
            $month_labels[] = date_i18n( 'Y-m', $cursor );
            $cursor = strtotime( '+1 month', $cursor );
        }

        $rev_monthly = array_fill_keys( $month_labels, 0.0 );
        $ent_monthly = array_fill_keys( $month_labels, 0 );

        foreach ( $revenue_map as $d => $v ) {
            $ym = substr( $d, 0, 7 );
            if ( isset( $rev_monthly[ $ym ] ) ) {
                $rev_monthly[ $ym ] += (float) $v;
            }
        }
        foreach ( $entries_map as $d => $v ) {
            $ym = substr( $d, 0, 7 );
            if ( isset( $ent_monthly[ $ym ] ) ) {
                $ent_monthly[ $ym ] += (int) $v;
            }
        }

        return [
            'labels'       => $month_labels,
            'revenue'      => array_map( static function ( $v ) { return round( (float) $v, 2 ); }, array_values( $rev_monthly ) ),
            'participants' => array_map( 'intval', array_values( $ent_monthly ) ),
            'range'        => (string) $range,
        ];
    }

    /**
     * Aggregate breakdowns (category, status, school, scores).
     *
     * @param int[] $ids
     */
    private static function compute_breakdowns( array $ids ) {
        global $wpdb;
        $out = [
            'category' => [],
            'status'   => [],
            'school'   => [],
            'scores'   => [],
        ];
        if ( empty( $ids ) ) {
            return $out;
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // Prime caches in one shot so campaign_type() reads from memory below.
        update_meta_cache( 'post', $ids );
        update_object_term_cache( $ids, 'product' );

        // Status + submission_deadline in ONE query (replaces per-pid get_post_status + get_post_meta).
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_status, pm.meta_value AS deadline
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = 'submission_deadline'
             WHERE p.ID IN ($placeholders)",
            $ids
        ), ARRAY_A );

        $status_by_id  = [];
        $deadline_by_id = [];
        foreach ( (array) $rows as $r ) {
            $rid = (int) $r['ID'];
            $status_by_id[ $rid ]   = (string) $r['post_status'];
            $deadline_by_id[ $rid ] = (string) $r['deadline'];
        }

        $cat_totals   = [];
        $state_totals = [];
        $now_ts       = current_time( 'timestamp' );

        foreach ( $ids as $pid ) {
            $pid  = (int) $pid;
            $type = self::campaign_type( $pid );
            $cat_totals[ $type['label'] ] = ( $cat_totals[ $type['label'] ] ?? 0 ) + 1;

            $status = $status_by_id[ $pid ] ?? '';
            if ( 'publish' !== $status ) {
                $state_totals['Draft'] = ( $state_totals['Draft'] ?? 0 ) + 1;
                continue;
            }
            $deadline_ts = ! empty( $deadline_by_id[ $pid ] ) ? strtotime( $deadline_by_id[ $pid ] ) : 0;
            if ( $deadline_ts && $deadline_ts < $now_ts ) {
                $state_totals['Past'] = ( $state_totals['Past'] ?? 0 ) + 1;
            } else {
                $state_totals['Active'] = ( $state_totals['Active'] ?? 0 ) + 1;
            }
        }
        $out['category'] = $cat_totals;
        $out['status']   = $state_totals;

        // School breakdown from staged submissions.
        $staged_table = CW_Staged_Submissions::table();
        $school_rows  = $wpdb->get_results( $wpdb->prepare(
            "SELECT school_code, COUNT(*) AS c FROM {$staged_table} WHERE campaign_id IN ({$placeholders}) GROUP BY school_code ORDER BY c DESC LIMIT 12",
            ...$ids
        ), ARRAY_A );
        foreach ( (array) $school_rows as $r ) {
            $out['school'][ (string) $r['school_code'] ] = (int) $r['c'];
        }

        // Score histogram for competition entries.
        $score_rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT pm.meta_value AS score
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} prod ON prod.post_id = p.ID AND prod.meta_key = 'product_id'
             INNER JOIN {$wpdb->postmeta} pm   ON pm.post_id   = p.ID AND pm.meta_key = 'judge_score'
             WHERE p.post_type = 'cw_competition_entry'
               AND p.post_status = 'publish'
               AND prod.meta_value IN ({$placeholders})",
            ...$ids
        ), ARRAY_A );
        $buckets = [
            '0'      => 0,
            '1-20'   => 0,
            '21-40'  => 0,
            '41-60'  => 0,
            '61-80'  => 0,
            '81-100' => 0,
        ];
        foreach ( (array) $score_rows as $r ) {
            $s = (float) $r['score'];
            if ( $s <= 0 )       { $buckets['0']++; }
            elseif ( $s <= 20 )  { $buckets['1-20']++; }
            elseif ( $s <= 40 )  { $buckets['21-40']++; }
            elseif ( $s <= 60 )  { $buckets['41-60']++; }
            elseif ( $s <= 80 )  { $buckets['61-80']++; }
            else                 { $buckets['81-100']++; }
        }
        $out['scores'] = $buckets;

        return $out;
    }

    /**
     * Total revenue across the given campaigns (publish/processing orders).
     *
     * @param int[] $ids
     */
    private static function revenue_total( array $ids ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return 0.0;
        }
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "SELECT SUM(total_meta.meta_value)
                FROM {$wpdb->prefix}woocommerce_order_itemmeta AS total_meta
                JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON total_meta.order_item_id = woi.order_item_id
                JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid_meta ON woi.order_item_id = pid_meta.order_item_id AND pid_meta.meta_key = '_product_id'
                JOIN {$wpdb->posts} AS p ON woi.order_id = p.ID
                WHERE woi.order_item_type = 'line_item'
                  AND total_meta.meta_key = '_line_total'
                  AND p.post_status IN ('wc-completed', 'wc-processing')
                  AND pid_meta.meta_value IN ({$placeholders})";
        $total = $wpdb->get_var( $wpdb->prepare( $sql, $ids ) );
        return $total ? (float) $total : 0.0;
    }

    /**
     * Classify campaign by root product category.
     *
     * @return array{key:string,label:string}
     */
    public static function campaign_type( $pid ) {
        $terms = get_the_terms( (int) $pid, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $parent = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
                $root   = $parent ? strtolower( $parent->slug ) : strtolower( $t->slug );
                if ( false !== strpos( $root, 'competition' ) ) {
                    return [ 'key' => 'competition', 'label' => __( 'Competition', 'creativewings-core' ) ];
                }
                if ( $root === 'talk-seminar' || false !== strpos( $root, 'seminar' ) || false !== strpos( $root, 'talk' ) ) {
                    return [ 'key' => 'seminar', 'label' => __( 'Talk / Seminar', 'creativewings-core' ) ];
                }
                if ( false !== strpos( $root, 'activit' ) ) {
                    return [ 'key' => 'activity', 'label' => __( 'Activity', 'creativewings-core' ) ];
                }
            }
        }
        return [ 'key' => 'activity', 'label' => __( 'Activity', 'creativewings-core' ) ];
    }

    private static function state_label( $state ) {
        switch ( $state ) {
            case 'active': return __( 'Active', 'creativewings-core' );
            case 'past':   return __( 'Past', 'creativewings-core' );
            default:       return __( 'Draft / Pending', 'creativewings-core' );
        }
    }

    private static function entry_post_types_sql_list() {
        $types = class_exists( 'CW_Shop' )
            ? CW_Shop::entry_post_types()
            : [ 'cw_competition_entry', 'cw_activity_entry' ];
        return "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";
    }

    /**
     * Bulk fetch the common entry meta keys we need for the roster.
     *
     * @param int[] $entry_ids
     * @return array<int, array<string, mixed>>
     */
    private static function bulk_post_meta( array $entry_ids ) {
        global $wpdb;
        if ( empty( $entry_ids ) ) {
            return [];
        }
        $keys = [
            'cw_participant_name',
            'participant_details',
            'cw_age_bracket_label',
            'cw_age_bracket_key',
            'order_id',
            'product_id',
            'judge_score',
            'judge_comment',
            'winner_status',
            'cw_staged_id',
            'upload_document',
        ];
        $id_placeholders  = implode( ',', array_fill( 0, count( $entry_ids ), '%d' ) );
        $key_placeholders = implode( ',', array_fill( 0, count( $keys ), '%s' ) );
        $sql = "SELECT post_id, meta_key, meta_value
                FROM {$wpdb->postmeta}
                WHERE post_id IN ({$id_placeholders})
                  AND meta_key IN ({$key_placeholders})";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, array_merge( $entry_ids, $keys ) ), ARRAY_A );
        $out  = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r['post_id'] ][ $r['meta_key'] ] = $r['meta_value'];
        }
        return $out;
    }

    private static function get_staged_row( $staged_id ) {
        global $wpdb;
        $table = CW_Staged_Submissions::table();
        return $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $staged_id ),
            ARRAY_A
        );
    }

    /**
     * All custom-field labels in the roster — used for export header expansion.
     *
     * @param array<int, array<string, mixed>> $roster
     * @return string[]
     */
    public static function collect_custom_field_labels( array $roster ) {
        $labels = [];
        foreach ( $roster as $row ) {
            if ( empty( $row['custom'] ) || ! is_array( $row['custom'] ) ) {
                continue;
            }
            foreach ( array_keys( $row['custom'] ) as $label ) {
                $labels[ $label ] = true;
            }
        }
        return array_keys( $labels );
    }
}
