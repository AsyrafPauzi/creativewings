<?php
/**
 * Registers the cw_badge CPT and its admin metabox.
 *
 * Each badge is an admin-managed post that captures:
 *   - target role (creator / business / any)
 *   - rule type (count_entries, count_campaigns, …, manual)
 *   - threshold ladder (comma-separated, e.g. 1,5,25,100) for tiered badges
 *   - icon attachment + colour for visual styling
 *   - admin-only flag (admin must manually award) + sort order
 *
 * The engine in CW_Badges_Engine reads these fields to decide who earns what.
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Badges_CPT {

    const POST_TYPE = 'cw_badge';

    /** Centralized list so the metabox dropdown stays in sync with the engine. */
    public static function rule_types() {
        return [
            'count_entries'       => __( 'Count of entries (participant)', 'creativewings-core' ),
            'count_certificates'  => __( 'Count of certificates earned', 'creativewings-core' ),
            'count_portfolio'     => __( 'Count of portfolio items', 'creativewings-core' ),
            'count_campaigns'     => __( 'Count of campaigns hosted', 'creativewings-core' ),
            'participant_total'   => __( 'Sum of participants across campaigns', 'creativewings-core' ),
            'prize_total'         => __( 'Sum of prize value across campaigns', 'creativewings-core' ),
            'profile_complete'    => __( 'Profile basics complete', 'creativewings-core' ),
            'directory_complete'  => __( 'Profile good enough for the public directory', 'creativewings-core' ),
            'first_entry'         => __( 'First entry submitted', 'creativewings-core' ),
            'first_campaign'      => __( 'First campaign published', 'creativewings-core' ),
            'first_win'           => __( 'First winning entry', 'creativewings-core' ),
            'perfect_score'       => __( 'Received a perfect (100) judge score', 'creativewings-core' ),
            'crowd_favorite'      => __( 'Highest vote count on an entry', 'creativewings-core' ),
            'multi_organizer'     => __( 'Entries submitted to N+ different organizers', 'creativewings-core' ),
            'multi_category'      => __( 'Entries across N+ campaign categories', 'creativewings-core' ),
            'campaign_types'      => __( 'Has hosted Activity + Competition + Talk', 'creativewings-core' ),
            'tenure_days'         => __( 'Account age in days >= threshold', 'creativewings-core' ),
            'consecutive_months'  => __( 'Entries in N consecutive months', 'creativewings-core' ),
            'social_links'        => __( 'Number of social URLs filled', 'creativewings-core' ),
            'fast_judge'          => __( 'Scored 5 entries within 48h of submission (organizer)', 'creativewings-core' ),
            'judge_quality'       => __( 'Judge comment on >=80% of entries (organizer)', 'creativewings-core' ),
            'early_adopter'      => __( 'Account registered before cutoff date', 'creativewings-core' ),
            'manual'              => __( 'Manual award only (no auto-evaluation)', 'creativewings-core' ),
        ];
    }

    public static function target_roles() {
        return [
            'creator'  => __( 'Creator', 'creativewings-core' ),
            'business' => __( 'Business / Organizer', 'creativewings-core' ),
            'any'      => __( 'Any user', 'creativewings-core' ),
        ];
    }

    public function __construct() {
        add_action( 'init',              [ $this, 'register_cpt' ] );
        add_action( 'add_meta_boxes',    [ $this, 'add_meta_box' ] );
        add_action( 'save_post_' . self::POST_TYPE, [ $this, 'save_meta' ], 10, 2 );
        add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', [ $this, 'admin_columns' ] );
        add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', [ $this, 'render_admin_column' ], 10, 2 );
    }

    public function register_cpt() {
        register_post_type( self::POST_TYPE, [
            'labels' => [
                'name'               => __( 'Badges', 'creativewings-core' ),
                'singular_name'      => __( 'Badge', 'creativewings-core' ),
                'menu_name'          => __( 'Badges', 'creativewings-core' ),
                'add_new_item'       => __( 'Add Badge', 'creativewings-core' ),
                'edit_item'          => __( 'Edit Badge', 'creativewings-core' ),
                'all_items'          => __( 'All Badges', 'creativewings-core' ),
                'search_items'       => __( 'Search Badges', 'creativewings-core' ),
                'not_found'          => __( 'No badges found', 'creativewings-core' ),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-awards',
            'menu_position' => 26,
            'supports'      => [ 'title', 'editor' ],
            'rewrite'       => false,
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ] );
    }

    public function add_meta_box() {
        add_meta_box(
            'cw_badge_settings',
            __( 'Badge settings', 'creativewings-core' ),
            [ $this, 'render_meta_box' ],
            self::POST_TYPE,
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        $slug         = (string) get_post_meta( $post->ID, 'cw_badge_slug', true );
        if ( $slug === '' ) {
            // Use sanitize_title( $post->post_title ) as a default suggestion.
            $slug = sanitize_title( $post->post_title );
        }
        $target_role  = (string) get_post_meta( $post->ID, 'cw_badge_target_role', true ) ?: 'creator';
        $rule_type    = (string) get_post_meta( $post->ID, 'cw_badge_rule_type', true ) ?: 'manual';
        $thresholds   = (string) get_post_meta( $post->ID, 'cw_badge_thresholds', true );
        $icon_id      = (int)    get_post_meta( $post->ID, 'cw_badge_icon', true );
        $icon_class   = (string) get_post_meta( $post->ID, 'cw_badge_icon_class', true );
        $color        = (string) get_post_meta( $post->ID, 'cw_badge_color', true ) ?: '#0ea5e9';
        $admin_only   = (string) get_post_meta( $post->ID, 'cw_badge_is_admin_only', true ) === '1';
        $sort_order   = (int)    get_post_meta( $post->ID, 'cw_badge_sort_order', true );
        $extra_config = (string) get_post_meta( $post->ID, 'cw_badge_extra_config', true );

        wp_nonce_field( 'cw_badge_save', '_cw_badge_nonce' );
        ?>
        <style>
            .cw-badge-form-row { display:flex; flex-wrap:wrap; gap:18px; align-items:flex-start; margin-bottom:14px; }
            .cw-badge-form-row label { flex:0 0 220px; font-weight:600; }
            .cw-badge-form-row .desc { flex:1 1 100%; font-size:12px; color:#555555; margin:6px 0 0 220px; }
            .cw-badge-form-row input[type=text], .cw-badge-form-row input[type=number], .cw-badge-form-row select { min-width:280px; }
        </style>
        <div class="cw-badge-form">
            <div class="cw-badge-form-row">
                <label for="cw_badge_slug"><?php esc_html_e( 'Slug', 'creativewings-core' ); ?></label>
                <input type="text" name="cw_badge_slug" id="cw_badge_slug" value="<?php echo esc_attr( $slug ); ?>">
                <p class="desc"><?php esc_html_e( 'Stable identifier used by the engine. Lowercase, underscores. Examples: creator_participant, org_host.', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_target_role"><?php esc_html_e( 'Target role', 'creativewings-core' ); ?></label>
                <select name="cw_badge_target_role" id="cw_badge_target_role">
                    <?php foreach ( self::target_roles() as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $target_role, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_rule_type"><?php esc_html_e( 'Rule type', 'creativewings-core' ); ?></label>
                <select name="cw_badge_rule_type" id="cw_badge_rule_type">
                    <?php foreach ( self::rule_types() as $val => $label ) : ?>
                        <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $rule_type, $val ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
                <p class="desc"><?php esc_html_e( 'How the engine decides whether the user has earned this badge.', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_thresholds"><?php esc_html_e( 'Tier thresholds', 'creativewings-core' ); ?></label>
                <input type="text" name="cw_badge_thresholds" id="cw_badge_thresholds" value="<?php echo esc_attr( $thresholds ); ?>" placeholder="1,5,25,100">
                <p class="desc"><?php esc_html_e( 'Comma-separated, ascending: Bronze, Silver, Gold, Platinum. Leave empty for flat (single-tier) badges.', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_icon"><?php esc_html_e( 'Icon (attachment ID)', 'creativewings-core' ); ?></label>
                <input type="number" name="cw_badge_icon" id="cw_badge_icon" value="<?php echo esc_attr( $icon_id ?: '' ); ?>" min="0">
                <?php if ( $icon_id && wp_attachment_is_image( $icon_id ) ) : ?>
                    <span><img src="<?php echo esc_url( (string) wp_get_attachment_image_url( $icon_id, 'thumbnail' ) ); ?>" style="max-height:48px;border-radius:6px;border:1px solid #e5e7eb;"></span>
                <?php endif; ?>
                <p class="desc"><?php esc_html_e( 'Optional. Numeric attachment ID of an uploaded PNG/SVG icon (upload via Media Library first).', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_icon_class"><?php esc_html_e( 'Fallback icon class', 'creativewings-core' ); ?></label>
                <input type="text" name="cw_badge_icon_class" id="cw_badge_icon_class" value="<?php echo esc_attr( $icon_class ); ?>" placeholder="fas fa-trophy">
                <p class="desc"><?php esc_html_e( 'FontAwesome class used when no icon attachment is set.', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_color"><?php esc_html_e( 'Badge colour', 'creativewings-core' ); ?></label>
                <input type="text" name="cw_badge_color" id="cw_badge_color" value="<?php echo esc_attr( $color ); ?>" placeholder="#0ea5e9">
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_sort_order"><?php esc_html_e( 'Sort order', 'creativewings-core' ); ?></label>
                <input type="number" name="cw_badge_sort_order" id="cw_badge_sort_order" value="<?php echo esc_attr( $sort_order ?: 0 ); ?>">
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_extra_config"><?php esc_html_e( 'Extra config (JSON)', 'creativewings-core' ); ?></label>
                <input type="text" name="cw_badge_extra_config" id="cw_badge_extra_config" value="<?php echo esc_attr( $extra_config ); ?>" placeholder='{"cutoff":"2026-01-01"}' style="min-width:380px;">
                <p class="desc"><?php esc_html_e( 'Optional JSON for rule-specific config (e.g. {"cutoff":"2026-01-01"} for early_adopter, {"months":6} for consecutive_months, {"min_entries":10,"ratio":0.8} for judge_quality).', 'creativewings-core' ); ?></p>
            </div>

            <div class="cw-badge-form-row">
                <label for="cw_badge_is_admin_only"><?php esc_html_e( 'Admin award only', 'creativewings-core' ); ?></label>
                <input type="checkbox" name="cw_badge_is_admin_only" id="cw_badge_is_admin_only" value="1" <?php checked( $admin_only ); ?>>
                <p class="desc"><?php esc_html_e( 'When ticked the engine will not auto-award this badge; only the admin manual-award form can grant it.', 'creativewings-core' ); ?></p>
            </div>
        </div>
        <?php
    }

    public function save_meta( $post_id, $post ) {
        if ( ! isset( $_POST['_cw_badge_nonce'] ) || ! wp_verify_nonce( $_POST['_cw_badge_nonce'], 'cw_badge_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        $slug = isset( $_POST['cw_badge_slug'] ) ? sanitize_title( wp_unslash( $_POST['cw_badge_slug'] ) ) : '';
        if ( $slug === '' ) {
            $slug = sanitize_title( $post->post_title );
        }
        update_post_meta( $post_id, 'cw_badge_slug', $slug );

        $target_role = isset( $_POST['cw_badge_target_role'] ) ? sanitize_key( $_POST['cw_badge_target_role'] ) : 'creator';
        if ( ! array_key_exists( $target_role, self::target_roles() ) ) $target_role = 'creator';
        update_post_meta( $post_id, 'cw_badge_target_role', $target_role );

        $rule_type = isset( $_POST['cw_badge_rule_type'] ) ? sanitize_key( $_POST['cw_badge_rule_type'] ) : 'manual';
        if ( ! array_key_exists( $rule_type, self::rule_types() ) ) $rule_type = 'manual';
        update_post_meta( $post_id, 'cw_badge_rule_type', $rule_type );

        $thresholds_raw = isset( $_POST['cw_badge_thresholds'] ) ? wp_unslash( $_POST['cw_badge_thresholds'] ) : '';
        $thresholds = array_filter( array_map( 'trim', explode( ',', $thresholds_raw ) ), 'strlen' );
        $thresholds = array_values( array_map( 'floatval', $thresholds ) );
        update_post_meta( $post_id, 'cw_badge_thresholds', implode( ',', $thresholds ) );

        update_post_meta( $post_id, 'cw_badge_icon', (int) ( $_POST['cw_badge_icon'] ?? 0 ) );
        update_post_meta( $post_id, 'cw_badge_icon_class', sanitize_text_field( wp_unslash( $_POST['cw_badge_icon_class'] ?? '' ) ) );
        $color = sanitize_text_field( wp_unslash( $_POST['cw_badge_color'] ?? '' ) );
        if ( $color && ! preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) {
            $color = '#0ea5e9';
        }
        update_post_meta( $post_id, 'cw_badge_color', $color ?: '#0ea5e9' );
        update_post_meta( $post_id, 'cw_badge_sort_order', (int) ( $_POST['cw_badge_sort_order'] ?? 0 ) );

        $extra_config = sanitize_text_field( wp_unslash( $_POST['cw_badge_extra_config'] ?? '' ) );
        update_post_meta( $post_id, 'cw_badge_extra_config', $extra_config );

        $admin_only = isset( $_POST['cw_badge_is_admin_only'] ) ? '1' : '0';
        update_post_meta( $post_id, 'cw_badge_is_admin_only', $admin_only );
    }

    public function admin_columns( $columns ) {
        $new = [];
        foreach ( $columns as $k => $v ) {
            $new[ $k ] = $v;
            if ( $k === 'title' ) {
                $new['cw_role']  = __( 'Role', 'creativewings-core' );
                $new['cw_rule']  = __( 'Rule', 'creativewings-core' );
                $new['cw_tiers'] = __( 'Tiers', 'creativewings-core' );
                $new['cw_owned'] = __( 'Earners', 'creativewings-core' );
            }
        }
        return $new;
    }

    public function render_admin_column( $col, $post_id ) {
        switch ( $col ) {
            case 'cw_role':
                $role = (string) get_post_meta( $post_id, 'cw_badge_target_role', true ) ?: 'creator';
                $map  = self::target_roles();
                echo esc_html( $map[ $role ] ?? $role );
                break;
            case 'cw_rule':
                $rule = (string) get_post_meta( $post_id, 'cw_badge_rule_type', true ) ?: 'manual';
                $map  = self::rule_types();
                echo esc_html( $map[ $rule ] ?? $rule );
                break;
            case 'cw_tiers':
                $t = (string) get_post_meta( $post_id, 'cw_badge_thresholds', true );
                echo $t === '' ? '<em>flat</em>' : esc_html( $t );
                break;
            case 'cw_owned':
                global $wpdb;
                $table = $wpdb->prefix . 'cw_user_badges';
                $count = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(DISTINCT user_id) FROM $table WHERE badge_id = %d",
                    $post_id
                ) );
                echo (int) $count;
                break;
        }
    }
}
