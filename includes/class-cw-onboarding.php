<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// CRITICAL FIX: Ensure core functions like update_user_meta and wp_redirect are available.
if ( ! function_exists( 'wp_safe_redirect' ) ) {
    require_once( ABSPATH . 'wp-includes/pluggable.php' );
}

class CW_Onboarding {

    public function __construct() {
        // Front-end Shortcodes
        add_shortcode('cw_role_selection', [ $this, 'render_selection_page' ]);
        
        // Front-end Form Handlers
        add_action('admin_post_cw_become_creator', [ $this, 'process_creator_upgrade' ]);
        add_action('admin_post_cw_become_business', [ $this, 'process_business_request' ]);
        add_action( 'admin_post_cw_skip_onboarding', [ $this, 'process_skip' ] ); 
        
        // Admin UI: Notices & Columns
        add_action('admin_notices', [ $this, 'admin_pending_requests_notice' ]);
        add_action('admin_notices', [ $this, 'admin_action_feedback_notice' ]); 

        // Admin User List Customization
        add_filter( 'manage_users_columns', [ $this, 'add_business_column' ] );
        add_filter( 'manage_users_custom_column', [ $this, 'show_business_column_content' ], 10, 3 );
        
        // Admin Actions (Approve/Reject)
        add_action( 'admin_post_cw_approve_biz', [ $this, 'process_admin_approve' ] );
        add_action( 'admin_post_cw_reject_biz', [ $this, 'process_admin_reject' ] );
    }

    /* ==========================================================================
       FRONT-END SELECTION PAGE (Nova UI Structure)
       ========================================================================== */
    public function render_selection_page() {
        if ( ! is_user_logged_in() ) {
            return '<script>window.location.href="' . home_url('/login') . '";</script>';
        }
        
        $user = wp_get_current_user();
        
        // 1. Check existing roles to prevent redundancy
        if ( in_array( 'business_role', (array) $user->roles ) ) {
            return '<div class="cw-msg success"><h2>You are a Business Partner!</h2><a href="'.home_url('/my-account').'" class="cw-btn">Go to Dashboard</a></div>';
        }
        
        if ( in_array( 'creator_role', (array) $user->roles ) ) {
             return '<div class="cw-msg success"><h2>You are a Creator!</h2><a href="'.home_url('/my-account').'" class="cw-btn">Go to Dashboard</a></div>';
        }
        
        // 2. Pending Application Check
        $status = get_user_meta( $user->ID, 'cw_business_application_status', true );
        if ( $status === 'pending' ) {
            return '<div class="cw-onboard-card" style="text-align:center;padding:60px 20px;max-width:600px;margin:0 auto; background:#fff; border-radius:8px; box-shadow:0 5px 20px rgba(0,0,0,0.05);">
                <i class="fa-solid fa-clock" style="font-size:60px;color:#f39c12;margin-bottom:25px;"></i>
                <h2>Application Pending</h2>
                <p>Your request to become a Business Partner is under review. Please wait for approval.</p>
                <div style="margin-top:20px;">
                    <a href="'.wp_logout_url(home_url()).'" class="cw-btn-link">Log Out</a>
                </div>
            </div>';
        }
        
        // Display Nonce Error from failed attempt if present
        if (isset($_GET['upgrade_error']) && $_GET['upgrade_error'] === 'security') {
            echo '<div class="cw-alert error" style="margin-bottom:20px;">Security check failed. Please try again.</div>';
        }

        ob_start();
        ?>
        <div class="cw-onboarding-wrapper">
            <h2>Get Started</h2>
            <p class="cw-subtext">Select your account type to continue. This will determine your features.</p>
            
            <div class="cw-role-cards">
                
                <!-- CREATOR CARD -->
                <div class="cw-role-card">
                    <div class="icon"><i class="fas fa-palette"></i></div>
                    <h3>Creator</h3>
                    <p>Build your portfolio, showcase your art, and join creative contests.</p>
                    <ul class="cw-features">
                        <li><i class="fas fa-check"></i> Upload Portfolio</li>
                        <li><i class="fas fa-check"></i> Public Profile</li>
                        <li><i class="fas fa-bolt"></i> Instant Approval</li>
                    </ul>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST" style="width:100%;margin-top:auto;">
                        <?php wp_nonce_field('cw_upgrade_creator_action', 'cw_nonce'); ?>
                        <input type="hidden" name="action" value="cw_become_creator">
                        <button type="submit" class="btn-creator">Select Creator</button>
                    </form>
                </div>

                <!-- BUSINESS CARD -->
                <div class="cw-role-card">
                    <div class="icon"><i class="fas fa-briefcase"></i></div>
                    <h3>Business Partner</h3>
                    <p>Organize tournaments, create campaigns, and manage participants.</p>
                    <ul class="cw-features">
                        <li><i class="fas fa-check"></i> Create Campaigns</li>
                        <li><i class="fas fa-check"></i> Manage Participants</li>
                        <li><i class="fas fa-clock"></i> Admin Approval Required</li>
                    </ul>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST" style="width:100%;margin-top:auto;">
                        <?php wp_nonce_field('cw_upgrade_business_action', 'cw_nonce'); ?>
                        <input type="hidden" name="action" value="cw_become_business">
                        <button type="submit" class="btn-business">Apply as Business</button>
                    </form>
                </div>

            </div>
            
            <!-- NEW: SKIP BUTTON -->
            <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST" style="width:100%; margin-top:30px;">
                <?php wp_nonce_field('cw_skip_onboarding_action', 'cw_nonce_skip'); ?>
                <input type="hidden" name="action" value="cw_skip_onboarding">
                <button type="submit" class="cw-btn-link" style="background:none; border:none; color:#64748b; font-size:16px;">Skip for now, continue as Contestant</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /* ==========================================================================
       FRONT-END ACTION HANDLERS
       ========================================================================== */
    public function process_creator_upgrade() {
        if (!isset($_POST['cw_nonce']) || !wp_verify_nonce($_POST['cw_nonce'], 'cw_upgrade_creator_action')) {
            wp_safe_redirect( add_query_arg('upgrade_error', 'security', wp_get_referer()) );
            exit;
        }
        
        if (!is_user_logged_in()) wp_die('Please login');

        $user = wp_get_current_user();
        
        $user->set_role('creator_role'); 
        
        update_user_meta($user->ID, 'account_type', 'creator');
        update_user_meta($user->ID, 'cw_onboarding_complete', 'true');
        
        wp_redirect(home_url('/my-account/')); 
        exit;
    }

    public function process_business_request() {
        if (!isset($_POST['cw_nonce']) || !wp_verify_nonce($_POST['cw_nonce'], 'cw_upgrade_business_action')) {
            wp_die('Security Error');
        }
        
        if (!is_user_logged_in()) wp_die('Please login');

        update_user_meta(get_current_user_id(), 'cw_business_application_status', 'pending');
        
        wp_redirect(wp_get_referer()); 
        exit;
    }
    
    // NEW HANDLER: Process Skip
    public function process_skip() {
        if (!isset($_POST['cw_nonce_skip']) || !wp_verify_nonce($_POST['cw_nonce_skip'], 'cw_skip_onboarding_action')) {
            wp_die('Security Error');
        }
        
        if (!is_user_logged_in()) wp_die('Please login');

        $user = wp_get_current_user();
        
        // ACTION: Set onboarding flag to true.
        update_user_meta($user->ID, 'cw_onboarding_complete', 'true');

        wp_redirect(home_url('/my-account/')); 
        exit;
    }

    /* ==========================================================================
       ADMIN: NOTICES & COLUMNS
       ========================================================================== */
    public function admin_pending_requests_notice() {
        if (!current_user_can('promote_users')) return;
        
        $pending_users = get_users(['meta_key' => 'cw_business_application_status', 'meta_value' => 'pending', 'fields' => 'ID']);
        $count = count($pending_users);
        
        if ($count > 0) {
            $url = admin_url('users.php');
            echo '<div class="notice notice-warning"><p>';
            echo sprintf( __('<strong>%d pending Business Application(s).</strong> <a href="%s">Review requests</a>', 'creativewings-core'), $count, $url );
            echo '</p></div>';
        }
    }

    public function admin_action_feedback_notice() {
        if ( isset($_GET['cw_status']) ) {
            $msg = '';
            if ( $_GET['cw_status'] === 'approved' ) $msg = __('User approved as Business Partner.', 'creativewings-core');
            if ( $_GET['cw_status'] === 'rejected' ) $msg = __('User application rejected.', 'creativewings-core');
            
            if ($msg) {
                echo '<div class="notice notice-success is-dismissible"><p>' . esc_html($msg) . '</p></div>';
            }
        }
    }

    public function add_business_column( $columns ) {
        $new_columns = [];
        foreach($columns as $key => $title) {
            $new_columns[$key] = $title;
            if ($key === 'email') {
                $new_columns['cw_biz_status'] = __('Business Status', 'creativewings-core');
            }
        }
        return $new_columns;
    }

    public function show_business_column_content( $value, $column_name, $user_id ) {
        if ( 'cw_biz_status' !== $column_name ) return $value;

        $status = get_user_meta( $user_id, 'cw_business_application_status', true );

        if ( $status === 'pending' ) {
            $approve_url = wp_nonce_url( admin_url( 'admin-post.php?action=cw_approve_biz&uid=' . $user_id ), 'cw_approve_biz_' . $user_id );
            $reject_url  = wp_nonce_url( admin_url( 'admin-post.php?action=cw_reject_biz&uid=' . $user_id ), 'cw_reject_biz_' . $user_id );

            // CRITICAL FIX: Add style="display:inline !important;" to force the Approve link to show
            return sprintf(
                '<strong style="color:#e67e22;">%s</strong><br>
                 <div class="row-actions visible" style="display:block;">
                    <span class="approve" style="display:inline !important;"><a href="%s" style="color:green;">%s</a> | </span>
                    <span class="reject"><a href="%s" style="color:#a00;">%s</a></span>
                 </div>',
                __('Pending', 'creativewings-core'),
                esc_url( $approve_url ), __('Approve', 'creativewings-core'),
                esc_url( $reject_url ), __('Reject', 'creativewings-core')
            );
        } elseif ( $status === 'approved' ) {
            return '<span class="dashicons dashicons-yes" style="color:green;"></span> ' . __('Approved', 'creativewings-core');
        } elseif ( $status === 'rejected' ) {
            return '<span class="dashicons dashicons-no" style="color:red;"></span> ' . __('Rejected', 'creativewings-core');
        }

        return '—';
    }

    /* ==========================================================================
       ADMIN: APPROVE / REJECT HANDLERS
       ========================================================================== */
    public function process_admin_approve() {
        if ( ! current_user_can( 'promote_users' ) ) wp_die( 'Permission Denied' );
        
        $uid = isset($_GET['uid']) ? intval( $_GET['uid'] ) : 0;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'cw_approve_biz_' . $uid ) ) wp_die( 'Security Check Failed' );

        $user = new WP_User( $uid );
        
        // CRITICAL FIX: Use set_role to overwrite 'contestant'
        $user->set_role( 'business_role' );
        
        update_user_meta( $uid, 'cw_business_application_status', 'approved' );
        update_user_meta( $uid, 'account_type', 'business' );
        // CRITICAL FIX: Set onboarding flag
        update_user_meta($uid, 'cw_onboarding_complete', 'true');
        
        $admin_email = get_option( 'admin_email' );
        wp_mail( $user->user_email, 'Application Approved', 'Congrats! You are now a Business Partner. You can start creating campaigns at '.home_url('/my-account/?tab=campaigns') );

        wp_redirect( admin_url( 'users.php?cw_status=approved' ) );
        exit;
    }

    public function process_admin_reject() {
        if ( ! current_user_can( 'promote_users' ) ) wp_die( 'Permission Denied' );
        
        $uid = isset($_GET['uid']) ? intval( $_GET['uid'] ) : 0;
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'cw_reject_biz_' . $uid ) ) wp_die( 'Security Check Failed' );

        update_user_meta( $uid, 'cw_business_application_status', 'rejected' );
        
        $user = get_userdata($uid);
        wp_mail( $user->user_email, 'Application Status', 'Sorry, your application was not approved.' );

        wp_redirect( admin_url( 'users.php?cw_status=rejected' ) );
        exit;
    }
}