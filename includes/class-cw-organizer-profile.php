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
             && preg_match( '#^/?' . self::ORG_BASE . '/([^/?#]+)/?#', (string) $_SERVER['REQUEST_URI'], $m ) ) {
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
        $uid = (int) $user->ID;

        // ── Identity / branding ─────────────────────────────────────
        $biz_name    = (string) get_user_meta( $uid, 'business_name', true );
        $tagline     = (string) get_user_meta( $uid, 'business_tagline', true );
        $about       = (string) get_user_meta( $uid, 'business_about', true );
        $founded     = (string) get_user_meta( $uid, 'business_founded_year', true );
        $industry    = (string) get_user_meta( $uid, 'business_industry', true );
        $team_size   = (string) get_user_meta( $uid, 'business_team_size', true );
        $city        = (string) get_user_meta( $uid, 'business_city', true );
        $country     = (string) get_user_meta( $uid, 'business_country', true );
        $address     = (string) get_user_meta( $uid, 'business_address', true );
        $ssm         = (string) get_user_meta( $uid, 'business_ssm', true );

        $logo_meta   = get_user_meta( $uid, 'business_logo',  true );
        $logo_url    = ( is_array( $logo_meta )  && ! empty( $logo_meta['url'] ) )  ? (string) $logo_meta['url']  : '';

        $cover_meta  = get_user_meta( $uid, 'business_cover', true );
        $cover_url   = ( is_array( $cover_meta ) && ! empty( $cover_meta['url'] ) ) ? (string) $cover_meta['url'] : '';

        // ── Contact (with visibility toggles) ───────────────────────
        $phone       = (string) get_user_meta( $uid, 'business_phone',  true );
        $website     = (string) get_user_meta( $uid, 'business_website', true );
        $show_phone  = get_user_meta( $uid, 'cw_show_org_phone', true );
        $show_email  = get_user_meta( $uid, 'cw_show_org_email', true );
        // Default visible unless explicitly stored as '0'.
        $phone_visible = ( $show_phone !== '0' );
        $email_visible = ( $show_email !== '0' );
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
            $url = trim( (string) get_user_meta( $uid, $key, true ) );
            if ( $url !== '' ) {
                $socials_present[ $key ] = [ 'url' => $url ] + $info;
            }
        }

        // ── Campaigns ───────────────────────────────────────────────
        $campaigns = $this->get_published_campaigns( $uid );
        $campaign_count = count( $campaigns );

        // Pre-compute total participants once (single query for all campaigns).
        $total_participants = $this->get_total_participants( array_map( static fn( $c ) => (int) $c->ID, $campaigns ) );

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
                'participants' => $this->get_participants_for_pid( $pid ),
            ];
        }

        // ── KPI tiles ───────────────────────────────────────────────
        $kpis = [];
        $kpis[] = [
            'label' => __( 'Campaigns', 'creativewings-core' ),
            'value' => number_format_i18n( $campaign_count ),
        ];
        $kpis[] = [
            'label' => __( 'Participants', 'creativewings-core' ),
            'value' => number_format_i18n( $total_participants ),
        ];
        if ( $founded !== '' ) {
            $kpis[] = [
                'label' => __( 'Founded', 'creativewings-core' ),
                'value' => sprintf( __( 'Since %s', 'creativewings-core' ), esc_html( $founded ) ),
            ];
        }
        if ( $team_size !== '' ) {
            $kpis[] = [
                'label' => __( 'Team Size', 'creativewings-core' ),
                'value' => esc_html( $team_size ),
            ];
        }
        if ( $industry !== '' ) {
            $kpis[] = [
                'label' => __( 'Industry', 'creativewings-core' ),
                'value' => esc_html( $industry ),
            ];
        }
        if ( $location !== '' ) {
            $kpis[] = [
                'label' => __( 'Location', 'creativewings-core' ),
                'value' => esc_html( $location ),
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

                <!-- Identity row overlapping hero -->
                <section class="cw-org-identity">
                    <div class="cw-org-logo-card">
                        <?php if ( $logo_url ) : ?>
                            <img class="cw-org-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php echo esc_attr( sprintf( __( '%s logo', 'creativewings-core' ), $display_name ) ); ?>">
                        <?php else : ?>
                            <span class="cw-org-logo-fallback" aria-hidden="true"><?php echo esc_html( mb_strtoupper( mb_substr( $display_name, 0, 1 ) ) ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="cw-org-identity-text">
                        <h1 class="cw-org-name"><?php echo esc_html( $display_name ); ?></h1>
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
                        <?php if ( $tagline !== '' ) : ?>
                            <p class="cw-org-tagline"><?php echo esc_html( $tagline ); ?></p>
                        <?php endif; ?>
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
                        </div>
                    </div>
                </section>

                <!-- 2. KPI tiles -->
                <?php if ( $kpis ) : ?>
                <section class="cw-org-kpi-row" aria-label="<?php esc_attr_e( 'At a glance', 'creativewings-core' ); ?>">
                    <?php foreach ( $kpis as $tile ) : ?>
                        <div class="cw-org-kpi">
                            <span class="cw-org-kpi-label"><?php echo esc_html( $tile['label'] ); ?></span>
                            <span class="cw-org-kpi-value"><?php echo esc_html( wp_strip_all_tags( $tile['value'] ) ); ?></span>
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
                    <div class="cw-org-contact-grid">
                        <div class="cw-org-contact-rows">
                            <h3 class="cw-org-sub-title"><?php esc_html_e( 'Get in touch', 'creativewings-core' ); ?></h3>
                            <?php if ( $phone_display !== '' ) : ?>
                                <div class="cw-org-contact-row">
                                    <span class="cw-org-contact-label"><i class="fas fa-phone" aria-hidden="true"></i> <?php esc_html_e( 'Phone', 'creativewings-core' ); ?></span>
                                    <a class="cw-org-contact-value" href="tel:<?php echo esc_attr( preg_replace( '/[^\d+]/', '', $phone_display ) ); ?>"><?php echo esc_html( $phone_display ); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ( $email !== '' ) : ?>
                                <div class="cw-org-contact-row">
                                    <span class="cw-org-contact-label"><i class="fas fa-envelope" aria-hidden="true"></i> <?php esc_html_e( 'Email', 'creativewings-core' ); ?></span>
                                    <a class="cw-org-contact-value" href="mailto:<?php echo esc_attr( $email ); ?>"><?php echo esc_html( $email ); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ( $website !== '' ) : ?>
                                <div class="cw-org-contact-row">
                                    <span class="cw-org-contact-label"><i class="fas fa-globe" aria-hidden="true"></i> <?php esc_html_e( 'Website', 'creativewings-core' ); ?></span>
                                    <a class="cw-org-contact-value" href="<?php echo esc_url( $website ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( wp_parse_url( $website, PHP_URL_HOST ) ?: $website ); ?></a>
                                </div>
                            <?php endif; ?>
                            <?php if ( $address !== '' ) : ?>
                                <div class="cw-org-contact-row">
                                    <span class="cw-org-contact-label"><i class="fas fa-map-marker-alt" aria-hidden="true"></i> <?php esc_html_e( 'Address', 'creativewings-core' ); ?></span>
                                    <span class="cw-org-contact-value"><?php echo esc_html( $address ); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <div class="cw-org-share">
                            <h3 class="cw-org-sub-title"><?php esc_html_e( 'Share this profile', 'creativewings-core' ); ?></h3>
                            <div class="cw-org-share-row">
                                <button type="button" class="cw-org-share-btn cw-org-share-copy" data-url="<?php echo esc_attr( $profile_url ); ?>" aria-label="<?php esc_attr_e( 'Copy profile link', 'creativewings-core' ); ?>">
                                    <i class="fas fa-link" aria-hidden="true"></i> <?php esc_html_e( 'Copy link', 'creativewings-core' ); ?>
                                </button>
                                <a class="cw-org-share-btn cw-org-share-wa" href="<?php echo esc_url( $share_wa ); ?>" target="_blank" rel="noopener">
                                    <i class="fab fa-whatsapp" aria-hidden="true"></i> <?php esc_html_e( 'WhatsApp', 'creativewings-core' ); ?>
                                </a>
                                <a class="cw-org-share-btn cw-org-share-fb" href="<?php echo esc_url( $share_fb ); ?>" target="_blank" rel="noopener">
                                    <i class="fab fa-facebook-f" aria-hidden="true"></i> <?php esc_html_e( 'Facebook', 'creativewings-core' ); ?>
                                </a>
                                <a class="cw-org-share-btn cw-org-share-x" href="<?php echo esc_url( $share_tw ); ?>" target="_blank" rel="noopener">
                                    <i class="fab fa-twitter" aria-hidden="true"></i> <?php esc_html_e( 'X', 'creativewings-core' ); ?>
                                </a>
                            </div>
                            <div class="cw-org-share-toast" role="status" aria-live="polite"><?php esc_html_e( 'Copied!', 'creativewings-core' ); ?></div>
                        </div>
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
                                        <img src="<?php echo esc_url( $c['thumb'] ); ?>" alt="<?php echo esc_attr( $c['title'] ); ?>" loading="lazy">
                                    <?php else : ?>
                                        <span class="cw-org-thumb-fallback"><i class="fas fa-image" aria-hidden="true"></i></span>
                                    <?php endif; ?>
                                    <?php if ( $c['cat_label'] !== '' ) : ?>
                                        <span class="cw-org-campaign-cat cw-org-campaign-cat--<?php echo esc_attr( $c['cat_slug'] ); ?>"><?php echo esc_html( $c['cat_label'] ); ?></span>
                                    <?php endif; ?>
                                    <span class="cw-org-campaign-state cw-org-campaign-state--<?php echo esc_attr( $c['state'] ); ?>">
                                        <?php echo esc_html( $c['state'] === 'past' ? __( 'Past', 'creativewings-core' ) : __( 'Active', 'creativewings-core' ) ); ?>
                                    </span>
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
        global $wpdb;
        $placeholders = implode( ',', array_fill( 0, count( $pids ), '%d' ) );
        $sql = "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'product_id' AND meta_value IN ($placeholders)";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$pids ) );
    }

    /**
     * Participants count for a single product_id, memoised per request.
     */
    private function get_participants_for_pid( $pid ) {
        $pid = (int) $pid;
        if ( isset( self::$participants_cache[ $pid ] ) ) {
            return self::$participants_cache[ $pid ];
        }
        global $wpdb;
        $n = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'product_id' AND meta_value = %d",
            $pid
        ) );
        self::$participants_cache[ $pid ] = $n;
        return $n;
    }
}
