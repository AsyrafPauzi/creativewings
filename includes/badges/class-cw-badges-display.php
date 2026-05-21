<?php
/**
 * Render helpers shared by dashboards, public profiles, and directory cards.
 *
 *   CW_Badges_Display::render_badge( $badge_row, $opts )
 *   CW_Badges_Display::render_grid( $badge_rows )
 *   CW_Badges_Display::render_strip( $badge_rows, $max )
 *   CW_Badges_Display::render_progress_grid( $user_id )  -- shows owned + locked w/ progress
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Badges_Display {

    public static function tier_label( $tier ) {
        switch ( $tier ) {
            case CW_Badges_Engine::TIER_BRONZE:   return __( 'Bronze',   'creativewings-core' );
            case CW_Badges_Engine::TIER_SILVER:   return __( 'Silver',   'creativewings-core' );
            case CW_Badges_Engine::TIER_GOLD:     return __( 'Gold',     'creativewings-core' );
            case CW_Badges_Engine::TIER_PLATINUM: return __( 'Platinum', 'creativewings-core' );
            default:                              return __( 'Earned',   'creativewings-core' );
        }
    }

    public static function tier_color( $tier ) {
        switch ( $tier ) {
            case CW_Badges_Engine::TIER_BRONZE:   return '#cd7f32';
            case CW_Badges_Engine::TIER_SILVER:   return '#c0c0c0';
            case CW_Badges_Engine::TIER_GOLD:     return '#facc15';
            case CW_Badges_Engine::TIER_PLATINUM: return '#9aa1ad';
            default:                              return '';
        }
    }

    /**
     * Render one badge medallion.
     *
     * @param array $badge   Hydrated row from CW_Badges_Engine::get_user_badges().
     * @param array $opts    'size' => 'sm'|'md'|'lg', 'show_label' => bool
     * @return string
     */
    public static function render_badge( array $badge, array $opts = [] ) {
        $opts = wp_parse_args( $opts, [
            'size'       => 'md',
            'show_label' => true,
            'show_tier'  => true,
        ] );

        $color      = ! empty( $badge['color'] ) ? $badge['color'] : '#0ea5e9';
        $tier_color = self::tier_color( $badge['tier'] ?? '' );
        $title      = (string) ( $badge['title'] ?? 'Badge' );
        $tier       = (string) ( $badge['tier'] ?? '' );
        $tier_lbl   = self::tier_label( $tier );
        $icon_url   = (string) ( $badge['icon_url'] ?? '' );
        $icon_cls   = (string) ( $badge['icon_class'] ?? 'fas fa-medal' );

        $size_cls = 'cw-badge--' . preg_replace( '/[^a-z]/', '', $opts['size'] );
        $extra_cls = $opts['show_label'] ? '' : ' cw-badge--icon-only';

        ob_start();
        ?>
        <div class="cw-badge <?php echo esc_attr( $size_cls . $extra_cls ); ?>"
             data-tier="<?php echo esc_attr( $tier ); ?>"
             title="<?php echo esc_attr( $title . ( $tier_lbl && $opts['show_tier'] ? ' — ' . $tier_lbl : '' ) ); ?>">
            <span class="cw-badge-medal" style="--cw-badge-color: <?php echo esc_attr( $color ); ?>; --cw-badge-tier-color: <?php echo esc_attr( $tier_color ?: $color ); ?>;">
                <?php if ( $icon_url ) : ?>
                    <img src="<?php echo esc_url( $icon_url ); ?>" alt="" loading="lazy" decoding="async">
                <?php else : ?>
                    <i class="<?php echo esc_attr( $icon_cls ); ?>" aria-hidden="true"></i>
                <?php endif; ?>
                <?php if ( $tier && $tier !== CW_Badges_Engine::TIER_FLAT ) : ?>
                    <span class="cw-badge-tier-ring" aria-hidden="true"></span>
                <?php endif; ?>
            </span>
            <?php if ( $opts['show_label'] ) : ?>
                <div class="cw-badge-meta">
                    <span class="cw-badge-name"><?php echo esc_html( $title ); ?></span>
                    <?php if ( $opts['show_tier'] ) : ?>
                        <span class="cw-badge-tier cw-badge-tier--<?php echo esc_attr( $tier ); ?>"><?php echo esc_html( $tier_lbl ); ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Render a horizontal strip — used in dashboards & directory cards.
     */
    public static function render_strip( array $rows, $max = 3, $opts = [] ) {
        $rows = array_slice( $rows, 0, max( 1, (int) $max ) );
        if ( empty( $rows ) ) return '';
        $opts = wp_parse_args( $opts, [
            'size'       => 'sm',
            'show_label' => false,
            'show_tier'  => false,
        ] );
        $html = '<div class="cw-badges cw-badges-strip">';
        foreach ( $rows as $r ) {
            $html .= self::render_badge( $r, $opts );
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render a full grid of EARNED badges (no progress info).
     */
    public static function render_grid( array $rows ) {
        if ( empty( $rows ) ) {
            return '<div class="cw-badges cw-badges-empty"><i class="fas fa-medal"></i><p>' . esc_html__( 'No badges earned yet — keep going!', 'creativewings-core' ) . '</p></div>';
        }
        $html = '<div class="cw-badges cw-badges-grid">';
        foreach ( $rows as $r ) {
            $html .= self::render_badge( $r );
        }
        $html .= '</div>';
        return $html;
    }

    /**
     * Render the full progress grid — owned badges + locked badges with progress
     * bars. Used inside the dashboard "My Badges" tab.
     */
    public static function render_progress_grid( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) return '';
        $user = get_user_by( 'id', $user_id );
        if ( ! $user ) return '';

        $role     = CW_Badges_Engine::user_target_role( $user );
        $catalog  = CW_Badges_Engine::get_catalog();
        $owned    = CW_Badges_Engine::get_user_badges( $user_id, true );
        // owned: list of raw rows. Build a lookup keyed by badge_id => highest tier owned.
        $owned_by = [];
        $ladder   = CW_Badges_Engine::tier_ladder();
        foreach ( $owned as $row ) {
            $bid = (int) $row['badge_id'];
            $w   = array_search( $row['tier'], $ladder, true );
            if ( $w === false ) $w = -1; // flat
            if ( ! isset( $owned_by[ $bid ] ) || $owned_by[ $bid ]['weight'] < $w ) {
                $owned_by[ $bid ] = [ 'row' => $row, 'weight' => $w ];
            }
        }

        ob_start();
        echo '<div class="cw-badges cw-badges-progress">';
        foreach ( $catalog as $b ) {
            // Filter by role.
            if ( $b['target_role'] !== 'any' && $b['target_role'] !== $role ) continue;

            $is_tiered = ! empty( $b['thresholds'] );
            $owned_row = $owned_by[ (int) $b['id'] ] ?? null;
            $tier_now  = '';
            $tier_w    = -2;
            $earned_at = '';
            if ( $owned_row ) {
                $tier_now  = $owned_row['row']['tier'];
                $tier_w    = $owned_row['weight'];
                $earned_at = $owned_row['row']['earned_at'];
            }

            // Compute current progress + next threshold (only meaningful for numeric rules).
            $current   = self::current_value_for_progress( $b, $user );
            $next_at   = '';
            $next_tier = '';
            if ( $is_tiered && is_numeric( $current ) ) {
                $next_index = $tier_w + 1;
                if ( $next_index >= 0 && isset( $b['thresholds'][ $next_index ] ) ) {
                    $next_at   = (float) $b['thresholds'][ $next_index ];
                    $next_tier = $ladder[ $next_index ] ?? '';
                }
            }

            $hydrated = array_merge( $b, [
                'tier'       => $tier_now,
                'earned_at'  => $earned_at,
            ] );

            $is_locked = ( $tier_w < 0 && ! $owned_row );

            ?>
            <div class="cw-badge-card <?php echo $is_locked ? 'cw-badge-card--locked' : ''; ?>">
                <?php echo self::render_badge( $hydrated, [ 'size' => 'lg', 'show_label' => false, 'show_tier' => false ] ); ?>
                <div class="cw-badge-card-body">
                    <h4 class="cw-badge-card-title"><?php echo esc_html( $b['title'] ); ?></h4>
                    <?php if ( $b['description'] ) : ?>
                        <p class="cw-badge-card-desc"><?php echo esc_html( wp_strip_all_tags( $b['description'] ) ); ?></p>
                    <?php endif; ?>

                    <?php if ( $owned_row ) : ?>
                        <div class="cw-badge-card-status">
                            <span class="cw-badge-tier cw-badge-tier--<?php echo esc_attr( $tier_now ); ?>">
                                <?php echo esc_html( self::tier_label( $tier_now ) ); ?>
                            </span>
                            <?php if ( $earned_at ) : ?>
                                <span class="cw-badge-earned">
                                    <?php echo esc_html( sprintf( __( 'earned %s', 'creativewings-core' ), date_i18n( get_option( 'date_format' ), strtotime( $earned_at ) ) ) ); ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    <?php elseif ( $b['is_admin_only'] ) : ?>
                        <div class="cw-badge-card-status">
                            <span class="cw-badge-locked"><i class="fas fa-lock"></i> <?php esc_html_e( 'Admin-awarded only', 'creativewings-core' ); ?></span>
                        </div>
                    <?php else : ?>
                        <div class="cw-badge-card-status">
                            <span class="cw-badge-locked"><i class="fas fa-lock"></i> <?php esc_html_e( 'Locked', 'creativewings-core' ); ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ( $is_tiered && $next_at && is_numeric( $current ) ) :
                        $pct = $next_at > 0 ? min( 100, max( 0, ( (float) $current / (float) $next_at ) * 100 ) ) : 0;
                    ?>
                        <div class="cw-badge-progress" aria-label="<?php esc_attr_e( 'Progress to next tier', 'creativewings-core' ); ?>">
                            <div class="cw-badge-progress-bar" style="width: <?php echo esc_attr( number_format( $pct, 1, '.', '' ) ); ?>%;"></div>
                        </div>
                        <p class="cw-badge-progress-label">
                            <?php echo esc_html( sprintf(
                                /* translators: 1: current, 2: target, 3: next tier label */
                                __( '%1$s / %2$s toward %3$s', 'creativewings-core' ),
                                number_format_i18n( (float) $current ),
                                number_format_i18n( $next_at ),
                                self::tier_label( $next_tier )
                            ) ); ?>
                        </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php
        }
        echo '</div>';
        return (string) ob_get_clean();
    }

    /**
     * Per-rule "current value" helper for progress bars. Returns null when the
     * rule isn't numeric (flat badges don't show a progress bar).
     */
    private static function current_value_for_progress( $badge, WP_User $user ) {
        switch ( $badge['rule_type'] ) {
            case 'count_entries':
                return CW_Badges_Engine::rule_count_entries( $user, $badge, [] );
            case 'count_certificates':
                return CW_Badges_Engine::rule_count_certificates( $user, $badge, [] );
            case 'count_portfolio':
                return CW_Badges_Engine::rule_count_portfolio( $user, $badge, [] );
            case 'count_campaigns':
                return CW_Badges_Engine::rule_count_campaigns( $user, $badge, [] );
            case 'participant_total':
                return CW_Badges_Engine::rule_participant_total( $user, $badge, [] );
            case 'prize_total':
                return CW_Badges_Engine::rule_prize_total( $user, $badge, [] );
            case 'tenure_days':
                $reg = strtotime( $user->user_registered ?: '' );
                return $reg ? (int) floor( ( time() - $reg ) / DAY_IN_SECONDS ) : 0;
            case 'social_links':
                $keys = [ 'Facebook_url', 'instagram_url', 'linkeden_url', 'twitter_url', 'behave_url', 'youtube_url', 'tiktok_url' ];
                $n = 0;
                foreach ( $keys as $k ) {
                    if ( trim( (string) get_user_meta( $user->ID, $k, true ) ) !== '' ) $n++;
                }
                return $n;
        }
        return null;
    }

    /**
     * Render any pending toasts for the current user. Inlines small JS that uses
     * SweetAlert2 when available, falls back to a CSS slide-in.
     */
    public static function maybe_render_toast() {
        if ( ! is_user_logged_in() ) return;
        $uid = get_current_user_id();
        $queue = (array) get_transient( 'cw_badge_toast_' . $uid );
        if ( empty( $queue ) ) return;
        delete_transient( 'cw_badge_toast_' . $uid );

        $catalog = CW_Badges_Engine::get_catalog_by_id();
        $items   = [];
        foreach ( $queue as $entry ) {
            $bid = (int) ( $entry['badge_id'] ?? 0 );
            if ( ! isset( $catalog[ $bid ] ) ) continue;
            $b = $catalog[ $bid ];
            $items[] = [
                'title' => $b['title'],
                'tier'  => self::tier_label( $entry['tier'] ?? '' ),
                'color' => $b['color'],
                'icon'  => $b['icon_class'],
                'image' => $b['icon_url'],
            ];
        }
        if ( empty( $items ) ) return;
        ?>
        <div class="cw-badge-toast-stack" id="cw-badge-toast-stack">
            <?php foreach ( $items as $i => $item ) : ?>
                <div class="cw-badge-toast" style="--cw-badge-color: <?php echo esc_attr( $item['color'] ); ?>;" data-delay="<?php echo (int) ( $i * 250 ); ?>">
                    <span class="cw-badge-toast-icon" aria-hidden="true">
                        <?php if ( ! empty( $item['image'] ) ) : ?>
                            <img src="<?php echo esc_url( $item['image'] ); ?>" alt="">
                        <?php else : ?>
                            <i class="<?php echo esc_attr( $item['icon'] ?: 'fas fa-medal' ); ?>"></i>
                        <?php endif; ?>
                    </span>
                    <div class="cw-badge-toast-body">
                        <strong><?php esc_html_e( 'Badge unlocked', 'creativewings-core' ); ?></strong>
                        <div class="cw-badge-toast-title"><?php echo esc_html( $item['title'] ); ?> · <span class="cw-badge-toast-tier"><?php echo esc_html( $item['tier'] ); ?></span></div>
                    </div>
                    <button class="cw-badge-toast-dismiss" type="button" aria-label="<?php esc_attr_e( 'Dismiss', 'creativewings-core' ); ?>">&times;</button>
                </div>
            <?php endforeach; ?>
        </div>
        <script>
        (function(){
            var stack = document.getElementById('cw-badge-toast-stack');
            if (!stack) return;
            Array.prototype.forEach.call(stack.querySelectorAll('.cw-badge-toast'), function (el) {
                var delay = parseInt(el.dataset.delay || '0', 10);
                setTimeout(function(){ el.classList.add('cw-badge-toast--in'); }, delay);
                setTimeout(function(){ el.classList.remove('cw-badge-toast--in'); }, delay + 6500);
            });
            stack.addEventListener('click', function (e) {
                if (e.target && e.target.classList && e.target.classList.contains('cw-badge-toast-dismiss')) {
                    var t = e.target.closest('.cw-badge-toast');
                    if (t) t.classList.remove('cw-badge-toast--in');
                }
            });
        })();
        </script>
        <?php
    }
}
