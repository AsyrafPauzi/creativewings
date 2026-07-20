<?php
/**
 * Contestant points ledger: earn on paid joins, 12-month expiry wipe, leaderboard.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Points {

    const DB_OPTION        = 'cw_points_db_version';
    const DB_VERSION       = '1.0.0';
    const META_BALANCE     = 'cw_points_balance';
    const META_LAST_JOIN   = 'cw_points_last_join_at';
    const META_LIFETIME    = 'cw_points_lifetime_earned';
    const META_EXPIRY      = 'cw_points_expires_at';
    const ORDER_META_FLAG  = '_cw_points_awarded';
    const OPTION_TOP10     = 'cw_points_top10_user_ids';
    const CRON_HOOK        = 'cw_daily_points_expiry';
    const EXPIRY_MONTHS    = 12;
    const LEADERBOARD_SIZE = 50;
    const TOP_CONTRIBUTOR  = 10;

    public static function register_hooks() {
        add_action( 'init', [ __CLASS__, 'maybe_install' ], 5 );
        add_action( 'init', [ __CLASS__, 'schedule_cron' ], 20 );
        add_action( self::CRON_HOOK, [ __CLASS__, 'run_expiry_wipe' ] );

        add_action( 'cw_entry_created_from_order', [ __CLASS__, 'on_entry_created_from_order' ], 40, 4 );
        add_action( 'cw_order_entry_created', [ __CLASS__, 'on_order_entry_created' ], 40, 4 );
        add_action( 'cw_guest_account_attached', [ __CLASS__, 'on_guest_account_attached' ], 10, 2 );

        add_filter( 'cw_badges_rule_handlers', [ __CLASS__, 'register_badge_rules' ] );
    }

    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_points_ledger';
    }

    public static function maybe_install() {
        if ( get_option( self::DB_OPTION ) === self::DB_VERSION ) {
            return;
        }
        self::create_table();
        update_option( self::DB_OPTION, self::DB_VERSION, false );
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();
        $sql     = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            points INT(11) NOT NULL DEFAULT 0,
            type VARCHAR(32) NOT NULL DEFAULT 'earn',
            ref_type VARCHAR(32) NULL,
            ref_id BIGINT(20) UNSIGNED NULL,
            note TEXT NULL,
            created_at DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id),
            KEY type (type),
            KEY ref (ref_type, ref_id),
            KEY created_at (created_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    public static function schedule_cron() {
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Balance helpers
     * ────────────────────────────────────────────────────────────────── */

    public static function get_balance( $user_id ) {
        return max( 0, (int) get_user_meta( (int) $user_id, self::META_BALANCE, true ) );
    }

    public static function get_lifetime_earned( $user_id ) {
        return max( 0, (int) get_user_meta( (int) $user_id, self::META_LIFETIME, true ) );
    }

    public static function get_last_join_at( $user_id ) {
        $raw = get_user_meta( (int) $user_id, self::META_LAST_JOIN, true );
        return is_string( $raw ) && $raw !== '' ? $raw : '';
    }

    public static function get_expires_at( $user_id ) {
        $raw = get_user_meta( (int) $user_id, self::META_EXPIRY, true );
        if ( is_string( $raw ) && $raw !== '' ) {
            return $raw;
        }
        $last = self::get_last_join_at( $user_id );
        if ( ! $last ) {
            return '';
        }
        $ts = strtotime( $last . ' +' . self::EXPIRY_MONTHS . ' months' );
        return $ts ? date( 'Y-m-d H:i:s', $ts ) : '';
    }

    /**
     * @param int    $user_id
     * @param int    $points   Positive for earn; wipe uses balance.
     * @param string $type     earn|wipe|adjust
     * @param string $ref_type
     * @param int    $ref_id
     * @param string $note
     */
    public static function add_ledger_row( $user_id, $points, $type, $ref_type = '', $ref_id = 0, $note = '' ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        $wpdb->insert(
            self::table(),
            [
                'user_id'    => $user_id,
                'points'     => (int) $points,
                'type'       => sanitize_key( $type ),
                'ref_type'   => sanitize_key( $ref_type ),
                'ref_id'     => (int) $ref_id,
                'note'       => sanitize_text_field( $note ),
                'created_at' => current_time( 'mysql' ),
            ],
            [ '%d', '%d', '%s', '%s', '%d', '%s', '%s' ]
        );

        return (bool) $wpdb->insert_id;
    }

    /**
     * Credit points and refresh 12-month expiry from now.
     *
     * @param int $user_id
     * @param int $points
     * @param int $order_id
     */
    public static function credit_earn( $user_id, $points, $order_id = 0 ) {
        $user_id = (int) $user_id;
        $points  = max( 0, (int) $points );
        if ( $user_id <= 0 ) {
            return false;
        }

        $now     = current_time( 'mysql' );
        $expires = date( 'Y-m-d H:i:s', strtotime( $now . ' +' . self::EXPIRY_MONTHS . ' months' ) );

        if ( $points > 0 ) {
            $balance  = self::get_balance( $user_id ) + $points;
            $lifetime = self::get_lifetime_earned( $user_id ) + $points;
            update_user_meta( $user_id, self::META_BALANCE, $balance );
            update_user_meta( $user_id, self::META_LIFETIME, $lifetime );
            self::add_ledger_row( $user_id, $points, 'earn', 'order', $order_id, 'Paid campaign join' );
        } else {
            self::add_ledger_row( $user_id, 0, 'earn', 'order', $order_id, 'Free campaign join (expiry refreshed)' );
        }

        update_user_meta( $user_id, self::META_LAST_JOIN, $now );
        update_user_meta( $user_id, self::META_EXPIRY, $expires );

        if ( class_exists( 'CW_Badges_Engine' ) ) {
            CW_Badges_Engine::evaluate_user( $user_id, [ 'source' => 'points_earn', 'order_id' => $order_id ] );
        }

        self::recompute_top_contributors();

        return true;
    }

    /**
     * Wipe all points for a stale user.
     */
    public static function wipe_user( $user_id, $note = 'Expired after 12 months without a join' ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        $balance = self::get_balance( $user_id );
        update_user_meta( $user_id, self::META_BALANCE, 0 );
        delete_user_meta( $user_id, self::META_EXPIRY );

        self::add_ledger_row( $user_id, -1 * $balance, 'wipe', 'cron', 0, $note );

        if ( class_exists( 'CW_Badges_Engine' ) ) {
            CW_Badges_Engine::evaluate_user( $user_id, [ 'source' => 'points_wipe' ] );
        }

        return true;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Award from orders
     * ────────────────────────────────────────────────────────────────── */

    public static function on_entry_created_from_order( $entry_id, $item, $order, $p_num = 1 ) {
        // Paid-join points are awarded by CW_Post_Checkout async to keep payment requests short.
        if ( class_exists( 'CW_Post_Checkout' ) && CW_Post_Checkout::defers_side_effects() ) {
            return;
        }
        if ( ! ( $order instanceof WC_Order ) ) {
            return;
        }
        self::maybe_award_for_order( $order->get_id(), (int) $order->get_user_id() );
    }

    public static function on_order_entry_created( $user_id, $entry_id, $product_id, $order_id ) {
        if ( class_exists( 'CW_Post_Checkout' ) && CW_Post_Checkout::defers_side_effects() ) {
            return;
        }
        self::maybe_award_for_order( (int) $order_id, (int) $user_id );
    }

    public static function on_guest_account_attached( $order_id, $user_id ) {
        self::maybe_award_for_order( (int) $order_id, (int) $user_id );
    }

    /**
     * Award points once per paid CW order when a user is attached.
     *
     * @param int $order_id
     * @param int $user_id
     */
    public static function maybe_award_for_order( $order_id, $user_id = 0 ) {
        $order_id = (int) $order_id;
        $user_id  = (int) $user_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( ! $user_id ) {
            $user_id = (int) $order->get_user_id();
        }
        if ( $user_id <= 0 ) {
            return; // Guest: wait until account completion.
        }

        if ( 'yes' === $order->get_meta( self::ORDER_META_FLAG ) ) {
            return;
        }

        if ( ! $order->is_paid() && ! in_array( $order->get_status(), [ 'processing', 'completed' ], true ) ) {
            return;
        }

        $points = self::calculate_order_points( $order );
        if ( null === $points ) {
            return; // No CW campaign lines.
        }

        self::credit_earn( $user_id, $points, $order_id );

        $order->update_meta_data( self::ORDER_META_FLAG, 'yes' );
        $order->update_meta_data( '_cw_points_awarded_amount', (int) $points );
        $order->save();
    }

    /**
     * Floor of original (regular) line prices for CW campaign products.
     *
     * @param WC_Order $order
     * @return int|null Null when order has no CW campaign lines.
     */
    public static function calculate_order_points( $order ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return null;
        }

        $total   = 0;
        $has_cw  = false;

        foreach ( $order->get_items() as $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( $product_id <= 0 || ! self::product_is_cw_campaign( $product_id, $item ) ) {
                continue;
            }
            $has_cw = true;

            $qty     = max( 1, (int) $item->get_quantity() );
            $product = $item->get_product();
            $unit    = 0.0;

            if ( $product ) {
                $regular = $product->get_regular_price();
                if ( '' !== $regular && null !== $regular ) {
                    $unit = (float) $regular;
                }
            }

            // Fallback: line subtotal before coupons (still better than paid total).
            if ( $unit <= 0 ) {
                $unit = (float) $item->get_subtotal() / $qty;
            }

            $total += (int) floor( max( 0, $unit ) * $qty );
        }

        return $has_cw ? $total : null;
    }

    /**
     * @param int                  $product_id
     * @param WC_Order_Item_Product $item
     */
    private static function product_is_cw_campaign( $product_id, $item ) {
        if ( get_post_meta( $product_id, 'cw_campaign_serial', true ) ) {
            return true;
        }
        if ( $item->get_meta( '_cw_participant_data' ) || $item->get_meta( '_cw_staged_id' ) || $item->get_meta( '_cw_addons_data' ) ) {
            return true;
        }
        if ( class_exists( 'CW_Design_Submission' )
            && ( $item->get_meta( '_' . CW_Design_Submission::CART_FLAG )
                || $item->get_meta( '_' . CW_Design_Submission::CART_ARTWORK_ID ) ) ) {
            return true;
        }
        return false;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Expiry cron + Top 10
     * ────────────────────────────────────────────────────────────────── */

    public static function run_expiry_wipe() {
        global $wpdb;

        $cutoff = date( 'Y-m-d H:i:s', strtotime( current_time( 'mysql' ) . ' -' . self::EXPIRY_MONTHS . ' months' ) );
        $uids   = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT user_id FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                   AND meta_value <> ''
                   AND meta_value < %s
                 LIMIT 500",
                self::META_LAST_JOIN,
                $cutoff
            )
        );

        foreach ( (array) $uids as $uid ) {
            $uid = (int) $uid;
            if ( $uid <= 0 ) {
                continue;
            }
            if ( self::get_balance( $uid ) <= 0 && ! self::get_expires_at( $uid ) ) {
                continue;
            }
            self::wipe_user( $uid );
        }

        self::recompute_top_contributors();
    }

    /**
     * Store Top 10 user IDs by current balance for the Top Contributor badge.
     */
    public static function recompute_top_contributors() {
        global $wpdb;

        $prev = get_option( self::OPTION_TOP10, [] );
        if ( ! is_array( $prev ) ) {
            $prev = [];
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT user_id, meta_value+0 AS bal
                 FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                   AND meta_value+0 > 0
                 ORDER BY bal DESC, user_id ASC
                 LIMIT %d",
                self::META_BALANCE,
                self::TOP_CONTRIBUTOR
            ),
            ARRAY_A
        );

        $ids = [];
        foreach ( (array) $rows as $row ) {
            $ids[] = (int) $row['user_id'];
        }
        update_option( self::OPTION_TOP10, $ids, false );

        if ( ! class_exists( 'CW_Badges_Engine' ) || ! class_exists( 'CW_Badges_Installer' ) ) {
            return $ids;
        }

        $badge_id = CW_Badges_Installer::find_badge_by_slug( 'contestant_top_contributor' );
        if ( $badge_id ) {
            $fallen = array_diff( array_map( 'intval', $prev ), $ids );
            foreach ( $fallen as $uid ) {
                if ( $uid > 0 ) {
                    CW_Badges_Engine::revoke( $uid, $badge_id, CW_Badges_Engine::TIER_FLAT );
                }
            }
        }

        foreach ( $ids as $uid ) {
            CW_Badges_Engine::evaluate_user( $uid, [ 'source' => 'top_contributor' ] );
        }

        return $ids;
    }

    /**
     * Site-wide leaderboard rows.
     *
     * @param int $limit
     * @return array<int, array{user_id:int,balance:int,display_name:string,rank:int}>
     */
    public static function get_leaderboard( $limit = self::LEADERBOARD_SIZE ) {
        global $wpdb;
        $limit = max( 1, min( 100, (int) $limit ) );

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT um.user_id, um.meta_value+0 AS bal
                 FROM {$wpdb->usermeta} um
                 INNER JOIN {$wpdb->users} u ON u.ID = um.user_id
                 WHERE um.meta_key = %s
                   AND um.meta_value+0 > 0
                 ORDER BY bal DESC, um.user_id ASC
                 LIMIT %d",
                self::META_BALANCE,
                $limit
            ),
            ARRAY_A
        );

        $out  = [];
        $rank = 1;
        foreach ( (array) $rows as $row ) {
            $uid  = (int) $row['user_id'];
            $user = get_user_by( 'id', $uid );
            $out[] = [
                'user_id'      => $uid,
                'balance'      => (int) $row['bal'],
                'display_name' => $user ? ( $user->display_name ?: $user->user_login ) : ( 'User #' . $uid ),
                'rank'         => $rank,
            ];
            $rank++;
        }
        return $out;
    }

    public static function get_user_rank( $user_id ) {
        $user_id = (int) $user_id;
        $balance = self::get_balance( $user_id );
        if ( $balance <= 0 ) {
            return 0;
        }
        global $wpdb;
        $higher = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta}
                 WHERE meta_key = %s
                   AND (
                     meta_value+0 > %d
                     OR (meta_value+0 = %d AND user_id < %d)
                   )",
                self::META_BALANCE,
                $balance,
                $balance,
                $user_id
            )
        );
        return $higher + 1;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Join counters (badges)
     * ────────────────────────────────────────────────────────────────── */

    /**
     * @param int    $user_id
     * @param string $scope any|competition|activity
     */
    public static function count_paid_joins( $user_id, $scope = 'any' ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return 0;
        }

        $types = [ 'cw_competition_entry', 'cw_activity_entry' ];
        if ( 'competition' === $scope ) {
            $types = [ 'cw_competition_entry' ];
        } elseif ( 'activity' === $scope ) {
            $types = [ 'cw_activity_entry' ];
        }

        $in = "'" . implode( "','", array_map( 'esc_sql', $types ) ) . "'";

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(DISTINCT p.ID)
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id' AND owner.meta_value = %d
                 INNER JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = 'order_id' AND oid.meta_value <> '' AND oid.meta_value <> '0'
                 WHERE p.post_type IN ($in)
                   AND p.post_status IN ('publish','private','draft')",
                $user_id
            )
        );
    }

    public static function has_early_bird_join( $user_id ) {
        global $wpdb;
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return false;
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT p.ID, p.post_date, prod.meta_value AS product_id
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id' AND owner.meta_value = %d
                 INNER JOIN {$wpdb->postmeta} oid ON oid.post_id = p.ID AND oid.meta_key = 'order_id' AND oid.meta_value <> ''
                 INNER JOIN {$wpdb->postmeta} prod ON prod.post_id = p.ID AND prod.meta_key = 'product_id'
                 WHERE p.post_type IN ('cw_competition_entry','cw_activity_entry')
                 LIMIT 200",
                $user_id
            ),
            ARRAY_A
        );

        foreach ( (array) $rows as $row ) {
            $pid = (int) $row['product_id'];
            if ( ! $pid ) {
                continue;
            }
            $start = get_post_meta( $pid, 'cw_submission_start', true );
            if ( ! $start ) {
                $start = get_post_field( 'post_date', $pid );
            }
            $start_ts = strtotime( (string) $start );
            $join_ts  = strtotime( (string) $row['post_date'] );
            if ( ! $start_ts || ! $join_ts ) {
                continue;
            }
            if ( $join_ts >= $start_ts && $join_ts <= ( $start_ts + ( 7 * DAY_IN_SECONDS ) ) ) {
                return true;
            }
        }

        return false;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Badge rules
     * ────────────────────────────────────────────────────────────────── */

    public static function register_badge_rules( $handlers ) {
        $handlers['paid_joins_any']         = [ __CLASS__, 'rule_paid_joins_any' ];
        $handlers['paid_joins_competition'] = [ __CLASS__, 'rule_paid_joins_competition' ];
        $handlers['early_bird_join']        = [ __CLASS__, 'rule_early_bird_join' ];
        $handlers['top_contributor']        = [ __CLASS__, 'rule_top_contributor' ];
        $handlers['event_explorer']         = [ __CLASS__, 'rule_event_explorer' ];
        $handlers['creative_legend']        = [ __CLASS__, 'rule_creative_legend' ];
        $handlers['champion']               = [ __CLASS__, 'rule_champion' ];
        return $handlers;
    }

    public static function rule_paid_joins_any( WP_User $user, $badge, $ctx ) {
        $min = self::extra_min( $badge, 1 );
        return self::count_paid_joins( $user->ID, 'any' ) >= $min;
    }

    public static function rule_paid_joins_competition( WP_User $user, $badge, $ctx ) {
        $min = self::extra_min( $badge, 1 );
        return self::count_paid_joins( $user->ID, 'competition' ) >= $min;
    }

    public static function rule_early_bird_join( WP_User $user, $badge, $ctx ) {
        return self::has_early_bird_join( $user->ID );
    }

    public static function rule_top_contributor( WP_User $user, $badge, $ctx ) {
        $top = get_option( self::OPTION_TOP10, [] );
        if ( ! is_array( $top ) ) {
            return false;
        }
        return in_array( (int) $user->ID, array_map( 'intval', $top ), true );
    }

    public static function rule_event_explorer( WP_User $user, $badge, $ctx ) {
        return self::count_paid_joins( $user->ID, 'competition' ) >= 1
            && self::count_paid_joins( $user->ID, 'activity' ) >= 1;
    }

    public static function rule_creative_legend( WP_User $user, $badge, $ctx ) {
        return self::count_paid_joins( $user->ID, 'any' ) >= 50
            || self::get_lifetime_earned( $user->ID ) >= 10000;
    }

    public static function rule_champion( WP_User $user, $badge, $ctx ) {
        if ( class_exists( 'CW_Badges_Engine' ) ) {
            return CW_Badges_Engine::rule_first_win( $user, $badge, $ctx );
        }
        return false;
    }

    private static function extra_min( $badge, $default = 1 ) {
        $raw = $badge['extra_config'] ?? '';
        if ( is_string( $raw ) && $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) && isset( $decoded['min'] ) ) {
                return max( 1, (int) $decoded['min'] );
            }
        }
        return (int) $default;
    }
}
