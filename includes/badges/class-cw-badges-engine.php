<?php
/**
 * The badge rule engine.
 *
 *   CW_Badges_Engine::evaluate_user( $user_id )
 *      -> loads every applicable badge for the user's role
 *      -> runs the matching rule handler
 *      -> awards any newly-earned tier
 *      -> fires `cw_badge_awarded` per row inserted
 *
 *   CW_Badges_Engine::award( $user_id, $badge_id, $tier )
 *      -> inserts (or upserts) a row in cw_user_badges
 *      -> fires `cw_badge_awarded`
 *
 *   CW_Badges_Engine::get_user_badges( $user_id )
 *      -> returns hydrated rows for display
 *
 * Hooks: see `register_hooks()` — every relevant action calls evaluate_user.
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Badges_Engine {

    const TIER_FLAT      = 'flat';
    const TIER_BRONZE    = 'bronze';
    const TIER_SILVER    = 'silver';
    const TIER_GOLD      = 'gold';
    const TIER_PLATINUM  = 'platinum';

    /** Ordered ladder. Index = tier weight (0..3). */
    public static function tier_ladder() {
        return [ self::TIER_BRONZE, self::TIER_SILVER, self::TIER_GOLD, self::TIER_PLATINUM ];
    }

    /** Per-request memo for the user's badge rows. */
    private static $user_badge_cache = [];

    /** Per-request memo for the catalog. */
    private static $catalog_cache = null;

    /* ──────────────────────────────────────────────────────────────────
     *  Boot
     * ────────────────────────────────────────────────────────────────── */

    public static function register_hooks() {
        // Entry submissions (both campaign types).
        add_action( 'save_post_cw_activity_entry',    [ __CLASS__, 'on_entry_saved' ], 30, 3 );
        add_action( 'save_post_cw_competition_entry', [ __CLASS__, 'on_entry_saved' ], 30, 3 );

        // Campaign publish/update.
        add_action( 'save_post_product', [ __CLASS__, 'on_product_saved' ], 30, 3 );

        // Profile changes.
        add_action( 'profile_update',          [ __CLASS__, 'on_profile_update' ], 30 );
        add_action( 'cw_business_info_saved',  [ __CLASS__, 'on_profile_update' ], 30 );

        // Certificate issued (best-effort — fires only if our certificate class fires it).
        add_action( 'cw_certificate_issued', [ __CLASS__, 'on_certificate_issued' ], 30, 2 );

        // Order completed — purchaser (contestant/creator) may have a new entry created.
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'on_order_completed' ], 30, 1 );

        // Cheap once-per-day catch-up at login.
        add_action( 'wp_login', [ __CLASS__, 'on_login' ], 30, 2 );

        // Bust per-request memo whenever a row is inserted/deleted.
        add_action( 'cw_badge_awarded', [ __CLASS__, 'invalidate_user_cache' ] );

        // Email notification on award (opt-in via user meta cw_badge_email_opt_in = 1).
        add_action( 'cw_badge_awarded', [ __CLASS__, 'maybe_send_award_email' ], 40 );

        // Deferred eval (avoids stacking badge queries during concurrent checkout).
        add_action( 'cw_badge_evaluate_user', [ __CLASS__, 'run_deferred_evaluate' ], 10, 1 );
    }

    /**
     * Send a notification email to the user when they earn a badge, but only
     * when they've opted in (`cw_badge_email_opt_in` user-meta = 1).
     */
    public static function maybe_send_award_email( $args ) {
        if ( ! is_array( $args ) ) return;
        $user_id  = (int) ( $args['user_id'] ?? 0 );
        $badge_id = (int) ( $args['badge_id'] ?? 0 );
        $tier     = (string) ( $args['tier'] ?? '' );
        if ( $user_id <= 0 || $badge_id <= 0 ) return;

        if ( (string) get_user_meta( $user_id, 'cw_badge_email_opt_in', true ) !== '1' ) {
            return;
        }
        $user = get_user_by( 'id', $user_id );
        if ( ! $user || ! $user->user_email ) return;

        $catalog = self::get_catalog_by_id();
        if ( ! isset( $catalog[ $badge_id ] ) ) return;
        $badge = $catalog[ $badge_id ];

        $tier_label = $tier === self::TIER_FLAT ? '' : ucfirst( $tier );
        $site_name  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject    = sprintf( __( '[%1$s] You unlocked the %2$s badge!', 'creativewings-core' ), $site_name, $badge['title'] );

        $body  = '<p>' . esc_html( sprintf( __( 'Congratulations %s,', 'creativewings-core' ), $user->display_name ?: $user->user_login ) ) . '</p>';
        $body .= '<p>' . esc_html( sprintf( __( "You just earned the \"%s\" badge", 'creativewings-core' ), $badge['title'] ) );
        if ( $tier_label ) {
            $body .= ' &mdash; <strong>' . esc_html( $tier_label ) . '</strong> ' . esc_html__( 'tier', 'creativewings-core' );
        }
        $body .= '.</p>';
        if ( ! empty( $badge['description'] ) ) {
            $body .= '<p style="color:#475569;">' . esc_html( wp_strip_all_tags( $badge['description'] ) ) . '</p>';
        }
        $body .= '<p><a href="' . esc_url( home_url( '/my-account/?tab=badges' ) ) . '" style="display:inline-block;padding:10px 18px;background:#0ea5e9;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">' . esc_html__( 'View your badges', 'creativewings-core' ) . '</a></p>';
        $body .= '<p style="font-size:12px;color:#94a3b8;">' . esc_html__( 'You are receiving this because badge emails are enabled in your settings.', 'creativewings-core' ) . '</p>';

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        wp_mail( $user->user_email, $subject, $body, $headers );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Hook handlers
     * ────────────────────────────────────────────────────────────────── */

    public static function on_entry_saved( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( ! isset( $post->post_status ) || $post->post_status === 'auto-draft' ) {
            return;
        }
        // Bulk entry creation during paid checkout — CW_Post_Checkout async evaluates badges.
        if ( ! empty( $GLOBALS['cw_defer_badge_eval'] ) ) {
            return;
        }
        // Owner = customer_id meta (set by checkout) or post_author fallback.
        $uid = (int) get_post_meta( $post_id, 'customer_id', true );
        if ( ! $uid ) $uid = (int) $post->post_author;
        if ( $uid > 0 ) {
            self::queue_or_evaluate( $uid, [ 'event' => 'entry_saved', 'entry_id' => $post_id ] );
        }

        // Also re-evaluate the campaign organizer (for participant_total / first_campaign etc.).
        $pid = (int) get_post_meta( $post_id, 'product_id', true );
        if ( $pid > 0 ) {
            $org = (int) get_post_field( 'post_author', $pid );
            if ( $org > 0 ) {
                self::queue_or_evaluate( $org, [ 'event' => 'entry_saved', 'role' => 'organizer', 'campaign_id' => $pid ] );
            }
        }
    }

    public static function on_product_saved( $post_id, $post, $update ) {
        if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
            return;
        }
        if ( $post->post_status !== 'publish' ) {
            return;
        }
        $uid = (int) $post->post_author;
        if ( $uid > 0 ) {
            self::evaluate_user( $uid, [ 'event' => 'product_saved', 'campaign_id' => $post_id ] );
        }
    }

    public static function on_profile_update( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id > 0 ) {
            self::evaluate_user( $user_id, [ 'event' => 'profile_update' ] );
        }
    }

    public static function on_certificate_issued( $entry_id, $user_id = 0 ) {
        if ( ! $user_id ) {
            $user_id = (int) get_post_meta( $entry_id, 'customer_id', true );
        }
        if ( $user_id > 0 ) {
            self::evaluate_user( (int) $user_id, [ 'event' => 'cert_issued', 'entry_id' => $entry_id ] );
        }
    }

    public static function on_order_completed( $order_id ) {
        // Badge eval for paid joins is handled by CW_Post_Checkout async.
        if ( class_exists( 'CW_Post_Checkout' ) && CW_Post_Checkout::defers_side_effects() ) {
            return;
        }
        if ( ! function_exists( 'wc_get_order' ) ) return;
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;
        $uid = (int) $order->get_user_id();
        if ( $uid > 0 ) {
            self::queue_or_evaluate( $uid, [ 'event' => 'order_completed', 'order_id' => $order_id ] );
        }
    }

    public static function on_login( $user_login, $user ) {
        if ( ! ( $user instanceof WP_User ) || ! $user->ID ) return;
        $uid = (int) $user->ID;
        $marker_key = 'cw_badge_login_eval_' . $uid;
        if ( get_transient( $marker_key ) ) {
            return; // Rate-limit to once per day.
        }
        set_transient( $marker_key, 1, DAY_IN_SECONDS );
        self::evaluate_user( $uid, [ 'event' => 'login' ] );
    }

    public static function invalidate_user_cache( $args = [] ) {
        if ( is_array( $args ) && ! empty( $args['user_id'] ) ) {
            unset( self::$user_badge_cache[ (int) $args['user_id'] ] );
        } else {
            self::$user_badge_cache = [];
        }
    }

    /**
     * During bulk entry creation (checkout flood), queue badge eval for cron
     * instead of running it inline on every wp_insert_post.
     *
     * @param int   $user_id
     * @param array $event_context
     */
    public static function queue_or_evaluate( $user_id, $event_context = [] ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        $defer = ! empty( $GLOBALS['cw_defer_badge_eval'] );

        if ( ! $defer ) {
            self::evaluate_user( $user_id, $event_context );
            return;
        }

        // Dedupe: one pending eval per user within a short window.
        $lock_key = 'cw_badge_q_' . $user_id;
        if ( get_transient( $lock_key ) ) {
            return;
        }
        set_transient( $lock_key, 1, 2 * MINUTE_IN_SECONDS );

        if ( ! wp_next_scheduled( 'cw_badge_evaluate_user', [ $user_id ] ) ) {
            wp_schedule_single_event( time() + 45, 'cw_badge_evaluate_user', [ $user_id ] );
        }
    }

    /**
     * Cron callback for deferred evaluate_user.
     *
     * @param int $user_id
     */
    public static function run_deferred_evaluate( $user_id ) {
        $user_id = (int) $user_id;
        delete_transient( 'cw_badge_q_' . $user_id );
        if ( $user_id > 0 ) {
            self::evaluate_user( $user_id, [ 'event' => 'deferred' ] );
        }
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Public API
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Evaluate every applicable badge for the user and award any newly
     * satisfied tiers. Returns the list of awards made this pass.
     *
     * @return array<int, array>  awards inserted this call
     */
    public static function evaluate_user( $user_id, $event_context = [] ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return [];

        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) return [];

        $role = self::user_target_role( $user );
        if ( ! $role ) return [];

        $catalog = self::get_catalog();
        $owned   = self::get_user_badges( $user_id, true );
        $by_id   = [];
        foreach ( $owned as $row ) {
            $by_id[ (int) $row['badge_id'] ][ $row['tier'] ] = $row;
        }

        $awards = [];
        foreach ( $catalog as $badge ) {
            // Filter to badges this user's role can earn.
            if ( $badge['target_role'] !== 'any' && $badge['target_role'] !== $role ) {
                continue;
            }
            if ( $badge['is_admin_only'] ) {
                continue; // Skip admin-only — handled via manual award.
            }
            $rule = $badge['rule_type'];
            if ( $rule === 'manual' ) {
                continue;
            }

            // Run the rule handler. Returns either:
            //   - bool true       -> award flat tier
            //   - int / float val -> compare against thresholds[] to award tier
            //   - false / null    -> no progress
            $value = self::run_rule( $rule, $user, $badge, $event_context );
            if ( $value === false || $value === null ) {
                continue;
            }

            $tier = self::resolve_tier( $badge, $value );
            if ( $tier === '' ) {
                continue; // value > 0 but didn't reach the lowest threshold.
            }

            $already = isset( $by_id[ $badge['id'] ] ) ? $by_id[ $badge['id'] ] : [];

            if ( $tier === self::TIER_FLAT ) {
                if ( isset( $already[ self::TIER_FLAT ] ) ) continue;
                if ( self::award( $user_id, $badge['id'], self::TIER_FLAT, [
                    'auto' => true, 'event' => $event_context, 'value' => $value,
                ] ) ) {
                    $awards[] = [ 'badge_id' => $badge['id'], 'slug' => $badge['slug'], 'tier' => self::TIER_FLAT ];
                }
                continue;
            }

            // Tiered: award any tier ≤ resolved tier that isn't already owned.
            $earned_index = array_search( $tier, self::tier_ladder(), true );
            if ( $earned_index === false ) continue;
            for ( $i = 0; $i <= $earned_index; $i++ ) {
                $t = self::tier_ladder()[ $i ];
                if ( isset( $already[ $t ] ) ) continue;
                if ( self::award( $user_id, $badge['id'], $t, [
                    'auto' => true, 'event' => $event_context, 'value' => $value,
                ] ) ) {
                    $awards[] = [ 'badge_id' => $badge['id'], 'slug' => $badge['slug'], 'tier' => $t ];
                }
            }
        }
        return $awards;
    }

    /**
     * Insert (or quietly skip on duplicate) a user_badge row.
     *
     * @return bool  true when a new row was written this call.
     */
    public static function award( $user_id, $badge_id, $tier = self::TIER_FLAT, $context = [] ) {
        global $wpdb;
        $user_id  = (int) $user_id;
        $badge_id = (int) $badge_id;
        if ( $user_id <= 0 || $badge_id <= 0 ) return false;

        $tier = (string) $tier;
        if ( ! in_array( $tier, [ self::TIER_FLAT, self::TIER_BRONZE, self::TIER_SILVER, self::TIER_GOLD, self::TIER_PLATINUM ], true ) ) {
            $tier = self::TIER_FLAT;
        }

        $table = CW_Badges_Installer::table();
        $inserted = $wpdb->query( $wpdb->prepare(
            "INSERT IGNORE INTO $table (user_id, badge_id, tier, earned_at, awarded_by, context)
             VALUES (%d, %d, %s, %s, %s, %s)",
            $user_id,
            $badge_id,
            $tier,
            current_time( 'mysql' ),
            is_user_logged_in() ? get_current_user_id() : null,
            wp_json_encode( $context )
        ) );

        if ( ! $inserted ) {
            return false; // Duplicate (already owned this tier).
        }

        // Stash in the toast queue so a dashboard pageload can show a slide-in.
        $queue_key = 'cw_badge_toast_' . $user_id;
        $queue     = (array) get_transient( $queue_key );
        $queue[]   = [ 'badge_id' => $badge_id, 'tier' => $tier, 'earned_at' => current_time( 'mysql' ) ];
        // Keep the queue small.
        $queue = array_slice( $queue, -8 );
        set_transient( $queue_key, $queue, 7 * DAY_IN_SECONDS );

        do_action( 'cw_badge_awarded', [
            'user_id'  => $user_id,
            'badge_id' => $badge_id,
            'tier'     => $tier,
            'context'  => $context,
        ] );

        return true;
    }

    /**
     * Return the user's owned badge rows (raw or hydrated with badge metadata).
     *
     * @param int  $user_id
     * @param bool $raw  When true, returns rows as-is from the table.
     */
    public static function get_user_badges( $user_id, $raw = false ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return [];

        if ( ! $raw && isset( self::$user_badge_cache[ $user_id ] ) ) {
            return self::$user_badge_cache[ $user_id ];
        }

        global $wpdb;
        $table = CW_Badges_Installer::table();
        $rows  = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM $table WHERE user_id = %d ORDER BY earned_at DESC",
            $user_id
        ), ARRAY_A );

        if ( $raw ) {
            return $rows;
        }

        // Hydrate with badge meta + only keep the highest-tier row per badge (for cards).
        $catalog   = self::get_catalog_by_id();
        $by_badge  = [];
        $ladder    = self::tier_ladder();
        foreach ( $rows as $row ) {
            $bid = (int) $row['badge_id'];
            if ( ! isset( $catalog[ $bid ] ) ) continue;
            $tier_weight = array_search( $row['tier'], $ladder, true );
            if ( $tier_weight === false ) $tier_weight = -1; // flat
            if ( isset( $by_badge[ $bid ] ) && $by_badge[ $bid ]['tier_weight'] >= $tier_weight ) {
                continue;
            }
            $by_badge[ $bid ] = array_merge( $catalog[ $bid ], $row, [
                'tier_weight' => $tier_weight,
            ] );
        }

        // Sort: highest tier first, then most recent.
        usort( $by_badge, static function ( $a, $b ) {
            if ( $a['tier_weight'] !== $b['tier_weight'] ) {
                return $b['tier_weight'] <=> $a['tier_weight'];
            }
            return strcmp( $b['earned_at'], $a['earned_at'] );
        } );

        self::$user_badge_cache[ $user_id ] = array_values( $by_badge );
        return self::$user_badge_cache[ $user_id ];
    }

    /**
     * Manually award a badge (admin form).
     */
    public static function manual_award( $user_id, $badge_id, $tier = self::TIER_FLAT ) {
        return self::award( (int) $user_id, (int) $badge_id, $tier, [
            'manual' => true, 'by' => get_current_user_id(),
        ] );
    }

    /**
     * Remove a single user_badge row (admin/debug only).
     */
    public static function revoke( $user_id, $badge_id, $tier = self::TIER_FLAT ) {
        global $wpdb;
        $rows = $wpdb->delete( CW_Badges_Installer::table(), [
            'user_id' => (int) $user_id, 'badge_id' => (int) $badge_id, 'tier' => (string) $tier,
        ] );
        if ( $rows ) {
            self::invalidate_user_cache( [ 'user_id' => $user_id ] );
        }
        return (bool) $rows;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Catalog
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Return all published badges, hydrated with their meta.
     */
    public static function get_catalog() {
        if ( self::$catalog_cache !== null ) {
            return self::$catalog_cache;
        }

        // Cross-request cache — catalog rarely changes; avoids reloading every badge
        // post + meta on each concurrent checkout entry insert.
        if ( class_exists( 'CW_Cache' ) ) {
            $cached = CW_Cache::get( 'catalog_v1', 'badges' );
            if ( is_array( $cached ) ) {
                self::$catalog_cache = $cached;
                return self::$catalog_cache;
            }
        }

        $posts = get_posts( [
            'post_type'              => CW_Badges_CPT::POST_TYPE,
            'post_status'            => 'publish',
            'posts_per_page'         => 100,
            'orderby'                => 'meta_value_num',
            'meta_key'               => 'cw_badge_sort_order',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => true,
            'update_post_term_cache' => false,
        ] );

        $out = [];
        foreach ( $posts as $p ) {
            $out[] = self::hydrate_badge( $p );
        }
        self::$catalog_cache = $out;

        if ( class_exists( 'CW_Cache' ) ) {
            CW_Cache::set( 'catalog_v1', 'badges', $out, 10 * MINUTE_IN_SECONDS );
        }

        return $out;
    }

    public static function get_catalog_by_id() {
        $by_id = [];
        foreach ( self::get_catalog() as $b ) {
            $by_id[ (int) $b['id'] ] = $b;
        }
        return $by_id;
    }

    public static function find_by_slug( $slug ) {
        foreach ( self::get_catalog() as $b ) {
            if ( $b['slug'] === $slug ) return $b;
        }
        return null;
    }

    private static function hydrate_badge( $post ) {
        $id          = (int) $post->ID;
        $thresholds  = (string) get_post_meta( $id, 'cw_badge_thresholds', true );
        $thresh_arr  = [];
        if ( $thresholds !== '' ) {
            foreach ( explode( ',', $thresholds ) as $t ) {
                $t = trim( $t );
                if ( $t !== '' ) $thresh_arr[] = (float) $t;
            }
        }
        $extra_raw = (string) get_post_meta( $id, 'cw_badge_extra_config', true );
        $extra     = $extra_raw ? json_decode( $extra_raw, true ) : [];
        if ( ! is_array( $extra ) ) $extra = [];

        $icon_id    = (int) get_post_meta( $id, 'cw_badge_icon', true );
        $icon_url   = $icon_id ? (string) wp_get_attachment_image_url( $icon_id, 'thumbnail' ) : '';

        return [
            'id'             => $id,
            'slug'           => (string) get_post_meta( $id, 'cw_badge_slug', true ) ?: sanitize_title( $post->post_title ),
            'title'          => get_the_title( $post ),
            'description'    => (string) $post->post_content,
            'target_role'    => (string) ( get_post_meta( $id, 'cw_badge_target_role', true ) ?: 'creator' ),
            'rule_type'      => (string) ( get_post_meta( $id, 'cw_badge_rule_type', true ) ?: 'manual' ),
            'thresholds'     => $thresh_arr,
            'icon_id'        => $icon_id,
            'icon_url'       => $icon_url,
            'icon_class'     => (string) ( get_post_meta( $id, 'cw_badge_icon_class', true ) ?: 'fas fa-medal' ),
            'color'          => (string) ( get_post_meta( $id, 'cw_badge_color', true ) ?: '#0ea5e9' ),
            'sort_order'     => (int) get_post_meta( $id, 'cw_badge_sort_order', true ),
            'is_admin_only'  => (string) get_post_meta( $id, 'cw_badge_is_admin_only', true ) === '1',
            'extra'          => $extra,
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Helpers
     * ────────────────────────────────────────────────────────────────── */

    public static function user_target_role( WP_User $user ) {
        if ( in_array( 'business_role', (array) $user->roles, true ) ) return 'business';
        if ( in_array( 'creator_role',  (array) $user->roles, true ) ) return 'creator';
        if ( in_array( 'contestant',    (array) $user->roles, true ) ) return 'creator';
        if ( in_array( 'administrator', (array) $user->roles, true ) ) return 'business';
        return '';
    }

    /**
     * Translate a numeric value into a tier slug, based on the badge thresholds.
     * Flat badges return TIER_FLAT when value is truthy.
     */
    private static function resolve_tier( array $badge, $value ) {
        $thresholds = $badge['thresholds'];
        if ( empty( $thresholds ) ) {
            return $value ? self::TIER_FLAT : '';
        }
        $ladder = self::tier_ladder();
        $best   = '';
        $val    = (float) $value;
        foreach ( $thresholds as $i => $threshold ) {
            if ( $val >= (float) $threshold && isset( $ladder[ $i ] ) ) {
                $best = $ladder[ $i ];
            } else {
                break;
            }
        }
        return $best;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Rule dispatcher
     * ────────────────────────────────────────────────────────────────── */

    private static function run_rule( $rule, WP_User $user, array $badge, array $event_context ) {
        $handlers = apply_filters( 'cw_badges_rule_handlers', [
            'count_entries'      => [ __CLASS__, 'rule_count_entries' ],
            'count_certificates' => [ __CLASS__, 'rule_count_certificates' ],
            'count_portfolio'    => [ __CLASS__, 'rule_count_portfolio' ],
            'count_campaigns'    => [ __CLASS__, 'rule_count_campaigns' ],
            'participant_total'  => [ __CLASS__, 'rule_participant_total' ],
            'prize_total'        => [ __CLASS__, 'rule_prize_total' ],
            'profile_complete'   => [ __CLASS__, 'rule_profile_complete' ],
            'directory_complete' => [ __CLASS__, 'rule_directory_complete' ],
            'first_entry'        => [ __CLASS__, 'rule_first_entry' ],
            'first_campaign'     => [ __CLASS__, 'rule_first_campaign' ],
            'first_win'          => [ __CLASS__, 'rule_first_win' ],
            'perfect_score'      => [ __CLASS__, 'rule_perfect_score' ],
            'crowd_favorite'     => [ __CLASS__, 'rule_crowd_favorite' ],
            'multi_organizer'    => [ __CLASS__, 'rule_multi_organizer' ],
            'multi_category'     => [ __CLASS__, 'rule_multi_category' ],
            'campaign_types'     => [ __CLASS__, 'rule_campaign_types' ],
            'tenure_days'        => [ __CLASS__, 'rule_tenure_days' ],
            'consecutive_months' => [ __CLASS__, 'rule_consecutive_months' ],
            'social_links'       => [ __CLASS__, 'rule_social_links' ],
            'fast_judge'         => [ __CLASS__, 'rule_fast_judge' ],
            'judge_quality'      => [ __CLASS__, 'rule_judge_quality' ],
            'early_adopter'      => [ __CLASS__, 'rule_early_adopter' ],
        ] );

        if ( ! isset( $handlers[ $rule ] ) || ! is_callable( $handlers[ $rule ] ) ) {
            return null;
        }
        return call_user_func( $handlers[ $rule ], $user, $badge, $event_context );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Rule handlers
     * ────────────────────────────────────────────────────────────────── */

    public static function rule_count_entries( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID
             WHERE p.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND p.post_status IN ('publish','private','draft')
               AND pm.meta_key = 'customer_id'
               AND pm.meta_value = %d",
            (int) $user->ID
        ) );
    }

    public static function rule_count_certificates( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        // Certificates are "earned" once `cw_cert_issued = 1` is set on the entry,
        // OR (for non-judged campaigns) once submission_deadline has passed on the
        // entry's product. The cheap proxy: count entries flagged.
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id'
             INNER JOIN {$wpdb->postmeta} cert  ON cert.post_id  = p.ID AND cert.meta_key = 'cw_cert_issued' AND cert.meta_value = '1'
             WHERE p.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND owner.meta_value = %d",
            (int) $user->ID
        ) );
    }

    public static function rule_count_portfolio( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        $table = $wpdb->prefix . 'jet_cct_creator_portfolio';
        if ( $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $table ) ) !== $table ) {
            return 0;
        }
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $table WHERE created_by = %d",
            (int) $user->ID
        ) );
    }

    public static function rule_count_campaigns( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'product'
               AND post_status = 'publish'
               AND post_author = %d",
            (int) $user->ID
        ) );
    }

    public static function rule_participant_total( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        $pids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish' AND post_author = %d",
            (int) $user->ID
        ) );
        if ( empty( $pids ) ) return 0;
        $placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta}
             WHERE meta_key = 'product_id' AND meta_value IN ($placeholders)",
            $pids
        ) );
    }

    public static function rule_prize_total( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        $val = (float) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(pm.meta_value), 0)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = 'cw_total_prize_value'
             WHERE p.post_type = 'product'
               AND p.post_status = 'publish'
               AND p.post_author = %d",
            (int) $user->ID
        ) );
        return $val;
    }

    public static function rule_profile_complete( WP_User $user, $badge, $ctx ) {
        $role = self::user_target_role( $user );
        if ( $role === 'business' ) {
            return class_exists( 'CW_Roles' ) ? ( CW_Roles::organizer_missing_basics( $user ) === true ) : false;
        }
        if ( $role === 'creator' ) {
            return class_exists( 'CW_Roles' ) ? ( CW_Roles::creator_missing_basics( $user ) === true ) : false;
        }
        return false;
    }

    public static function rule_directory_complete( WP_User $user, $badge, $ctx ) {
        if ( ! class_exists( 'CW_Roles' ) ) return false;
        $role = self::user_target_role( $user );
        if ( $role === 'business' ) {
            return (bool) CW_Roles::has_complete_organizer_profile( $user );
        }
        if ( $role === 'creator' ) {
            return (bool) CW_Roles::has_complete_creator_profile( $user );
        }
        return false;
    }

    public static function rule_first_entry( WP_User $user, $badge, $ctx ) {
        return self::rule_count_entries( $user, $badge, $ctx ) >= 1;
    }

    public static function rule_first_campaign( WP_User $user, $badge, $ctx ) {
        return self::rule_count_campaigns( $user, $badge, $ctx ) >= 1;
    }

    public static function rule_first_win( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id'
             INNER JOIN {$wpdb->postmeta} win   ON win.post_id   = p.ID AND win.meta_key   = 'winner_status' AND win.meta_value = 'yes'
             WHERE p.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND owner.meta_value = %d
             LIMIT 1",
            (int) $user->ID
        ) );
    }

    public static function rule_perfect_score( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        return (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id'
             INNER JOIN {$wpdb->postmeta} score ON score.post_id = p.ID AND score.meta_key = 'judge_score'
             WHERE p.post_type = 'cw_competition_entry'
               AND owner.meta_value = %d
               AND CAST(score.meta_value AS UNSIGNED) >= 100
             LIMIT 1",
            (int) $user->ID
        ) );
    }

    public static function rule_crowd_favorite( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        // True if the user holds the max(vote_count) for any single competition
        // where votes > 0.
        $rows = $wpdb->get_results(
            "SELECT pid_meta.meta_value AS product_id,
                    MAX(CAST(vc.meta_value AS UNSIGNED)) AS top_vote
             FROM {$wpdb->postmeta} pid_meta
             INNER JOIN {$wpdb->posts} p ON p.ID = pid_meta.post_id
             INNER JOIN {$wpdb->postmeta} vc ON vc.post_id = p.ID AND vc.meta_key = 'vote_count'
             WHERE pid_meta.meta_key = 'product_id'
               AND p.post_type = 'cw_competition_entry'
             GROUP BY pid_meta.meta_value
             HAVING top_vote > 0",
            ARRAY_A
        );
        if ( ! $rows ) return false;
        foreach ( $rows as $row ) {
            $top = (int) $row['top_vote'];
            $pid = (int) $row['product_id'];
            if ( $top <= 0 ) continue;
            $owns = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pid ON pid.post_id = p.ID AND pid.meta_key = 'product_id' AND pid.meta_value = %d
                 INNER JOIN {$wpdb->postmeta} own ON own.post_id = p.ID AND own.meta_key = 'customer_id' AND own.meta_value = %d
                 INNER JOIN {$wpdb->postmeta} vc  ON vc.post_id  = p.ID AND vc.meta_key  = 'vote_count'
                 WHERE p.post_type = 'cw_competition_entry'
                   AND CAST(vc.meta_value AS UNSIGNED) = %d
                 LIMIT 1",
                $pid, (int) $user->ID, $top
            ) );
            if ( $owns ) return true;
        }
        return false;
    }

    public static function rule_multi_organizer( WP_User $user, $badge, $ctx ) {
        $min = (int) ( $badge['extra']['min'] ?? 3 );
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT prod.post_author)
             FROM {$wpdb->posts} entries
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = entries.ID AND owner.meta_key = 'customer_id'
             INNER JOIN {$wpdb->postmeta} pid   ON pid.post_id   = entries.ID AND pid.meta_key   = 'product_id'
             INNER JOIN {$wpdb->posts} prod ON prod.ID = pid.meta_value AND prod.post_type = 'product'
             WHERE entries.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND owner.meta_value = %d",
            (int) $user->ID
        ) );
        return $count >= $min;
    }

    public static function rule_multi_category( WP_User $user, $badge, $ctx ) {
        $min = (int) ( $badge['extra']['min'] ?? 3 );
        global $wpdb;
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT tt.term_id)
             FROM {$wpdb->posts} entries
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = entries.ID AND owner.meta_key = 'customer_id'
             INNER JOIN {$wpdb->postmeta} pid   ON pid.post_id   = entries.ID AND pid.meta_key   = 'product_id'
             INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = pid.meta_value
             INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id AND tt.taxonomy = 'product_cat'
             WHERE entries.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND owner.meta_value = %d",
            (int) $user->ID
        ) );
        return $count >= $min;
    }

    public static function rule_campaign_types( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        $pids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish' AND post_author = %d",
            (int) $user->ID
        ) );
        if ( empty( $pids ) ) return false;
        $needed = [ 'activities' => false, 'competitions' => false, 'talk-seminar' => false ];
        foreach ( $pids as $pid ) {
            $terms = wp_get_post_terms( (int) $pid, 'product_cat', [ 'fields' => 'slugs' ] );
            foreach ( (array) $terms as $slug ) {
                $slug = strtolower( $slug );
                if ( strpos( $slug, 'competition' ) !== false ) $needed['competitions'] = true;
                if ( strpos( $slug, 'activit' )     !== false ) $needed['activities']   = true;
                if ( strpos( $slug, 'talk' ) !== false || strpos( $slug, 'seminar' ) !== false ) {
                    $needed['talk-seminar'] = true;
                }
            }
            if ( $needed['competitions'] && $needed['activities'] && $needed['talk-seminar'] ) {
                return true;
            }
        }
        return false;
    }

    public static function rule_tenure_days( WP_User $user, $badge, $ctx ) {
        $required = (int) ( $badge['extra']['days'] ?? 365 );
        $registered = strtotime( $user->user_registered ?: '' );
        if ( ! $registered ) return false;
        $age_days = (int) floor( ( time() - $registered ) / DAY_IN_SECONDS );
        if ( $age_days < $required ) return false;
        if ( ! empty( $badge['extra']['require_campaign'] ) ) {
            return self::rule_count_campaigns( $user, $badge, $ctx ) >= 1;
        }
        return true;
    }

    public static function rule_consecutive_months( WP_User $user, $badge, $ctx ) {
        $months_required = max( 1, (int) ( $badge['extra']['months'] ?? 6 ) );
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT DATE_FORMAT(p.post_date, '%%Y-%%m')
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} owner ON owner.post_id = p.ID AND owner.meta_key = 'customer_id'
             WHERE p.post_type IN ('cw_competition_entry','cw_activity_entry')
               AND owner.meta_value = %d
             ORDER BY p.post_date ASC",
            (int) $user->ID
        ) );
        if ( count( $rows ) < $months_required ) return false;

        // Walk the sorted list; find any run of length >= $months_required.
        $best = 1;
        $run  = 1;
        $prev = null;
        foreach ( $rows as $ym ) {
            if ( $prev === null ) { $prev = $ym; continue; }
            $expected = date( 'Y-m', strtotime( $prev . '-01 +1 month' ) );
            if ( $ym === $expected ) {
                $run++;
                $best = max( $best, $run );
            } else {
                $run = 1;
            }
            $prev = $ym;
        }
        return $best >= $months_required;
    }

    public static function rule_social_links( WP_User $user, $badge, $ctx ) {
        $min = (int) ( $badge['extra']['min'] ?? 3 );
        $keys = [ 'Facebook_url', 'instagram_url', 'linkeden_url', 'twitter_url', 'behave_url', 'youtube_url', 'tiktok_url' ];
        $count = 0;
        foreach ( $keys as $k ) {
            if ( trim( (string) get_user_meta( $user->ID, $k, true ) ) !== '' ) $count++;
        }
        return $count >= $min;
    }

    /**
     * Organizer: 5 consecutive entries scored within 48 hours of submission.
     */
    public static function rule_fast_judge( WP_User $user, $badge, $ctx ) {
        global $wpdb;
        $pids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish' AND post_author = %d",
            (int) $user->ID
        ) );
        if ( empty( $pids ) ) return false;
        $placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );

        // Pull the most recent 50 scored entries owned by this organizer's campaigns.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, p.post_date,
                    score_at.meta_value AS scored_at,
                    sc.meta_value       AS score
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pid ON pid.post_id = p.ID AND pid.meta_key = 'product_id'
             INNER JOIN {$wpdb->postmeta} sc  ON sc.post_id  = p.ID AND sc.meta_key  = 'judge_score' AND sc.meta_value <> ''
             LEFT JOIN  {$wpdb->postmeta} score_at ON score_at.post_id = p.ID AND score_at.meta_key = 'cw_scored_at'
             WHERE p.post_type = 'cw_competition_entry'
               AND pid.meta_value IN ($placeholders)
             ORDER BY p.post_date DESC
             LIMIT 50",
            $pids
        ), ARRAY_A );
        if ( empty( $rows ) ) return false;

        $streak = 0;
        foreach ( $rows as $row ) {
            $submitted_at = strtotime( $row['post_date'] );
            $scored_at    = $row['scored_at'] ? strtotime( $row['scored_at'] ) : $submitted_at + ( 7 * DAY_IN_SECONDS );
            $diff_hours   = ( $scored_at - $submitted_at ) / HOUR_IN_SECONDS;
            if ( $diff_hours > 0 && $diff_hours <= 48 ) {
                $streak++;
                if ( $streak >= 5 ) return true;
            } else {
                $streak = 0;
            }
        }
        return false;
    }

    /**
     * Organizer: judge_comment filled on >= ratio of their scored entries (min count).
     */
    public static function rule_judge_quality( WP_User $user, $badge, $ctx ) {
        $min_entries = (int)   ( $badge['extra']['min_entries'] ?? 10 );
        $ratio       = (float) ( $badge['extra']['ratio']       ?? 0.8 );
        global $wpdb;
        $pids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product' AND post_status = 'publish' AND post_author = %d",
            (int) $user->ID
        ) );
        if ( empty( $pids ) ) return false;
        $placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );

        $scored = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pid ON pid.post_id = p.ID AND pid.meta_key = 'product_id'
             INNER JOIN {$wpdb->postmeta} sc  ON sc.post_id  = p.ID AND sc.meta_key  = 'judge_score' AND sc.meta_value <> ''
             WHERE p.post_type = 'cw_competition_entry'
               AND pid.meta_value IN ($placeholders)",
            $pids
        ) );
        if ( $scored < $min_entries ) return false;

        $with_comment = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pid ON pid.post_id = p.ID AND pid.meta_key = 'product_id'
             INNER JOIN {$wpdb->postmeta} sc  ON sc.post_id  = p.ID AND sc.meta_key  = 'judge_score' AND sc.meta_value <> ''
             INNER JOIN {$wpdb->postmeta} jc  ON jc.post_id  = p.ID AND jc.meta_key  = 'judge_comment' AND jc.meta_value <> ''
             WHERE p.post_type = 'cw_competition_entry'
               AND pid.meta_value IN ($placeholders)",
            $pids
        ) );

        return ( $with_comment / max( 1, $scored ) ) >= $ratio;
    }

    public static function rule_early_adopter( WP_User $user, $badge, $ctx ) {
        $cutoff = (string) ( $badge['extra']['cutoff'] ?? '2026-12-31' );
        $cutoff_ts = strtotime( $cutoff . ' 23:59:59' );
        $reg_ts    = strtotime( $user->user_registered ?: '' );
        if ( ! $cutoff_ts || ! $reg_ts ) return false;
        return $reg_ts <= $cutoff_ts;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Bulk re-evaluation (used by the admin Sync Center / cron)
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Evaluate one chunk of users, starting after $after_user_id.
     *
     * @return array{processed:int, awarded:int, last_id:int, has_more:bool}
     */
    public static function reevaluate_batch( $after_user_id = 0, $limit = 50 ) {
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->users} WHERE ID > %d ORDER BY ID ASC LIMIT %d",
            (int) $after_user_id,
            (int) $limit
        ) );
        $processed = 0;
        $awarded   = 0;
        $last_id   = (int) $after_user_id;
        foreach ( (array) $ids as $uid ) {
            $uid     = (int) $uid;
            $last_id = $uid;
            $new     = self::evaluate_user( $uid, [ 'event' => 'bulk_reeval' ] );
            $processed++;
            $awarded += count( (array) $new );
        }
        return [
            'processed' => $processed,
            'awarded'   => $awarded,
            'last_id'   => $last_id,
            'has_more'  => count( (array) $ids ) === (int) $limit,
        ];
    }
}
