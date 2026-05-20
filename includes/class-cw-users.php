<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Users {

    private $portfolio_table = 'jet_cct_creator_portfolio';

    public function __construct() {
        add_filter('woocommerce_account_menu_items', [ $this, 'remove_wc_tabs' ], 99);
        add_filter( 'template_include', [ $this, 'load_public_profile_template' ], 99 );
        add_action('init', [ $this, 'add_profile_rewrite_rule_init' ]);
        add_filter('query_vars', [ $this, 'add_profile_query_vars' ]);
    }

    public function remove_wc_tabs($items) { unset($items['edit-address'], $items['downloads'], $items['orders']); return $items; }
    public function add_profile_rewrite_rule_init() { add_rewrite_rule('^profile/([^/]+)/?$', 'index.php?profile_nickname=$matches[1]', 'top'); }
    public function add_profile_query_vars($vars) { $vars[] = 'profile_nickname'; return $vars; }

    public function load_public_profile_template( $template ) {
        $slug = get_query_var( 'profile_nickname' );
        if ( empty($slug) && preg_match('#^profile/([^/]+)/?#', $_SERVER['REQUEST_URI'], $matches) ) $slug = sanitize_title($matches[1]);
        
        if ( $slug ) {
            $user = get_user_by( 'login', $slug );
            if ( ! $user ) { 
                global $wp_query; $wp_query->set_404(); status_header( 404 ); return get_404_template(); 
            }
            
            $uid = $user->ID;
            $current_user_id = get_current_user_id();
            $cookie_name = 'cw_view_check_' . $uid;

            // TEST MODE: Remove the "$uid !== $current_user_id" check if you are testing alone
            if ( ! isset( $_COOKIE[$cookie_name] ) ) {
                global $wpdb;
                $table_name = $wpdb->prefix . 'cw_profile_views';

                // INSERT INTO DATABASE
                $inserted = $wpdb->insert(
                    $table_name,
                    array(
                        'creator_id' => $uid,
                        'view_date'  => current_time('mysql', 1),
                        'ip_address' => $_SERVER['REMOTE_ADDR']
                    )
                );

                // UPDATE TOTAL META
                $total_views = (int) get_user_meta($uid, 'cw_profile_views', true);
                update_user_meta($uid, 'cw_profile_views', $total_views + 1);

                // Set cookie for 1 hour
                setcookie($cookie_name, '1', time() + 3600, COOKIEPATH, COOKIE_DOMAIN);
            }

            $this->render_public_profile_html( $user ); 
            exit;
        }
        return $template;
    }
    
    
    
    private function get_creator_portfolio_data($uid, $only_public = false) {
        global $wpdb;
        $table = $wpdb->prefix . $this->portfolio_table;

        if ( $only_public ) {
            // Visibility column may not exist on very old installs — guard with a column check.
            $has_visibility = (bool) $wpdb->get_var( $wpdb->prepare(
                "SHOW COLUMNS FROM `{$table}` LIKE %s",
                'visibility'
            ) );
            if ( $has_visibility ) {
                return $wpdb->get_results( $wpdb->prepare(
                    "SELECT * FROM $table WHERE created_by = %d AND (visibility IS NULL OR visibility = '' OR visibility = 'public') ORDER BY _ID DESC",
                    $uid
                ) );
            }
        }

        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE created_by = %d ORDER BY _ID DESC", $uid ) );
    }

    public function render_public_profile_html( $u ) {
        $uid = $u->ID;
        $name     = get_user_meta($uid, 'creator_display_name', true) ?: $u->display_name;
        $tagline  = get_user_meta($uid, 'creator_tagline', true);
        $bio      = get_user_meta($uid, 'creator_bio', true);
        $address  = get_user_meta($uid, 'creator_address', true) ?: 'Global';
        $skills_raw = get_user_meta($uid, 'creator_skills', true);
        $skills   = array_filter(array_map('trim', explode(',', (string) $skills_raw)));
        $views    = (int) get_user_meta($uid, 'cw_profile_views', true);
        $website  = get_user_meta($uid, 'website_url', true);

        $img_meta   = get_user_meta($uid, 'creator_profile_image', true);
        $avatar_url = (is_array($img_meta) && isset($img_meta['url'])) ? $img_meta['url'] : get_avatar_url($uid, ['size' => 200]);

        $hdr_meta   = get_user_meta($uid, 'creator_header_image', true);
        $header_url = (is_array($hdr_meta) && isset($hdr_meta['url'])) ? $hdr_meta['url'] : 'https://creativewings.asia/wp-content/uploads/2025/09/Asset-2@2x.png';

        // Viewer state — needed before we fetch portfolio so we can hide private items from visitors.
        $is_logged_in = is_user_logged_in();
        $is_owner     = $is_logged_in && ( (int) get_current_user_id() === (int) $uid );

        $portfolio_items = $this->get_creator_portfolio_data( $uid, ! $is_owner );
        $project_count   = count((array) $portfolio_items);

        // Build per-category counts for the filter strip (and overall "All" count)
        $cat_counts = [];
        if ($portfolio_items) {
            foreach ($portfolio_items as $item) {
                $c = trim((string) $item->category);
                if ($c === '') continue;
                if (!isset($cat_counts[$c])) $cat_counts[$c] = 0;
                $cat_counts[$c]++;
            }
        }
        $unique_categories = array_keys($cat_counts);
        // Render filter pills only when there are 2+ categories AND 4+ total items.
        $show_filter_strip = ( count($unique_categories) >= 2 ) && ( $project_count >= 4 );

        // Member-since year (graceful fallback if missing/unparseable)
        $user_data         = get_userdata( $uid );
        $member_since_year = '';
        if ( $user_data && ! empty( $user_data->user_registered ) ) {
            $ts = strtotime( $user_data->user_registered );
            if ( $ts ) {
                $member_since_year = date_i18n( 'Y', $ts );
            }
        }

        // Names for OG profile tags
        $first_name = get_user_meta( $uid, 'first_name', true );
        $last_name  = get_user_meta( $uid, 'last_name',  true );

        // Canonical profile URL (used for sharing + OG)
        $profile_url = home_url( '/profile/' . $u->user_login . '/' );

        // Share URLs (pre-encoded server-side)
        $share_subject = sprintf( "Check out %s's creative portfolio", $name );
        $share_wa      = 'https://wa.me/?text=' . rawurlencode( $share_subject . ': ' . $profile_url );
        $share_fb      = 'https://www.facebook.com/sharer/sharer.php?u=' . rawurlencode( $profile_url );
        $share_tw      = 'https://twitter.com/intent/tweet?url=' . rawurlencode( $profile_url )
                         . '&text=' . rawurlencode( $share_subject );

        // Sign-up redirect for logged-out CTA
        $signup_redirect_url = home_url( '/registration?redirect_to=' . rawurlencode( $profile_url ) );

        // ── Open Graph / Twitter card meta (don't fight Yoast/RankMath/AIOSEO)
        $has_seo_plugin = defined( 'WPSEO_VERSION' )
            || class_exists( 'WPSEO_Frontend' )
            || defined( 'RANK_MATH_VERSION' )
            || class_exists( 'RankMath\\Helper' )
            || defined( 'AIOSEO_VERSION' )
            || class_exists( 'AIOSEO\\Plugin\\Common\\Main' );

        if ( ! $has_seo_plugin ) {
            $bio_for_meta = trim( wp_strip_all_tags( (string) $bio ) );
            if ( $bio_for_meta === '' ) { $bio_for_meta = trim( (string) $tagline ); }
            if ( $bio_for_meta === '' ) { $bio_for_meta = sprintf( '%s — creative portfolio on Creative Wings.', $name ); }
            if ( function_exists( 'wp_html_excerpt' ) ) {
                $bio_for_meta = wp_html_excerpt( $bio_for_meta, 160, '…' );
            } elseif ( strlen( $bio_for_meta ) > 160 ) {
                $bio_for_meta = substr( $bio_for_meta, 0, 159 ) . '…';
            }
            $og_image = $avatar_url;
            $og_title = $name . ' — Creative Wings';

            add_action( 'wp_head', function() use ( $og_title, $bio_for_meta, $og_image, $profile_url, $first_name, $last_name, $u ) {
                echo "\n<!-- Creative Wings: public profile social cards -->\n";
                echo '<meta property="og:type" content="profile">' . "\n";
                echo '<meta property="og:title" content="' . esc_attr( $og_title ) . '">' . "\n";
                echo '<meta property="og:description" content="' . esc_attr( $bio_for_meta ) . '">' . "\n";
                echo '<meta property="og:image" content="' . esc_url( $og_image ) . '">' . "\n";
                echo '<meta property="og:url" content="' . esc_url( $profile_url ) . '">' . "\n";
                echo '<meta property="og:site_name" content="Creative Wings">' . "\n";
                if ( $first_name ) { echo '<meta property="profile:first_name" content="' . esc_attr( $first_name ) . '">' . "\n"; }
                if ( $last_name )  { echo '<meta property="profile:last_name" content="'  . esc_attr( $last_name )  . '">' . "\n"; }
                echo '<meta property="profile:username" content="' . esc_attr( $u->user_login ) . '">' . "\n";
                echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
                echo '<meta name="twitter:title" content="' . esc_attr( $og_title ) . '">' . "\n";
                echo '<meta name="twitter:description" content="' . esc_attr( $bio_for_meta ) . '">' . "\n";
                echo '<meta name="twitter:image" content="' . esc_url( $og_image ) . '">' . "\n";
            }, 1 );
        }

        // Title used by themes that render <title> via WP
        add_filter( 'pre_get_document_title', function() use ( $name ) {
            return $name . ' — Creative Wings';
        } );

        // Body marker so we can hide theme page banners that print the title above our content
        add_filter( 'body_class', function( $classes ) {
            $classes[] = 'cw-pub-profile-page';
            return $classes;
        } );

        // Suppress the duplicate-name banner: if any the_title() echo equals the creator's name,
        // return empty. Scoped narrowly to avoid clobbering unrelated titles in widgets.
        add_filter( 'the_title', function( $title ) use ( $name ) {
            if ( trim( (string) $title ) === trim( (string) $name ) ) {
                return '';
            }
            return $title;
        }, 999 );

        // Social-link map (uses brand-coloured circular icons in the sidebar)
        $social_map = [
            'behave_url'    => [ 'icon' => 'fab fa-behance',     'label' => 'Behance',   'net' => 'behance'   ],
            'instagram_url' => [ 'icon' => 'fab fa-instagram',   'label' => 'Instagram', 'net' => 'instagram' ],
            'twitter_url'   => [ 'icon' => 'fab fa-twitter',     'label' => 'Twitter',   'net' => 'twitter'   ],
            'Facebook_url'  => [ 'icon' => 'fab fa-facebook-f',  'label' => 'Facebook',  'net' => 'facebook'  ],
            'linkeden_url'  => [ 'icon' => 'fab fa-linkedin-in', 'label' => 'LinkedIn',  'net' => 'linkedin'  ],
        ];

        get_header();
        ?>
        <style>
            :root { --pub-primary: var(--cw-primary, #006599); --pub-accent: #FE6261; --pub-soft: var(--cw-text-soft, #64748b); }
            *, *::before, *::after { box-sizing: border-box; }
            .cw-pub-wrap { background: #f4f6f9; font-family: 'Inter', sans-serif; min-height: 100vh; padding-bottom: 80px; }

            /* Suppress duplicate theme-rendered page title above our profile */
            body.cw-pub-profile-page .page-header,
            body.cw-pub-profile-page .page-header-inner,
            body.cw-pub-profile-page .entry-header,
            body.cw-pub-profile-page .entry-title,
            body.cw-pub-profile-page .page-title,
            body.cw-pub-profile-page .single-page-title,
            body.cw-pub-profile-page .elementor-page-title,
            body.cw-pub-profile-page .wp-block-post-title,
            body.cw-pub-profile-page .breadcrumbs,
            body.cw-pub-profile-page .site-banner,
            body.cw-pub-profile-page header.page-header { display: none !important; }

            /* ── Hero Banner ── */
            .cw-pub-hero { height: 320px; width: 100%; overflow: hidden; position: relative; background: #1a2035; }
            .cw-pub-hero img { width: 100%; height: 100%; object-fit: cover; opacity: 0.75; }
            .cw-pub-hero-overlay { position: absolute; inset: 0; background: linear-gradient(to bottom, rgba(0,0,0,0.1) 0%, rgba(0,0,0,0.5) 100%); }

            /* ── Profile Identity Card ── */
            .cw-pub-identity-wrap { max-width: 1200px; margin: 0 auto; padding: 0 32px; }
            .cw-pub-identity-card {
                background: #fff; border-radius: 20px; padding: 32px 40px;
                display: flex; align-items: flex-end; gap: 28px; flex-wrap: wrap;
                box-shadow: 0 8px 30px rgba(0,0,0,0.07);
                margin-top: -80px; position: relative; z-index: 10;
            }
            .cw-pub-avatar-wrap { flex-shrink: 0; }
            .cw-pub-avatar { width: 130px; height: 130px; border-radius: 50%; object-fit: cover; border: 5px solid #fff; box-shadow: 0 4px 16px rgba(0,0,0,0.12); }
            .cw-pub-id-info { flex: 1; min-width: 220px; }
            .cw-pub-id-info h1 { font-size: 30px; font-weight: 800; color: #0f172a; margin: 0 0 4px; line-height: 1.2; display: flex; flex-wrap: wrap; align-items: center; gap: 10px; }
            .cw-pub-id-info .tagline { font-size: 16px; color: #64748b; margin: 0 0 6px; }
            .cw-pub-member-since { font-size: 13px; color: var(--pub-soft); margin: 0 0 14px; display: inline-flex; align-items: center; gap: 6px; }
            .cw-pub-member-since i { font-size: 12px; opacity: 0.75; }
            .cw-views-badge {
                display: inline-flex; align-items: center; gap: 6px;
                background: #f1f5f9; color: #475569;
                padding: 4px 10px; border-radius: 50px;
                font-size: 12px; font-weight: 600;
                vertical-align: middle; line-height: 1;
                border: 1px solid #e2e8f0;
            }
            .cw-views-badge i { font-size: 11px; color: #94a3b8; }
            .cw-pub-id-meta { display: flex; flex-wrap: wrap; gap: 18px; font-size: 13px; color: #64748b; }
            .cw-pub-id-meta span { display: flex; align-items: center; gap: 6px; }
            .cw-pub-id-meta i { color: var(--pub-primary); font-size: 13px; }
            .cw-pub-id-meta a { color: inherit; text-decoration: none; }
            .cw-pub-id-meta a:hover { color: var(--pub-primary); }
            .cw-pub-stats { display: flex; gap: 24px; margin-left: auto; flex-shrink: 0; }
            .cw-pub-stat { text-align: center; }
            .cw-pub-stat strong { display: block; font-size: 26px; font-weight: 800; color: #0f172a; }
            .cw-pub-stat span { font-size: 11px; font-weight: 700; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; }

            /* ── Main Two-Column Layout ── */
            .cw-pub-layout { max-width: 1200px; margin: 40px auto 0; padding: 0 32px; display: grid; grid-template-columns: 280px 1fr; gap: 40px; }

            /* ── Sidebar ── */
            .cw-pub-sidebar { display: flex; flex-direction: column; gap: 28px; }
            .cw-pub-sidebar-block { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); position: relative; }
            .cw-pub-sidebar-block h4 { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin: 0 0 14px; }
            .cw-pub-bio-text { font-size: 14px; line-height: 1.75; color: #475569; }
            .cw-pub-skills { display: flex; flex-wrap: wrap; gap: 8px; }
            .cw-pub-skill-chip { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: 600; }

            /* ── Share Profile widget ── */
            .cw-share-row { display: flex; flex-wrap: wrap; gap: 8px; }
            .cw-share-btn {
                display: inline-flex; align-items: center; gap: 6px;
                padding: 8px 14px; border-radius: 50px;
                background: #f1f5f9; color: #334155;
                font-size: 12.5px; font-weight: 600;
                border: 1px solid #e2e8f0;
                text-decoration: none; cursor: pointer;
                transition: transform 0.15s ease, background 0.2s ease, color 0.2s ease, border-color 0.2s ease, box-shadow 0.2s ease;
                line-height: 1; font-family: inherit;
            }
            .cw-share-btn:hover { transform: translateY(-1px); box-shadow: 0 6px 16px rgba(0,0,0,0.08); }
            .cw-share-btn i { font-size: 13px; }
            .cw-share-btn.cw-share-copy:hover { background: var(--pub-primary); color: #fff; border-color: var(--pub-primary); }
            .cw-share-btn.cw-share-wa:hover   { background: #25D366; color: #fff; border-color: #25D366; }
            .cw-share-btn.cw-share-fb:hover   { background: #1877f2; color: #fff; border-color: #1877f2; }
            .cw-share-btn.cw-share-x:hover    { background: #000;    color: #fff; border-color: #000;    }
            .cw-share-toast {
                position: absolute; bottom: 14px; right: 18px;
                background: #0f172a; color: #fff; font-size: 12px; font-weight: 600;
                padding: 6px 12px; border-radius: 50px;
                opacity: 0; transform: translateY(6px); pointer-events: none;
                transition: opacity 0.18s ease, transform 0.18s ease;
            }
            .cw-share-toast.show { opacity: 1; transform: translateY(0); }

            /* ── Contact / Message CTA ── */
            .cw-pub-cta { text-align: center; }
            .cw-pub-cta-headline { font-size: 14px; font-weight: 700; color: #0f172a; margin: 0 0 4px; }
            .cw-pub-cta-sub { font-size: 12.5px; color: var(--pub-soft); margin: 0 0 14px; line-height: 1.5; }
            .cw-pub-cta-btn {
                display: inline-flex; align-items: center; justify-content: center; gap: 8px;
                width: 100%; padding: 11px 16px; border-radius: 50px;
                background: var(--pub-primary); color: #fff;
                font-size: 13px; font-weight: 700; letter-spacing: 0.2px;
                text-decoration: none; border: none; cursor: pointer;
                transition: filter 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
                font-family: inherit; line-height: 1;
            }
            .cw-pub-cta-btn:hover { filter: brightness(1.08); transform: translateY(-1px); box-shadow: 0 8px 20px rgba(0, 101, 153, 0.25); color: #fff; }
            .cw-pub-cta-btn.cw-pub-cta-secondary { background: #fff; color: var(--pub-primary); border: 1.5px solid var(--pub-primary); }
            .cw-pub-cta-btn.cw-pub-cta-secondary:hover { background: var(--pub-primary); color: #fff; }

            /* ── On the Web (brand-coloured circular icons) ── */
            .cw-pub-social-row { display: flex; flex-wrap: wrap; gap: 10px; }
            .cw-pub-social-icon {
                display: inline-flex; align-items: center; justify-content: center;
                width: 40px; height: 40px; min-width: 40px;
                border-radius: 50%;
                background: #f1f5f9; color: #475569;
                text-decoration: none;
                transition: background 0.2s ease, color 0.2s ease, transform 0.2s ease, box-shadow 0.2s ease;
                border: 1px solid #e2e8f0;
            }
            .cw-pub-social-icon i { font-size: 16px; line-height: 1; }
            .cw-pub-social-icon:hover { transform: translateY(-2px); color: #fff; box-shadow: 0 6px 16px rgba(0,0,0,0.12); }
            .cw-pub-social-icon[data-net="behance"]:hover   { background: #1769ff; border-color: #1769ff; }
            .cw-pub-social-icon[data-net="instagram"]:hover { background: linear-gradient(45deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%); border-color: #dc2743; }
            .cw-pub-social-icon[data-net="twitter"]:hover   { background: #000;    border-color: #000;    }
            .cw-pub-social-icon[data-net="facebook"]:hover  { background: #1877f2; border-color: #1877f2; }
            .cw-pub-social-icon[data-net="linkedin"]:hover  { background: #0077b5; border-color: #0077b5; }
            .cw-pub-social-icon[data-net="website"]:hover   { background: var(--pub-primary); border-color: var(--pub-primary); }

            /* ── Portfolio Section ── */
            .cw-pub-portfolio h2 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 20px; }
            .cw-pub-cat-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #e9ecef; }
            .cw-pub-cat-tab { padding: 7px 18px; border-radius: 50px; font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer; transition: all 0.2s; border: 1.5px solid transparent; background: #f1f5f9; }
            .cw-pub-cat-tab:hover { background: #e2e8f0; }
            .cw-pub-cat-tab.active { background: var(--pub-primary); color: #fff; border-color: var(--pub-primary); }
            .cw-pub-cat-count { opacity: 0.7; margin-left: 4px; font-weight: 500; }

            /* ── 3-Column Portfolio Grid ── */
            .cw-pub-pf-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 24px; }
            .cw-pub-pf-card {
                background: #fff; border-radius: 14px; overflow: hidden;
                border: 1px solid #e9ecef; cursor: pointer;
                transition: transform 0.25s ease, box-shadow 0.25s ease;
            }
            .cw-pub-pf-card:hover { transform: translateY(-6px); box-shadow: 0 16px 40px rgba(0,0,0,0.09); }
            .cw-pub-pf-thumb { height: 200px; position: relative; overflow: hidden; background: #f1f5f9; }
            .cw-pub-pf-thumb img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.4s ease; }
            .cw-pub-pf-card:hover .cw-pub-pf-thumb img { transform: scale(1.05); }
            .cw-pub-pf-overlay { position: absolute; inset: 0; background: rgba(0, 101, 153, 0.75); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; }
            .cw-pub-pf-card:hover .cw-pub-pf-overlay { opacity: 1; }
            .cw-pub-pf-overlay span { color: #fff; font-size: 14px; font-weight: 700; letter-spacing: 0.5px; }
            .cw-pub-pf-info { padding: 16px 18px; }
            .cw-pub-pf-cat { font-size: 11px; font-weight: 800; color: var(--pub-primary); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; display: block; }
            .cw-pub-pf-title { font-size: 15px; font-weight: 700; color: #1e293b; margin: 0; line-height: 1.4; }

            /* ── Empty state ── */
            .cw-pub-pf-empty { grid-column: 1 / -1; text-align: center; padding: 64px 20px; color: #64748b; }
            .cw-pub-empty-icon {
                width: 80px; height: 80px; border-radius: 50%;
                background: rgba(0, 101, 153, 0.08);
                color: var(--pub-primary);
                display: inline-flex; align-items: center; justify-content: center;
                margin: 0 auto 18px;
            }
            .cw-pub-empty-icon i { font-size: 32px; line-height: 1; }
            .cw-pub-pf-empty h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 6px; }
            .cw-pub-pf-empty p { font-size: 14px; color: var(--pub-soft); margin: 0 0 18px; }
            .cw-pub-empty-btn {
                display: inline-flex; align-items: center; gap: 8px;
                padding: 10px 22px; border-radius: 50px;
                background: var(--pub-primary); color: #fff;
                font-size: 13px; font-weight: 700;
                text-decoration: none;
                transition: filter 0.2s ease, transform 0.15s ease, box-shadow 0.2s ease;
            }
            .cw-pub-empty-btn:hover { filter: brightness(1.08); transform: translateY(-1px); color: #fff; box-shadow: 0 8px 20px rgba(0, 101, 153, 0.25); }

            /* ── Message-coming-soon modal ── */
            #cw-pub-msg-modal {
                display: none; position: fixed; inset: 0; z-index: 99999;
                background: rgba(10, 15, 25, 0.55);
                align-items: center; justify-content: center;
                padding: 24px; backdrop-filter: blur(4px);
            }
            #cw-pub-msg-modal.open { display: flex; }
            .cw-pub-msg-inner {
                background: #fff; border-radius: 18px;
                max-width: 420px; width: 100%;
                padding: 28px 28px 24px;
                box-shadow: 0 30px 80px rgba(0,0,0,0.25);
                text-align: center;
                animation: pubModalIn 0.22s ease;
            }
            .cw-pub-msg-inner .cw-pub-msg-icon {
                width: 60px; height: 60px; border-radius: 50%;
                background: rgba(0, 101, 153, 0.1); color: var(--pub-primary);
                display: inline-flex; align-items: center; justify-content: center;
                margin: 0 auto 14px;
            }
            .cw-pub-msg-inner .cw-pub-msg-icon i { font-size: 24px; }
            .cw-pub-msg-inner h3 { font-size: 18px; font-weight: 800; color: #0f172a; margin: 0 0 8px; }
            .cw-pub-msg-inner p { font-size: 14px; color: #475569; line-height: 1.6; margin: 0 0 18px; }
            .cw-pub-msg-inner .cw-pub-msg-close { background: var(--pub-primary); color: #fff; border: none; padding: 10px 22px; border-radius: 50px; font-weight: 700; font-size: 13px; cursor: pointer; font-family: inherit; }
            .cw-pub-msg-inner .cw-pub-msg-close:hover { filter: brightness(1.08); }

            /* ── Portfolio View Modal (Behance-style) ── */
            #cw-pub-view-modal {
                display: none; position: fixed; inset: 0; z-index: 99999;
                background: rgba(10, 15, 25, 0.72);
                align-items: center; justify-content: center;
                padding: 24px; backdrop-filter: blur(6px);
            }
            #cw-pub-view-modal.open { display: flex; }
            .cw-pub-modal-inner {
                background: #fff; border-radius: 20px; width: 100%;
                max-width: 840px; max-height: 90vh; overflow-y: auto;
                box-shadow: 0 30px 80px rgba(0,0,0,0.3);
                animation: pubModalIn 0.28s ease;
            }
            @keyframes pubModalIn { from { opacity: 0; transform: translateY(20px) scale(0.97); } to { opacity: 1; transform: none; } }
            .cw-pub-modal-hero { position: relative; height: 340px; background: #1a2035; border-radius: 20px 20px 0 0; overflow: hidden; }
            .cw-pub-modal-hero img { width: 100%; height: 100%; object-fit: cover; }
            .cw-pub-modal-close {
                position: absolute; top: 16px; right: 16px; width: 36px; height: 36px;
                background: rgba(0,0,0,0.5); border: none; border-radius: 50%;
                color: #fff; font-size: 18px; cursor: pointer; display: flex;
                align-items: center; justify-content: center; line-height: 1;
                transition: background 0.2s;
            }
            .cw-pub-modal-close:hover { background: rgba(0,0,0,0.75); }
            .cw-pub-modal-body { padding: 32px 36px 40px; }
            .cw-pub-modal-meta { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
            .cw-pub-modal-cat-badge { background: #e0f2fe; color: #0369a1; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; padding: 4px 12px; border-radius: 50px; }
            .cw-pub-modal-title { font-size: 26px; font-weight: 800; color: #0f172a; margin: 0 0 16px; line-height: 1.3; }
            .cw-pub-modal-desc { font-size: 15px; line-height: 1.75; color: #475569; margin-bottom: 30px; white-space: pre-line; }
            .cw-pub-gallery-head { font-size: 13px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.5px; color: #94a3b8; margin-bottom: 14px; }
            .cw-pub-gallery-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; }
            .cw-pub-gallery-grid img { width: 100%; aspect-ratio: 1; object-fit: cover; border-radius: 10px; cursor: pointer; transition: transform 0.2s; }
            .cw-pub-gallery-grid img:hover { transform: scale(1.03); }

            /* ── Responsive ── */
            @media (max-width: 1024px) {
                .cw-pub-pf-grid { grid-template-columns: repeat(2, 1fr); }
            }
            @media (max-width: 768px) {
                .cw-pub-layout { grid-template-columns: 1fr; }
                .cw-pub-identity-card { flex-direction: column; align-items: flex-start; padding: 24px; }
                .cw-pub-stats { margin-left: 0; }
                .cw-pub-id-info h1 { font-size: 24px; }
                .cw-pub-pf-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
                .cw-pub-modal-inner { max-height: 95vh; }
                .cw-pub-modal-hero { height: 220px; }
                .cw-pub-modal-body { padding: 24px 20px 32px; }
                .cw-pub-gallery-grid { grid-template-columns: repeat(2, 1fr); }
                .cw-share-row { gap: 6px; }
                .cw-share-btn { padding: 8px 12px; font-size: 12px; }
            }
            @media (max-width: 480px) {
                .cw-pub-pf-grid { grid-template-columns: 1fr; }
                .cw-pub-identity-wrap { padding: 0 16px; }
                .cw-pub-layout { padding: 0 16px; }
                .cw-pub-hero { height: 220px; }
                .cw-pub-id-info h1 { font-size: 22px; }
                .cw-share-row { gap: 6px; }
            }
        </style>

        <div class="cw-pub-wrap">

            <!-- Hero Banner -->
            <div class="cw-pub-hero">
                <img src="<?php echo esc_url($header_url); ?>" alt="Cover Photo">
                <div class="cw-pub-hero-overlay"></div>
            </div>

            <!-- Identity Card -->
            <div class="cw-pub-identity-wrap">
                <div class="cw-pub-identity-card">
                    <div class="cw-pub-avatar-wrap">
                        <img src="<?php echo esc_url($avatar_url); ?>" class="cw-pub-avatar" alt="<?php echo esc_attr($name); ?>">
                    </div>
                    <div class="cw-pub-id-info">
                        <h1>
                            <span><?php echo esc_html($name); ?></span>
                            <span class="cw-views-badge" aria-label="<?php echo esc_attr( sprintf( _n( '%s profile view', '%s profile views', $views, 'creativewings-core' ), number_format_i18n( $views ) ) ); ?>">
                                <i class="fas fa-eye" aria-hidden="true"></i>
                                <?php echo esc_html( number_format_i18n( $views ) ); ?> <?php echo esc_html( _n( 'view', 'views', $views, 'creativewings-core' ) ); ?>
                            </span>
                        </h1>
                        <?php if($tagline): ?><p class="tagline"><?php echo esc_html($tagline); ?></p><?php endif; ?>
                        <?php if($member_since_year): ?>
                            <p class="cw-pub-member-since cw-text-soft"><i class="far fa-calendar-alt" aria-hidden="true"></i> <?php echo esc_html( sprintf( __( 'Member since %s', 'creativewings-core' ), $member_since_year ) ); ?></p>
                        <?php endif; ?>
                        <div class="cw-pub-id-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo esc_html($address); ?></span>
                            <?php if($website): ?>
                                <span><i class="fas fa-link"></i> <a href="<?php echo esc_url($website); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html(parse_url($website, PHP_URL_HOST) ?: $website); ?></a></span>
                            <?php endif; ?>
                            <?php if(!empty($skills)): ?>
                                <span><i class="fas fa-palette"></i> <?php echo esc_html(implode(', ', array_slice($skills, 0, 3))); ?><?php echo count($skills) > 3 ? ' +'.( count($skills)-3).' more' : ''; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cw-pub-stats">
                        <div class="cw-pub-stat">
                            <strong><?php echo number_format_i18n($project_count); ?></strong>
                            <span><?php echo esc_html( _n( 'Project', 'Projects', $project_count, 'creativewings-core' ) ); ?></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Two-Column Layout -->
            <div class="cw-pub-layout">

                <!-- Sidebar -->
                <aside class="cw-pub-sidebar">
                    <?php if($bio): ?>
                    <div class="cw-pub-sidebar-block">
                        <h4><?php esc_html_e( 'About', 'creativewings-core' ); ?></h4>
                        <div class="cw-pub-bio-text"><?php echo wp_kses_post($bio); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Share Profile widget -->
                    <div class="cw-pub-sidebar-block cw-share-widget">
                        <h4><?php esc_html_e( 'Share this profile', 'creativewings-core' ); ?></h4>
                        <div class="cw-share-row">
                            <button type="button" class="cw-share-btn cw-share-copy" id="cwShareCopyBtn" data-url="<?php echo esc_attr( $profile_url ); ?>" aria-label="<?php esc_attr_e( 'Copy profile link', 'creativewings-core' ); ?>">
                                <i class="fas fa-link" aria-hidden="true"></i> <?php esc_html_e( 'Copy link', 'creativewings-core' ); ?>
                            </button>
                            <a class="cw-share-btn cw-share-wa" href="<?php echo esc_url( $share_wa ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( __( 'Share %s on WhatsApp', 'creativewings-core' ), $name ) ); ?>">
                                <i class="fab fa-whatsapp" aria-hidden="true"></i> <?php esc_html_e( 'WhatsApp', 'creativewings-core' ); ?>
                            </a>
                            <a class="cw-share-btn cw-share-fb" href="<?php echo esc_url( $share_fb ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( __( 'Share %s on Facebook', 'creativewings-core' ), $name ) ); ?>">
                                <i class="fab fa-facebook-f" aria-hidden="true"></i> <?php esc_html_e( 'Facebook', 'creativewings-core' ); ?>
                            </a>
                            <a class="cw-share-btn cw-share-x" href="<?php echo esc_url( $share_tw ); ?>" target="_blank" rel="noopener" aria-label="<?php echo esc_attr( sprintf( __( 'Share %s on X (Twitter)', 'creativewings-core' ), $name ) ); ?>">
                                <i class="fab fa-twitter" aria-hidden="true"></i> <?php esc_html_e( 'X', 'creativewings-core' ); ?>
                            </a>
                        </div>
                        <div class="cw-share-toast" id="cwShareToast" role="status" aria-live="polite"><?php esc_html_e( 'Copied!', 'creativewings-core' ); ?></div>
                    </div>

                    <?php if ( ! $is_owner ): ?>
                    <!-- Contact / Message CTA -->
                    <div class="cw-pub-sidebar-block cw-pub-cta">
                        <?php if ( ! $is_logged_in ): ?>
                            <p class="cw-pub-cta-headline"><?php echo esc_html( sprintf( __( 'Want to connect with %s?', 'creativewings-core' ), $name ) ); ?></p>
                            <p class="cw-pub-cta-sub"><?php esc_html_e( 'Sign up to send a message and discover more creators.', 'creativewings-core' ); ?></p>
                            <a href="<?php echo esc_url( $signup_redirect_url ); ?>" class="cw-pub-cta-btn">
                                <i class="fas fa-user-plus" aria-hidden="true"></i>
                                <?php echo esc_html( sprintf( __( 'Sign up to message %s', 'creativewings-core' ), $name ) ); ?>
                            </a>
                        <?php else: ?>
                            <p class="cw-pub-cta-headline"><?php echo esc_html( sprintf( __( 'Reach out to %s', 'creativewings-core' ), $name ) ); ?></p>
                            <p class="cw-pub-cta-sub"><?php esc_html_e( 'Direct messaging is coming soon.', 'creativewings-core' ); ?></p>
                            <button type="button" class="cw-pub-cta-btn" onclick="cwPubOpenMsgModal(); return false;" aria-haspopup="dialog">
                                <i class="far fa-paper-plane" aria-hidden="true"></i>
                                <?php esc_html_e( 'Message', 'creativewings-core' ); ?>
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($skills)): ?>
                    <div class="cw-pub-sidebar-block">
                        <h4><?php esc_html_e( 'Focus Areas', 'creativewings-core' ); ?></h4>
                        <div class="cw-pub-skills">
                            <?php foreach($skills as $skill): ?>
                                <span class="cw-pub-skill-chip"><?php echo esc_html($skill); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php
                    $has_social = false;
                    foreach($social_map as $key => $data) { if(get_user_meta($uid, $key, true)) { $has_social = true; break; } }
                    if($has_social): ?>
                    <div class="cw-pub-sidebar-block">
                        <h4><?php esc_html_e( 'On the Web', 'creativewings-core' ); ?></h4>
                        <div class="cw-pub-social-row">
                            <?php foreach($social_map as $meta_key => $data):
                                $link = get_user_meta($uid, $meta_key, true);
                                if(!$link) continue;
                                $aria = sprintf( __( 'Visit %1$s on %2$s', 'creativewings-core' ), $name, $data['label'] );
                            ?>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" rel="noopener noreferrer"
                                   class="cw-pub-social-icon" data-net="<?php echo esc_attr( $data['net'] ); ?>"
                                   aria-label="<?php echo esc_attr( $aria ); ?>" title="<?php echo esc_attr( $data['label'] ); ?>">
                                    <i class="<?php echo esc_attr($data['icon']); ?>" aria-hidden="true"></i>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </aside>

                <!-- Portfolio -->
                <main class="cw-pub-portfolio">
                    <h2><?php esc_html_e( 'Portfolio', 'creativewings-core' ); ?> <span style="font-size:16px; font-weight:500; color:#94a3b8;">(<?php echo esc_html( number_format_i18n( $project_count ) ); ?>)</span></h2>

                    <?php if ( $show_filter_strip ): ?>
                    <div class="cw-pub-cat-tabs cw-filter-group" role="tablist">
                        <button type="button" class="cw-pub-cat-tab cw-filter-btn active" data-category="all" onclick="cwPubFilter('all', this)" role="tab" aria-selected="true">
                            <?php esc_html_e( 'All', 'creativewings-core' ); ?> <span class="cw-pub-cat-count">(<?php echo esc_html( number_format_i18n( $project_count ) ); ?>)</span>
                        </button>
                        <?php foreach( $unique_categories as $cat ): ?>
                            <button type="button" class="cw-pub-cat-tab cw-filter-btn" data-category="<?php echo esc_attr( $cat ); ?>" onclick="cwPubFilter('<?php echo esc_js( $cat ); ?>', this)" role="tab" aria-selected="false">
                                <?php echo esc_html( $cat ); ?> <span class="cw-pub-cat-count">(<?php echo esc_html( number_format_i18n( $cat_counts[ $cat ] ) ); ?>)</span>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <div class="cw-pub-pf-grid" id="cw-pub-pf-grid">
                        <?php if($portfolio_items): foreach($portfolio_items as $item):
                            $p_img_data = maybe_unserialize($item->image);
                            $p_thumb    = (is_array($p_img_data) && isset($p_img_data['url'])) ? $p_img_data['url'] : '';

                            $p_gal_data = maybe_unserialize($item->gallery);
                            $p_gal_urls = [];
                            if (is_array($p_gal_data)) {
                                foreach ($p_gal_data as $g) {
                                    if (isset($g['url'])) $p_gal_urls[] = $g['url'];
                                }
                            }

                            $p_json = htmlspecialchars(json_encode([
                                'title'   => $item->title,
                                'cat'     => $item->category,
                                'desc'    => $item->description,
                                'img'     => $p_thumb,
                                'gallery' => $p_gal_urls,
                            ]), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="cw-pub-pf-card cw-pf-card portfolio-item" data-category="<?php echo esc_attr($item->category); ?>"
                             onclick='cwPubViewProject(<?php echo $p_json; ?>)'>
                            <div class="cw-pub-pf-thumb">
                                <?php if($p_thumb): ?>
                                    <img src="<?php echo esc_url($p_thumb); ?>" alt="<?php echo esc_attr($item->title); ?>" loading="lazy">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image" style="font-size:32px;color:#cbd5e1;"></i></div>
                                <?php endif; ?>
                                <div class="cw-pub-pf-overlay"><span><i class="fas fa-eye" style="margin-right:6px;"></i><?php esc_html_e( 'View Project', 'creativewings-core' ); ?></span></div>
                            </div>
                            <div class="cw-pub-pf-info">
                                <span class="cw-pub-pf-cat"><?php echo esc_html($item->category); ?></span>
                                <h3 class="cw-pub-pf-title"><?php echo esc_html($item->title); ?></h3>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                            <div class="cw-pub-pf-empty">
                                <div class="cw-pub-empty-icon"><i class="fas fa-folder-open" aria-hidden="true"></i></div>
                                <h3><?php esc_html_e( 'No projects yet', 'creativewings-core' ); ?></h3>
                                <?php if ( $is_owner ): ?>
                                    <p><?php esc_html_e( 'Add your first project to get started.', 'creativewings-core' ); ?></p>
                                    <a href="<?php echo esc_url( home_url( '/my-account/?tab=portfolio' ) ); ?>" class="cw-pub-empty-btn">
                                        <i class="fas fa-plus" aria-hidden="true"></i> <?php esc_html_e( 'Add Project', 'creativewings-core' ); ?>
                                    </a>
                                <?php else: ?>
                                    <p><?php esc_html_e( 'Nothing here yet — check back soon.', 'creativewings-core' ); ?></p>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </main>
            </div>
        </div>

        <!-- Portfolio View Modal -->
        <div id="cw-pub-view-modal" role="dialog" aria-modal="true">
            <div class="cw-pub-modal-inner" id="cw-pub-modal-inner">
                <div class="cw-pub-modal-hero" id="cw-pub-modal-hero">
                    <img src="" alt="" id="cw-pub-modal-img">
                    <button class="cw-pub-modal-close" onclick="cwPubCloseModal()" aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
                </div>
                <div class="cw-pub-modal-body">
                    <div class="cw-pub-modal-meta">
                        <span class="cw-pub-modal-cat-badge" id="cw-pub-modal-cat"></span>
                    </div>
                    <h2 class="cw-pub-modal-title" id="cw-pub-modal-title"></h2>
                    <p class="cw-pub-modal-desc" id="cw-pub-modal-desc"></p>
                    <div id="cw-pub-gallery-wrap" style="display:none;">
                        <p class="cw-pub-gallery-head"><?php esc_html_e( 'Gallery', 'creativewings-core' ); ?></p>
                        <div class="cw-pub-gallery-grid" id="cw-pub-gallery-grid"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Message Modal (placeholder until DM feature ships) -->
        <div id="cw-pub-msg-modal" role="dialog" aria-modal="true" aria-labelledby="cwPubMsgTitle">
            <div class="cw-pub-msg-inner">
                <div class="cw-pub-msg-icon"><i class="far fa-paper-plane" aria-hidden="true"></i></div>
                <h3 id="cwPubMsgTitle"><?php esc_html_e( 'Messaging coming soon', 'creativewings-core' ); ?></h3>
                <p><?php esc_html_e( 'Direct messaging is on the way. For now, reach out via the social links above.', 'creativewings-core' ); ?></p>
                <button type="button" class="cw-pub-msg-close" onclick="cwPubCloseMsgModal()"><?php esc_html_e( 'Got it', 'creativewings-core' ); ?></button>
            </div>
        </div>

        <script>
        (function(){
            function cwPubFilter(category, btn) {
                var tabs = document.querySelectorAll('.cw-pub-cat-tab');
                for (var i = 0; i < tabs.length; i++) {
                    tabs[i].classList.remove('active');
                    tabs[i].setAttribute('aria-selected', 'false');
                }
                if (btn) {
                    btn.classList.add('active');
                    btn.setAttribute('aria-selected', 'true');
                }
                var cards = document.querySelectorAll('#cw-pub-pf-grid .cw-pub-pf-card');
                for (var j = 0; j < cards.length; j++) {
                    var card = cards[j];
                    var cardCat = card.getAttribute('data-category') || '';
                    var show = (category === 'all') || (cardCat === category);
                    card.style.display = show ? '' : 'none';
                }
            }
            window.cwPubFilter = cwPubFilter;

            function cwPubViewProject(data) {
                var modal    = document.getElementById('cw-pub-view-modal');
                var heroImg  = document.getElementById('cw-pub-modal-img');
                var heroWrap = document.getElementById('cw-pub-modal-hero');

                document.getElementById('cw-pub-modal-title').textContent = data.title || '';
                document.getElementById('cw-pub-modal-cat').textContent   = data.cat   || '';
                document.getElementById('cw-pub-modal-desc').textContent  = data.desc  || '';

                if (data.img) {
                    heroImg.src = data.img;
                    heroImg.alt = data.title || '';
                    heroWrap.style.display = '';
                } else {
                    heroWrap.style.display = 'none';
                }

                var galWrap = document.getElementById('cw-pub-gallery-wrap');
                var galGrid = document.getElementById('cw-pub-gallery-grid');
                galGrid.innerHTML = '';
                if (data.gallery && data.gallery.length > 0) {
                    data.gallery.forEach(function(url){
                        var img = document.createElement('img');
                        img.src = url; img.loading = 'lazy'; img.alt = data.title || '';
                        img.onclick = function(){ window.open(url, '_blank', 'noopener'); };
                        galGrid.appendChild(img);
                    });
                    galWrap.style.display = '';
                } else {
                    galWrap.style.display = 'none';
                }

                modal.classList.add('open');
                document.body.style.overflow = 'hidden';
            }
            window.cwPubViewProject = cwPubViewProject;

            function cwPubCloseModal() {
                var m = document.getElementById('cw-pub-view-modal');
                if (m) m.classList.remove('open');
                document.body.style.overflow = '';
            }
            window.cwPubCloseModal = cwPubCloseModal;

            var pvModal = document.getElementById('cw-pub-view-modal');
            if (pvModal) {
                pvModal.addEventListener('click', function(e){
                    if (e.target === this) cwPubCloseModal();
                });
            }

            document.addEventListener('keydown', function(e){
                if (e.key === 'Escape') {
                    cwPubCloseModal();
                    cwPubCloseMsgModal();
                }
            });

            // Legacy compat
            window.viewPortfolio = function(data) {
                cwPubViewProject({ title: data.title, cat: data.cat, desc: data.desc, img: data.img, gallery: data.gallery || [] });
            };

            /* ── Share: Copy link with clipboard + execCommand fallback ── */
            function cwPubFallbackCopy(text) {
                var ta = document.createElement('textarea');
                ta.value = text;
                ta.setAttribute('readonly', '');
                ta.style.position = 'fixed';
                ta.style.top = '-1000px';
                ta.style.left = '-1000px';
                ta.style.opacity = '0';
                document.body.appendChild(ta);
                ta.select();
                var ok = false;
                try { ok = document.execCommand('copy'); } catch (err) { ok = false; }
                document.body.removeChild(ta);
                return ok;
            }

            function cwPubShowToast() {
                var t = document.getElementById('cwShareToast');
                if (!t) return;
                t.classList.add('show');
                clearTimeout(t._cwTimer);
                t._cwTimer = setTimeout(function(){ t.classList.remove('show'); }, 1500);
            }

            var copyBtn = document.getElementById('cwShareCopyBtn');
            if (copyBtn) {
                copyBtn.addEventListener('click', function(){
                    var url = this.getAttribute('data-url') || window.location.href;
                    var done = function(){ cwPubShowToast(); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(url).then(done, function(){
                            if (cwPubFallbackCopy(url)) done();
                        });
                    } else if (cwPubFallbackCopy(url)) {
                        done();
                    }
                });
            }

            /* ── Message modal (placeholder) ── */
            function cwPubOpenMsgModal() {
                var m = document.getElementById('cw-pub-msg-modal');
                if (m) {
                    m.classList.add('open');
                    document.body.style.overflow = 'hidden';
                }
            }
            function cwPubCloseMsgModal() {
                var m = document.getElementById('cw-pub-msg-modal');
                if (m) m.classList.remove('open');
                document.body.style.overflow = '';
            }
            window.cwPubOpenMsgModal  = cwPubOpenMsgModal;
            window.cwPubCloseMsgModal = cwPubCloseMsgModal;

            var msgModal = document.getElementById('cw-pub-msg-modal');
            if (msgModal) {
                msgModal.addEventListener('click', function(e){
                    if (e.target === this) cwPubCloseMsgModal();
                });
            }
        })();
        </script>
        <?php
        get_footer();
    }
}