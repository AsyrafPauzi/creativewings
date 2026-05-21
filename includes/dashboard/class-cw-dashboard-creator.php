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

        // 3. Schema migrations (idempotent — runs once via option flag)
        add_action( 'init', [ __CLASS__, 'maybe_install_portfolio_columns' ] );
    }

    /**
     * Idempotently add the visibility column to the portfolio table.
     * Uses a stored version flag so we only hit the DB once per upgrade.
     */
    public static function maybe_install_portfolio_columns() {
        $current = (int) get_option( 'cw_portfolio_schema_version', 0 );
        if ( $current >= 1 ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'jet_cct_creator_portfolio';

        // Only proceed if the JetEngine portfolio table exists
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( ! $exists ) {
            return; // leave flag unset so we try again later
        }

        $has_visibility = $wpdb->get_var( $wpdb->prepare(
            "SHOW COLUMNS FROM `{$table}` LIKE %s",
            'visibility'
        ) );

        if ( ! $has_visibility ) {
            $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `visibility` VARCHAR(20) NOT NULL DEFAULT 'public'" );
        }

        update_option( 'cw_portfolio_schema_version', 1, false );
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
            if(!is_wp_error($aid)) {
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    $ctx = ( strpos( $file_key, 'header' ) !== false ) ? 'cover' : 'avatar';
                    CW_Image_Optimizer::optimize_attachment( $aid, $ctx );
                }
                update_user_meta($uid, $file_key, ['id'=>$aid, 'url'=>wp_get_attachment_url($aid)]);
            }
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

        // 3. Get Submission Stats — single query for IDs, single query for judge_score scan.
        $submissions = get_posts([
            'post_type'      => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key'       => 'customer_id',
            'meta_value'     => $uid,
            'posts_per_page' => -1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ]);
        $entries_total      = count($submissions);
        $active_engagements = 0;
        $completed_entries  = 0;
        if ( ! empty( $submissions ) ) {
            $placeholders  = implode(',', array_fill(0, count($submissions), '%d'));
            $scored_count  = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta}
                 WHERE meta_key = 'judge_score'
                   AND meta_value <> ''
                   AND post_id IN ($placeholders)",
                $submissions
            ) );
            $completed_entries  = $scored_count;
            $active_engagements = max( 0, $entries_total - $scored_count );
        }

        // 4. Get Total Views for the stat box
        $views = (int) get_user_meta( $uid, 'cw_profile_views', true );

        // 5. Setup Profile Info
        $display_name = get_user_meta($uid, 'creator_display_name', true) ?: $u->display_name;
        $profile_url  = class_exists( 'CW_Roles' ) ? CW_Roles::get_public_portfolio_url( $u ) : home_url( '/profile/' . $u->user_login . '/' );

        // Directory completeness — show a nudge banner if missing any basic field.
        $missing_dir_fields = [];
        if ( class_exists( 'CW_Roles' ) ) {
            $check = CW_Roles::creator_missing_basics( $u );
            if ( is_array( $check ) ) {
                $missing_dir_fields = $check;
            }
        }
        $missing_dir_labels = [
            'creator_display_name'  => __( 'Display name', 'creativewings-core' ),
            'creator_profile_image' => __( 'Profile photo', 'creativewings-core' ),
            'creator_tagline'       => __( 'Tagline', 'creativewings-core' ),
            'creator_address'       => __( 'Location', 'creativewings-core' ),
        ];

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
                </div>
                <?php if ( $profile_url ) : ?>
                <a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" class="cw-public-profile-link">
                    <i class="fas fa-external-link-alt"></i> View Public Profile
                </a>
                <?php endif; ?>
            </div>

            <?php if ( ! empty( $missing_dir_fields ) ) : ?>
                <div class="cw-dir-nudge" role="status">
                    <div class="cw-dir-nudge-icon"><i class="fas fa-eye-slash"></i></div>
                    <div class="cw-dir-nudge-body">
                        <h3><?php esc_html_e( 'Complete your profile to appear in the public directory', 'creativewings-core' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'You won\'t be listed on the creators directory until these basics are filled in:', 'creativewings-core' ); ?>
                        </p>
                        <div class="cw-dir-nudge-chips">
                            <?php foreach ( $missing_dir_fields as $field ) :
                                $label = $missing_dir_labels[ $field ] ?? ucwords( str_replace( [ 'creator_', '_' ], [ '', ' ' ], $field ) );
                                ?>
                                <span class="cw-dir-nudge-chip"><i class="fas fa-times-circle"></i> <?php echo esc_html( $label ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo esc_url( $profile_tab ); ?>" class="cw-btn-primary cw-dir-nudge-cta">
                            <i class="fas fa-pen-to-square"></i> <?php esc_html_e( 'Complete profile', 'creativewings-core' ); ?>
                        </a>
                    </div>
                </div>
                <style>
                    .cw-dir-nudge {
                        display: flex;
                        gap: 14px;
                        align-items: flex-start;
                        background: linear-gradient(135deg, #fff7ed 0%, #fffbeb 100%);
                        border: 1px solid #fde68a;
                        border-radius: 14px;
                        padding: 16px 18px;
                        margin: 0 0 20px;
                        box-shadow: 0 2px 10px rgba(180, 83, 9, 0.06);
                    }
                    .cw-dir-nudge-icon {
                        width: 42px;
                        height: 42px;
                        border-radius: 50%;
                        background: #fef3c7;
                        color: #b45309;
                        display: flex;
                        align-items: center;
                        justify-content: center;
                        font-size: 18px;
                        flex-shrink: 0;
                    }
                    .cw-dir-nudge-body { flex: 1; min-width: 0; }
                    .cw-dir-nudge-body h3 {
                        margin: 0 0 4px;
                        font-size: 15px;
                        font-weight: 800;
                        color: #92400e;
                        line-height: 1.35;
                    }
                    .cw-dir-nudge-body p {
                        margin: 0 0 10px;
                        font-size: 13px;
                        color: #78350f;
                    }
                    .cw-dir-nudge-chips {
                        display: flex;
                        flex-wrap: wrap;
                        gap: 6px;
                        margin: 0 0 12px;
                    }
                    .cw-dir-nudge-chip {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        background: #fff;
                        border: 1px solid #fde68a;
                        color: #92400e;
                        font-size: 12px;
                        font-weight: 700;
                        padding: 4px 10px;
                        border-radius: 999px;
                    }
                    .cw-dir-nudge-chip i { color: #b45309; font-size: 10px; }
                    .cw-dir-nudge-cta {
                        display: inline-flex;
                        align-items: center;
                        gap: 6px;
                        text-decoration: none;
                    }
                    @media (max-width: 600px) {
                        .cw-dir-nudge { flex-direction: column; gap: 10px; padding: 14px; }
                        .cw-dir-nudge-icon { width: 36px; height: 36px; font-size: 16px; }
                    }
                </style>
            <?php endif; ?>

            <?php
            // Latest-achievement strip (badges system).
            if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                $owned_badges = CW_Badges_Engine::get_user_badges( $uid );
                if ( ! empty( $owned_badges ) ) :
                    $badges_tab_url = add_query_arg( 'tab', 'badges', $my_account_url ); ?>
                    <div class="cw-badges cw-badges-latest">
                        <h4><i class="fas fa-trophy" style="color:#facc15;margin-right:6px;"></i><?php esc_html_e( 'Latest achievements', 'creativewings-core' ); ?></h4>
                        <?php echo CW_Badges_Display::render_strip( $owned_badges, 4, [ 'size' => 'sm', 'show_label' => false, 'show_tier' => false ] ); ?>
                        <a href="<?php echo esc_url( $badges_tab_url ); ?>" style="margin-left:auto;font-size:13px;font-weight:600;color:#0ea5e9;text-decoration:none;">
                            <?php esc_html_e( 'See all badges', 'creativewings-core' ); ?> &rarr;
                        </a>
                    </div>
                <?php endif;
            }
            ?>

            <!-- 4-stat grid -->
            <div class="cw-overview-stats-grid cw-stats-4col">
                <div class="cw-stat-box-small">
                    <div class="cw-stat-value-row">
                        <div class="icon"><i class="fas fa-eye" style="color:var(--cw-primary);"></i></div>
                        <h3><?php echo number_format($views); ?></h3>
                    </div>
                    <span>Profile Views</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="cw-stat-value-row">
                        <div class="icon"><i class="fas fa-briefcase" style="color:#7c3aed;"></i></div>
                        <h3><?php echo number_format((int)$port_count); ?></h3>
                    </div>
                    <span>Portfolio Items</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="cw-stat-value-row">
                        <div class="icon"><i class="fas fa-fire" style="color:var(--cw-accent);"></i></div>
                        <h3><?php echo number_format($active_engagements); ?></h3>
                    </div>
                    <span>Active Campaigns</span>
                </div>
                <div class="cw-stat-box-small">
                    <div class="cw-stat-value-row">
                        <div class="icon"><i class="fas fa-check-circle" style="color:var(--cw-success);"></i></div>
                        <h3><?php echo number_format($completed_entries); ?></h3>
                    </div>
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
                            <div class="icon-wrap cw-iw-teal"><i class="fas fa-tasks"></i></div>
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
        (function(){
            var CHART_V4_URL = <?php echo wp_json_encode( CW_URL . 'assets/vendor/chart.js/chart.umd.min.js?v=' . CW_VERSION ); ?>;
            function ensureChartV4(cb){
                var hasChart = typeof window.Chart !== 'undefined';
                var v = hasChart && window.Chart.version ? String(window.Chart.version) : '';
                if (hasChart && v && v.charAt(0) >= '3') { cb(); return; }
                if (hasChart) { try { delete window.Chart; } catch(e) { window.Chart = undefined; } }
                var s = document.createElement('script');
                s.src = CHART_V4_URL; s.async = false;
                s.onload = function(){ cb(); };
                s.onerror = function(){ console.warn('[CW] Failed to load Chart.js v4'); };
                document.head.appendChild(s);
            }
            document.addEventListener('DOMContentLoaded', function(){ ensureChartV4(function(){
                var ctx = document.getElementById('trafficChart');
                if(!ctx || typeof Chart === 'undefined') return;
                var chartData = <?php echo json_encode($chart_data); ?>;
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.map(function(d){ return d.name; }),
                        datasets: [{
                            label: 'Views',
                            data: chartData.map(function(d){ return d.views; }),
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
            }); });
        })();
        </script>
        <?php
    }
    /* ==========================================================================
       2. EDIT PROFILE (Nova UI Overhaul - Final Version)
       ========================================================================== */
    public function render_profile() {
        $uid = get_current_user_id();
        $fields = ['creator_display_name', 'creator_tagline', 'creator_bio', 'awards_won', 'creator_skills', 'website_url', 'linkeden_url', 'instagram_url', 'twitter_url', 'Facebook_url', 'behave_url', 'creator_address', 'birthdate']; // Added creator_address, birthdate
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
                        <img src="<?php echo esc_url($hdr_url); ?>" alt="Header Banner" class="cw-banner-image" loading="lazy" decoding="async" />
                        
                        <!-- Upload Button Overlay (Header) -->
                        <label for="creator_header_image_upload" class="cw-header-upload-btn">
                            <i class="fas fa-camera"></i> Change Banner
                        </label>
                        <input type="file" id="creator_header_image_upload" name="creator_header_image" accept="image/*" style="display:none;">

                        <!-- Avatar -->
                        <div class="cw-avatar-composite">
                            <img src="<?php echo esc_url($img_url); ?>" alt="Profile Avatar" class="cw-profile-avatar" loading="lazy" decoding="async" />
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

                            <!-- Date of Birth (jQuery UI datepicker auto-binds to #birthdate) -->
                            <div class="cw-field-dark"><label>Date of Birth</label><input type="text" id="birthdate" name="birthdate" value="<?php echo esc_attr($meta['birthdate']); ?>" class="cw-input-dark-v2" placeholder="dd/mm/yyyy" readonly autocomplete="bday"></div>

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

        // Fixed portfolio-relevant categories (Issue 6).
        $cw_pf_categories = [
            'Architecture',
            'Branding & Logo Design',
            'Calligraphy & Typography',
            'Ceramics & Pottery',
            'Crafts & Handmade',
            'Creative Writing',
            'Digital Art',
            'Fashion & Textile Design',
            'Film & Video',
            'Game Design',
            'Graphic Design',
            'Illustration',
            'Industrial / Product Design',
            'Interior Design',
            'Mixed Media',
            'Music & Sound',
            'Painting',
            'Photography',
            'Printmaking',
            'Sculpture',
            'UI / UX Design',
            'Web Design',
            'Others',
        ];
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-portfolio-header">
                <div class="cw-portfolio-header-top">
                    <h2><?php _e('My Portfolio', 'creativewings-core'); ?></h2>
                    <button class="cw-btn-primary cw-portfolio-add-btn" onclick="openPortfolioModal()"><i class="fas fa-plus"></i> <span><?php _e('Add Project', 'creativewings-core'); ?></span></button>
                </div>
                <p class="cw-portfolio-header-desc"><?php _e('Manage the projects displayed on your public profile.', 'creativewings-core'); ?></p>
            </div>
            
            <?php if ( $ports ): ?>
                <div class="cw-portfolio-grid-modern">
                    <?php foreach ( $ports as $p ): 
                        $img_data = maybe_unserialize( $p->image ); 
                        $img_url  = ( is_array( $img_data ) && isset( $img_data['url'] ) ) ? $img_data['url'] : '';
                        $gal_data = maybe_unserialize( $p->gallery ); 
                        $g_urls = []; 
                        if ( is_array( $gal_data ) ) { foreach ( $gal_data as $g ) { if ( isset( $g['url'] ) ) $g_urls[] = $g['url']; } }

                        $visibility = ( isset( $p->visibility ) && $p->visibility === 'private' ) ? 'private' : 'public';

                        $del_url = wp_nonce_url( admin_url( 'admin-post.php?action=cw_delete_portfolio&pid=' . $p->_ID . '&redirect_to=' . urlencode($portfolio_url) ), 'cw_del_' . $p->_ID );
                        $edit_json = htmlspecialchars( json_encode([
                            '_ID'         => $p->_ID, 
                            'title'       => $p->title, 
                            'category'    => $p->category, 
                            'description' => $p->description, 
                            'img_url'     => $img_url, 
                            'gallery'     => $g_urls,
                            'visibility'  => $visibility,
                        ]), ENT_QUOTES, 'UTF-8' );
                    ?>
                    <div class="cw-project-card-v2 cw-pf-vis-<?php echo esc_attr( $visibility ); ?>" onclick="viewPortfolio(<?php echo $edit_json; ?>)">
                        <div class="cw-project-image-wrap">
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr($p->title); ?>" loading="lazy" decoding="async" />
                            <?php if ( $visibility === 'private' ): ?>
                                <span class="cw-pf-private-badge" title="<?php esc_attr_e( 'Only you can see this project', 'creativewings-core' ); ?>">
                                    <i class="fas fa-lock"></i> <?php esc_html_e( 'Private', 'creativewings-core' ); ?>
                                </span>
                            <?php endif; ?>
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
                        <div class="cw-modal-upload-box" id="cw-pf-cover-box">
                             <img class="cw-pf-cover-preview" id="cw-pf-cover-preview" alt="" />
                             <div class="cw-pf-cover-placeholder">
                                 <i class="fas fa-cloud-upload-alt"></i>
                                 <p class="text-sm font-medium">Drag and drop or click to upload cover image</p>
                             </div>
                             <button type="button" class="cw-pf-cover-remove" id="cw-pf-cover-remove" aria-label="Remove cover image">&times;</button>
                             <input type="file" name="pf_image" id="pf_image" accept="image/*" class="cw-file-input-ghost">
                        </div>
                        <input type="text" name="pf_title" id="pf_title" required class="cw-modal-input" placeholder="Project Title">

                        <select name="pf_category" id="pf_category" required class="cw-modal-input">
                            <option value="">— Select Category —</option>
                            <?php foreach ( $cw_pf_categories as $cw_pf_cat ): ?>
                                <option value="<?php echo esc_attr( $cw_pf_cat ); ?>"><?php echo esc_html( $cw_pf_cat ); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="cw-pf-cat-other-wrap" id="pf_category_other_wrap" style="display:none;">
                            <input type="text" name="pf_category_other" id="pf_category_other" class="cw-modal-input" placeholder="<?php esc_attr_e('Enter your category', 'creativewings-core'); ?>">
                        </div>

                        <div class="cw-wysiwyg-wrap">
                            <div class="cw-wysiwyg-toolbar" role="toolbar" aria-label="<?php esc_attr_e('Formatting', 'creativewings-core'); ?>">
                                <button type="button" class="cw-wys-btn" data-cmd="bold" title="<?php esc_attr_e('Bold', 'creativewings-core'); ?>"><b>B</b></button>
                                <button type="button" class="cw-wys-btn" data-cmd="italic" title="<?php esc_attr_e('Italic', 'creativewings-core'); ?>"><i>I</i></button>
                                <button type="button" class="cw-wys-btn" data-cmd="underline" title="<?php esc_attr_e('Underline', 'creativewings-core'); ?>"><u>U</u></button>
                                <span class="cw-wys-sep" aria-hidden="true"></span>
                                <button type="button" class="cw-wys-btn" data-cmd="insertUnorderedList" title="<?php esc_attr_e('Bulleted list', 'creativewings-core'); ?>"><i class="fas fa-list-ul"></i></button>
                                <button type="button" class="cw-wys-btn" data-cmd="insertOrderedList" title="<?php esc_attr_e('Numbered list', 'creativewings-core'); ?>"><i class="fas fa-list-ol"></i></button>
                                <span class="cw-wys-sep" aria-hidden="true"></span>
                                <button type="button" class="cw-wys-btn" data-cmd="createLink" title="<?php esc_attr_e('Insert link', 'creativewings-core'); ?>"><i class="fas fa-link"></i></button>
                                <button type="button" class="cw-wys-btn" data-cmd="unlink" title="<?php esc_attr_e('Remove link', 'creativewings-core'); ?>"><i class="fas fa-unlink"></i></button>
                            </div>
                            <div id="pf_desc_editor" class="cw-wysiwyg-area" contenteditable="true" data-placeholder="<?php esc_attr_e('Brief description of this project…', 'creativewings-core'); ?>"></div>
                            <textarea name="pf_desc" id="pf_desc_hidden" class="cw-wys-hidden-input" hidden></textarea>
                        </div>

                        <div class="cw-modal-gallery-field">
                            <div class="cw-modal-gallery-box">
                                <i class="fas fa-images"></i>
                                <span>Add gallery images <small>(optional, multiple)</small></span>
                                <input type="file" name="pf_gallery[]" id="pf_gallery_input" multiple accept="image/*" class="cw-file-input-ghost">
                            </div>
                            <div class="cw-pf-gallery-preview-grid" id="cw-pf-gallery-preview-grid"></div>
                        </div>

                        <fieldset class="cw-pf-visibility">
                            <legend class="cw-pf-visibility-legend"><?php esc_html_e( 'Visibility', 'creativewings-core' ); ?></legend>
                            <div class="cw-pf-visibility-options">
                                <label class="cw-pf-vis-card">
                                    <input type="radio" name="pf_visibility" value="public" checked>
                                    <span class="cw-pf-vis-icon"><i class="fas fa-globe"></i></span>
                                    <span class="cw-pf-vis-text">
                                        <strong><?php esc_html_e( 'Public', 'creativewings-core' ); ?></strong>
                                        <small><?php esc_html_e( 'Anyone visiting your profile can see this project.', 'creativewings-core' ); ?></small>
                                    </span>
                                </label>
                                <label class="cw-pf-vis-card">
                                    <input type="radio" name="pf_visibility" value="private">
                                    <span class="cw-pf-vis-icon"><i class="fas fa-lock"></i></span>
                                    <span class="cw-pf-vis-text">
                                        <strong><?php esc_html_e( 'Private', 'creativewings-core' ); ?></strong>
                                        <small><?php esc_html_e( 'Only you can see this project. Hidden from your public profile.', 'creativewings-core' ); ?></small>
                                    </span>
                                </label>
                            </div>
                        </fieldset>
                    </div>
                    <div class="cw-modal-footer">
                        <button type="submit" class="cw-modal-btn-submit" id="cw-pf-submit-btn"><?php _e('Create Project', 'creativewings-core'); ?></button>
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
                    <div class="cw-pf-view-desc" id="cw-pf-view-desc"></div>
                    <div id="cw-pf-gallery-section" style="display:none;">
                        <p class="cw-pf-gallery-label">Gallery</p>
                        <div class="cw-pf-gallery-grid" id="cw-pf-gallery-grid"></div>
                    </div>
                </div>
            </div>
        </div>

        <script>
            const CW_PF_CATEGORIES = <?php echo wp_json_encode( $cw_pf_categories ); ?>;

            function cwPfResetCoverPreview(){
                const preview = document.getElementById('cw-pf-cover-preview');
                const box     = document.getElementById('cw-pf-cover-box');
                const fileEl  = document.getElementById('pf_image');
                if (preview) { preview.src = ''; preview.removeAttribute('src'); }
                if (box)     { box.classList.remove('has-preview'); }
                if (fileEl)  { fileEl.value = ''; }
            }
            function cwPfSetCoverPreview(url){
                const preview = document.getElementById('cw-pf-cover-preview');
                const box     = document.getElementById('cw-pf-cover-box');
                if (!preview || !box) return;
                if (url) { preview.src = url; box.classList.add('has-preview'); }
                else     { cwPfResetCoverPreview(); }
            }
            function cwPfClearGalleryPreviews(){
                const grid = document.getElementById('cw-pf-gallery-preview-grid');
                if (grid) grid.innerHTML = '';
                const fileEl = document.getElementById('pf_gallery_input');
                if (fileEl) fileEl.value = '';
            }
            function cwPfRenderGalleryPreviews(){
                const grid   = document.getElementById('cw-pf-gallery-preview-grid');
                const fileEl = document.getElementById('pf_gallery_input');
                if (!grid || !fileEl) return;
                grid.innerHTML = '';
                if (!fileEl.files || fileEl.files.length === 0) return;
                Array.from(fileEl.files).forEach((file, idx) => {
                    const item = document.createElement('div');
                    item.className = 'cw-pf-gallery-preview-item';
                    const img = document.createElement('img');
                    img.alt = file.name || '';
                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'cw-pf-gallery-preview-remove';
                    removeBtn.setAttribute('aria-label', 'Remove image');
                    removeBtn.innerHTML = '&times;';
                    removeBtn.addEventListener('click', function(){
                        const dt = new DataTransfer();
                        Array.from(fileEl.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
                        fileEl.files = dt.files;
                        cwPfRenderGalleryPreviews();
                    });
                    const reader = new FileReader();
                    reader.onload = e => { img.src = e.target.result; };
                    reader.readAsDataURL(file);
                    item.appendChild(img);
                    item.appendChild(removeBtn);
                    grid.appendChild(item);
                });
            }

            function cwPfApplyCategory(cat){
                const sel      = document.getElementById('pf_category');
                const otherInp = document.getElementById('pf_category_other');
                const otherWrap= document.getElementById('pf_category_other_wrap');
                if (!sel) return;
                cat = cat || '';
                if (cat && CW_PF_CATEGORIES.indexOf(cat) !== -1 && cat !== 'Others') {
                    sel.value = cat;
                    if (otherInp) otherInp.value = '';
                    if (otherWrap) otherWrap.style.display = 'none';
                    if (otherInp) otherInp.required = false;
                } else if (cat) {
                    sel.value = 'Others';
                    if (otherInp)  otherInp.value = cat;
                    if (otherWrap) otherWrap.style.display = '';
                    if (otherInp)  otherInp.required = true;
                } else {
                    sel.value = '';
                    if (otherInp)  otherInp.value = '';
                    if (otherWrap) otherWrap.style.display = 'none';
                    if (otherInp)  otherInp.required = false;
                }
            }
            function cwPfOnCategoryChange(){
                const sel      = document.getElementById('pf_category');
                const otherInp = document.getElementById('pf_category_other');
                const otherWrap= document.getElementById('pf_category_other_wrap');
                if (!sel || !otherWrap) return;
                if (sel.value === 'Others') {
                    otherWrap.style.display = '';
                    if (otherInp) otherInp.required = true;
                } else {
                    otherWrap.style.display = 'none';
                    if (otherInp) { otherInp.required = false; otherInp.value = ''; }
                }
            }

            function cwPfSetDescription(html){
                const area = document.getElementById('pf_desc_editor');
                if (area) area.innerHTML = html || '';
            }
            function cwPfSyncDescription(){
                const area   = document.getElementById('pf_desc_editor');
                const hidden = document.getElementById('pf_desc_hidden');
                if (area && hidden) {
                    let html = area.innerHTML.trim();
                    // Treat an empty editor (only <br> or whitespace) as truly empty.
                    if (html === '<br>' || html === '<div><br></div>' || html === '<p><br></p>') html = '';
                    hidden.value = html;
                }
            }

            function cwPfSetVisibility(value){
                const v = (value === 'private') ? 'private' : 'public';
                const radios = document.querySelectorAll('input[name="pf_visibility"]');
                radios.forEach(r => { r.checked = (r.value === v); });
            }
            function openPortfolioModal(){
                document.getElementById('cw-pf-modal').style.display='flex';
                document.body.style.overflow = 'hidden';
                document.getElementById('pf_id').value='';
                document.getElementById('pf_title').value='';
                cwPfApplyCategory('');
                cwPfSetDescription('');
                cwPfResetCoverPreview();
                cwPfClearGalleryPreviews();
                cwPfSetVisibility('public');
                const submit = document.getElementById('cw-pf-submit-btn');
                if (submit) submit.textContent = <?php echo wp_json_encode( __( 'Create Project', 'creativewings-core' ) ); ?>;
                const header = document.querySelector('#cw-pf-modal .cw-modal-header h3');
                if (header) header.textContent = <?php echo wp_json_encode( __( 'Add New Project', 'creativewings-core' ) ); ?>;
            }
            function closeModal(){
                document.getElementById('cw-pf-modal').style.display='none';
                document.getElementById('cw-view-modal').style.display='none';
                document.body.style.overflow = '';
            }
            function editProject(d){
                document.getElementById('cw-pf-modal').style.display='flex';
                document.body.style.overflow = 'hidden';
                document.getElementById('pf_id').value = d._ID || '';
                document.getElementById('pf_title').value = d.title || '';
                cwPfApplyCategory(d.category || '');
                cwPfSetDescription(d.description || '');
                cwPfSetCoverPreview(d.img_url || '');
                cwPfClearGalleryPreviews();
                cwPfSetVisibility(d.visibility || 'public');
                const submit = document.getElementById('cw-pf-submit-btn');
                if (submit) submit.textContent = <?php echo wp_json_encode( __( 'Save Changes', 'creativewings-core' ) ); ?>;
                const header = document.querySelector('#cw-pf-modal .cw-modal-header h3');
                if (header) header.textContent = <?php echo wp_json_encode( __( 'Edit Project', 'creativewings-core' ) ); ?>;
            }
            function viewPortfolio(d){
                document.getElementById('cw-pf-view-title').textContent = d.title || '';
                document.getElementById('cw-pf-view-cat').textContent   = d.category || '';
                document.getElementById('cw-pf-view-desc').innerHTML    = d.description || '';

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
                    if($(e.target).is('#cw-view-modal, #cw-pf-modal')){ closeModal(); }
                });

                // Cover image: live preview when a new file is selected.
                const coverInput = document.getElementById('pf_image');
                if (coverInput) {
                    coverInput.addEventListener('change', function(){
                        if (this.files && this.files[0]) {
                            const reader = new FileReader();
                            reader.onload = e => cwPfSetCoverPreview(e.target.result);
                            reader.readAsDataURL(this.files[0]);
                        } else {
                            cwPfResetCoverPreview();
                        }
                    });
                }
                const coverRemove = document.getElementById('cw-pf-cover-remove');
                if (coverRemove) {
                    coverRemove.addEventListener('click', function(ev){
                        ev.stopPropagation();
                        ev.preventDefault();
                        cwPfResetCoverPreview();
                    });
                }

                // Gallery: render previews + per-thumb remove.
                const galInput = document.getElementById('pf_gallery_input');
                if (galInput) {
                    galInput.addEventListener('change', cwPfRenderGalleryPreviews);
                }

                // Category "Others" toggle.
                const catSel = document.getElementById('pf_category');
                if (catSel) {
                    catSel.addEventListener('change', cwPfOnCategoryChange);
                }

                // WYSIWYG toolbar.
                document.querySelectorAll('#cw-pf-modal .cw-wys-btn').forEach(function(btn){
                    btn.addEventListener('mousedown', function(ev){ ev.preventDefault(); });
                    btn.addEventListener('click', function(){
                        const cmd  = this.getAttribute('data-cmd');
                        const area = document.getElementById('pf_desc_editor');
                        if (area) area.focus();
                        if (cmd === 'createLink') {
                            const url = window.prompt(<?php echo wp_json_encode( __( 'Enter URL', 'creativewings-core' ) ); ?>, 'https://');
                            if (url) document.execCommand('createLink', false, url);
                        } else {
                            document.execCommand(cmd, false, null);
                        }
                        cwPfSyncDescription();
                    });
                });
                const descArea = document.getElementById('pf_desc_editor');
                if (descArea) {
                    descArea.addEventListener('input', cwPfSyncDescription);
                    descArea.addEventListener('blur',  cwPfSyncDescription);
                }

                // Sync description into hidden textarea right before submit.
                const pfForm = document.querySelector('#cw-pf-modal form.cw-modal-form-wrapper');
                if (pfForm) {
                    pfForm.addEventListener('submit', function(){
                        cwPfSyncDescription();
                    });
                }
            });
            document.addEventListener('keydown', function(e){ if(e.key==='Escape'){ closeModal(); } });
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

        // Prime caches once so the per-card foreach below doesn't N+1 on meta/terms.
        if ( ! empty( $opportunities ) ) {
            $opportunity_ids = array_map( static fn( $p ) => (int) $p->ID, $opportunities );
            update_meta_cache( 'post', $opportunity_ids );
            update_object_term_cache( $opportunity_ids, 'product' );
        }

        // Pre-load products this user has already joined (single SQL: postmeta JOIN posts).
        $joined_product_ids = [];
        if ( $uid ) {
            global $wpdb;
            $rows = $wpdb->get_col( $wpdb->prepare(
                "SELECT pm.meta_value
                 FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->postmeta} cm
                    ON cm.post_id = pm.post_id
                   AND cm.meta_key = 'customer_id'
                   AND cm.meta_value = %d
                 INNER JOIN {$wpdb->posts} p
                    ON p.ID = pm.post_id
                   AND p.post_type IN ('cw_competition_entry','cw_activity_entry')
                 WHERE pm.meta_key = 'product_id'",
                $uid
            ) );
            foreach ( (array) $rows as $pid ) {
                $pid = (int) $pid;
                if ( $pid ) $joined_product_ids[ $pid ] = true;
            }
        }

        // Pre-resolve organizer business names referenced by the cards (one query).
        $organizer_names_map = [];
        if ( ! empty( $opportunities ) ) {
            $org_ids = [];
            foreach ( $opportunities as $product ) {
                $oid = (int) get_post_meta( $product->ID, 'organizer_id', true );
                if ( $oid > 0 ) {
                    $org_ids[ $oid ] = true;
                }
            }
            $org_ids = array_keys( $org_ids );
            if ( ! empty( $org_ids ) ) {
                update_meta_cache( 'user', $org_ids );
                foreach ( $org_ids as $oid ) {
                    $organizer_names_map[ $oid ] = (string) get_user_meta( $oid, 'business_name', true );
                }
            }
        }

        // SDG reverse map (name → number) for icon display
        $sdg_map     = class_exists('CW_Business') ? CW_Business::get_sdg_map() : [];
        $sdg_reverse = array_flip( $sdg_map );
        $sdg_base    = 'https://creativewings.asia/wp-content/uploads/2025/12/';
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-dash-header">
                <h1>Explore Opportunities</h1>
                <p>Discover competitions and activities to join.</p>
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
                    $type_tag = 'Campaign';
                    $type_css = '';
                    if ( $terms && ! is_wp_error( $terms ) ) {
                        foreach ( $terms as $term ) {
                            if ( in_array( $term->slug, ['competitions', 'competition'] ) ) { $type_tag = 'Competition'; $type_css = 'competition'; break; }
                            if ( in_array( $term->slug, ['activities', 'activity'] ) )     { $type_tag = 'Activity';    $type_css = 'activity';    break; }
                            if ( in_array( $term->slug, ['talk-seminar', 'seminar'] ) )    { $type_tag = 'Seminar';     $type_css = 'seminar';     break; }
                        }
                    }

                    $organizer_id   = (int) get_post_meta( $product_id, 'organizer_id', true );
                    $organizer_name = ( $organizer_names_map[ $organizer_id ] ?? '' ) ?: 'Host';
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
                        <div class="cw-sdg-row cw-sdg-icons" aria-label="<?php esc_attr_e( 'Sustainable Development Goals', 'creativewings-core' ); ?>">
                            <?php foreach ( $active_sdgs as $sdg ):
                                $pad = str_pad( $sdg['num'], 2, '0', STR_PAD_LEFT ); ?>
                                <img src="<?php echo esc_url( $sdg_base . 'E_WEB_' . $pad . '.png' ); ?>"
                                     alt="SDG <?php echo (int) $sdg['num']; ?>"
                                     title="<?php echo esc_attr( $sdg['name'] ); ?>"
                                     class="cw-sdg-icon-img" loading="lazy">
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                        <?php if ( $is_closed ): ?>
                            <button class="cw-btn-join cw-btn-closed" disabled>
                                <i class="fas fa-lock"></i> Campaign Closed
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

        // Per-page selector: 5 / 10 / 25 / 50 / 100 / All (default 10).
        $allowed_per_page = ['5', '10', '25', '50', '100', 'all'];
        $per_page_raw = isset($_GET['act_per_page']) ? sanitize_text_field($_GET['act_per_page']) : '10';
        if ( ! in_array( $per_page_raw, $allowed_per_page, true ) ) {
            $per_page_raw = '10';
        }
        $show_all = ( $per_page_raw === 'all' );
        $per_page = $show_all ? -1 : intval( $per_page_raw );
        $paged    = isset($_GET['act_page']) ? max(1, intval($_GET['act_page'])) : 1;

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

        // Single WP_Query: get the page slice AND total count via found_posts.
        if ( $show_all ) {
            $paged_args                   = $base_args;
            $paged_args['posts_per_page'] = -1;
            $paged_args['no_found_rows']  = true;
        } else {
            $paged_args                   = $base_args;
            $paged_args['posts_per_page'] = $per_page;
            $paged_args['paged']          = $paged;
            $paged_args['no_found_rows']  = false;
        }
        $entries_q   = new WP_Query( $paged_args );
        $entries     = $entries_q->posts;
        $total_items = $show_all ? count( $entries ) : (int) $entries_q->found_posts;
        $total_pages = ( $show_all || $per_page <= 0 ) ? 1 : max( 1, (int) $entries_q->max_num_pages );

        // Clamp current page in case the filter shrunk the result set.
        if ( $paged > $total_pages ) {
            $paged = max( 1, $total_pages );
        }

        // Prime postmeta cache for the displayed entries + their associated product_ids.
        if ( ! empty( $entries ) ) {
            $entry_ids = array_map( static fn( $e ) => (int) $e->ID, $entries );
            update_meta_cache( 'post', $entry_ids );
            $referenced_pids = [];
            foreach ( $entry_ids as $eid ) {
                $rpid = (int) get_post_meta( $eid, 'product_id', true );
                if ( $rpid ) $referenced_pids[ $rpid ] = true;
            }
            $referenced_pids = array_keys( $referenced_pids );
            if ( ! empty( $referenced_pids ) ) {
                update_meta_cache( 'post', $referenced_pids );
                update_object_term_cache( $referenced_pids, 'product' );
            }
        }

        // Base URL used for filter pills (preserve per-page choice, reset to page 1).
        $filter_link = function( $slug ) use ( $activities_url, $per_page_raw ) {
            return add_query_arg([
                'filter'       => $slug,
                'act_per_page' => $per_page_raw,
                'act_page'     => 1,
            ], $activities_url);
        };
        // Base URL for pagination + per-page selector (preserve current filter).
        $pagination_base_url = add_query_arg([
            'filter'       => $current_filter,
            'act_per_page' => $per_page_raw,
        ], $activities_url);
        $per_page_base_url   = add_query_arg([
            'filter' => $current_filter,
        ], $activities_url);
        ?>

        <div class="cw-content-wrapper">
            <div class="cw-header-flex">
                <div>
                    <h2 class="cw-section-title">My Activities</h2>
                    <p class="cw-section-subtitle">Track your competitions and activities.</p>
                </div>

                <div class="cw-filter-group">
                    <a href="<?php echo esc_url( $filter_link('all') ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'all') ? 'active' : ''; ?>">All</a>
                    <a href="<?php echo esc_url( $filter_link('competitions') ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'competitions') ? 'active' : ''; ?>">Competitions</a>
                    <a href="<?php echo esc_url( $filter_link('activities') ); ?>"
                       class="cw-filter-btn <?php echo ($current_filter === 'activities') ? 'active' : ''; ?>">Activities</a>
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

                    $is_judged = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : ( $type_css === 'competition' );

                    if ( ! $is_judged )                              { $status = 'Completed';  $cls = 'completed'; }
                    elseif ( $is_winner )                            { $status = 'Winner';     $cls = 'winner'; }
                    elseif ( $score !== '' && (float)$score > 0 )    { $status = 'Reviewed';   $cls = 'reviewed'; }
                    else                                             { $status = 'Registered'; $cls = 'registered'; }

                    $thumb = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';

                    $entry_vote_count = 0;
                    $entry_voting_on  = false;
                    if ( $type_css === 'competition' ) {
                        $entry_voting_on  = get_post_meta( $pid, 'cw_enable_voting', true ) === 'yes';
                        $entry_vote_count = (int) get_post_meta( $e->ID, 'vote_count', true );
                    }

                    $entry_cert_enabled = get_post_meta( $pid, 'cw_enable_certificate', true ) === 'yes';
                    $entry_cert_available = $entry_cert_enabled && class_exists( 'CW_Certificate' ) && CW_Certificate::entry_cert_available( $e->ID );
                    $entry_cert_eta_raw   = get_post_meta( $pid, 'submission_deadline', true );
                    $entry_cert_eta       = $entry_cert_eta_raw ? date_i18n( get_option( 'date_format', 'd M Y' ), strtotime( $entry_cert_eta_raw ) ) : '';
                    $entry_cert_url       = ( $entry_cert_enabled && class_exists( 'CW_Certificate' ) ) ? CW_Certificate::download_url( $e->ID ) : '';

                    $modal_data = htmlspecialchars( json_encode([
                        'id'             => $e->ID,
                        'title'          => $product->get_name(),
                        'date'           => get_the_date( 'Y-m-d', $e->ID ),
                        'status'         => strtoupper( $status ),
                        'score'          => $score ?: '0',
                        'comment'        => get_post_meta( $e->ID, 'judge_comment', true ) ?: 'No feedback provided yet.',
                        'cert_enabled'   => $entry_cert_enabled,
                        'cert_available' => $entry_cert_available,
                        'cert_eta'       => $entry_cert_eta,
                        'cert_url'       => $entry_cert_url,
                        'details'        => get_post_meta( $e->ID, 'participant_details', true ) ?: [],
                        'vote_count'     => $entry_vote_count,
                        'voting_on'      => $entry_voting_on,
                    ]), ENT_QUOTES, 'UTF-8' );
                ?>
                <div class="cw-activity-card">
                    <div class="cw-activity-image-wrap">
                        <img src="<?php echo esc_url( $thumb ); ?>" alt="<?php echo esc_attr( $product->get_name() ); ?>" loading="lazy" decoding="async">
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

            <div class="cw-list-foot">
                <?php $this->render_per_page_selector( 'act_per_page', $per_page_raw, $per_page_base_url, 'act_page' ); ?>
                <?php $this->render_pagination( $paged, $total_pages, $pagination_base_url, 'act_page' ); ?>
            </div>

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
            const certBox = jQuery('#m-cert-bar');
            if (data.cert_enabled && data.cert_available && data.cert_url) {
                certBox.html(`
                    <div class="cw-entry-cert-banner">
                        <div><strong>🎓 Certificate Ready</strong><p>Download your achievement certificate.</p></div>
                        <a href="${data.cert_url}" class="cw-btn-primary cw-btn-sm" target="_blank" rel="noopener">
                            <i class="fas fa-download"></i> Download
                        </a>
                    </div>`);
            } else if (data.cert_enabled && data.cert_eta) {
                certBox.html(`
                    <div class="cw-entry-cert-banner" style="background:#fff7ed;border-color:#fed7aa;">
                        <div><strong>🎓 Certificate Coming Soon</strong><p>Available after the event ends on <strong>${data.cert_eta}</strong>.</p></div>
                        <button class="cw-btn-disabled cw-btn-sm" disabled>
                            <i class="fas fa-clock"></i> Not yet available
                        </button>
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
        $fields=['creator_display_name', 'creator_tagline', 'creator_bio', 'awards_won', 'creator_skills', 'website_url', 'linkeden_url', 'instagram_url', 'twitter_url', 'Facebook_url', 'behave_url', 'creator_address', 'birthdate'];
        foreach($fields as $f){if(isset($_POST[$f])){ $val=($f==='creator_bio')?wp_kses_post($_POST['creator_bio']):trim(sanitize_text_field(wp_unslash($_POST[$f]))); update_user_meta($uid,$f,$val); }}
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

        // Category: when "Others" is selected, store the user-supplied text instead.
        $pf_category_raw = sanitize_text_field( $_POST['pf_category'] ?? '' );
        if ( $pf_category_raw === 'Others' ) {
            $pf_category_other = sanitize_text_field( $_POST['pf_category_other'] ?? '' );
            if ( $pf_category_other !== '' ) {
                $pf_category_raw = $pf_category_other;
            }
        }

        $pf_visibility_raw = isset( $_POST['pf_visibility'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_visibility'] ) ) : 'public';
        $pf_visibility     = ( $pf_visibility_raw === 'private' ) ? 'private' : 'public';

        $data=['title'=>sanitize_text_field($_POST['pf_title']),'category'=>$pf_category_raw,'description'=>wp_kses_post($_POST['pf_desc']),'visibility'=>$pf_visibility,'cct_modified'=>current_time('mysql'),'cct_author_id'=>$uid,'created_by'=>$uid,'cct_status'=>'publish'];
        
        require_once(ABSPATH.'wp-admin/includes/image.php');
        require_once(ABSPATH.'wp-admin/includes/file.php');
        require_once(ABSPATH.'wp-admin/includes/media.php');
        
        if(!empty($_FILES['pf_image']['name'])){
            $aid=media_handle_upload('pf_image',0);
            if(!is_wp_error($aid)){
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $aid, 'portfolio' );
                }
                $data['image']=serialize(['id'=>$aid,'url'=>wp_get_attachment_url($aid)]);
            }
        }
        if(!empty($_FILES['pf_gallery']['name'][0])){
            $gals=[]; $files=$_FILES['pf_gallery'];
            foreach($files['name'] as $k=>$v){
                if($files['name'][$k]){
                    $_FILES['s_file']=['name'=>$files['name'][$k],'type'=>$files['type'][$k],'tmp_name'=>$files['tmp_name'][$k],'error'=>$files['error'][$k],'size'=>$files['size'][$k]];
                    $gid=media_handle_upload('s_file',0);
                    if(!is_wp_error($gid)){
                        if ( class_exists( 'CW_Image_Optimizer' ) ) {
                            CW_Image_Optimizer::optimize_attachment( $gid, 'gallery' );
                        }
                        $gals[]=['id'=>$gid,'url'=>wp_get_attachment_url($gid)];
                    }
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
    private function render_pagination( $current_page, $total_pages, $base_url, $page_arg = 'cw_page' ) {
        if ( $total_pages <= 1 ) return;
        ?>
        <nav class="cw-pagination-nav" aria-label="Pagination">
            <?php if ( $current_page > 1 ): ?>
                <a href="<?php echo esc_url( add_query_arg( $page_arg, $current_page - 1, $base_url ) ); ?>" class="cw-page-btn prev" aria-label="Previous page">
                    <i class="fas fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="cw-page-btn prev disabled" aria-label="Previous page"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php
            $range   = 2;
            $start   = max( 1, $current_page - $range );
            $end     = min( $total_pages, $current_page + $range );

            if ( $start > 1 ) {
                echo '<a href="' . esc_url( add_query_arg( $page_arg, 1, $base_url ) ) . '" class="cw-page-btn">1</a>';
                if ( $start > 2 ) echo '<span class="cw-page-ellipsis">…</span>';
            }
            for ( $i = $start; $i <= $end; $i++ ):
                $active = ( $i === $current_page ) ? 'active' : '';
            ?>
                <a href="<?php echo esc_url( add_query_arg( $page_arg, $i, $base_url ) ); ?>"
                   class="cw-page-btn <?php echo $active; ?>"
                   <?php echo $active ? 'aria-current="page"' : ''; ?>>
                    <?php echo $i; ?>
                </a>
            <?php endfor;
            if ( $end < $total_pages ) {
                if ( $end < $total_pages - 1 ) echo '<span class="cw-page-ellipsis">…</span>';
                echo '<a href="' . esc_url( add_query_arg( $page_arg, $total_pages, $base_url ) ) . '" class="cw-page-btn">' . $total_pages . '</a>';
            }
            ?>

            <?php if ( $current_page < $total_pages ): ?>
                <a href="<?php echo esc_url( add_query_arg( $page_arg, $current_page + 1, $base_url ) ); ?>" class="cw-page-btn next" aria-label="Next page">
                    <i class="fas fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="cw-page-btn next disabled" aria-label="Next page"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
        <?php
    }

    /* ==========================================================================
       SHARED: Per-page selector renderer
       ========================================================================== */
    private function render_per_page_selector( $arg_name, $current_value, $base_url, $page_arg = 'cw_page' ) {
        $options = [
            '5'   => '5',
            '10'  => '10',
            '25'  => '25',
            '50'  => '50',
            '100' => '100',
            'all' => 'All',
        ];
        $current_value = (string) $current_value;
        if ( ! isset( $options[ $current_value ] ) ) {
            $current_value = '10';
        }
        ?>
        <div class="cw-per-page-wrap">
            <label for="<?php echo esc_attr( $arg_name ); ?>-select">Per page:</label>
            <select id="<?php echo esc_attr( $arg_name ); ?>-select"
                    class="cw-per-page-select"
                    aria-label="Items per page"
                    onchange="if(this.value){ window.location.href = this.value; }">
                <?php foreach ( $options as $val => $label ):
                    $url = add_query_arg( [
                        $arg_name => $val,
                        $page_arg => 1,
                    ], $base_url );
                    $sel = ( $current_value === $val ) ? 'selected' : '';
                ?>
                    <option value="<?php echo esc_url( $url ); ?>" <?php echo $sel; ?>>
                        <?php echo esc_html( $label ); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php
    }
}