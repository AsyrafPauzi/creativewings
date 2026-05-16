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
        
        // Note: The save handler 'admin_post_cw_save_biz_info' is located in CW_Business class.
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

        // 2. Get Campaign Stats
        $campaigns = get_posts([
            'post_type'      => 'product', 
            'author'         => $uid, 
            'post_status'    => ['publish', 'pending', 'draft'], 
            'posts_per_page' => -1
        ]);
        
        $total_active  = 0;
        $total_pending = 0;
        $total_entries = 0;

        foreach ( $campaigns as $c ) {
            $status = get_post_status( $c->ID );
            if ( $status == 'publish' ) $total_active++;
            else $total_pending++;
        }

        // Total entries across all campaigns
        global $wpdb;
        if ( $campaigns ) {
            $pids = array_map( fn($c) => $c->ID, $campaigns );
            $placeholders = implode(',', array_fill(0, count($pids), '%d'));
            $total_entries = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = 'product_id' AND meta_value IN ($placeholders)",
                ...$pids
            ) );
        }

        // Base URL for links
        $base = get_permalink( wc_get_page_id( 'myaccount' ) );

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
                    <p>Manage your events, track earnings, and update your profile.</p>
                </div>
                <a href="<?php echo esc_url( add_query_arg('tab', 'campaigns', $base) ); ?>" class="cw-btn-primary" style="text-decoration:none;">
                    <i class="fas fa-plus"></i> Create Event
                </a>
            </div>

            <!-- STATS GRID (4 cols) -->
            <div class="cw-stats-grid cols-4">
                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Active Events</span>
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
                        <h3 class="cw-stat-value" style="font-size:22px;"><?php echo wc_price( $wallet['total_earned'] ); ?></h3>
                    </div>
                    <div class="cw-stat-icon-wrapper coral"><i class="fas fa-wallet"></i></div>
                </div>
            </div>

            <!-- SPLIT SECTION (Chart + Actions) -->
            <div class="cw-split-section">
                
                <!-- LEFT: REVENUE CHART -->
                <div class="cw-chart-container">
                    <div class="cw-chart-header">
                        <h3>Revenue Overview</h3>
                        <select class="cw-chart-filter"><option>Last 6 Months</option><option>This Year</option></select>
                    </div>
                    <div class="cw-chart-wrapper">
                        <canvas id="revenueChart"></canvas>
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
                                    <span>Launch a new event</span>
                                </div>
                            </div>
                            <i class="fas fa-arrow-right"></i>
                        </a>

                        <a href="<?php echo add_query_arg('tab', 'wallet', $base); ?>" class="cw-action-btn hover-blue">
                            <div class="cw-action-content">
                                <div class="cw-action-icon blue"><i class="fas fa-wallet"></i></div>
                                <div>
                                    <strong>Wallet</strong>
                                    <span>Check balance & payouts</span>
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

                    </div>
                </div>
            </div>

        </div>

        <!-- CHART JS CONFIG -->
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            const ctx = document.getElementById('revenueChart');
            if(ctx && typeof Chart !== 'undefined') {
                const ctx2d = ctx.getContext('2d');
                // Create Gradient
                let gradient = ctx2d.createLinearGradient(0, 0, 0, 400);
                gradient.addColorStop(0, 'rgba(15, 103, 150, 0.2)'); // Brand Blue transparent
                gradient.addColorStop(1, 'rgba(15, 103, 150, 0)');

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun'],
                        datasets: [{
                            label: 'Revenue',
                            data: [0, 0, 0, 0, 0, <?php echo floatval($wallet['total_earned']); ?>], // Populate with real historical data in V2
                            borderColor: '#0F6796', // Brand Blue
                            borderWidth: 3,
                            backgroundColor: gradient,
                            fill: true,
                            tension: 0.4, // Smooth curve
                            pointRadius: 3,
                            pointHoverRadius: 6
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false }, tooltip: { backgroundColor: '#fff', titleColor: '#000', bodyColor: '#0F6796', borderColor: '#e2e8f0', borderWidth: 1 } },
                        scales: {
                            y: { beginAtZero: true, grid: { borderDash: [5, 5], color: '#e2e8f0' }, ticks: { color: '#64748b' } },
                            x: { grid: { display: false }, ticks: { color: '#64748b' } }
                        }
                    }
                });
            }
        });
        </script>
        <?php
    }

    /* ==========================================================================
       2. CAMPAIGNS TAB (IMPLEMENTING NEW UI)
       ========================================================================== */
   public function render_campaigns() {
        $uid = get_current_user_id();
        $campaigns = get_posts([
            'post_type'      => 'product', 
            'author'         => $uid, 
            'post_status'    => ['publish', 'pending', 'draft'], 
            'posts_per_page' => -1
        ]);
        
        // --- FIX: Define base URLs using the safe ?tab=slug structure ---
        $my_account_page_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $base_campaigns_url = add_query_arg('tab', 'campaigns', $my_account_page_url); // For Edit links
        $manage_entries_url = add_query_arg('tab', 'manage_entries', $my_account_page_url); // For Manage Entries link

        
        $is_edit_mode = isset($_GET['edit_id']);
        $edit_id = $is_edit_mode ? intval($_GET['edit_id']) : 0;
        
        // Note: Success messages are handled by Transients/SweetAlert2 now
        if ( isset($_GET['saved']) ) echo '<div class="cw-alert success">Campaign saved successfully!</div>';
        if ( isset($_GET['campaign_created']) ) echo '<div class="cw-alert success">New Campaign created successfully!</div>';

        if ( current_user_can( 'manage_woocommerce' ) ) {
            echo '<p style="margin:0 0 16px;"><a class="button" href="' . esc_url( admin_url( 'admin.php?page=cw-import-campaign' ) ) . '">' . esc_html__( 'Import campaign from JSON (admin)', 'creativewings-core' ) . '</a></p>';
        }

        ?>
        <div class="cw-content-wrapper">
            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">My Campaigns</h2>
                    <p>Manage and track all your events</p>
                </div>
                <button type="button" class="cw-btn-primary" id="cw-open-create" style="text-decoration:none;">
                    <i class="fas fa-plus"></i> Create New Event
                </button>
            </div>

            <?php if ( $campaigns ): ?>
                <div class="cw-portfolio-grid-modern">
                    <?php foreach ( $campaigns as $c ):
                        $pid = $c->ID;
                        $status = get_post_status( $pid );
                        $status_label = ucfirst($status);

                        $pill_class = 'bg-gray';
                        if ($status == 'publish') $pill_class = 'bg-green';
                        if ($status == 'pending') $pill_class = 'bg-yellow';

                        $img = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';
                        $earnings = class_exists( 'CW_Wallet' ) ? CW_Wallet::get_product_earnings( $pid ) : 0;
                        global $wpdb;
                        $entries_count = (int) $wpdb->get_var( $wpdb->prepare(
                            "SELECT COUNT(meta_value) FROM {$wpdb->postmeta} WHERE meta_key = 'product_id' AND meta_value = %d", $pid
                        ) );

                        $is_competition = has_term('competitions', 'product_cat', $pid);
                        $is_talk        = has_term('talks', 'product_cat', $pid);
                        $cat_label      = $is_competition ? 'Competition' : ( $is_talk ? 'Talk/Seminar' : 'Activity' );
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
            <?php else: ?>
                <div class="cw-empty-state">
                    <i class="fas fa-bullhorn"></i>
                    <p>No campaigns yet. Create your first event to get started!</p>
                    <button type="button" class="cw-btn-primary" id="cw-open-create-empty" style="margin-top:14px;">
                        <i class="fas fa-plus"></i> Create Event
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

        // Messages
        if ( isset($_GET['requested']) ) echo '<div class="cw-alert success">Withdrawal request submitted.</div>';
        if ( isset($_GET['updated']) ) echo '<div class="cw-alert success">Bank details saved.</div>';

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
                        <h2 class="cw-stat-val text-white"><?php echo wc_price($wallet['total_earned']); ?></h2>
                        <div class="cw-mini-tag"><span class="dot green"></span> Gross Revenue</div>
                    </div>
                </div>

                <!-- Pending -->
                <div class="cw-stat-card">
                    <div>
                        <span class="cw-stat-label">Pending Clearance</span>
                        <h2 class="cw-stat-val text-yellow"><?php echo wc_price($wallet['pending']); ?></h2>
                        <div class="cw-stat-meta text-muted">Held funds</div>
                    </div>
                </div>

                <!-- Available -->
                <div class="cw-stat-card border-left">
                    <div>
                        <span class="cw-stat-label">Available Balance</span>
                        <h2 class="cw-stat-val text-blue"><?php echo wc_price($wallet['available']); ?></h2>
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
       4. SETTINGS TAB (Company Profile - New UI)
       ========================================================================== */
    public function render_settings() {
        $uid = get_current_user_id();
        $fields = ['business_name', 'business_phone', 'business_address', 'business_website', 'business_ssm'];
        $meta = []; 
        foreach($fields as $f) $meta[$f] = get_user_meta($uid, $f, true);
        
        $logo = get_user_meta($uid, 'business_logo', true);
        $logo_url = (is_array($logo) && isset($logo['url'])) ? $logo['url'] : '';

        if ( isset($_GET['updated']) ) echo '<div class="cw-alert success"><i class="fas fa-check-circle"></i> Profile updated successfully.</div>';
        ?>
        <div class="cw-content-wrapper">

            <div class="cw-dash-header">
                <div>
                    <h2 style="margin:0 0 4px;">Company Profile</h2>
                    <p>Update your organisation details and public information</p>
                </div>
            </div>

            <div class="cw-profile-card-ui">
                <!-- Banner Section -->
                <div class="cw-profile-banner"></div>
                
                <div class="cw-profile-body">
                    
                    <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="cw_save_biz_info">
                        <?php wp_nonce_field('cw_biz_info_nonce'); ?>

                        <!-- Avatar / Logo Section -->
                        <div class="cw-profile-avatar-wrap">
                            <div class="cw-avatar-circle">
                                <?php if($logo_url): ?>
                                    <img src="<?php echo esc_url($logo_url); ?>" id="cw-biz-logo-preview">
                                <?php else: ?>
                                    <div class="cw-avatar-placeholder"><i class="fas fa-building"></i></div>
                                <?php endif; ?>
                            </div>
                            <label for="biz_logo_input" class="cw-avatar-upload-btn">
                                <i class="fas fa-camera"></i>
                            </label>
                            <input type="file" id="biz_logo_input" name="business_logo" accept="image/*" style="display:none;" 
                                   onchange="if(this.files[0]) document.getElementById('cw-biz-logo-preview').src = window.URL.createObjectURL(this.files[0])">
                        </div>

                        <!-- Form Grid -->
                        <div class="cw-profile-grid-layout">
                            
                            <!-- Left Column -->
                            <div class="cw-col-left">
                                <div class="cw-field-dark">
                                    <label>Company Name</label>
                                    <input type="text" name="business_name" value="<?php echo esc_attr($meta['business_name']); ?>" placeholder="Company Name">
                                </div>
                                <div class="cw-field-dark">
                                    <label>SSM / Registration Number</label>
                                    <input type="text" name="business_ssm" value="<?php echo esc_attr($meta['business_ssm']); ?>" placeholder="2024010...">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Website</label>
                                    <input type="text" name="business_website" value="<?php echo esc_attr($meta['business_website']); ?>" placeholder="https://...">
                                </div>
                            </div>

                            <!-- Right Column -->
                            <div class="cw-col-right">
                                <div class="cw-field-dark">
                                    <label>Phone Number</label>
                                    <input type="text" name="business_phone" value="<?php echo esc_attr($meta['business_phone']); ?>" placeholder="+60...">
                                </div>
                                <div class="cw-field-dark">
                                    <label>Business Address</label>
                                    <textarea name="business_address" rows="5" placeholder="Enter address..."><?php echo esc_textarea($meta['business_address']); ?></textarea>
                                </div>
                            </div>

                        </div>

                        <div class="cw-profile-footer">
                            <button type="submit" class="cw-btn-primary">
                                <i class="far fa-save"></i> Save Changes
                            </button>
                        </div>

                    </form>
                </div>
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
        $campaign_author_id = get_post_field('post_author', $campaign_id);
        
        if ( !$campaign_author_id || (int)$campaign_author_id !== (int)$uid ) {
            
            if (!$campaign_author_id) {
                $error_message = 'Error: Campaign ID ' . $campaign_id . ' not found.';
            } else {
                $error_message = 'Access Denied: You do not own Campaign ID ' . $campaign_id;
            }
            
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

        $args = [
            'post_type'      => 'cw_competition_entry',
            'posts_per_page' => -1,
            'meta_query'     => [[
                'key'     => 'product_id',
                'value'   => $campaign_id,
                'compare' => '=',
                'type'    => 'NUMERIC',
            ]],
            'orderby' => 'date',
            'order'   => $sort_order,
        ];

        if ($sort_by === 'score') {
            $args['orderby']   = 'meta_value_num';
            $args['meta_key']  = 'judge_score';
            $args['meta_type'] = 'NUMERIC';
        }

        $all_entries  = get_posts($args);
        $total_entries = count($all_entries);
        $total_pages   = (int) ceil($total_entries / $per_page);
        $entries       = array_slice($all_entries, ($paged - 1) * $per_page, $per_page);

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
                        <option value="<?php echo esc_url($sort_score_high_link); ?>" <?php selected($sort_by==='score'&&$sort_order==='DESC', true); ?>>Score: High → Low</option>
                        <option value="<?php echo esc_url($sort_score_low_link); ?>"  <?php selected($sort_by==='score'&&$sort_order==='ASC',  true); ?>>Score: Low → High</option>
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
                            $img_display = '<img src="'.esc_url($file_url).'" alt="Artwork Preview">';
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
                            <span>Score: <strong><?php echo esc_html($score); ?></strong> / 100</span>
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
                            <span class="cwb-vote-badge"><i class="fas fa-heart"></i> <?php echo $vote_count; ?> votes</span>
                            <?php if ($is_winner && $winner_rank_val && isset($rank_label[$winner_rank_val])): $rl = $rank_label[$winner_rank_val]; ?>
                            <span style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;background:<?php echo $rl['bg']; ?>;color:<?php echo $rl['color']; ?>;border:1px solid <?php echo $rl['border']; ?>;"><?php echo $rl['label']; ?></span>
                            <?php elseif ($is_winner): ?>
                            <span style="font-size:11px;font-weight:700;padding:3px 8px;border-radius:999px;background:#fef3c7;color:#b45309;border:1px solid #fde68a;">🏆 Winner</span>
                            <?php endif; ?>
                            <button class="cw-btn-primary small cw-open-eval-btn" style="flex:1; min-width:100px;">
                                <i class="fas fa-pencil-alt"></i> Evaluate
                            </button>
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