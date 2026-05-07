<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Dashboard_Contestant {

    public function __construct() {
        // 1. Tab Content Injection
        add_action( 'woocommerce_account_cw-activities_endpoint', [ $this, 'render_activities' ] );
        add_action( 'woocommerce_account_cw-upgrade_endpoint', [ $this, 'render_upgrade' ] );
        add_action( 'woocommerce_account_cw-settings_endpoint', [ $this, 'render_settings' ] );

        // 2. Form Handlers
        add_action( 'admin_post_cw_save_contestant_settings', [ $this, 'handle_save_settings' ] ); // Corrected handler name
    }

    /* ==========================================================================
       1. OVERVIEW TAB (Fourth Screenshot - Nova UI)
       ========================================================================== */
    public function render_overview() {
        $uid = get_current_user_id();
        $u   = get_userdata( $uid );
        
        $entries = get_posts([ 'post_type' => ['cw_competition_entry', 'cw_activity_entry'], 'meta_key' => 'customer_id', 'meta_value' => $uid, 'posts_per_page' => -1 ]);
        $entries_count = count( $entries );
        
        // Count entries by status for Pending Review (A basic placeholder)
        $pending_review_count = 0;
        foreach($entries as $e) {
            if (get_post_meta($e->ID, 'judge_score', true) === '' && get_post_meta($e->ID, 'upload_document', true) !== '') {
                $pending_review_count++;
            }
        }
        
        // CRITICAL FIX: Base URL for View All Activities link
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $activities_url = add_query_arg( 'tab', 'activities', $base_url );
        $upgrade_url = add_query_arg( 'tab', 'upgrade', $base_url );

        ?>
        <div class="cw-content-wrapper">
             <div class="cw-dash-header">
                 <h2 style="font-size:32px; font-weight:800;">Welcome back, <?php echo esc_html($u->first_name ?: $u->display_name); ?></h2>
                 <p style="font-size:16px; color:#64748b;">Here's what's happening with your activities.</p>
                 <a href="<?php echo esc_url($activities_url); ?>" style="font-size:14px; color:var(--cw-primary); text-decoration:none;">View All Activities →</a>
             </div>
            
            <div class="cw-stats-container">
                <!-- Total Submissions -->
                <div class="cw-stat-box-v2">
                    <i class="fas fa-trophy" style="color:var(--cw-primary); font-size:24px;"></i>
                    <h3><?php echo number_format($entries_count); ?></h3>
                    <span>Total Submissions</span>
                </div>
                
                <!-- Pending Review -->
                <div class="cw-stat-box-v2">
                    <i class="fas fa-chart-line" style="color:var(--cw-warning); font-size:24px;"></i>
                    <h3><?php echo number_format($pending_review_count); ?></h3>
                    <span>Pending Review</span>
                </div>

                <!-- Upgrade Card -->
                <div class="cw-upgrade-card-host">
                    <h4>Looking to host?</h4>
                    <p>Upgrade to Creator to unlock full features.</p>
                    <a href="<?php echo esc_url($upgrade_url); ?>" class="cw-btn-upgrade">Upgrade Now <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- Recent Submissions Table (FIXED DATA LOOP) -->
            <h3 style="margin-top:40px; font-weight:700;">Recent Submissions</h3>
            <table class="cw-recent-table">
                <thead>
                    <tr>
                        <th>Activity Name</th>
                        <th>Type</th>
                        <th>Date</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $recent_entries = get_posts([ 'post_type' => ['cw_competition_entry', 'cw_activity_entry'], 'meta_key' => 'customer_id', 'meta_value' => $uid, 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC' ]);
                    foreach ($recent_entries as $e): 
                        $pid = get_post_meta( $e->ID, 'product_id', true );
                        $product = wc_get_product( $pid );
                        
                        if (!$product) continue;
                        
                        // Type Logic
                        $is_activity = has_term('activities', 'product_cat', $pid);
                        $type_tag = $is_activity ? 'Activity' : 'Competition';
                        
                        // Status Logic
                        $score = get_post_meta($e->ID, 'judge_score', true);
                        if ($score !== '' && $score > 0) {
                             $status_label = 'Completed';
                             $status_class = 'cw-status-completed';
                        } else {
                             // Assuming any entry without a score is pending if not explicitly completed
                             $status_label = 'Pending';
                             $status_class = 'cw-status-pending';
                        }

                    ?>
                    <tr>
                        <td><?php echo esc_html($product->get_name()); ?></td>
                        <td><span class="cw-status-badge cw-status-registered"><?php echo esc_html($type_tag); ?></span></td>
                        <td><?php echo get_the_date('Y-m-d', $e->ID); ?></td>
                        <td><span class="cw-status-badge <?php echo $status_class; ?>"><?php echo esc_html($status_label); ?></span></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    /* ==========================================================================
       2. ACTIVITIES TAB (Third Screenshot - Nova UI)
       ========================================================================== */
/* ==========================================================================
       2. ACTIVITIES TAB (Third Screenshot - Nova UI)
       ========================================================================== */
    public function render_activities() {
        $uid      = get_current_user_id();
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $act_url  = add_query_arg( 'tab', 'activities', $base_url );
        $paged    = isset( $_GET['cw_page'] ) ? max( 1, intval( $_GET['cw_page'] ) ) : 1;
        $per_page = 9;

        $base_args = [
            'post_type'  => ['cw_competition_entry', 'cw_activity_entry'],
            'meta_key'   => 'customer_id',
            'meta_value' => $uid,
            'orderby'    => 'date',
            'order'      => 'DESC',
        ];

        // Count for pagination
        $count_args                 = $base_args;
        $count_args['posts_per_page'] = -1;
        $count_args['fields']       = 'ids';
        $all_ids     = get_posts( $count_args );
        $total_items = count( $all_ids );
        $total_pages = (int) ceil( $total_items / $per_page );

        $base_args['posts_per_page'] = $per_page;
        $base_args['offset']         = ( $paged - 1 ) * $per_page;
        $entries = get_posts( $base_args );

        ?>
        <div class="cw-content-wrapper">
            <div class="cw-portfolio-header">
                <h2>My Activities</h2>
                <a href="<?php echo esc_url( home_url( '/competitions' ) ); ?>" class="cw-btn-primary" style="padding:10px 20px; font-size:14px;"><i class="fas fa-search"></i> Browse New Events</a>
            </div>

            <?php if ( $entries ): ?>
            <div class="cw-activities-grid">
                <?php foreach ( $entries as $e ):
                    $pid = get_post_meta( $e->ID, 'product_id', true );
                    $product = wc_get_product( $pid );
                    if ( ! $product ) continue;

                    $title         = $product->get_name();
                    $img           = get_the_post_thumbnail_url( $pid, 'medium' ) ?: CW_URL . 'assets/img/placeholder.jpg';
                    $date          = get_the_date( 'Y-m-d', $e->ID );
                    $score         = get_post_meta( $e->ID, 'judge_score', true );
                    $comment       = get_post_meta( $e->ID, 'judge_comment', true );
                    $entry_details = get_post_meta( $e->ID, 'participant_details', true );
                    $cert_enabled  = get_post_meta( $pid, 'cw_enable_certificate', true ) === 'yes';
                    $is_activity   = has_term( 'activities', 'product_cat', $pid );
                    $type_tag      = $is_activity ? 'Activity' : 'Competition';

                    if ( $score !== '' && $score > 0 ) {
                        $status_label = 'Completed'; $status_cls = 'completed';
                    } else {
                        $status_label = 'Registered'; $status_cls = 'registered';
                    }

                    $modal_data = htmlspecialchars( json_encode([
                        'id'           => $e->ID,
                        'title'        => $title,
                        'date'         => $date,
                        'status'       => $status_label,
                        'score'        => $score ?: 'N/A',
                        'comment'      => $comment ?: 'No feedback yet.',
                        'details'      => $entry_details ?: [],
                        'cert_enabled' => $cert_enabled,
                    ]), ENT_QUOTES, 'UTF-8' );
                ?>
                <div class="cw-activity-card">
                    <div class="cw-activity-image-wrap">
                        <img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $title ); ?>">
                        <span class="cw-activity-type-badge"><?php echo esc_html( $type_tag ); ?></span>
                    </div>
                    <div class="cw-activity-info">
                        <h4><?php echo esc_html( $title ); ?></h4>
                        <div class="cw-activity-meta">
                            <span><i class="fas fa-calendar-alt"></i> <?php echo esc_html( $date ); ?></span>
                        </div>
                        <div class="cw-activity-footer">
                            <span class="cw-status-badge <?php echo esc_attr( $status_cls ); ?>"><?php echo esc_html( $status_label ); ?></span>
                            <button class="cw-btn-details" onclick="openContestantModal('<?php echo $modal_data; ?>')">View Details</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>

            <?php $this->render_pagination( $paged, $total_pages, $act_url ); ?>

            <?php else: ?>
                <div class="cw-empty-state"><p>You haven't joined any activities yet.</p></div>
            <?php endif; ?>
        </div>
        <?php
        $this->render_activity_detail_modal();
    }  
    // Helper to format the entry details into a clean HTML table
    private function format_entry_details_html_contestant($details) {
        $html = '<table class="cw-detail-table">';
        if (is_array($details)) {
            foreach($details as $item) {
                if (isset($item['label']) && isset($item['value'])) {
                    $value = esc_html($item['value']);
                    // Check if the value is a file URL and replace with link
                    if (filter_var($item['value'], FILTER_VALIDATE_URL) && preg_match('/\.(jpg|jpeg|png|pdf|doc|docx)$/i', $item['value'])) {
                        $value = '<a href="'.esc_url($item['value']).'" target="_blank" style="color:var(--cw-primary); text-decoration:underline;">View File</a>';
                    }
                    $html .= '<tr><td>'.esc_html($item['label']).'</td><td>'.$value.'</td></tr>';
                }
            }
        }
        $html .= '</table>';
        return $html;
    }
    
    // Method to Render the Modal HTML and JS Logic
    private function render_activity_detail_modal() {
        ?>
        <!-- MODAL CSS/STRUCTURE -->
        <style>
            #cw-activity-detail-modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.6); align-items: center; justify-content: center; }
            .cw-detail-box { background: #fff; width: 90%; max-width: 650px; border-radius: 12px; position: relative; padding: 30px; }
            .cw-detail-header h3 { margin-top: 0; font-size: 24px; font-weight: 700; }
            .cw-detail-header p { font-size: 14px; color: #64748b; margin-top: 5px; }
            .cw-detail-close { position: absolute; top: 20px; right: 20px; font-size: 20px; cursor: pointer; color: #94a3b8; }
            
            /* Status/Score Cards */
            .cw-status-score-grid { display: flex; gap: 20px; margin-bottom: 30px; }
            .cw-status-card { flex: 1; padding: 20px; border-radius: 8px; }
            .cw-status-card.status { flex: 1; padding: 20px; border-radius: 8px; background: #e0f2fe; border: 1px solid #a7b7ff; }
            .cw-status-card.score { flex: 1; padding: 20px; border-radius: 8px; background: #fffbe6; border: 1px solid #fce88e; }
            .cw-status-card strong { display: block; font-size: 12px; color: #64748b; margin-bottom: 5px; }
            .cw-status-card .value { font-size: 24px; font-weight: 800; }

            /* Details Table */
            .cw-detail-table { width: 100%; border-collapse: collapse; margin-bottom: 20px; }
            .cw-detail-table tr { border-bottom: 1px solid #f1f5f9; }
            .cw-detail-table td { padding: 10px 0; font-size: 14px; }
            .cw-detail-table td:first-child { font-weight: 600; width: 40%; color: #475569; }

            /* Host Feedback */
            .cw-host-feedback { background: #f8fafc; padding: 20px; border-radius: 8px; margin-bottom: 30px; }
            .cw-host-feedback h4 { font-weight: 700; margin: 0 0 10px; }
        </style>
        
        <div id="cw-activity-detail-modal" class="cw-modal">
            <div class="cw-detail-box">
                <span class="cw-detail-close" onclick="closeContestantModal()"><i class="fas fa-times"></i></span>
                <div class="cw-detail-header">
                    <h3 id="modal-title">Spring Photography Challenge</h3>
                    <p id="modal-date">2023-10-15</p>
                </div>

                <div class="cw-status-score-grid">
                    <div class="cw-status-card status">
                        <strong>STATUS</strong>
                        <div class="value"><span id="modal-status-text">Completed</span> <i class="fas fa-check-circle" style="color:var(--cw-success);"></i></div>
                    </div>
                    <div class="cw-status-card score">
                        <strong>SCORE</strong>
                        <div class="value"><span id="modal-score">92</span> / 100 <i class="fas fa-trophy" style="color:var(--cw-warning);"></i></div>
                    </div>
                </div>
                
                <div id="modal-certificate-bar">
                    <!-- Certificate bar injected here -->
                </div>

                <h4 style="font-weight:700; margin-bottom:15px;"><i class="fas fa-file-invoice" style="margin-right:10px;"></i>Submission Details</h4>
                <div id="modal-details-table">
                    <!-- Dynamic table content injected here -->
                </div>
                
                <div id="modal-host-feedback">
                    <!-- Host feedback injected here -->
                </div>

                <button class="cw-btn-primary" style="float:right;" onclick="closeContestantModal()">Close</button>

            </div>
        </div>
        
        <!-- JAVASCRIPT LOGIC -->
        <script>
        function formatDetailsTable(details) {
            let tableHtml = '<table class="cw-detail-table">';
            if (Array.isArray(details)) {
                details.forEach(item => {
                    let value = item.value;
                    // Check for File/URL and replace with link
                    if (value && value.match(/\.(jpg|jpeg|png|pdf|doc|docx)$/i)) {
                        value = `<a href="${value}" target="_blank" style="color:var(--cw-primary); text-decoration:underline;">View File</a>`;
                    }
                    tableHtml += `<tr><td>${item.label}</td><td>${value}</td></tr>`;
                });
            }
            tableHtml += '</table>';
            return tableHtml;
        }

        function openContestantModal(modalDataJson) {
            const data = JSON.parse(modalDataJson);
            
            // Populate Header
            jQuery('#modal-title').text(data.title);
            jQuery('#modal-date').text(data.date);
            
            // Populate Status/Score
            const score = data.score === 'N/A' || data.score === '' ? 'N/A' : data.score;
            jQuery('#modal-status-text').text(data.status);
            jQuery('#modal-score').text(score);
            
            // Score card background (Completed/Pending)
            const scoreCard = jQuery('.cw-status-card.score');
            if(data.status === 'Completed') {
                scoreCard.css({backgroundColor: '#fffbe6', borderColor: '#fce88e'}); // Light yellow for score
            } else {
                scoreCard.css({backgroundColor: '#f1f5f9', borderColor: '#e2e8f0'}); // Grey for pending
            }
            
            // Certificate Bar Logic
            const certBar = jQuery('#modal-certificate-bar');
            
            // NOTE: Assuming cert_enabled is true if the campaign is completed
            if (data.status === 'Completed' && data.cert_enabled) { // Use data.cert_enabled if you passed it
                 certBar.html(`
                    <div style="background:#e6f3ff; border:1px solid #b3d9ff; padding:20px; border-radius:8px; display:flex; justify-content:space-between; align-items:center;">
                        <div>
                            <strong>Certificate Available</strong>
                            <p style="font-size:14px; margin:5px 0 0 0;">Great job! You can download your certificate of completion.</p>
                        </div>
                        <!-- FIX: Download Button - Link should point to the Certificate generation action -->
                         <a href="<?php echo admin_url('admin-post.php'); ?>?action=cw_download_cert&entry_id=${data.id}" class="cw-btn-primary" style="padding:10px 20px; font-size:14px;"><i class="fas fa-download"></i> Download</a>
                    </div>
                `);
            } else {
                certBar.empty();
            }


            // Populate Submission Details Table
            jQuery('#modal-details-table').html(formatDetailsTable(data.details));

            // Populate Host Feedback
            const feedbackContainer = jQuery('#modal-host-feedback');
            if (data.comment && data.comment !== 'No feedback yet.') {
                feedbackContainer.html(`
                    <div class="cw-host-feedback">
                        <h4 style="font-weight:700;">FEEDBACK FROM HOST</h4>
                        <p style="font-style:italic; margin:0;">"${data.comment}"</p>
                    </div>
                `);
            } else {
                feedbackContainer.empty();
            }

            // Show Modal
            jQuery('#cw-activity-detail-modal').css('display', 'flex').fadeIn(200);
        }

        function closeContestantModal() {
            jQuery('#cw-activity-detail-modal').fadeOut(200);
        }
        
        // --- NOTE: This is for the button to open the modal without relying on the onclick attribute ---
        jQuery(document).ready(function($) {
            // Re-bind the View Details button to open the modal
            $(document).on('click', '.cw-btn-details', function() {
                 const modalDataJson = $(this).attr('onclick').match(/openContestantModal\('(.*)'\)/);
                 if (modalDataJson && modalDataJson[1]) {
                     openContestantModal(modalDataJson[1]);
                 }
            });
        });
        </script>
        <?php
    }
    /* ==========================================================================
       3. UPGRADE TAB (Second Screenshot - Nova UI)
       ========================================================================== */
    public function render_upgrade() { 
        // Get URLs for the forms
        $creator_form_url = esc_url(admin_url('admin-post.php'));
        $business_form_url = esc_url(admin_url('admin-post.php'));
        
        // Get Nonces
        $creator_nonce = wp_nonce_field('cw_upgrade_creator_action', 'cw_nonce_creator', true, false);
        $business_nonce = wp_nonce_field('cw_upgrade_business_action', 'cw_nonce_business', true, false);

        echo '<div class="cw-content-wrapper">
                <div class="cw-dash-header" style="text-align:center;">
                    <h2 style="font-size:32px; font-weight:800;">Upgrade Your Experience</h2>
                    <p style="font-size:16px; color:#64748b;">Ready to do more than just participate? Unlock the ability to host competitions or partner with us for business growth.</p>
                </div>
              <div class="cw-upgrade-cards-v2">
              ';
              
        // --- CREATOR CARD ---
        echo '<div class="cw-role-upgrade-card creator">
                <i class="fas fa-shield-alt" style="font-size:30px; color:#7c3aed;"></i>
                <h3>Creator</h3>
                <p>For individuals who want to host challenges and build a following.</p>
                <ul class="cw-feature-list">
                   <li><i class="fas fa-check"></i> Upload Portfolio</li>
                        <li><i class="fas fa-check"></i> Public Profile</li>
                        <li><i class="fas fa-bolt"></i> Instant Approval</li>
                </ul>
                <form action="'.$creator_form_url.'" method="POST" style="width:100%;margin-top:auto;">
                    '.$creator_nonce.'
                    <input type="hidden" name="action" value="cw_become_creator">
                    <button type="submit" class="btn-creator-v2">Become a Creator</button>
                </form>
              </div>';
              
        // --- BUSINESS CARD ---
        echo '<div class="cw-role-upgrade-card business">
                <i class="fas fa-building" style="font-size:30px; color:#1e293b;"></i>
                <h3>Business Partner</h3>
                <p>For organizations needing advanced analytics and branding solutions.</p>
                <ul class="cw-feature-list">
                    <li><i class="fas fa-check"></i> Create Campaigns</li>
                        <li><i class="fas fa-check"></i> Manage Participants</li>
                        <li><i class="fas fa-clock"></i> Admin Approval Required</li>
                </ul>
                <form action="'.$business_form_url.'" method="POST" style="width:100%;margin-top:auto;">
                    '.$business_nonce.'
                    <input type="hidden" name="action" value="cw_become_business">
                    <button type="submit" class="btn-business-v2">Apply as Partner</button>
                </form>
              </div>';
              
        echo '</div></div>';
    }

    /* ==========================================================================
       4. SETTINGS TAB (First Screenshot - Nova UI)
       ========================================================================== */
    public function render_settings() {
        $u = wp_get_current_user();
        
        if ( isset($_GET['updated']) ) {
            echo '<div class="cw-alert success" style="margin-bottom:20px;">Profile updated successfully.</div>';
        }
        if ( isset($_GET['err']) ) {
            echo '<div class="cw-alert error" style="margin-bottom:20px;">Passwords did not match.</div>';
        }
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-auth-card" style="max-width:800px; margin:0 auto; padding:40px; border:1px solid #e2e8f0; border-radius:12px; box-shadow:0 4px 10px rgba(0,0,0,0.05);">
            <h2>Account Settings</h2>
            
            <form action="<?php echo admin_url('admin-post.php'); ?>" method="POST" class="cw-modern-form">
                <input type="hidden" name="action" value="cw_save_contestant_settings">
                <?php wp_nonce_field('cw_settings_nonce'); ?>
                
                <div class="cw-form-grid" style="grid-template-columns: 1fr 1fr; gap:20px;">
                    <div class="cw-field">
                        <label>First Name</label>
                        <input type="text" name="first_name" class="cw-input" value="<?php echo esc_attr($u->first_name); ?>" required>
                    </div>
                    <div class="cw-field">
                        <label>Last Name</label>
                        <input type="text" name="last_name" class="cw-input" value="<?php echo esc_attr($u->last_name); ?>" required>
                    </div>
                </div>
                
                <div class="cw-field full" style="margin-bottom:30px;">
                    <label>Display Name</label>
                    <input type="text" name="display_name" class="cw-input" value="<?php echo esc_attr($u->display_name); ?>">
                    <small style="font-size:12px; color:#64748b;">This is how you will appear on public certificates and leaderboards.</small>
                </div>

                <div class="cw-field full" style="margin-bottom:30px;">
                    <label>New Password</label>
                    <input type="password" name="pass1" class="cw-input" autocomplete="new-password" placeholder="Leave blank to keep current password">
                </div>
                
                <div class="cw-form-footer" style="border-top:none; padding-top:0;">
                    <button type="submit" class="cw-btn-primary" style="padding:12px 30px; font-size:16px;">Save Changes</button>
                </div>
            </form>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       5. HANDLER
       ========================================================================== */
    private function render_pagination( $current_page, $total_pages, $base_url ) {
        if ( $total_pages <= 1 ) return;
        ?>
        <nav class="cw-pagination-nav" aria-label="Pagination">
            <?php if ( $current_page > 1 ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page - 1, $base_url ) ); ?>" class="cw-page-btn prev"><i class="fas fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="cw-page-btn prev disabled"><i class="fas fa-chevron-left"></i></span>
            <?php endif; ?>
            <?php
            $range = 2; $start = max(1, $current_page - $range); $end = min($total_pages, $current_page + $range);
            if ($start > 1) { echo '<a href="'.esc_url(add_query_arg('cw_page', 1, $base_url)).'" class="cw-page-btn">1</a>'; if ($start > 2) echo '<span class="cw-page-ellipsis">…</span>'; }
            for ($i = $start; $i <= $end; $i++): $active = ($i === $current_page) ? 'active' : ''; ?>
                <a href="<?php echo esc_url(add_query_arg('cw_page', $i, $base_url)); ?>" class="cw-page-btn <?php echo $active; ?>" <?php echo $active ? 'aria-current="page"' : ''; ?>><?php echo $i; ?></a>
            <?php endfor;
            if ($end < $total_pages) { if ($end < $total_pages - 1) echo '<span class="cw-page-ellipsis">…</span>'; echo '<a href="'.esc_url(add_query_arg('cw_page', $total_pages, $base_url)).'" class="cw-page-btn">'.$total_pages.'</a>'; }
            ?>
            <?php if ( $current_page < $total_pages ): ?>
                <a href="<?php echo esc_url( add_query_arg( 'cw_page', $current_page + 1, $base_url ) ); ?>" class="cw-page-btn next"><i class="fas fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="cw-page-btn next disabled"><i class="fas fa-chevron-right"></i></span>
            <?php endif; ?>
        </nav>
        <?php
    }

    public function handle_save_settings() { 
        if ( ! is_user_logged_in() || ! isset($_POST['_wpnonce']) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cw_settings_nonce' ) ) {
            wp_die( 'Security Error' );
        }

        $uid = get_current_user_id();
        
        $userdata = [
            'ID'           => $uid,
            'first_name'   => sanitize_text_field( $_POST['first_name'] ),
            'last_name'    => sanitize_text_field( $_POST['last_name'] ),
            'display_name' => sanitize_text_field( $_POST['display_name'] ),
        ];
        
        wp_update_user( $userdata );

        if ( ! empty( $_POST['pass1'] ) ) {
            if ( $_POST['pass1'] === $_POST['pass2'] ) {
                wp_update_user([ 'ID' => $uid, 'user_pass' => $_POST['pass1'] ]);
                
                // Re-authenticate user so they aren't logged out
                $user = get_user_by( 'id', $uid );
                wp_signon([
                    'user_login'    => $user->user_login,
                    'user_password' => $_POST['pass1'],
                    'remember'      => true
                ]);
            } else {
                wp_safe_redirect( wc_get_account_endpoint_url('cw-settings') . '?err=mismatch' );
                exit;
            }
        }

        wp_safe_redirect( wc_get_account_endpoint_url('cw-settings') . '?updated=1' ); 
        exit; 
    }
}