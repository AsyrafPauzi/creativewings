<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Shortcodes {

    public function __construct() {
        // 1. Organizer Details
        add_shortcode('display_organizer_name', [ $this, 'organizer_name' ]);
        add_shortcode('display_organizer_phone', [ $this, 'organizer_phone' ]);
        add_shortcode('display_organizer_country', [ $this, 'organizer_country' ]);
        add_shortcode('display_organizer_email', [ $this, 'organizer_email' ]);

        // 2. Homepage Stats
        add_shortcode('product_count', [ $this, 'count_products' ]);
        add_shortcode('total_prize_money', [ $this, 'total_prize_money' ]);

        // 3. Search Form
        add_shortcode('custom_search_form', [ $this, 'render_search_form' ]);
        add_action('pre_get_posts', [ $this, 'search_query_filter' ]);

        // 4. Utilities
        add_shortcode('save_competition_button', [ $this, 'save_button' ]);
        add_shortcode('my_saved_competitions', [ $this, 'saved_grid' ]);
        add_shortcode('event_pdfs', [ $this, 'render_pdfs' ]);
        add_shortcode('current_page_redirect_param', [ $this, 'redirect_param' ]);
        
        // 5. Campaign Data (Elementor Helpers)
        add_shortcode('cw_campaign_start', function(){ $d=get_post_meta(get_the_ID(),'cw_submission_start',true); return $d?date('d M Y',strtotime($d)):''; });
        add_shortcode('cw_campaign_deadline', function(){ $d=get_post_meta(get_the_ID(),'submission_deadline',true); return $d?date('d M Y',strtotime($d)):''; });
        add_shortcode('cw_review_date', function(){ $d=get_post_meta(get_the_ID(),'cw_review_start',true); return $d?date('d M Y',strtotime($d)):''; });
        add_shortcode('cw_event_date', function(){ $d=get_post_meta(get_the_ID(),'cw_final_event_date',true); return $d?date('d M Y',strtotime($d)):''; });
        
        // Location Logic: Shows "Online Event" if online, otherwise address
        add_shortcode('cw_location', function(){ 
            $mode = get_post_meta(get_the_ID(), 'cw_event_mode', true);
            if($mode === 'online') return 'Online';
            return get_post_meta(get_the_ID(), 'cw_location_details', true); 
        });

        add_shortcode('cw_is_voting_active', function(){ return get_post_meta(get_the_ID(),'cw_enable_voting',true)==='yes'?'yes':'no'; });
        add_shortcode('cw_campaign_prizes', function(){ return get_post_meta(get_the_ID(),'cw_total_prize_value',true); });
        add_shortcode('cw_judging_criteria', function(){ return wpautop(get_post_meta(get_the_ID(),'cw_judging_criteria',true)); });
        add_shortcode('cw_speaker', function(){ return get_post_meta(get_the_ID(),'cw_talk_speaker',true); });

        // 6. Visual Blocks
        add_shortcode('cw_event_details', [ $this, 'event_details' ]);
        add_shortcode('cw_event_timeline', [ $this, 'event_timeline' ]);
        add_shortcode('cw_sdg_display', [ $this, 'sdg_display' ]);

        // 8. Public Event Grids
        add_shortcode('cw_activities_grid',   [ $this, 'render_activities_grid' ]);
        add_shortcode('cw_competitions_grid', [ $this, 'render_competitions_grid' ]);
        add_shortcode('cw_events_grid',       [ $this, 'render_events_grid' ]);

        // 9. Full Event Detail Page
        add_shortcode('cw_event_detail', [ $this, 'render_event_detail' ]);
        
        // NEW: FAQs and Prizes List
        add_shortcode('cw_faq', [ $this, 'render_faq' ]);
        add_shortcode('cw_prizes_list', [ $this, 'render_prizes_list' ]);

        // NEW: Secure Link Button
        add_shortcode('cw_secure_link_button', [ $this, 'render_secure_link' ]);

        // 7. System
        add_filter('query_vars', function($v){ $v[]='profile_nickname'; $v[]='cw_q'; $v[]='product_category'; return $v; });
        add_filter('date_i18n', function($d,$f,$t,$g){return str_replace('UTC','GMT',$d);},10,4);

        // Prevent WordPress treating ?cw_q= as a search (avoids 404 on activity/competition pages)
        add_action('pre_get_posts', function( $query ) {
            if ( is_admin() || ! $query->is_main_query() ) return;
            if ( isset( $_GET['cw_q'] ) ) {
                $query->set( 'is_search', false );
                $query->is_search = false;
            }
        });

        // Inject title-only search for _cw_search query arg used by event grid shortcodes
        add_filter('posts_where', function( $where, $q ) {
            global $wpdb;
            $term = $q->get('_cw_search');
            if ( $term ) {
                $like  = '%' . $wpdb->esc_like( $term ) . '%';
                $where .= $wpdb->prepare( " AND {$wpdb->posts}.post_title LIKE %s", $like );
            }
            return $where;
        }, 10, 2);
    }

    /* ==========================================================================
       1. SECURE CONTENT (Online Link)
       ========================================================================== */
    public function render_secure_link() {
        if ( ! is_user_logged_in() ) return '';
        $pid = get_the_ID();
        $user_id = get_current_user_id();
        $user = get_userdata( $user_id );
        if ( ! $user ) return '';

        $is_author = ( (int) get_post_field( 'post_author', $pid ) === $user_id );
        $has_bought = function_exists( 'wc_customer_bought_product' )
            ? wc_customer_bought_product( $user->user_email, $user_id, $pid )
            : false;

        if ( $is_author || $has_bought ) {
            $link = get_post_meta( $pid, 'cw_online_link', true );
            if ( $link ) {
                return '<a href="'.esc_url($link).'" target="_blank" class="cw-btn-pink"><i class="fa-solid fa-video"></i> Join Online</a>';
            }
        }
        return '';
    }

    /* ==========================================================================
       2. REPEATER DISPLAYS (FAQ & Prizes)
       ========================================================================== */
    public function render_faq() {
        $data = get_post_meta( get_the_ID(), 'faq', true );
        if(isset($data[0])) $data = $data[0]; // Unwrap
        
        if ( empty($data) || ! is_array($data) ) return '';

        ob_start();
        echo '<div class="cw-faq-wrapper">';
        foreach ( $data as $item ) {
            if ( ! empty($item['question']) ) {
                echo '<details class="cw-faq-item">';
                echo '<summary>'.esc_html($item['question']).' <i class="fa-solid fa-chevron-down"></i></summary>';
                echo '<div class="cw-faq-content">'.wpautop(esc_html($item['answer'])).'</div>';
                echo '</details>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    public function render_prizes_list() {
        $data = get_post_meta( get_the_ID(), 'prizes', true );
        if(isset($data[0])) $data = $data[0];

        if ( empty($data) || ! is_array($data) ) return '';

        ob_start();
        echo '<div class="cw-prizes-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:20px;">';
        foreach ( $data as $item ) {
            if ( ! empty($item['prize_title']) ) {
                echo '<div class="cw-prize-card" style="background:#f9f9f9; padding:20px; border-radius:8px; border:1px solid #eee; text-align:center;">';
                echo '<i class="fa-solid fa-trophy" style="font-size:24px; color:#f1c40f; margin-bottom:10px;"></i>';
                echo '<h4 style="margin:0 0 5px; color:#333;">'.esc_html($item['prize_title']).'</h4>';
                echo '<p style="margin:0; font-size:14px; color:#666;">'.esc_html($item['prize_description']).'</p>';
                echo '</div>';
            }
        }
        echo '</div>';
        return ob_get_clean();
    }

    /* ==========================================================================
       3. VISUALS
       ========================================================================== */
    public function event_details() {
        $pid = get_the_ID(); if ( ! $pid ) return;
        $product = wc_get_product($pid); if ( ! $product ) return;

        $start = get_post_meta($pid, 'cw_submission_start', true);
        $min = (int) get_post_meta($pid, 'cw_min_participants', true) ?: 1;
        $date = $start ? date('j M Y', strtotime($start)) : '-';
        $time = $start ? date('g:i A', strtotime($start)) : '-';
        
        $price = $product->get_price();
        $fee_text = ($price > 0) ? wc_price($price) . ($min>1 ? ' per team' : ' per entry') : 'Free';

        ob_start();
        ?>
        <div class="cw-event-details-box">
            <p><i class="fa-solid fa-calendar-days"></i> <?php echo esc_html($date); ?></p>
            <p><i class="fa-solid fa-clock"></i> <?php echo esc_html($time); ?></p>
            <p><i class="fa-solid fa-tag"></i> <?php echo wp_kses_post($fee_text); ?></p>
        </div>
        <?php
        return ob_get_clean();
    }

    public function event_timeline() {
        $pid = get_the_ID();
        $sub_open  = get_post_meta($pid, 'cw_submission_start', true);
        $sub_close = get_post_meta($pid, 'submission_deadline', true);
        $review    = get_post_meta($pid, 'cw_review_start', true);
        $final     = get_post_meta($pid, 'cw_final_event_date', true);

        ob_start();
        ?>
        <div class="cw-timeline-box">
            <h3>Timeline</h3>
            <?php if($sub_open): ?><p class="cw-timeline-item cw-open"><span class="dot" style="background:#8bc34a;"></span>Submission Open<br><strong><?php echo date('d M Y', strtotime($sub_open)); ?></strong></p><?php endif; ?>
            <?php if($sub_close): ?><p class="cw-timeline-item cw-close"><span class="dot" style="background:#f44336;"></span>Deadline<br><strong><?php echo date('d M Y', strtotime($sub_close)); ?></strong></p><?php endif; ?>
            <?php if($review): ?><p class="cw-timeline-item"><span class="dot" style="background:#f39c12;"></span>Review<br><strong><?php echo date('d M Y', strtotime($review)); ?></strong></p><?php endif; ?>
            <?php if($final): ?><p class="cw-timeline-item"><span class="dot" style="background:#555;"></span>Campaign Date<br><strong><?php echo date('d M Y', strtotime($final)); ?></strong></p><?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    public function sdg_display() {
        $pid = get_the_ID();
        $goals = get_post_meta($pid, 'sdg_goals', true); 
        if(isset($goals[0])) $goals = $goals[0];
        if ( empty($goals) || ! is_array($goals) ) return '';
        
        $base_url = 'https://creativewings.asia/wp-content/uploads/2025/12/';
        $map = ['No Poverty'=>1, 'Zero Hunger'=>2, 'Good Health and Well-Being'=>3, 'Quality Education'=>4, 'Gender Equality'=>5, 'Clean Water and Sanitation'=>6, 'Affordable and Clean Energy'=>7, 'Decent Work and Economic Growth'=>8, 'Industry, Innovation, and Infrastructure'=>9, 'Reduced Inequalities'=>10, 'Sustainable Cities and Communities'=>11, 'Responsible Consumption and Production'=>12, 'Climate Action'=>13, 'Life Below Water'=>14, 'Life on Land'=>15, 'Peace, Justice, and Strong Institutions'=>16, 'Partnerships for the Goals'=>17];

        ob_start();
        ?>
        <div class="cw-sdg-display-box">
            <div class="cw-sdg-list">
                <?php foreach ($goals as $name => $val): 
                    if ( $val === 'true' && isset($map[$name]) ) {
                        $num = $map[$name];
                        $pad_num = str_pad($num, 2, '0', STR_PAD_LEFT);
                        echo '<div class="cw-sdg-item" title="'.esc_attr($name).'"><img src="'.esc_url($base_url . 'E_WEB_' . $pad_num . '.png').'" alt="SDG"></div>';
                    }
                endforeach; ?>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       4. STANDARD HELPERS (Stats, Search, Utils)
       ========================================================================== */
    public function organizer_name() { return esc_html($this->get_org_meta('business_name')); }
    private function get_org_meta($key) { 
        $oid = get_post_meta(get_the_ID(), 'organizer_id', true); 
        
        if ($key === 'email_address') { 
            $u = get_userdata($oid); 
            return $u ? $u->user_email : 'N/A'; 
        } 
        
        // Use 'business_phone' and 'business_address' which are saved in CW_Business_Save
        if ($key === 'phone_number') $key = 'business_phone'; 
        if ($key === 'country_of_operation') $key = 'business_address'; // Redirect Country to show Address

        return $oid ? get_user_meta($oid, $key, true) : 'N/A'; 
    }public function organizer_phone() { return esc_html($this->get_org_meta('business_phone')); }
     public function organizer_address() { return esc_html($this->get_org_meta('business_address')); }
    public function organizer_country() { return esc_html($this->get_org_meta('country_of_operation')); }
    public function organizer_email() { return esc_html($this->get_org_meta('email_address')); }

    public function count_products() { $c = wp_count_posts('product'); return $c->publish; }
    
    public function total_prize_money() {
        if ( false === ( $total = get_transient( 'cw_total_prize_money_v3' ) ) ) {
            global $wpdb;
            $hpos_table = $wpdb->prefix . 'wc_orders';
            if($wpdb->get_var("SHOW TABLES LIKE '$hpos_table'") === $hpos_table) {
                $sql = "SELECT SUM(total_amount) FROM $hpos_table WHERE status IN ('completed', 'processing', 'wc-completed', 'wc-processing') AND type = 'shop_order'";
            } else {
                $sql = "SELECT SUM(pm.meta_value) FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE p.post_type = 'shop_order' AND p.post_status IN ('wc-completed', 'wc-processing') AND pm.meta_key = '_order_total'";
            }
            $total = $wpdb->get_var($sql) ?: 0;
            set_transient( 'cw_total_prize_money_v3', $total, HOUR_IN_SECONDS );
        }
        return number_format( floatval($total), 2 );
    }

    /* ==========================================================================
       3. SEARCH FORM — Redesigned, parent-only categories, uses ?cw_q= param
       ========================================================================== */
    public function render_search_form() {
        // Only top-level (parent) product categories
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => true,
            'parent'     => 0,
        ]);

        $cw_search_val = sanitize_text_field( $_GET['cw_q'] ?? '' );
        $cw_active_cat = sanitize_text_field( $_GET['product_category'] ?? ($_GET['tab'] ?? '') );

        // Category icon map for the pill buttons
        $cat_icons = [
            'activities'   => 'fa-calendar-check',
            'competitions' => 'fa-trophy',
            'talk-seminar' => 'fa-microphone-alt',
        ];

        ob_start();
        ?>
        <div class="cws-wrap">
            <form class="cws-form" id="cw_main_search_form" role="search">

                <!-- Category pill buttons -->
                <div class="cws-cats">
                    <button type="button" class="cws-cat-btn <?php echo ! $cw_active_cat ? 'active' : ''; ?>" data-slug="">
                        <i class="fas fa-th"></i> All
                    </button>
                    <?php if ( $categories && ! is_wp_error( $categories ) ): foreach ( $categories as $cat ):
                        $icon = $cat_icons[ $cat->slug ] ?? 'fa-tag';
                        $is_active = ( $cw_active_cat === $cat->slug );
                    ?>
                    <button type="button" class="cws-cat-btn <?php echo $is_active ? 'active' : ''; ?>" data-slug="<?php echo esc_attr( $cat->slug ); ?>">
                        <i class="fas <?php echo esc_attr( $icon ); ?>"></i>
                        <?php echo esc_html( $cat->name ); ?>
                    </button>
                    <?php endforeach; endif; ?>
                </div>

                <!-- Search input row -->
                <div class="cws-input-row">
                    <div class="cws-input-wrap">
                        <i class="fas fa-search cws-input-icon"></i>
                        <input type="text" id="cw_search_input" class="cws-input"
                               placeholder="Search campaigns, competitions, activities…"
                               value="<?php echo esc_attr( $cw_search_val ); ?>" autocomplete="off">
                    </div>
                    <button type="submit" class="cws-submit">
                        <i class="fas fa-search"></i>
                        <span>Search</span>
                    </button>
                </div>

            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const form   = document.getElementById('cw_main_search_form');
            const input  = document.getElementById('cw_search_input');
            const catBtns = form.querySelectorAll('.cws-cat-btn');
            let activeCat = '<?php echo esc_js( $cw_active_cat ); ?>';

            <?php
            $fn_tree = function( $slug ) {
                $t = get_term_by('slug', $slug, 'product_cat');
                $out = [ $slug ];
                if ( $t ) foreach ( get_term_children( $t->term_id, 'product_cat' ) as $cid ) {
                    $ct = get_term( $cid, 'product_cat' );
                    if ( $ct && ! is_wp_error($ct) ) $out[] = $ct->slug;
                }
                return $out;
            };
            $comp_slugs = $fn_tree('competitions');
            echo 'const compSlugs = ' . json_encode( array_values($comp_slugs) ) . ';';
            echo 'const siteUrl = "' . home_url() . '";';
            ?>

            // Toggle active category button
            catBtns.forEach(function(btn) {
                btn.addEventListener('click', function() {
                    catBtns.forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    activeCat = btn.dataset.slug;
                });
            });

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                const keyword = input.value.trim();

                // Determine destination
                const targetUrl = compSlugs.includes(activeCat)
                    ? siteUrl + '/competitions/'
                    : siteUrl + '/activities/';

                const params = new URLSearchParams();
                if (keyword)   params.append('cw_q', keyword);            // custom param — avoids WP 404
                if (activeCat) params.append('product_category', activeCat);

                const qs = params.toString();
                window.location.href = qs ? targetUrl + '?' + qs : targetUrl;
            });
        });
        </script>

        <style>
        /* ── Search Form (cws-) ── */
        .cws-wrap { width: 100%; max-width: 760px; margin: 0 auto; }
        .cws-form { background: rgba(255,255,255,0.12); backdrop-filter: blur(12px); border: 1px solid rgba(255,255,255,0.25); border-radius: 20px; padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; box-shadow: 0 8px 32px rgba(0,0,0,0.15); }

        /* Category pills */
        .cws-cats { display: flex; flex-wrap: wrap; gap: 8px; }
        .cws-cat-btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 50px; font-size: 13px; font-weight: 600; border: 1.5px solid rgba(255,255,255,0.4); background: rgba(255,255,255,0.15); color: #fff; cursor: pointer; transition: all 0.2s; white-space: nowrap; }
        .cws-cat-btn:hover { background: rgba(255,255,255,0.3); }
        .cws-cat-btn.active { background: #fff; color: #006599; border-color: #fff; box-shadow: 0 3px 10px rgba(0,0,0,0.15); }
        .cws-cat-btn i { font-size: 12px; }

        /* Input row */
        .cws-input-row { display: flex; gap: 10px; align-items: center; }
        .cws-input-wrap { flex: 1; position: relative; }
        .cws-input-icon { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: rgba(255,255,255,0.6); font-size: 15px; pointer-events: none; }
        .cws-input { width: 100%; padding: 13px 16px 13px 44px; border-radius: 12px; border: 1.5px solid rgba(255,255,255,0.3); background: rgba(255,255,255,0.18); color: #fff; font-size: 15px; font-family: inherit; outline: none; transition: border-color 0.2s; box-sizing: border-box; }
        .cws-input::placeholder { color: rgba(255,255,255,0.6); }
        .cws-input:focus { border-color: rgba(255,255,255,0.7); background: rgba(255,255,255,0.25); }
        .cws-submit { display: inline-flex; align-items: center; gap: 8px; padding: 13px 24px; border-radius: 12px; background: #fff; color: #006599; font-size: 14px; font-weight: 700; border: none; cursor: pointer; white-space: nowrap; transition: all 0.2s; font-family: inherit; flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .cws-submit:hover { background: #f0f9ff; transform: translateY(-1px); }

        /* Mobile */
        @media (max-width: 600px) {
            .cws-form { padding: 14px 14px; gap: 12px; border-radius: 16px; }
            .cws-input-row { flex-direction: column; }
            .cws-input-wrap { width: 100%; }
            .cws-submit { width: 100%; justify-content: center; }
            .cws-cat-btn { font-size: 12px; padding: 7px 13px; }
        }
        </style>
        <?php
        return ob_get_clean();
    }

    public function search_query_filter($query) {
        // Handled by pre_get_posts in constructor
    }

    public function save_button() {
        if (!is_user_logged_in()) return '<p class="cw-login-req">Log in to save.</p>';
        $uid = get_current_user_id(); $pid = get_the_ID();
        $saved = get_user_meta($uid, 'saved_competitions', true) ?: [];
        $is_saved = false; if(!empty($saved)){ $ids = array_column($saved, 'competition_id'); if(in_array($pid, $ids))$is_saved=true; }
        $lbl = $is_saved ? 'Remove' : 'Save'; $act = $is_saved ? 'remove' : 'save'; $cls = $is_saved ? 'btn-saved' : 'btn-not-saved';
        ob_start(); ?>
        <button class="cw-save-btn <?php echo $cls; ?>" data-id="<?php echo $pid; ?>" data-act="<?php echo $act; ?>"><i class="fa-regular fa-bookmark"></i> <?php echo $lbl; ?></button>
        <script>jQuery(document).ready(function($){$('.cw-save-btn').on('click',function(e){e.preventDefault();var btn=$(this);btn.prop('disabled',true).css('opacity',0.6);$.post(cw_vars.ajax_url,{action:'handle_save_competition',competition_id:btn.data('id'),task:btn.data('act'),security:cw_vars.nonce},function(res){btn.prop('disabled',false).css('opacity',1);if(res.success){if(btn.data('act')==='save'){btn.data('act','remove').text('Remove').addClass('btn-saved');}else{btn.data('act','save').text('Save').removeClass('btn-saved');}}});});});</script>
        <?php return ob_get_clean();
    }

    public function saved_grid() {
        if (!is_user_logged_in()) return '<p>Please log in.</p>';
        $saved = get_user_meta(get_current_user_id(), 'saved_competitions', true);
        if (empty($saved)) return '<p>No saved items.</p>';
        ob_start(); echo '<div class="cw-grid-container" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:20px;">';
        foreach($saved as $item) { $pid = $item['competition_id']; if(get_post_status($pid)!=='publish') continue; $img = get_the_post_thumbnail_url($pid,'medium'); echo '<div class="cw-card"><div class="cw-card-img" style="height:150px;background:url('.esc_url($img).')"></div><div class="cw-card-body"><h4>'.get_the_title($pid).'</h4><a href="'.get_permalink($pid).'" class="cw-btn-outline">View</a></div></div>'; }
        echo '</div>'; return ob_get_clean();
    }

    public function render_pdfs() {
        global $post; if(!$post) return ''; $atts = get_post_meta($post->ID, 'event_attachment', true); if(empty($atts)) return '';
        $html = '<div class="cw-pdf-wrapper"><h4>Downloads</h4><ul>';
        foreach($atts as $grp){ if(is_array($grp)){ foreach($grp as $f){ if(isset($f['url'])){ $html .= '<li><a href="'.$f['url'].'" target="_blank"><i class="fa fa-file-pdf"></i> '.basename($f['url']).'</a></li>'; }}}}
        return $html . '</ul></div>';
    }

    public function redirect_param() { return '?redirect_to=' . urlencode($_SERVER['REQUEST_URI']); }

    /* ==========================================================================
       10. FULL EVENT DETAIL PAGE  [cw_event_detail]
       ========================================================================== */
    public function render_event_detail() {
        $pid = get_the_ID();
        if ( ! $pid ) return '';

        $wcp = wc_get_product( $pid );
        if ( ! $wcp ) return '';

        // ── Meta helpers ──────────────────────────────────────────────────────
        $g = function( $key ) use ( $pid ) { return get_post_meta( $pid, $key, true ); };
        $unwrap = function( $key ) use ( $pid ) {
            $v = get_post_meta( $pid, $key, true );
            if ( is_array($v) && isset($v[0]) && is_array($v[0]) ) return $v[0];
            return is_array($v) ? $v : [];
        };

        // ── Category detection ────────────────────────────────────────────────
        $terms       = get_the_terms( $pid, 'product_cat' );
        $cat_type    = 'activity';  // default
        $cat_label   = 'Campaign';
        $cat_key     = '';
        $sub_cat_name = '';

        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $t ) {
                $parent = $t->parent ? get_term( $t->parent, 'product_cat' ) : null;
                $root_slug = $parent ? strtolower($parent->slug) : strtolower($t->slug);
                $sub_cat_name = $t->name;

                if ( false !== strpos( $root_slug, 'competition' ) ) {
                    $cat_type = 'competition'; $cat_label = 'Competition'; $cat_key = 'competition'; break;
                }
                if ( $root_slug === 'talk-seminar' || false !== strpos($root_slug,'seminar') || false !== strpos($root_slug,'talk') ) {
                    $cat_type = 'seminar'; $cat_label = 'Talk / Seminar'; $cat_key = 'seminar'; break;
                }
                if ( false !== strpos( $root_slug, 'running' ) )   { $cat_type = 'running'; $cat_label = 'Running'; $cat_key = 'running'; break; }
                if ( false !== strpos( $root_slug, 'volunteer' ) ) { $cat_type = 'volunteer'; $cat_label = 'Volunteer'; $cat_key = 'volunteer'; break; }
                if ( false !== strpos( $root_slug, 'workshop' ) )  { $cat_type = 'workshop'; $cat_label = 'Workshop'; $cat_key = 'workshop'; break; }
                if ( false !== strpos( $root_slug, 'community' ) ) { $cat_type = 'community'; $cat_label = 'Community'; $cat_key = 'community'; break; }
                if ( false !== strpos( $root_slug, 'activit' ) )   { $cat_type = 'activity'; $cat_label = 'Activity'; $cat_key = 'activity'; break; }
                $cat_label = $t->name; $cat_key = $t->slug;
            }
        }
        $is_competition  = ( $cat_type === 'competition' );
        $is_seminar      = ( $cat_type === 'seminar' );
        $is_activity     = ! $is_competition && ! $is_seminar;
        $voting_enabled  = $is_competition && ( $g('cw_enable_voting') === 'yes' );

        // ── Dates & status ────────────────────────────────────────────────────
        $date_start  = $g('cw_submission_start');
        $deadline    = $g('submission_deadline');
        $review_date = $g('cw_review_start');
        $final_date  = $g('cw_final_event_date');
        $now         = current_time('timestamp');
        $is_closed   = $deadline && strtotime($deadline) < $now;
        // Registration hasn't opened yet — start date is in the future.
        $is_upcoming = $date_start && strtotime($date_start) > $now;
        $days_left   = $deadline ? max(0, (int) ceil((strtotime($deadline) - $now) / DAY_IN_SECONDS)) : null;
        $days_to_start = $is_upcoming ? max(0, (int) ceil((strtotime($date_start) - $now) / DAY_IN_SECONDS)) : null;

        $fmt_date = function($d) { return $d ? date_i18n('j M Y', strtotime($d)) : '—'; };
        $fmt_day  = function($d) { return $d ? date_i18n('j M Y (l)', strtotime($d)) : '—'; };
        $fmt_time = function($d) { return $d ? date_i18n('g:i A', strtotime($d)) . ' (GMT +08:00)' : ''; };

        // ── Pricing ───────────────────────────────────────────────────────────
        $price       = floatval( $wcp->get_price() );
        $cert_type   = $g('cw_certificate_type');
        $fee_text    = $price > 0 ? 'RM ' . number_format($price, 2) : ( $cert_type ? 'E Certificate' : 'Free' );
        $fee_label   = $price > 0 ? wc_price($price) : ( $cert_type ? 'E Certificate' : '<span style="color:#16a34a;font-weight:800;">FREE</span>' );

        // ── Location ──────────────────────────────────────────────────────────
        $event_mode  = $g('cw_event_mode');
        $location    = $event_mode === 'online' ? 'Online' : ( $g('cw_location_details') ?: '—' );
        $loc_icon    = $event_mode === 'online' ? 'fa-video' : 'fa-map-marker-alt';

        // ── Organiser ─────────────────────────────────────────────────────────
        $org_id      = $g('organizer_id');
        $org_user    = $org_id ? get_userdata($org_id) : null;
        $org_name    = $org_id ? ( get_user_meta($org_id,'business_name',true) ?: 'Host' ) : 'Host';
        $org_phone   = $org_id ? get_user_meta($org_id,'business_phone',true) : '';
        $org_email   = $org_user ? ( $org_user->user_email ?? '' ) : '';
        $org_login   = $org_user ? $org_user->user_login : '';
        // Visibility toggles (default: visible). Stored as '1' / '0' user meta — empty string also = visible.
        $org_show_email = ! $org_id || ( get_user_meta($org_id, 'cw_show_org_email', true) !== '0' );
        $org_show_phone = ! $org_id || ( get_user_meta($org_id, 'cw_show_org_phone', true) !== '0' );
        $org_profile_url = ( $org_user && class_exists( 'CW_Roles' ) )
            ? CW_Roles::get_public_organizer_url( $org_user )
            : '';

        // ── SDG ───────────────────────────────────────────────────────────────
        $sdg_base  = 'https://creativewings.asia/wp-content/uploads/2025/12/';
        $sdg_names = ['No Poverty'=>1,'Zero Hunger'=>2,'Good Health and Well-Being'=>3,'Quality Education'=>4,'Gender Equality'=>5,'Clean Water and Sanitation'=>6,'Affordable and Clean Energy'=>7,'Decent Work and Economic Growth'=>8,'Industry, Innovation, and Infrastructure'=>9,'Reduced Inequalities'=>10,'Sustainable Cities and Communities'=>11,'Responsible Consumption and Production'=>12,'Climate Action'=>13,'Life Below Water'=>14,'Life on Land'=>15,'Peace, Justice, and Strong Institutions'=>16,'Partnerships for the Goals'=>17];
        $sdg_raw   = $g('sdg_goals');
        $active_sdgs = [];
        if ( is_array($sdg_raw) ) {
            foreach ( $sdg_raw as $name => $val ) {
                if ( $val === 'true' && isset($sdg_names[$name]) ) {
                    $active_sdgs[] = ['num' => $sdg_names[$name], 'name' => $name];
                }
            }
        }

        // ── Prizes ────────────────────────────────────────────────────────────
        $prizes       = $unwrap('prizes');
        $total_prize  = $g('cw_total_prize_value');

        // ── FAQ ───────────────────────────────────────────────────────────────
        $faqs = $unwrap('faq');

        // ── Cover image ───────────────────────────────────────────────────────
        $thumb = get_the_post_thumbnail_url($pid, 'full');

        // ── Product image gallery (WooCommerce) ───────────────────────────────
        $gallery_ids = method_exists( $wcp, 'get_gallery_image_ids' ) ? (array) $wcp->get_gallery_image_ids() : [];
        $gallery_images = [];
        foreach ( $gallery_ids as $gid ) {
            $full  = wp_get_attachment_image_url( (int) $gid, 'full' );
            $thumb_url = wp_get_attachment_image_url( (int) $gid, 'medium_large' );
            if ( ! $full ) {
                continue;
            }
            $gallery_images[] = [
                'id'     => (int) $gid,
                'full'   => $full,
                'thumb'  => $thumb_url ?: $full,
                'alt'    => trim( (string) get_post_meta( (int) $gid, '_wp_attachment_image_alt', true ) ),
            ];
        }

        // ── KPI progress (admin toggle + target) ──────────────────────────────
        $kpi_show     = ( $g('cw_kpi_show_progress') === 'yes' );
        $kpi_target   = (int) $g('cw_kpi_target');
        $kpi_label  = trim( (string) $g('cw_kpi_label') );
        if ( $kpi_label === '' ) {
            $kpi_label = __( 'participated', 'creativewings-core' );
        }
        $kpi_count        = 0;
        $kpi_percent      = 0;   // Real percent — can exceed 100 (e.g. 156%).
        $kpi_fill_percent = 0;   // Visual progress-bar fill — clamped to 0–100.
        $kpi_visible      = $kpi_show && $kpi_target > 0;
        if ( $kpi_visible && class_exists( 'CW_Campaign_Admin' ) ) {
            $kpi_count        = (int) CW_Campaign_Admin::get_participant_count( $pid );
            $kpi_percent      = $kpi_target > 0 ? round( ( $kpi_count / $kpi_target ) * 100, 1 ) : 0;
            $kpi_fill_percent = max( 0, min( 100, $kpi_percent ) );
        }

        // ── Already joined ────────────────────────────────────────────────────
        // Only treats the user as "already in" when the campaign enforces one-entry-per-user.
        // Multi-entry campaigns let users register again with the same account.
        $uid          = get_current_user_id();
        $one_per_user = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_limits_to_one_entry( $pid ) : true;
        $already      = false;
        if ( $uid && $one_per_user ) {
            $entries = get_posts(['post_type'=>['cw_activity_entry','cw_competition_entry'],'meta_query'=>[['key'=>'customer_id','value'=>$uid],['key'=>'product_id','value'=>$pid]],'posts_per_page'=>1,'fields'=>'ids']);
            $already = ! empty($entries);
        }

        // ── Chip colour map ───────────────────────────────────────────────────
        $chip_map = ['competition'=>'#7c3aed','seminar'=>'#0d9488','running'=>'#f59e0b','community'=>'#006599','workshop'=>'#d97706','volunteer'=>'#22c55e','activity'=>'#006599'];
        $chip_color = $chip_map[$cat_type] ?? '#006599';

        $current_url = ( is_ssl() ? 'https' : 'http' ) . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];

        // ── Build timeline items once (used in sidebar) ──────────────────────
        $tl_items = [];
        if ($date_start)  $tl_items[] = ['icon'=>'fa-door-open',     'color'=>'#16a34a', 'label'=>'Submission Opens',    'date'=>$date_start];
        if ($deadline)    $tl_items[] = ['icon'=>'fa-flag-checkered', 'color'=>'#dc2626', 'label'=>'Submission Closes',   'date'=>$deadline];
        if ($review_date) $tl_items[] = ['icon'=>'fa-search',         'color'=>'#d97706', 'label'=>'Review Period',       'date'=>$review_date];
        if ($final_date)  $tl_items[] = ['icon'=>'fa-star',           'color'=>'#7c3aed', 'label'=>'Campaign Date / Announcement','date'=>$final_date];

        // ── Participant meta ──────────────────────────────────────────────────
        $min_p = (int) $g('cw_min_participants');
        $max_p = (int) $g('cw_max_participants');

        // ── Speaker meta ──────────────────────────────────────────────────────
        $speaker   = $g('cw_talk_speaker');
        $talk_type = $g('cw_talk_type');

        // ── Registration form config ──────────────────────────────────────────
        $custom_fields   = get_post_meta($pid, 'cw_custom_fields', true) ?: [];
        $is_multi_sub    = get_post_meta($pid, 'multiple_submissions', true) === 'true';
        $allow_multi_p   = ! $is_competition && get_post_meta( $pid, 'cw_allow_multiple_participants', true ) === 'yes';
        $reg_min         = $is_competition ? ( (int) get_post_meta( $pid, 'cw_multi_min', true ) ?: 1 ) : ( $allow_multi_p ? ( $min_p ?: 1 ) : 1 );
        $reg_max         = $is_competition ? ( $is_multi_sub ? ( (int) get_post_meta( $pid, 'cw_multi_max', true ) ?: 50 ) : 1 ) : ( $allow_multi_p ? ( $max_p ?: 10 ) : 1 );
        $reg_label       = $is_competition ? 'Artwork Entry' : 'Participant';
        $reg_btn         = $is_competition ? '+ Add Artwork' : '+ Add Participant';
        $show_name_field = $is_activity || $is_seminar;
        $use_account_fn  = get_post_meta( $pid, 'cw_use_account_fullname', true );
        if ( $use_account_fn === '' ) {
            $use_account_fn = 'yes';
        }
        $account_full_name = '';
        if ( is_user_logged_in() ) {
            $uid = get_current_user_id();
            $account_full_name = get_user_meta( $uid, 'cw_full_name', true );
            if ( ! $account_full_name ) {
                $u = wp_get_current_user();
                $account_full_name = $u->display_name;
            }
        }
        $reg_config = [
            'pid'               => $pid,
            'fields'            => $custom_fields,
            'min'               => $reg_min,
            'max'               => $reg_max,
            'label'             => $reg_label,
            'btnText'           => $reg_btn,
            'showName'          => $show_name_field,
            'allowMultiple'     => $allow_multi_p,
            'useAccountFullname'=> ( $use_account_fn === 'yes' ),
            'accountFullName'   => $account_full_name,
            'calcMode'          => $is_competition ? 'entry' : 'team',
            'action'            => get_permalink( $pid ),
            'nonce'             => wp_create_nonce( 'cw_reg_' . $pid ),
        ];

        ob_start();
        ?>
        <div class="cwd-wrap">

            <!-- ═══════════════════════ HERO CARD (contained) ════════════════════ -->
            <div class="cwd-hero-card">

                <!-- Left: event image -->
                <div class="cwd-hero-img-col">
                    <?php if ( $thumb ): ?>
                    <img src="<?php echo esc_url($thumb); ?>" alt="<?php the_title_attribute(); ?>" class="cwd-hero-img">
                    <?php else: ?>
                    <div class="cwd-hero-img-placeholder">
                        <i class="fas fa-image"></i>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Right: info -->
                <div class="cwd-hero-info-col">
                    <div class="cwd-hero-top">
                        <span class="cwd-cat-chip" style="background:<?php echo esc_attr($chip_color); ?>"><?php echo esc_html($cat_label); ?></span>
                        <?php
                            $status_class = $is_closed ? 'closed' : ( $is_upcoming ? 'upcoming' : 'open' );
                            $status_text  = $is_closed ? 'Closed' : ( $is_upcoming ? 'Upcoming' : 'Open' );
                        ?>
                        <span class="cwd-status-pill <?php echo esc_attr( $status_class ); ?>">
                            <span class="cwd-status-dot"></span>
                            <?php echo esc_html( $status_text ); ?>
                        </span>
                    </div>

                    <h1 class="cwd-hero-title"><?php the_title(); ?></h1>
                    <p class="cwd-hero-org"><i class="fas fa-building"></i> <?php echo esc_html($org_name); ?></p>

                    <!-- Quick stats -->
                    <div class="cwd-hero-stats">
                        <?php if ($date_start): ?>
                        <div class="cwd-hero-stat"><i class="fas fa-calendar-alt"></i> <?php echo esc_html($fmt_day($date_start)); ?></div>
                        <?php endif; ?>
                        <?php if ($fmt_time($date_start)): ?>
                        <div class="cwd-hero-stat"><i class="fas fa-clock"></i> <?php echo esc_html($fmt_time($date_start)); ?></div>
                        <?php endif; ?>
                        <div class="cwd-hero-stat"><i class="fas fa-tag"></i> <?php echo $fee_label; ?></div>
                        <div class="cwd-hero-stat"><i class="fas <?php echo esc_attr($loc_icon); ?>"></i> <?php echo esc_html($location); ?></div>
                    </div>

                    <!-- SDG icons + compact KPI progress (compact strip) -->
                    <?php
                    $kpi_state = $kpi_percent > 100 ? 'over' : ( $kpi_percent >= 100 ? 'done' : ( $kpi_percent >= 50 ? 'mid' : 'start' ) );
                    $has_sdg   = ! empty( $active_sdgs );
                    if ( $has_sdg || $kpi_visible ): ?>
                    <div class="cwd-hero-meta">
                        <?php if ( $has_sdg ): ?>
                        <div class="cwd-hero-sdg" aria-label="<?php esc_attr_e( 'Sustainable Development Goals', 'creativewings-core' ); ?>">
                            <?php foreach ( array_slice( $active_sdgs, 0, 6 ) as $sg ):
                                $pad = str_pad( $sg['num'], 2, '0', STR_PAD_LEFT ); ?>
                            <img src="<?php echo esc_url( $sdg_base . 'E_WEB_' . $pad . '.png' ); ?>"
                                 alt="SDG <?php echo (int) $sg['num']; ?>" title="<?php echo esc_attr( $sg['name'] ); ?>"
                                 class="cwd-hero-sdg-icon" loading="lazy">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <?php if ( $kpi_visible ):
                            // Display-only percent: round to int (e.g. 156.4 → 156). Floor at 1% when there's any participation
                            // so a single signup in a huge target doesn't render as a misleading "0%".
                            $kpi_display_percent = (int) round( $kpi_percent );
                            if ( $kpi_count > 0 && $kpi_percent > 0 && $kpi_display_percent === 0 ) {
                                $kpi_display_percent_label = '<1%';
                            } else {
                                $kpi_display_percent_label = $kpi_display_percent . '%';
                            }
                        ?>
                        <div class="cwd-hero-kpi cwd-kpi-state-<?php echo esc_attr( $kpi_state ); ?>"
                             role="progressbar"
                             aria-valuenow="<?php echo esc_attr( (string) $kpi_display_percent ); ?>"
                             aria-valuemin="0" aria-valuemax="100"
                             aria-label="<?php esc_attr_e( 'Campaign progress', 'creativewings-core' ); ?>"
                             title="<?php echo esc_attr( sprintf( __( '%1$s of %2$s %3$s · %4$s%%', 'creativewings-core' ), number_format_i18n( $kpi_count ), number_format_i18n( $kpi_target ), $kpi_label, (string) $kpi_percent ) ); ?>">
                            <div class="cwd-hero-kpi-top">
                                <span class="cwd-hero-kpi-text">
                                    <i class="fas fa-bullseye"></i>
                                    <strong><?php echo esc_html( number_format_i18n( $kpi_count ) ); ?></strong>
                                    <span class="cwd-hero-kpi-of">/ <?php echo esc_html( number_format_i18n( $kpi_target ) ); ?></span>
                                    <span class="cwd-hero-kpi-label"><?php echo esc_html( $kpi_label ); ?></span>
                                </span>
                                <span class="cwd-hero-kpi-percent"><?php echo esc_html( $kpi_display_percent_label ); ?></span>
                            </div>
                            <div class="cwd-hero-kpi-bar">
                                <span class="cwd-hero-kpi-fill" style="width: <?php echo esc_attr( (string) $kpi_fill_percent ); ?>%;"></span>
                            </div>
                            <?php if ( $kpi_state === 'over' ): ?>
                            <div class="cwd-hero-kpi-exceeded">
                                <i class="fas fa-trophy"></i> <?php echo esc_html( sprintf( __( 'Goal exceeded by %s!', 'creativewings-core' ), number_format_i18n( max( 0, $kpi_count - $kpi_target ) ) ) ); ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
            <!-- ════════════════ END HERO CARD ════════════════ -->

            <!-- ════════════════════════ BODY ════════════════════════ -->
            <div class="cwd-body">

                <!-- ── LEFT SIDEBAR ── -->
                <aside class="cwd-sidebar">

                    <!-- Registration Card -->
                    <div class="cwd-card cwd-reg-card">
                        <div class="cwd-reg-price"><?php echo $fee_label; ?></div>
                        <div class="cwd-reg-label">Entry Fee</div>

                        <?php if ( $is_upcoming ): ?>
                        <div class="cwd-deadline-row cwd-deadline-upcoming">
                            <i class="fas fa-calendar-alt"></i>
                            <span>
                                <?php if ( $days_to_start === 0 ): ?>
                                    <strong style="color:#0369a1;">Opens today!</strong>
                                <?php elseif ( $days_to_start !== null && $days_to_start <= 14 ): ?>
                                    <strong style="color:#0369a1;">Opens in <?php echo $days_to_start; ?> day<?php echo $days_to_start === 1 ? '' : 's'; ?></strong>
                                <?php else: ?>
                                    Opens: <?php echo esc_html($fmt_date($date_start)); ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php elseif ( $deadline && ! $is_closed ): ?>
                        <div class="cwd-deadline-row">
                            <i class="fas fa-hourglass-half"></i>
                            <span>
                                <?php if ( $days_left !== null ): ?>
                                    <?php if ( $days_left === 0 ): ?>
                                        <strong style="color:#dc2626;">Closes today!</strong>
                                    <?php elseif ( $days_left <= 7 ): ?>
                                        <strong style="color:#d97706;"><?php echo $days_left; ?> days left</strong>
                                    <?php else: ?>
                                        Deadline: <?php echo esc_html($fmt_date($deadline)); ?>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </span>
                        </div>
                        <?php elseif ( $is_closed ): ?>
                        <div class="cwd-deadline-row cwd-deadline-closed">
                            <i class="fas fa-lock"></i> <span>Registration closed <?php echo esc_html($fmt_date($deadline)); ?></span>
                        </div>
                        <?php endif; ?>

                        <?php if ( $is_closed ): ?>
                            <button class="cwd-cta-btn cwd-cta-closed" disabled><i class="fas fa-lock"></i> Already Closed</button>
                        <?php elseif ( $already ): ?>
                            <button class="cwd-cta-btn cwd-cta-joined" disabled><i class="fas fa-check-circle"></i> Already Joined</button>
                        <?php elseif ( $is_upcoming ): ?>
                            <button class="cwd-cta-btn cwd-cta-upcoming" disabled><i class="fas fa-clock"></i> Coming Soon</button>
                        <?php elseif ( ! is_user_logged_in() ): ?>
                            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount') . '?redirect_to=' . urlencode(get_permalink($pid))); ?>" class="cwd-cta-btn cwd-cta-join">
                                <i class="fas fa-sign-in-alt"></i> Log in to Join
                            </a>
                        <?php else: ?>
                            <button type="button" class="cwd-cta-btn cwd-cta-join" onclick="cwdOpenRegModal()">
                                <i class="fas fa-bolt"></i> Join Now
                            </button>
                        <?php endif; ?>

                        <?php if ( ! $is_closed && ! $already && ! $is_upcoming ):
                            $deadline_str = $deadline ? 'Closes ' . $fmt_date($deadline) : '';
                            if ( $deadline_str ): ?>
                        <p class="cwd-reg-footnote"><i class="fas fa-info-circle"></i> <?php echo esc_html($deadline_str); ?></p>
                            <?php endif; ?>
                        <?php elseif ( $is_upcoming && $date_start ): ?>
                        <p class="cwd-reg-footnote"><i class="fas fa-info-circle"></i> Registration opens <?php echo esc_html($fmt_date($date_start)); ?></p>
                        <?php endif; ?>

                        <?php if ( $voting_enabled ): ?>
                        <button type="button" class="cwv-open-btn" style="margin-top:10px;"
                            onclick="cwvOpenGalleryModal()">
                            <i class="fas fa-images"></i> View Submissions &amp; Vote
                        </button>
                        <?php endif; ?>
                    </div>

                    <!-- Timeline Card (sidebar) -->
                    <?php if ( ! empty($tl_items) ): ?>
                    <div class="cwd-card cwd-tl-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-stream"></i> Timeline</h4>
                        <div class="cwd-timeline">
                            <?php foreach ( $tl_items as $i => $item ):
                                $is_past = strtotime($item['date']) < $now; ?>
                            <div class="cwd-tl-item <?php echo $is_past ? 'cwd-tl-past' : 'cwd-tl-future'; ?>">
                                <div class="cwd-tl-dot" style="background:<?php echo esc_attr($item['color']); ?>">
                                    <i class="fas <?php echo esc_attr($item['icon']); ?>"></i>
                                </div>
                                <?php if ($i < count($tl_items)-1): ?>
                                <div class="cwd-tl-line"></div>
                                <?php endif; ?>
                                <div class="cwd-tl-text">
                                    <span class="cwd-tl-label"><?php echo esc_html($item['label']); ?></span>
                                    <span class="cwd-tl-date"><?php echo esc_html($fmt_date($item['date'])); ?></span>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Participants Card (sidebar) -->
                    <?php if ( $min_p || $max_p ): ?>
                    <div class="cwd-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-users"></i> Participants</h4>
                        <div class="cwd-participant-row">
                            <?php if ($min_p): ?>
                            <div class="cwd-participant-box">
                                <span class="cwd-pbox-num"><?php echo $min_p; ?></span>
                                <span class="cwd-pbox-label">Min per team</span>
                            </div>
                            <?php endif; ?>
                            <?php if ($max_p): ?>
                            <div class="cwd-participant-box">
                                <span class="cwd-pbox-num"><?php echo $max_p; ?></span>
                                <span class="cwd-pbox-label">Max per team</span>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Additional Info Card -->
                    <?php
                    $cert_label  = $g('cw_certificate_type');
                    $sub_type    = $g('cw_submission_type');
                    $max_sub     = (int) $g('cw_max_submissions');
                    $has_addl    = $event_mode || $location !== '—' || $cert_label || $sub_type || $max_sub;
                    if ( $has_addl ):
                    ?>
                    <div class="cwd-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-info-circle"></i> Additional Info</h4>
                        <ul class="cwd-info-list">
                            <?php if ($event_mode): ?>
                            <li>
                                <span class="cwd-il-icon"><i class="fas <?php echo $event_mode === 'online' ? 'fa-video' : 'fa-map-marker-alt'; ?>"></i></span>
                                <div>
                                    <span class="cwd-il-label">Format</span>
                                    <span class="cwd-il-value"><?php echo $event_mode === 'online' ? 'Online' : 'Physical'; ?></span>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if ($location && $location !== '—' && $event_mode !== 'online'): ?>
                            <li>
                                <span class="cwd-il-icon"><i class="fas fa-building"></i></span>
                                <div>
                                    <span class="cwd-il-label">Venue</span>
                                    <span class="cwd-il-value"><?php echo esc_html($location); ?></span>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if ($cert_label): ?>
                            <li>
                                <span class="cwd-il-icon"><i class="fas fa-certificate"></i></span>
                                <div>
                                    <span class="cwd-il-label">Certificate</span>
                                    <span class="cwd-il-value"><?php echo esc_html($cert_label); ?></span>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if ($sub_type): ?>
                            <li>
                                <span class="cwd-il-icon"><i class="fas fa-upload"></i></span>
                                <div>
                                    <span class="cwd-il-label">Submission Type</span>
                                    <span class="cwd-il-value"><?php echo esc_html($sub_type); ?></span>
                                </div>
                            </li>
                            <?php endif; ?>
                            <?php if ($max_sub): ?>
                            <li>
                                <span class="cwd-il-icon"><i class="fas fa-layer-group"></i></span>
                                <div>
                                    <span class="cwd-il-label">Max Submissions</span>
                                    <span class="cwd-il-value"><?php echo $max_sub; ?></span>
                                </div>
                            </li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>

                    <!-- Judging Criteria Card (sidebar, Competition only) -->
                    <?php $judging = $g('cw_judging_criteria');
                    if ( $is_competition && $judging ): ?>
                    <div class="cwd-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-balance-scale"></i> Judging Criteria</h4>
                        <div class="cwd-prose cwd-prose-sm"><?php echo wp_kses_post(wpautop($judging)); ?></div>
                    </div>
                    <?php endif; ?>

                    <!-- Organiser Card -->
                    <div class="cwd-card cwd-org-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-building"></i> Organiser</h4>
                        <?php
                        $has_org_info       = $org_name && $org_name !== 'Host';
                        $show_email_visible = $org_email && $org_show_email;
                        $show_phone_visible = $org_phone && $org_show_phone;
                        $has_contact        = $show_email_visible || $show_phone_visible;
                        if ( $has_org_info || $has_contact ):
                        ?>
                        <?php if ($has_org_info): ?>
                            <?php if ($org_profile_url): ?>
                            <a href="<?php echo esc_url($org_profile_url); ?>" class="cwd-org-name cwd-org-name-link">
                                <?php echo esc_html($org_name); ?>
                                <i class="fas fa-arrow-right" aria-hidden="true"></i>
                            </a>
                            <?php else: ?>
                            <p class="cwd-org-name"><?php echo esc_html($org_name); ?></p>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($show_email_visible): ?>
                        <a href="mailto:<?php echo esc_attr($org_email); ?>" class="cwd-org-row">
                            <i class="fas fa-envelope"></i> <?php echo esc_html($org_email); ?>
                        </a>
                        <?php endif; ?>
                        <?php if ($show_phone_visible): ?>
                        <a href="tel:<?php echo esc_attr(preg_replace('/\D/','',$org_phone)); ?>" class="cwd-org-row">
                            <i class="fas fa-phone"></i> <?php echo esc_html($org_phone); ?>
                        </a>
                        <?php endif; ?>
                        <?php else: ?>
                        <p class="cwd-org-no-info"><i class="fas fa-info-circle"></i> No information available.</p>
                        <?php endif; ?>
                    </div>

                    <!-- Share Card -->
                    <div class="cwd-card cwd-share-card">
                        <h4 class="cwd-card-heading"><i class="fas fa-share-alt"></i> Share</h4>
                        <div class="cwd-share-row">
                            <a href="https://www.facebook.com/sharer/sharer.php?u=<?php echo urlencode(get_permalink($pid)); ?>"
                               target="_blank" class="cwd-share-btn cwd-share-fb" title="Share on Facebook">
                                <i class="fab fa-facebook-f"></i>
                            </a>
                            <a href="https://wa.me/?text=<?php echo urlencode(get_the_title($pid).' '.get_permalink($pid)); ?>"
                               target="_blank" class="cwd-share-btn cwd-share-wa" title="Share on WhatsApp">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                            <button class="cwd-share-btn cwd-share-copy" onclick="cwdCopyLink(this)" data-url="<?php echo esc_attr(get_permalink($pid)); ?>" title="Copy link">
                                <i class="fas fa-link"></i>
                            </button>
                        </div>
                    </div>

                </aside>
                <!-- ── END SIDEBAR ── -->

                <!-- ── RIGHT MAIN CONTENT ── -->
                <main class="cwd-main">

                    <!-- GALLERY (WooCommerce product image gallery) -->
                    <?php if ( ! empty( $gallery_images ) ): ?>
                    <section class="cwd-section cwd-gallery-section">
                        <h2 class="cwd-section-title"><i class="fas fa-images"></i> Gallery</h2>
                        <div class="cwd-gallery-grid" id="cwd-gallery-grid">
                            <?php foreach ( $gallery_images as $idx => $img ): ?>
                            <button type="button"
                                class="cwd-gallery-item"
                                data-cwd-gallery-index="<?php echo (int) $idx; ?>"
                                data-full="<?php echo esc_url( $img['full'] ); ?>"
                                aria-label="<?php echo esc_attr( $img['alt'] ?: 'Open image ' . ( $idx + 1 ) ); ?>">
                                <img src="<?php echo esc_url( $img['thumb'] ); ?>"
                                     alt="<?php echo esc_attr( $img['alt'] ); ?>"
                                     loading="lazy">
                                <span class="cwd-gallery-zoom-icon" aria-hidden="true"><i class="fas fa-search-plus"></i></span>
                            </button>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- About -->
                    <?php $content = get_post_field('post_content', $pid);
                    if ( $content ): ?>
                    <section class="cwd-section">
                        <h2 class="cwd-section-title"><i class="fas fa-info-circle"></i> About</h2>
                        <div class="cwd-prose"><?php echo wp_kses_post(wpautop($content)); ?></div>
                    </section>
                    <?php endif; ?>

                    <!-- PRIZES (Competition only) -->
                    <?php if ( $is_competition && ( ! empty($prizes) || $total_prize ) ): ?>
                    <section class="cwd-section">
                        <h2 class="cwd-section-title"><i class="fas fa-trophy"></i> Prizes</h2>
                        <?php if ($total_prize): ?>
                        <div class="cwd-total-prize-banner">
                            <i class="fas fa-coins"></i>
                            Total Prize Pool: <strong><?php echo esc_html('RM '.number_format(floatval($total_prize),2)); ?></strong>
                        </div>
                        <?php endif; ?>
                        <?php if ( ! empty($prizes) ): ?>
                        <div class="cwd-prize-grid">
                            <?php foreach ($prizes as $pr):
                                if (empty($pr['prize_title'])) continue; ?>
                            <div class="cwd-prize-card">
                                <div class="cwd-prize-icon"><i class="fas fa-medal"></i></div>
                                <h4 class="cwd-prize-title"><?php echo esc_html($pr['prize_title']); ?></h4>
                                <?php if (!empty($pr['prize_description'])): ?>
                                <p class="cwd-prize-desc"><?php echo esc_html($pr['prize_description']); ?></p>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </section>
                    <?php endif; ?>

                    <!-- SPEAKER (Talk/Seminar only) -->
                    <?php if ( $is_seminar && $speaker ): ?>
                    <section class="cwd-section">
                        <h2 class="cwd-section-title"><i class="fas fa-microphone-alt"></i> Speaker</h2>
                        <div class="cwd-speaker-card">
                            <div class="cwd-speaker-avatar"><i class="fas fa-user-tie"></i></div>
                            <div class="cwd-speaker-info">
                                <h3 class="cwd-speaker-name"><?php echo esc_html($speaker); ?></h3>
                                <?php if ($talk_type): ?><span class="cwd-speaker-type"><?php echo esc_html($talk_type); ?></span><?php endif; ?>
                            </div>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- FAQ ACCORDION -->
                    <?php if ( ! empty($faqs) ): ?>
                    <section class="cwd-section">
                        <h2 class="cwd-section-title"><i class="fas fa-question-circle"></i> FAQs</h2>
                        <div class="cwd-faq">
                            <?php foreach ($faqs as $fq):
                                if (empty($fq['question'])) continue; ?>
                            <details class="cwd-faq-item">
                                <summary class="cwd-faq-q">
                                    <?php echo esc_html($fq['question']); ?>
                                    <i class="fas fa-chevron-down cwd-faq-arrow"></i>
                                </summary>
                                <div class="cwd-faq-a"><?php echo wp_kses_post(wpautop($fq['answer'] ?? '')); ?></div>
                            </details>
                            <?php endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                    <!-- PDF DOWNLOADS -->
                    <?php $atts_data = get_post_meta($pid,'event_attachment',true);
                    if ( ! empty($atts_data) ): ?>
                    <section class="cwd-section">
                        <h2 class="cwd-section-title"><i class="fas fa-file-download"></i> Downloads</h2>
                        <div class="cwd-downloads">
                            <?php foreach ($atts_data as $grp):
                                if (!is_array($grp)) continue;
                                foreach ($grp as $f):
                                    if (empty($f['url'])) continue;
                                    $fname = basename($f['url']); ?>
                            <a href="<?php echo esc_url($f['url']); ?>" target="_blank" class="cwd-dl-item">
                                <div class="cwd-dl-icon"><i class="fas fa-file-pdf"></i></div>
                                <span><?php echo esc_html($fname); ?></span>
                                <i class="fas fa-download cwd-dl-arrow"></i>
                            </a>
                            <?php endforeach; endforeach; ?>
                        </div>
                    </section>
                    <?php endif; ?>

                </main>
                <!-- ── END MAIN ── -->

            </div>
            <!-- ════════════════ END BODY ════════════════ -->

        </div><!-- .cwd-wrap -->

        <?php if ( ! empty( $gallery_images ) ): ?>
        <!-- ═════════════════ PRODUCT GALLERY LIGHTBOX ═════════════════ -->
        <div id="cwd-gallery-lightbox" class="cwd-gallery-lightbox" style="display:none;" role="dialog" aria-modal="true" aria-label="Image viewer"
             onclick="if(event.target===this)cwdGalleryClose()">
            <button type="button" class="cwd-gallery-lb-close" aria-label="Close" onclick="cwdGalleryClose()">&times;</button>
            <button type="button" class="cwd-gallery-lb-nav cwd-gallery-lb-prev" aria-label="Previous image" onclick="cwdGalleryStep(-1)"><i class="fas fa-chevron-left"></i></button>
            <figure class="cwd-gallery-lb-figure">
                <img id="cwd-gallery-lb-img" src="" alt="">
                <figcaption class="cwd-gallery-lb-counter" id="cwd-gallery-lb-counter"></figcaption>
            </figure>
            <button type="button" class="cwd-gallery-lb-nav cwd-gallery-lb-next" aria-label="Next image" onclick="cwdGalleryStep(1)"><i class="fas fa-chevron-right"></i></button>
        </div>
        <script>
        (function(){
            var images = <?php echo wp_json_encode( array_values( array_map( function( $i ) {
                return [ 'full' => $i['full'], 'alt' => $i['alt'] ];
            }, $gallery_images ) ) ); ?>;
            var current = 0;
            function open(i) {
                if (!images.length) return;
                current = ((i % images.length) + images.length) % images.length;
                var lb = document.getElementById('cwd-gallery-lightbox');
                var img = document.getElementById('cwd-gallery-lb-img');
                var counter = document.getElementById('cwd-gallery-lb-counter');
                if (!lb || !img) return;
                img.src = images[current].full;
                img.alt = images[current].alt || '';
                if (counter) counter.textContent = (current + 1) + ' / ' + images.length;
                lb.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                var prev = lb.querySelector('.cwd-gallery-lb-prev');
                var next = lb.querySelector('.cwd-gallery-lb-next');
                if (prev) prev.style.visibility = images.length > 1 ? 'visible' : 'hidden';
                if (next) next.style.visibility = images.length > 1 ? 'visible' : 'hidden';
            }
            function close() {
                var lb = document.getElementById('cwd-gallery-lightbox');
                if (lb) lb.style.display = 'none';
                document.body.style.overflow = '';
            }
            function step(d) { open(current + d); }
            window.cwdGalleryClose = close;
            window.cwdGalleryStep  = step;
            var grid = document.getElementById('cwd-gallery-grid');
            if (grid) {
                grid.addEventListener('click', function(e){
                    var t = e.target.closest('.cwd-gallery-item');
                    if (!t) return;
                    e.preventDefault();
                    open(parseInt(t.getAttribute('data-cwd-gallery-index'), 10) || 0);
                });
            }
            document.addEventListener('keydown', function(e){
                var lb = document.getElementById('cwd-gallery-lightbox');
                if (!lb || lb.style.display === 'none') return;
                if (e.key === 'Escape')      close();
                else if (e.key === 'ArrowLeft')  step(-1);
                else if (e.key === 'ArrowRight') step(1);
            });
            // Touch swipe support
            var touchStartX = null;
            var lbEl = document.getElementById('cwd-gallery-lightbox');
            if (lbEl) {
                lbEl.addEventListener('touchstart', function(e){
                    if (e.touches.length === 1) touchStartX = e.touches[0].clientX;
                }, { passive: true });
                lbEl.addEventListener('touchend', function(e){
                    if (touchStartX === null) return;
                    var dx = (e.changedTouches[0].clientX || 0) - touchStartX;
                    touchStartX = null;
                    if (Math.abs(dx) > 40) step(dx < 0 ? 1 : -1);
                });
            }
        })();
        </script>
        <?php endif; ?>

        <?php if ( $voting_enabled ):
            // Resolve visitor IP for "already voted" check
            $visitor_ip = '';
            if ( ! empty($_SERVER['HTTP_X_FORWARDED_FOR']) ) {
                $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
                $visitor_ip = trim($parts[0]);
            }
            if ( empty($visitor_ip) ) $visitor_ip = $_SERVER['REMOTE_ADDR'] ?? '';

            // Fetch entries sorted by votes
            $gallery_entries = get_posts([
                'post_type'      => 'cw_competition_entry',
                'posts_per_page' => -1,
                'meta_query'     => [['key'=>'product_id','value'=>$pid,'compare'=>'=','type'=>'NUMERIC']],
                'orderby'        => 'meta_value_num',
                'meta_key'       => 'vote_count',
                'order'          => 'DESC',
            ]);
            $cwv_total        = count( $gallery_entries );
            $cwv_per_page     = 9;
            $cwv_pages        = $cwv_total ? array_chunk( $gallery_entries, $cwv_per_page ) : [];
            $cwv_page_count   = max( 1, count( $cwv_pages ) );
        ?>
        <!-- ═══════════════════ VOTING GALLERY MODAL ═══════════════════ -->
        <div id="cwv-gallery-modal" class="cwv-modal-overlay" style="display:none;" aria-modal="true" role="dialog"
             onclick="if(event.target===this) this.style.display='none'">
            <div class="cwv-modal-box">
                <!-- Modal header -->
                <div class="cwv-modal-header">
                    <div class="cwv-modal-header-text">
                        <h3><i class="fas fa-images" style="color:var(--cw-accent);margin-right:8px;"></i>Submissions Gallery</h3>
                        <p id="cwv-gallery-subtitle"><?php echo esc_html( $cwv_total ); ?> entries &middot; <?php echo $cwv_page_count > 1 ? esc_html( $cwv_per_page ) . ' per page &middot; ' : ''; ?>vote for your favourite</p>
                    </div>
                    <button type="button" class="cwv-modal-close" onclick="document.getElementById('cwv-gallery-modal').style.display='none'">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <!-- Modal body -->
                <div class="cwv-modal-body">
                    <?php if ( $gallery_entries ) : ?>
                    <div class="cwv-gallery-pages" id="cwv-gallery-pages" data-total-pages="<?php echo (int) $cwv_page_count; ?>">
                        <?php
                        $global_rank = 0;
                        foreach ( $cwv_pages as $pi => $page_entries ) :
                            $display = ( $pi === 0 ) ? 'grid' : 'none';
                        ?>
                        <div class="cwv-gallery cwv-gallery-page" data-page="<?php echo (int) ( $pi + 1 ); ?>" style="display:<?php echo $display === 'grid' ? 'grid' : 'none'; ?>;">
                            <?php foreach ( $page_entries as $entry ) :
                                $global_rank++;
                                $e_file   = get_post_meta($entry->ID, 'upload_document', true);
                                $e_name   = get_post_meta($entry->ID, 'cw_participant_name', true);
                                $e_votes  = (int) get_post_meta($entry->ID, 'vote_count', true);
                                $e_voters = get_post_meta($entry->ID, 'cw_voters', true);
                                if (!is_array($e_voters)) $e_voters = [];
                                $already_voted = in_array($visitor_ip, $e_voters, true);
                                $is_image = $e_file && preg_match('/\.(jpg|jpeg|png|gif|webp)$/i', $e_file);
                            ?>
                            <div class="cwv-card">
                                <div class="cwv-card-img-wrap">
                                    <?php if ($is_image): ?>
                                    <img src="<?php echo esc_url($e_file); ?>" alt="<?php echo esc_attr($entry->post_title); ?>" class="cwv-card-img cwv-zoomable" role="button" tabindex="0" data-full="<?php echo esc_url($e_file); ?>" title="Click to enlarge">
                                    <?php elseif ($e_file): ?>
                                    <div class="cwv-card-img-placeholder"><i class="fas fa-file-alt"></i><span>Document</span></div>
                                    <?php else: ?>
                                    <div class="cwv-card-img-placeholder"><i class="fas fa-image"></i><span>No file</span></div>
                                    <?php endif; ?>
                                    <span class="cwv-rank-badge">#<?php echo (int) $global_rank; ?></span>
                                </div>
                                <div class="cwv-card-body">
                                    <p class="cwv-card-title"><?php echo esc_html($entry->post_title); ?></p>
                                    <p class="cwv-card-submitter"><i class="fas fa-user" style="margin-right:4px;"></i><?php echo esc_html($e_name ?: 'Anonymous'); ?></p>
                                    <div class="cwv-vote-row">
                                        <span class="cwv-vote-count"><i class="fas fa-heart"></i> <span class="cwv-count-num" data-entry="<?php echo $entry->ID; ?>"><?php echo $e_votes; ?></span></span>
                                        <?php if ($already_voted): ?>
                                        <button type="button" class="cwv-vote-btn voted" disabled><i class="fas fa-heart"></i> Voted</button>
                                        <?php elseif ($is_closed): ?>
                                        <button type="button" class="cwv-vote-btn voted" disabled><i class="fas fa-lock"></i> Closed</button>
                                        <?php else: ?>
                                        <button type="button" class="cwv-vote-btn" data-entry-id="<?php echo $entry->ID; ?>" onclick="cwvCastVote(this)">
                                            <i class="fas fa-heart"></i> Vote
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( $cwv_page_count > 1 ) : ?>
                    <div class="cwv-gallery-pager" id="cwv-gallery-pager">
                        <button type="button" class="cwv-pager-btn" id="cwv-pager-prev" onclick="cwvGalleryPage(-1)" aria-label="Previous page" disabled><i class="fas fa-chevron-left"></i></button>
                        <span class="cwv-pager-info" id="cwv-pager-info">Page <strong id="cwv-pager-num">1</strong> of <?php echo (int) $cwv_page_count; ?></span>
                        <button type="button" class="cwv-pager-btn" id="cwv-pager-next" onclick="cwvGalleryPage(1)" aria-label="Next page"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="cwv-gallery">
                        <div class="cwv-empty" style="grid-column:1/-1;">
                            <i class="fas fa-inbox"></i>
                            <p>No submissions yet. Check back after the submission deadline.</p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div><!-- .cwv-modal-body -->
            </div><!-- .cwv-modal-box -->
        </div><!-- #cwv-gallery-modal -->

        <div id="cwv-lightbox" class="cwv-lightbox" style="display:none;" role="dialog" aria-modal="true" onclick="if(event.target===this)cwvCloseLightbox()">
            <button type="button" class="cwv-lightbox-close" onclick="cwvCloseLightbox()" aria-label="Close">&times;</button>
            <img src="" alt="" id="cwv-lightbox-img">
        </div>

        <script>
        function cwvCastVote(btn) {
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

            jQuery.post(cw_vars.ajax_url, {
                action: 'cw_cast_vote',
                security: cw_vars.nonce,
                entry_id: btn.dataset.entryId
            }, function(res) {
                if (res.success) {
                    btn.classList.add('voted');
                    btn.innerHTML = '<i class="fas fa-heart"></i> Voted';
                    const countEl = document.querySelector('.cwv-count-num[data-entry="' + btn.dataset.entryId + '"]');
                    if (countEl) countEl.textContent = res.data.vote_count;
                } else {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-heart"></i> Vote';
                    if (typeof Swal !== 'undefined') {
                        Swal.fire({ icon:'info', title:'Already voted', text: res.data?.message || 'You have already voted.', timer: 2000, showConfirmButton: false });
                    } else {
                        alert(res.data?.message || 'Already voted.');
                    }
                }
            }).fail(function() {
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-heart"></i> Vote';
            });
        }
        var cwvCurrentPage = 1;
        function cwvGalleryTotalPages() {
            var wrap = document.getElementById('cwv-gallery-pages');
            return wrap ? parseInt(wrap.getAttribute('data-total-pages'), 10) || 1 : 1;
        }
        function cwvGalleryPage(dir) {
            var total = cwvGalleryTotalPages();
            if (total < 2) return;
            cwvCurrentPage = Math.min(total, Math.max(1, cwvCurrentPage + dir));
            var wrap = document.getElementById('cwv-gallery-pages');
            if (!wrap) return;
            wrap.querySelectorAll('.cwv-gallery-page').forEach(function(el) {
                var p = parseInt(el.getAttribute('data-page'), 10);
                el.style.display = (p === cwvCurrentPage) ? 'grid' : 'none';
            });
            var numEl = document.getElementById('cwv-pager-num');
            if (numEl) numEl.textContent = String(cwvCurrentPage);
            var prev = document.getElementById('cwv-pager-prev');
            var next = document.getElementById('cwv-pager-next');
            if (prev) prev.disabled = cwvCurrentPage <= 1;
            if (next) next.disabled = cwvCurrentPage >= total;
        }
        function cwvOpenGalleryModal() {
            var m = document.getElementById('cwv-gallery-modal');
            if (m) m.style.display = 'flex';
            cwvCurrentPage = 1;
            var wrap = document.getElementById('cwv-gallery-pages');
            if (wrap) {
                wrap.querySelectorAll('.cwv-gallery-page').forEach(function(el) {
                    var p = parseInt(el.getAttribute('data-page'), 10);
                    el.style.display = (p === 1) ? 'grid' : 'none';
                });
            }
            var numEl = document.getElementById('cwv-pager-num');
            if (numEl) numEl.textContent = '1';
            var total = cwvGalleryTotalPages();
            var prev = document.getElementById('cwv-pager-prev');
            var next = document.getElementById('cwv-pager-next');
            if (prev) prev.disabled = total <= 1 || cwvCurrentPage <= 1;
            if (next) next.disabled = total <= 1 || cwvCurrentPage >= total;
        }
        function cwvOpenLightbox(src) {
            var lb = document.getElementById('cwv-lightbox');
            var img = document.getElementById('cwv-lightbox-img');
            if (!lb || !img || !src) return;
            img.src = src;
            img.alt = '';
            lb.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        }
        function cwvCloseLightbox() {
            var lb = document.getElementById('cwv-lightbox');
            if (lb) { lb.style.display = 'none'; document.body.style.overflow = ''; }
        }
        document.addEventListener('click', function(e) {
            var z = e.target.closest('.cwv-zoomable');
            if (z && z.getAttribute('data-full')) {
                e.preventDefault();
                cwvOpenLightbox(z.getAttribute('data-full'));
            }
        });
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') cwvCloseLightbox();
        });
        </script>
        <?php endif; // voting_enabled ?>

        <!-- Sticky mobile CTA -->
        <?php if ( ! $already ): ?>
        <div class="cwd-mobile-cta">
            <div class="cwd-mobile-cta-price"><?php echo $fee_label; ?></div>
            <?php if ( $is_closed ): ?>
            <button class="cwd-cta-btn cwd-cta-closed" disabled><i class="fas fa-lock"></i> Already Closed</button>
            <?php elseif ( $is_upcoming ): ?>
            <button class="cwd-cta-btn cwd-cta-upcoming" disabled><i class="fas fa-clock"></i> Coming Soon</button>
            <?php elseif ( ! is_user_logged_in() ): ?>
            <a href="<?php echo esc_url(wc_get_page_permalink('myaccount').'?redirect_to='.urlencode(get_permalink($pid))); ?>" class="cwd-cta-btn cwd-cta-join">Log in to Join</a>
            <?php else: ?>
            <button type="button" class="cwd-cta-btn cwd-cta-join" onclick="cwdOpenRegModal()">Join Now <i class="fas fa-bolt"></i></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- ═══════════════════ REGISTRATION MODAL ═══════════════════ -->
        <div id="cwd-reg-modal" class="cwd-modal-overlay" style="display:none;" aria-modal="true" role="dialog">
            <div class="cwd-modal-wrap">
                <div class="cwd-modal-head">
                    <div class="cwd-modal-head-info">
                        <span class="cwd-cat-chip" style="background:<?php echo esc_attr($chip_color); ?>"><?php echo esc_html($cat_label); ?></span>
                        <h3 class="cwd-modal-title"><?php the_title(); ?></h3>
                        <p class="cwd-modal-subtitle"><i class="fas fa-tag"></i> <?php echo $fee_label; ?> &nbsp;|&nbsp; <i class="fas fa-building"></i> <?php echo esc_html($org_name); ?></p>
                    </div>
                    <button class="cwd-modal-close" onclick="cwdCloseRegModal()" aria-label="Close">&times;</button>
                </div>
                <form id="cwd-reg-form" method="POST" action="<?php echo esc_url(get_permalink($pid)); ?>" class="cwd-modal-body" enctype="multipart/form-data">
                    <?php wp_nonce_field('cw_reg_'.$pid, 'cw_reg_nonce'); ?>
                    <input type="hidden" name="add-to-cart" value="<?php echo esc_attr($pid); ?>">
                    <input type="hidden" name="quantity" id="cwd-reg-qty" value="1">

                    <div id="cwd-reg-rows"></div>

                    <?php if ( $reg_max > 1 ): ?>
                    <button type="button" class="cwd-reg-add-btn" id="cwd-add-row-btn">
                        <i class="fas fa-plus"></i> <?php echo esc_html($reg_btn); ?>
                    </button>
                    <?php endif; ?>

                    <div class="cwd-modal-foot">
                        <button type="button" class="cwd-cta-btn cwd-cta-closed" onclick="cwdCloseRegModal()">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="cwd-cta-btn cwd-cta-join cwd-reg-submit-primary" id="cwd-reg-submit"
                            style="background:#fff;background-image:none;color:#0f172a;-webkit-text-fill-color:#0f172a;border:2px solid #006599;box-shadow:0 2px 10px rgba(0,101,153,.12);">
                            <i class="fas fa-paper-plane" style="color:#0f172a;-webkit-text-fill-color:#0f172a;"></i> Submit &amp; Proceed
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        var cwdRegConfig = <?php echo json_encode($reg_config); ?>;

        function cwdOpenRegModal() {
            var m = document.getElementById('cwd-reg-modal');
            if (!m) return;
            m.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            cwdInitRows();
        }
        function cwdCloseRegModal() {
            var m = document.getElementById('cwd-reg-modal');
            if (m) m.style.display = 'none';
            document.body.style.overflow = '';
        }
        document.getElementById('cwd-reg-modal').addEventListener('click', function(e){
            if (e.target === this) cwdCloseRegModal();
        });

        function cwdInitRows() {
            var wrap = document.getElementById('cwd-reg-rows');
            wrap.innerHTML = '';
            for (var i = 0; i < cwdRegConfig.min; i++) cwdAddRow(i + 1);
            cwdUpdateQty();
            cwdUpdateAddBtn();
        }

        function cwdBuildRow(num) {
            var cfg = cwdRegConfig;
            var html = '<div class="cwd-reg-row" data-row="' + num + '">';
            if (cfg.min < cfg.max) {
                html += '<button type="button" class="cwd-reg-row-del" onclick="cwdRemoveRow(this)" aria-label="Remove"><i class="fas fa-times"></i></button>';
            }
            var rowTitle = cfg.label;
            if (cfg.allowMultiple || cfg.max > 1) {
                rowTitle = cfg.label + ' ' + num;
            }
            html += '<h4 class="cwd-reg-row-title">' + rowTitle + '</h4>';
            if (cfg.showName) {
                var nameVal = '';
                var nameHint = '';
                if (num === 1 && cfg.useAccountFullname && cfg.accountFullName) {
                    nameVal = cfg.accountFullName.replace(/"/g, '&quot;');
                    nameHint = '<p class="cwd-reg-hint">Prefilled from your account — edit if this certificate should show a different name.</p>';
                } else if (num > 1 && cfg.useAccountFullname) {
                    nameHint = '<p class="cwd-reg-hint">Enter the full name for this participant (certificate).</p>';
                }
                html += '<div class="cwd-reg-field">'
                      + '<label class="cwd-reg-label">Full Name <span class="cwd-req">*</span></label>'
                      + nameHint
                      + '<input type="text" name="cw_names[' + num + ']" class="cwd-reg-input" placeholder="Enter full name" value="' + nameVal + '" required>'
                      + '</div>';
            } else {
                html += '<input type="hidden" name="cw_names[' + num + ']" value="Self">';
            }
            if (cfg.fields && cfg.fields.length) {
                cfg.fields.forEach(function(f, idx) {
                    var ftype = (f.type || 'text').toString().toLowerCase();
                    var req = f.required ? ' required' : '';
                    var reqStar = f.required ? ' <span class="cwd-req">*</span>' : '';
                    var esc = function(s) {
                        if (!s) return '';
                        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
                    };
                    html += '<div class="cwd-reg-field"><label class="cwd-reg-label">' + esc(f.label) + reqStar + '</label>';
                    if (ftype === 'file') {
                        html += '<input type="file" name="cw_data[' + num + '][' + idx + ']" class="cwd-reg-input" accept=".pdf,.doc,.docx,.zip,.jpg,.jpeg,.png"' + req + '>';
                    } else if (ftype === 'media') {
                        html += '<div class="cwd-reg-file-wrap">';
                        html += '<input type="file" name="cw_data[' + num + '][' + idx + ']" class="cwd-reg-input cwd-reg-file-media" accept="image/*"' + req + ' data-preview="cwd-reg-prev-' + num + '-' + idx + '">';
                        html += '<img class="cwd-reg-media-preview" id="cwd-reg-prev-' + num + '-' + idx + '" alt="" style="display:none;max-width:120px;border-radius:8px;margin-top:8px;border:1px solid var(--cwd-border);">';
                        html += '</div>';
                    } else if (ftype === 'textarea' || ftype === 'wysiwyg') {
                        html += '<textarea name="cw_data[' + num + '][' + idx + ']" class="cwd-reg-input" placeholder="' + esc(f.placeholder || f.label) + '" rows="3"' + req + '></textarea>';
                    } else if (ftype === 'select' && f.opts) {
                        var opts = String(f.opts).split(',').map(function(o){ return o.trim(); }).filter(Boolean);
                        html += '<select name="cw_data[' + num + '][' + idx + ']" class="cwd-reg-input"' + req + '>';
                        html += '<option value="">— Select —</option>';
                        opts.forEach(function(o){ html += '<option value="' + esc(o) + '">' + esc(o) + '</option>'; });
                        html += '</select>';
                    } else {
                        var inputType = ftype === 'phone' ? 'tel' : ftype;
                        if (inputType !== 'text' && inputType !== 'number' && inputType !== 'email' && inputType !== 'tel') {
                            inputType = 'text';
                        }
                        html += '<input type="' + inputType + '" name="cw_data[' + num + '][' + idx + ']" class="cwd-reg-input" placeholder="' + esc(f.placeholder || f.label) + '"' + req + '>';
                    }
                    html += '</div>';
                });
            }
            html += '</div>';
            return html;
        }

        function cwdAddRow(num) {
            var wrap = document.getElementById('cwd-reg-rows');
            var div = document.createElement('div');
            div.innerHTML = cwdBuildRow(num || (wrap.children.length + 1));
            wrap.appendChild(div.firstChild);
            cwdUpdateAddBtn();
            cwdUpdateQty();
        }

        function cwdRemoveRow(btn) {
            var row = btn.closest('.cwd-reg-row');
            var wrap = document.getElementById('cwd-reg-rows');
            if (wrap.children.length <= cwdRegConfig.min) return;
            row.remove();
            // Re-number
            var rows = wrap.querySelectorAll('.cwd-reg-row');
            rows.forEach(function(r, i) {
                r.setAttribute('data-row', i + 1);
                var title = r.querySelector('.cwd-reg-row-title');
                if (title) title.textContent = cwdRegConfig.label + ' ' + (i + 1);
                r.querySelectorAll('[name]').forEach(function(el) {
                    el.name = el.name.replace(/\[\d+\]/g, '[' + (i + 1) + ']');
                });
            });
            cwdUpdateAddBtn();
            cwdUpdateQty();
        }

        document.getElementById('cwd-add-row-btn') && document.getElementById('cwd-add-row-btn').addEventListener('click', function(){
            var wrap = document.getElementById('cwd-reg-rows');
            if (wrap.children.length >= cwdRegConfig.max) return;
            cwdAddRow(wrap.children.length + 1);
        });

        function cwdUpdateAddBtn() {
            var btn = document.getElementById('cwd-add-row-btn');
            if (!btn) return;
            var count = document.getElementById('cwd-reg-rows').children.length;
            btn.disabled = count >= cwdRegConfig.max;
            btn.innerHTML = count >= cwdRegConfig.max
                ? '<i class="fas fa-ban"></i> Max Reached'
                : '<i class="fas fa-plus"></i> ' + cwdRegConfig.btnText;
        }

        function cwdUpdateQty() {
            var wrap = document.getElementById('cwd-reg-rows');
            var qty = cwdRegConfig.calcMode === 'entry' ? wrap.children.length : 1;
            document.getElementById('cwd-reg-qty').value = qty;
        }

        // Image preview for media fields in modal
        document.addEventListener('change', function(e) {
            if (!e.target.classList || !e.target.classList.contains('cwd-reg-file-media')) return;
            var pid = e.target.getAttribute('data-preview');
            var img = pid ? document.getElementById(pid) : null;
            if (!img || !e.target.files || !e.target.files[0]) return;
            var r = new FileReader();
            r.onload = function(ev) { img.src = ev.target.result; img.style.display = 'block'; };
            r.readAsDataURL(e.target.files[0]);
        });

        // Submit loader
        document.getElementById('cwd-reg-form').addEventListener('submit', function(){
            var btn = document.getElementById('cwd-reg-submit');
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Submitting...';
        });

        function cwdCopyLink(btn) {
            var url = btn.getAttribute('data-url');
            navigator.clipboard.writeText(url).then(function() {
                btn.innerHTML = '<i class="fas fa-check"></i>';
                setTimeout(function(){ btn.innerHTML = '<i class="fas fa-link"></i>'; }, 2000);
            });
        }
        document.querySelectorAll('.cwd-faq-item').forEach(function(d){
            d.addEventListener('toggle', function(){
                var arrow = d.querySelector('.cwd-faq-arrow');
                if (arrow) arrow.style.transform = d.open ? 'rotate(180deg)' : '';
            });
        });
        </script>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       9. COMBINED EVENTS SNIPPET  [cw_events_grid]
          Shows N cards (default 3) from ALL event categories — no tabs/pagination
       ========================================================================== */
    public function render_events_grid( $atts ) {
        $atts = shortcode_atts([
            'limit'      => 3,
            'columns'    => 3,
            'categories' => 'activities,competitions,talk-seminar',
            'orderby'    => 'date',  // date | rand | title
        ], $atts, 'cw_events_grid');

        $limit   = max( 1, intval( $atts['limit'] ) );
        $cols    = max( 1, min( 4, intval( $atts['columns'] ) ) );
        $orderby = in_array( $atts['orderby'], ['date','rand','title'] ) ? $atts['orderby'] : 'date';

        // Collect all term IDs for the requested parent categories
        $cat_slugs = array_filter( array_map( 'trim', explode( ',', $atts['categories'] ) ) );
        $term_ids  = [];
        foreach ( $cat_slugs as $slug ) {
            $t = get_term_by( 'slug', $slug, 'product_cat' );
            if ( ! $t || is_wp_error($t) ) continue;
            $term_ids[] = $t->term_id;
            foreach ( get_term_children( $t->term_id, 'product_cat' ) as $cid ) {
                $term_ids[] = (int) $cid;
            }
        }
        if ( empty( $term_ids ) ) return '';

        $query = new WP_Query([
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'orderby'        => $orderby,
            'order'          => 'DESC',
            'tax_query'      => [[
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => array_unique( $term_ids ),
            ]],
        ]);

        if ( ! $query->have_posts() ) return '';

        // SDG icon base URL
        $sdg_base  = 'https://creativewings.asia/wp-content/uploads/2025/12/';
        $sdg_names = [ 'No Poverty'=>1,'Zero Hunger'=>2,'Good Health and Well-Being'=>3,'Quality Education'=>4,'Gender Equality'=>5,'Clean Water and Sanitation'=>6,'Affordable and Clean Energy'=>7,'Decent Work and Economic Growth'=>8,'Industry, Innovation, and Infrastructure'=>9,'Reduced Inequalities'=>10,'Sustainable Cities and Communities'=>11,'Responsible Consumption and Production'=>12,'Climate Action'=>13,'Life Below Water'=>14,'Life on Land'=>15,'Peace, Justice, and Strong Institutions'=>16,'Partnerships for the Goals'=>17 ];

        // Already-joined check
        $joined_pids = [];
        $uid = get_current_user_id();
        if ( $uid ) {
            $joined = get_posts([ 'post_type' => ['cw_activity_entry','cw_competition_entry'], 'meta_key' => 'customer_id', 'meta_value' => $uid, 'posts_per_page' => -1, 'fields' => 'ids' ]);
            foreach ( $joined as $eid ) { $epid = (int) get_post_meta( $eid, 'product_id', true ); if ( $epid ) $joined_pids[$epid] = true; }
        }

        $chip_colors = [
            'competition' => 'cwg-chip-competition',
            'seminar'     => 'cwg-chip-seminar',
            'running'     => 'cwg-chip-running',
            'community'   => 'cwg-chip-community',
            'workshop'    => 'cwg-chip-workshop',
            'volunteer'   => 'cwg-chip-volunteer',
        ];

        ob_start();
        ?>
        <div class="cwg-grid cwg-cols-<?php echo $cols; ?> cwg-events-snippet">
            <?php while ( $query->have_posts() ): $query->the_post();
                $pid  = get_the_ID();
                $wcp  = wc_get_product( $pid );
                if ( ! $wcp ) continue;

                // Type
                $terms_p = get_the_terms( $pid, 'product_cat' );
                $type_lbl = ''; $type_key = '';
                if ( $terms_p && ! is_wp_error( $terms_p ) ) {
                    foreach ( $terms_p as $tp ) {
                        $s = strtolower( $tp->slug );
                        if ( false !== strpos( $s, 'competition' ) ) { $type_lbl = 'Competition'; $type_key = 'competition'; break; }
                        if ( $s === 'talk-seminar' || false !== strpos( $s, 'seminar' ) || false !== strpos( $s, 'talk' ) ) { $type_lbl = 'Talk / Seminar'; $type_key = 'seminar'; break; }
                        if ( false !== strpos( $s, 'running' ) )   { $type_lbl = 'Running';    $type_key = 'running';    break; }
                        if ( false !== strpos( $s, 'volunteer' ) ) { $type_lbl = 'Volunteer';  $type_key = 'volunteer';  break; }
                        if ( false !== strpos( $s, 'workshop' ) )  { $type_lbl = 'Workshop';   $type_key = 'workshop';   break; }
                        if ( false !== strpos( $s, 'community' ) ) { $type_lbl = 'Community';  $type_key = 'community';  break; }
                    }
                    if ( ! $type_lbl ) { $t0 = reset( $terms_p ); $type_lbl = $t0->name; $type_key = $t0->slug; }
                }

                // Meta
                $start     = get_post_meta( $pid, 'cw_submission_start', true );
                $deadline  = get_post_meta( $pid, 'submission_deadline', true );
                $is_closed = $deadline && strtotime( $deadline ) < current_time('timestamp');
                $date_str  = $start ? date_i18n( 'j M Y (l)', strtotime($start) ) : '—';
                $time_str  = $start ? date_i18n( 'g:i A', strtotime($start) ) . ' (GMT +08:00)' : '';
                $price     = floatval( $wcp->get_price() );
                $cert_type = get_post_meta( $pid, 'cw_certificate_type', true );
                $fee_text  = $price > 0 ? 'RM ' . number_format($price,2) : ( $cert_type ? 'E Certificate' : 'Free' );
                $org_id    = get_post_meta( $pid, 'organizer_id', true );
                $org_name  = $org_id ? ( get_user_meta( $org_id, 'business_name', true ) ?: 'Host' ) : 'Host';
                $thumb     = get_the_post_thumbnail_url( $pid, 'medium_large' );
                $already   = isset( $joined_pids[$pid] );

                // SDG
                $sdg_raw = get_post_meta( $pid, 'sdg_goals', true );
                $sdg_icons_d = [];
                if ( is_array($sdg_raw) ) foreach ( $sdg_raw as $n => $v ) if ( $v === 'true' && isset($sdg_names[$n]) ) $sdg_icons_d[] = [ 'num' => $sdg_names[$n], 'name' => $n ];
            ?>
            <div class="cwg-card<?php echo $is_closed ? ' cwg-card-closed' : ''; ?>">
                <a href="<?php the_permalink(); ?>" class="cwg-card-cover">
                    <?php if ( $thumb ): ?>
                        <img src="<?php echo esc_url($thumb); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
                    <?php else: ?>
                        <div class="cwg-cover-ph"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <?php if ( $type_lbl ): ?><span class="cwg-chip <?php echo esc_attr( $chip_colors[$type_key] ?? '' ); ?>"><?php echo esc_html($type_lbl); ?></span><?php endif; ?>
                    <?php if ( $is_closed ): ?>
                        <span class="cwg-closed-ribbon"><i class="fas fa-lock"></i> Closed</span>
                    <?php elseif ( $already ): ?>
                        <span class="cwg-joined-ribbon"><i class="fas fa-check"></i> Joined</span>
                    <?php endif; ?>
                </a>
                <div class="cwg-card-body">
                    <a href="<?php the_permalink(); ?>"><h3 class="cwg-card-title"><?php the_title(); ?></h3></a>
                    <p class="cwg-card-org"><i class="fas fa-building"></i> <?php echo esc_html($org_name); ?></p>
                    <ul class="cwg-card-meta">
                        <li><i class="fas fa-calendar-alt"></i> <?php echo esc_html($date_str); ?></li>
                        <?php if ($time_str): ?><li><i class="fas fa-clock"></i> <?php echo esc_html($time_str); ?></li><?php endif; ?>
                        <li><i class="fas fa-tag"></i> <?php echo esc_html($fee_text); ?></li>
                    </ul>
                    <?php if ( ! empty($sdg_icons_d) ): ?>
                    <div class="cwg-sdg-section">
                        <p class="cwg-sdg-label">Sustainable Development Goals (SDGs)</p>
                        <div class="cwg-sdg-icons">
                            <?php foreach ($sdg_icons_d as $sg): $pad = str_pad($sg['num'],2,'0',STR_PAD_LEFT); ?>
                            <img src="<?php echo esc_url($sdg_base.'E_WEB_'.$pad.'.png'); ?>" alt="SDG <?php echo $sg['num']; ?>" title="<?php echo esc_attr($sg['name']); ?>" class="cwg-sdg-icon" loading="lazy">
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ( $is_closed ): ?>
                        <button class="cwg-cta cwg-cta-closed" disabled><i class="fas fa-lock"></i> Campaign Closed</button>
                    <?php elseif ( $already ): ?>
                        <a href="<?php the_permalink(); ?>" class="cwg-cta cwg-cta-joined"><i class="fas fa-check-circle"></i> Already Joined</a>
                    <?php else: ?>
                        <a href="<?php the_permalink(); ?>" class="cwg-cta cwg-cta-join">View &amp; Join <i class="fas fa-arrow-right"></i></a>
                    <?php endif; ?>
                </div>
            </div>
            <?php endwhile; wp_reset_postdata(); ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       8. EVENT GRID SHORTCODES
       [cw_activities_grid]   — activities + talk/seminar combined
       [cw_competitions_grid] — competitions only
       ========================================================================== */

    /** Proxy: competitions grid */
    public function render_competitions_grid( $atts ) {
        $atts = shortcode_atts( [ 'parent_cats' => 'competitions', 'columns' => 3 ], $atts, 'cw_competitions_grid' );
        return $this->render_event_grid( $atts );
    }

    /** Proxy: activities grid (shows activities + talk/seminar by default) */
    public function render_activities_grid( $atts ) {
        $atts = shortcode_atts( [ 'parent_cats' => 'activities,talk-seminar', 'columns' => 3 ], $atts, 'cw_activities_grid' );
        return $this->render_event_grid( $atts );
    }

    /** Core rendering method used by both shortcodes */
    private function render_event_grid( $atts ) {
        $parent_cat_slugs = array_filter( array_map( 'trim', explode( ',', $atts['parent_cats'] ) ) );
        $columns          = max( 1, intval( $atts['columns'] ) );

        // ── URL state ──────────────────────────────────────────────────────────
        $active_cat  = sanitize_text_field( $_GET['tab'] ?? ($_GET['product_category'] ?? '') );
        $search_term = sanitize_text_field( $_GET['cw_q'] ?? '' );
        $paged       = max( 1, intval( $_GET['cw_page'] ?? 1 ) );
        $per_page    = 9;
        $base_url    = get_permalink();

        // ── Resolve all parent terms + their sub-categories ───────────────────
        $all_parent_terms = [];
        $all_sub_cats     = [];   // flat list for filter tabs
        $all_term_ids     = [];   // used when no active_cat

        foreach ( $parent_cat_slugs as $pslug ) {
            $pterm = get_term_by( 'slug', $pslug, 'product_cat' );
            if ( ! $pterm || is_wp_error( $pterm ) ) continue;
            $all_parent_terms[] = $pterm;
            $all_term_ids[]     = $pterm->term_id;

            $subs = get_terms([
                'taxonomy'   => 'product_cat',
                'parent'     => $pterm->term_id,
                'hide_empty' => true,
                'orderby'    => 'name',
            ]);
            if ( $subs && ! is_wp_error( $subs ) ) {
                foreach ( $subs as $sub ) {
                    $all_sub_cats[] = $sub;
                    $all_term_ids[] = $sub->term_id;
                }
            }
        }

        if ( empty( $all_parent_terms ) ) {
            return '<p>No categories found for: ' . esc_html( $atts['parent_cats'] ) . '</p>';
        }

        // "All" label: single parent → its name, multiple → "All Events"
        $all_label = count( $all_parent_terms ) === 1
            ? 'All ' . ucwords( $all_parent_terms[0]->name )
            : 'All Campaigns';

        // ── Tax query ──────────────────────────────────────────────────────────
        if ( $active_cat ) {
            $tax_q = [[ 'taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $active_cat, 'include_children' => true ]];
        } else {
            $tax_q = [[ 'taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => array_unique( $all_term_ids ) ]];
        }

        $query_args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'tax_query'      => $tax_q,
        ];
        if ( $search_term ) {
            // Use 'search_product_title' to search only by title without triggering WP global search mode
            $query_args['_cw_search'] = $search_term;
        }

        $query       = new WP_Query( $query_args );
        $total_pages = $query->max_num_pages;

        // ── SDG data ───────────────────────────────────────────────────────────
        $sdg_base  = 'https://creativewings.asia/wp-content/uploads/2025/12/';
        $sdg_names = [ 'No Poverty'=>1,'Zero Hunger'=>2,'Good Health and Well-Being'=>3,'Quality Education'=>4,'Gender Equality'=>5,'Clean Water and Sanitation'=>6,'Affordable and Clean Energy'=>7,'Decent Work and Economic Growth'=>8,'Industry, Innovation, and Infrastructure'=>9,'Reduced Inequalities'=>10,'Sustainable Cities and Communities'=>11,'Responsible Consumption and Production'=>12,'Climate Action'=>13,'Life Below Water'=>14,'Life on Land'=>15,'Peace, Justice, and Strong Institutions'=>16,'Partnerships for the Goals'=>17 ];

        // ── Category icon map ──────────────────────────────────────────────────
        $tab_icons = [
            'community'      => 'fa-users',
            'running'        => 'fa-running',
            'seminar'        => 'fa-chalkboard-teacher',
            'talk'           => 'fa-microphone-alt',
            'talk-seminar'   => 'fa-microphone-alt',
            'volunteer-work' => 'fa-hands-helping',
            'workshop'       => 'fa-tools',
            'competition'    => 'fa-trophy',
            'competitions'   => 'fa-trophy',
            'activities'     => 'fa-calendar-check',
        ];

        // ── Already-joined check (logged-in users) ─────────────────────────────
        $joined_pids = [];
        $uid = get_current_user_id();
        if ( $uid ) {
            $joined = get_posts([
                'post_type'      => ['cw_activity_entry', 'cw_competition_entry'],
                'meta_key'       => 'customer_id',
                'meta_value'     => $uid,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            foreach ( $joined as $eid ) {
                $epid = (int) get_post_meta( $eid, 'product_id', true );
                if ( $epid ) $joined_pids[$epid] = true;
            }
        }

        ob_start();
        ?>
        <div class="cwg-wrap" id="cwg-top">

            <!-- ── Filter Tabs ── -->
            <div class="cwg-tabs-wrap">
                <div class="cwg-tabs">
                    <a href="<?php echo esc_url( $base_url ); ?>"
                       class="cwg-tab <?php echo ! $active_cat ? 'active' : ''; ?>">
                        <i class="fas fa-th-large"></i>
                        <span><?php echo esc_html( $all_label ); ?></span>
                    </a>
                    <?php foreach ( $all_sub_cats as $sc ):
                        $icon = $tab_icons[ $sc->slug ] ?? 'fa-flag';
                    ?>
                    <a href="<?php echo esc_url( add_query_arg( [ 'tab' => $sc->slug, 'cw_page' => 1 ], $base_url ) ); ?>"
                       class="cwg-tab <?php echo ( $active_cat === $sc->slug ) ? 'active' : ''; ?>">
                        <i class="fas <?php echo esc_attr( $icon ); ?>"></i>
                        <span><?php echo esc_html( $sc->name ); ?></span>
                    </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- ── Active filter bar ── -->
            <?php if ( $search_term || $active_cat ): ?>
            <div class="cwg-filter-bar">
                <span class="cwg-filter-bar-text">
                    <?php if ( $search_term ): ?>
                        <i class="fas fa-search"></i> Results for "<strong><?php echo esc_html( $search_term ); ?></strong>"
                        <?php if ( $active_cat ): $t_obj = get_term_by('slug', $active_cat, 'product_cat'); ?>
                            &nbsp;in <strong><?php echo esc_html( $t_obj ? $t_obj->name : $active_cat ); ?></strong>
                        <?php endif; ?>
                    <?php elseif ( $active_cat ): $t_obj = get_term_by('slug', $active_cat, 'product_cat'); ?>
                        <i class="fas fa-filter"></i> <?php echo esc_html( $t_obj ? $t_obj->name : $active_cat ); ?>
                    <?php endif; ?>
                </span>
                <a href="<?php echo esc_url( $base_url ); ?>" class="cwg-filter-clear">
                    <i class="fas fa-times"></i> Clear filters
                </a>
            </div>
            <?php endif; ?>

            <!-- ── Card Grid ── -->
            <?php if ( $query->have_posts() ): ?>
            <div class="cwg-grid cwg-cols-<?php echo $columns; ?>">
                <?php while ( $query->have_posts() ): $query->the_post();
                    $pid  = get_the_ID();
                    $wcp  = wc_get_product( $pid );
                    if ( ! $wcp ) continue;

                    // Type detection
                    $terms_p  = get_the_terms( $pid, 'product_cat' );
                    $type_lbl = ''; $type_key = '';
                    if ( $terms_p && ! is_wp_error( $terms_p ) ) {
                        foreach ( $terms_p as $tp ) {
                            $s = strtolower( $tp->slug );
                            if ( false !== strpos( $s, 'competition' ) ) { $type_lbl = 'Competition'; $type_key = 'competition'; break; }
                            if ( $s === 'talk-seminar' || false !== strpos( $s, 'seminar' ) || false !== strpos( $s, 'talk' ) ) { $type_lbl = 'Talk / Seminar'; $type_key = 'seminar'; break; }
                            if ( false !== strpos( $s, 'running' ) )    { $type_lbl = 'Running';    $type_key = 'running';    break; }
                            if ( false !== strpos( $s, 'volunteer' ) )  { $type_lbl = 'Volunteer';  $type_key = 'volunteer';  break; }
                            if ( false !== strpos( $s, 'workshop' ) )   { $type_lbl = 'Workshop';   $type_key = 'workshop';   break; }
                            if ( false !== strpos( $s, 'community' ) )  { $type_lbl = 'Community';  $type_key = 'community';  break; }
                        }
                        if ( ! $type_lbl ) { $t0 = reset( $terms_p ); $type_lbl = $t0->name; $type_key = $t0->slug; }
                    }

                    // Meta
                    $start       = get_post_meta( $pid, 'cw_submission_start', true );
                    $deadline    = get_post_meta( $pid, 'submission_deadline', true );
                    $is_closed   = $deadline && strtotime( $deadline ) < current_time( 'timestamp' );
                    $date_str    = $start ? date_i18n( 'j M Y (l)', strtotime( $start ) ) : '—';
                    $time_str    = $start ? date_i18n( 'g:i A', strtotime( $start ) ) . ' (GMT +08:00)' : '';
                    $price       = floatval( $wcp->get_price() );
                    $cert_type   = get_post_meta( $pid, 'cw_certificate_type', true );
                    if ( $price > 0 ) {
                        $fee_text = 'RM ' . number_format( $price, 2 );
                    } elseif ( $cert_type ) {
                        $fee_text = 'E Certificate';
                    } else {
                        $fee_text = 'Free';
                    }

                    $org_id      = get_post_meta( $pid, 'organizer_id', true );
                    $org_name    = $org_id ? ( get_user_meta( $org_id, 'business_name', true ) ?: 'Host' ) : 'Host';
                    $thumb       = get_the_post_thumbnail_url( $pid, 'medium_large' );

                    // SDG
                    $sdg_raw     = get_post_meta( $pid, 'sdg_goals', true );
                    $sdg_icons_d = [];
                    if ( is_array( $sdg_raw ) ) {
                        foreach ( $sdg_raw as $n => $v ) {
                            if ( $v === 'true' && isset( $sdg_names[$n] ) ) {
                                $sdg_icons_d[] = [ 'num' => $sdg_names[$n], 'name' => $n ];
                            }
                        }
                    }

                    $already = isset( $joined_pids[$pid] );
                ?>
                <div class="cwg-card<?php echo $is_closed ? ' cwg-card-closed' : ''; ?>">
                    <a href="<?php the_permalink(); ?>" class="cwg-card-cover">
                        <?php if ( $thumb ): ?>
                            <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( get_the_title() ); ?>" loading="lazy">
                        <?php else: ?>
                            <div class="cwg-cover-ph"><i class="fas fa-image"></i></div>
                        <?php endif; ?>
                        <?php if ( $type_lbl ): ?>
                            <span class="cwg-chip cwg-chip-<?php echo esc_attr( $type_key ); ?>"><?php echo esc_html( $type_lbl ); ?></span>
                        <?php endif; ?>
                        <?php if ( $is_closed ): ?>
                            <span class="cwg-closed-ribbon"><i class="fas fa-lock"></i> Closed</span>
                        <?php elseif ( $already ): ?>
                            <span class="cwg-joined-ribbon"><i class="fas fa-check"></i> Joined</span>
                        <?php endif; ?>
                    </a>

                    <div class="cwg-card-body">
                        <a href="<?php the_permalink(); ?>">
                            <h3 class="cwg-card-title"><?php the_title(); ?></h3>
                        </a>
                        <p class="cwg-card-org"><i class="fas fa-building"></i> <?php echo esc_html( $org_name ); ?></p>

                        <ul class="cwg-card-meta">
                            <li><i class="fas fa-calendar-alt"></i> <?php echo esc_html( $date_str ); ?></li>
                            <?php if ( $time_str ): ?>
                            <li><i class="fas fa-clock"></i> <?php echo esc_html( $time_str ); ?></li>
                            <?php endif; ?>
                            <li><i class="fas fa-tag"></i> <?php echo esc_html( $fee_text ); ?></li>
                        </ul>

                        <?php if ( ! empty( $sdg_icons_d ) ): ?>
                        <div class="cwg-sdg-section">
                            <p class="cwg-sdg-label">Sustainable Development Goals (SDGs)</p>
                            <div class="cwg-sdg-icons">
                                <?php foreach ( $sdg_icons_d as $sg ):
                                    $pad_n = str_pad( $sg['num'], 2, '0', STR_PAD_LEFT );
                                ?>
                                <img src="<?php echo esc_url( $sdg_base . 'E_WEB_' . $pad_n . '.png' ); ?>"
                                     alt="SDG <?php echo $sg['num']; ?>"
                                     title="<?php echo esc_attr( $sg['name'] ); ?>"
                                     class="cwg-sdg-icon" loading="lazy">
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <?php if ( $is_closed ): ?>
                            <button class="cwg-cta cwg-cta-closed" disabled><i class="fas fa-lock"></i> Campaign Closed</button>
                        <?php elseif ( $already ): ?>
                            <a href="<?php the_permalink(); ?>" class="cwg-cta cwg-cta-joined"><i class="fas fa-check-circle"></i> Already Joined</a>
                        <?php else: ?>
                            <a href="<?php the_permalink(); ?>" class="cwg-cta cwg-cta-join">View &amp; Join <i class="fas fa-arrow-right"></i></a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>

            <!-- ── Pagination ── -->
            <?php if ( $total_pages > 1 ):
                $pg_base = $active_cat ? add_query_arg( 'tab', $active_cat, $base_url ) : $base_url;
                if ( $search_term ) $pg_base = add_query_arg( 'cw_q', $search_term, $pg_base );
            ?>
            <div class="cwg-pagination">
                <?php if ( $paged > 1 ): ?>
                    <a href="<?php echo esc_url( add_query_arg( 'cw_page', $paged - 1, $pg_base ) ); ?>" class="cwg-pg-btn cwg-pg-arrow"><i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ( $p = 1; $p <= $total_pages; $p++ ): ?>
                    <?php if ( $p == 1 || $p == $total_pages || abs( $p - $paged ) <= 1 ): ?>
                        <a href="<?php echo esc_url( add_query_arg( 'cw_page', $p, $pg_base ) ); ?>"
                           class="cwg-pg-btn <?php echo ( $p == $paged ) ? 'active' : ''; ?>"><?php echo $p; ?></a>
                    <?php elseif ( abs( $p - $paged ) == 2 ): ?>
                        <span class="cwg-pg-ellipsis">…</span>
                    <?php endif; ?>
                <?php endfor; ?>
                <?php if ( $paged < $total_pages ): ?>
                    <a href="<?php echo esc_url( add_query_arg( 'cw_page', $paged + 1, $pg_base ) ); ?>" class="cwg-pg-btn cwg-pg-arrow"><i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
            <div class="cwg-empty">
                <div class="cwg-empty-icon"><i class="fas fa-search"></i></div>
                <h3>No campaigns found</h3>
                <p><?php echo $search_term ? 'Try a different keyword or clear the filter.' : 'No activities are available right now. Check back soon!'; ?></p>
                <?php if ( $search_term || $active_cat ): ?>
                    <a href="<?php echo esc_url( $base_url ); ?>" class="cwg-cta cwg-cta-join">View All Activities</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

        </div><!-- .cwg-wrap -->
        <?php
        return ob_get_clean();
    }
}