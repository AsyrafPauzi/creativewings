<?php
/**
 * Creates the user-badge ledger table and seeds the default badge catalog.
 *
 * Runs from CW_Activator::create_tables() and also via a "needs-seed"
 * versioned option so existing installs pick up new badges and table changes
 * without a manual reinstall.
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Badges_Installer {

    const TABLE_OPTION       = 'cw_badges_db_version';
    const TARGET_TABLE_VER   = '1.0.0';
    const SEED_OPTION        = 'cw_badges_seed_version';
    const TARGET_SEED_VER    = '1.1.0';

    /** Full ledger table name (with the WP prefix). */
    public static function table() {
        global $wpdb;
        return $wpdb->prefix . 'cw_user_badges';
    }

    /**
     * Ensure the table + default catalog exist. Cheap to call from an `init`
     * hook (gated by version options).
     */
    public static function maybe_install() {
        if ( get_option( self::TABLE_OPTION ) !== self::TARGET_TABLE_VER ) {
            self::create_table();
            update_option( self::TABLE_OPTION, self::TARGET_TABLE_VER, false );
        }
        if ( get_option( self::SEED_OPTION ) !== self::TARGET_SEED_VER ) {
            self::seed_defaults();
            update_option( self::SEED_OPTION, self::TARGET_SEED_VER, false );
        }
    }

    public static function create_table() {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        // tier is a varchar (no MySQL ENUM) so we can extend later without a migration.
        $sql = "CREATE TABLE $table (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            badge_id BIGINT(20) UNSIGNED NOT NULL,
            tier VARCHAR(16) NOT NULL DEFAULT 'flat',
            earned_at DATETIME NOT NULL,
            awarded_by BIGINT(20) UNSIGNED DEFAULT NULL,
            context LONGTEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_badge_tier (user_id, badge_id, tier),
            KEY user_id (user_id),
            KEY badge_id (badge_id),
            KEY earned_at (earned_at)
        ) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }

    /**
     * Seed the 25 default badges if they don't exist yet (matched by slug).
     */
    public static function seed_defaults() {
        $catalog = self::default_catalog();
        foreach ( $catalog as $entry ) {
            $existing = self::find_badge_by_slug( $entry['slug'] );
            if ( $existing ) {
                continue;
            }
            $post_id = wp_insert_post( [
                'post_type'    => CW_Badges_CPT::POST_TYPE,
                'post_status'  => 'publish',
                'post_title'   => $entry['title'],
                'post_content' => $entry['description'] ?? '',
            ], true );
            if ( is_wp_error( $post_id ) || ! $post_id ) {
                continue;
            }
            update_post_meta( $post_id, 'cw_badge_slug',           $entry['slug'] );
            update_post_meta( $post_id, 'cw_badge_target_role',    $entry['target_role'] );
            update_post_meta( $post_id, 'cw_badge_rule_type',      $entry['rule_type'] );
            update_post_meta( $post_id, 'cw_badge_thresholds',     $entry['thresholds'] ?? '' );
            update_post_meta( $post_id, 'cw_badge_icon_class',     $entry['icon_class'] ?? 'fas fa-medal' );
            update_post_meta( $post_id, 'cw_badge_color',          $entry['color'] ?? '#0ea5e9' );
            update_post_meta( $post_id, 'cw_badge_sort_order',     $entry['sort_order'] ?? 100 );
            update_post_meta( $post_id, 'cw_badge_is_admin_only',  ! empty( $entry['admin_only'] ) ? '1' : '0' );
            update_post_meta( $post_id, 'cw_badge_extra_config',   $entry['extra_config'] ?? '' );
        }
    }

    public static function find_badge_by_slug( $slug ) {
        $q = new WP_Query( [
            'post_type'      => CW_Badges_CPT::POST_TYPE,
            'post_status'    => 'any',
            'posts_per_page' => 1,
            'meta_key'       => 'cw_badge_slug',
            'meta_value'     => (string) $slug,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return ! empty( $q->posts ) ? (int) $q->posts[0] : 0;
    }

    /**
     * The 25-badge default catalog (see plan §1).
     */
    public static function default_catalog() {
        return [
            // ───── Creator tiered ─────
            [ 'slug' => 'creator_participant', 'title' => 'Active Participant', 'target_role' => 'creator',
              'rule_type' => 'count_entries', 'thresholds' => '1,5,25,100',
              'icon_class' => 'fas fa-flag', 'color' => '#0ea5e9', 'sort_order' => 10,
              'description' => 'Submit campaign entries to earn participation tiers.' ],

            [ 'slug' => 'creator_certified', 'title' => 'Certified', 'target_role' => 'creator',
              'rule_type' => 'count_certificates', 'thresholds' => '1,5,15,50',
              'icon_class' => 'fas fa-certificate', 'color' => '#22c55e', 'sort_order' => 20,
              'description' => 'Receive certificates from completed campaigns.' ],

            [ 'slug' => 'creator_portfolio', 'title' => 'Portfolio Showcase', 'target_role' => 'creator',
              'rule_type' => 'count_portfolio', 'thresholds' => '1,5,15,50',
              'icon_class' => 'fas fa-images', 'color' => '#a855f7', 'sort_order' => 30,
              'description' => 'Publish portfolio works on your creator profile.' ],

            // ───── Creator flat ─────
            [ 'slug' => 'creator_first_steps', 'title' => 'First Steps', 'target_role' => 'creator',
              'rule_type' => 'profile_complete', 'icon_class' => 'fas fa-shoe-prints',
              'color' => '#0ea5e9', 'sort_order' => 100,
              'description' => 'Filled the four directory basics: name, tagline, photo, location.' ],

            [ 'slug' => 'creator_verified', 'title' => 'Verified Creator', 'target_role' => 'creator',
              'rule_type' => 'directory_complete', 'icon_class' => 'fas fa-check-circle',
              'color' => '#16a34a', 'sort_order' => 110,
              'description' => 'Profile is complete enough to appear in the Creators directory.' ],

            [ 'slug' => 'creator_first_entry', 'title' => 'First Entry', 'target_role' => 'creator',
              'rule_type' => 'first_entry', 'icon_class' => 'fas fa-rocket',
              'color' => '#3b82f6', 'sort_order' => 120,
              'description' => 'Submit your first campaign entry.' ],

            [ 'slug' => 'creator_first_win', 'title' => 'First Win', 'target_role' => 'creator',
              'rule_type' => 'first_win', 'icon_class' => 'fas fa-trophy',
              'color' => '#facc15', 'sort_order' => 130,
              'description' => 'Win a competition for the first time.' ],

            [ 'slug' => 'creator_perfect_score', 'title' => 'Perfect Score', 'target_role' => 'creator',
              'rule_type' => 'perfect_score', 'icon_class' => 'fas fa-star',
              'color' => '#f59e0b', 'sort_order' => 140,
              'description' => 'Receive 100/100 on a competition entry.' ],

            [ 'slug' => 'creator_crowd_favorite', 'title' => 'Crowd Favorite', 'target_role' => 'creator',
              'rule_type' => 'crowd_favorite', 'icon_class' => 'fas fa-heart',
              'color' => '#ef4444', 'sort_order' => 150,
              'description' => 'Hold the highest vote count on a competition entry.' ],

            [ 'slug' => 'creator_hat_trick', 'title' => 'Hat Trick', 'target_role' => 'creator',
              'rule_type' => 'multi_organizer', 'extra_config' => '{"min":3}',
              'icon_class' => 'fas fa-puzzle-piece', 'color' => '#10b981', 'sort_order' => 160,
              'description' => 'Submit entries to 3 different organizers.' ],

            [ 'slug' => 'creator_genre_explorer', 'title' => 'Genre Explorer', 'target_role' => 'creator',
              'rule_type' => 'multi_category', 'extra_config' => '{"min":3}',
              'icon_class' => 'fas fa-compass', 'color' => '#8b5cf6', 'sort_order' => 170,
              'description' => 'Submit entries across 3+ different campaign categories.' ],

            [ 'slug' => 'creator_veteran', 'title' => 'Veteran', 'target_role' => 'creator',
              'rule_type' => 'tenure_days', 'extra_config' => '{"days":365}',
              'icon_class' => 'fas fa-hourglass-half', 'color' => '#555555', 'sort_order' => 180,
              'description' => 'Account active for at least 1 year.' ],

            [ 'slug' => 'creator_legend', 'title' => 'Legend', 'target_role' => 'creator',
              'rule_type' => 'tenure_days', 'extra_config' => '{"days":1095}',
              'icon_class' => 'fas fa-crown', 'color' => '#fbbf24', 'sort_order' => 190,
              'description' => 'Account active for at least 3 years.' ],

            [ 'slug' => 'creator_streak_master', 'title' => 'Streak Master', 'target_role' => 'creator',
              'rule_type' => 'consecutive_months', 'extra_config' => '{"months":6}',
              'icon_class' => 'fas fa-fire', 'color' => '#f97316', 'sort_order' => 200,
              'description' => 'Submit at least one entry every month for 6 months running.' ],

            [ 'slug' => 'creator_social_connect', 'title' => 'Social Connect', 'target_role' => 'creator',
              'rule_type' => 'social_links', 'extra_config' => '{"min":3}',
              'icon_class' => 'fas fa-link', 'color' => '#06b6d4', 'sort_order' => 210,
              'description' => 'Fill in 3 or more social profile URLs.' ],

            // ───── Organizer tiered ─────
            [ 'slug' => 'org_host', 'title' => 'Host', 'target_role' => 'business',
              'rule_type' => 'count_campaigns', 'thresholds' => '1,5,20,50',
              'icon_class' => 'fas fa-bullhorn', 'color' => '#0ea5e9', 'sort_order' => 10,
              'description' => 'Publish campaigns to grow your host tier.' ],

            [ 'slug' => 'org_community_builder', 'title' => 'Community Builder', 'target_role' => 'business',
              'rule_type' => 'participant_total', 'thresholds' => '10,100,500,2000',
              'icon_class' => 'fas fa-users', 'color' => '#22c55e', 'sort_order' => 20,
              'description' => 'Attract participants across all your campaigns.' ],

            [ 'slug' => 'org_prize_patron', 'title' => 'Prize Patron', 'target_role' => 'business',
              'rule_type' => 'prize_total', 'thresholds' => '100,1000,10000,50000',
              'icon_class' => 'fas fa-gift', 'color' => '#facc15', 'sort_order' => 30,
              'description' => 'Award prizes across your campaigns.' ],

            // ───── Organizer flat ─────
            [ 'slug' => 'org_welcome_aboard', 'title' => 'Welcome Aboard', 'target_role' => 'business',
              'rule_type' => 'profile_complete', 'icon_class' => 'fas fa-handshake',
              'color' => '#0ea5e9', 'sort_order' => 100,
              'description' => 'Complete the business basics: name, industry, about, location.' ],

            [ 'slug' => 'org_verified', 'title' => 'Verified Organizer', 'target_role' => 'business',
              'rule_type' => 'directory_complete', 'icon_class' => 'fas fa-check-circle',
              'color' => '#16a34a', 'sort_order' => 110,
              'description' => 'Profile complete enough to appear in the Organizers directory.' ],

            [ 'slug' => 'org_first_campaign', 'title' => 'First Campaign', 'target_role' => 'business',
              'rule_type' => 'first_campaign', 'icon_class' => 'fas fa-flag-checkered',
              'color' => '#3b82f6', 'sort_order' => 120,
              'description' => 'Publish your first campaign.' ],

            [ 'slug' => 'org_diverse_host', 'title' => 'Diverse Host', 'target_role' => 'business',
              'rule_type' => 'campaign_types', 'icon_class' => 'fas fa-palette',
              'color' => '#8b5cf6', 'sort_order' => 130,
              'description' => 'Host all three campaign types: Activity, Competition, and Talk/Seminar.' ],

            [ 'slug' => 'org_fast_responder', 'title' => 'Fast Responder', 'target_role' => 'business',
              'rule_type' => 'fast_judge', 'icon_class' => 'fas fa-bolt',
              'color' => '#f59e0b', 'sort_order' => 140,
              'description' => 'Score 5 consecutive entries within 48 hours of submission.' ],

            [ 'slug' => 'org_trusted_judge', 'title' => 'Trusted Judge', 'target_role' => 'business',
              'rule_type' => 'judge_quality', 'extra_config' => '{"min_entries":10,"ratio":0.8}',
              'icon_class' => 'fas fa-gavel', 'color' => '#a855f7', 'sort_order' => 150,
              'description' => 'Leave judge comments on 80%+ of scored entries (min 10).' ],

            [ 'slug' => 'org_long_standing', 'title' => 'Long Standing', 'target_role' => 'business',
              'rule_type' => 'tenure_days', 'extra_config' => '{"days":1095,"require_campaign":true}',
              'icon_class' => 'fas fa-landmark', 'color' => '#555555', 'sort_order' => 160,
              'description' => 'Active for 3+ years with at least one published campaign.' ],

            [ 'slug' => 'org_featured', 'title' => 'Featured', 'target_role' => 'business',
              'rule_type' => 'manual', 'icon_class' => 'fas fa-star',
              'color' => '#facc15', 'sort_order' => 170, 'admin_only' => true,
              'description' => 'Hand-picked by the CreativeWings team.' ],

            // ───── Universal ─────
            [ 'slug' => 'early_adopter', 'title' => 'Early Adopter', 'target_role' => 'any',
              'rule_type' => 'early_adopter', 'extra_config' => '{"cutoff":"2026-12-31"}',
              'icon_class' => 'fas fa-seedling', 'color' => '#10b981', 'sort_order' => 300,
              'description' => 'Joined CreativeWings before the early-adopter cutoff.' ],

            [ 'slug' => 'beta_tester', 'title' => 'Beta Tester', 'target_role' => 'any',
              'rule_type' => 'manual', 'icon_class' => 'fas fa-flask',
              'color' => '#6366f1', 'sort_order' => 310, 'admin_only' => true,
              'description' => 'Provided feedback during the platform beta.' ],

            // ───── Contestant / join & points (Phase 3) ─────
            [ 'slug' => 'contestant_first_join', 'title' => 'First Competition', 'target_role' => 'creator',
              'rule_type' => 'paid_joins_any', 'extra_config' => '{"min":1}',
              'icon_class' => 'fas fa-flag-checkered', 'color' => '#0ea5e9', 'sort_order' => 50,
              'description' => 'Complete your first paid campaign join (competition or activity).' ],

            [ 'slug' => 'contestant_5_competitions', 'title' => '5 Competitions Joined', 'target_role' => 'creator',
              'rule_type' => 'paid_joins_competition', 'extra_config' => '{"min":5}',
              'icon_class' => 'fas fa-layer-group', 'color' => '#3b82f6', 'sort_order' => 55,
              'description' => 'Join 5 paid competitions.' ],

            [ 'slug' => 'contestant_10_competitions', 'title' => '10 Competitions Joined', 'target_role' => 'creator',
              'rule_type' => 'paid_joins_competition', 'extra_config' => '{"min":10}',
              'icon_class' => 'fas fa-layer-group', 'color' => '#2563eb', 'sort_order' => 56,
              'description' => 'Join 10 paid competitions.' ],

            [ 'slug' => 'contestant_early_bird', 'title' => 'Early Bird', 'target_role' => 'creator',
              'rule_type' => 'early_bird_join',
              'icon_class' => 'fas fa-dove', 'color' => '#f59e0b', 'sort_order' => 60,
              'description' => 'Join a campaign within the first 7 days of its submission start.' ],

            [ 'slug' => 'contestant_top_contributor', 'title' => 'Top Contributor', 'target_role' => 'creator',
              'rule_type' => 'top_contributor',
              'icon_class' => 'fas fa-chart-line', 'color' => '#10b981', 'sort_order' => 65,
              'description' => 'Rank in the Top 10 contestants by current points balance.' ],

            [ 'slug' => 'contestant_champion', 'title' => 'Champion', 'target_role' => 'creator',
              'rule_type' => 'champion',
              'icon_class' => 'fas fa-trophy', 'color' => '#facc15', 'sort_order' => 70,
              'description' => 'Be marked as a winner by a campaign organiser.' ],

            [ 'slug' => 'contestant_event_explorer', 'title' => 'Event Explorer', 'target_role' => 'creator',
              'rule_type' => 'event_explorer',
              'icon_class' => 'fas fa-map-marked-alt', 'color' => '#8b5cf6', 'sort_order' => 75,
              'description' => 'Complete at least one paid competition and one paid activity.' ],

            [ 'slug' => 'contestant_creative_legend', 'title' => 'Creative Legend', 'target_role' => 'creator',
              'rule_type' => 'creative_legend',
              'icon_class' => 'fas fa-crown', 'color' => '#eab308', 'sort_order' => 80,
              'description' => 'Reach 50 paid joins or 10,000 lifetime points earned.' ],
        ];
    }
}
