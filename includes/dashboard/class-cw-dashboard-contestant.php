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
        
        // Count entries by status for Pending Review — competition campaigns only.
        $pending_review_count = 0;
        foreach($entries as $e) {
            $entry_pid = (int) get_post_meta( $e->ID, 'product_id', true );
            if ( class_exists( 'CW_Shop' ) && ! CW_Shop::campaign_is_judged( $entry_pid ) ) {
                continue;
            }
            if (get_post_meta($e->ID, 'judge_score', true) === '' && get_post_meta($e->ID, 'upload_document', true) !== '') {
                $pending_review_count++;
            }
        }
        
        // CRITICAL FIX: Base URL for View All Activities link
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $activities_url = add_query_arg( 'tab', 'activities', $base_url );
        $upgrade_url    = add_query_arg( 'tab', 'upgrade', $base_url );
        $explore_url    = add_query_arg( 'tab', 'explore', $base_url );

        $recent_entries = get_posts([ 'post_type' => ['cw_competition_entry', 'cw_activity_entry'], 'meta_key' => 'customer_id', 'meta_value' => $uid, 'posts_per_page' => 5, 'orderby' => 'date', 'order' => 'DESC' ]);

        // Build a usable list (skipping entries whose product was deleted)
        $renderable_recent = [];
        foreach ( $recent_entries as $e ) {
            $pid     = get_post_meta( $e->ID, 'product_id', true );
            $product = wc_get_product( $pid );
            if ( ! $product ) continue;

            $is_activity = has_term( 'activities', 'product_cat', $pid );
            $score       = get_post_meta( $e->ID, 'judge_score', true );
            $is_judged   = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : true;

            if ( ! $is_judged ) {
                $status_label = 'Completed';
                $status_class = 'cw-status-completed';
            } elseif ( $score !== '' && $score > 0 ) {
                $status_label = 'Completed';
                $status_class = 'cw-status-completed';
            } else {
                $status_label = 'Pending';
                $status_class = 'cw-status-pending';
            }

            $renderable_recent[] = [
                'entry'        => $e,
                'pid'          => $pid,
                'product'      => $product,
                'type_tag'     => $is_activity ? 'Activity' : 'Competition',
                'status_label' => $status_label,
                'status_class' => $status_class,
            ];
        }

        ?>
        <div class="cw-content-wrapper">
             <div class="cw-dash-header">
                 <h2>Welcome back, <?php echo esc_html($u->first_name ?: $u->display_name); ?></h2>
                 <p>Here's what's happening with your activities. <a href="<?php echo esc_url($activities_url); ?>" style="font-weight:600;color:var(--cw-primary);text-decoration:none;">View All Activities →</a></p>
             </div>

            <div class="cw-stats-container">
                <!-- Total Submissions -->
                <div class="cw-stat-box-v2">
                    <div class="cw-stat-value-row">
                        <div class="stat-icon blue"><i class="fas fa-trophy"></i></div>
                        <h3><?php echo number_format($entries_count); ?></h3>
                    </div>
                    <span>Total Submissions</span>
                </div>

                <!-- Pending Review -->
                <div class="cw-stat-box-v2">
                    <div class="cw-stat-value-row">
                        <div class="stat-icon yellow"><i class="fas fa-chart-line"></i></div>
                        <h3><?php echo number_format($pending_review_count); ?></h3>
                    </div>
                    <span>Pending Review</span>
                </div>

                <!-- Upgrade Card -->
                <div class="cw-upgrade-card-host">
                    <h4>Looking to host?</h4>
                    <p>Upgrade to Creator to unlock full features.</p>
                    <a href="<?php echo esc_url($upgrade_url); ?>" class="cw-btn-upgrade">Upgrade Now <i class="fas fa-arrow-right"></i></a>
                </div>
            </div>

            <!-- Recent Submissions -->
            <h3 class="cw-recent-heading">Recent Submissions</h3>

            <?php if ( ! empty( $renderable_recent ) ): ?>
                <div class="cw-recent-table-wrap">
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
                            <?php foreach ( $renderable_recent as $row ): ?>
                            <tr>
                                <td><?php echo esc_html( $row['product']->get_name() ); ?></td>
                                <td><span class="cw-status-badge cw-status-registered"><?php echo esc_html( $row['type_tag'] ); ?></span></td>
                                <td><?php echo get_the_date( 'Y-m-d', $row['entry']->ID ); ?></td>
                                <td><span class="cw-status-badge <?php echo esc_attr( $row['status_class'] ); ?>"><?php echo esc_html( $row['status_label'] ); ?></span></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="cw-recent-empty">
                    <div class="cw-recent-empty-icon"><i class="fas fa-rocket"></i></div>
                    <h4 class="cw-recent-empty-title"><?php esc_html_e( 'No activities yet', 'creativewings-core' ); ?></h4>
                    <p class="cw-recent-empty-desc"><?php esc_html_e( 'Join a competition or activity to see your submissions here. Browse all the latest opportunities in one place.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-recent-empty-btn">
                        <i class="fas fa-bolt"></i> <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public function render_link_submission() {
        if ( function_exists( 'cw_core' ) && cw_core()->claim_flow ) {
            cw_core()->claim_flow->render_endpoint();
            return;
        }
        echo '<p>' . esc_html__( 'Link submission is unavailable.', 'creativewings-core' ) . '</p>';
    }

    /* ==========================================================================
       2. ACTIVITIES TAB (Third Screenshot - Nova UI)
       ========================================================================== */
    public function render_activities() {
        $uid         = get_current_user_id();
        $base_url    = get_permalink( wc_get_page_id( 'myaccount' ) );
        $act_url     = add_query_arg( 'tab', 'activities', $base_url );
        $explore_url = add_query_arg( 'tab', 'explore', $base_url );
        $paged       = isset( $_GET['cw_page'] ) ? max( 1, intval( $_GET['cw_page'] ) ) : 1;
        $per_page    = 9;

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
            <div class="cw-portfolio-header cw-activities-header">
                <h2>My Activities</h2>
                <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-btn-primary cw-browse-new-btn"><i class="fas fa-bolt"></i> Browse Campaigns</a>
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
                    $is_judged     = class_exists( 'CW_Shop' ) ? CW_Shop::campaign_is_judged( $pid ) : true;

                    if ( ! $is_judged ) {
                        $status_label = 'Completed'; $status_cls = 'completed';
                    } elseif ( $score !== '' && $score > 0 ) {
                        $status_label = 'Completed'; $status_cls = 'completed';
                    } else {
                        $status_label = 'Registered'; $status_cls = 'registered';
                    }

                    $cert_available = $cert_enabled && class_exists( 'CW_Certificate' ) && CW_Certificate::entry_cert_available( $e->ID );
                    $cert_eta_raw   = get_post_meta( $pid, 'submission_deadline', true );
                    $cert_eta_label = $cert_eta_raw ? date_i18n( get_option( 'date_format', 'd M Y' ), strtotime( $cert_eta_raw ) ) : '';
                    $cert_url       = ( $cert_enabled && class_exists( 'CW_Certificate' ) ) ? CW_Certificate::download_url( $e->ID ) : '';

                    $modal_data = htmlspecialchars( json_encode([
                        'id'             => $e->ID,
                        'title'          => $title,
                        'date'           => $date,
                        'status'         => $status_label,
                        'score'          => $score ?: 'N/A',
                        'comment'        => $comment ?: 'No feedback yet.',
                        'details'        => $entry_details ?: [],
                        'cert_enabled'   => $cert_enabled,
                        'cert_available' => $cert_available,
                        'cert_eta'       => $cert_eta_label,
                        'cert_url'       => $cert_url,
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
                <div class="cw-recent-empty">
                    <div class="cw-recent-empty-icon"><i class="fas fa-rocket"></i></div>
                    <h4 class="cw-recent-empty-title"><?php esc_html_e( "You haven't joined any activities yet", 'creativewings-core' ); ?></h4>
                    <p class="cw-recent-empty-desc"><?php esc_html_e( 'Browse the latest competitions and activities to get started.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $explore_url ); ?>" class="cw-recent-empty-btn">
                        <i class="fas fa-bolt"></i> <?php esc_html_e( 'Explore Opportunities', 'creativewings-core' ); ?>
                    </a>
                </div>
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
            #cw-activity-detail-modal { display: none; position: fixed; inset: 0; z-index: 1000; background: rgba(0,0,0,0.6); align-items: center; justify-content: center; padding: 16px; }
            .cw-detail-box { background: #fff; width: 100%; max-width: 620px; max-height: calc(100vh - 32px); overflow-y: auto; border-radius: 12px; position: relative; padding: 20px 22px; }
            .cw-detail-header { padding-right: 32px; }
            .cw-detail-header h3 { margin-top: 0; margin-bottom: 4px; font-size: 18px; font-weight: 700; line-height: 1.3; }
            .cw-detail-header p { font-size: 12px; color: #64748b; margin: 0 0 16px; }
            .cw-detail-close { position: absolute; top: 12px; right: 14px; font-size: 18px; cursor: pointer; color: #94a3b8; line-height: 1; }

            /* Status/Score Cards — compact */
            .cw-status-score-grid { display: flex; gap: 10px; margin-bottom: 16px; }
            .cw-status-card { flex: 1; padding: 10px 12px; border-radius: 8px; min-width: 0; }
            .cw-status-card.status { background: #e0f2fe; border: 1px solid #a7b7ff; }
            .cw-status-card.score  { background: #fffbe6; border: 1px solid #fce88e; }
            .cw-status-card strong { display: block; font-size: 10px; color: #64748b; margin-bottom: 2px; letter-spacing: 0.4px; text-transform: uppercase; font-weight: 700; }
            .cw-status-card .value { font-size: 15px; font-weight: 700; line-height: 1.2; display: inline-flex; align-items: center; gap: 6px; flex-wrap: wrap; }
            .cw-status-card .value i { font-size: 14px; }

            /* Details Table */
            #modal-details-table { margin-bottom: 16px; }
            .cw-detail-table { width: 100%; border-collapse: collapse; }
            .cw-detail-table tr { border-bottom: 1px solid #f1f5f9; }
            .cw-detail-table td { padding: 7px 8px; font-size: 13px; vertical-align: top; }
            .cw-detail-table td:first-child { font-weight: 600; width: 38%; color: #475569; }

            /* Submission Details heading */
            .cw-submission-heading { font-weight: 700; font-size: 14px; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }

            /* Host Feedback */
            .cw-host-feedback { background: #f8fafc; padding: 12px 14px; border-radius: 8px; margin-bottom: 16px; font-size: 13px; }
            .cw-host-feedback h4 { font-weight: 700; margin: 0 0 6px; font-size: 13px; }

            @media (max-width: 480px) {
                .cw-detail-box { padding: 16px; }
                .cw-status-score-grid { flex-direction: column; gap: 8px; }
                .cw-status-card { padding: 10px 12px; }
                .cw-status-card .value { font-size: 14px; }
                .cw-detail-header h3 { font-size: 16px; }
                .cw-detail-table td { padding: 7px 6px; font-size: 12px; }
            }
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

                <h4 class="cw-submission-heading"><i class="fas fa-file-invoice"></i>Submission Details</h4>
                <div id="modal-details-table">
                    <!-- Dynamic table content injected here -->
                </div>
                
                <div id="modal-host-feedback">
                    <!-- Host feedback injected here -->
                </div>

                <div style="display:flex; justify-content:flex-end; margin-top:8px;">
                    <button class="cw-btn-primary" style="padding:8px 18px; font-size:13px;" onclick="closeContestantModal()">Close</button>
                </div>

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

            if (data.cert_enabled && data.cert_available && data.cert_url) {
                certBar.html(`
                    <div style="background:#e6f3ff; border:1px solid #b3d9ff; padding:12px 14px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                        <div style="min-width:0; flex:1 1 200px;">
                            <strong style="font-size:13px;">Certificate Available</strong>
                            <p style="font-size:12px; margin:2px 0 0; color:#475569;">Download your certificate of completion.</p>
                        </div>
                        <a href="${data.cert_url}" class="cw-btn-primary" style="padding:7px 14px; font-size:12px;" target="_blank" rel="noopener"><i class="fas fa-download"></i> Download</a>
                    </div>
                `);
            } else if (data.cert_enabled && data.cert_eta) {
                certBar.html(`
                    <div style="background:#fff7ed; border:1px solid #fed7aa; padding:12px 14px; border-radius:8px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px;">
                        <div style="min-width:0; flex:1 1 200px;">
                            <strong style="font-size:13px;">Certificate Coming Soon</strong>
                            <p style="font-size:12px; margin:2px 0 0; color:#475569;">Available after the event ends on <strong>${data.cert_eta}</strong>.</p>
                        </div>
                        <button class="cw-btn-disabled" style="padding:7px 14px; font-size:12px;" disabled><i class="fas fa-clock"></i> Not yet available</button>
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
       3. UPGRADE TAB — mirrors the public Get Started page
       ========================================================================== */
    public function render_upgrade() {
        $uid          = get_current_user_id();
        $user         = wp_get_current_user();
        $base_url     = get_permalink( wc_get_page_id( 'myaccount' ) );
        $overview_url = add_query_arg( 'tab', 'overview', $base_url );
        $admin_post   = esc_url( admin_url( 'admin-post.php' ) );

        // Already a Creator -> show a success card with a link back to the dashboard.
        if ( in_array( 'creator_role', (array) $user->roles, true ) ) {
            ?>
            <div class="cw-content-wrapper">
                <div class="cw-onboard-card" style="text-align:center;padding:60px 20px;max-width:600px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,0.05);">
                    <i class="fa-solid fa-circle-check" style="font-size:60px;color:#22c55e;margin-bottom:25px;"></i>
                    <h2 style="margin:0 0 10px;"><?php esc_html_e( 'You are already a Creator', 'creativewings-core' ); ?></h2>
                    <p style="color:#64748b;margin:0 0 25px;"><?php esc_html_e( 'Your account already has Creator privileges.', 'creativewings-core' ); ?></p>
                    <a href="<?php echo esc_url( $overview_url ); ?>" class="cw-btn-primary" style="padding:12px 28px;font-size:15px;"><?php esc_html_e( 'Go to Dashboard', 'creativewings-core' ); ?></a>
                </div>
            </div>
            <?php
            return;
        }

        // Pending Business Partner application -> show the same pending card markup the onboarding page uses.
        $biz_status = get_user_meta( $uid, 'cw_business_application_status', true );
        if ( $biz_status === 'pending' ) {
            ?>
            <div class="cw-content-wrapper">
                <div class="cw-onboard-card" style="text-align:center;padding:60px 20px;max-width:600px;margin:40px auto;background:#fff;border-radius:12px;box-shadow:0 5px 20px rgba(0,0,0,0.05);">
                    <i class="fa-solid fa-clock" style="font-size:60px;color:#f39c12;margin-bottom:25px;"></i>
                    <h2 style="margin:0 0 10px;"><?php esc_html_e( 'Application Pending', 'creativewings-core' ); ?></h2>
                    <p style="color:#64748b;margin:0;"><?php esc_html_e( 'Your request to become a Business Partner is under review. Please wait for approval.', 'creativewings-core' ); ?></p>
                </div>
            </div>
            <?php
            return;
        }

        if ( isset( $_GET['upgrade_error'] ) && $_GET['upgrade_error'] === 'security' ) {
            echo '<div class="cw-alert error" style="max-width:820px;margin:0 auto 20px;">' . esc_html__( 'Security check failed. Please try again.', 'creativewings-core' ) . '</div>';
        }
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-onboarding-wrapper">
                <h2><?php esc_html_e( 'Upgrade your account', 'creativewings-core' ); ?></h2>
                <p class="cw-subtext"><?php esc_html_e( 'Select an account type to unlock more features.', 'creativewings-core' ); ?></p>

                <div class="cw-role-cards">

                    <!-- CREATOR CARD -->
                    <div class="cw-role-card">
                        <div class="icon"><i class="fas fa-palette"></i></div>
                        <h3><?php esc_html_e( 'Creator', 'creativewings-core' ); ?></h3>
                        <p><?php esc_html_e( 'Build your portfolio, showcase your art, and join creative contests.', 'creativewings-core' ); ?></p>
                        <ul class="cw-features">
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Upload Portfolio', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Public Profile', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-bolt"></i> <?php esc_html_e( 'Instant Approval', 'creativewings-core' ); ?></li>
                        </ul>
                        <form action="<?php echo $admin_post; ?>" method="POST" style="width:100%;margin-top:auto;">
                            <?php wp_nonce_field( 'cw_upgrade_creator_action', 'cw_nonce' ); ?>
                            <input type="hidden" name="action" value="cw_become_creator">
                            <button type="submit" class="btn-creator"><?php esc_html_e( 'Select Creator', 'creativewings-core' ); ?></button>
                        </form>
                    </div>

                    <!-- BUSINESS CARD -->
                    <div class="cw-role-card">
                        <div class="icon"><i class="fas fa-briefcase"></i></div>
                        <h3><?php esc_html_e( 'Business Partner', 'creativewings-core' ); ?></h3>
                        <p><?php esc_html_e( 'Organize tournaments, create campaigns, and manage participants.', 'creativewings-core' ); ?></p>
                        <ul class="cw-features">
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Create Campaigns', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-check"></i> <?php esc_html_e( 'Manage Participants', 'creativewings-core' ); ?></li>
                            <li><i class="fas fa-clock"></i> <?php esc_html_e( 'Admin Approval Required', 'creativewings-core' ); ?></li>
                        </ul>
                        <form action="<?php echo $admin_post; ?>" method="POST" style="width:100%;margin-top:auto;">
                            <?php wp_nonce_field( 'cw_upgrade_business_action', 'cw_nonce' ); ?>
                            <input type="hidden" name="action" value="cw_become_business">
                            <button type="submit" class="btn-business"><?php esc_html_e( 'Apply as Business', 'creativewings-core' ); ?></button>
                        </form>
                    </div>

                </div>
            </div>
        </div>
        <?php
    }

    /* ==========================================================================
       4. SETTINGS TAB
       ========================================================================== */
    public function render_settings() {
        $u   = wp_get_current_user();
        $uid = $u->ID;
        $dob = get_user_meta( $uid, 'birthdate', true );

        $error_messages = [
            'mismatch'    => __( 'The new passwords you entered do not match. Please try again.', 'creativewings-core' ),
            'invalid_dob' => __( 'That date of birth doesn\'t look right. Please use the format dd/mm/yyyy.', 'creativewings-core' ),
        ];
        $err_key = isset( $_GET['err'] ) ? sanitize_key( wp_unslash( $_GET['err'] ) ) : '';
        ?>
        <div class="cw-content-wrapper">
            <div class="cw-settings-card">

                <div class="cw-settings-header">
                    <h2><?php esc_html_e( 'Account Settings', 'creativewings-core' ); ?></h2>
                    <p class="cw-settings-subtitle"><?php esc_html_e( 'Update your personal details and account security. These details appear on certificates and leaderboards.', 'creativewings-core' ); ?></p>
                </div>

                <?php if ( isset( $_GET['updated'] ) ) : ?>
                    <div class="cw-alert success cw-settings-alert"><i class="fas fa-check-circle"></i> <?php esc_html_e( 'Your profile has been updated.', 'creativewings-core' ); ?></div>
                <?php endif; ?>
                <?php if ( $err_key && isset( $error_messages[ $err_key ] ) ) : ?>
                    <div class="cw-alert error cw-settings-alert"><i class="fas fa-exclamation-circle"></i> <?php echo esc_html( $error_messages[ $err_key ] ); ?></div>
                <?php endif; ?>

                <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST" class="cw-settings-form" autocomplete="off">
                    <input type="hidden" name="action" value="cw_save_contestant_settings">
                    <?php wp_nonce_field( 'cw_settings_nonce' ); ?>

                    <section class="cw-form-section">
                        <h3><?php esc_html_e( 'Personal Information', 'creativewings-core' ); ?></h3>

                        <div class="cw-settings-name-grid">
                            <div class="cw-field">
                                <label for="cw-settings-first-name"><?php esc_html_e( 'First Name', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-settings-first-name" name="first_name" class="cw-input" value="<?php echo esc_attr( $u->first_name ); ?>" required>
                            </div>
                            <div class="cw-field">
                                <label for="cw-settings-last-name"><?php esc_html_e( 'Last Name', 'creativewings-core' ); ?></label>
                                <input type="text" id="cw-settings-last-name" name="last_name" class="cw-input" value="<?php echo esc_attr( $u->last_name ); ?>" required>
                            </div>
                        </div>

                        <div class="cw-field">
                            <label for="cw-settings-display-name"><?php esc_html_e( 'Display Name', 'creativewings-core' ); ?></label>
                            <input type="text" id="cw-settings-display-name" name="display_name" class="cw-input" value="<?php echo esc_attr( $u->display_name ); ?>">
                            <small><?php esc_html_e( 'This is how you will appear on public certificates and leaderboards.', 'creativewings-core' ); ?></small>
                        </div>

                        <div class="cw-field">
                            <label for="birthdate"><?php esc_html_e( 'Date of Birth', 'creativewings-core' ); ?></label>
                            <input type="text" id="birthdate" name="birthdate" class="cw-input cw-datepicker" value="<?php echo esc_attr( $dob ); ?>" placeholder="dd/mm/yyyy" readonly autocomplete="bday">
                            <small><?php esc_html_e( 'Format: dd/mm/yyyy. Tap the field to pick a date.', 'creativewings-core' ); ?></small>
                        </div>
                    </section>

                    <section class="cw-form-section">
                        <h3><?php esc_html_e( 'Security', 'creativewings-core' ); ?></h3>

                        <div class="cw-field">
                            <label for="cw-settings-pass1"><?php esc_html_e( 'New Password', 'creativewings-core' ); ?></label>
                            <input type="password" id="cw-settings-pass1" name="pass1" class="cw-input" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Leave blank to keep current password', 'creativewings-core' ); ?>">
                            <small><?php esc_html_e( 'Use at least 8 characters with a mix of letters, numbers, and symbols.', 'creativewings-core' ); ?></small>
                        </div>

                        <div class="cw-field">
                            <label for="cw-settings-pass2"><?php esc_html_e( 'Confirm New Password', 'creativewings-core' ); ?></label>
                            <input type="password" id="cw-settings-pass2" name="pass2" class="cw-input" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Re-enter the new password', 'creativewings-core' ); ?>">
                        </div>
                    </section>

                    <div class="cw-form-footer">
                        <button type="submit" class="cw-btn-primary cw-settings-save-btn"><i class="fas fa-save"></i> <?php esc_html_e( 'Save Changes', 'creativewings-core' ); ?></button>
                    </div>
                </form>
            </div>
        </div>

        <script>
        // Safety-net datepicker init: the global script in assets/js/cw-script.js
        // already binds jQuery UI datepicker to #birthdate with dd/mm/yy format.
        // This guarded fallback runs only if the global init hasn't bound yet
        // (e.g. if script order ever changes), to keep the picker working.
        (function () {
            if ( typeof jQuery === 'undefined' ) { return; }
            jQuery(function ($) {
                if ( ! $.fn || ! $.fn.datepicker ) { return; }
                var $field = $('input.cw-datepicker#birthdate');
                if ( ! $field.length || $field.hasClass('hasDatepicker') ) { return; }
                $field.datepicker({
                    changeMonth: true,
                    changeYear: true,
                    yearRange: 'c-90:c-13',
                    dateFormat: 'dd/mm/yy',
                    maxDate: 0
                });
            });
        })();
        </script>
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
        if ( ! is_user_logged_in() || ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( $_POST['_wpnonce'], 'cw_settings_nonce' ) ) {
            wp_die( 'Security Error' );
        }

        $uid          = get_current_user_id();
        $base_url     = get_permalink( wc_get_page_id( 'myaccount' ) );
        $settings_url = add_query_arg( 'tab', 'settings', $base_url );

        // --- Validate Date of Birth (optional, but if provided must match dd/mm/yyyy
        //     to stay consistent with how 'birthdate' is stored elsewhere in the plugin
        //     — see CW_Auth::process_complete_profile() and the registration flow.)
        $dob_raw = isset( $_POST['birthdate'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['birthdate'] ) ) ) : '';
        if ( $dob_raw !== '' && ! preg_match( '#^\d{2}/\d{2}/\d{4}$#', $dob_raw ) ) {
            wp_safe_redirect( add_query_arg( 'err', 'invalid_dob', $settings_url ) );
            exit;
        }

        $userdata = [
            'ID'           => $uid,
            'first_name'   => sanitize_text_field( wp_unslash( $_POST['first_name'] ?? '' ) ),
            'last_name'    => sanitize_text_field( wp_unslash( $_POST['last_name'] ?? '' ) ),
            'display_name' => sanitize_text_field( wp_unslash( $_POST['display_name'] ?? '' ) ),
        ];

        wp_update_user( $userdata );

        if ( isset( $_POST['birthdate'] ) ) {
            update_user_meta( $uid, 'birthdate', $dob_raw );
        }

        if ( ! empty( $_POST['pass1'] ) ) {
            if ( $_POST['pass1'] === ( $_POST['pass2'] ?? '' ) ) {
                wp_update_user([ 'ID' => $uid, 'user_pass' => $_POST['pass1'] ]);

                // Re-authenticate user so they aren't logged out
                $user = get_user_by( 'id', $uid );
                wp_signon([
                    'user_login'    => $user->user_login,
                    'user_password' => $_POST['pass1'],
                    'remember'      => true,
                ]);
            } else {
                wp_safe_redirect( add_query_arg( 'err', 'mismatch', $settings_url ) );
                exit;
            }
        }

        wp_safe_redirect( add_query_arg( 'updated', '1', $settings_url ) );
        exit;
    }
}