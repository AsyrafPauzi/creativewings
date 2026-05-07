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
    
    
    
    private function get_creator_portfolio_data($uid) {
        global $wpdb;
        $table = $wpdb->prefix . $this->portfolio_table;
        return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE created_by = %d ORDER BY _ID DESC", $uid ) );
    }

    public function render_public_profile_html( $u ) {
        $uid = $u->ID;
        $name     = get_user_meta($uid, 'creator_display_name', true) ?: $u->display_name;
        $tagline  = get_user_meta($uid, 'creator_tagline', true);
        $bio      = get_user_meta($uid, 'creator_bio', true);
        $address  = get_user_meta($uid, 'creator_address', true) ?: 'Global';
        $skills_raw = get_user_meta($uid, 'creator_skills', true);
        $skills   = array_filter(array_map('trim', explode(',', $skills_raw)));
        $views    = (int) get_user_meta($uid, 'cw_profile_views', true);
        $website  = get_user_meta($uid, 'website_url', true);

        $img_meta   = get_user_meta($uid, 'creator_profile_image', true);
        $avatar_url = (is_array($img_meta) && isset($img_meta['url'])) ? $img_meta['url'] : get_avatar_url($uid, ['size' => 200]);

        $hdr_meta   = get_user_meta($uid, 'creator_header_image', true);
        $header_url = (is_array($hdr_meta) && isset($hdr_meta['url'])) ? $hdr_meta['url'] : 'https://creativewings.asia/wp-content/uploads/2025/09/Asset-2@2x.png';

        $portfolio_items = $this->get_creator_portfolio_data($uid);
        $project_count   = count((array) $portfolio_items);

        $dynamic_categories = ['All'];
        if ($portfolio_items) {
            foreach ($portfolio_items as $item) {
                if (!empty($item->category) && !in_array($item->category, $dynamic_categories)) {
                    $dynamic_categories[] = $item->category;
                }
            }
        }

        $social_map = [
            'behave_url'    => ['icon' => 'fab fa-behance',   'label' => 'Behance'],
            'instagram_url' => ['icon' => 'fab fa-instagram', 'label' => 'Instagram'],
            'twitter_url'   => ['icon' => 'fab fa-twitter',   'label' => 'Twitter'],
            'Facebook_url'  => ['icon' => 'fab fa-facebook',  'label' => 'Facebook'],
            'linkeden_url'  => ['icon' => 'fab fa-linkedin',  'label' => 'LinkedIn'],
        ];

        get_header();
        ?>
        <style>
            :root { --pub-primary: #006599; --pub-accent: #FE6261; }
            *, *::before, *::after { box-sizing: border-box; }
            .cw-pub-wrap { background: #f4f6f9; font-family: 'Inter', sans-serif; min-height: 100vh; padding-bottom: 80px; }

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
            .cw-pub-id-info h1 { font-size: 30px; font-weight: 800; color: #0f172a; margin: 0 0 4px; }
            .cw-pub-id-info .tagline { font-size: 16px; color: #64748b; margin: 0 0 14px; }
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
            .cw-pub-sidebar-block { background: #fff; border-radius: 16px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.04); }
            .cw-pub-sidebar-block h4 { font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: #94a3b8; margin: 0 0 14px; }
            .cw-pub-bio-text { font-size: 14px; line-height: 1.75; color: #475569; }
            .cw-pub-skills { display: flex; flex-wrap: wrap; gap: 8px; }
            .cw-pub-skill-chip { background: #f0f9ff; color: #0369a1; border: 1px solid #bae6fd; padding: 5px 12px; border-radius: 50px; font-size: 12px; font-weight: 600; }
            .cw-pub-views-num { font-size: 36px; font-weight: 800; color: #0f172a; }
            .cw-pub-social-row { display: flex; gap: 12px; flex-wrap: wrap; }
            .cw-pub-social-btn { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; color: #475569; font-size: 13px; font-weight: 600; text-decoration: none; transition: all 0.2s; }
            .cw-pub-social-btn:hover { border-color: var(--pub-primary); color: var(--pub-primary); background: #f0f9ff; transform: translateY(-1px); }
            .cw-pub-social-btn i { font-size: 14px; }

            /* ── Portfolio Section ── */
            .cw-pub-portfolio h2 { font-size: 24px; font-weight: 800; color: #0f172a; margin: 0 0 20px; }
            .cw-pub-cat-tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 28px; padding-bottom: 20px; border-bottom: 1px solid #e9ecef; }
            .cw-pub-cat-tab { padding: 7px 18px; border-radius: 50px; font-size: 13px; font-weight: 600; color: #64748b; cursor: pointer; transition: all 0.2s; border: 1.5px solid transparent; background: #f1f5f9; }
            .cw-pub-cat-tab:hover { background: #e2e8f0; }
            .cw-pub-cat-tab.active { background: var(--pub-primary); color: #fff; border-color: var(--pub-primary); }

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
            .cw-pub-pf-empty { grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: #94a3b8; }
            .cw-pub-pf-empty i { font-size: 40px; margin-bottom: 12px; display: block; }

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
                .cw-pub-identity-card { flex-direction: column; align-items: flex-start; }
                .cw-pub-stats { margin-left: 0; }
                .cw-pub-pf-grid { grid-template-columns: repeat(2, 1fr); gap: 16px; }
                .cw-pub-modal-inner { max-height: 95vh; }
                .cw-pub-modal-hero { height: 220px; }
                .cw-pub-modal-body { padding: 24px 20px 32px; }
                .cw-pub-gallery-grid { grid-template-columns: repeat(2, 1fr); }
            }
            @media (max-width: 480px) {
                .cw-pub-pf-grid { grid-template-columns: 1fr; }
                .cw-pub-identity-wrap { padding: 0 16px; }
                .cw-pub-layout { padding: 0 16px; }
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
                        <h1><?php echo esc_html($name); ?></h1>
                        <?php if($tagline): ?><p class="tagline"><?php echo esc_html($tagline); ?></p><?php endif; ?>
                        <div class="cw-pub-id-meta">
                            <span><i class="fas fa-map-marker-alt"></i> <?php echo esc_html($address); ?></span>
                            <?php if($website): ?>
                                <span><i class="fas fa-link"></i> <a href="<?php echo esc_url($website); ?>" target="_blank"><?php echo esc_html(parse_url($website, PHP_URL_HOST) ?: $website); ?></a></span>
                            <?php endif; ?>
                            <?php if(!empty($skills)): ?>
                                <span><i class="fas fa-palette"></i> <?php echo esc_html(implode(', ', array_slice($skills, 0, 3))); ?><?php echo count($skills) > 3 ? ' +'.( count($skills)-3).' more' : ''; ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="cw-pub-stats">
                        <div class="cw-pub-stat">
                            <strong><?php echo number_format($project_count); ?></strong>
                            <span>Projects</span>
                        </div>
                        <div class="cw-pub-stat">
                            <strong><?php echo number_format($views); ?></strong>
                            <span>Views</span>
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
                        <h4>About</h4>
                        <div class="cw-pub-bio-text"><?php echo wp_kses_post($bio); ?></div>
                    </div>
                    <?php endif; ?>

                    <?php if(!empty($skills)): ?>
                    <div class="cw-pub-sidebar-block">
                        <h4>Focus Areas</h4>
                        <div class="cw-pub-skills">
                            <?php foreach($skills as $skill): ?>
                                <span class="cw-pub-skill-chip"><?php echo esc_html($skill); ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="cw-pub-sidebar-block">
                        <h4>Profile Views</h4>
                        <div class="cw-pub-views-num"><?php echo number_format($views); ?></div>
                    </div>

                    <?php
                    $has_social = false;
                    foreach($social_map as $key => $data) { if(get_user_meta($uid, $key, true)) { $has_social = true; break; } }
                    if($has_social): ?>
                    <div class="cw-pub-sidebar-block">
                        <h4>On the Web</h4>
                        <div class="cw-pub-social-row">
                            <?php foreach($social_map as $meta_key => $data): $link = get_user_meta($uid, $meta_key, true); if($link): ?>
                                <a href="<?php echo esc_url($link); ?>" target="_blank" class="cw-pub-social-btn">
                                    <i class="<?php echo esc_attr($data['icon']); ?>"></i> <?php echo esc_html($data['label']); ?>
                                </a>
                            <?php endif; endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </aside>

                <!-- Portfolio -->
                <main class="cw-pub-portfolio">
                    <h2>Portfolio <span style="font-size:16px; font-weight:500; color:#94a3b8;">(<?php echo $project_count; ?> projects)</span></h2>

                    <?php if(count($dynamic_categories) > 1): ?>
                    <div class="cw-pub-cat-tabs">
                        <?php foreach($dynamic_categories as $cat): ?>
                            <div class="cw-pub-cat-tab <?php echo $cat === 'All' ? 'active' : ''; ?>"
                                 onclick="cwPubFilter('<?php echo esc_js($cat); ?>', this)">
                                <?php echo esc_html($cat); ?>
                            </div>
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
                        <div class="cw-pub-pf-card portfolio-item" data-cat="<?php echo esc_attr($item->category); ?>"
                             onclick='cwPubViewProject(<?php echo $p_json; ?>)'>
                            <div class="cw-pub-pf-thumb">
                                <?php if($p_thumb): ?>
                                    <img src="<?php echo esc_url($p_thumb); ?>" alt="<?php echo esc_attr($item->title); ?>" loading="lazy">
                                <?php else: ?>
                                    <div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;"><i class="fas fa-image" style="font-size:32px;color:#cbd5e1;"></i></div>
                                <?php endif; ?>
                                <div class="cw-pub-pf-overlay"><span><i class="fas fa-eye" style="margin-right:6px;"></i>View Project</span></div>
                            </div>
                            <div class="cw-pub-pf-info">
                                <span class="cw-pub-pf-cat"><?php echo esc_html($item->category); ?></span>
                                <h3 class="cw-pub-pf-title"><?php echo esc_html($item->title); ?></h3>
                            </div>
                        </div>
                        <?php endforeach; else: ?>
                            <div class="cw-pub-pf-empty">
                                <i class="fas fa-folder-open"></i>
                                <p>No projects have been added yet.</p>
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
                    <button class="cw-pub-modal-close" onclick="cwPubCloseModal()" aria-label="Close">&times;</button>
                </div>
                <div class="cw-pub-modal-body">
                    <div class="cw-pub-modal-meta">
                        <span class="cw-pub-modal-cat-badge" id="cw-pub-modal-cat"></span>
                    </div>
                    <h2 class="cw-pub-modal-title" id="cw-pub-modal-title"></h2>
                    <p class="cw-pub-modal-desc" id="cw-pub-modal-desc"></p>
                    <div id="cw-pub-gallery-wrap" style="display:none;">
                        <p class="cw-pub-gallery-head">Gallery</p>
                        <div class="cw-pub-gallery-grid" id="cw-pub-gallery-grid"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function cwPubFilter(category, btn) {
            document.querySelectorAll('.cw-pub-cat-tab').forEach(t => t.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('#cw-pub-pf-grid .portfolio-item').forEach(card => {
                const show = (category === 'All' || card.dataset.cat === category);
                card.style.display = show ? '' : 'none';
            });
        }

        function cwPubViewProject(data) {
            const modal    = document.getElementById('cw-pub-view-modal');
            const heroImg  = document.getElementById('cw-pub-modal-img');
            const heroWrap = document.getElementById('cw-pub-modal-hero');

            document.getElementById('cw-pub-modal-title').textContent = data.title || '';
            document.getElementById('cw-pub-modal-cat').textContent   = data.cat   || '';
            document.getElementById('cw-pub-modal-desc').textContent  = data.desc  || '';

            if (data.img) {
                heroImg.src = data.img;
                heroImg.alt = data.title;
                heroWrap.style.display = '';
            } else {
                heroWrap.style.display = 'none';
            }

            const galWrap = document.getElementById('cw-pub-gallery-wrap');
            const galGrid = document.getElementById('cw-pub-gallery-grid');
            galGrid.innerHTML = '';
            if (data.gallery && data.gallery.length > 0) {
                data.gallery.forEach(url => {
                    const img = document.createElement('img');
                    img.src = url; img.loading = 'lazy'; img.alt = data.title;
                    img.onclick = () => window.open(url, '_blank');
                    galGrid.appendChild(img);
                });
                galWrap.style.display = '';
            } else {
                galWrap.style.display = 'none';
            }

            modal.classList.add('open');
            document.body.style.overflow = 'hidden';
        }

        function cwPubCloseModal() {
            document.getElementById('cw-pub-view-modal').classList.remove('open');
            document.body.style.overflow = '';
        }

        document.getElementById('cw-pub-view-modal').addEventListener('click', function(e) {
            if (e.target === this) cwPubCloseModal();
        });

        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cwPubCloseModal();
        });

        // Legacy compat: if anything still calls viewPortfolio(data)
        function viewPortfolio(data) {
            cwPubViewProject({ title: data.title, cat: data.cat, desc: data.desc, img: data.img, gallery: data.gallery || [] });
        }
        </script>
        <?php
        get_footer();
    }
}