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
     * Participant roster — one row per entry post.
     *
     * @param int[]  $ids
     * @param string $range
     */
    private static function compute_roster( array $ids, $range ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $entry_types  = self::entry_post_types_sql_list();
        $range_start  = self::range_start_date( $range );

        $sql  = "SELECT p.ID, p.post_type, p.post_date, p.post_author, pm.meta_value AS product_id
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                WHERE p.post_type IN ({$entry_types})
                  AND p.post_status = 'publish'
                  AND pm.meta_value IN ({$placeholders})";
        $args = $ids;
        if ( $range_start ) {
            $sql   .= ' AND p.post_date >= %s';
            $args[] = $range_start;
        }
        $sql .= ' ORDER BY p.post_date DESC LIMIT 5000';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        if ( ! $rows ) {
            return [];
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

        return $out;
    }

    /**
     * Staged submissions (school/PIC flow).
     *
     * @param int[]  $ids
     * @param string $range
     */
    private static function compute_staged( array $ids, $range ) {
        global $wpdb;
        if ( empty( $ids ) ) {
            return [];
        }

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $range_start  = self::range_start_date( $range );
        $table        = CW_Staged_Submissions::table();

        $sql  = "SELECT id, campaign_id, submission_code, student_name, school_code, status, moderation_status, claimed_by_user_id, order_id, created_at
                FROM {$table}
                WHERE campaign_id IN ({$placeholders})";
        $args = $ids;
        if ( $range_start ) {
            $sql   .= ' AND created_at >= %s';
            $args[] = $range_start;
        }
        $sql .= ' ORDER BY created_at DESC LIMIT 5000';

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
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

        // Category (competition / activity / talk-seminar) — from per-campaign helper.
        $cat_totals = [];
        $state_totals = [];
        $now_ts = current_time( 'timestamp' );
        foreach ( $ids as $pid ) {
            $type = self::campaign_type( $pid );
            $cat_totals[ $type['label'] ] = ( $cat_totals[ $type['label'] ] ?? 0 ) + 1;

            $status = get_post_status( $pid );
            if ( 'publish' !== $status ) {
                $state_totals['Draft'] = ( $state_totals['Draft'] ?? 0 ) + 1;
                continue;
            }
            $deadline = get_post_meta( $pid, 'submission_deadline', true );
            $deadline_ts = $deadline ? strtotime( $deadline ) : 0;
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
