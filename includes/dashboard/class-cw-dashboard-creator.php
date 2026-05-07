<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Dashboard_Creator {

    // The CCT Slug (matches JetEngine CCT slug)
    private $cct_slug = 'jet_cct_creator_portfolio';

    public function __construct() {
        // 1. Tab Content Injection
        add_action( 'woocommerce_account_cw-profile_endpoint', [ $this, 'render_profile' ] );
        add_action( 'woocommerce_account_cw-portfolio_endpoint', [ $this, 'render_portfolio' ] );
        add_action( 'woocommerce_account_cw-saved_endpoint', [ $this, 'render_saved' ] );
        
        // Separate Endpoints
        add_action( 'woocommerce_account_cw-my-activities_endpoint', [ $this, 'render_my_activities' ] );
        
        // New Explore Endpoint (if you added it to Manager)
        add_action( 'woocommerce_account_cw-explore_endpoint', [ $this, 'render_explore_opportunities' ] );

        // 2. Form Handlers
        add_action( 'admin_post_cw_save_creative_profile', [ $this, 'handle_save_profile' ] );
        add_action( 'admin_post_cw_save_portfolio', [ $this, 'handle_save_portfolio' ] );
        add_action( 'admin_post_cw_delete_portfolio', [ $this, 'handle_delete_portfolio' ] );
    }

    /* ==========================================================================
       HELPERS
       ========================================================================== */
    
    /**
     * Helper to get full table name dynamically based on WP Prefix
     */
    private function get_table_name() {
        global $wpdb;
        return $wpdb->prefix . $this->cct_slug;
    }
    
    private function get_real_graph_data($creator_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'cw_profile_views';
    $chart_data = [];

    // Loop through the last 7 days
    for ($i = 6; $i >= 0; $i--) {
        $date = date('Y-m-d', strtotime("-$i days"));
        $day_name = date('D', strtotime($date)); // e.g., "Mon"

        // Count how many rows exist for this creator on this specific date
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $table_name WHERE creator_id = %d AND view_date = %s",
            $creator_id, $date
        ));

        $chart_data[] = [
            'name' => $day_name,
            'views' => (int)$count
        ];
    }
    return $chart_data;
}

    /**
     * Helper to handle image uploads for profile/portfolio
     */
    private function handle_image_upload($uid, $file_key){
        if(!empty($_FILES[$file_key]['name'])){
            require_once(ABSPATH.'wp-admin/includes/image.php');
            require_once(ABSPATH.'wp-admin/includes/file.php');
            require_once(ABSPATH.'wp-admin/includes/media.php');
            $aid=media_handle_upload($file_key,0);
            if(!is_wp_error($aid)) update_user_meta($uid, $file_key, ['id'=>$aid, 'url'=>wp_get_attachment_url($aid)]);
        }
    }

    /* ==========================================================================
       1. OVERVIEW TAB
       ========================================================================== */
   public function render_overview() {
        $uid = get_current_user_id();
        $u   = get_userdata( $uid );
        global $wpdb; 
        
        // 1. Get REAL Graph Data from the custom table
        $chart_data = $this->get_real_graph_data($uid);
        
        // 2. Count Portfolio Items
        $table = $this->get_table_name();
        $port_count = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_by = %d", $uid ) );
        $recent_ports = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE created_by = %d ORDER BY _ID DESC LIMIT 3", $uid ) );

        // 3. Get Submission Stats
        $submissions = get_posts([
            'post_type' => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key' => 'customer_id',
            'meta_value' => $uid,
            'posts_per_page' => -1,
            'fields' => 'ids'
        ]);
        $entries_total = count($submissions);
        $active_engagements = 0;
        $completed_entries  = 0;
        foreach ($submissions as $sid) {
            $score = get_post_meta($sid, 'judge_score', true);
            if ($score === '') $active_engagements++;
            else $completed_entries++;
        }

        // 4. Get Total Views for the stat box
        $views = (int) get_user_meta( $uid, 'cw_profile_views', true );

        // 5. Setup Profile Info
        $display_name = get_user_meta($uid, 'creator_display_name', true) ?: $u->display_name;
        $profile_url  = home_url('/profile/' . $u->user_login);

        ?>
        <?php
        $my_account_url  = get_permalink( wc_get_page_id('myaccount') );
        $portfolio_tab   = add_query_arg('tab', 'portfolio', $my_account_url);
        $profile_tab     = add_query_arg('tab', 'profile',   $my_account_url);
        $activities_tab  = add_query_arg('tab', 'activities', $my_account_url);
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-overview-header">
                <div>
                    <h1>Welcome back, <?php echo esc_html($display_name); ?> 👋</h1>
                    <p>Here's what's happening with your creative profile today.</p>
                </div>
                <a href="<?php echo esc_url($profile_url); ?>" target="_blank" class="cw-public-profile-link">
                    <i class="fas fa-external-link-alt"></i> View Public Profile
                </a>
            </div>

            <!-- 4-stat grid -->
            <div class="cw-overview-stats-grid cw-stats-4col">
                <div class="cw-stat-box-small">
                    <div class="icon"><i class="fas fa-eye" style="color:var(--cw-primary);"></i></div>
                    <h3><?php echo number_format($views); ?></h3>
                    <span>Profile Views</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="icon"><i class="fas fa-briefcase" style="color:#7c3aed;"></i></div>
                    <h3><?php echo number_format((int)$port_count); ?></h3>
                    <span>Portfolio Items</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="icon"><i class="fas fa-fire" style="color:var(--cw-accent);"></i></div>
                    <h3><?php echo number_format($active_engagements); ?></h3>
                    <span>Active Events</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="icon"><i class="fas fa-check-circle" style="color:var(--cw-success);"></i></div>
                    <h3><?php echo number_format($completed_entries); ?></h3>
                    <span>Completed</span>
                </div>
            </div>

            <!-- Chart + Quick Actions -->
            <div class="cw-main-content-grid">
                <div class="cw-chart-panel">
                    <h3>Profile Traffic <span style="font-size:12px;font-weight:500;color:var(--cw-muted);">Last 7 Days</span></h3>
                    <div id="trafficChartContainer" style="height:230px; width:100%;">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>

                <div class="cw-quick-actions">
                    <h3>Quick Actions</h3>
                    <div class="cw-quick-action-list">
                        <a href="<?php echo esc_url($portfolio_tab); ?>" onclick="openPortfolioModal(); return false;" class="cw-action-item">
                            <div class="icon-wrap cw-iw-blue"><i class="fas fa-plus"></i></div>
                            <div class="text-wrap">
                                <span>Add New Project</span>
                                <small>Upload to your portfolio</small>
                            </div>
                        </a>
                        <a href="<?php echo esc_url($profile_tab); ?>" class="cw-action-item">
                            <div class="icon-wrap cw-iw-purple"><i class="fas fa-pen"></i></div>
                            <div class="text-wrap">
                                <span>Edit Profile</span>
                                <small>Update bio &amp; links</small>
                            </div>
                        </a>
                        <a href="<?php echo esc_url($activities_tab); ?>" class="cw-action-item">
                            <div class="icon-wrap cw-iw-teal"><i class="fas fa-list-check"></i></div>
                            <div class="text-wrap">
                                <span>My Activities</span>
                                <small>Track your submissions</small>
                            </div>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Recent Portfolio Strip -->
            <?php if ($recent_ports): ?>
            <div class="cw-recent-section">
                <div class="cw-recent-head">
                    <h3>Recent Portfolio</h3>
                    <a href="<?php echo esc_url($portfolio_tab); ?>" class="cw-public-profile-link">View All →</a>
                </div>
                <div class="cw-recent-portfolio-row">
                    <?php foreach ($recent_ports as $rp):
                        $rp_img_data = maybe_unserialize($rp->image);
                        $rp_img      = (is_array($rp_img_data) && isset($rp_img_data['url'])) ? $rp_img_data['url'] : '';
                    ?>
                    <div class="cw-recent-pf-card">
                        <div class="cw-recent-pf-thumb" <?php if($rp_img): ?>style="background-image:url('<?php echo esc_url($rp_img); ?>')"<?php endif; ?>>
                            <?php if(!$rp_img): ?><i class="fas fa-image"></i><?php endif; ?>
                        </div>
                        <div class="cw-recent-pf-info">
                            <span class="cw-recent-pf-cat"><?php echo esc_html($rp->category ?: '—'); ?></span>
                            <p><?php echo esc_html($rp->title); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('trafficChart');
            if(ctx && typeof Chart !== 'undefined') {
                // Pass the real PHP data array to Javascript
                const chartData = <?php echo json_encode($chart_data); ?>;
                
                new Chart(ctx, { 
                    type: 'bar', 
                    data: { 
                        labels: chartData.map(d => d.name), 
                        datasets: [{
                            label: 'Views',
                            data: chartData.map(d => d.views),
                            backgroundColor: '#006599',
                            borderRadius: 6,
                            borderSkipped: false,
                        }] 
                    }, 
                    options: { 
                        responsive: true, 
                        maintainAspectRatio: false, 
                        plugins: { legend: { display: false } }, 
                        scales: { 
                            y: { beginAtZero: true, grid: { display: false } }, 
                            x: { grid: { display: false } } 
                        } 
                    } 
                });
            }
        });
        </script>
        <?php
    }
    /* ==========================================================================
       2. EDIT PROFILE (Nova UI Overhaul - Final Version)
       ========================================================================== */
    public function render_profile() {
        $uid = get_current_user_id();
        $fields = ['creator_display_name', 'creator_tagline', 'creator_bio', 'awards_won', 'creator_skills', 'website_url', 'linkeden_url', 'instagram_url', 'twitter_url', 'Facebook_url', 'behave_url', 'creator_address']; // Added creator_address
$meta = []; foreach( $fields as $f ) $meta[$f] = get_user_meta( $uid, $f, true );

        $img_data = get_user_meta( $uid, 'creator_profile_image', true );
        $img_url  = ( is_array( $img_data ) && isset( $img_data['url'] ) ) ? $img_data['url'] : get_avatar_url( $uid );
        $hdr_data = get_user_meta( $uid, 'creator_header_image', true );
        $hdr_url  = ( is_array( $hdr_data ) && isset( $hdr_data['url'] ) ) ? $hdr_data['url'] : CW_URL . 'assets/img/default-header.jpg';

        if ( isset($_GET['updated']) ) echo '<div class="cw-alert success">Profile updated successfully.</div>';
        
        $skills_array = array_filter(array_map('trim', explode(',', $meta['creator_skills'])));
        ?>
        <div class="cw-content-wrapper">
            <h2 class="text-2xl font-bold text-gray-900">Profile Settings</h2>
            <p class="text-gray-500">Manage your public presence and personal information.</p>
            
            <div class="cw-profile-settings-card">
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" enctype="multipart/form-data" class="cw-modern-form">
                    <input type="hidden" name="action" value="cw_save_creative_profile">
                    <?php wp_nonce_field('cw_profile_nonce'); ?>
                    
                    <!-- 1. Banner/Avatar Composite Section -->
                    <div class="cw-settings-header">
                        <img src="<?php echo esc_url($hdr_url); ?>" alt="Header Banner" class="cw-banner-image" />
                        
                        <!-- Upload Button Overlay (Header) -->
                        <label for="creator_header_image_upload" class="cw-header-upload-btn">
                            <i class="fas fa-camera"></i> Change Banner
                        </label>
                        <input type="file" id="creator_header_image_upload" name="creator_header_image" accept="image/*" style="display:none;">

                        <!-- Avatar -->
                        <div class="cw-avatar-composite">
                            <img src="<?php echo esc_url($img_url); ?>" alt="Profile Avatar" class="cw-profile-avatar" />
                            <div class="cw-profile-status-dot"></div>
                            
                            <!-- Avatar Upload Button (Overlays avatar) -->
                            <label for="creator_profile_image_upload" style="position:absolute; inset:0; border-radius:50%; cursor:pointer;"></label>
                            <input type="file" id="creator_profile_image_upload" name="creator_profile_image" accept="image/*" style="display:none;" onchange="if(this.files[0]) this.closest('.cw-avatar-composite').querySelector('img').src = window.URL.createObjectURL(this.files[0])">
                        </div>
                    </div>

                    <!-- 2. Form Content Area -->
                    <div class="cw-settings-content">
                        <div class="cw-form-grid" style="grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Display Name / Tagline -->
                            <div class="cw-field-dark"><label>Display Name</label><input type="text" name="creator_display_name" value="<?php echo esc_attr($meta['creator_display_name']); ?>" class="cw-input-dark-v2" placeholder="Alex Morgan"></div>
                            <div class="cw-field-dark"><label>Tagline</label><input type="text" name="creator_tagline" value="<?php echo esc_attr($meta['creator_tagline']); ?>" class="cw-input-dark-v2" placeholder="Senior UI/UX Designer & Digital Artist"></div>
                            
                            <!-- Bio (Rich Text) -->
                            <div class="cw-field-dark full cw-rich-text-area"><label>Bio (Rich Text)</label><?php wp_editor( $meta['creator_bio'], 'creator_bio_editor', ['textarea_name' => 'creator_bio', 'media_buttons' => false, 'textarea_rows' => 4, 'teeny' => true, 'quicktags' => false, 'editor_class' => 'cw-slim-editor-dark'] ); ?></div>
                            
                            <!-- Skills Input -->
                            <div class="cw-field-dark full">
                                <label>Skills (Press Enter to add)</label>
                                <div id="cw-skills-wrapper" class="cw-tags-input-container">
                                    <?php foreach($skills_array as $skill): ?>
                                       <span class="cw-tag-v2" data-skill="<?php echo esc_attr($skill); ?>"><?php echo esc_html($skill); ?> <i class="fas fa-times" onclick="removeSkill(event, '<?php echo esc_attr($skill); ?>')"></i></span>
                                    <?php endforeach; ?>
                                    <input type="text" id="cw-skills-input" class="cw-input-ghost" placeholder="Add a skill...">
                                </div>
                                <input type="hidden" name="creator_skills" id="cw_skills_hidden" value="<?php echo esc_attr(implode(',', $skills_array)); ?>">
                            </div>
                            
                            <!-- Awards Won - Retained from original fields -->
                            <div class="cw-field-dark full"><label>Awards Won</label><input type="text" name="awards_won" value="<?php echo esc_attr($meta['awards_won']); ?>" class="cw-input-dark-v2" placeholder="Best in Show 2023, Digital Artist Finalist 2024"></div>
                        </div>
                        
                        <div class="cw-field-dark full">
    <label>Location Address</label>
    <input type="text" name="creator_address" value="<?php echo esc_attr($meta['creator_address']); ?>" class="cw-input-dark-v2" placeholder="e.g. San Francisco, CA">
</div>

                        <h4 style="margin-top:40px;">Social Links</h4>
                        
                        <div class="cw-form-grid" style="grid-template-columns: 1fr 1fr; gap: 20px;">
                            <!-- Website / Behance -->
                            <div class="cw-field-dark cw-social-input-wrap"><i class="fas fa-globe"></i><input type="url" name="website_url" value="<?php echo esc_attr($meta['website_url']); ?>" class="cw-input-dark-v2" placeholder="https://alexmorgan.design"></div>
                            <div class="cw-field-dark cw-social-input-wrap"><i class="fab fa-behance"></i><input type="url" name="behave_url" value="<?php echo esc_attr($meta['behave_url']); ?>" class="cw-input-dark-v2" placeholder="behance.net/alexm"></div>
                            
                            <!-- Instagram / Twitter -->
                            <div class="cw-field-dark cw-social-input-wrap"><i class="fab fa-instagram"></i><input type="url" name="instagram_url" value="<?php echo esc_attr($meta['instagram_url']); ?>" class="cw-input-dark-v2" placeholder="@alexm_design"></div>
                            <div class="cw-field-dark cw-social-input-wrap"><i class="fab fa-twitter"></i><input type="url" name="twitter_url" value="<?php echo esc_attr($meta['twitter_url']); ?>" class="cw-input-dark-v2" placeholder="Twitter Handle"></div>
                            
                            <!-- Facebook / LinkedIn (Retained for completeness) -->
                            <?php if ($meta['Facebook_url'] || $meta['linkeden_url']): ?>
                                <div class="cw-field-dark cw-social-input-wrap"><i class="fab fa-facebook"></i><input type="url" name="Facebook_url" value="<?php echo esc_attr($meta['Facebook_url']); ?>" class="cw-input-dark-v2" placeholder="Facebook URL"></div>
                                <div class="cw-field-dark cw-social-input-wrap"><i class="fab fa-linkedin"></i><input type="url" name="linkeden_url" value="<?php echo esc_attr($meta['linkeden_url']); ?>" class="cw-input-dark-v2" placeholder="LinkedIn URL"></div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="cw-form-footer" style="border-top:none; padding-top:20px;">
                           <button type="submit" class="cw-btn-primary">Save Changes</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Skills JS (Must remain for tagging logic) -->
        <script>
        document.addEventListener('DOMContentLoaded', function() { 
            const wrap = document.getElementById('cw-skills-wrapper');
            const inp = document.getElementById('cw-skills-input');
            const hidden = document.getElementById('cw_skills_hidden'); 
            if(wrap && inp && hidden) {
                let tags = hidden.value ? hidden.value.split(',').map(s=>s.trim()).filter(s=>s) : []; 
                
                function render() {
                    wrap.querySelectorAll('.cw-tag-v2').forEach(e => e.remove()); 
                    tags.forEach((tag) => {
                        const el = document.createElement('span'); 
                        el.className = 'cw-tag-v2';
                        el.setAttribute('data-skill', tag);
                        el.innerHTML = `${tag} <i class="fas fa-times"></i>`; 
                        wrap.insertBefore(el, inp);
                    }); 
                    hidden.value = tags.join(',');
                    // Rebind click listener to the newly created elements
                    wrap.querySelectorAll('.cw-tag-v2 i').forEach(icon => {
                        icon.addEventListener('click', (e) => {
                            e.stopPropagation(); // Stop click from propagating up to the container
                            const skill = e.target.closest('.cw-tag-v2').getAttribute('data-skill');
                            window.removeSkill(skill);
                        });
                    });
                } 
                
                window.removeSkill = function(skill) { 
                    tags = tags.filter(s => s !== skill); 
                    render(); 
                }; 
                
                inp.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter' || e.key === ',') {
                        e.preventDefault();
                        const v = inp.value.trim().replace(/,$/, '');
                        if (v && !tags.includes(v)) { tags.push(v); render(); inp.value = ''; }
                    }
                    if (e.key === 'Backspace' && inp.value === '' && tags.length > 0) { tags.pop(); render(); }
                });
                render();
            }
        });
        </script>
        <?php
    }

    /* ==========================================================================
       3. PORTFOLIO TAB
       ========================================================================== */
    public function render_portfolio() {
        $uid = get_current_user_id();
        global $wpdb; 
        $table = $this->get_table_name();
        $ports = [];
        
        $limit = 6;
        $page = isset($_GET['cw_page']) ? max(1, intval($_GET['cw_page'])) : 1;
        $offset = ($page - 1) * $limit;
        
        $total_items = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $table WHERE created_by = %d", $uid ) );
        if ( $total_items > 0 ) {
            $ports = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table WHERE created_by = %d ORDER BY _ID DESC LIMIT %d OFFSET %d", $uid, $limit, $offset ) );
        }
        $total_pages = ceil( $total_items / $limit );
        
        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $portfolio_url = add_query_arg('tab', 'portfolio', $my_account_url);

        ?>
        <div class="cw-content-wrapper">
            <div class="cw-portfolio-header">
                <div><h2><?php _e('My Portfolio', 'creativewings-core'); ?></h2><p style="color:#666;"><?php _e('Manage the projects displayed on your public profile.', 'creativewings-core'); ?></p></div>
                <button class="cw-btn-primary" onclick="openPortfolioModal()"><i class="fas fa-plus"></i> <?php _e('Add Project', 'creativewings-core'); ?></button>
            </div>
            
            <?php if ( $ports ): ?>
                <div class="cw-portfolio-grid-modern">
                    <?php foreach ( $ports as $p ): 
                        $img_data = maybe_unserialize( $p->image ); 
                        $img_url  = ( is_array( $img_data ) && isset( $img_data['url'] ) ) ? $img_data['url'] : '';
                        $gal_data = maybe_unserialize( $p->gallery ); 
                        $g_urls = []; 
                        if ( is_array( $gal_data ) ) { foreach ( $gal_data as $g ) { if ( isset( $g['url'] ) ) $g_urls[] = $g['url']; } }
                        
                        $del_url = wp_nonce_url( admin_url( 'admin-post.php?action=cw_delete_portfolio&pid=' . $p->_ID . '&redirect_to=' . urlencode($portfolio_url) ), 'cw_del_' . $p->_ID );
                        $edit_json = htmlspecialchars( json_encode([
                            '_ID'         => $p->_ID, 
                            'title'       => $p->title, 
                            'category'    => $p->category, 
                            'description' => $p->description, 
                            'img_url'     => $img_url, 
                            'gallery'     => $g_urls
                        ]), ENT_QUOTES, 'UTF-8' );
                    ?>
                    <div class="cw-project-card-v2" onclick="viewPortfolio(<?php echo $edit_json; ?>)">
                        <div class="cw-project-image-wrap">
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr($p->title); ?>" />
                            <div class="cw-project-card-overlay">
                                <button class="cw-overlay-btn" onclick="event.stopPropagation(); editProject(<?php echo $edit_json; ?>)"><i class="fas fa-edit"></i></button>
                                <a href="<?php echo $del_url; ?>" class="cw-overlay-btn" onclick="event.stopPropagation(); return confirm('Delete?')"><i class="fas fa-trash-alt"></i></a>
                            </div>
                        </div>
                        <div class="cw-project-card-info">
                            <div class="category"><?php echo esc_html($p->category); ?></div>
                            <h3 class="title"><?php echo esc_html( $p->title ); ?></h3>
                            <div class="text-xs text-gray-500">Added on: <?php echo get_date_from_gmt($p->cct_created, 'Y-m-d'); ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php $this->render_pagination( $page, $total_pages, $portfolio_url ); ?>

            <?php else: ?>
                <div class="cw-empty-state"><p><?php _e('No projects yet. Add your first one!', 'creativewings-core'); ?></p></div>
            <?php endif; ?>
        </div>
        
        <!-- ADD/EDIT MODAL -->
        <div id="cw-pf-modal" class="cw-modal" style="display:none;">
            <div class="cw-modal-box large">
                <div class="cw-modal-header"><h3><?php _e('Add New Project', 'creativewings-core'); ?></h3><span class="cw-close-modal" onclick="closeModal()">&times;</span></div>
                <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" enctype="multipart/form-data" class="cw-modal-form-wrapper">
                    <input type="hidden" name="action" value="cw_save_portfolio">
                    <?php wp_nonce_field('cw_portfolio_nonce'); ?>
                    <input type="hidden" name="pf_id" id="pf_id" value="">
                    
                    <div class="cw-modal-body">
                        <div class="cw-modal-upload-box">
                             <i class="fas fa-cloud-upload-alt"></i>
                             <p class="text-sm font-medium">Drag and drop or click to upload cover image</p>
                             <input type="file" name="pf_image" accept="image/*" class="cw-file-input-ghost">
                        </div>
                        <input type="text" name="pf_title" id="pf_title" required class="cw-modal-input" placeholder="Project Title">
                        <?php
                        // Build grouped category dropdown from product_cat taxonomy
                        $pf_top_cats = get_terms(['taxonomy'=>'product_cat','parent'=>0,'hide_empty'=>false,'exclude'=>get_option('default_product_cat')]);
                        ?>
                        <select name="pf_category" id="pf_category" required class="cw-modal-input">
                            <option value="">— Select Category —</option>
                            <?php if ($pf_top_cats && !is_wp_error($pf_top_cats)): foreach ($pf_top_cats as $ptc):
                                $pf_children = get_terms(['taxonomy'=>'product_cat','parent'=>$ptc->term_id,'hide_empty'=>false]);
                                if ($pf_children && !is_wp_error($pf_children) && count($pf_children)):
                            ?>
                                <optgroup label="<?php echo esc_attr($ptc->name); ?>">
                                    <?php foreach ($pf_children as $pfc): ?>
                                        <option value="<?php echo esc_attr($pfc->name); ?>"><?php echo esc_html($pfc->name); ?></option>
                                    <?php endforeach; ?>
                                </optgroup>
                            <?php else: ?>
                                <option value="<?php echo esc_attr($ptc->name); ?>"><?php echo esc_html($ptc->name); ?></option>
                            <?php endif; endforeach; endif; ?>
                        </select>
                        <textarea name="pf_desc" id="pf_desc_editor" rows="3" class="cw-modal-input" placeholder="Brief description of this project…"></textarea>
                        <div class="cw-modal-gallery-field">
                            <div class="cw-modal-gallery-box">
                                <i class="fas fa-images"></i>
                                <span>Add gallery images <small>(optional, multiple)</small></span>
                                <input type="file" name="pf_gallery[]" multiple accept="image/*" class="cw-file-input-ghost">
                            </div>
                        </div>
                    </div>
                    <div class="cw-modal-footer">
                        <button type="submit" class="cw-modal-btn-submit">Create Project</button>
                    </div>
                </form>
            </div>
        </div>

        <!-- VIEW MODAL (Behance-style) -->
        <div id="cw-view-modal" class="cw-modal" style="display:none;">
            <div class="cw-pf-view-inner">
                <button class="cw-pf-view-close" onclick="closeModal()" aria-label="Close">&times;</button>
                <div class="cw-pf-view-hero">
                    <img id="cw-pf-view-hero-img" src="" alt="">
                </div>
                <div class="cw-pf-view-body">
                    <div class="cw-pf-view-meta">
                        <span class="cw-pf-view-cat-badge" id="cw-pf-view-cat"></span>
                    </div>
                    <h2 class="cw-pf-view-title" id="cw-pf-view-title"></h2>
                    <p class="cw-pf-view-desc" id="cw-pf-view-desc"></p>
                    <div id="cw-pf-gallery-section" style="display:none;">
                        <p class="cw-pf-gallery-label">Gallery</p>
                        <div class="cw-pf-gallery-grid" id="cw-pf-gallery-grid"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            function openPortfolioModal(){
                document.getElementById('cw-pf-modal').style.display='flex';
                document.getElementById('pf_id').value='';
                document.getElementById('pf_title').value='';
                document.getElementById('pf_category').value='';
                if(document.getElementById('pf_desc_editor')) document.getElementById('pf_desc_editor').value='';
            }
            function closeModal(){
                document.getElementById('cw-pf-modal').style.display='none';
                document.getElementById('cw-view-modal').style.display='none';
            }
            function editProject(d){
                document.getElementById('cw-pf-modal').style.display='flex';
                document.getElementById('pf_id').value = d._ID;
                document.getElementById('pf_title').value = d.title;
                document.getElementById('pf_category').value = d.category;
                if(document.getElementById('pf_desc_editor')) document.getElementById('pf_desc_editor').value = d.description;
            }
            function viewPortfolio(d){
                document.getElementById('cw-pf-view-title').textContent = d.title || '';
                document.getElementById('cw-pf-view-cat').textContent   = d.category || '';
                document.getElementById('cw-pf-view-desc').textContent  = d.description || '';

                const heroImg = document.getElementById('cw-pf-view-hero-img');
                const heroWrap = heroImg.parentElement;
                if(d.img_url){ heroImg.src = d.img_url; heroImg.alt = d.title; heroWrap.style.display=''; }
                else { heroWrap.style.display='none'; }

                const galSection = document.getElementById('cw-pf-gallery-section');
                const galGrid    = document.getElementById('cw-pf-gallery-grid');
                galGrid.innerHTML = '';
                if(d.gallery && d.gallery.length > 0){
                    d.gallery.forEach(url => {
                        const img = document.createElement('img');
                        img.src = url; img.loading='lazy'; img.alt = d.title;
                        img.onclick = () => window.open(url, '_blank');
                        galGrid.appendChild(img);
                    });
                    galSection.style.display = '';
                } else {
                    galSection.style.display = 'none';
                }

                document.getElementById('cw-view-modal').style.display = 'flex';
                document.body.style.overflow = 'hidden';
            }

            jQuery(document).ready(function($){
                $(window).on('click', function(e){
                    if($(e.target).is('#cw-view-modal, #cw-pf-modal')){ closeModal(); document.body.style.overflow = ''; }
                });
            });
            document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeModal(); document.body.style.overflow=''; } });
        </script>
        <?php
    }

    /* ==========================================================================
       4. EXPLORE OPPORTUNITIES
       ========================================================================== */
    public function render_explore_opportunities() {
        $uid         = get_current_user_id();
        $base_url    = get_permalink( wc_get_page_id( 'myaccount' ) );
        $explore_url = add_query_arg( 'tab', 'explore', $base_url );
        $current_filter = sanitize_text_field( $_GET['filter'] ?? 'all' );
        $paged       = isset( $_GET['cw_page'] ) ? max( 1, intval( $_GET['cw_page'] ) ) : 1;
        $per_page    = 9;

        $filters = [
            'all'          => 'All',
            'competitions' => 'Competitions',
            'activities'   => 'Activities',
            'talk-seminar' => 'Seminars',
        ];

        $tax_query = [];
        if ( $current_filter !== 'all' ) {
            $tax_query[] = [
                'taxonomy'         => 'product_cat',
                'field'            => 'slug',
                'terms'            => $current_filter,
                'include_children' => true,
            ];
        }

        $query = new WP_Query([
            'post_type'      => 'product',
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'post_status'    => 'publish',
            'tax_query'      => $tax_query,
            'orderby'        => 'date',
            'order'          => 'DESC',
        ]);
        $opportunities = $query->posts;
        $total_pages   = $query->max_num_pages;

        // Pre-load products this user has already joined (performance: one query)
        $joined_product_ids = [];
        if ( $uid ) {
            $joined_entries = get_posts([
                'post_type'      => ['cw_competition_entry', 'cw_activity_entry'],
                'meta_key'       => 'customer_id',
                'meta_value'     => $uid,
                'posts_per_page' => -1,
                'fields'         => 'ids',
            ]);
            foreach ( $joined_entries as $eid ) {
                $epid = (int) get_post_meta( $eid, 'product_id', true );
                if ( $epid ) $joined_product_ids[$epid] = true;
            }
        }

        // SDG reverse map (name → number) for badge display
        $sdg_map     = class_exists('CW_Business') ? CW_Business::get_sdg_map() : [];
        $sdg_reverse = array_flip( $sdg_map );
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-dash-header">
                <h1>Explore Opportunities</h1>
                <p>Discover competitions, seminars, and activities to join.</p>
            </div>

            <div class="cw-filter-tabs">
                <?php foreach ( $filters as $slug => $label ):
                    $is_active = ( $current_filter === $slug );
                    $link = add_query_arg( [ 'filter' => $slug, 'cw_page' => 1 ], $explore_url );
                ?>
                    <a href="<?php echo esc_url( $link ); ?>"
                       class="cw-filter-btn <?php echo $is_active ? 'active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </div>

            <?php if ( $opportunities ): ?>
            <div class="cw-opportunity-grid">
                <?php foreach ( $opportunities as $product ):
                    $product_id  = $product->ID;
                    $wc_product  = wc_get_product( $product_id );
                    if ( ! $wc_product ) continue;

                    $terms    = get_the_terms( $product_id, 'product_cat' );
                    $type_tag = 'Event';
                    $type_css = '';
                    if ( $terms && ! is_wp_error( $terms ) ) {
                        foreach ( $terms as $term ) {
                            if ( in_array( $term->slug, ['competitions', 'competition'] ) ) { $type_tag = 'Competition'; $type_css = 'competition'; break; }
                            if ( in_array( $term->slug, ['activities', 'activity'] ) )     { $type_tag = 'Activity';    $type_css = 'activity';    break; }
                            if ( in_array( $term->slug, ['talk-seminar', 'seminar'] ) )    { $type_tag = 'Seminar';     $type_css = 'seminar';     break; }
                        }
                    }

                    $organizer_id   = get_post_meta( $product_id, 'organizer_id', true );
                    $organizer_name = get_user_meta( $organizer_id, 'business_name', true ) ?: 'Host';
                    $date           = get_post_meta( $product_id, 'cw_submission_start', true ) ?: get_the_date( 'Y-m-d', $product_id );
                    $price          = $wc_product->get_price();
                    $join_link      = get_permalink( $product_id );

                    $deadline       = get_post_meta( $product_id, 'submission_deadline', true );
                    $is_closed      = $deadline && strtotime( $deadline ) < current_time( 'timestamp' );
                    $already_joined = isset( $joined_product_ids[$product_id] );

                    // SDG badges (max 4 shown)
                    $sdg_data    = get_post_meta( $product_id, 'sdg_goals', true );
                    $active_sdgs = [];
                    if ( is_array( $sdg_data ) ) {
                        foreach ( $sdg_data as $name => $enabled ) {
                            if ( $enabled === 'true' && isset( $sdg_reverse[$name] ) ) {
                                $active_sdgs[] = [ 'num' => (int)$sdg_reverse[$name], 'name' => $name ];
                            }
                        }
                        $active_sdgs = array_slice( $active_sdgs, 0, 4 );
                    }
                ?>
                <div class="cw-opportunity-card">
                    <div class="cw-opportunity-card-image">
                        <?php echo get_the_post_thumbnail( $product_id, 'medium' ); ?>
                        <span class="cw-type-tag <?php echo esc_attr( $type_css ); ?>"><?php echo esc_html( $type_tag ); ?></span>
                    </div>
                    <div class="cw-opportunity-card-body">
                        <h3><?php echo esc_html( $product->post_title ); ?></h3>
                        <p>by <?php echo esc_html( $organizer_name ); ?></p>
                        <div class="cw-opportunity-meta">
                            <div><i class="fas fa-calendar-alt"></i> <?php echo esc_html( $date ); ?></div>
                            <div><i class="fas fa-wallet"></i> Entry Fee: <?php echo $price > 0 ? wc_price( $price ) : 'Free'; ?></div>
                        </div>
                        <?php if ( !empty($active_sdgs) ): ?>
                        <div class="cw-sdg-row">
                            <?php foreach ( $active_sdgs as $sdg ): ?>
                                <span class="cw-sdg-badge" title="<?php echo esc_attr( $sdg['name'] ); ?>">SDG <?php echo $sdg['num']; ?></span>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ( $is_closed ): ?>
                            <button class="cw-btn-join cw-btn-closed" disabled>
                                <i class="fas fa-lock"></i> Event Closed
                            </button>
                        <?php elseif ( $already_joined ): ?>
                            <button class="cw-btn-join cw-btn-joined" disabled>
                                <i class="fas fa-check-circle"></i> Already Joined
                            </button>
                        <?php else: ?>
                            <a href="<?php echo esc_url( $join_link ); ?>" class="cw-btn-join">Join Now</a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php $this->render_pagination( $paged, $total_pages, add_query_arg( 'filter', $current_filter, $explore_url ) ); ?>

            <?php else: ?>
                <div class="cw-empty-state">
                    <i class="fas fa-search"></i>
                    <p>No opportunities found<?php echo $current_filter !== 'all' ? ' in this category' : ''; ?>.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

   /* ==========================================================================
       4. MY ACTIVITIES TAB (Filtered by Product Categories)
       ========================================================================== */
   public function render_my_activities() {
        $uid = get_current_user_id();
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $activities_url = add_query_arg('tab', 'activities', $base_url);
        $current_filter = sanitize_text_field($_GET['filter'] ?? 'all');
        $paged    = isset($_GET['cw_page']) ? max(1, intval($_GET['cw_page'])) : 1;
        $per_page = 9;

        $comp_term = get_term_by('slug', 'competitions', 'product_cat');
        $comp_ids  = $comp_term ? array_merge([$comp_term->term_id], get_term_children($comp_term->term_id, 'product_cat')) : [];

        $talk_term = get_term_by('slug', 'talk-seminar', 'product_cat');
        $talk_ids  = $talk_term ? array_merge([$talk_term->term_id], get_term_children($talk_term->term_id, 'product_cat')) : [];

        $filtered_product_ids = [];
        if ($current_filter !== 'all') {
            $product_ids = get_posts([
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids',
                'tax_query' => [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => $current_filter,
                    'include_children' => true
                ]]
            ]);
            $filtered_product_ids = !empty($product_ids) ? $product_ids : [0];
        }

        $base_args = [
            'post_type'  => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key'   => 'customer_id',
            'meta_value' => $uid,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ];
        if ($current_filter !== 'all') {
            $base_args['meta_query'] = [['key' => 'product_id', 'value' => $filtered_product_ids, 'compare' => 'IN']];
        }

        // Count total for pagination
        $count_args                 = $base_args;
        $count_args['posts_per_page'] = -1;
        $count_args['fields']       = 'ids';
        $all_ids     = get_posts($count_args);
        $total_items = count($all_ids);
        $total_pages = (int) ceil($total_items / $per_page);

        // Paginated query
        $base_args['posts_per_page'] = $per_page;
        $base_args['offset']         = ($paged - 1) * $per_page;
        $entries = get_posts($base_args);
        ?>

        <div class="cw-content-wrapper">
            <div class="cw-header-flex">
                <div>
                    <h2 class="cw-section-title">My Activities</h2>
                    <p class="cw-section-subtitle">Track your competitions, seminars, and events.</p>
                </div>

                <div class="cw-filter-group">
                    <a href="<?php echo esc_url( add_query_arg('filter', 'all', $activities_url) ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'all') ? 'active' : ''; ?>">All</a>
                    <a href="<?php echo esc_url( add_query_arg('filter', 'competitions', $activities_url) ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'competitions') ? 'active' : ''; ?>">Competitions</a>
                    <a href="<?php echo esc_url( add_query_arg('filter', 'activities', $activities_url) ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'activities') ? 'active' : ''; ?>">Activities</a>
                    <a href="<?php echo esc_url( add_query_arg('filter', 'talk-seminar', $activities_url) ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'talk-seminar') ? 'active' : ''; ?>">Talk/Seminar</a>
                </div>
            </div>

            <?php if ( $entries ): ?>
            <div class="cw-activities-grid">
                <?php foreach ( $entries as $e ):
                    $pid     = get_post_meta( $e->ID, 'product_id', true );
                    $product = wc_get_product( $pid );
                    if ( ! $product ) continue;

                    $score     = get_post_meta( $e->ID, 'judge_score', true );
                    $is_winner = get_post_meta( $e->ID, 'winner_status', true ) === 'yes';

                    if ( has_term( $comp_ids, 'product_cat', $pid ) ) {
                        $type_label = 'Competition'; $type_css = 'competition';
                    } elseif ( has_term( $talk_ids, 'product_cat', $pid ) ) {
                        $type_label = 'Seminar'; $type_css = 'seminar';
                    } else {
                        $type_label = 'Activity'; $type_css = 'activity';
                    }

                    if ( $is_winner )                               { $status = 'Winner';     $cls = 'winner'; }
                    elseif ( $score !== '' && (float)$score > 0 )  { $status = 'Reviewed';   $cls = 'reviewed'; }
                    else                                             { $status = 'Registered'; $cls = 'registered'; }

                    $thumb = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';

                    $entry_vote_count = 0;
                    $entry_voting_on  = false;
                    if ( $type_css === 'competition' ) {
                        $entry_voting_on  = get_post_meta( $pid, 'cw_enable_voting', true ) === 'yes';
                        $entry_vote_count = (int) get_post_meta( $e->ID, 'vote_count', true );
                    }

                    $modal_data = htmlspecialchars( json_encode([
                        'id'           => $e->ID,
                        'title'        => $product->get_name(),
                        'date'         => get_the_date( 'Y-m-d', $e->ID ),
                        'status'       => strtoupper( $status ),
                        'score'        => $score ?: '0',
                        'comment'      => get_post_meta( $e->ID, 'judge_comment', true ) ?: 'No feedback provided yet.',
                        'cert_enabled' => get_post_meta( $pid, 'cw_enable_certificate', true ) === 'yes',
                        'details'      => get_post_meta( $e->ID, 'participant_details', true ) ?: [],
                        'vote_count'   => $entry_vote_count,
                        'voting_on'    => $entry_voting_on,
                    ]), ENT_QUOTES, 'UTF-8' );
                ?>
                <div class="cw-activity-card">
                    <div class="cw-activity-image-wrap">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>">
                        <span class="cw-activity-type-badge <?php echo esc_attr( $type_css ); ?>"><?php echo esc_html( $type_label ); ?></span>
                    </div>
                    <div class="cw-activity-info">
                        <h4><?php echo esc_html( $product->get_name() ); ?></h4>
                        <div class="cw-activity-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?php echo get_the_date( 'M j, Y', $e->ID ); ?></span>
                            <?php if ( $entry_voting_on ): ?>
                            <span class="cw-vote-chip"><i class="fas fa-heart"></i> <?php echo $entry_vote_count; ?> votes</span>
                            <?php endif; ?>
                        </div>
                        <div class="cw-activity-footer">
                            <span class="cw-status-badge <?php echo esc_attr( $cls ); ?>"><?php echo esc_html( $status ); ?></span>
                            <button class="cw-btn-details"
                                    onclick="openContestantModal('<?php echo $modal_data; ?>')">
                                View Details
                            </button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php $this->render_pagination( $paged, $total_pages, add_query_arg( 'filter', $current_filter, $activities_url ) ); ?>

            <?php else: ?>
                <div class="cw-empty-state">
                    <i class="fas fa-folder-open"></i>
                    <p>No entries found<?php echo $current_filter !== 'all' ? ' in this category' : ''; ?>.</p>
                </div>
            <?php endif; ?>
        </div>
        <?php
        $this->render_activity_detail_modal();
    }
        
    
    /* ==========================================================================
       6. MODAL HELPER (Nova UI Style)
       ========================================================================== */
    private function render_activity_detail_modal() {
        $cert_action_url = admin_url( 'admin-post.php' );
        ?>
        <div id="cw-activity-detail-modal" class="cw-modal cw-entry-modal" style="display:none;">
            <div class="cw-entry-modal-box">

                <!-- ── Gradient Header ── -->
                <div class="cw-entry-modal-head">
                    <button class="cw-entry-modal-close-v2" onclick="closeContestantModal()" aria-label="Close">
                        <i class="fas fa-times"></i>
                    </button>
                    <div class="cw-entry-head-icon"><i class="fas fa-star"></i></div>
                    <h3 class="cw-entry-modal-title" id="m-title"></h3>
                    <p class="cw-entry-modal-date" id="m-date"></p>
                    <span class="cw-entry-head-status" id="m-head-status"></span>
                </div>

                <!-- ── Body ── -->
                <div class="cw-entry-modal-body">

                    <!-- Score card -->
                    <div class="cw-entry-score-showcase" id="m-card-score">
                        <div class="cw-ess-left">
                            <span class="cw-ess-label">Your Score</span>
                            <div class="cw-ess-number">
                                <span id="m-score">—</span><span class="cw-ess-denom"> / 100</span>
                            </div>
                        </div>
                        <div class="cw-ess-icon"><i class="fas fa-trophy"></i></div>
                    </div>

                    <!-- Vote count bar (populated by JS, competition only) -->
                    <div id="m-vote-bar" style="display:none;"></div>

                    <!-- Certificate bar (populated by JS) -->
                    <div id="m-cert-bar"></div>

                    <!-- Submission details -->
                    <h4 class="cw-entry-section-head">
                        <i class="fas fa-clipboard-list"></i> Submission Details
                    </h4>
                    <div class="cw-entry-detail-area" id="m-details"></div>

                    <!-- Feedback -->
                    <div class="cw-entry-feedback-box" id="m-feedback-box" style="display:none;">
                        <p class="cw-entry-feedback-label"><i class="fas fa-comment-dots"></i> Feedback from Host</p>
                        <p class="cw-entry-feedback-text" id="m-feedback"></p>
                    </div>

                    <div class="cw-entry-modal-footer">
                        <button class="cw-entry-close-btn" onclick="closeContestantModal()">
                            <i class="fas fa-times-circle"></i> Close
                        </button>
                    </div>
                </div>

            </div>
        </div>

        <script>
        const cwCertActionUrl = '<?php echo esc_js( $cert_action_url ); ?>';

        const cwStatusMeta = {
            'WINNER':     { bg: '#fef3c7', color: '#92400e', icon: '🏆' },
            'REVIEWED':   { bg: '#dbeafe', color: '#1e40af', icon: '✅' },
            'REGISTERED': { bg: 'rgba(255,255,255,0.25)', color: '#fff', icon: '📋' },
        };

        function openContestantModal(jsonStr) {
            const data = JSON.parse(jsonStr);
            jQuery('#m-title').text(data.title || '');
            jQuery('#m-date').text(data.date || '');
            jQuery('#m-score').text(data.score !== undefined && data.score !== '' ? data.score : '—');

            // Header status badge
            const st   = (data.status || 'REGISTERED').toUpperCase();
            const meta = cwStatusMeta[st] || { bg: 'rgba(255,255,255,0.2)', color: '#fff', icon: '📋' };
            jQuery('#m-head-status').text(meta.icon + ' ' + st)
                .css({ background: meta.bg, color: meta.color });

            // Build details table
            let detHtml = '<table class="cw-entry-detail-table">';
            if (Array.isArray(data.details) && data.details.length > 0) {
                data.details.forEach(d => {
                    let val = String(d.value || '');
                    if (val.match(/^https?:\/\//i)) {
                        val = `<a href="${val}" target="_blank" class="cw-entry-file-link"><i class="fas fa-paperclip"></i> View Attachment</a>`;
                    }
                    detHtml += `<tr><td class="cw-entry-detail-label">${d.label}</td><td class="cw-entry-detail-val">${val}</td></tr>`;
                });
            } else {
                detHtml += `<tr><td colspan="2" class="cw-entry-detail-val" style="color:var(--cw-muted);">No submission details found.</td></tr>`;
            }
            detHtml += '</table>';
            jQuery('#m-details').html(detHtml);

            // Feedback
            if (data.comment && data.comment !== 'No feedback provided yet.') {
                jQuery('#m-feedback').text(data.comment);
                jQuery('#m-feedback-box').show();
            } else {
                jQuery('#m-feedback-box').hide();
            }

            // Vote count (if voting is enabled)
            const voteBar = jQuery('#m-vote-bar');
            if (data.voting_on) {
                const voteCount = data.vote_count !== undefined ? data.vote_count : 0;
                voteBar.html(`<div style="display:flex;align-items:center;gap:8px;padding:10px 14px;background:#fff5f5;border:1px solid #fca5a5;border-radius:10px;">
                    <i class="fas fa-heart" style="color:#f43f5e;"></i>
                    <span style="font-size:14px;font-weight:600;color:#64748b;">Your entry has received <strong style="color:#f43f5e;">${voteCount}</strong> public vote${voteCount !== 1 ? 's' : ''}.</span>
                </div>`).show();
            } else {
                voteBar.empty().hide();
            }

            // Certificate
            const certBox    = jQuery('#m-cert-bar');
            const canDownload = (st === 'REVIEWED' || st === 'WINNER') && data.cert_enabled;
            if (canDownload) {
                certBox.html(`
                    <div class="cw-entry-cert-banner">
                        <div><strong>🎓 Certificate Ready</strong><p>Download your achievement certificate.</p></div>
                        <a href="${cwCertActionUrl}?action=cw_download_cert&entry_id=${data.id}"
                           class="cw-btn-primary cw-btn-sm" target="_blank">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>`);
            } else {
                certBox.empty();
            }

            jQuery('#cw-activity-detail-modal').css('display', 'flex');
            document.body.style.overflow = 'hidden';
        }

        function closeContestantModal() {
            jQuery('#cw-activity-detail-modal').hide();
            document.body.style.overflow = '';
        }

        jQuery(document).on('keydown', function(e) { if (e.key === 'Escape') closeContestantModal(); });
        jQuery(document).on('click', '#cw-activity-detail-modal', function(e) {
            if (e.target === this) closeContestantModal();
        });
        </script>
        <?php
    }
    
    public function render_saved() {
        $uid=get_current_user_id(); $saved=get_user_meta($uid,'saved_competitions',true);
        echo '<div class="cw-content-wrapper"><h2>Saved Items</h2><div class="cw-portfolio-grid-modern">';
        if($saved && is_array($saved)): 
            foreach($saved as $item){ 
                $pid=isset($item['competition_id'])?$item['competition_id']:0; 
                if(get_post_status($pid)!=='publish')continue; 
                $img = get_the_post_thumbnail_url($pid,'medium')?:CW_URL.'assets/img/placeholder.jpg';
                echo '<div class="cw-modern-card"><div class="cw-card-image" style="background-image:url('.esc_url($img).')"></div><div class="cw-card-content"><h4><a href="'.get_permalink($pid).'">'.get_the_title($pid).'</a></h4></div></div>'; 
            } 
        else: echo '<p>No saved items found.</p>'; endif; echo '</div></div>';
    }

    /* ==========================================================================
       6. HANDLERS
       ========================================================================== */
    public function handle_save_profile() {
        if(!is_user_logged_in()||!wp_verify_nonce($_POST['_wpnonce'],'cw_profile_nonce'))wp_die('Security Error');$uid=get_current_user_id();
        $fields=['creator_display_name', 'creator_tagline', 'creator_bio', 'awards_won', 'creator_skills', 'website_url', 'linkeden_url', 'instagram_url', 'twitter_url', 'Facebook_url', 'behave_url', 'creator_address'];
        foreach($fields as $f){if(isset($_POST[$f])){ $val=($f==='creator_bio')?wp_kses_post($_POST['creator_bio']):sanitize_text_field($_POST[$f]); update_user_meta($uid,$f,$val); }}
        $this->handle_image_upload($uid, 'creator_profile_image'); 
        $this->handle_image_upload($uid, 'creator_header_image');
        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $target_url = add_query_arg(['tab' => 'profile', 'updated' => '1'], $my_account_url);
        
        wp_safe_redirect($target_url);
        exit;
    }
    
    public function handle_save_portfolio() {
        if(!is_user_logged_in()||!wp_verify_nonce($_POST['_wpnonce'],'cw_portfolio_nonce'))wp_die('Error');
        global $wpdb;
        $uid=get_current_user_id();
        $table=$this->get_table_name();
        
        $data=['title'=>sanitize_text_field($_POST['pf_title']),'category'=>sanitize_text_field($_POST['pf_category']),'description'=>wp_kses_post($_POST['pf_desc']),'cct_modified'=>current_time('mysql'),'cct_author_id'=>$uid,'created_by'=>$uid,'cct_status'=>'publish'];
        
        require_once(ABSPATH.'wp-admin/includes/image.php');
        require_once(ABSPATH.'wp-admin/includes/file.php');
        require_once(ABSPATH.'wp-admin/includes/media.php');
        
        if(!empty($_FILES['pf_image']['name'])){
            $aid=media_handle_upload('pf_image',0);
            if(!is_wp_error($aid))$data['image']=serialize(['id'=>$aid,'url'=>wp_get_attachment_url($aid)]);
        }
        if(!empty($_FILES['pf_gallery']['name'][0])){ 
            $gals=[]; $files=$_FILES['pf_gallery'];
            foreach($files['name'] as $k=>$v){
                if($files['name'][$k]){
                    $_FILES['s_file']=['name'=>$files['name'][$k],'type'=>$files['type'][$k],'tmp_name'=>$files['tmp_name'][$k],'error'=>$files['error'][$k],'size'=>$files['size'][$k]];
                    $gid=media_handle_upload('s_file',0);
                    if(!is_wp_error($gid))$gals[]=['id'=>$gid,'url'=>wp_get_attachment_url($gid)];
                }
            }
            if($gals)$data['gallery']=serialize($gals); 
        }
        
        if(!empty($_POST['pf_id'])){
            $wpdb->update($table,$data,['_ID'=>intval($_POST['pf_id'])]);
        }else{
            $data['cct_created']=current_time('mysql');
            $wpdb->insert($table,$data);
        }
        
        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        wp_safe_redirect( add_query_arg('tab', 'portfolio', $my_account_url) );
        exit;
    }
    
    public function handle_delete_portfolio(){
        if(!is_user_logged_in()||!isset($_GET['_wpnonce'])||!wp_verify_nonce($_GET['_wpnonce'],'cw_del_'.intval($_GET['pid'])))wp_die('Security Error');
        global $wpdb;
        $table=$this->get_table_name();
        $pid = intval($_GET['pid']);
        $redirect_to = sanitize_url($_GET['redirect_to'] ?? '');
        $wpdb->query($wpdb->prepare("DELETE FROM $table WHERE _ID=%d", $pid));
        
        if ($redirect_to) {
            wp_safe_redirect($redirect_to);
        } else {
            $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
            wp_safe_redirect( add_query_arg('tab', 'portfolio', $my_account_url) );
        }
        exit;
    }

    /* ==========================================================================
       SHARED: Pagination renderer
       ========================================================================== */
    private function render_pagination( $current_page, $total_pages, $base_url ) {
        if ( $total_pages <= 1 ) return;
        ?>
        <nav class="cw-pagination-nav" aria-label="Pagination">
            <?php if ( $current_page > 1 ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page - 1, $base_url ) ); ?>" class="cw-page-btn prev" aria-label="Previous">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="cw-page-btn prev disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php
            $range   = 2;
            $start   = max( 1, $current_page - $range );
            $end     = min( $total_pages, $current_page + $range );

            if ( $start > 1 ) {
                echo '<a href="' . esc_url( add_query_arg( 'cw_page', 1, $base_url ) ) . '" class="cw-page-btn">1</a>';
                if ( $start > 2 ) echo '<span class="cw-page-ellipsis">…</span>';
            }
            for ( $i = $start; $i <= $end; $i++ ):
                $active = ( $i === $current_page ) ? 'active' : '';
            ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $i, $base_url ) ); ?>"
                   class="cw-page-btn <?php echo $active; ?>"
                   <?php echo $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo $i; ?>
                </a>
            <?php endfor;
            if ( $end < $total_pages ) {
                if ( $end < $total_pages - 1 ) echo '<span class="cw-page-ellipsis">…</span>';
                echo '<a href="' . esc_url( add_query_arg( 'cw_page', $total_pages, $base_url ) ) . '" class="cw-page-btn">' . $total_pages . '</a>';
            }
            ?>

            <?php if ( $current_page < $total_pages ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page + 1, $base_url ) ); ?>" class="cw-page-btn next" aria-label="Next">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="cw-page-btn next disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
        <?php
    }
}