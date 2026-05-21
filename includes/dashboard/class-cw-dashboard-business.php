<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Dashboard_Business {

    public function __construct() {
        // Tab Content Injection
        add_action( 'woocommerce_account_cw-biz-campaigns_endpoint', [ $this, 'render_campaigns' ] );
        add_action( 'woocommerce_account_cw-biz-wallet_endpoint', [ $this, 'render_wallet' ] );
        add_action( 'woocommerce_account_cw-biz-info_endpoint', [ $this, 'render_settings' ] );

        // Overview chart range AJAX refresh.
        add_action( 'wp_ajax_cw_biz_chart_series', [ $this, 'ajax_chart_series' ] );

        // Note: The save handler 'admin_post_cw_save_biz_info' is located in CW_Business class.
    }

    /**
     * AJAX: return revenue + participants time series for the current business user.
     * Used by the overview chart range selector.
     */
    public function ajax_chart_series() {
        check_ajax_referer( 'cw_biz_chart_series', 'nonce' );

        $uid = get_current_user_id();
        if ( ! $uid || ! ( class_exists( 'CW_Roles' ) ? CW_Roles::is_business_user( $uid ) : current_user_can( 'edit_posts' ) ) ) {
            wp_send_json_error( [ 'message' => __( 'Not authorized.', 'creativewings-core' ) ], 403 );
        }

        if ( ! class_exists( 'CW_Business_Reports' ) ) {
            wp_send_json_error( [ 'message' => __( 'Reports service unavailable.', 'creativewings-core' ) ], 500 );
        }

        $range_raw = isset( $_POST['range'] ) ? sanitize_text_field( wp_unslash( $_POST['range'] ) ) : '30';
        $range     = ( $range_raw === 'all' ) ? 'all' : (string) max( 1, (int) $range_raw );

        $series = CW_Business_Reports::get_chart_series( $uid, $range );
        wp_send_json_success( $series );
    }

    /* ==========================================================================
       1. OVERVIEW TAB (TechNova Design)
       ========================================================================== */
    public function render_overview() {
        $uid = get_current_user_id();
        $u   = get_userdata($uid);
        $biz_name = get_user_meta( $uid, 'business_name', true ) ?: $u->display_name;
        
        // 1. Get Wallet Stats
        $wallet = ['total_earned' => 0, 'pending' => 0, 'available' => 0];
        if ( class_exists( 'CW_Wallet' ) ) {
            $wallet = CW_Wallet::get_wallet_stats( $uid );
        }

        // 2. Get Campaign Stats — fetch IDs + status only (lighter than full posts).
        $campaign_query_args = class_exists( 'CW_Roles' )
            ? CW_Roles::get_business_campaign_query_args( $uid )
            : [
                'post_type'      => 'product',
                'author'         => $uid,
                'post_status'    => [ 'publish', 'pending', 'draft' ],
                'posts_per_page' => -1,
            ];
        $campaign_query_args['posts_per_page'] = -1;
        $campaign_query_args['fields']         = 'ids';
        $campaign_ids = (array) get_posts( $campaign_query_args );

        $total_active  = 0;
        $total_pending = 0;
        $total_entries = 0;

        if ( ! empty( $campaign_ids ) ) {
            global $wpdb;
            $placeholders = implode( ',', array_fill( 0, count( $campaign_ids ), '%d' ) );
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT post_status, COUNT(*) AS c FROM {$wpdb->posts}
                 WHERE ID IN ($placeholders) GROUP BY post_status",
                $campaign_ids
            ), ARRAY_A );
            foreach ( (array) $rows as $r ) {
                if ( $r['post_status'] === 'publish' ) {
                    $total_active = (int) $r['c'];
                } else {
                    $total_pending += (int) $r['c'];
                }
            }
        }
        $campaigns = $campaign_ids; // back-compat for any legacy reference below

        // Total entries across all campaigns (single query).
        if ( ! empty( $campaign_ids ) ) {
            global $wpdb;
            $placeholders = implode(',', array_fill(0, count($campaign_ids), '%d'));
            $total_entries = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'product_id' AND meta_value IN ($placeholders)",
                $campaign_ids
            ) );
        }

        // Base URL for links
        $base = get_permalink( wc_get_page_id( 'myaccount' ) );

        // Public organiser profile — campaigns, not portfolio (/organizer/{login}/).
        $public_profile_url = class_exists( 'CW_Roles' )
            ? CW_Roles::get_public_organizer_url( $u )
            : '';

        // Directory completeness — show a nudge banner if missing any basic field.
        $missing_dir_fields = [];
        if ( class_exists( 'CW_Roles' ) ) {
            $check = CW_Roles::organizer_missing_basics( $u );
            if ( is_array( $check ) ) {
                $missing_dir_fields = $check;
            }
        }
        $missing_dir_labels = [
            'business_name'     => __( 'Business name', 'creativewings-core' ),
            'business_logo'     => __( 'Logo', 'creativewings-core' ),
            'business_industry' => __( 'Industry', 'creativewings-core' ),
            'business_about'    => __( 'About / description', 'creativewings-core' ),
            'business_location' => __( 'City or country', 'creativewings-core' ),
        ];
        $biz_info_url = add_query_arg( 'tab', 'biz-info', $base );

        ?>
        <style>
            .woocommerce-MyAccount-content > p:first-child,
            .woocommerce-MyAccount-content > p:nth-of-type(2) { display: none !important; }
        </style>

        <div class="cw-dashboard-container">

            <!-- HEADER -->
            <div class="cw-dash-header">
                <div>
                    <h1 style="margin:0 0 4px;">Hello, <?php echo esc_html($biz_name); ?> 👋</h1>
                    <p>Manage your campaigns, track earnings, and update your profile.</p>
                </div>
                <div class="cw-dash-header-actions">
                    <?php if ( $public_profile_url ) : ?>
                        <a href="<?php echo esc_url( $public_profile_url ); ?>"
                           class="cw-btn-outline-blue"
                           target="_blank" rel="noopener"
                           title="Open your public organiser profile in a new tab">
                            <i class="fas fa-user-circle"></i> View Public Profile
                        </a>
                    <?php endif; ?>
                    <a href="<?php echo esc_url( add_query_arg('tab', 'campaigns', $base) ); ?>" class="cw-btn-primary" style="text-decoration:none;">
                        <i class="fas fa-plus"></i> Create Campaign
                    </a>
                </div>
            </div>

            <?php if ( ! empty( $missing_dir_fields ) ) : ?>
                <div class="cw-dir-nudge" role="status">
                    <div class="cw-dir-nudge-icon"><i class="fas fa-eye-slash"></i></div>
                    <div class="cw-dir-nudge-body">
                        <h3><?php esc_html_e( 'Complete your business info to appear in the public directory', 'creativewings-core' ); ?></h3>
                        <p>
                            <?php esc_html_e( 'You won\'t be listed on the organizers directory until these basics are filled in:', 'creativewings-core' ); ?>
                        </p>
                        <div class="cw-dir-nudge-chips">
                            <?php foreach ( $missing_dir_fields as $field ) :
                                $label = $missing_dir_labels[ $field ] ?? ucwords( str_replace( [ 'business_', '_' ], [ '', ' ' ], $field ) );
                                ?>
                                <span class="cw-dir-nudge-chip"><i class="fas fa-times-circle"></i> <?php echo esc_html( $label ); ?></span>
                            <?php endforeach; ?>
                        </div>
                        <a href="<?php echo esc_url( $biz_info_url ); ?>" class="cw-btn-primary cw-dir-nudge-cta">
                            <i class="fas fa-pen-to-square"></i> <?php esc_html_e( 'Complete business info', 'creativewings-core' ); ?>
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
            if ( class_exists( 'CW_Badges_Engine' ) && class_exists( 'CW_Badges_Display' ) ) {
                $owned_badges = CW_Badges_Engine::get_user_badges( get_current_user_id() );
                if ( ! empty( $owned_badges ) ) :
                    $badges_tab_url = add_query_arg( 'tab', 'badges', get_permalink( wc_get_page_id( 'myaccount' ) ) );
                    ?>
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

            <!-- STATS GRID (4 cols) -->
            <div class="cw-stats-grid cols-4">
                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Active Campaigns</span>
                        <h3 class="cw-stat-value"><?php echo intval($total_active); ?></h3>
                    </div>
                    <div class="cw-stat-icon-wrapper green"><i class="fas fa-bullhorn"></i></div>
                </div>

                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Pending / Draft</span>
                        <h3 class="cw-stat-value"><?php echo intval($total_pending); ?></h3>
                    </div>
                    <div class="cw-stat-icon-wrapper yellow"><i class="fas fa-clock"></i></div>
                </div>

                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Total Entries</span>
                        <h3 class="cw-stat-value"><?php echo intval($total_entries); ?></h3>
                    </div>
                    <div class="cw-stat-icon-wrapper blue"><i class="fas fa-users"></i></div>
                </div>

                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Total Revenue</span>
                        <h3 class="cw-stat-value cw-stat-money"><?php echo wc_price( $wallet['total_earned'] ); ?></h3>
                    </div>
                    <div class="cw-stat-icon-wrapper coral"><i class="fas fa-wallet"></i></div>
                </div>
            </div>

            <!-- SPLIT SECTION (Chart + Actions) -->
            <div class="cw-split-section">

                <!-- LEFT: REVENUE + PARTICIPANTS CHART -->
                <?php
                $default_range  = '30';
                $initial_series = class_exists( 'CW_Business_Reports' )
                    ? CW_Business_Reports::get_chart_series( $uid, $default_range )
                    : [ 'labels' => [], 'revenue' => [], 'participants' => [], 'range' => $default_range ];

                $currency_symbol = function_exists( 'get_woocommerce_currency_symbol' ) ? get_woocommerce_currency_symbol() : '$';
                $chart_nonce     = wp_create_nonce( 'cw_biz_chart_series' );
                ?>
                <div class="cw-chart-container">
                    <div class="cw-chart-header">
                        <h3>Revenue &amp; Participants</h3>
                        <select class="cw-chart-filter" id="cw-revenue-range" aria-label="Date range">
                            <option value="1">Today</option>
                            <option value="3">Last 3 days</option>
                            <option value="7">Last 7 days</option>
                            <option value="14">Last 14 days</option>
                            <option value="30" selected>Last 30 days</option>
                            <option value="180">Last 6 months</option>
                            <option value="365">Last 1 year</option>
                            <option value="all">All time</option>
                        </select>
                    </div>
                    <div class="cw-chart-wrapper">
                        <canvas id="revenueChart"></canvas>
                    </div>
                    <div class="cw-chart-legend-note" aria-hidden="true">
                        <span class="cw-chart-legend-dot cw-chart-legend-dot--rev"></span> <?php esc_html_e( 'Revenue', 'creativewings-core' ); ?>
                        <span class="cw-chart-legend-dot cw-chart-legend-dot--part"></span> <?php esc_html_e( 'Participants', 'creativewings-core' ); ?>
                    </div>
                </div>

                <!-- RIGHT: QUICK ACTIONS -->
                <div class="cw-actions-container">
                    <h3>Quick Actions</h3>
                    <div class="cw-actions-list">
                        
                        <a href="<?php echo add_query_arg('tab', 'campaigns', $base); ?>" class="cw-action-btn hover-red">
                            <div class="cw-action-content">
                                <div class="cw-action-icon red"><i class="fas fa-plus"></i></div>
                                <div>
                                    <strong>Create Campaign</strong>
                                    <span>Launch a new campaign</span>
                                </div>
                            </div>
                            <i class="fas fa-arrow-right"></i>
                        </a>

                        <a href="<?php echo add_query_arg('tab', 'reports', $base); ?>" class="cw-action-btn hover-blue">
                            <div class="cw-action-content">
                                <div class="cw-action-icon blue"><i class="fas fa-chart-bar"></i></div>
                                <div>
                                    <strong>Reports</strong>
                                    <span>Analytics, exports &amp; insights</span>
                                </div>
                            </div>
                            <i class="fas fa-arrow-right"></i>
                        </a>

                        <a href="<?php echo add_query_arg('tab', 'wallet', $base); ?>" class="cw-action-btn hover-blue">
                            <div class="cw-action-content">
                                <div class="cw-action-icon blue"><i class="fas fa-wallet"></i></div>
                                <div>
                                    <strong>Wallet</strong>
                                    <span>Check balance &amp; payouts</span>
                                </div>
                            </div>
                            <i class="fas fa-arrow-right"></i>
                        </a>

                        <a href="<?php echo add_query_arg('tab', 'biz-info', $base); ?>" class="cw-action-btn hover-dark">
                            <div class="cw-action-content">
                                <div class="cw-action-icon dark"><i class="fas fa-user"></i></div>
                                <div>
                                    <strong>Profile</strong>
                                    <span>Company settings</span>
                                </div>
                            </div>
                            <i class="fas fa-arrow-right"></i>
                        </a>

                        <?php if ( $public_profile_url ) : ?>
                        <a href="<?php echo esc_url( $public_profile_url ); ?>" class="cw-action-btn hover-blue" target="_blank" rel="noopener">
                            <div class="cw-action-content">
                                <div class="cw-action-icon blue"><i class="fas fa-globe"></i></div>
                                <div>
                                    <strong>Public Profile</strong>
                                    <span>View how the public sees you</span>
                                </div>
                            </div>
                            <i class="fas fa-external-link-alt"></i>
                        </a>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

        </div>

        <!-- CHART JS CONFIG -->
        <script>
        (function(){
            var CHART_V4_URL = <?php echo wp_json_encode( CW_URL . 'assets/vendor/chart.js/chart.umd.min.js?v=' . CW_VERSION ); ?>;

            // Detect a stale Chart.js v2 (its global has no `.version`); if found,
            // wipe it and load our v4 build before booting the dashboard chart.
            function ensureChartV4(callback) {
                var hasChart = typeof window.Chart !== 'undefined';
                var v = hasChart && window.Chart.version ? String(window.Chart.version) : '';
                if (hasChart && v && v.charAt(0) >= '3') {
                    callback();
                    return;
                }
                if (hasChart) {
                    try { delete window.Chart; } catch(e) { window.Chart = undefined; }
                }
                var s = document.createElement('script');
                s.src = CHART_V4_URL;
                s.async = false;
                s.onload  = function(){ callback(); };
                s.onerror = function(){ console.warn('[CW] Failed to load Chart.js v4 from', CHART_V4_URL); };
                document.head.appendChild(s);
            }

            function bootChart(){
                var ctx = document.getElementById('revenueChart');
                if (!ctx || typeof Chart === 'undefined') return;
                runChart(ctx);
            }

            document.addEventListener('DOMContentLoaded', function(){ ensureChartV4(bootChart); });

            function runChart(ctx) {
            const currencySymbol = <?php echo wp_json_encode( $currency_symbol ); ?>;
            const ajaxUrl        = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
            const ajaxNonce      = <?php echo wp_json_encode( $chart_nonce ); ?>;
            const initialSeries  = <?php echo wp_json_encode( $initial_series ); ?>;

            const ctx2d = ctx.getContext('2d');
            const revGradient = ctx2d.createLinearGradient(0, 0, 0, 400);
            revGradient.addColorStop(0, 'rgba(15, 103, 150, 0.22)');
            revGradient.addColorStop(1, 'rgba(15, 103, 150, 0)');

            // Pretty-print labels (YYYY-MM-DD or YYYY-MM) for the x-axis.
            function prettyLabels(raw) {
                const monthShort = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
                return (raw || []).map(function(l) {
                    if (typeof l !== 'string') return l;
                    if (/^\d{4}-\d{2}-\d{2}$/.test(l)) {
                        const parts = l.split('-');
                        return parts[2] + ' ' + monthShort[parseInt(parts[1], 10) - 1];
                    }
                    if (/^\d{4}-\d{2}$/.test(l)) {
                        const parts = l.split('-');
                        return monthShort[parseInt(parts[1], 10) - 1] + ' ' + parts[0].slice(2);
                    }
                    return l;
                });
            }

            const chart = new Chart(ctx, {
                data: {
                    labels: prettyLabels(initialSeries.labels || []),
                    datasets: [
                        {
                            type: 'line',
                            label: 'Revenue',
                            data: initialSeries.revenue || [],
                            borderColor: '#0F6796',
                            borderWidth: 3,
                            backgroundColor: revGradient,
                            fill: true,
                            tension: 0.35,
                            pointRadius: 3,
                            pointHoverRadius: 6,
                            yAxisID: 'yRev',
                            order: 1
                        },
                        {
                            type: 'bar',
                            label: 'Participants',
                            data: initialSeries.participants || [],
                            backgroundColor: 'rgba(254, 98, 97, 0.55)',
                            borderColor: '#FE6261',
                            borderWidth: 1,
                            borderRadius: 4,
                            maxBarThickness: 22,
                            yAxisID: 'yPart',
                            order: 2
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    interaction: { mode: 'index', intersect: false },
                    plugins: {
                        legend: { display: false },
                        tooltip: {
                            backgroundColor: '#fff',
                            titleColor: '#0f172a',
                            bodyColor: '#0F6796',
                            borderColor: '#e2e8f0',
                            borderWidth: 1,
                            padding: 10,
                            callbacks: {
                                label: function (item) {
                                    const v = item.parsed.y;
                                    if (item.dataset.label === 'Revenue') {
                                        return '  Revenue: ' + currencySymbol + Number(v).toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
                                    }
                                    return '  Participants: ' + Number(v).toLocaleString();
                                }
                            }
                        }
                    },
                    scales: {
                        yRev: {
                            position: 'left',
                            beginAtZero: true,
                            grid: { borderDash: [5, 5], color: '#e2e8f0' },
                            ticks: {
                                color: '#0F6796',
                                callback: function (v) {
                                    if (Math.abs(v) >= 1000) return currencySymbol + (v / 1000).toFixed(1) + 'k';
                                    return currencySymbol + v;
                                }
                            },
                            title: { display: true, text: 'Revenue', color: '#0F6796', font: { size: 11, weight: '700' } }
                        },
                        yPart: {
                            position: 'right',
                            beginAtZero: true,
                            grid: { display: false },
                            ticks: { color: '#FE6261', precision: 0 },
                            title: { display: true, text: 'Participants', color: '#FE6261', font: { size: 11, weight: '700' } }
                        },
                        x: {
                            grid: { display: false },
                            ticks: { color: '#64748b', maxRotation: 0, autoSkip: true, autoSkipPadding: 12 }
                        }
                    }
                }
            });

            function applySeries(series) {
                chart.data.labels                = prettyLabels(series.labels || []);
                chart.data.datasets[0].data      = series.revenue || [];
                chart.data.datasets[1].data      = series.participants || [];
                chart.update();
            }

            const rangeEl = document.getElementById('cw-revenue-range');
            if (rangeEl) {
                rangeEl.addEventListener('change', function () {
                    const range = this.value;
                    const form  = new FormData();
                    form.append('action', 'cw_biz_chart_series');
                    form.append('nonce', ajaxNonce);
                    form.append('range', range);

                    if (window.fetch) {
                        fetch(ajaxUrl, { method: 'POST', body: form, credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (j) {
                                if (j && j.success && j.data) applySeries(j.data);
                            })
                            .catch(function () {});
                    }
                });
            }
            } // close runChart
        })();
        </script>
        <style>
            .cw-chart-legend-note {
                margin-top: 6px;
                font-size: 12px;
                color: #64748b;
                display: flex;
                align-items: center;
                gap: 6px;
                flex-wrap: wrap;
            }
            .cw-chart-legend-note .cw-chart-legend-dot {
                display: inline-block;
                width: 10px; height: 10px; border-radius: 50%;
                margin-right: 4px;
            }
            .cw-chart-legend-note .cw-chart-legend-dot--rev  { background: #0F6796; }
            .cw-chart-legend-note .cw-chart-legend-dot--part { background: #FE6261; }
            .cw-chart-legend-note .cw-chart-legend-dot:not(:first-child) { margin-left: 14px; }
        </style>
        <?php
    }

    /* ==========================================================================
       2. CAMPAIGNS TAB (IMPLEMENTING NEW UI)
       ========================================================================== */
   public function render_campaigns() {
        $uid = get_current_user_id();

        // --- FIX: Define base URLs using the safe ?tab=slug structure ---
        $my_account_page_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $base_campaigns_url = add_query_arg('tab', 'campaigns', $my_account_page_url); // For Edit links
        $manage_entries_url = add_query_arg('tab', 'manage_entries', $my_account_page_url); // For Manage Entries link

        // --- Read & sanitize filter / pagination inputs from URL ---
        $cw_q       = isset($_GET['cw_q']) ? sanitize_text_field( wp_unslash( $_GET['cw_q'] ) ) : '';
        $allowed_statuses = ['any', 'publish', 'pending', 'draft'];
        $cw_status  = isset($_GET['cw_status']) && in_array($_GET['cw_status'], $allowed_statuses, true)
            ? $_GET['cw_status']
            : 'any';
        $cw_cpage   = isset($_GET['cw_cpage']) ? max(1, intval($_GET['cw_cpage'])) : 1;

        // --- Build query args: role-aware base, then merge our filters on top ---
        $base_args = class_exists('CW_Roles')
            ? CW_Roles::get_business_campaign_query_args($uid)
            : [
                'post_type'   => 'product',
                'author'      => $uid,
                'post_status' => ['publish', 'pending', 'draft'],
            ];

        $args = array_merge($base_args, [
            'posts_per_page' => 6,
            'paged'          => $cw_cpage,
        ]);
        if ($cw_q !== '')          { $args['s']           = $cw_q; }
        if ($cw_status !== 'any')  { $args['post_status'] = $cw_status; }

        $query        = new WP_Query($args);
        $found_posts  = (int) $query->found_posts;

        // Prime caches once for the current page's campaigns so the card grid
        // foreach (lines ~634-688) avoids N+1 meta + earnings + count queries.
        $cw_card_pids       = array_map( static fn( $p ) => (int) $p->ID, (array) $query->posts );
        $cw_earnings_map    = ( ! empty( $cw_card_pids ) && class_exists( 'CW_Wallet' ) )
            ? CW_Wallet::get_product_earnings_map( $cw_card_pids )
            : [];
        $cw_entries_map     = ( ! empty( $cw_card_pids ) && class_exists( 'CW_Wallet' ) )
            ? CW_Wallet::get_product_entries_count_map( $cw_card_pids )
            : [];
        if ( ! empty( $cw_card_pids ) ) {
            update_meta_cache( 'post', $cw_card_pids );
            update_object_term_cache( $cw_card_pids, 'product' );
        }
        $total_pages  = (int) $query->max_num_pages;
        $is_filtered  = ($cw_q !== '' || $cw_status !== 'any');

        // Reset URL (clears all filter/pagination args, keeps the tab)
        $reset_url = add_query_arg('tab', 'campaigns', $my_account_page_url);

        $is_edit_mode = isset($_GET['edit_id']);
        $edit_id = $is_edit_mode ? intval($_GET['edit_id']) : 0;
        
        // Note: Success messages are handled by Transients/SweetAlert2 now
        if ( isset($_GET['saved']) ) echo '<div class="cw-alert success">Campaign saved successfully!</div>';
        if ( isset($_GET['campaign_created']) ) echo '<div class="cw-alert success">New Campaign created successfully!</div>';

        ?>
        <div class="cw-content-wrapper">
            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">My Campaigns</h2>
                    <p>Manage and track all your campaigns</p>
                </div>
                <button type="button" class="cw-btn-primary" id="cw-open-create" style="text-decoration:none;">
                    <i class="fas fa-plus"></i> Create New Campaign
                </button>
            </div>

            <!-- Filter / search bar -->
            <form method="GET" class="cw-camp-filterbar" role="search" aria-label="Filter campaigns">
                <input type="hidden" name="tab" value="campaigns">
                <input type="text"
                       name="cw_q"
                       value="<?php echo esc_attr($cw_q); ?>"
                       placeholder="Search campaign name…"
                       aria-label="Search campaign name">
                <select name="cw_status" aria-label="Filter by status">
                    <option value="any"     <?php selected($cw_status, 'any'); ?>>All statuses</option>
                    <option value="publish" <?php selected($cw_status, 'publish'); ?>>Published</option>
                    <option value="pending" <?php selected($cw_status, 'pending'); ?>>Pending</option>
                    <option value="draft"   <?php selected($cw_status, 'draft'); ?>>Draft</option>
                </select>
                <button type="submit" class="cw-btn-primary small">
                    <i class="fas fa-filter"></i> Filter
                </button>
                <a href="<?php echo esc_url($reset_url); ?>" class="cw-btn-outline-blue small">
                    <i class="fas fa-times"></i> Reset
                </a>
            </form>

            <?php if ( $found_posts > 0 ):
                $range_from = (($cw_cpage - 1) * 6) + 1;
                $range_to   = min($cw_cpage * 6, $found_posts);
            ?>
                <p class="cw-result-count">
                    Showing <strong><?php echo intval($range_from); ?>–<?php echo intval($range_to); ?></strong>
                    of <strong><?php echo intval($found_posts); ?></strong>
                    <?php echo ($found_posts === 1) ? 'campaign' : 'campaigns'; ?>
                    <?php if ($is_filtered) echo '<span aria-hidden="true"> · filtered</span>'; ?>
                </p>

                <div class="cw-portfolio-grid-modern">
                    <?php foreach ( $query->posts as $c ):
                        $pid = $c->ID;
                        $status = get_post_status( $pid );
                        $status_label = ucfirst($status);

                        $pill_class = 'bg-gray';
                        if ($status == 'publish') $pill_class = 'bg-green';
                        if ($status == 'pending') $pill_class = 'bg-yellow';

                        $img = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';
                        $earnings = (float) ( $cw_earnings_map[ $pid ] ?? 0 );
                        $entries_count = (int) ( $cw_entries_map[ $pid ] ?? 0 );

                        $is_competition = has_term('competitions', 'product_cat', $pid);
                        $cat_label      = $is_competition ? 'Competition' : 'Activity';
                        $event_mode     = get_post_meta($pid, 'cw_event_mode', true) ?: 'physical';

                        $deadline = get_post_meta($pid, 'submission_deadline', true);
                        $is_locked = ($deadline && strtotime($deadline) < time());
                        $edit_url_safe  = add_query_arg('edit_id', $pid, $base_campaigns_url);
                        $entries_link   = add_query_arg('campaign_id', $pid, $manage_entries_url);

                        $edit_button_html = $is_locked
                            ? '<button class="cw-btn-disabled full-width" disabled><i class="fas fa-lock"></i> Locked</button>'
                            : '<a href="' . esc_url($edit_url_safe) . '" class="cw-btn-outline-blue full-width" style="flex:0.5; min-width:80px;"><i class="fas fa-edit"></i> Edit</a>';
                    ?>
                        <div class="cw-modern-card">
                            <div class="cw-card-image" style="background-image:url('<?php echo esc_url($img); ?>');">
                                <div class="cw-card-badges">
                                    <span class="cw-status-pill <?php echo $pill_class; ?>"><?php echo esc_html($status_label); ?></span>
                                </div>
                            </div>
                            <div class="cw-card-content">
                                <div class="cw-card-tags">
                                    <span class="cw-tag blue"><?php echo esc_html($cat_label); ?></span>
                                    <span class="cw-tag gray"><?php echo ucfirst($event_mode); ?></span>
                                </div>
                                <h4><?php echo esc_html(get_the_title($pid)); ?></h4>
                                <?php if ($status === 'pending'): ?>
                                <small class="cw-pending-note">Awaiting admin approval</small>
                                <?php endif; ?>
                                <div class="cw-card-stats">
                                    <div class="cw-stat-item"><i class="fas fa-users"></i> <?php echo number_format($entries_count); ?> Joined</div>
                                    <div class="cw-stat-item"><i class="fas fa-wallet"></i> RM <?php echo number_format($earnings, 0); ?></div>
                                </div>
                                <div class="cw-card-actions">
                                    <a href="<?php echo esc_url($entries_link); ?>" class="cw-btn-primary full-width" style="flex:1;">
                                        <i class="fas fa-user-check"></i> Entries (<?php echo $entries_count; ?>)
                                    </a>
                                    <a href="<?php echo esc_url(get_permalink($pid)); ?>" target="_blank" class="cw-btn-white full-width" style="flex:0.5; min-width:72px;"><i class="fas fa-eye"></i> View</a>
                                    <?php echo $edit_button_html; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <?php if ( $total_pages > 1 ):
                    $prev_page = max(1, $cw_cpage - 1);
                    $next_page = min($total_pages, $cw_cpage + 1);
                    $on_first  = ($cw_cpage <= 1);
                    $on_last   = ($cw_cpage >= $total_pages);

                    $build_page_url = function ($n) use ($cw_q, $cw_status, $my_account_page_url) {
                        return add_query_arg([
                            'tab'       => 'campaigns',
                            'cw_q'      => $cw_q,
                            'cw_status' => $cw_status,
                            'cw_cpage'  => $n,
                        ], $my_account_page_url);
                    };
                ?>
                <nav class="cw-pagination" role="navigation" aria-label="Campaigns pagination">
                    <?php if ($on_first): ?>
                        <span class="cw-page-btn prev disabled" aria-disabled="true">‹ Prev</span>
                    <?php else: ?>
                        <a class="cw-page-btn prev"
                           href="<?php echo esc_url($build_page_url($prev_page)); ?>"
                           aria-label="Previous page">‹ Prev</a>
                    <?php endif; ?>

                    <span class="cw-page-info">
                        Page <?php echo intval($cw_cpage); ?> of <?php echo intval($total_pages); ?>
                    </span>

                    <?php if ($on_last): ?>
                        <span class="cw-page-btn next disabled" aria-disabled="true">Next ›</span>
                    <?php else: ?>
                        <a class="cw-page-btn next"
                           href="<?php echo esc_url($build_page_url($next_page)); ?>"
                           aria-label="Next page">Next ›</a>
                    <?php endif; ?>
                </nav>
                <?php endif; ?>

            <?php elseif ( $is_filtered ): ?>
                <div class="cw-empty-state">
                    <i class="fas fa-search"></i>
                    <p>No campaigns match your filter.</p>
                    <a href="<?php echo esc_url($reset_url); ?>" class="cw-btn-outline-blue" style="margin-top:14px; text-decoration:none;">
                        <i class="fas fa-times"></i> Clear filter
                    </a>
                </div>
            <?php else: ?>
                <div class="cw-empty-state">
                    <i class="fas fa-bullhorn"></i>
                    <p>No campaigns yet. Create your first campaign to get started!</p>
                    <button type="button" class="cw-btn-primary" id="cw-open-create-empty" style="margin-top:14px;">
                        <i class="fas fa-plus"></i> Create Campaign
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <!-- CREATE/EDIT MODAL -->
        <div id="cw-campaign-modal" class="cw-modal" style="display: <?php echo $is_edit_mode ? 'flex' : 'none'; ?>; align-items:center; justify-content:center; padding:16px; box-sizing:border-box;">
            <?php 
            if ( class_exists('CW_Business_Form') ) {
                $form = new CW_Business_Form();
                echo $form->render_form([], null, true, $edit_id);
            } else {
                echo '<p style="color:red;">Error: Form class not loaded. Please contact support.</p>';
            }
            ?>
        </div>

        <script>
            jQuery(document).ready(function($){

                function openCampaignModal() {
                    $('#cw-campaign-modal').css('display', 'flex');
                    if (typeof window.currentStep !== 'undefined' && typeof window.updateWizardUI === 'function') {
                        window.currentStep = 1;
                        window.updateWizardUI();
                    }
                }

                // Open modal from header button or empty-state button
                $('.cw-content-wrapper').on('click', '#cw-open-create, #cw-open-create-empty', function(e){
                    e.preventDefault();
                    openCampaignModal();
                });

                // 2. CLOSE MODAL Logic
                function closeCampaignModal(){ 
                    $('#cw-campaign-modal').hide();
                    const url = new URL(window.location);
                    if (url.searchParams.has('edit_id')) {
                        url.searchParams.delete('edit_id');
                        window.history.pushState({}, '', url.toString()); 
                        location.reload();
                    }
                }
                
                // 3. Bind Close Events — #cww-close-btn lives inside the wizard shell
                $(document).on('click', '#cww-close-btn', closeCampaignModal);
                $(window).on('click', function(e){ 
                    if($(e.target).hasClass('cw-modal')){ closeCampaignModal(); } 
                });

            });
        </script>
        <?php
    }
    
    /* ==========================================================================
       3. Wallet TAB (UI Match)
       ========================================================================== */

    public function render_wallet() {
        $uid = get_current_user_id();
        
        // 1. Get Stats
        $wallet = class_exists('CW_Wallet') ? CW_Wallet::get_wallet_stats($uid) : ['total_earned'=>0, 'pending'=>0, 'available'=>0];
        
        // 2. Get Bank Data
        $bank = [
            'name'   => get_user_meta($uid, 'cw_bank_name', true), 
            'acc'    => get_user_meta($uid, 'cw_bank_acc', true), 
            'holder' => get_user_meta($uid, 'cw_bank_holder', true)
        ];
        
        // 3. Get History
        $history = get_posts([ 'post_type' => 'cw_withdrawal', 'author' => $uid, 'posts_per_page' => 10 ]);

        // Status messages now surface via CW_Flash_Notices (SweetAlert2 popups).

        ?>
        <div class="cw-content-wrapper">

            <!-- HEADER -->
            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">Wallet</h2>
                    <p>Manage your earnings and payouts.</p>
                </div>
                <a href="<?php echo esc_url(admin_url('admin-post.php?action=cw_export_wallet')); ?>" class="cw-btn-white small" style="text-decoration:none;">
                    <i class="fas fa-download"></i> Export Report
                </a>
            </div>

            <!-- STATS ROW (3 Cards) -->
            <div class="cw-stats-grid">
                <!-- Dark Card: Total -->
                <div class="cw-stat-card dark">
                    <div>
                        <span class="cw-stat-label text-gray">Total Earned (Lifetime)</span>
                        <h2 class="cw-stat-val cw-stat-money text-white"><?php echo wc_price($wallet['total_earned']); ?></h2>
                        <div class="cw-mini-tag"><span class="dot green"></span> Gross Revenue</div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Pending Clearance</span>
                        <h2 class="cw-stat-val cw-stat-money text-yellow"><?php echo wc_price($wallet['pending']); ?></h2>
                        <div class="cw-stat-meta text-muted">Held funds</div>
                    </div>
                </div>

                <!-- Available -->
                <div class="cw-stat-card border-left">
                    <div>
                        <span class="cw-stat-label">Available Balance</span>
                        <h2 class="cw-stat-val cw-stat-money text-blue"><?php echo wc_price($wallet['available']); ?></h2>
                        <div class="cw-stat-meta text-muted">Ready to withdraw</div>
                    </div>
                </div>
            </div>

            <!-- MAIN SPLIT CONTENT (Left: Forms, Right: History) -->
            <div class="wallet-split">
                
                <!-- LEFT COLUMN: ACTIONS -->
                <div class="cw-wallet-actions">
                    
                    <!-- 1. Payout Form -->
                    <div class="cw-info-card">
                        <div class="cw-card-title">
                            <i class="fas fa-arrow-up text-blue"></i>
                            <h3>Request Payout</h3>
                        </div>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST">
                            <input type="hidden" name="action" value="cw_request_withdrawal">
                            <?php wp_nonce_field('cw_request_withdraw', 'cw_withdraw_nonce'); ?>
                            
                            <div class="cw-field">
                                <label>Amount (RM)</label>
                                <input type="number" name="withdraw_amount" class="cw-input dark-input" placeholder="0.00" max="<?php echo esc_attr($wallet['available']); ?>" min="10" step="0.01" required>
                                <div class="cw-input-hint">Max: <?php echo wc_price($wallet['available']); ?></div>
                            </div>
                            
                            <button class="cw-btn-primary full-width" <?php if($wallet['available'] < 10) echo 'disabled style="opacity:0.6;"'; ?>>Request</button>
                        </form>
                    </div>

                    <!-- 2. Bank Form -->
                    <div class="cw-info-card">
                        <div class="cw-card-title">
                            <i class="fas fa-university text-gray"></i>
                            <h3>Bank Settings</h3>
                        </div>
                        <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" class="cw-compact-form">
                            <input type="hidden" name="action" value="cw_save_bank_details">
                            <?php wp_nonce_field('cw_save_bank', 'cw_bank_nonce'); ?>
                            
                            <div class="cw-field-slim">
                                <label>Bank Name</label>
                                <input type="text" name="cw_bank_name" value="<?php echo esc_attr($bank['name']); ?>" class="cw-input-slim">
                            </div>
                            <div class="cw-field-slim">
                                <label>Account Number</label>
                                <input type="text" name="cw_bank_acc" value="<?php echo esc_attr($bank['acc']); ?>" class="cw-input-slim">
                            </div>
                            <div class="cw-field-slim">
                                <label>Holder Name</label>
                                <input type="text" name="cw_bank_holder" value="<?php echo esc_attr($bank['holder']); ?>" class="cw-input-slim">
                            </div>
                            
                            <button class="cw-text-btn small" style="margin-top:10px;">Save Details</button>
                        </form>
                    </div>

                </div>
                <!-- END LEFT COLUMN -->

                <!-- RIGHT COLUMN: HISTORY -->
                <div class="cw-wallet-right">
                    <div class="cw-history-card">
                        <div class="cw-card-title">
                            <i class="fas fa-history text-gray"></i>
                            <h3>Transaction History</h3>
                        </div>
                        
                        <table class="cw-table wallet-table">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Desc</th>
                                    <th>Status</th>
                                    <th style="text-align:right;">Amt</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if($history): foreach($history as $h): 
                                    $amt = get_post_meta($h->ID, 'cw_amount', true);
                                    $st = get_post_status($h->ID) == 'publish' ? 'Paid' : 'Pending';
                                    $bg = $st == 'Paid' ? 'bg-green' : 'bg-yellow';
                                ?>
                                <tr>
                                    <td class="text-muted"><?php echo get_the_date('d M Y', $h->ID); ?></td>
                                    <td>Payout Request</td>
                                    <td><span class="cw-badge <?php echo $bg; ?>"><?php echo $st; ?></span></td>
                                    <td style="text-align:right;"><strong>- <?php echo wc_price($amt); ?></strong></td>
                                </tr>
                                <?php endforeach; else: ?>
                                    <tr><td colspan="4" style="text-align:center; padding:40px; color:#999;">No transactions found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <!-- END RIGHT COLUMN -->

            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       3b. REPORTS TAB (Analytics + Multi-format export)
       ========================================================================== */
    public function render_reports() {
        if ( ! class_exists( 'CW_Business_Reports' ) ) {
            echo '<div class="cw-content-wrapper"><div class="cw-alert error">Reports service unavailable.</div></div>';
            return;
        }

        $uid = get_current_user_id();
        $u   = get_userdata( $uid );

        $requested_campaign = isset( $_GET['campaign_id'] ) ? (int) $_GET['campaign_id'] : 0;
        $range              = isset( $_GET['range'] ) ? CW_Business_Reports::sanitize_range( $_GET['range'] ) : CW_Business_Reports::DEFAULT_RANGE;
        $roster_page        = isset( $_GET['roster_page'] ) ? max( 1, (int) $_GET['roster_page'] ) : 1;

        if ( $requested_campaign && ! CW_Business_Reports::user_can_view_campaign( $requested_campaign, $uid ) ) {
            $requested_campaign = 0;
        }

        $context = CW_Business_Reports::get_context( $uid, $requested_campaign, $range );

        $owned_ids = CW_Business_Reports::owned_campaign_ids( $uid );

        $my_account_url   = get_permalink( wc_get_page_id( 'myaccount' ) );
        $base_url         = add_query_arg( 'tab', 'reports', $my_account_url );
        $manage_entries_url = add_query_arg( 'tab', 'manage_entries', $my_account_url );

        $export_args = [
            'action'      => 'cw_export_report',
            'campaign_id' => $requested_campaign,
            'range'       => $range,
        ];
        $export_base = wp_nonce_url( add_query_arg( $export_args, admin_url( 'admin-post.php' ) ), 'cw_export_report' );

        $per_page    = 25;
        $roster      = $context['roster'];
        $total_rows  = count( $roster );
        $total_pages = max( 1, (int) ceil( $total_rows / $per_page ) );
        $roster_page = min( $roster_page, $total_pages );
        $roster_slice = array_slice( $roster, ( $roster_page - 1 ) * $per_page, $per_page );

        $custom_labels = CW_Business_Reports::collect_custom_field_labels( $roster );

        $is_empty = empty( $owned_ids );
        ?>
        <div class="cw-content-wrapper cw-reports-page">
            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;"><?php esc_html_e( 'Reports', 'creativewings-core' ); ?></h2>
                    <p>
                        <?php
                        if ( $is_empty ) {
                            esc_html_e( 'No campaigns yet — create one to start tracking analytics.', 'creativewings-core' );
                        } else {
                            printf(
                                /* translators: 1: campaign name, 2: range label */
                                esc_html__( '%1$s · %2$s', 'creativewings-core' ),
                                esc_html( $context['campaign_title'] ),
                                esc_html( $context['range_label'] )
                            );
                        }
                        ?>
                    </p>
                </div>
            </div>

            <?php if ( $is_empty ) : ?>
                <div class="cw-empty-state">
                    <i class="fas fa-chart-bar"></i>
                    <h3><?php esc_html_e( 'No data yet', 'creativewings-core' ); ?></h3>
                    <p><?php esc_html_e( 'Once campaigns are published and start receiving registrations, you will see numbers, trends, and exports here.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( add_query_arg( 'tab', 'campaigns', $my_account_url ) ); ?>" class="cw-btn-primary">
                        <i class="fas fa-plus"></i> <?php esc_html_e( 'Create your first campaign', 'creativewings-core' ); ?>
                    </a>
                </div>
            <?php else : ?>

                <!-- Toolbar: filter + export -->
                <div class="cw-reports-toolbar">
                    <form method="GET" class="cw-reports-filters" action="<?php echo esc_url( $my_account_url ); ?>">
                        <input type="hidden" name="tab" value="reports">
                        <div class="cw-reports-field">
                            <label for="cw-report-campaign"><?php esc_html_e( 'Campaign', 'creativewings-core' ); ?></label>
                            <select id="cw-report-campaign" name="campaign_id">
                                <option value="0"><?php esc_html_e( 'All campaigns', 'creativewings-core' ); ?></option>
                                <?php foreach ( $owned_ids as $pid ) : ?>
                                    <option value="<?php echo (int) $pid; ?>" <?php selected( $requested_campaign, $pid ); ?>>
                                        <?php echo esc_html( get_the_title( $pid ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="cw-reports-field">
                            <label for="cw-report-range"><?php esc_html_e( 'Date range', 'creativewings-core' ); ?></label>
                            <select id="cw-report-range" name="range">
                                <?php foreach ( CW_Business_Reports::range_options() as $key => $_days ) : ?>
                                    <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $range, $key ); ?>>
                                        <?php echo esc_html( CW_Business_Reports::range_label( $key ) ); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="cw-btn-primary small">
                            <i class="fas fa-filter"></i> <?php esc_html_e( 'Apply', 'creativewings-core' ); ?>
                        </button>
                    </form>

                    <div class="cw-reports-exports">
                        <span class="cw-reports-exports-label"><?php esc_html_e( 'Export', 'creativewings-core' ); ?></span>
                        <a href="<?php echo esc_url( add_query_arg( 'format', 'csv', $export_base ) ); ?>" class="cw-btn-outline-blue small">
                            <i class="fas fa-file-csv"></i> CSV
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'format', 'xlsx', $export_base ) ); ?>" class="cw-btn-outline-blue small">
                            <i class="fas fa-file-excel"></i> Excel
                        </a>
                        <a href="<?php echo esc_url( add_query_arg( 'format', 'pdf', $export_base ) ); ?>" class="cw-btn-outline-blue small">
                            <i class="fas fa-file-pdf"></i> PDF
                        </a>
                    </div>
                </div>

                <!-- KPI strip -->
                <div class="cw-stats-grid cols-4 cw-reports-kpis">
                    <div class="cw-stat-card">
                        <div>
                            <span class="cw-stat-label"><?php esc_html_e( 'Campaigns', 'creativewings-core' ); ?></span>
                            <h3 class="cw-stat-value"><?php echo (int) $context['kpis']['campaigns_total']; ?></h3>
                            <div class="cw-stat-meta text-muted">
                                <?php echo (int) $context['kpis']['campaigns_active']; ?> active ·
                                <?php echo (int) $context['kpis']['campaigns_past']; ?> past ·
                                <?php echo (int) $context['kpis']['campaigns_pending']; ?> draft
                            </div>
                        </div>
                        <div class="cw-stat-icon-wrapper blue"><i class="fas fa-bullhorn"></i></div>
                    </div>
                    <div class="cw-stat-card">
                        <div>
                            <span class="cw-stat-label"><?php esc_html_e( 'Participants', 'creativewings-core' ); ?></span>
                            <h3 class="cw-stat-value"><?php echo (int) $context['kpis']['participants']; ?></h3>
                            <div class="cw-stat-meta text-muted"><?php esc_html_e( 'Completed registrations', 'creativewings-core' ); ?></div>
                        </div>
                        <div class="cw-stat-icon-wrapper green"><i class="fas fa-users"></i></div>
                    </div>
                    <div class="cw-stat-card">
                        <div>
                            <span class="cw-stat-label"><?php esc_html_e( 'Revenue', 'creativewings-core' ); ?></span>
                            <h3 class="cw-stat-value cw-stat-money"><?php echo wp_kses_post( wc_price( $context['kpis']['revenue'] ) ); ?></h3>
                            <div class="cw-stat-meta text-muted">
                                <?php
                                /* translators: %s formatted average revenue */
                                printf( esc_html__( 'Avg %s per campaign', 'creativewings-core' ), wp_kses_post( wc_price( $context['kpis']['avg_revenue'] ) ) );
                                ?>
                            </div>
                        </div>
                        <div class="cw-stat-icon-wrapper coral"><i class="fas fa-wallet"></i></div>
                    </div>
                    <div class="cw-stat-card">
                        <div>
                            <span class="cw-stat-label"><?php esc_html_e( 'Submissions', 'creativewings-core' ); ?></span>
                            <h3 class="cw-stat-value"><?php echo (int) ( $context['kpis']['staged'] + $context['kpis']['claimed'] ); ?></h3>
                            <div class="cw-stat-meta text-muted">
                                <?php echo (int) $context['kpis']['staged']; ?> staged ·
                                <?php echo (int) $context['kpis']['claimed']; ?> claimed ·
                                <?php echo (int) $context['kpis']['moderation_pending']; ?> pending
                            </div>
                        </div>
                        <div class="cw-stat-icon-wrapper yellow"><i class="fas fa-clipboard-check"></i></div>
                    </div>
                </div>

                <!-- Charts: Registrations + Revenue over time -->
                <div class="cw-reports-section cw-reports-charts">
                    <div class="cw-chart-container">
                        <div class="cw-chart-header"><h3><?php esc_html_e( 'Registrations over time', 'creativewings-core' ); ?></h3></div>
                        <div class="cw-chart-wrapper"><canvas id="cw-report-entries-chart"></canvas></div>
                    </div>
                    <div class="cw-chart-container">
                        <div class="cw-chart-header"><h3><?php esc_html_e( 'Revenue over time', 'creativewings-core' ); ?></h3></div>
                        <div class="cw-chart-wrapper"><canvas id="cw-report-revenue-chart"></canvas></div>
                    </div>
                </div>

                <!-- Breakdown charts -->
                <div class="cw-reports-section cw-reports-charts">
                    <?php if ( $context['is_all'] && ! empty( $context['breakdowns']['category'] ) ) : ?>
                        <div class="cw-chart-container">
                            <div class="cw-chart-header"><h3><?php esc_html_e( 'Campaign types', 'creativewings-core' ); ?></h3></div>
                            <div class="cw-chart-wrapper"><canvas id="cw-report-category-chart"></canvas></div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $context['is_all'] && ! empty( $context['breakdowns']['status'] ) ) : ?>
                        <div class="cw-chart-container">
                            <div class="cw-chart-header"><h3><?php esc_html_e( 'Campaign status', 'creativewings-core' ); ?></h3></div>
                            <div class="cw-chart-wrapper"><canvas id="cw-report-status-chart"></canvas></div>
                        </div>
                    <?php endif; ?>

                    <?php if ( ! empty( $context['breakdowns']['school'] ) ) : ?>
                        <div class="cw-chart-container">
                            <div class="cw-chart-header"><h3><?php esc_html_e( 'Submissions by school', 'creativewings-core' ); ?></h3></div>
                            <div class="cw-chart-wrapper"><canvas id="cw-report-school-chart"></canvas></div>
                        </div>
                    <?php endif; ?>

                    <?php if ( $context['has_competitions'] ) : ?>
                        <div class="cw-chart-container">
                            <div class="cw-chart-header"><h3><?php esc_html_e( 'Judge score distribution', 'creativewings-core' ); ?></h3></div>
                            <div class="cw-chart-wrapper"><canvas id="cw-report-scores-chart"></canvas></div>
                        </div>
                    <?php endif; ?>
                </div>

                <?php if ( $context['is_all'] && ! empty( $context['campaigns'] ) ) : ?>
                    <!-- Campaign comparison -->
                    <div class="cw-reports-section">
                        <h3 class="cw-reports-section-title"><?php esc_html_e( 'Campaign comparison', 'creativewings-core' ); ?></h3>
                        <div class="cw-table-wrap">
                            <table class="cw-report-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Campaign', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Type', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Participants', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Revenue', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Staged', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Claimed', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Deadline', 'creativewings-core' ); ?></th>
                                        <th></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $context['campaigns'] as $c ) : ?>
                                    <?php
                                    $filter_url = add_query_arg(
                                        [
                                            'tab'         => 'reports',
                                            'campaign_id' => (int) $c['id'],
                                            'range'       => $range,
                                        ],
                                        $my_account_url
                                    );
                                    ?>
                                    <tr>
                                        <td><strong><?php echo esc_html( $c['title'] ); ?></strong></td>
                                        <td><?php echo esc_html( $c['type_label'] ); ?></td>
                                        <td><span class="cw-pill cw-pill-<?php echo esc_attr( $c['state'] ); ?>"><?php echo esc_html( $c['state_label'] ); ?></span></td>
                                        <td class="num"><?php echo (int) $c['participants']; ?></td>
                                        <td class="num"><?php echo wp_kses_post( wc_price( $c['revenue'] ) ); ?></td>
                                        <td class="num"><?php echo (int) $c['staged']; ?></td>
                                        <td class="num"><?php echo (int) $c['claimed']; ?></td>
                                        <td><?php echo esc_html( $c['deadline'] ?: '—' ); ?></td>
                                        <td>
                                            <a href="<?php echo esc_url( $filter_url ); ?>" class="cw-btn-outline-blue small">
                                                <i class="fas fa-search-plus"></i> <?php esc_html_e( 'View', 'creativewings-core' ); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Participant roster -->
                <div class="cw-reports-section">
                    <h3 class="cw-reports-section-title">
                        <?php esc_html_e( 'Participant roster', 'creativewings-core' ); ?>
                        <small><?php echo (int) $total_rows; ?> <?php esc_html_e( 'rows', 'creativewings-core' ); ?></small>
                    </h3>
                    <?php if ( empty( $roster_slice ) ) : ?>
                        <div class="cw-reports-empty"><?php esc_html_e( 'No registrations in this period.', 'creativewings-core' ); ?></div>
                    <?php else : ?>
                        <div class="cw-table-wrap">
                            <table class="cw-report-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Date', 'creativewings-core' ); ?></th>
                                        <?php if ( $context['is_all'] ) : ?><th><?php esc_html_e( 'Campaign', 'creativewings-core' ); ?></th><?php endif; ?>
                                        <th><?php esc_html_e( 'Participant', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Email', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Order', 'creativewings-core' ); ?></th>
                                        <th class="num"><?php esc_html_e( 'Amount', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Age', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Submission code', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'School', 'creativewings-core' ); ?></th>
                                        <?php if ( $context['has_competitions'] ) : ?>
                                            <th class="num"><?php esc_html_e( 'Score', 'creativewings-core' ); ?></th>
                                            <th><?php esc_html_e( 'Winner', 'creativewings-core' ); ?></th>
                                        <?php endif; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( $roster_slice as $row ) : ?>
                                    <tr>
                                        <td><?php echo esc_html( date_i18n( 'd M Y', strtotime( $row['date'] ) ) ); ?></td>
                                        <?php if ( $context['is_all'] ) : ?><td><?php echo esc_html( $row['campaign'] ); ?></td><?php endif; ?>
                                        <td><?php echo esc_html( $row['participant'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $row['email'] ?: '—' ); ?></td>
                                        <td class="num"><?php echo $row['order_id'] ? '#' . (int) $row['order_id'] : '—'; ?></td>
                                        <td class="num"><?php echo $row['amount'] !== '' ? wp_kses_post( wc_price( (float) $row['amount'] ) ) : '—'; ?></td>
                                        <td><?php echo esc_html( $row['age_label'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $row['submission_code'] ?: '—' ); ?></td>
                                        <td><?php echo esc_html( $row['school_code'] ?: '—' ); ?></td>
                                        <?php if ( $context['has_competitions'] ) : ?>
                                            <td class="num"><?php echo $row['score'] !== '' ? esc_html( $row['score'] ) : '—'; ?></td>
                                            <td><?php echo $row['winner'] ? '<i class="fas fa-trophy" style="color:#d97706;"></i>' : '—'; ?></td>
                                        <?php endif; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <?php if ( $total_pages > 1 ) :
                            $page_url = function ( $n ) use ( $base_url, $requested_campaign, $range ) {
                                return add_query_arg(
                                    [
                                        'campaign_id' => (int) $requested_campaign,
                                        'range'       => $range,
                                        'roster_page' => (int) $n,
                                    ],
                                    $base_url
                                );
                            };
                        ?>
                            <nav class="cw-pagination" role="navigation">
                                <?php if ( $roster_page > 1 ) : ?>
                                    <a class="cw-page-btn prev" href="<?php echo esc_url( $page_url( $roster_page - 1 ) ); ?>">‹ <?php esc_html_e( 'Prev', 'creativewings-core' ); ?></a>
                                <?php else : ?>
                                    <span class="cw-page-btn prev disabled">‹ <?php esc_html_e( 'Prev', 'creativewings-core' ); ?></span>
                                <?php endif; ?>
                                <span class="cw-page-info"><?php printf( esc_html__( 'Page %1$d of %2$d', 'creativewings-core' ), (int) $roster_page, (int) $total_pages ); ?></span>
                                <?php if ( $roster_page < $total_pages ) : ?>
                                    <a class="cw-page-btn next" href="<?php echo esc_url( $page_url( $roster_page + 1 ) ); ?>"><?php esc_html_e( 'Next', 'creativewings-core' ); ?> ›</a>
                                <?php else : ?>
                                    <span class="cw-page-btn next disabled"><?php esc_html_e( 'Next', 'creativewings-core' ); ?> ›</span>
                                <?php endif; ?>
                            </nav>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <?php if ( $context['has_staged'] ) : ?>
                    <!-- Staged submissions -->
                    <div class="cw-reports-section">
                        <h3 class="cw-reports-section-title">
                            <?php esc_html_e( 'Staged submissions (school flow)', 'creativewings-core' ); ?>
                            <small><?php echo count( $context['staged'] ); ?></small>
                        </h3>
                        <div class="cw-table-wrap">
                            <table class="cw-report-table">
                                <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Code', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Student', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'School', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Moderation', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Order', 'creativewings-core' ); ?></th>
                                        <th><?php esc_html_e( 'Created', 'creativewings-core' ); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php foreach ( array_slice( $context['staged'], 0, 100 ) as $s ) : ?>
                                    <tr>
                                        <td><code><?php echo esc_html( $s['submission_code'] ); ?></code></td>
                                        <td><?php echo esc_html( $s['student_name'] ); ?></td>
                                        <td><?php echo esc_html( $s['school_code'] ); ?></td>
                                        <td><?php echo esc_html( $s['status'] ); ?></td>
                                        <td><?php echo esc_html( $s['moderation_status'] ); ?></td>
                                        <td><?php echo $s['order_id'] ? '#' . (int) $s['order_id'] : '—'; ?></td>
                                        <td><?php echo esc_html( date_i18n( 'd M Y H:i', strtotime( $s['created_at'] ) ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ( count( $context['staged'] ) > 100 ) : ?>
                            <p class="cw-reports-empty">
                                <?php
                                /* translators: %d total staged rows */
                                printf( esc_html__( 'Showing first 100 rows. Use export to view all %d staged submissions.', 'creativewings-core' ), count( $context['staged'] ) );
                                ?>
                            </p>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <!-- Chart bootstrap data -->
                <script type="application/json" id="cw-report-data">
                    <?php
                    echo wp_json_encode( [
                        'entries'     => $context['timeseries']['entries'],
                        'revenue'     => $context['timeseries']['revenue'],
                        'category'    => $context['breakdowns']['category'],
                        'status'      => $context['breakdowns']['status'],
                        'school'      => $context['breakdowns']['school'],
                        'scores'      => $context['breakdowns']['scores'],
                        'currency'    => function_exists( 'get_woocommerce_currency_symbol' ) ? html_entity_decode( get_woocommerce_currency_symbol() ) : 'RM',
                    ] );
                    ?>
                </script>
                <script>
                (function(){
                    var CHART_V4_URL = <?php echo wp_json_encode( CW_URL . 'assets/vendor/chart.js/chart.umd.min.js?v=' . CW_VERSION ); ?>;
                    function ensureChartV4(cb){
                        var has = typeof window.Chart !== 'undefined';
                        var v = has && window.Chart.version ? String(window.Chart.version) : '';
                        if (has && v && v.charAt(0) >= '3') { cb(); return; }
                        if (has) { try { delete window.Chart; } catch(e) { window.Chart = undefined; } }
                        var s = document.createElement('script');
                        s.src = CHART_V4_URL; s.async = false;
                        s.onload = cb; s.onerror = function(){ console.warn('[CW] Failed to load Chart.js v4'); };
                        document.head.appendChild(s);
                    }
                    document.addEventListener('DOMContentLoaded', function () { ensureChartV4(function(){
                    if (typeof Chart === 'undefined') return;
                    var dataEl = document.getElementById('cw-report-data');
                    if (!dataEl) return;
                    var d;
                    try { d = JSON.parse(dataEl.textContent); } catch (e) { return; }

                    var defaults = {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: {
                            x: { grid: { display: false }, ticks: { color: '#64748b', autoSkip: true, maxRotation: 0 } },
                            y: { beginAtZero: true, grid: { color: '#e2e8f0' }, ticks: { color: '#64748b' } }
                        }
                    };

                    function shortLabel(s) {
                        if (!s || s.length < 8) return s;
                        var parts = s.split('-');
                        return parts.length === 3 ? (parts[2] + '/' + parts[1]) : s;
                    }

                    var entryCtx = document.getElementById('cw-report-entries-chart');
                    if (entryCtx && d.entries) {
                        new Chart(entryCtx, {
                            type: 'line',
                            data: {
                                labels: d.entries.labels.map(shortLabel),
                                datasets: [{
                                    label: 'Registrations',
                                    data: d.entries.data,
                                    borderColor: '#006599',
                                    backgroundColor: 'rgba(0,101,153,0.12)',
                                    fill: true,
                                    tension: 0.35,
                                    pointRadius: 2
                                }]
                            },
                            options: defaults
                        });
                    }

                    var revCtx = document.getElementById('cw-report-revenue-chart');
                    if (revCtx && d.revenue) {
                        new Chart(revCtx, {
                            type: 'line',
                            data: {
                                labels: d.revenue.labels.map(shortLabel),
                                datasets: [{
                                    label: 'Revenue',
                                    data: d.revenue.data,
                                    borderColor: '#FE6261',
                                    backgroundColor: 'rgba(254,98,97,0.12)',
                                    fill: true,
                                    tension: 0.35,
                                    pointRadius: 2
                                }]
                            },
                            options: Object.assign({}, defaults, {
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function (ctx) { return (d.currency || 'RM') + ' ' + Number(ctx.parsed.y || 0).toLocaleString(); }
                                        }
                                    }
                                }
                            })
                        });
                    }

                    function buildBar(id, dataMap, color) {
                        var ctx = document.getElementById(id);
                        if (!ctx || !dataMap || !Object.keys(dataMap).length) return;
                        new Chart(ctx, {
                            type: 'bar',
                            data: {
                                labels: Object.keys(dataMap),
                                datasets: [{ data: Object.values(dataMap), backgroundColor: color, borderRadius: 6 }]
                            },
                            options: defaults
                        });
                    }
                    function buildDoughnut(id, dataMap, palette) {
                        var ctx = document.getElementById(id);
                        if (!ctx || !dataMap || !Object.keys(dataMap).length) return;
                        new Chart(ctx, {
                            type: 'doughnut',
                            data: {
                                labels: Object.keys(dataMap),
                                datasets: [{ data: Object.values(dataMap), backgroundColor: palette }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: { legend: { position: 'bottom' } }
                            }
                        });
                    }

                    buildDoughnut('cw-report-category-chart', d.category, ['#006599','#FE6261','#22c55e','#f59e0b','#7c3aed']);
                    buildDoughnut('cw-report-status-chart',   d.status,   ['#22c55e','#94a3b8','#f59e0b']);
                    buildBar('cw-report-school-chart', d.school, '#006599');
                    buildBar('cw-report-scores-chart', d.scores, '#7c3aed');
                    }); });
                })();
                </script>

            <?php endif; ?>
        </div>
        <?php
    }

    /* ==========================================================================
       4. SETTINGS TAB (Company Profile - New UI)
       ========================================================================== */
    public function render_settings() {
        $uid = get_current_user_id();

        // Text-ish fields read in bulk so the form can pre-fill cleanly.
        $text_fields = [
            // Existing
            'business_name', 'business_phone', 'business_address', 'business_website', 'business_ssm',
            // New basics / story / location
            'business_tagline', 'business_about', 'business_founded_year', 'business_industry',
            'business_team_size', 'business_city', 'business_country',
            // Social links (reuse existing meta keys so the public profile picks them up)
            'Facebook_url', 'instagram_url', 'linkeden_url', 'twitter_url', 'behave_url',
            'youtube_url', 'tiktok_url',
        ];
        $meta = [];
        foreach ( $text_fields as $f ) {
            $meta[ $f ] = get_user_meta( $uid, $f, true );
        }

        $logo = get_user_meta($uid, 'business_logo', true);
        $logo_url = (is_array($logo) && isset($logo['url'])) ? $logo['url'] : '';

        $cover = get_user_meta($uid, 'business_cover', true);
        $cover_url = (is_array($cover) && isset($cover['url'])) ? $cover['url'] : '';

        // Public-visibility toggles for the organiser card on campaign pages.
        // Default to visible ('1'). Stored as '1' / '0'.
        $show_email = get_user_meta( $uid, 'cw_show_org_email', true );
        $show_phone = get_user_meta( $uid, 'cw_show_org_phone', true );
        $show_email = ( $show_email === '' ) ? '1' : $show_email;
        $show_phone = ( $show_phone === '' ) ? '1' : $show_phone;

        $current_user = wp_get_current_user();
        $public_email = $current_user ? $current_user->user_email : '';

        $current_year = (int) date( 'Y' );

        $industries = [
            'Education', 'Technology', 'Arts & Culture', 'Media', 'Non-Profit',
            'Government', 'Sports', 'Healthcare', 'Retail', 'Other',
        ];
        $team_sizes = [ '1-10', '11-50', '51-200', '201-500', '500+' ];

        // Status messages surface via CW_Flash_Notices (SweetAlert2 popups).
        ?>
        <div class="cw-content-wrapper">

            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">Company Profile</h2>
                    <p>Update your organisation details and public information</p>
                </div>
            </div>

            <div class="cw-profile-card-ui">

                <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="cw_save_biz_info">
                    <?php wp_nonce_field('cw_biz_info_nonce'); ?>

                    <!-- Cover Photo Banner (uploader) -->
                    <div class="cw-profile-banner<?php echo $cover_url ? ' has-cover' : ''; ?>">
                        <?php if ( $cover_url ): ?>
                            <img src="<?php echo esc_url( $cover_url ); ?>" id="cw-biz-cover-preview" alt="Cover photo" loading="lazy" decoding="async">
                        <?php else: ?>
                            <img src="" id="cw-biz-cover-preview" alt="Cover photo" style="display:none;">
                        <?php endif; ?>

                        <label for="biz_cover_input" class="cw-cover-upload-btn">
                            <i class="fas fa-camera"></i>
                            <span><?php echo $cover_url ? 'Change cover photo' : 'Add cover photo'; ?></span>
                        </label>
                        <input type="file" id="biz_cover_input" name="business_cover" accept="image/*" style="display:none;"
                               onchange="if(this.files[0]){var p=document.getElementById('cw-biz-cover-preview');p.src=window.URL.createObjectURL(this.files[0]);p.style.display='block';this.closest('.cw-profile-banner').classList.add('has-cover');}">
                    </div>

                    <div class="cw-profile-body">

                        <!-- Avatar / Logo Section -->
                        <div class="cw-profile-avatar-wrap">
                            <div class="cw-avatar-circle">
                                <?php if($logo_url): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" id="cw-biz-logo-preview" loading="lazy" decoding="async">
                                <?php else: ?>
                                    <img src="" id="cw-biz-logo-preview" style="display:none;">
                                    <div class="cw-avatar-placeholder" id="cw-biz-logo-placeholder"><i class="fas fa-building"></i></div>
                                <?php endif; ?>
                            </div>
                            <label for="biz_logo_input" class="cw-avatar-upload-btn">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" id="biz_logo_input" name="business_logo" accept="image/*" style="display:none;"
                                   onchange="if(this.files[0]){var p=document.getElementById('cw-biz-logo-preview');p.src=window.URL.createObjectURL(this.files[0]);p.style.display='block';var ph=document.getElementById('cw-biz-logo-placeholder');if(ph)ph.style.display='none';}">
                        </div>

                        <!-- ============================================================
                             SECTION: BASICS
                             ============================================================ -->
                        <h4 class="cw-biz-section-title"><i class="fas fa-building"></i> Basics</h4>

                        <div class="cw-profile-grid-layout">

                            <div class="cw-col-left">
                                <div class="cw-field-dark">
                                    <label>Company Name</label>
                                    <input type="text" name="business_name" value="<?php echo esc_attr($meta['business_name']); ?>" placeholder="Company Name">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Tagline</label>
                                    <input type="text" name="business_tagline" value="<?php echo esc_attr($meta['business_tagline']); ?>" placeholder="A short slogan that describes you" maxlength="120">
                                </div>
                                <div class="cw-field-dark">
                                    <label>SSM / Registration Number</label>
                                    <input type="text" name="business_ssm" value="<?php echo esc_attr($meta['business_ssm']); ?>" placeholder="2024010...">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Industry</label>
                                    <select name="business_industry">
                                        <option value="" <?php selected( $meta['business_industry'], '' ); ?>>— Select industry —</option>
                                        <?php foreach ( $industries as $ind ): ?>
                                            <option value="<?php echo esc_attr( $ind ); ?>" <?php selected( $meta['business_industry'], $ind ); ?>><?php echo esc_html( $ind ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="cw-col-right">
                                <div class="cw-field-dark">
                                    <label>Phone Number</label>
                                    <input type="text" name="business_phone" value="<?php echo esc_attr($meta['business_phone']); ?>" placeholder="+60...">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Website</label>
                                    <input type="url" name="business_website" value="<?php echo esc_attr($meta['business_website']); ?>" placeholder="https://...">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Founded Year</label>
                                    <input type="number" name="business_founded_year" value="<?php echo esc_attr($meta['business_founded_year']); ?>" min="1900" max="<?php echo esc_attr( $current_year ); ?>" placeholder="e.g. <?php echo esc_attr( $current_year - 5 ); ?>">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Team Size</label>
                                    <select name="business_team_size">
                                        <option value="" <?php selected( $meta['business_team_size'], '' ); ?>>— Select size —</option>
                                        <?php foreach ( $team_sizes as $ts ): ?>
                                            <option value="<?php echo esc_attr( $ts ); ?>" <?php selected( $meta['business_team_size'], $ts ); ?>><?php echo esc_html( $ts ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                        </div>

                        <!-- ============================================================
                             SECTION: STORY
                             ============================================================ -->
                        <h4 class="cw-biz-section-title"><i class="fas fa-book-open"></i> Story</h4>

                        <div class="cw-field-dark">
                            <label>About</label>
                            <textarea name="business_about" rows="5" placeholder="Tell people what your organisation does, who you serve, and what makes you different..."><?php echo esc_textarea( $meta['business_about'] ); ?></textarea>
                            <p class="cw-field-hint">Basic HTML is allowed (links, bold, lists). Shown on your public profile.</p>
                        </div>

                        <!-- ============================================================
                             SECTION: LOCATION
                             ============================================================ -->
                        <h4 class="cw-biz-section-title"><i class="fas fa-map-marker-alt"></i> Location</h4>

                        <div class="cw-profile-grid-layout">
                            <div class="cw-col-left">
                                <div class="cw-field-dark">
                                    <label>City</label>
                                    <input type="text" name="business_city" value="<?php echo esc_attr( $meta['business_city'] ); ?>" placeholder="e.g. Kuala Lumpur">
                                </div>
                            </div>
                            <div class="cw-col-right">
                                <div class="cw-field-dark">
                                    <label>Country</label>
                                    <input type="text" name="business_country" value="<?php echo esc_attr( $meta['business_country'] ); ?>" placeholder="Malaysia">
                                </div>
                            </div>
                        </div>

                        <div class="cw-field-dark">
                            <label>Business Address</label>
                            <textarea name="business_address" rows="3" placeholder="Street, postcode, building..."><?php echo esc_textarea($meta['business_address']); ?></textarea>
                        </div>

                        <!-- ============================================================
                             SECTION: SOCIAL LINKS
                             ============================================================ -->
                        <h4 class="cw-biz-section-title"><i class="fas fa-share-alt"></i> Social Links</h4>

                        <div class="cw-profile-grid-layout">
                            <div class="cw-col-left">
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-facebook-f" style="color:#1877f2;"></i> Facebook</label>
                                    <input type="url" name="Facebook_url" value="<?php echo esc_attr( $meta['Facebook_url'] ); ?>" placeholder="https://facebook.com/yourpage">
                                </div>
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-instagram" style="color:#dc2743;"></i> Instagram</label>
                                    <input type="url" name="instagram_url" value="<?php echo esc_attr( $meta['instagram_url'] ); ?>" placeholder="https://instagram.com/yourhandle">
                                </div>
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-linkedin-in" style="color:#0077b5;"></i> LinkedIn</label>
                                    <input type="url" name="linkeden_url" value="<?php echo esc_attr( $meta['linkeden_url'] ); ?>" placeholder="https://linkedin.com/company/...">
                                </div>
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-behance" style="color:#1769ff;"></i> Behance</label>
                                    <input type="url" name="behave_url" value="<?php echo esc_attr( $meta['behave_url'] ); ?>" placeholder="https://behance.net/yourstudio">
                                </div>
                            </div>
                            <div class="cw-col-right">
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-twitter" style="color:#000;"></i> X / Twitter</label>
                                    <input type="url" name="twitter_url" value="<?php echo esc_attr( $meta['twitter_url'] ); ?>" placeholder="https://x.com/yourhandle">
                                </div>
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-youtube" style="color:#ff0000;"></i> YouTube</label>
                                    <input type="url" name="youtube_url" value="<?php echo esc_attr( $meta['youtube_url'] ); ?>" placeholder="https://youtube.com/@yourchannel">
                                </div>
                                <div class="cw-field-dark">
                                    <label><i class="fab fa-tiktok" style="color:#000;"></i> TikTok</label>
                                    <input type="url" name="tiktok_url" value="<?php echo esc_attr( $meta['tiktok_url'] ); ?>" placeholder="https://tiktok.com/@yourhandle">
                                </div>
                            </div>
                        </div>

                        <!-- Public visibility on campaign pages -->
                        <div class="cw-biz-visibility">
                            <h3 class="cw-biz-visibility-title"><i class="fas fa-eye"></i> Public visibility</h3>
                            <p class="cw-biz-visibility-desc">Choose what contact details participants can see on your campaign pages.</p>

                            <label class="cw-biz-vis-row">
                                <input type="hidden" name="cw_show_org_email" value="0">
                                <input type="checkbox" name="cw_show_org_email" value="1" <?php checked( $show_email, '1' ); ?>>
                                <span class="cw-biz-vis-text">
                                    <strong>Show email on campaign pages</strong>
                                    <?php if ( $public_email ): ?>
                                        <em><?php echo esc_html( $public_email ); ?></em>
                                    <?php endif; ?>
                                </span>
                            </label>

                            <label class="cw-biz-vis-row">
                                <input type="hidden" name="cw_show_org_phone" value="0">
                                <input type="checkbox" name="cw_show_org_phone" value="1" <?php checked( $show_phone, '1' ); ?>>
                                <span class="cw-biz-vis-text">
                                    <strong>Show phone number on campaign pages</strong>
                                    <?php if ( ! empty( $meta['business_phone'] ) ): ?>
                                        <em><?php echo esc_html( $meta['business_phone'] ); ?></em>
                                    <?php else: ?>
                                        <em class="cw-biz-vis-empty">Add a phone number above to enable this</em>
                                    <?php endif; ?>
                                </span>
                            </label>
                        </div>

                        <div class="cw-profile-footer">
                            <button type="submit" class="cw-btn-primary">
                                <i class="far fa-save"></i> Save Changes
                            </button>
                        </div>

                    </div>
                </form>
            </div>

        </div>
        <?php
    }
    
    /* ==========================================================================
       5. ENTRY MANAGEMENT & SCORING (MODAL VIEW FIX)
       ========================================================================== */
    
    // Helper to format the entry details into clean HTML (Needed by render_entry_management)
    private function format_entry_details_html($details) {
        $html = '<ul style="list-style:none; padding:0; margin-top:10px; font-size:14px;">';
        if (is_array($details)) {
            foreach($details as $item) {
                if (isset($item['label']) && isset($item['value'])) {
                    $value = esc_html($item['value']);
                    // Check if the value is a file URL (as saved by CW_Shop::save_custom_data_to_cart)
                    if (filter_var($item['value'], FILTER_VALIDATE_URL) && preg_match('/\.(jpg|jpeg|png|pdf)$/i', $item['value'])) {
                        $value = '<a href="'.esc_url($item['value']).'" target="_blank" style="color:#0073aa; text-decoration:underline;">View File/Image</a>';
                    }
                    $html .= '<li style="margin-bottom:5px;"><strong>'.esc_html($item['label']).':</strong> '.$value.'</li>';
                }
            }
        }
        $html .= '</ul>';
        return $html;
    }

    public function render_entry_management($campaign_id) {
        
        $uid = get_current_user_id();
        
        // --- CRITICAL SECURITY FIX: Enforce Campaign Ownership ---
        if ( ! get_post( $campaign_id ) ) {
            $error_message = 'Error: Campaign ID ' . $campaign_id . ' not found.';
        } elseif ( class_exists( 'CW_Roles' ) ? ! CW_Roles::user_owns_campaign( $campaign_id, $uid ) : (int) get_post_field( 'post_author', $campaign_id ) !== (int) $uid ) {
            $error_message = 'Access Denied: You do not own Campaign ID ' . $campaign_id;
        } else {
            $error_message = '';
        }

        if ( $error_message ) {
            
            // Redirect to the main campaign list with an error message (using Transients)
            if ( class_exists('CW_Core_Platform') && function_exists('wc_get_page_id') ) {
                 set_transient( 'cw_popup_msg_uid_' . $uid, $error_message, 60 );
                 set_transient( 'cw_popup_type_uid_' . $uid, 'error', 60 );
                 $my_account_page_url = get_permalink( wc_get_page_id( 'myaccount' ) );
                 wp_safe_redirect( add_query_arg( 'tab', 'campaigns', $my_account_page_url ) );
                 exit;
            }
            // Fallback for non-WC/non-Core context
            wp_die($error_message);
        }
        // --- END CRITICAL SECURITY FIX ---

        
        $sort_by    = sanitize_text_field($_GET['sort']  ?? 'date');
        $sort_order = sanitize_text_field($_GET['order'] ?? 'DESC');
        $per_page   = 9;
        $paged      = max(1, intval($_GET['entries_page'] ?? 1));

        $is_judged = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $campaign_id ) : true;
        if ( ! $is_judged && 'score' === $sort_by ) {
            $sort_by = 'date';
        }

        $entry_types = class_exists( 'CW_Shop' )
            ? CW_Shop::entry_post_types()
            : [ 'cw_competition_entry', 'cw_activity_entry' ];

        $args = [
            'post_type'      => $entry_types,
            'posts_per_page' => $per_page,
            'paged'          => $paged,
            'meta_query'     => [[
                'key'     => 'product_id',
                'value'   => $campaign_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ]],
            'orderby'        => 'date',
            'order'          => $sort_order,
            'no_found_rows'  => false,
        ];

        if ($sort_by === 'score') {
            $args['orderby']   = 'meta_value_num';
            $args['meta_key']  = 'judge_score';
            $args['meta_type'] = 'NUMERIC';
        }

        $entries_q     = new WP_Query( $args );
        $entries       = $entries_q->posts;
        $total_entries = (int) $entries_q->found_posts;
        $total_pages   = (int) $entries_q->max_num_pages;

        // Prime postmeta cache once so the per-entry get_post_meta calls below hit memory.
        if ( ! empty( $entries ) ) {
            $entry_ids = array_map( static fn( $e ) => (int) $e->ID, $entries );
            update_meta_cache( 'post', $entry_ids );
        }

        // Back-compat: code below uses $all_entries to detect "any entries exist at all".
        $all_entries = $total_entries > 0 ? $entries : [];

        $campaign_title      = get_the_title($campaign_id);
        $my_account_page_url = get_permalink(wc_get_page_id('myaccount'));
        $base_url            = add_query_arg(['tab' => 'manage_entries', 'campaign_id' => $campaign_id], $my_account_page_url);

        $sort_date_desc_link  = add_query_arg(['sort' => 'date',  'order' => 'DESC', 'entries_page' => 1], $base_url);
        $sort_date_asc_link   = add_query_arg(['sort' => 'date',  'order' => 'ASC',  'entries_page' => 1], $base_url);
        $sort_score_high_link = add_query_arg(['sort' => 'score', 'order' => 'DESC', 'entries_page' => 1], $base_url);
        $sort_score_low_link  = add_query_arg(['sort' => 'score', 'order' => 'ASC',  'entries_page' => 1], $base_url);
        
        
       
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">Manage Entries</h2>
                    <p><?php echo esc_html($campaign_title); ?></p>
                </div>
                <a href="<?php echo esc_url(add_query_arg('tab', 'campaigns', get_permalink(wc_get_page_id('myaccount')))); ?>" class="cw-btn-white small" style="text-decoration:none;"><i class="fas fa-arrow-left"></i> Back to Campaigns</a>
            </div>

            <?php if($all_entries): ?>
            <!-- Filter/Sort Bar -->
            <div class="cw-filter-bar" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:20px;">
                <div class="cwb-entry-count">
                    <i class="fas fa-users"></i>
                    <strong><?php echo $total_entries; ?></strong> entries found
                </div>
                <div style="display:flex;align-items:center;gap:8px;">
                    <label style="font-size:13px;color:#64748b;font-weight:600;white-space:nowrap;">Sort:</label>
                    <select class="cwb-search-input" onchange="window.location.href=this.value">
                        <option value="<?php echo esc_url($sort_date_desc_link); ?>"  <?php selected($sort_by==='date'&&$sort_order==='DESC', true); ?>>Latest First</option>
                        <option value="<?php echo esc_url($sort_date_asc_link); ?>"   <?php selected($sort_by==='date'&&$sort_order==='ASC',  true); ?>>Oldest First</option>
                        <?php if ( $is_judged ): ?>
                        <option value="<?php echo esc_url($sort_score_high_link); ?>" <?php selected($sort_by==='score'&&$sort_order==='DESC', true); ?>>Score: High → Low</option>
                        <option value="<?php echo esc_url($sort_score_low_link); ?>"  <?php selected($sort_by==='score'&&$sort_order==='ASC',  true); ?>>Score: Low → High</option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>

            <!-- Entries Grid -->
            <div class="cw-entry-management-grid">
                <?php foreach($entries as $entry): 
                    $name = get_post_meta($entry->ID, 'cw_participant_name', true);
                    $file_url = get_post_meta($entry->ID, 'upload_document', true);
                    $score = get_post_meta($entry->ID, 'judge_score', true) ?: '0';
                    $comment = get_post_meta($entry->ID, 'judge_comment', true) ?: '';
                    $entry_data = get_post_meta($entry->ID, 'participant_details', true);
                    $is_winner = get_post_meta($entry->ID, 'winner_status', true) === 'yes'; // CRITICAL: Retrieve winner 
                    
                    $img_display = '';
                    $file_class = 'file-icon';
                    $download_link = '';

                    if($file_url) {
                        $download_link = $file_url;
                        if(preg_match('/\.(jpg|jpeg|png|gif)$/i', $file_url)) {
                            $img_display = '<img src="'.esc_url($file_url).'" alt="Artwork Preview" loading="lazy" decoding="async">';
                            $file_class = 'image-preview';
                        } else {
                            $img_display = '<i class="fas fa-file-alt file-icon"></i>';
                            $file_class = 'document-preview';
                        }
                    } else {
                        $img_display = '<i class="fas fa-times-circle file-icon"></i>';
                        $file_class = 'no-file';
                    }
                    
                    $vote_count_val = (int) get_post_meta($entry->ID, 'vote_count', true);
                    $winner_rank_val = get_post_meta($entry->ID, 'winner_rank', true) ?: '';
                    // Create JSON object for modal
                    $entry_json = json_encode([
                        'id'           => $entry->ID,
                        'title'        => esc_html($entry->post_title),
                        'submitter'    => esc_html($name),
                        'submitter_id' => $entry->post_author,
                        'file_url'     => $file_url,
                        'score'        => $score,
                        'comment'      => $comment,
                        'html_details' => $this->format_entry_details_html($entry_data),
                        'is_winner'    => $is_winner,
                        'winner_rank'  => $winner_rank_val,
                        'vote_count'   => $vote_count_val,
                    ]);
                ?>
                <div class="cw-evaluation-card" data-entry-id="<?php echo $entry->ID; ?>" data-entry-json='<?php echo esc_attr($entry_json); ?>'>
                    <div class="cw-entry-preview <?php echo $file_class; ?>">
                        <?php echo $img_display; ?>
                        <?php if($download_link): ?>
                            <a href="<?php echo esc_url($download_link); ?>" target="_blank" class="cw-download-overlay" style="position:absolute; top:10px; right:10px; color:#fff; background:rgba(0,0,0,0.5); padding:5px 10px; border-radius:4px; font-size:12px;"><i class="fas fa-download"></i></a>
                        <?php endif; ?>
                    </div>
                    <div class="cw-entry-content">
                        <h4><?php echo esc_html($entry->post_title); ?></h4>
                        <div class="cw-entry-meta" style="display:flex; flex-direction:column; gap:4px;">
                            <span>By: <strong><?php echo esc_html($name); ?></strong></span>
                            <?php if ( $is_judged ): ?>
                            <span>Score: <strong><?php echo esc_html($score); ?></strong> / 100</span>
                            <?php endif; ?>
                        </div>
                        <?php
                        $vote_count = (int) get_post_meta($entry->ID, 'vote_count', true);
                        $rank_label = [
                            '1st' => ['label'=>'🥇 1st Place',  'color'=>'#b45309','bg'=>'#fef3c7','border'=>'#fde68a'],
                            '2nd' => ['label'=>'🥈 2nd Place',  'color'=>'#4b5563','bg'=>'#f3f4f6','border'=>'#d1d5db'],
                            '3rd' => ['label'=>'🥉 3rd Place',  'color'=>'#92400e','bg'=>'#fff7ed','border'=>'#fed7aa'],
                            'mention' => ['label'=>'⭐ Honorable Mention','color'=>'#1e40af','bg'=>'#eff6ff','border'=>'#bfdbfe'],
                        ];
                        ?>
                        <div style="display:flex; align-items:center; gap:8px; margin-top:8px; flex-wrap:wrap;">
                            <?php if ( $is_judged ): ?>
                                <span class="cwb-vote-badge"><i class="fas fa-heart"></i> <?php echo $vote_count; ?> votes</span>
                                <?php if ($is_winner && $winner_rank_val && isset($rank_label[$winner_rank_val])): $rl = $rank_label[$winner_rank_val]; ?>
                                <span style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;background:<?php echo $rl['bg']; ?>;color:<?php echo $rl['color']; ?>;border:1px solid <?php echo $rl['border']; ?>;"><?php echo $rl['label']; ?></span>
                                <?php elseif ($is_winner): ?>
                                <span style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">🏆 Winner</span>
                                <?php endif; ?>
                                <button class="cw-btn-primary small cw-open-eval-btn" style="flex:1; min-width:100px;">
                                    <i class="fas fa-pencil-alt"></i> Evaluate
                                </button>
                            <?php else: ?>
                                <span class="cw-status-badge cw-status-completed" style="font-size:11px;font-weight:700;padding:4px 10px;border-radius:999px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;">
                                    <i class="fas fa-check-circle"></i> Completed
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <div style="display:flex;align-items:center;justify-content:center;gap:6px;margin-top:24px;flex-wrap:wrap;">
                <?php if ($paged > 1): ?>
                <a href="<?php echo esc_url(add_query_arg(['sort'=>$sort_by,'order'=>$sort_order,'entries_page'=>$paged-1],$base_url)); ?>"
                   style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:var(--cw-bg);border:1.5px solid var(--cw-border);color:var(--cw-text);text-decoration:none;font-size:13px;transition:all .15s;"
                   onmouseover="this.style.background='var(--cw-primary-light)'" onmouseout="this.style.background='var(--cw-bg)'">
                   <i class="fas fa-chevron-left"></i></a>
                <?php endif; ?>
                <?php for ($pi = 1; $pi <= $total_pages; $pi++): ?>
                <a href="<?php echo esc_url(add_query_arg(['sort'=>$sort_by,'order'=>$sort_order,'entries_page'=>$pi],$base_url)); ?>"
                   style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;border:1.5px solid <?php echo $pi===$paged?'var(--cw-primary)':'var(--cw-border)'; ?>;background:<?php echo $pi===$paged?'var(--cw-primary)':'var(--cw-bg)'; ?>;color:<?php echo $pi===$paged?'#fff':'var(--cw-text)'; ?>;text-decoration:none;font-size:13px;font-weight:600;transition:all .15s;">
                   <?php echo $pi; ?></a>
                <?php endfor; ?>
                <?php if ($paged < $total_pages): ?>
                <a href="<?php echo esc_url(add_query_arg(['sort'=>$sort_by,'order'=>$sort_order,'entries_page'=>$paged+1],$base_url)); ?>"
                   style="display:inline-flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:50%;background:var(--cw-bg);border:1.5px solid var(--cw-border);color:var(--cw-text);text-decoration:none;font-size:13px;transition:all .15s;"
                   onmouseover="this.style.background='var(--cw-primary-light)'" onmouseout="this.style.background='var(--cw-bg)'">
                   <i class="fas fa-chevron-right"></i></a>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php else: ?>
                <div class="cw-empty-state">
                    <i class="fas fa-inbox"></i>
                    <p>No entries have been submitted for this campaign yet.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- ============================================================ -->
        <!--  EVALUATION MODAL — TWO-PANEL REDESIGN                     -->
        <!-- ============================================================ -->
        <div id="cw-evaluation-modal" class="cw-modal">
            <div class="cw-eval-box">

                <!-- Header bar -->
                <div class="cwb-eval-header">
                    <div class="cwb-eval-header-left">
                        <h3 id="eval-title">Entry Title</h3>
                        <p id="eval-submitter">Submitted by: —</p>
                    </div>
                    <button type="button" class="cwb-eval-close" onclick="closeEvaluationModal()">
                        <i class="fas fa-times"></i>
                    </button>
                </div>

                <!-- Two-panel body -->
                <div class="cwb-eval-grid">

                    <!-- LEFT: preview + participant details -->
                    <div class="cwb-eval-preview-panel">
                        <!-- Image or placeholder -->
                        <div id="eval-media-wrap" style="width:100%;"></div>

                        <!-- Download link -->
                        <a id="eval-file-link" href="#" target="_blank" class="cwb-eval-download" style="display:none;">
                            <i class="fas fa-download"></i> Download File
                        </a>

                        <!-- Vote count display -->
                        <div id="eval-vote-display" class="cwb-eval-vote-display" style="display:none;">
                            <i class="fas fa-heart"></i> <span id="eval-vote-num">0</span> votes
                        </div>

                        <!-- Participant details -->
                        <div class="cwb-eval-details" id="eval-details-wrap" style="display:none;">
                            <p style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.4px; color:#94a3b8; margin:0 0 8px;">Participant Details</p>
                            <div id="eval-details-content"></div>
                        </div>
                    </div>

                    <!-- RIGHT: scoring actions -->
                    <div class="cwb-eval-actions-panel">
                        <form id="cw-score-form">

                            <!-- Score -->
                            <div>
                                <p class="cwb-score-section-label">Score (0 – 100)</p>
                                <div class="cwb-score-slider-wrap">
                                    <input type="range" id="eval-score-slider" min="0" max="100" value="0" class="cwb-score-slider">
                                    <div class="cwb-score-input-row">
                                        <input type="number" id="eval-score" min="0" max="100" value="0" class="cwb-score-number">
                                        <button type="submit" class="cwb-save-btn">
                                            <i class="fas fa-save"></i> Save Score
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <hr class="cwb-eval-divider">

                            <!-- Winner toggle + rank -->
                            <div class="cwb-toggle-row">
                                <div class="cwb-toggle-row-label">
                                    Mark as Winner
                                    <small>Highlights this entry as a winning submission</small>
                                </div>
                                <label class="cwb-toggle-switch">
                                    <input type="checkbox" id="eval-winner-status" onchange="toggleWinnerRank(this)">
                                    <span class="cwb-toggle-track"></span>
                                </label>
                            </div>
                            <div id="eval-rank-row" style="display:none;padding:12px 0 4px;">
                                <p class="cwb-score-section-label">Rank / Position</p>
                                <select id="eval-winner-rank" style="width:100%;padding:10px 12px;border:1.5px solid var(--cw-border);border-radius:10px;font-size:14px;font-family:inherit;color:var(--cw-text);background:var(--cw-bg);">
                                    <option value="">— Select rank —</option>
                                    <option value="1st">🥇 1st Place</option>
                                    <option value="2nd">🥈 2nd Place</option>
                                    <option value="3rd">🥉 3rd Place</option>
                                    <option value="mention">⭐ Honorable Mention</option>
                                </select>
                            </div>

                            <hr class="cwb-eval-divider">

                            <!-- Judge comment -->
                            <div>
                                <p class="cwb-score-section-label">Judge Comment</p>
                                <textarea id="eval-comment" class="cwb-eval-textarea" placeholder="Write your feedback for this submission..."></textarea>
                            </div>

                        </form>
                    </div>

                </div><!-- .cwb-eval-grid -->
            </div><!-- .cw-eval-box -->
        </div><!-- #cw-evaluation-modal -->





        <!-- Evaluation Modal JS -->
        <script>
        let activeEntryData = null;

        function openEvaluationModal(data) {
            activeEntryData = data;

            // Header
            jQuery('#eval-title').text(data.title);
            jQuery('#eval-submitter').html(
                `Submitted by: <strong>${data.submitter}</strong>&nbsp;·&nbsp;Entry #${data.id}`
            );

            // Media preview
            const mediaWrap = jQuery('#eval-media-wrap');
            if (data.file_url && /\.(jpg|jpeg|png|gif|webp)$/i.test(data.file_url)) {
                mediaWrap.html(`<img src="${data.file_url}" class="cwb-eval-img" alt="Entry Preview">`);
            } else if (data.file_url) {
                mediaWrap.html(`<div class="cwb-eval-img-placeholder"><i class="fas fa-file-alt"></i><span>Document attached</span></div>`);
            } else {
                mediaWrap.html(`<div class="cwb-eval-img-placeholder"><i class="fas fa-image"></i><span>No file submitted</span></div>`);
            }

            // Download link
            if (data.file_url) {
                jQuery('#eval-file-link').attr('href', data.file_url).show();
            } else {
                jQuery('#eval-file-link').hide();
            }

            // Vote count
            if (data.vote_count !== undefined && data.vote_count !== null) {
                jQuery('#eval-vote-num').text(data.vote_count);
                jQuery('#eval-vote-display').show();
            } else {
                jQuery('#eval-vote-display').hide();
            }

            // Participant details — always visible in left panel
            if (data.html_details) {
                jQuery('#eval-details-content').html(data.html_details);
                jQuery('#eval-details-wrap').show();
            } else {
                jQuery('#eval-details-wrap').hide();
            }

            // Score
            const scoreVal = parseInt(data.score) || 0;
            jQuery('#eval-score').val(scoreVal);
            jQuery('#eval-score-slider').val(scoreVal);

            // Comment + winner + rank
            jQuery('#eval-comment').val(data.comment || '');
            jQuery('#eval-winner-status').prop('checked', !!data.is_winner);
            jQuery('#eval-winner-rank').val(data.winner_rank || '');
            jQuery('#eval-rank-row').css('display', data.is_winner ? 'block' : 'none');

            // Show modal
            jQuery('#cw-evaluation-modal').css('display', 'flex').fadeIn(200);
        }

        function closeEvaluationModal() {
            jQuery('#cw-evaluation-modal').fadeOut(200);
        }

        function toggleWinnerRank(chk) {
            document.getElementById('eval-rank-row').style.display = chk.checked ? 'block' : 'none';
        }

        jQuery(document).ready(function($) {

            // Sync slider ↔ number input
            $(document).on('input', '#eval-score-slider', function() {
                $('#eval-score').val(this.value);
            });
            $(document).on('input', '#eval-score', function() {
                const v = Math.min(100, Math.max(0, parseInt(this.value) || 0));
                $('#eval-score-slider').val(v);
            });

            // Open modal on card button click
            $('.cw-entry-management-grid').on('click', '.cw-open-eval-btn', function(e) {
                e.preventDefault();
                const card = $(this).closest('.cw-evaluation-card');
                const entryJson = card.data('entry-json');
                if (entryJson) openEvaluationModal(entryJson);
            });

            // Close on overlay click
            $(document).on('click', '#cw-evaluation-modal', function(e) {
                if ($(e.target).is('#cw-evaluation-modal')) closeEvaluationModal();
            });

            // Save score form
            $('#cw-score-form').on('submit', function(e) {
                e.preventDefault();
                if (!activeEntryData) return;

                const entryId    = activeEntryData.id;
                const score      = $('#eval-score').val();
                const comment    = $('#eval-comment').val();
                const isWinner   = $('#eval-winner-status').is(':checked') ? 'yes' : 'no';
                const winnerRank = $('#eval-winner-rank').val();
                const saveBtn    = $(this).find('.cwb-save-btn');

                saveBtn.prop('disabled', true).html('<i class="fas fa-spinner fa-spin"></i> Saving…');

                $.post(cw_vars.ajax_url, {
                    action: 'cw_save_score',
                    security: cw_vars.nonce,
                    entry_id: entryId,
                    score: score,
                    comment: comment,
                    winner_status: isWinner,
                    winner_rank: winnerRank
                }, function(res) {
                    saveBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Score');
                    if (res.success) {
                        Swal.fire({ icon: 'success', title: 'Saved!', text: 'Score & comment updated.', timer: 1200, showConfirmButton: false });
                        // Update card meta inline
                        $(`.cw-evaluation-card[data-entry-id="${entryId}"] .cw-entry-meta`).html(
                            `<span>By: <strong>${activeEntryData.submitter}</strong></span><span>Score: <strong>${score}</strong> / 100</span>`
                        );
                        activeEntryData.score   = score;
                        activeEntryData.comment = comment;
                        closeEvaluationModal();
                    } else {
                        Swal.fire('Error', res.data?.message || 'Failed to save.', 'error');
                    }
                }).fail(function() {
                    saveBtn.prop('disabled', false).html('<i class="fas fa-save"></i> Save Score');
                    Swal.fire('Error', 'Server connection failed.', 'error');
                });
            });
        });
        </script>
        <?php
    }
    
    
    
    public function handle_save_biz_info() { /* Managed in CW_Business, included for structure */ }
}