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
                if ( class_exists( 'CW' ) ) {
                    CW::tag_plugin_media( (int) $aid );
                }
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    $ctx = ( strpos( $file_key, 'header' ) !== false ) ? 'cover' : 'avatar';
                    CW_Image_Optimizer::optimize_attachment( $aid, $ctx );
                }
                update_user_meta($uid, $file_key, ['id'=>$aid, 'url'=>wp_get_attachment_url($aid)]);
                // Profile photo also drives the global WP/Woo avatar via CW_Users::filter_avatar_data.
                if ( $file_key === 'creator_profile_image' ) {
                    update_user_meta( (int) $uid, 'cw_avatar_attachment_id', (int) $aid );
                }
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

        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $portfolio_tab  = add_query_arg( 'tab', 'portfolio', $my_account_url );
        $profile_tab    = add_query_arg( 'tab', 'profile', $my_account_url );
        $activities_tab = add_query_arg( 'tab', 'activities', $my_account_url );
        $explore_tab    = add_query_arg( 'tab', 'explore', $my_account_url );
        $badges_tab     = add_query_arg( 'tab', 'badges', $my_account_url );
        ?>
        <div class="cw-content-wrapper cw-ov cw-ov--creator">
            <header class="cw-ov-welcome">
                <div class="cw-ov-welcome-copy">
                    <h2 class="cw-ov-welcome-title">
                        <?php
                        printf(
                            /* translators: %s: creator display name */
                            esc_html__( 'Welcome back, %s', 'creativewings-core' ),
                            esc_html( $display_name )
                        );
                        ?>
                    </h2>
                    <p class="cw-ov-welcome-sub">
                        <?php esc_html_e( 'Grow your public profile, track campaigns, and keep your portfolio current.', 'creativewings-core' ); ?>
                    </p>
                </div>
                <div class="cw-ov-welcome-actions">
                    <?php if ( $profile_url ) : ?>
                        <a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer" class="cw-btn-primary cw-ov-btn-primary">
                            <?php esc_html_e( 'View public profile', 'creativewings-core' ); ?>
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( $explore_tab ); ?>" class="cw-ov-btn-secondary">
                        <?php esc_html_e( 'Explore', 'creativewings-core' ); ?>
                    </a>
                    <button type="button" class="cw-ov-btn-secondary" id="cw-ov-link-code-open" aria-haspopup="dialog" aria-controls="cw-ov-link-modal">
                        <?php esc_html_e( 'Link a code', 'creativewings-core' ); ?>
                    </button>
                </div>
            </header>

            <?php if ( ! empty( $missing_dir_fields ) ) : ?>
                <div class="cw-dir-nudge" role="status">
                    <div class="cw-dir-nudge-body">
                        <h3><?php esc_html_e( 'Complete your profile to appear in the public directory', 'creativewings-core' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'You won\'t be listed on the creators directory until these basics are filled in:', 'creativewings-core' ); ?>
                        </p>
                        <div class="cw-dir-nudge-chips">
                            <?php foreach ( $missing_dir_fields as $field ) :
                                $label = $missing_dir_labels[ $field ] ?? ucwords( str_replace( [ 'creator_', '_' ], [ '', ' ' ], $field ) );
                                ?>
                                <span class="cw-dir-nudge-chip"><?php echo esc_html( $label ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo esc_url( $profile_tab ); ?>" class="cw-btn-primary cw-dir-nudge-cta">
                            <?php esc_html_e( 'Complete profile', 'creativewings-core' ); ?>
                        </a>
                    </div>
                </div>
            <?php endif; ?>

            <?php
            if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                $owned_badges = CW_Badges_Engine::get_user_badges( $uid );
                if ( ! empty( $owned_badges ) ) :
                    ?>
                    <div class="cw-badges cw-badges-latest">
                        <h4><?php esc_html_e( 'Latest achievements', 'creativewings-core' ); ?></h4>
                        <?php echo CW_Badges_Display::render_strip( $owned_badges, 4, [ 'size' => 'sm', 'show_label' => false, 'show_tier' => false ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
                        <a href="<?php echo esc_url( $badges_tab ); ?>" class="cw-badges-latest-link">
                            <?php esc_html_e( 'See all badges', 'creativewings-core' ); ?> &rarr;
                        </a>
                    </div>
                <?php endif;
            }
            ?>

            <div class="cw-ov-metrics" role="list">
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value"><?php echo esc_html( number_format_i18n( $views ) ); ?></p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Profile views', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value">
                        <a href="<?php echo esc_url( $portfolio_tab ); ?>" class="cw-ov-metric-link">
                            <?php echo esc_html( number_format_i18n( (int) $port_count ) ); ?>
                        </a>
                    </p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Portfolio items', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value"><?php echo esc_html( number_format_i18n( $active_engagements ) ); ?></p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Active campaigns', 'creativewings-core' ); ?></p>
                </div>
                <div class="cw-ov-metric" role="listitem">
                    <p class="cw-ov-metric-value"><?php echo esc_html( number_format_i18n( $completed_entries ) ); ?></p>
                    <p class="cw-ov-metric-label"><?php esc_html_e( 'Completed', 'creativewings-core' ); ?></p>
                </div>
            </div>

            <div class="cw-main-content-grid">
                <div class="cw-chart-panel">
                    <h3>
                        <?php esc_html_e( 'Profile traffic', 'creativewings-core' ); ?>
                        <span class="cw-chart-panel-meta"><?php esc_html_e( 'Last 7 days', 'creativewings-core' ); ?></span>
                    </h3>
                    <div id="trafficChartContainer">
                        <canvas id="trafficChart"></canvas>
                    </div>
                </div>

                <div class="cw-quick-actions">
                    <h3><?php esc_html_e( 'Quick actions', 'creativewings-core' ); ?></h3>
                    <div class="cw-qa-grid" role="list">
                        <a href="<?php echo esc_url( $portfolio_tab ); ?>" onclick="if (typeof openPortfolioModal === 'function') { openPortfolioModal(); return false; }" class="cw-qa-tile" role="listitem">
                            <?php esc_html_e( 'Add project', 'creativewings-core' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $profile_tab ); ?>" class="cw-qa-tile" role="listitem">
                            <?php esc_html_e( 'Edit profile', 'creativewings-core' ); ?>
                        </a>
                        <a href="<?php echo esc_url( $activities_tab ); ?>" class="cw-qa-tile" role="listitem">
                            <?php esc_html_e( 'Activities', 'creativewings-core' ); ?>
                        </a>
                        <button type="button" class="cw-qa-tile" id="cw-ov-link-code-action" role="listitem">
                            <?php esc_html_e( 'Link a code', 'creativewings-core' ); ?>
                        </button>
                        <a href="<?php echo esc_url( $badges_tab ); ?>" class="cw-qa-tile cw-qa-tile--wide" role="listitem">
                            <?php esc_html_e( 'My badges', 'creativewings-core' ); ?>
                        </a>
                    </div>
                </div>
            </div>

            <?php if ( $recent_ports ) : ?>
            <section class="cw-recent-section" aria-labelledby="cw-creator-recent-heading">
                <div class="cw-recent-head">
                    <h3 id="cw-creator-recent-heading"><?php esc_html_e( 'Recent portfolio', 'creativewings-core' ); ?></h3>
                    <a href="<?php echo esc_url( $portfolio_tab ); ?>" class="cw-public-profile-link"><?php esc_html_e( 'View all', 'creativewings-core' ); ?> &rarr;</a>
                </div>
                <div class="cw-recent-portfolio-row">
                    <?php foreach ( $recent_ports as $rp ) :
                        $rp_img_data = maybe_unserialize( $rp->image );
                        $rp_img      = ( is_array( $rp_img_data ) && isset( $rp_img_data['url'] ) ) ? $rp_img_data['url'] : '';
                        ?>
                    <div class="cw-recent-pf-card">
                        <div class="cw-recent-pf-thumb" <?php if ( $rp_img ) : ?>style="background-image:url('<?php echo esc_url( $rp_img ); ?>')"<?php endif; ?>>
                            <?php if ( ! $rp_img ) : ?><i class="fas fa-image" aria-hidden="true"></i><?php endif; ?>
                        </div>
                        <div class="cw-recent-pf-info">
                            <span class="cw-recent-pf-cat"><?php echo esc_html( $rp->category ?: '—' ); ?></span>
                            <p><?php echo esc_html( $rp->title ); ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </section>
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
                var chartData = <?php echo wp_json_encode( $chart_data ); ?>;
                new Chart(ctx, {
                    type: 'bar',
                    data: {
                        labels: chartData.map(function(d){ return d.name; }),
                        datasets: [{
                            label: 'Views',
                            data: chartData.map(function(d){ return d.views; }),
                            backgroundColor: '#125B9A',
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
        $this->render_overview_link_code_modal();
    }

    /**
     * Overview quick-enter modal for school submission codes.
     */
    private function render_overview_link_code_modal() {
        ?>
        <div id="cw-ov-link-modal" class="cw-ov-link-modal" role="dialog" aria-modal="true" aria-labelledby="cw-ov-link-modal-title" hidden>
            <div class="cw-ov-link-modal-backdrop" data-cw-ov-link-close tabindex="-1"></div>
            <div class="cw-ov-link-modal-panel">
                <button type="button" class="cw-ov-link-modal-close" data-cw-ov-link-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
                <h2 id="cw-ov-link-modal-title" class="cw-ov-link-modal-title"><?php esc_html_e( 'Link a submission code', 'creativewings-core' ); ?></h2>
                <p class="cw-ov-link-modal-sub"><?php esc_html_e( 'Enter the code from your school. One code per campaign.', 'creativewings-core' ); ?></p>
                <?php
                if ( function_exists( 'cw_core' ) && cw_core()->claim_flow ) {
                    cw_core()->claim_flow->render_quick_link_form( 'cw-ov-link-code-input' );
                }
                ?>
            </div>
        </div>
        <script>
        (function () {
            var modal = document.getElementById('cw-ov-link-modal');
            var openBtns = [
                document.getElementById('cw-ov-link-code-open'),
                document.getElementById('cw-ov-link-code-action')
            ].filter(Boolean);
            if (!modal || !openBtns.length) return;
            var input = document.getElementById('cw-ov-link-code-input');
            var lastFocus = null;

            function openModal() {
                lastFocus = document.activeElement;
                modal.hidden = false;
                modal.classList.add('is-open');
                document.body.classList.add('cw-ov-link-modal-open');
                if (input) {
                    setTimeout(function () { input.focus(); }, 20);
                }
            }
            function closeModal() {
                modal.classList.remove('is-open');
                modal.hidden = true;
                document.body.classList.remove('cw-ov-link-modal-open');
                if (lastFocus && lastFocus.focus) lastFocus.focus();
            }
            openBtns.forEach(function (btn) {
                btn.addEventListener('click', openModal);
            });
            modal.querySelectorAll('[data-cw-ov-link-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && modal.classList.contains('is-open')) {
                    closeModal();
                }
            });
        })();
        </script>
        <?php
    }
    /* ==========================================================================
       2. EDIT PROFILE (Nova UI Overhaul - Final Version)
       ========================================================================== */
    public function render_profile() {
        $uid = get_current_user_id();
        $u   = get_userdata( $uid );
        $fields = [
            'creator_display_name',
            'creator_tagline',
            'creator_bio',
            'awards_won',
            'creator_skills',
            'website_url',
            'linkeden_url',
            'instagram_url',
            'twitter_url',
            'Facebook_url',
            'behave_url',
            'creator_address',
            'birthdate',
        ];
        $meta = [];
        foreach ( $fields as $f ) {
            $meta[ $f ] = get_user_meta( $uid, $f, true );
        }

        $img_data = get_user_meta( $uid, 'creator_profile_image', true );
        $img_url  = ( is_array( $img_data ) && isset( $img_data['url'] ) ) ? $img_data['url'] : get_avatar_url( $uid );
        $hdr_data = get_user_meta( $uid, 'creator_header_image', true );
        // Prefer uploaded header; fall back to the public-profile default asset
        // (legacy assets/img/default-header.jpg is missing on many installs).
        $hdr_url = ( is_array( $hdr_data ) && ! empty( $hdr_data['url'] ) )
            ? $hdr_data['url']
            : 'https://creativewings.asia/wp-content/uploads/2025/09/Asset-2@2x.png';

        $display_name = $meta['creator_display_name'] ?: ( $u ? $u->display_name : '' );
        $profile_url  = ( $u && class_exists( 'CW_Roles' ) )
            ? CW_Roles::get_public_portfolio_url( $u )
            : ( $u ? home_url( '/profile/' . $u->user_login . '/' ) : '' );

        $addr = class_exists( 'CW_Points_Rewards' )
            ? CW_Points_Rewards::get_user_shipping_address( $uid )
            : [
                'first_name' => $u ? $u->first_name : '',
                'last_name'  => $u ? $u->last_name : '',
                'phone'      => '',
                'address_1'  => '',
                'address_2'  => '',
                'city'       => '',
                'state'      => '',
                'postcode'   => '',
                'country'    => 'MY',
            ];
        if ( empty( $addr['first_name'] ) && $u ) {
            $addr['first_name'] = $u->first_name;
        }
        if ( empty( $addr['last_name'] ) && $u ) {
            $addr['last_name'] = $u->last_name;
        }

        $skills_array = array_filter( array_map( 'trim', explode( ',', (string) $meta['creator_skills'] ) ) );
        ?>
        <div class="cw-content-wrapper cw-settings-atelier cw-creator-profile-atelier">
            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" enctype="multipart/form-data" class="cw-settings-atelier-form cw-modern-form" autocomplete="off">
                <input type="hidden" name="action" value="cw_save_creative_profile">
                <?php wp_nonce_field( 'cw_profile_nonce' ); ?>

                <div class="cw-settings-atelier-layout">
                    <aside class="cw-settings-rail" aria-label="<?php esc_attr_e( 'Your profile', 'creativewings-core' ); ?>">
                        <div class="cw-settings-rail__avatar">
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="" class="cw-settings-rail__avatar-img" loading="lazy" decoding="async" id="cw-rail-avatar-preview" />
                        </div>
                        <h2 class="cw-settings-rail__name"><?php echo esc_html( $display_name ?: ( $u ? $u->user_login : '' ) ); ?></h2>
                        <?php if ( $u ) : ?>
                            <p class="cw-settings-rail__email"><?php echo esc_html( $u->user_email ); ?></p>
                        <?php endif; ?>
                        <p class="cw-settings-rail__note"><?php esc_html_e( 'Presence and social links appear on your public profile. Delivery address stays private for checkout and merch.', 'creativewings-core' ); ?></p>
                        <?php if ( $profile_url ) : ?>
                            <a href="<?php echo esc_url( $profile_url ); ?>" target="_blank" rel="noopener noreferrer" class="cw-settings-rail__link">
                                <?php esc_html_e( 'View public profile', 'creativewings-core' ); ?>
                            </a>
                        <?php endif; ?>
                    </aside>

                    <div class="cw-settings-atelier-main">
                        <header class="cw-settings-atelier-head">
                            <h1><?php esc_html_e( 'Profile Settings', 'creativewings-core' ); ?></h1>
                            <p><?php esc_html_e( 'Manage your public presence and private delivery details.', 'creativewings-core' ); ?></p>
                        </header>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Presence', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'Shown on your public creator profile and directory listing.', 'creativewings-core' ); ?></p>
                            </header>

                            <div class="cw-settings-header cw-creator-banner-block">
                                <img src="<?php echo esc_url( $hdr_url ); ?>" alt="" class="cw-banner-image" id="cw-banner-preview" loading="lazy" decoding="async" />
                                <label for="creator_header_image_upload" class="cw-header-upload-btn">
                                    <i class="fas fa-camera" aria-hidden="true"></i> <?php esc_html_e( 'Change banner', 'creativewings-core' ); ?>
                                </label>
                                <input type="file" id="creator_header_image_upload" name="creator_header_image" accept="image/*" class="cw-visually-hidden" onchange="if(this.files[0]){ var img=document.getElementById('cw-banner-preview'); if(img) img.src=URL.createObjectURL(this.files[0]); }">

                                <div class="cw-avatar-composite">
                                    <img src="<?php echo esc_url( $img_url ); ?>" alt="" class="cw-profile-avatar" loading="lazy" decoding="async" />
                                    <div class="cw-profile-status-dot" aria-hidden="true"></div>
                                    <label for="creator_profile_image_upload" class="cw-avatar-upload-hit" aria-label="<?php esc_attr_e( 'Change profile photo', 'creativewings-core' ); ?>"></label>
                                    <input type="file" id="creator_profile_image_upload" name="creator_profile_image" accept="image/*" class="cw-visually-hidden" onchange="if(this.files[0]){ var url=URL.createObjectURL(this.files[0]); var a=this.closest('.cw-avatar-composite').querySelector('img'); if(a) a.src=url; var r=document.getElementById('cw-rail-avatar-preview'); if(r) r.src=url; }">
                                </div>
                            </div>

                            <div class="cw-settings-name-grid">
                                <div class="cw-field">
                                    <label for="cw-creator-display-name"><?php esc_html_e( 'Display name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-creator-display-name" name="creator_display_name" class="cw-input" value="<?php echo esc_attr( $meta['creator_display_name'] ); ?>" placeholder="<?php esc_attr_e( 'Alex Morgan', 'creativewings-core' ); ?>">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-creator-tagline"><?php esc_html_e( 'Tagline', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-creator-tagline" name="creator_tagline" class="cw-input" value="<?php echo esc_attr( $meta['creator_tagline'] ); ?>" placeholder="<?php esc_attr_e( 'UI/UX designer & illustrator', 'creativewings-core' ); ?>">
                                </div>
                            </div>

                            <div class="cw-field">
                                <label for="birthdate"><?php esc_html_e( 'Date of birth', 'creativewings-core' ); ?></label>
                                <input type="text" id="birthdate" name="birthdate" class="cw-input cw-datepicker" value="<?php echo esc_attr( $meta['birthdate'] ); ?>" placeholder="dd/mm/yyyy" readonly autocomplete="bday">
                            </div>

                            <div class="cw-field cw-rich-text-area">
                                <label for="creator_bio_editor"><?php esc_html_e( 'Bio', 'creativewings-core' ); ?></label>
                                <?php
                                wp_editor(
                                    $meta['creator_bio'],
                                    'creator_bio_editor',
                                    [
                                        'textarea_name' => 'creator_bio',
                                        'media_buttons' => false,
                                        'textarea_rows' => 5,
                                        'teeny'         => true,
                                        'quicktags'     => false,
                                        'editor_class'  => 'cw-slim-editor-dark',
                                    ]
                                );
                                ?>
                            </div>

                            <div class="cw-field">
                                <label for="cw-skills-input"><?php esc_html_e( 'Skills', 'creativewings-core' ); ?></label>
                                <div id="cw-skills-wrapper" class="cw-tags-input-container">
                                    <?php foreach ( $skills_array as $skill ) : ?>
                                        <span class="cw-tag-v2" data-skill="<?php echo esc_attr( $skill ); ?>"><?php echo esc_html( $skill ); ?> <i class="fas fa-times" aria-hidden="true"></i></span>
                                    <?php endforeach; ?>
                                    <input type="text" id="cw-skills-input" class="cw-input-ghost" placeholder="<?php esc_attr_e( 'Press Enter to add', 'creativewings-core' ); ?>">
                                </div>
                                <input type="hidden" name="creator_skills" id="cw_skills_hidden" value="<?php echo esc_attr( implode( ',', $skills_array ) ); ?>">
                                <small><?php esc_html_e( 'Focus areas shown as chips on your public profile.', 'creativewings-core' ); ?></small>
                            </div>

                            <div class="cw-field">
                                <label for="cw-awards-won"><?php esc_html_e( 'Awards', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-awards-won" name="awards_won" class="cw-input" value="<?php echo esc_attr( $meta['awards_won'] ); ?>" placeholder="<?php esc_attr_e( 'Best in Show 2025', 'creativewings-core' ); ?>">
                            </div>

                            <div class="cw-field">
                                <label for="cw-creator-location"><?php esc_html_e( 'Location', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-creator-location" name="creator_address" class="cw-input" value="<?php echo esc_attr( $meta['creator_address'] ); ?>" placeholder="<?php esc_attr_e( 'Kuala Lumpur, MY', 'creativewings-core' ); ?>">
                                <small><?php esc_html_e( 'Short public location (city or region) for your profile and directory.', 'creativewings-core' ); ?></small>
                            </div>
                        </section>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Social', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'Links shown on your public profile.', 'creativewings-core' ); ?></p>
                            </header>
                            <div class="cw-settings-name-grid">
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-website-url"><?php esc_html_e( 'Website', 'creativewings-core' ); ?></label>
                                    <i class="fas fa-globe" aria-hidden="true"></i>
                                    <input type="url" id="cw-website-url" name="website_url" class="cw-input" value="<?php echo esc_attr( $meta['website_url'] ); ?>" placeholder="https://">
                                </div>
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-behance-url"><?php esc_html_e( 'Behance', 'creativewings-core' ); ?></label>
                                    <i class="fab fa-behance" aria-hidden="true"></i>
                                    <input type="url" id="cw-behance-url" name="behave_url" class="cw-input" value="<?php echo esc_attr( $meta['behave_url'] ); ?>" placeholder="https://behance.net/">
                                </div>
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-instagram-url"><?php esc_html_e( 'Instagram', 'creativewings-core' ); ?></label>
                                    <i class="fab fa-instagram" aria-hidden="true"></i>
                                    <input type="url" id="cw-instagram-url" name="instagram_url" class="cw-input" value="<?php echo esc_attr( $meta['instagram_url'] ); ?>" placeholder="https://instagram.com/">
                                </div>
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-twitter-url"><?php esc_html_e( 'X / Twitter', 'creativewings-core' ); ?></label>
                                    <i class="fab fa-twitter" aria-hidden="true"></i>
                                    <input type="url" id="cw-twitter-url" name="twitter_url" class="cw-input" value="<?php echo esc_attr( $meta['twitter_url'] ); ?>" placeholder="https://x.com/">
                                </div>
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-facebook-url"><?php esc_html_e( 'Facebook', 'creativewings-core' ); ?></label>
                                    <i class="fab fa-facebook" aria-hidden="true"></i>
                                    <input type="url" id="cw-facebook-url" name="Facebook_url" class="cw-input" value="<?php echo esc_attr( $meta['Facebook_url'] ); ?>" placeholder="https://facebook.com/">
                                </div>
                                <div class="cw-field cw-social-input-wrap">
                                    <label for="cw-linkedin-url"><?php esc_html_e( 'LinkedIn', 'creativewings-core' ); ?></label>
                                    <i class="fab fa-linkedin" aria-hidden="true"></i>
                                    <input type="url" id="cw-linkedin-url" name="linkeden_url" class="cw-input" value="<?php echo esc_attr( $meta['linkeden_url'] ); ?>" placeholder="https://linkedin.com/in/">
                                </div>
                            </div>
                        </section>

                        <section class="cw-settings-panel">
                            <header class="cw-settings-panel__head">
                                <h3><?php esc_html_e( 'Delivery address', 'creativewings-core' ); ?></h3>
                                <p><?php esc_html_e( 'Private — prefills checkout and merchandise delivery. Not shown on your public profile.', 'creativewings-core' ); ?></p>
                            </header>
                            <div class="cw-settings-name-grid">
                                <div class="cw-field">
                                    <label for="cw-addr-first"><?php esc_html_e( 'Recipient first name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-first" name="addr_first_name" class="cw-input" value="<?php echo esc_attr( $addr['first_name'] ); ?>" autocomplete="shipping given-name">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-last"><?php esc_html_e( 'Recipient last name', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-last" name="addr_last_name" class="cw-input" value="<?php echo esc_attr( $addr['last_name'] ); ?>" autocomplete="shipping family-name">
                                </div>
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-phone"><?php esc_html_e( 'Phone', 'creativewings-core' ); ?></label>
                                <input type="tel" id="cw-addr-phone" name="addr_phone" class="cw-input" value="<?php echo esc_attr( $addr['phone'] ); ?>" autocomplete="tel">
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-1"><?php esc_html_e( 'Address', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-addr-1" name="addr_address_1" class="cw-input" value="<?php echo esc_attr( $addr['address_1'] ); ?>" autocomplete="shipping address-line1">
                            </div>
                            <div class="cw-field">
                                <label for="cw-addr-2"><?php esc_html_e( 'Address line 2', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-addr-2" name="addr_address_2" class="cw-input" value="<?php echo esc_attr( $addr['address_2'] ); ?>" autocomplete="shipping address-line2">
                            </div>
                            <div class="cw-settings-addr-grid">
                                <div class="cw-field">
                                    <label for="cw-addr-city"><?php esc_html_e( 'City', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-city" name="addr_city" class="cw-input" value="<?php echo esc_attr( $addr['city'] ); ?>" autocomplete="shipping address-level2">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-state"><?php esc_html_e( 'State', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-state" name="addr_state" class="cw-input" value="<?php echo esc_attr( $addr['state'] ); ?>" autocomplete="shipping address-level1">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-postcode"><?php esc_html_e( 'Postcode', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-postcode" name="addr_postcode" class="cw-input" value="<?php echo esc_attr( $addr['postcode'] ); ?>" autocomplete="shipping postal-code">
                                </div>
                                <div class="cw-field">
                                    <label for="cw-addr-country"><?php esc_html_e( 'Country', 'creativewings-core' ); ?></label>
                                    <input type="text" id="cw-addr-country" name="addr_country" class="cw-input" value="<?php echo esc_attr( $addr['country'] ?: 'MY' ); ?>" autocomplete="shipping country" maxlength="2">
                                    <small><?php esc_html_e( 'ISO country code, e.g. MY.', 'creativewings-core' ); ?></small>
                                </div>
                            </div>
                        </section>

                        <div class="cw-settings-atelier-footer">
                            <button type="submit" class="cw-btn-primary cw-settings-save-btn"><?php esc_html_e( 'Save changes', 'creativewings-core' ); ?></button>
                        </div>
                    </div>
                </div>
            </form>
        </div>

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var wrap = document.getElementById('cw-skills-wrapper');
            var inp = document.getElementById('cw-skills-input');
            var hidden = document.getElementById('cw_skills_hidden');
            if (!wrap || !inp || !hidden) return;
            var tags = hidden.value ? hidden.value.split(',').map(function(s){ return s.trim(); }).filter(Boolean) : [];

            function render() {
                wrap.querySelectorAll('.cw-tag-v2').forEach(function(e){ e.remove(); });
                tags.forEach(function(tag) {
                    var el = document.createElement('span');
                    el.className = 'cw-tag-v2';
                    el.setAttribute('data-skill', tag);
                    el.innerHTML = tag + ' <i class="fas fa-times" aria-hidden="true"></i>';
                    wrap.insertBefore(el, inp);
                });
                hidden.value = tags.join(',');
                wrap.querySelectorAll('.cw-tag-v2 i').forEach(function(icon) {
                    icon.addEventListener('click', function(e) {
                        e.stopPropagation();
                        var skill = e.target.closest('.cw-tag-v2').getAttribute('data-skill');
                        window.removeSkill(skill);
                    });
                });
            }

            window.removeSkill = function(skill) {
                tags = tags.filter(function(s){ return s !== skill; });
                render();
            };

            inp.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' || e.key === ',') {
                    e.preventDefault();
                    var v = inp.value.trim().replace(/,$/, '');
                    if (v && tags.indexOf(v) === -1) { tags.push(v); render(); inp.value = ''; }
                }
                if (e.key === 'Backspace' && inp.value === '' && tags.length > 0) { tags.pop(); render(); }
            });
            render();
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
                    <button type="button" class="cw-btn-primary cw-portfolio-add-btn" id="cw-pf-open-add"><i class="fas fa-plus" aria-hidden="true"></i> <span><?php esc_html_e( 'Add Project', 'creativewings-core' ); ?></span></button>
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

                        $del_url = wp_nonce_url( admin_url( 'admin-post.php?action=cw_delete_portfolio&pid=' . (int) $p->_ID . '&redirect_to=' . rawurlencode( $portfolio_url ) ), 'cw_del_' . (int) $p->_ID );
                        $edit_payload = [
                            '_ID'         => (int) $p->_ID,
                            'title'       => (string) $p->title,
                            'category'    => (string) $p->category,
                            'description' => wp_kses_post( (string) $p->description ),
                            'img_url'     => esc_url_raw( (string) $img_url ),
                            'gallery'     => array_values( array_map( 'esc_url_raw', $g_urls ) ),
                            'visibility'  => $visibility,
                        ];
                        $edit_json = wp_json_encode( $edit_payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
                    ?>
                    <div class="cw-project-card-v2 cw-pf-vis-<?php echo esc_attr( $visibility ); ?>" role="button" tabindex="0" data-cw-pf-view="<?php echo esc_attr( $edit_json ); ?>">
                        <div class="cw-project-image-wrap">
                            <img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $p->title ); ?>" loading="lazy" decoding="async" width="640" height="480" />
                            <?php if ( $visibility === 'private' ): ?>
                                <span class="cw-pf-private-badge" title="<?php esc_attr_e( 'Only you can see this project', 'creativewings-core' ); ?>">
                                    <i class="fas fa-lock" aria-hidden="true"></i> <?php esc_html_e( 'Private', 'creativewings-core' ); ?>
                                </span>
                            <?php endif; ?>
                            <div class="cw-project-card-overlay">
                                <button type="button" class="cw-overlay-btn cw-icon-btn" data-cw-pf-edit="<?php echo esc_attr( $edit_json ); ?>" aria-label="<?php esc_attr_e( 'Edit project', 'creativewings-core' ); ?>"><i class="fas fa-edit" aria-hidden="true"></i></button>
                                <a href="<?php echo esc_url( $del_url ); ?>" class="cw-overlay-btn cw-icon-btn" data-cw-pf-delete aria-label="<?php esc_attr_e( 'Delete project', 'creativewings-core' ); ?>" onclick="event.stopPropagation(); return confirm(<?php echo wp_json_encode( __( 'Delete this project?', 'creativewings-core' ) ); ?>);"><i class="fas fa-trash-alt" aria-hidden="true"></i></a>
                            </div>
                        </div>
                        <div class="cw-project-card-info">
                            <div class="category"><?php echo esc_html( $p->category ); ?></div>
                            <h3 class="title"><?php echo esc_html( $p->title ); ?></h3>
                            <div class="cw-pf-card-meta"><?php echo esc_html( sprintf( __( 'Added %s', 'creativewings-core' ), get_date_from_gmt( $p->cct_created, 'Y-m-d' ) ) ); ?></div>
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
        <div id="cw-pf-modal" class="cw-modal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="cw-pf-modal-title">
            <div class="cw-modal-box large">
                <div class="cw-modal-header">
                    <h3 id="cw-pf-modal-title"><?php esc_html_e( 'Add New Project', 'creativewings-core' ); ?></h3>
                    <button type="button" class="cw-close-modal cw-icon-btn" data-cw-pf-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
                </div>
                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" enctype="multipart/form-data" class="cw-modal-form-wrapper">
                    <input type="hidden" name="action" value="cw_save_portfolio">
                    <?php wp_nonce_field( 'cw_portfolio_nonce' ); ?>
                    <input type="hidden" name="pf_id" id="pf_id" value="">

                    <div class="cw-modal-body">
                        <div class="cw-modal-upload-box" id="cw-pf-cover-box">
                            <img class="cw-pf-cover-preview" id="cw-pf-cover-preview" alt="" />
                            <div class="cw-pf-cover-placeholder">
                                <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                                <p class="text-sm font-medium"><?php esc_html_e( 'Drop a cover image or click to upload', 'creativewings-core' ); ?></p>
                            </div>
                            <button type="button" class="cw-pf-cover-remove" id="cw-pf-cover-remove" aria-label="<?php esc_attr_e( 'Remove cover image', 'creativewings-core' ); ?>">&times;</button>
                            <input type="file" name="pf_image" id="pf_image" accept="image/*" class="cw-file-input-ghost">
                        </div>

                        <div class="cw-pf-field">
                            <label class="cw-pf-field-label" for="pf_title"><?php esc_html_e( 'Title', 'creativewings-core' ); ?></label>
                            <input type="text" name="pf_title" id="pf_title" required maxlength="140" autocomplete="off" class="cw-modal-input" placeholder="<?php esc_attr_e( 'Project title', 'creativewings-core' ); ?>">
                        </div>

                        <div class="cw-pf-field">
                            <label class="cw-pf-field-label" for="pf_category"><?php esc_html_e( 'Category', 'creativewings-core' ); ?></label>
                            <select name="pf_category" id="pf_category" required class="cw-modal-input">
                                <option value=""><?php esc_html_e( 'Select category', 'creativewings-core' ); ?></option>
                                <?php foreach ( $cw_pf_categories as $cw_pf_cat ) : ?>
                                    <option value="<?php echo esc_attr( $cw_pf_cat ); ?>"><?php echo esc_html( $cw_pf_cat ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cw-pf-cat-other-wrap" id="pf_category_other_wrap" style="display:none;">
                            <label class="cw-pf-field-label" for="pf_category_other"><?php esc_html_e( 'Custom category', 'creativewings-core' ); ?></label>
                            <input type="text" name="pf_category_other" id="pf_category_other" class="cw-modal-input" placeholder="<?php esc_attr_e( 'Enter your category', 'creativewings-core' ); ?>">
                        </div>

                        <div class="cw-pf-field cw-pf-field--desc">
                            <label class="cw-pf-field-label" for="pf_desc_editor"><?php esc_html_e( 'Description', 'creativewings-core' ); ?></label>
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
                            <div id="pf_desc_editor" class="cw-wysiwyg-area" contenteditable="true" role="textbox" aria-multiline="true" data-placeholder="<?php esc_attr_e('Brief description of this project…', 'creativewings-core'); ?>"></div>
                            <textarea name="pf_desc" id="pf_desc_hidden" class="cw-wys-hidden-input" hidden></textarea>
                        </div>
                        </div>

                        <div class="cw-modal-gallery-field">
                            <span class="cw-pf-field-label"><?php esc_html_e( 'Gallery', 'creativewings-core' ); ?></span>
                            <div class="cw-modal-gallery-box">
                                <i class="fas fa-images"></i>
                                <span>Add gallery images <small>(optional, multiple)</small></span>
                                <input type="file" name="pf_gallery[]" id="pf_gallery_input" multiple accept="image/*" data-max-files="8" class="cw-file-input-ghost">
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
                <button type="button" class="cw-pf-view-close cw-icon-btn" data-cw-pf-close aria-label="<?php esc_attr_e( 'Close', 'creativewings-core' ); ?>">&times;</button>
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
        (function(){
            const CW_PF_CATEGORIES = <?php echo wp_json_encode( $cw_pf_categories ); ?>;
            const I18N = {
                create: <?php echo wp_json_encode( __( 'Create Project', 'creativewings-core' ) ); ?>,
                save: <?php echo wp_json_encode( __( 'Save Changes', 'creativewings-core' ) ); ?>,
                addTitle: <?php echo wp_json_encode( __( 'Add New Project', 'creativewings-core' ) ); ?>,
                editTitle: <?php echo wp_json_encode( __( 'Edit Project', 'creativewings-core' ) ); ?>,
                enterUrl: <?php echo wp_json_encode( __( 'Enter URL', 'creativewings-core' ) ); ?>,
                removeImg: <?php echo wp_json_encode( __( 'Remove image', 'creativewings-core' ) ); ?>
            };
            let coverObjectUrl = null;
            const galleryObjectUrls = [];

            function cwPfRevokeCoverUrl(){
                if (coverObjectUrl) { try { URL.revokeObjectURL(coverObjectUrl); } catch(e){} coverObjectUrl = null; }
            }
            function cwPfRevokeGalleryUrls(){
                while (galleryObjectUrls.length) {
                    const u = galleryObjectUrls.pop();
                    try { URL.revokeObjectURL(u); } catch(e){}
                }
            }
            function cwPfSafeHttpUrl(url){
                if (!url || typeof url !== 'string') return '';
                try {
                    const u = new URL(url, window.location.origin);
                    if (u.protocol === 'http:' || u.protocol === 'https:') return u.href;
                } catch(e){}
                return '';
            }
            function cwPfParsePayload(raw){
                if (!raw) return null;
                try { return JSON.parse(raw); } catch(e){ return null; }
            }
            function cwPfShowModal(el){
                if (!el) return;
                el.style.display = 'flex';
                document.body.style.overflow = 'hidden';
                const focusable = el.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
                if (focusable) { try { focusable.focus({ preventScroll: true }); } catch(e){ focusable.focus(); } }
            }

            function cwPfResetCoverPreview(){
                const preview = document.getElementById('cw-pf-cover-preview');
                const box     = document.getElementById('cw-pf-cover-box');
                const fileEl  = document.getElementById('pf_image');
                cwPfRevokeCoverUrl();
                if (preview) { preview.removeAttribute('src'); preview.src = ''; }
                if (box) box.classList.remove('has-preview');
                if (fileEl) fileEl.value = '';
            }
            function cwPfSetCoverPreview(url){
                const preview = document.getElementById('cw-pf-cover-preview');
                const box     = document.getElementById('cw-pf-cover-box');
                if (!preview || !box) return;
                if (url) { preview.src = url; box.classList.add('has-preview'); }
                else { cwPfResetCoverPreview(); }
            }
            function cwPfClearGalleryPreviews(){
                cwPfRevokeGalleryUrls();
                const grid = document.getElementById('cw-pf-gallery-preview-grid');
                if (grid) grid.innerHTML = '';
                const fileEl = document.getElementById('pf_gallery_input');
                if (fileEl) fileEl.value = '';
            }
            function cwPfRenderGalleryPreviews(){
                const grid   = document.getElementById('cw-pf-gallery-preview-grid');
                const fileEl = document.getElementById('pf_gallery_input');
                if (!grid || !fileEl) return;
                cwPfRevokeGalleryUrls();
                grid.innerHTML = '';
                if (!fileEl.files || !fileEl.files.length) return;
                const max = parseInt(fileEl.getAttribute('data-max-files') || '8', 10) || 8;
                let files = Array.from(fileEl.files).slice(0, max);
                if (fileEl.files.length > max) {
                    const dt = new DataTransfer();
                    files.forEach(f => dt.items.add(f));
                    fileEl.files = dt.files;
                    files = Array.from(fileEl.files);
                }
                files.forEach((file, idx) => {
                    if (!file.type || file.type.indexOf('image/') !== 0) return;
                    const item = document.createElement('div');
                    item.className = 'cw-pf-gallery-preview-item';
                    const img = document.createElement('img');
                    img.alt = file.name || '';
                    img.loading = 'lazy';
                    const obj = URL.createObjectURL(file);
                    galleryObjectUrls.push(obj);
                    img.src = obj;
                    const removeBtn = document.createElement('button');
                    removeBtn.type = 'button';
                    removeBtn.className = 'cw-pf-gallery-preview-remove cw-icon-btn';
                    removeBtn.setAttribute('aria-label', I18N.removeImg);
                    removeBtn.innerHTML = '&times;';
                    removeBtn.addEventListener('click', function(){
                        const dt = new DataTransfer();
                        Array.from(fileEl.files).forEach((f, i) => { if (i !== idx) dt.items.add(f); });
                        fileEl.files = dt.files;
                        cwPfRenderGalleryPreviews();
                    });
                    item.appendChild(img);
                    item.appendChild(removeBtn);
                    grid.appendChild(item);
                });
            }

            function cwPfApplyCategory(cat){
                const sel = document.getElementById('pf_category');
                const otherInp = document.getElementById('pf_category_other');
                const otherWrap = document.getElementById('pf_category_other_wrap');
                if (!sel) return;
                cat = cat || '';
                if (cat && CW_PF_CATEGORIES.indexOf(cat) !== -1 && cat !== 'Others') {
                    sel.value = cat;
                    if (otherInp) { otherInp.value = ''; otherInp.required = false; }
                    if (otherWrap) otherWrap.style.display = 'none';
                } else if (cat) {
                    sel.value = 'Others';
                    if (otherInp) { otherInp.value = cat; otherInp.required = true; }
                    if (otherWrap) otherWrap.style.display = '';
                } else {
                    sel.value = '';
                    if (otherInp) { otherInp.value = ''; otherInp.required = false; }
                    if (otherWrap) otherWrap.style.display = 'none';
                }
            }
            function cwPfOnCategoryChange(){
                const sel = document.getElementById('pf_category');
                const otherInp = document.getElementById('pf_category_other');
                const otherWrap = document.getElementById('pf_category_other_wrap');
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
                const area = document.getElementById('pf_desc_editor');
                const hidden = document.getElementById('pf_desc_hidden');
                if (!area || !hidden) return;
                let html = area.innerHTML.trim();
                if (html === '<br>' || html === '<div><br></div>' || html === '<p><br></p>') html = '';
                hidden.value = html;
            }
            function cwPfSetVisibility(value){
                const v = (value === 'private') ? 'private' : 'public';
                document.querySelectorAll('input[name="pf_visibility"]').forEach(function(r){ r.checked = (r.value === v); });
            }

            window.openPortfolioModal = function(){
                const modal = document.getElementById('cw-pf-modal');
                document.getElementById('pf_id').value = '';
                document.getElementById('pf_title').value = '';
                cwPfApplyCategory('');
                cwPfSetDescription('');
                cwPfResetCoverPreview();
                cwPfClearGalleryPreviews();
                cwPfSetVisibility('public');
                const submit = document.getElementById('cw-pf-submit-btn');
                if (submit) submit.textContent = I18N.create;
                const header = document.getElementById('cw-pf-modal-title');
                if (header) header.textContent = I18N.addTitle;
                cwPfShowModal(modal);
            };
            window.closeModal = function(){
                const pf = document.getElementById('cw-pf-modal');
                const view = document.getElementById('cw-view-modal');
                if (pf) pf.style.display = 'none';
                if (view) view.style.display = 'none';
                document.body.style.overflow = '';
                cwPfRevokeCoverUrl();
                cwPfRevokeGalleryUrls();
            };
            window.editProject = function(d){
                if (!d) return;
                const modal = document.getElementById('cw-pf-modal');
                document.getElementById('pf_id').value = d._ID || '';
                document.getElementById('pf_title').value = d.title || '';
                cwPfApplyCategory(d.category || '');
                cwPfSetDescription(d.description || '');
                cwPfRevokeCoverUrl();
                cwPfSetCoverPreview(cwPfSafeHttpUrl(d.img_url) || '');
                cwPfClearGalleryPreviews();
                cwPfSetVisibility(d.visibility || 'public');
                const submit = document.getElementById('cw-pf-submit-btn');
                if (submit) submit.textContent = I18N.save;
                const header = document.getElementById('cw-pf-modal-title');
                if (header) header.textContent = I18N.editTitle;
                cwPfShowModal(modal);
            };
            window.viewPortfolio = function(d){
                if (!d) return;
                document.getElementById('cw-pf-view-title').textContent = d.title || '';
                document.getElementById('cw-pf-view-cat').textContent = d.category || '';
                document.getElementById('cw-pf-view-desc').innerHTML = d.description || '';

                const heroImg = document.getElementById('cw-pf-view-hero-img');
                const heroWrap = heroImg && heroImg.parentElement;
                const safeImg = cwPfSafeHttpUrl(d.img_url);
                if (safeImg && heroImg && heroWrap) {
                    heroImg.src = safeImg;
                    heroImg.alt = d.title || '';
                    heroWrap.style.display = '';
                } else if (heroWrap) {
                    heroWrap.style.display = 'none';
                }

                const galSection = document.getElementById('cw-pf-gallery-section');
                const galGrid = document.getElementById('cw-pf-gallery-grid');
                if (galGrid) galGrid.innerHTML = '';
                if (d.gallery && d.gallery.length && galGrid && galSection) {
                    d.gallery.forEach(function(url){
                        const safe = cwPfSafeHttpUrl(url);
                        if (!safe) return;
                        const img = document.createElement('img');
                        img.src = safe;
                        img.loading = 'lazy';
                        img.decoding = 'async';
                        img.alt = d.title || '';
                        img.addEventListener('click', function(){ window.open(safe, '_blank', 'noopener,noreferrer'); });
                        galGrid.appendChild(img);
                    });
                    galSection.style.display = '';
                } else if (galSection) {
                    galSection.style.display = 'none';
                }
                cwPfShowModal(document.getElementById('cw-view-modal'));
            };

            document.addEventListener('DOMContentLoaded', function(){
                const addBtn = document.getElementById('cw-pf-open-add');
                if (addBtn) addBtn.addEventListener('click', function(){ openPortfolioModal(); });

                document.querySelectorAll('[data-cw-pf-close]').forEach(function(btn){
                    btn.addEventListener('click', closeModal);
                });
                const viewClose = document.querySelector('#cw-view-modal .cw-pf-view-close');
                if (viewClose) viewClose.addEventListener('click', closeModal);

                document.addEventListener('click', function(e){
                    const editBtn = e.target.closest('[data-cw-pf-edit]');
                    if (editBtn) {
                        e.stopPropagation();
                        e.preventDefault();
                        editProject(cwPfParsePayload(editBtn.getAttribute('data-cw-pf-edit')));
                        return;
                    }
                    const card = e.target.closest('[data-cw-pf-view]');
                    if (card && !e.target.closest('.cw-project-card-overlay')) {
                        viewPortfolio(cwPfParsePayload(card.getAttribute('data-cw-pf-view')));
                    }
                });
                document.addEventListener('keydown', function(e){
                    if (e.key === 'Enter' && e.target.closest('[data-cw-pf-view]') && e.target === e.target.closest('[data-cw-pf-view]')) {
                        e.preventDefault();
                        viewPortfolio(cwPfParsePayload(e.target.getAttribute('data-cw-pf-view')));
                    }
                    if (e.key !== 'Escape') return;
                    const pf = document.getElementById('cw-pf-modal');
                    const view = document.getElementById('cw-view-modal');
                    if ((pf && pf.style.display === 'flex') || (view && view.style.display === 'flex')) closeModal();
                });

                window.addEventListener('click', function(e){
                    if (e.target && (e.target.id === 'cw-view-modal' || e.target.id === 'cw-pf-modal')) closeModal();
                });

                const coverInput = document.getElementById('pf_image');
                if (coverInput) {
                    coverInput.addEventListener('change', function(){
                        if (this.files && this.files[0] && this.files[0].type.indexOf('image/') === 0) {
                            cwPfRevokeCoverUrl();
                            coverObjectUrl = URL.createObjectURL(this.files[0]);
                            cwPfSetCoverPreview(coverObjectUrl);
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
                const galInput = document.getElementById('pf_gallery_input');
                if (galInput) galInput.addEventListener('change', cwPfRenderGalleryPreviews);
                const catSel = document.getElementById('pf_category');
                if (catSel) catSel.addEventListener('change', cwPfOnCategoryChange);

                document.querySelectorAll('#cw-pf-modal .cw-wys-btn').forEach(function(btn){
                    btn.addEventListener('mousedown', function(ev){ ev.preventDefault(); });
                    btn.addEventListener('click', function(){
                        const cmd = this.getAttribute('data-cmd');
                        const area = document.getElementById('pf_desc_editor');
                        if (area) area.focus();
                        if (cmd === 'createLink') {
                            const raw = window.prompt(I18N.enterUrl, 'https://');
                            const safe = cwPfSafeHttpUrl(raw);
                            if (safe) document.execCommand('createLink', false, safe);
                        } else {
                            document.execCommand(cmd, false, null);
                        }
                        cwPfSyncDescription();
                    });
                });
                const descArea = document.getElementById('pf_desc_editor');
                if (descArea) {
                    descArea.addEventListener('input', cwPfSyncDescription);
                    descArea.addEventListener('blur', cwPfSyncDescription);
                }
                const pfForm = document.querySelector('#cw-pf-modal form.cw-modal-form-wrapper');
                if (pfForm) {
                    pfForm.addEventListener('submit', function(){ cwPfSyncDescription(); });
                }
            });
        })();
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
            'all'          => __( 'All', 'creativewings-core' ),
            'competitions' => __( 'Competitions', 'creativewings-core' ),
            'activities'   => __( 'Activities', 'creativewings-core' ),
        ];

        // Resolve parent category + children by term ID so child cats
        // (drawing, art-activities, run, …) are included reliably.
        $tax_query = [];
        if ( 'competitions' === $current_filter || 'activities' === $current_filter ) {
            $parent = get_term_by( 'slug', $current_filter, 'product_cat' );
            if ( $parent && ! is_wp_error( $parent ) ) {
                $term_ids = array_merge(
                    [ (int) $parent->term_id ],
                    array_map( 'intval', get_term_children( (int) $parent->term_id, 'product_cat' ) )
                );
                $tax_query[] = [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'term_id',
                    'terms'            => $term_ids,
                    'include_children' => false,
                    'operator'         => 'IN',
                ];
            } else {
                // Fallback: slug match with children (legacy installs).
                $tax_query[] = [
                    'taxonomy'         => 'product_cat',
                    'field'            => 'slug',
                    'terms'            => $current_filter,
                    'include_children' => true,
                ];
            }
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
        $total_pages   = (int) $query->max_num_pages;

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

                    // Walk parent category tree (Drawing → Competitions, Run → Activities, …).
                    $main_cat = class_exists( 'CW_Shop' )
                        ? CW_Shop::main_category( $product_id )
                        : [ 'key' => '', 'label' => '' ];
                    $type_key = (string) ( $main_cat['key'] ?? '' );
                    $type_tag = (string) ( $main_cat['label'] ?? '' );
                    if ( '' === $type_tag ) {
                        $type_tag = __( 'Campaign', 'creativewings-core' );
                    }
                    $type_css = '';
                    if ( 'competition' === $type_key || false !== strpos( $type_key, 'competition' ) ) {
                        $type_css = 'competition';
                    } elseif ( 'activity' === $type_key || false !== strpos( $type_key, 'activit' ) ) {
                        $type_css = 'activity';
                    } elseif ( 'seminar' === $type_key || false !== strpos( $type_key, 'talk' ) || false !== strpos( $type_key, 'seminar' ) ) {
                        $type_css = 'seminar';
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
                    <span style="font-size:14px;font-weight:600;color:#555555;">Your entry has received <strong style="color:#f43f5e;">${voteCount}</strong> public vote${voteCount !== 1 ? 's' : ''}.</span>
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
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', 'cw_profile_nonce' ) ) {
            wp_die( 'Security Error' );
        }
        $uid = get_current_user_id();
        $fields = [
            'creator_display_name',
            'creator_tagline',
            'creator_bio',
            'awards_won',
            'creator_skills',
            'website_url',
            'linkeden_url',
            'instagram_url',
            'twitter_url',
            'Facebook_url',
            'behave_url',
            'creator_address',
            'birthdate',
        ];
        foreach ( $fields as $f ) {
            if ( ! isset( $_POST[ $f ] ) ) {
                continue;
            }
            $val = ( 'creator_bio' === $f )
                ? wp_kses_post( wp_unslash( $_POST['creator_bio'] ) )
                : trim( sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
            update_user_meta( $uid, $f, $val );
        }
        $this->handle_image_upload( $uid, 'creator_profile_image' );
        $this->handle_image_upload( $uid, 'creator_header_image' );

        if ( class_exists( 'CW_Points_Rewards' ) ) {
            CW_Points_Rewards::save_user_shipping_address(
                $uid,
                [
                    'first_name' => sanitize_text_field( wp_unslash( $_POST['addr_first_name'] ?? '' ) ),
                    'last_name'  => sanitize_text_field( wp_unslash( $_POST['addr_last_name'] ?? '' ) ),
                    'phone'      => sanitize_text_field( wp_unslash( $_POST['addr_phone'] ?? '' ) ),
                    'address_1'  => sanitize_text_field( wp_unslash( $_POST['addr_address_1'] ?? '' ) ),
                    'address_2'  => sanitize_text_field( wp_unslash( $_POST['addr_address_2'] ?? '' ) ),
                    'city'       => sanitize_text_field( wp_unslash( $_POST['addr_city'] ?? '' ) ),
                    'state'      => sanitize_text_field( wp_unslash( $_POST['addr_state'] ?? '' ) ),
                    'postcode'   => sanitize_text_field( wp_unslash( $_POST['addr_postcode'] ?? '' ) ),
                    'country'    => strtoupper( sanitize_text_field( wp_unslash( $_POST['addr_country'] ?? 'MY' ) ) ),
                ]
            );
        }

        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $target_url     = add_query_arg( [ 'tab' => 'profile', 'updated' => '1' ], $my_account_url );
        wp_safe_redirect( $target_url );
        exit;
    }
    
    /**
     * Upload a single image field, tag/optimize, return attachment payload or null.
     *
     * @param string $file_key $_FILES key.
     * @param string $opt_ctx  CW_Image_Optimizer context.
     * @return array|null
     */
    private function cw_pf_upload_image( $file_key, $opt_ctx = 'portfolio' ) {
        if ( empty( $_FILES[ $file_key ]['name'] ) ) {
            return null;
        }
        $check = wp_check_filetype_and_ext(
            $_FILES[ $file_key ]['tmp_name'],
            $_FILES[ $file_key ]['name']
        );
        if ( empty( $check['type'] ) || strpos( (string) $check['type'], 'image/' ) !== 0 ) {
            return null;
        }
        $aid = media_handle_upload( $file_key, 0 );
        if ( is_wp_error( $aid ) ) {
            return null;
        }
        if ( class_exists( 'CW' ) ) {
            CW::tag_plugin_media( (int) $aid );
        }
        if ( class_exists( 'CW_Image_Optimizer' ) ) {
            CW_Image_Optimizer::optimize_attachment( (int) $aid, $opt_ctx );
        }
        $url = wp_get_attachment_url( (int) $aid );
        return $url ? [ 'id' => (int) $aid, 'url' => $url ] : null;
    }

    public function handle_save_portfolio() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
        }
        if ( empty( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), 'cw_portfolio_nonce' ) ) {
            wp_die( esc_html__( 'Security check failed', 'creativewings-core' ), 403 );
        }

        $uid = get_current_user_id();
        $user = get_userdata( $uid );
        $allowed = $user && (
            user_can( $user, 'manage_options' )
            || ( class_exists( 'CW_Roles' ) && CW_Roles::is_creator_user( $user ) )
            || in_array( 'creator_role', (array) $user->roles, true )
        );
        if ( ! $allowed ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
        }

        global $wpdb;
        $table = $this->get_table_name();

        $title = sanitize_text_field( wp_unslash( $_POST['pf_title'] ?? '' ) );
        $title = function_exists( 'mb_substr' ) ? mb_substr( $title, 0, 140 ) : substr( $title, 0, 140 );
        if ( $title === '' ) {
            wp_die( esc_html__( 'Title is required', 'creativewings-core' ), 400 );
        }

        $pf_category_raw = sanitize_text_field( wp_unslash( $_POST['pf_category'] ?? '' ) );
        if ( $pf_category_raw === 'Others' ) {
            $other = sanitize_text_field( wp_unslash( $_POST['pf_category_other'] ?? '' ) );
            if ( $other !== '' ) {
                $pf_category_raw = function_exists( 'mb_substr' ) ? mb_substr( $other, 0, 80 ) : substr( $other, 0, 80 );
            }
        }
        $pf_category_raw = function_exists( 'mb_substr' ) ? mb_substr( $pf_category_raw, 0, 80 ) : substr( $pf_category_raw, 0, 80 );

        $pf_visibility_raw = isset( $_POST['pf_visibility'] ) ? sanitize_text_field( wp_unslash( $_POST['pf_visibility'] ) ) : 'public';
        $pf_visibility     = ( $pf_visibility_raw === 'private' ) ? 'private' : 'public';

        $desc = isset( $_POST['pf_desc'] ) ? wp_kses_post( wp_unslash( $_POST['pf_desc'] ) ) : '';

        $data = [
            'title'         => $title,
            'category'      => $pf_category_raw,
            'description'   => $desc,
            'visibility'    => $pf_visibility,
            'cct_modified'  => current_time( 'mysql' ),
            'cct_author_id' => $uid,
            'created_by'    => $uid,
            'cct_status'    => 'publish',
        ];

        require_once ABSPATH . 'wp-admin/includes/image.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';

        $cover = $this->cw_pf_upload_image( 'pf_image', 'portfolio' );
        if ( $cover ) {
            $data['image'] = serialize( $cover );
        }

        $max_gallery = 8;
        if ( ! empty( $_FILES['pf_gallery']['name'][0] ) ) {
            $gals  = [];
            $files = $_FILES['pf_gallery'];
            $count = 0;
            foreach ( $files['name'] as $k => $v ) {
                if ( empty( $files['name'][ $k ] ) ) {
                    continue;
                }
                if ( $count >= $max_gallery ) {
                    break;
                }
                $_FILES['s_file'] = [
                    'name'     => $files['name'][ $k ],
                    'type'     => $files['type'][ $k ],
                    'tmp_name' => $files['tmp_name'][ $k ],
                    'error'    => $files['error'][ $k ],
                    'size'     => $files['size'][ $k ],
                ];
                $item = $this->cw_pf_upload_image( 's_file', 'gallery' );
                if ( $item ) {
                    $gals[] = $item;
                    $count++;
                }
            }
            if ( $gals ) {
                $data['gallery'] = serialize( $gals );
            }
        }

        $pf_id = isset( $_POST['pf_id'] ) ? absint( $_POST['pf_id'] ) : 0;
        if ( $pf_id > 0 ) {
            $owner = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT created_by FROM {$table} WHERE _ID = %d LIMIT 1",
                $pf_id
            ) );
            if ( $owner !== $uid && ! user_can( $user, 'manage_options' ) ) {
                wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
            }
            $wpdb->update( $table, $data, [ '_ID' => $pf_id ] );
        } else {
            $data['cct_created'] = current_time( 'mysql' );
            $wpdb->insert( $table, $data );
        }

        $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        wp_safe_redirect( add_query_arg( 'tab', 'portfolio', $my_account_url ) );
        exit;
    }

    public function handle_delete_portfolio() {
        if ( ! is_user_logged_in() ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
        }
        $pid = isset( $_GET['pid'] ) ? absint( $_GET['pid'] ) : 0;
        if ( ! $pid || empty( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'cw_del_' . $pid ) ) {
            wp_die( esc_html__( 'Security Error', 'creativewings-core' ), 403 );
        }

        $uid  = get_current_user_id();
        $user = get_userdata( $uid );
        global $wpdb;
        $table = $this->get_table_name();

        $owner = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT created_by FROM {$table} WHERE _ID = %d LIMIT 1",
            $pid
        ) );
        if ( ! $owner || ( $owner !== $uid && ! user_can( $user, 'manage_options' ) ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
        }

        if ( user_can( $user, 'manage_options' ) ) {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE _ID = %d", $pid ) );
        } else {
            $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE _ID = %d AND created_by = %d", $pid, $uid ) );
        }

        $redirect_to = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : '';
        if ( $redirect_to ) {
            wp_safe_redirect( $redirect_to );
        } else {
            $my_account_url = get_permalink( wc_get_page_id( 'myaccount' ) );
            wp_safe_redirect( add_query_arg( 'tab', 'portfolio', $my_account_url ) );
        }
        exit;
    }

    /* ==========================================================================
       SHARED: Pagination renderer
       ========================================================================== */
    private function render_pagination( $current_page, $total_pages, $base_url, $page_arg = 'cw_page' ) {
        if ( class_exists( 'CW_Pagination' ) ) {
            CW_Pagination::render( [
                'current'  => (int) $current_page,
                'total'    => (int) $total_pages,
                'base_url' => $base_url,
                'page_arg' => $page_arg,
            ] );
        }
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