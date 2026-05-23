<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Public Organizer Profile (B2B-style company page).
 *
 * - Shortcode: [cw_organizer_profile slug="acme"]
 *      • slug attr → ?org=… query arg → current logged-in business user (in that order).
 * - Pretty URL: /organizer/{user_login}/ (via add_rewrite_rule).
 *
 * Distinct from the creator portfolio profile at /profile/{login}/ rendered by
 * CW_Users::render_public_profile_html(). This one surfaces every piece of
 * business-info meta the organizer entered plus every campaign they have run.
 *
 * Read-only — never writes any of the business_* meta keys.
 */
class CW_Organizer_Profile {

    const ORG_BASE   = 'organizer';
    const QUERY_VAR  = 'cw_organizer';
    const REWRITE_OPT = 'cw_organizer_rewrite_v1';

    /**
     * Per-request cache for participants count per product_id (avoids N queries
     * when the same campaign appears in two contexts).
     *
     * @var array<int,int>
     */
    private static $participants_cache = [];

    public function __construct() {
        add_action( 'init',            [ $this, 'register_rewrite' ] );
        add_filter( 'query_vars',      [ $this, 'add_query_var' ] );
        add_filter( 'template_include', [ $this, 'maybe_render_organizer_template' ], 99 );
        add_shortcode( 'cw_organizer_profile', [ $this, 'shortcode_handler' ] );
    }

    /* ──────────────────────────────────────────────────────────────────
     * Rewrite / query-var plumbing
     * ────────────────────────────────────────────────────────────────── */

    public function register_rewrite() {
        add_rewrite_rule(
            '^' . self::ORG_BASE . '/([^/]+)/?$',
            'index.php?' . self::QUERY_VAR . '=$matches[1]',
            'top'
        );

        // One-time flush bridge: pipes into the existing CW_Activator
        // maybe_flush_rewrite_rules() handler so we don't flush on every load.
        if ( get_option( self::REWRITE_OPT ) !== '1' ) {
            update_option( 'cw_needs_rewrite_flush', '1' );
            update_option( self::REWRITE_OPT, '1' );
        }
    }

    public function add_query_var( $vars ) {
        $vars[] = self::QUERY_VAR;
        return $vars;
    }

    /**
     * Catch /organizer/{slug}/ and render the organizer page inline.
     */
    public function maybe_render_organizer_template( $template ) {
        $slug = get_query_var( self::QUERY_VAR );
        if ( empty( $slug ) && isset( $_SERVER['REQUEST_URI'] )
             && preg_match( '~^/?' . self::ORG_BASE . '/([^/?#]+)/?~', (string) $_SERVER['REQUEST_URI'], $m ) ) {
            $slug = sanitize_title( $m[1] );
        }

        if ( ! $slug ) {
            return $template;
        }

        $user = get_user_by( 'login', $slug );
        if ( ! $user ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return get_404_template();
        }

        if ( class_exists( 'CW_Roles' ) && ! CW_Roles::has_public_organizer_page( $user ) ) {
            global $wp_query;
            $wp_query->set_404();
            status_header( 404 );
            return get_404_template();
        }

        get_header();
        echo $this->render_html( $user ); // already-escaped buffer
        get_footer();
        exit;
    }

    /* ──────────────────────────────────────────────────────────────────
     * Shortcode handler
     * ────────────────────────────────────────────────────────────────── */

    public function shortcode_handler( $atts ) {
        $atts = shortcode_atts( [
            'slug' => '',
        ], $atts, 'cw_organizer_profile' );

        $user = null;
        $slug = trim( (string) $atts['slug'] );

        if ( $slug === '' && isset( $_GET['org'] ) ) {
            $slug = sanitize_title( wp_unslash( $_GET['org'] ) );
        }

        if ( $slug !== '' ) {
            $user = get_user_by( 'login', $slug );
            if ( $user && class_exists( 'CW_Roles' ) && ! CW_Roles::has_public_organizer_page( $user ) ) {
                $user = null;
            }
        }

        if ( ! $user && is_user_logged_in() ) {
            $current = wp_get_current_user();
            if ( $current && class_exists( 'CW_Roles' ) && CW_Roles::is_business_user( $current ) ) {
                $user = $current;
            }
        }

        if ( ! $user ) {
            return '<div class="cw-org-page cw-org-empty"><p>'
                 . esc_html__( 'No organizer found.', 'creativewings-core' )
                 . '</p></div>';
        }

        return $this->render_html( $user );
    }

    /* ──────────────────────────────────────────────────────────────────
     * Render
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Render the organizer profile HTML for the given user.
     * Returns escaped HTML; never echoes directly.
     */
    public function render_html( WP_User $user ) {
        // Cache the full rendered HTML per organizer + current language. Busted
        // automatically by CW_Cache hooks when the org's products, profile, or
        // entries change.
        if ( class_exists( 'CW_Cache' ) ) {
            $locale  = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
            $key     = (int) $user->ID . ':' . md5( (string) $locale );
            return (string) CW_Cache::remember(
                $key,
                'org_profile',
                10 * MINUTE_IN_SECONDS,
                function () use ( $user ) {
                    return $this->render_html_uncached( $user );
                }
            );
        }
        return $this->render_html_uncached( $user );
    }

    /**
     * The actual heavy renderer (uncached). Cached wrapper above calls this.
     */
    private function render_html_uncached( WP_User $user ) {
        $uid = (int) $user->ID;

        // ── Bulk-load all user meta in a single query ───────────────
        // (replaces 20+ separate get_user_meta() calls).
        $all_meta = (array) get_user_meta( $uid );
        $meta = static function ( $k ) use ( $all_meta ) {
            return isset( $all_meta[ $k ][0] ) ? (string) $all_meta[ $k ][0] : '';
        };
        $meta_raw = static function ( $k ) use ( $all_meta ) {
            if ( ! isset( $all_meta[ $k ][0] ) ) {
                return null;
            }
            $val = $all_meta[ $k ][0];
            // get_user_meta() returns serialized arrays as strings when fetched with $key=''. Unserialize.
            return maybe_unserialize( $val );
        };

        // ── Identity / branding ─────────────────────────────────────
        $biz_name    = $meta( 'business_name' );
        $tagline     = $meta( 'business_tagline' );
        $about       = $meta( 'business_about' );
        $founded     = $meta( 'business_founded_year' );
        $industry    = $meta( 'business_industry' );
        $team_size   = $meta( 'business_team_size' );
        $city        = $meta( 'business_city' );
        $country     = $meta( 'business_country' );
        $address     = $meta( 'business_address' );
        $ssm         = $meta( 'business_ssm' );

        $logo_meta   = $meta_raw( 'business_logo' );
        $logo_url    = ( is_array( $logo_meta )  && ! empty( $logo_meta['url'] ) )  ? (string) $logo_meta['url']  : '';

        $cover_meta  = $meta_raw( 'business_cover' );
        $cover_url   = ( is_array( $cover_meta ) && ! empty( $cover_meta['url'] ) ) ? (string) $cover_meta['url'] : '';

        // ── Contact (with visibility toggles) ───────────────────────
        $phone       = $meta( 'business_phone' );
        $website     = $meta( 'business_website' );
        // Default visible unless explicitly stored as '0'.
        $phone_visible = ( $meta( 'cw_show_org_phone' ) !== '0' );
        $email_visible = ( $meta( 'cw_show_org_email' ) !== '0' );
        $email         = $email_visible ? (string) $user->user_email : '';
        $phone_display = $phone_visible ? $phone : '';

        // ── Display name fallbacks ──────────────────────────────────
        $display_name = $biz_name !== '' ? $biz_name : ( $user->display_name ?: $user->user_login );

        // ── Location (best-of-three) ────────────────────────────────
        $location_parts = array_filter( [ trim( $city ), trim( $country ) ] );
        $location       = $location_parts ? implode( ', ', $location_parts ) : trim( $address );

        // ── Socials ─────────────────────────────────────────────────
        $social_map = [
            'Facebook_url'  => [ 'icon' => 'fab fa-facebook-f',  'label' => 'Facebook',  'net' => 'facebook'  ],
            'instagram_url' => [ 'icon' => 'fab fa-instagram',   'label' => 'Instagram', 'net' => 'instagram' ],
            'linkeden_url'  => [ 'icon' => 'fab fa-linkedin-in', 'label' => 'LinkedIn',  'net' => 'linkedin'  ],
            'twitter_url'   => [ 'icon' => 'fab fa-twitter',     'label' => 'Twitter',   'net' => 'twitter'   ],
            'behave_url'    => [ 'icon' => 'fab fa-behance',     'label' => 'Behance',   'net' => 'behance'   ],
            'youtube_url'   => [ 'icon' => 'fab fa-youtube',     'label' => 'YouTube',   'net' => 'youtube'   ],
            'tiktok_url'    => [ 'icon' => 'fab fa-tiktok',      'label' => 'TikTok',    'net' => 'tiktok'    ],
        ];
        $socials_present = [];
        foreach ( $social_map as $key => $info ) {
            $url = trim( $meta( $key ) );
            if ( $url !== '' ) {
                $socials_present[ $key ] = [ 'url' => $url ] + $info;
            }
        }

        // ── Campaigns ───────────────────────────────────────────────
        $campaigns = $this->get_published_campaigns( $uid );
        $campaign_count = count( $campaigns );

        $campaign_pids = array_map( static fn( $c ) => (int) $c->ID, $campaigns );

        // Prime caches once so the per-campaign render loop hits memory, not the DB.
        if ( ! empty( $campaign_pids ) ) {
            update_meta_cache( 'post', $campaign_pids );
            update_object_term_cache( $campaign_pids, 'product' );
        }

        // Per-campaign participant counts via a single GROUP BY (replaces N+1 inside the loop).
        $participants_map   = $this->get_participants_map( $campaign_pids );
        $total_participants = (int) array_sum( $participants_map );

        // Total prizes actually awarded to winners.
        //
        // We intentionally do NOT use the campaign's `cw_total_prize_value`
        // meta (free-text "prize pool"): that field accepts any number an
        // organizer types in, so a test/typo value like "100,000,000" would
        // inflate this KPI for everyone visiting the public profile (see the
        // RM100,002,026.00 bug). The declared pool stays available in the
        // editor for organizer reference but is no longer surfaced publicly.
        //
        // Logic: for each campaign, build a rank-token lookup over the prizes
        // repeater (`prize_title` → first matched description), then query
        // winners (winner_status='yes') and accumulate the currency amount
        // parsed from each matched prize description.
        $total_prize_value  = $this->compute_awarded_prize_total( $campaign_pids );
        $awarded_winners    = $this->count_awarded_winners( $campaign_pids );
        $total_prize_display = '';
        if ( $total_prize_value > 0 ) {
            if ( function_exists( 'wc_price' ) ) {
                $total_prize_display = wp_strip_all_tags( wc_price( $total_prize_value ) );
            } else {
                $total_prize_display = number_format( $total_prize_value, 0 );
            }
        }

        // Years active (since founded year).
        $years_active = 0;
        $founded_int  = (int) preg_replace( '/\D+/', '', (string) $founded );
        if ( $founded_int > 1900 && $founded_int <= (int) current_time( 'Y' ) ) {
            $years_active = max( 0, (int) current_time( 'Y' ) - $founded_int );
        }

        // Verified flag — true once the basic profile is complete enough for the directory.
        $is_verified = class_exists( 'CW_Roles' ) && CW_Roles::has_complete_organizer_profile( $user );

        // Active vs past split for filter pill counts.
        $now_ts = current_time( 'timestamp' );
        $active_count = 0;
        $past_count   = 0;
        $campaign_view = [];
        foreach ( $campaigns as $c ) {
            $pid       = (int) $c->ID;
            $deadline  = (string) get_post_meta( $pid, 'submission_deadline', true );
            $deadline_ts = $deadline ? strtotime( $deadline ) : 0;
            $is_past   = ( $deadline_ts && $deadline_ts < $now_ts );
            $state     = $is_past ? 'past' : 'active';
            if ( $is_past ) { $past_count++; } else { $active_count++; }

            // Category (Competition / Activity) from product_cat taxonomy.
            $cat_label = '';
            $cat_slug  = '';
            $terms = get_the_terms( $pid, 'product_cat' );
            if ( $terms && ! is_wp_error( $terms ) ) {
                foreach ( $terms as $t ) {
                    if ( strtolower( $t->slug ) === 'competitions' || strtolower( $t->name ) === 'competitions' ) {
                        $cat_label = __( 'Competition', 'creativewings-core' );
                        $cat_slug  = 'competition';
                        break;
                    }
                    if ( strtolower( $t->slug ) === 'activities' || strtolower( $t->name ) === 'activities' ) {
                        $cat_label = __( 'Activity', 'creativewings-core' );
                        $cat_slug  = 'activity';
                        break;
                    }
                }
                if ( $cat_label === '' ) {
                    $first = reset( $terms );
                    if ( $first instanceof WP_Term ) {
                        $cat_label = $first->name;
                        $cat_slug  = $first->slug;
                    }
                }
            }

            $thumb = get_the_post_thumbnail_url( $pid, 'medium_large' );
            $excerpt = get_the_excerpt( $c );
            if ( ! $excerpt ) {
                $excerpt = wp_strip_all_tags( (string) $c->post_content );
            }
            if ( function_exists( 'wp_html_excerpt' ) ) {
                $excerpt = wp_html_excerpt( $excerpt, 140, '…' );
            }

            $prize_raw = (string) get_post_meta( $pid, 'cw_total_prize_value', true );
            $prize_num = $prize_raw !== '' ? (float) preg_replace( '/[^0-9.]/', '', $prize_raw ) : 0;
            $prize_lbl = '';
            if ( $prize_num > 0 ) {
                $prize_lbl = function_exists( 'wc_price' )
                    ? wp_strip_all_tags( wc_price( $prize_num ) )
                    : number_format( $prize_num, 0 );
            }

            $campaign_view[] = [
                'id'           => $pid,
                'title'        => get_the_title( $c ),
                'permalink'    => get_permalink( $pid ),
                'thumb'        => $thumb ?: '',
                'excerpt'      => $excerpt,
                'state'        => $state,
                'cat_label'    => $cat_label,
                'cat_slug'     => $cat_slug,
                'deadline'     => $deadline_ts ? date_i18n( get_option( 'date_format', 'd M Y' ), $deadline_ts ) : '',
                'participants' => (int) ( $participants_map[ $pid ] ?? 0 ),
                'prize'        => $prize_lbl,
            ];
        }

        // ── KPI strip ───────────────────────────────────────────────
        // Inline strip with up to four data points: Campaigns, Participants,
        // Total Prizes (awarded), Years Active. Team Size / Industry / Location
        // are intentionally omitted — they already appear in the identity pills
        // and Quick Facts sidebar, so repeating them as full KPI tiles was visually
        // noisy and duplicative.
        $kpis = [];
        $kpis[] = [
            'label' => __( 'Campaigns', 'creativewings-core' ),
            'value' => number_format_i18n( $campaign_count ),
        ];
        $kpis[] = [
            'label' => __( 'Participants', 'creativewings-core' ),
            'value' => number_format_i18n( $total_participants ),
        ];
        if ( $total_prize_display !== '' ) {
            $kpis[] = [
                'label' => __( 'Total Prizes', 'creativewings-core' ),
                'value' => $total_prize_display,
            ];
        }
        if ( $years_active > 0 ) {
            $kpis[] = [
                'label' => __( 'Years Active', 'creativewings-core' ),
                'value' => number_format_i18n( $years_active ),
            ];
        }

        // ── Profile URL for share buttons ───────────────────────────
        $profile_url = home_url( '/' . self::ORG_BASE . '/' . rawurlencode( $user->user_login ) . '/' );
        $share_subject = sprintf( __( 'Check out %s on Creative Wings', 'creativewings-core' ), $display_name );
        $share_wa = 'https://wa.me/?text=' . rawurlencode( $share_subject . ': ' . $profile_url );
        $share_fb = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $profile_url );
        $share_tw = 'https://twitter.com/intent/tweet?url=' . rawurlencode( $profile_url ) . '&text=' . rawurlencode( $share_subject );

        // ── Quick-facts sidebar rows (only present items) ───────────
        $quick_facts = [];
        if ( $founded !== '' )  { $quick_facts[] = [ __( 'Founded', 'creativewings-core' ),  $founded ]; }
        if ( $industry !== '' ) { $quick_facts[] = [ __( 'Industry', 'creativewings-core' ), $industry ]; }
        if ( $team_size !== '' ){ $quick_facts[] = [ __( 'Team Size', 'creativewings-core' ),$team_size ]; }
        if ( $ssm !== '' )      { $quick_facts[] = [ __( 'Reg. No.', 'creativewings-core' ), $ssm ]; }

        // ── Footer CTA target ───────────────────────────────────────
        $footer_cta_url   = '';
        $footer_cta_label = '';
        if ( $email !== '' ) {
            $footer_cta_url   = 'mailto:' . $email;
            $footer_cta_label = __( 'Email us', 'creativewings-core' );
        } elseif ( $website !== '' ) {
            $footer_cta_url   = $website;
            $footer_cta_label = __( 'Visit website', 'creativewings-core' );
        }

        $about_default = __( 'No description yet.', 'creativewings-core' );

        ob_start();
        ?>
        <div class="cw-org-page" id="cw-org-page-<?php echo esc_attr( $uid ); ?>">

            <!-- 1. HERO BAND -->
            <header class="cw-org-hero <?php echo $cover_url ? 'has-cover' : 'no-cover'; ?>">
                <?php if ( $cover_url ) : ?>
                    <div class="cw-org-hero-bg" style="background-image:url('<?php echo esc_url( $cover_url ); ?>');" aria-hidden="true"></div>
                <?php endif; ?>
                <div class="cw-org-hero-overlay" aria-hidden="true"></div>
            </header>

            <div class="cw-org-shell">

                <!-- Identity card overlapping hero — logo + identity merged into one card -->
                <section class="cw-org-identity-card">
                    <div class="cw-org-logo-slot">
                        <?php if ( $logo_url ) : ?>
                            <?php
                            $logo_alt = sprintf( __( '%s logo', 'creativewings-core' ), $display_name );
                            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                                echo CW_Image_Optimizer::picture_tag( $logo_url, $logo_alt, [ 'class' => 'cw-org-logo' ] );
                            } else {
                                echo '<img class="cw-org-logo" src="' . esc_url( $logo_url ) . '" alt="' . esc_attr( $logo_alt ) . '" loading="lazy" decoding="async">';
                            }
                            ?>
                        <?php else : ?>
                            <span class="cw-org-logo-fallback" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $display_name, 0, 1 ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cw-org-identity-body">
                        <div class="cw-org-name-row">
                            <h1 class="cw-org-name"><?php echo esc_html( $display_name ); ?></h1>
                            <?php if ( $is_verified ) : ?>
                                <span class="cw-org-verified" title="<?php esc_attr_e( 'Verified Organizer', 'creativewings-core' ); ?>" aria-label="<?php esc_attr_e( 'Verified Organizer', 'creativewings-core' ); ?>">
                                    <i class="fas fa-check" aria-hidden="true"></i>
                                </span>
                            <?php endif; ?>
                        </div>
                        <?php if ( $tagline !== '' ) : ?>
                            <p class="cw-org-tagline"><?php echo esc_html( $tagline ); ?></p>
                        <?php endif; ?>
                        <div class="cw-org-meta-row">
                            <?php if ( $industry !== '' ) : ?>
                                <span class="cw-org-pill cw-org-pill--industry"><i class="fas fa-briefcase" aria-hidden="true"></i> <?php echo esc_html( $industry ); ?></span>
                            <?php endif; ?>
                            <?php if ( $location !== '' ) : ?>
                                <span class="cw-org-pill"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php echo esc_html( $location ); ?></span>
                            <?php endif; ?>
                            <?php if ( $founded !== '' ) : ?>
                                <span class="cw-org-pill"><i class="far fa-calendar-alt" aria-hidden="true"></i> <?php echo esc_html( sprintf( __( 'Since %s', 'creativewings-core' ), $founded ) ); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="cw-org-cta-row">
                            <a href="#cw-org-campaigns" class="cw-org-btn cw-org-btn--primary">
                                <i class="fas fa-bullhorn" aria-hidden="true"></i>
                                <?php esc_html_e( 'View Campaigns', 'creativewings-core' ); ?>
                            </a>
                            <?php if ( $website !== '' ) : ?>
                                <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer" class="cw-org-btn cw-org-btn--ghost">
                                    <i class="fas fa-external-link-alt" aria-hidden="true"></i>
                                    <?php esc_html_e( 'Website', 'creativewings-core' ); ?>
                                </a>
                            <?php endif; ?>
                            <?php if ( $email !== '' ) : ?>
                                <a href="mailto:<?php echo esc_attr( $email ); ?>" class="cw-org-btn cw-org-btn--ghost">
                                    <i class="far fa-envelope" aria-hidden="true"></i>
                                    <?php esc_html_e( 'Contact', 'creativewings-core' ); ?>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Trust strip -->
                <?php
                $trust_items = [];
                if ( $is_verified ) {
                    $trust_items[] = [ 'icon' => 'fas fa-shield-alt',  'label' => __( 'Verified Organizer', 'creativewings-core' ) ];
                }
                if ( $years_active > 0 ) {
                    $trust_items[] = [ 'icon' => 'fas fa-history', 'label' => sprintf( _n( '%s year active', '%s years active', $years_active, 'creativewings-core' ), number_format_i18n( $years_active ) ) ];
                }
                if ( $campaign_count > 0 ) {
                    $trust_items[] = [ 'icon' => 'fas fa-bullhorn', 'label' => sprintf( _n( '%s campaign hosted', '%s campaigns hosted', $campaign_count, 'creativewings-core' ), number_format_i18n( $campaign_count ) ) ];
                }
                if ( $total_participants > 0 ) {
                    $trust_items[] = [ 'icon' => 'fas fa-users', 'label' => sprintf( _n( '%s participant engaged', '%s participants engaged', $total_participants, 'creativewings-core' ), number_format_i18n( $total_participants ) ) ];
                }
                if ( $awarded_winners > 0 ) {
                    $trust_items[] = [
                        'icon'  => 'fas fa-trophy',
                        'label' => sprintf(
                            _n( '%s prize awarded', '%s prizes awarded', $awarded_winners, 'creativewings-core' ),
                            number_format_i18n( $awarded_winners )
                        ),
                    ];
                }
                if ( $trust_items ) :
                ?>
                <section class="cw-org-trust-strip" aria-label="<?php esc_attr_e( 'Trust signals', 'creativewings-core' ); ?>">
                    <?php foreach ( $trust_items as $i => $item ) : ?>
                        <span class="cw-org-trust-item">
                            <i class="<?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></i>
                            <span><?php echo esc_html( $item['label'] ); ?></span>
                        </span>
                        <?php if ( $i < count( $trust_items ) - 1 ) : ?>
                            <span class="cw-org-trust-sep" aria-hidden="true">·</span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>

                <?php
                if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                    $org_badges = CW_Badges_Engine::get_user_badges( (int) $user->ID );
                    if ( ! empty( $org_badges ) ) :
                ?>
                <section class="cw-org-badges-row" aria-label="<?php esc_attr_e( 'Badges earned', 'creativewings-core' ); ?>">
                    <h3 class="cw-org-sub-title" style="display:flex;align-items:center;gap:6px;margin:10px 0 6px;">
                        <i class="fas fa-medal" style="color:#facc15;"></i> <?php esc_html_e( 'Badges earned', 'creativewings-core' ); ?>
                        <span style="font-size:11px;color:#555555;font-weight:600;background:#f1f5f9;padding:2px 8px;border-radius:999px;">
                            <?php echo (int) count( $org_badges ); ?>
                        </span>
                    </h3>
                    <?php echo CW_Badges_Display::render_strip( $org_badges, 8, [ 'size' => 'sm', 'show_label' => false, 'show_tier' => false ] ); ?>
                </section>
                <?php endif; } ?>

                <!-- 2. KPI strip — inline metrics with dividers, no per-tile chrome -->
                <?php if ( $kpis ) : ?>
                <section class="cw-org-kpi-strip" aria-label="<?php esc_attr_e( 'At a glance', 'creativewings-core' ); ?>">
                    <?php foreach ( $kpis as $tile ) : ?>
                        <div class="cw-org-kpi-item">
                            <span class="cw-org-kpi-value"><?php echo esc_html( wp_strip_all_tags( $tile['value'] ) ); ?></span>
                            <span class="cw-org-kpi-label"><?php echo esc_html( $tile['label'] ); ?></span>
                        </div>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>

                <!-- 3. ABOUT + quick facts -->
                <section class="cw-org-about-section">
                    <div class="cw-org-card cw-org-about">
                        <h2 class="cw-org-section-title"><?php echo esc_html( sprintf( __( 'About %s', 'creativewings-core' ), $display_name ) ); ?></h2>
                        <div class="cw-org-about-body">
                            <?php echo $about !== '' ? wp_kses_post( wpautop( $about ) ) : esc_html( $about_default ); ?>
                        </div>
                    </div>

                    <?php if ( $quick_facts ) : ?>
                    <aside class="cw-org-card cw-org-quickfacts" aria-label="<?php esc_attr_e( 'Quick facts', 'creativewings-core' ); ?>">
                        <h3 class="cw-org-sub-title"><?php esc_html_e( 'Quick Facts', 'creativewings-core' ); ?></h3>
                        <dl class="cw-org-facts-list">
                            <?php foreach ( $quick_facts as $row ) : ?>
                                <div class="cw-org-fact">
                                    <dt><?php echo esc_html( $row[0] ); ?></dt>
                                    <dd><?php echo esc_html( $row[1] ); ?></dd>
                                </div>
                            <?php endforeach; ?>
                        </dl>
                    </aside>
                    <?php endif; ?>
                </section>

                <!-- 4. CONTACT + share -->
                <?php
                $has_contact = ( $phone_display !== '' ) || ( $email !== '' ) || ( $website !== '' ) || ( $address !== '' );
                if ( $has_contact ) :
                ?>
                <section class="cw-org-card cw-org-contact-card" aria-label="<?php esc_attr_e( 'Contact', 'creativewings-core' ); ?>">
                    <h3 class="cw-org-sub-title cw-org-contact-title"><i class="fas fa-headset" aria-hidden="true"></i> <?php esc_html_e( 'Get in touch', 'creativewings-core' ); ?></h3>
                    <ul class="cw-org-contact-list">
                        <?php if ( $phone_display !== '' ) : ?>
                            <li class="cw-org-contact-item">
                                <i class="fas fa-phone" aria-hidden="true"></i>
                                <a href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone_display ) ); ?>"><?php echo esc_html( $phone_display ); ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if ( $email !== '' ) : ?>
                            <li class="cw-org-contact-item">
                                <i class="fas fa-envelope" aria-hidden="true"></i>
                                <a href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if ( $website !== '' ) : ?>
                            <li class="cw-org-contact-item">
                                <i class="fas fa-globe" aria-hidden="true"></i>
                                <a href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $website, PHP_URL_HOST ) ?: $website ); ?></a>
                            </li>
                        <?php endif; ?>
                        <?php if ( $address !== '' ) : ?>
                            <li class="cw-org-contact-item cw-org-contact-item--wide">
                                <i class="fas fa-map-marker-alt" aria-hidden="true"></i>
                                <span><?php echo esc_html( $address ); ?></span>
                            </li>
                        <?php endif; ?>
                    </ul>

                    <div class="cw-org-share cw-org-share--compact">
                        <span class="cw-org-share-label"><?php esc_html_e( 'Share:', 'creativewings-core' ); ?></span>
                        <button type="button" class="cw-org-share-btn cw-org-share-copy" data-url="<?php echo esc_attr( $profile_url ); ?>" aria-label="<?php esc_attr_e( 'Copy profile link', 'creativewings-core' ); ?>" title="<?php esc_attr_e( 'Copy link', 'creativewings-core' ); ?>">
                            <i class="fas fa-link" aria-hidden="true"></i>
                        </button>
                        <a class="cw-org-share-btn cw-org-share-wa" href="<?php echo esc_url( $share_wa ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'WhatsApp', 'creativewings-core' ); ?>" aria-label="<?php esc_attr_e( 'Share on WhatsApp', 'creativewings-core' ); ?>">
                            <i class="fab fa-whatsapp" aria-hidden="true"></i>
                        </a>
                        <a class="cw-org-share-btn cw-org-share-fb" href="<?php echo esc_url( $share_fb ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'Facebook', 'creativewings-core' ); ?>" aria-label="<?php esc_attr_e( 'Share on Facebook', 'creativewings-core' ); ?>">
                            <i class="fab fa-facebook-f" aria-hidden="true"></i>
                        </a>
                        <a class="cw-org-share-btn cw-org-share-x" href="<?php echo esc_url( $share_tw ); ?>" target="_blank" rel="noopener" title="<?php esc_attr_e( 'X', 'creativewings-core' ); ?>" aria-label="<?php esc_attr_e( 'Share on X', 'creativewings-core' ); ?>">
                            <i class="fab fa-twitter" aria-hidden="true"></i>
                        </a>
                        <div class="cw-org-share-toast" role="status" aria-live="polite"><?php esc_html_e( 'Copied!', 'creativewings-core' ); ?></div>
                    </div>
                </section>
                <?php endif; ?>

                <!-- 5. SOCIAL ROW -->
                <?php if ( $socials_present ) : ?>
                <section class="cw-org-socials" aria-label="<?php esc_attr_e( 'Find us on social media', 'creativewings-core' ); ?>">
                    <?php foreach ( $socials_present as $key => $info ) : ?>
                        <a href="<?php echo esc_url( $info['url'] ); ?>" target="_blank" rel="noopener noreferrer"
                           class="cw-org-social" data-net="<?php echo esc_attr( $info['net'] ); ?>"
                           aria-label="<?php echo esc_attr( sprintf( __( '%1$s on %2$s', 'creativewings-core' ), $display_name, $info['label'] ) ); ?>"
                           title="<?php echo esc_attr( $info['label'] ); ?>">
                            <i class="<?php echo esc_attr( $info['icon'] ); ?>" aria-hidden="true"></i>
                        </a>
                    <?php endforeach; ?>
                </section>
                <?php endif; ?>

                <!-- 6. CAMPAIGNS -->
                <section class="cw-org-campaigns" id="cw-org-campaigns">
                    <div class="cw-org-campaigns-head">
                        <h2 class="cw-org-section-title">
                            <?php echo esc_html( sprintf( __( 'Campaigns by %s', 'creativewings-core' ), $display_name ) ); ?>
                            <span class="cw-org-count">(<?php echo esc_html( number_format_i18n( $campaign_count ) ); ?>)</span>
                        </h2>

                        <?php if ( $campaign_count > 0 ) : ?>
                        <div class="cw-org-filter-pills" role="tablist" aria-label="<?php esc_attr_e( 'Campaign filter', 'creativewings-core' ); ?>">
                            <button type="button" class="cw-org-filter-pill is-active" data-filter="all" role="tab" aria-selected="true">
                                <?php esc_html_e( 'All', 'creativewings-core' ); ?>
                                <span class="cw-org-filter-count">(<?php echo esc_html( number_format_i18n( $campaign_count ) ); ?>)</span>
                            </button>
                            <button type="button" class="cw-org-filter-pill" data-filter="active" role="tab" aria-selected="false">
                                <?php esc_html_e( 'Active', 'creativewings-core' ); ?>
                                <span class="cw-org-filter-count">(<?php echo esc_html( number_format_i18n( $active_count ) ); ?>)</span>
                            </button>
                            <button type="button" class="cw-org-filter-pill" data-filter="past" role="tab" aria-selected="false">
                                <?php esc_html_e( 'Past', 'creativewings-core' ); ?>
                                <span class="cw-org-filter-count">(<?php echo esc_html( number_format_i18n( $past_count ) ); ?>)</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ( $campaign_count > 0 ) : ?>
                    <div class="cw-org-campaign-grid">
                        <?php foreach ( $campaign_view as $c ) : ?>
                            <article class="cw-org-campaign-card" data-state="<?php echo esc_attr( $c['state'] ); ?>">
                                <a class="cw-org-campaign-thumb" href="<?php echo esc_url( $c['permalink'] ); ?>" aria-label="<?php echo esc_attr( $c['title'] ); ?>">
                                    <?php if ( $c['thumb'] ) : ?>
                                        <?php
                                        if ( class_exists( 'CW_Image_Optimizer' ) ) {
                                            echo CW_Image_Optimizer::picture_tag( $c['thumb'], $c['title'] );
                                        } else {
                                            echo '<img src="' . esc_url( $c['thumb'] ) . '" alt="' . esc_attr( $c['title'] ) . '" loading="lazy" decoding="async">';
                                        }
                                        ?>
                                    <?php else : ?>
                                        <span class="cw-org-thumb-fallback"><i class="fas fa-image" aria-hidden="true"></i></span>
                                    <?php endif; ?>
                                    <?php if ( $c['cat_label'] !== '' ) : ?>
                                        <span class="cw-org-campaign-cat cw-org-campaign-cat--<?php echo esc_attr( $c['cat_slug'] ); ?>"><?php echo esc_html( $c['cat_label'] ); ?></span>
                                    <?php endif; ?>
                                    <span class="cw-org-campaign-state cw-org-campaign-state--<?php echo esc_attr( $c['state'] ); ?>">
                                        <i class="<?php echo esc_attr( $c['state'] === 'past' ? 'fas fa-circle' : 'fas fa-circle' ); ?>" aria-hidden="true"></i>
                                        <?php echo esc_html( $c['state'] === 'past' ? __( 'Past', 'creativewings-core' ) : __( 'Active', 'creativewings-core' ) ); ?>
                                    </span>
                                    <?php if ( ! empty( $c['prize'] ) ) : ?>
                                        <span class="cw-org-campaign-prize">
                                            <i class="fas fa-trophy" aria-hidden="true"></i>
                                            <?php echo esc_html( $c['prize'] ); ?>
                                        </span>
                                    <?php endif; ?>
                                </a>
                                <div class="cw-org-campaign-body">
                                    <h3 class="cw-org-campaign-title"><a href="<?php echo esc_url( $c['permalink'] ); ?>"><?php echo esc_html( $c['title'] ); ?></a></h3>
                                    <?php if ( $c['excerpt'] !== '' ) : ?>
                                        <p class="cw-org-campaign-excerpt"><?php echo esc_html( $c['excerpt'] ); ?></p>
                                    <?php endif; ?>
                                    <ul class="cw-org-campaign-stats">
                                        <?php if ( $c['deadline'] !== '' ) : ?>
                                            <li><i class="far fa-clock" aria-hidden="true"></i> <?php echo esc_html( sprintf( __( 'Deadline %s', 'creativewings-core' ), $c['deadline'] ) ); ?></li>
                                        <?php endif; ?>
                                        <li><i class="fas fa-users" aria-hidden="true"></i> <?php echo esc_html( sprintf( _n( '%s participant', '%s participants', $c['participants'], 'creativewings-core' ), number_format_i18n( $c['participants'] ) ) ); ?></li>
                                    </ul>
                                    <a class="cw-org-campaign-cta" href="<?php echo esc_url( $c['permalink'] ); ?>">
                                        <?php esc_html_e( 'View campaign', 'creativewings-core' ); ?>
                                        <i class="fas fa-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </div>
                            </article>
                        <?php endforeach; ?>
                    </div>
                    <?php else : ?>
                    <div class="cw-org-empty">
                        <div class="cw-org-empty-icon"><i class="fas fa-bullhorn" aria-hidden="true"></i></div>
                        <h3><?php esc_html_e( 'No campaigns yet', 'creativewings-core' ); ?></h3>
                        <p><?php echo esc_html( sprintf( __( '%s has not published any campaigns yet — check back soon.', 'creativewings-core' ), $display_name ) ); ?></p>
                    </div>
                    <?php endif; ?>
                </section>

                <!-- 7. FOOTER CTA STRIP -->
                <?php if ( $footer_cta_url !== '' ) : ?>
                <section class="cw-org-footer-cta" aria-label="<?php esc_attr_e( 'Work with us', 'creativewings-core' ); ?>">
                    <div class="cw-org-footer-cta-inner">
                        <div>
                            <h3><?php echo esc_html( sprintf( __( 'Want to host a campaign with %s?', 'creativewings-core' ), $display_name ) ); ?></h3>
                            <p><?php esc_html_e( 'Reach out and start a conversation.', 'creativewings-core' ); ?></p>
                        </div>
                        <a class="cw-org-btn cw-org-btn--primary" href="<?php echo esc_url( $footer_cta_url ); ?>"<?php echo strpos( $footer_cta_url, 'mailto:' ) === 0 ? '' : ' target="_blank" rel="noopener noreferrer"'; ?>>
                            <i class="fas fa-paper-plane" aria-hidden="true"></i>
                            <?php echo esc_html( $footer_cta_label ); ?>
                        </a>
                    </div>
                </section>
                <?php endif; ?>

            </div><!-- /.cw-org-shell -->
        </div><!-- /.cw-org-page -->

        <script>
        (function(){
            var page = document.getElementById('cw-org-page-<?php echo (int) $uid; ?>');
            if ( ! page ) return;

            /* ── Filter pills ── */
            var pills = page.querySelectorAll('.cw-org-filter-pill');
            var cards = page.querySelectorAll('.cw-org-campaign-card');
            for (var i = 0; i < pills.length; i++) {
                pills[i].addEventListener('click', function(){
                    var f = this.getAttribute('data-filter') || 'all';
                    for (var j = 0; j < pills.length; j++) {
                        pills[j].classList.remove('is-active');
                        pills[j].setAttribute('aria-selected', 'false');
                    }
                    this.classList.add('is-active');
                    this.setAttribute('aria-selected', 'true');
                    for (var k = 0; k < cards.length; k++) {
                        var s = cards[k].getAttribute('data-state') || '';
                        cards[k].style.display = (f === 'all' || f === s) ? '' : 'none';
                    }
                });
            }

            /* ── Copy share link ── */
            var copyBtn = page.querySelector('.cw-org-share-copy');
            var toast   = page.querySelector('.cw-org-share-toast');
            function showToast(){
                if (!toast) return;
                toast.classList.add('show');
                clearTimeout(toast._t);
                toast._t = setTimeout(function(){ toast.classList.remove('show'); }, 1500);
            }
            function fallbackCopy(text){
                var ta = document.createElement('textarea');
                ta.value = text; ta.setAttribute('readonly', '');
                ta.style.position = 'fixed'; ta.style.top = '-1000px'; ta.style.opacity = '0';
                document.body.appendChild(ta); ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch(e) { ok = false; }
                document.body.removeChild(ta);
                return ok;
            }
            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    var url = this.getAttribute('data-url') || window.location.href;
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(showToast, function(){
                            if (fallbackCopy(url)) showToast();
                        });
                    } else if (fallbackCopy(url)) {
                        showToast();
                    }
                });
            }

            /* ── Smooth scroll for the in-hero "View Campaigns" CTA ── */
            var jump = page.querySelector('a[href="#cw-org-campaigns"]');
            var target = document.getElementById('cw-org-campaigns');
            if (jump && target && 'scrollBehavior' in document.documentElement.style) {
                jump.addEventListener('click', function(e){
                    e.preventDefault();
                    target.scrollIntoView({ behavior: 'smooth', block: 'start' });
                });
            }
        })();
        </script>
        <?php
        return (string) ob_get_clean();
    }

    /* ──────────────────────────────────────────────────────────────────
     * Data helpers
     * ────────────────────────────────────────────────────────────────── */

    /**
     * Published campaigns authored by the organizer.
     *
     * @return WP_Post[]
     */
    private function get_published_campaigns( $uid ) {
        $q = new WP_Query( [
            'post_type'      => 'product',
            'author'         => (int) $uid,
            'post_status'    => 'publish',
            'posts_per_page' => -1,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ] );
        return $q->posts ?: [];
    }

    /**
     * Single batched query for the total participants across many campaigns.
     */
    private function get_total_participants( array $pids ) {
        if ( empty( $pids ) ) {
            return 0;
        }
        $map = $this->get_participants_map( $pids );
        return (int) array_sum( $map );
    }

    /**
     * Return [ pid => participant_count ] for the given product IDs.
     *
     * Single GROUP BY query replaces the previous N+1 pattern of calling
     * get_participants_for_pid() inside the campaign render foreach.
     * Results are also primed into the per-request memo cache so any leftover
     * legacy callers of get_participants_for_pid() avoid further DB hits.
     *
     * @param int[] $pids
     * @return array<int,int>
     */
    private function get_participants_map( array $pids ) {
        $pids = array_values( array_unique( array_filter( array_map( 'intval', $pids ) ) ) );
        if ( empty( $pids ) ) {
            return [];
        }

        // Honor whatever's already memoized; only query the misses.
        $hits   = [];
        $misses = [];
        foreach ( $pids as $pid ) {
            if ( isset( self::$participants_cache[ $pid ] ) ) {
                $hits[ $pid ] = (int) self::$participants_cache[ $pid ];
            } else {
                $misses[] = $pid;
            }
        }

        if ( ! empty( $misses ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $misses ), '%d' ) );
            $sql = "SELECT meta_value AS pid, COUNT(*) AS c
                    FROM {$wpdb->postmeta}
                    WHERE meta_key = 'product_id'
                      AND meta_value IN ($placeholders)
                    GROUP BY meta_value";
            $rows = $wpdb->get_results( $wpdb->prepare( $sql, $misses ), ARRAY_A );

            $found = [];
            foreach ( (array) $rows as $r ) {
                $found[ (int) $r['pid'] ] = (int) $r['c'];
            }
            foreach ( $misses as $pid ) {
                $cnt = (int) ( $found[ $pid ] ?? 0 );
                self::$participants_cache[ $pid ] = $cnt;
                $hits[ $pid ] = $cnt;
            }
        }

        return $hits;
    }

    /**
     * Participants count for a single product_id, memoised per request.
     *
     * Kept for backwards-compat; new code should prefer get_participants_map()
     * to avoid N+1 queries.
     */
    private function get_participants_for_pid( $pid ) {
        $pid = (int) $pid;
        if ( isset( self::$participants_cache[ $pid ] ) ) {
            return self::$participants_cache[ $pid ];
        }
        $map = $this->get_participants_map( [ $pid ] );
        return (int) ( $map[ $pid ] ?? 0 );
    }

    /**
     * Sum the monetary value of all prizes actually awarded across the
     * organizer's campaigns. Sourced from each campaign's `prizes` repeater
     * (`prize_title`, `prize_description`) matched against winning entries.
     *
     * @param int[] $campaign_pids
     * @return float
     */
    private function compute_awarded_prize_total( array $campaign_pids ) {
        $total = 0.0;
        foreach ( $campaign_pids as $pid ) {
            $pid     = (int) $pid;
            $prizes  = $this->build_prize_rank_lookup( $pid );
            if ( empty( $prizes ) ) {
                continue;
            }
            $winners = $this->get_campaign_winners( $pid );
            foreach ( $winners as $w ) {
                $rank = self::normalize_rank_token( (string) $w['winner_rank'] );
                if ( $rank === '' || ! isset( $prizes[ $rank ] ) ) {
                    continue;
                }
                $amount = self::extract_currency_amount( $prizes[ $rank ] );
                if ( $amount > 0 ) {
                    $total += $amount;
                }
            }
        }
        return $total;
    }

    /**
     * Count how many entries across all campaigns have winner_status = 'yes'.
     * Used for the trust strip "N prizes awarded" line.
     *
     * @param int[] $campaign_pids
     * @return int
     */
    private function count_awarded_winners( array $campaign_pids ) {
        $count = 0;
        foreach ( $campaign_pids as $pid ) {
            $count += count( $this->get_campaign_winners( (int) $pid ) );
        }
        return $count;
    }

    /**
     * Build a `rank_token => prize_description` lookup for a campaign's
     * prizes repeater. Tokens: 1st, 2nd, 3rd, mention.
     *
     * @return array<string, string>
     */
    private function build_prize_rank_lookup( $campaign_id ) {
        $prizes = get_post_meta( (int) $campaign_id, 'prizes', true );
        if ( ! is_array( $prizes ) || empty( $prizes ) ) {
            return [];
        }
        $map = [];
        foreach ( $prizes as $p ) {
            if ( ! is_array( $p ) ) {
                continue;
            }
            $title = (string) ( $p['prize_title'] ?? '' );
            $desc  = (string) ( $p['prize_description'] ?? '' );
            $rank  = self::normalize_rank_token( $title );
            if ( $rank !== '' && ! isset( $map[ $rank ] ) ) {
                $map[ $rank ] = $desc;
            }
        }
        return $map;
    }

    /**
     * Returns [{ winner_rank, product_id }, …] for entries flagged as winners
     * on the given campaign.
     */
    private function get_campaign_winners( $campaign_id ) {
        global $wpdb;
        $campaign_id = (int) $campaign_id;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID, pm_rank.meta_value AS winner_rank
               FROM {$wpdb->posts} p
               INNER JOIN {$wpdb->postmeta} pm_pid  ON pm_pid.post_id  = p.ID AND pm_pid.meta_key  = 'product_id'
               INNER JOIN {$wpdb->postmeta} pm_win  ON pm_win.post_id  = p.ID AND pm_win.meta_key  = 'winner_status'
               LEFT JOIN  {$wpdb->postmeta} pm_rank ON pm_rank.post_id = p.ID AND pm_rank.meta_key = 'winner_rank'
              WHERE p.post_type IN ('cw_competition_entry', 'cw_activity_entry')
                AND p.post_status = 'publish'
                AND pm_pid.meta_value = %d
                AND pm_win.meta_value = 'yes'",
            $campaign_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Normalize a prize title or winner_rank value into a canonical token.
     *   "1st Place" / "first prize" / "1"  -> "1st"
     *   "2nd Place" / "second"             -> "2nd"
     *   "3rd Place" / "third"              -> "3rd"
     *   "Honorable Mention" / "mention"    -> "mention"
     */
    private static function normalize_rank_token( $raw ) {
        $s = strtolower( trim( (string) $raw ) );
        if ( $s === '' ) {
            return '';
        }
        if ( preg_match( '/\b1\b|\b1st\b|\bfirst\b/u', $s ) )      { return '1st'; }
        if ( preg_match( '/\b2\b|\b2nd\b|\bsecond\b/u', $s ) )     { return '2nd'; }
        if ( preg_match( '/\b3\b|\b3rd\b|\bthird\b/u', $s ) )      { return '3rd'; }
        if ( preg_match( '/honou?r|mention/u', $s ) )              { return 'mention'; }
        return '';
    }

    /**
     * Extract the first plausible currency amount from a free-text prize
     * description. Matches RM 1,000.00 / MYR 500 / $50 / 1500 etc.
     */
    private static function extract_currency_amount( $text ) {
        if ( ! preg_match( '/(?:RM|MYR|\$|USD)?\s*([\d][\d,]*(?:\.\d+)?)/u', (string) $text, $m ) ) {
            return 0.0;
        }
        return (float) str_replace( ',', '', $m[1] );
    }
}
