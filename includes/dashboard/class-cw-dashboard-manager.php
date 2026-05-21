<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Dashboard_Manager {

    public $creator_dashboard;
    public $business_dashboard;
    public $contestant_dashboard;

    public function __construct() {
        add_shortcode( 'cw_dashboard', [ $this, 'render_dashboard_shortcode' ] );

        if ( class_exists( 'CW_Dashboard_Creator' ) ) $this->creator_dashboard = new CW_Dashboard_Creator();
        if ( class_exists( 'CW_Dashboard_Business' ) ) $this->business_dashboard = new CW_Dashboard_Business();
        if ( class_exists( 'CW_Dashboard_Contestant' ) ) $this->contestant_dashboard = new CW_Dashboard_Contestant();
    }

    public function render_dashboard_shortcode() {
        if ( ! is_user_logged_in() ) {
            return '<script>window.location.href="' . home_url('/login') . '";</script>';
        }

        $user = wp_get_current_user();
        $current_tab = isset( $_GET['tab'] ) ? sanitize_text_field( wp_unslash( $_GET['tab'] ) ) : 'overview';
        $role = '';
        $menu_items = [];
        
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );

        // Define Menus based on Role (FINAL STRUCTURE)
        $role = class_exists( 'CW_Roles' ) ? CW_Roles::get_dashboard_role( $user ) : 'contestant';

        // Claim flow redirects may use WC endpoint URLs; portal tabs use ?tab=link-submission.
        // Both contestants and creators have the Link submission code tab.
        if ( in_array( $role, [ 'contestant', 'creator' ], true ) && 'overview' === $current_tab ) {
            if ( ! empty( $_GET['step'] ) || ! empty( $_GET['claim_token'] ) || ! empty( $_GET['linked'] ) ) {
                $current_tab = 'link-submission';
            } elseif ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'cw-link-submission' ) ) {
                $current_tab = 'link-submission';
            }
        }

        if ( 'business' === $role ) {
            $menu_items = [
                'overview'  => ['icon' => 'fa-th-large',  'label' => 'Dashboard'],
                'campaigns' => ['icon' => 'fa-bullhorn',  'label' => 'My Campaigns'],
                'reports'   => ['icon' => 'fa-chart-bar', 'label' => 'Reports'],
                'wallet'    => ['icon' => 'fa-wallet',    'label' => 'Wallet'],
                'badges'    => ['icon' => 'fa-medal',     'label' => 'My Badges'],
                'biz-info'  => ['icon' => 'fa-building',  'label' => 'Company Profile'],
            ];
        } elseif ( 'creator' === $role ) {
            $menu_items = [
                'overview'        => ['icon' => 'fa-th-large', 'label' => 'Dashboard'],
                'explore'         => ['icon' => 'fa-bolt',     'label' => 'Explore Opportunities'], // NEW TAB
                'activities'      => ['icon' => 'fa-running',  'label' => 'My Activities'], // CONSOLIDATED ENGAGEMENTS
                'link-submission' => ['icon' => 'fa-link',     'label' => 'Link submission code'],
                'portfolio'       => ['icon' => 'fa-briefcase','label' => 'Portfolio'],
                'badges'          => ['icon' => 'fa-medal',    'label' => 'My Badges'],
                'profile'         => ['icon' => 'fa-user-cog', 'label' => 'Profile Settings'],
                // Removed 'competitions' as it's now part of 'activities'
            ];
        } else {
            $menu_items = [
                'overview'         => ['icon' => 'fa-th-large', 'label' => 'Overview'],
                'explore'          => ['icon' => 'fa-bolt',     'label' => 'Explore Opportunities'],
                'link-submission'  => ['icon' => 'fa-link',     'label' => 'Link submission code'],
                'activities'       => ['icon' => 'fa-running',  'label' => 'My Activities'],
                'upgrade'          => ['icon' => 'fa-arrow-up', 'label' => 'Upgrade Account'],
                'settings'         => ['icon' => 'fa-cog',      'label' => 'Settings'],
            ];
        }

        ob_start();
        ?>
        <div class="cw-layout-container">
            
            <!-- MOBILE OVERLAY -->
            <div id="cw-mobile-overlay" class="cw-mobile-overlay" onclick="toggleSidebar()"></div>

            <!-- SIDEBAR -->
            <aside id="cw-sidebar" class="cw-sidebar">
                <div class="cw-sidebar-inner">
                    
                    <!-- LOGO AREA -->
                    <div class="cw-logo-area">
                        <div class="cw-logo-circle">
                            <div class="cw-logo-top"></div>
                            <div class="cw-logo-bot"></div>
                            <span>CW</span>
                        </div>
                        <div class="cw-logo-text">
                            <h1>Creative Wings</h1>
                            <p><?php
                            $portal_label = ucfirst( $role );
                            if ( 'business' === $role && class_exists( 'CW_Roles' ) && CW_Roles::is_business_admin( $user ) ) {
                                $portal_label = __( 'Administrator', 'creativewings-core' );
                            }
                            echo esc_html( $portal_label );
                            ?> Portal</p>
                        </div>
                    </div>

                    <!-- NAVIGATION -->
                    <nav class="cw-nav-list">
                        <?php foreach($menu_items as $slug => $item): 
                            $active = ($current_tab === $slug) ? 'active' : '';
                            
                            $link = add_query_arg('tab', $slug, $base_url);
                        ?>
                            <a href="<?php echo esc_url($link); ?>" class="cw-nav-link <?php echo $active; ?>">
                                <i class="fas <?php echo $item['icon']; ?>"></i>
                                <span><?php echo esc_html($item['label']); ?></span>
                            </a>
                        <?php endforeach; ?>
                    </nav>

                    <!-- FOOTER -->
                    <div class="cw-sidebar-footer">
                        <!-- USER PROFILE BLOCK -->
                        <div class="cw-sidebar-user">
                            <?php echo get_avatar( $user->ID, 38, '', '', ['class' => 'cw-sidebar-avatar'] ); ?>
                            <div class="cw-sidebar-user-info">
                                <span class="cw-sidebar-user-name"><?php echo esc_html( $user->display_name ); ?></span>
                                <span class="cw-sidebar-user-role"><?php
                                $sidebar_role = ucfirst( $role );
                                if ( 'business' === $role && class_exists( 'CW_Roles' ) && CW_Roles::is_business_admin( $user ) ) {
                                    $sidebar_role = __( 'Administrator', 'creativewings-core' );
                                }
                                echo esc_html( $sidebar_role );
                                ?></span>
                            </div>
                        </div>
                        <a href="<?php echo wp_logout_url(home_url()); ?>" class="cw-nav-link logout">
                            <i class="fas fa-sign-out-alt"></i>
                            <span>Sign Out</span>
                        </a>
                    </div>

                </div>
            </aside>

            <!-- MAIN CONTENT -->
            <main class="cw-main-area">
                
                <!-- MOBILE HEADER -->
                <header class="cw-mobile-header">
                    <div class="cw-mobile-brand">
                        <div class="cw-logo-circle small">
                            <div class="cw-logo-top"></div>
                            <div class="cw-logo-bot"></div>
                            <span>CW</span>
                        </div>
                        <span>Creative Wings</span>
                    </div>
                    <button onclick="toggleSidebar()" class="cw-menu-toggle"><i class="fas fa-bars"></i></button>
                </header>

                <!-- SCROLLABLE CONTENT -->
                <div class="cw-content-scroll">
                    <div class="cw-content-max">
                        <?php $this->load_tab_content($role, $current_tab); ?>
                    </div>
                </div>
            </main>

        </div>

        <script>
            function toggleSidebar() {
                const sidebar = document.getElementById('cw-sidebar');
                const overlay = document.getElementById('cw-mobile-overlay');
                sidebar.classList.toggle('open');
                overlay.classList.toggle('active');
            }
        </script>
        <?php
        return ob_get_clean();
    }

    private function load_tab_content($role, $tab) {
        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0; // Check for campaign ID

        if ($role === 'business') {
            switch ($tab) {
                case 'overview': 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_overview(); 
                    break;
                case 'campaigns': 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_campaigns(); 
                    break;
                // Entry Management Route
                case 'manage_entries': 
                    if ($this->business_dashboard && $campaign_id) {
                        $this->business_dashboard->render_entry_management($campaign_id);
                    } else {
                        if ( $this->business_dashboard ) $this->business_dashboard->render_campaigns(); 
                    }
                    break;
                case 'wallet': 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_wallet(); 
                    break;
                case 'reports':
                    if ( $this->business_dashboard ) $this->business_dashboard->render_reports();
                    break;
                case 'biz-info': 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_settings(); 
                    break;
                case 'badges':
                    $this->render_badges_tab( $role );
                    break;
                default: 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_overview();
                    break;
            }
        } elseif ($role === 'creator') {
            switch ($tab) {
                case 'overview': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_overview(); 
                    break;
                // NEW EXPLORE ROUTE
                case 'explore':
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_explore_opportunities();
                    break;
                case 'activities': // CONSOLIDATED ENGAGEMENTS
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_my_activities(); 
                    break;
                case 'link-submission':
                    // Reuse the contestant claim/link-code form (delegates to CW_Claim_Flow::render_endpoint()).
                    if ( $this->contestant_dashboard ) {
                        $this->contestant_dashboard->render_link_submission();
                    }
                    break;
                case 'portfolio': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_portfolio(); 
                    break;
                case 'profile': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_profile(); 
                    break;
                case 'badges':
                    $this->render_badges_tab( $role );
                    break;
                case 'competitions': // Retained logic for old hook compatibility, defaults to activities
                case 'saved': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_saved(); 
                    break;
                default: 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_overview();
                    break;
            }
        } else {
            // Contestant Logic
            switch ($tab) {
                case 'overview': 
                    if ( $this->contestant_dashboard ) $this->contestant_dashboard->render_overview(); 
                    break;
                case 'explore':
                    if ( $this->creator_dashboard ) {
                        $this->creator_dashboard->render_explore_opportunities();
                    } elseif ( $this->contestant_dashboard ) {
                        $this->contestant_dashboard->render_overview();
                    }
                    break;
                case 'link-submission':
                    if ( $this->contestant_dashboard ) {
                        $this->contestant_dashboard->render_link_submission();
                    }
                    break;
                case 'activities': 
                    if ( $this->contestant_dashboard ) $this->contestant_dashboard->render_activities(); 
                    break;
                case 'upgrade': 
                    if ( $this->contestant_dashboard ) $this->contestant_dashboard->render_upgrade(); 
                    break;
                case 'settings': 
                    if ( $this->contestant_dashboard ) $this->contestant_dashboard->render_settings(); 
                    break;
                default: 
                    if ( $this->contestant_dashboard ) $this->contestant_dashboard->render_overview();
                    break;
            }
        }
    }

    /**
     * Render the "My Badges" tab — shared by creator & business.
     */
    private function render_badges_tab( $role ) {
        if ( ! class_exists( 'CW_Badges_Display' ) ) return;
        $uid = get_current_user_id();

        // Handle opt-in form submit.
        if ( isset( $_POST['cw_badge_pref_nonce'] ) && wp_verify_nonce( $_POST['cw_badge_pref_nonce'], 'cw_badge_pref_' . $uid ) ) {
            update_user_meta( $uid, 'cw_badge_email_opt_in', ! empty( $_POST['cw_badge_email_opt_in'] ) ? '1' : '0' );
            echo '<div class="cw-alert success" style="margin:0 0 16px;padding:10px 14px;border-radius:8px;background:#dcfce7;color:#166534;border:1px solid #bbf7d0;font-size:13px;font-weight:600;">' . esc_html__( 'Notification preferences saved.', 'creativewings-core' ) . '</div>';
        }
        $opt_in = (string) get_user_meta( $uid, 'cw_badge_email_opt_in', true ) === '1';
        ?>
        <div class="cw-tab-header" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:8px;">
            <div>
                <h1 style="margin:0 0 6px;font-size:1.6rem;font-weight:800;color:#0f172a;"><i class="fas fa-medal" style="color:#facc15;margin-right:8px;"></i><?php esc_html_e( 'My Badges', 'creativewings-core' ); ?></h1>
                <p style="margin:0;color:#64748b;font-size:14px;"><?php esc_html_e( 'Earn badges by participating, hosting campaigns, and growing your profile.', 'creativewings-core' ); ?></p>
            </div>
            <form method="post" style="display:flex;align-items:center;gap:10px;background:#f8fafc;border:1px solid #e5e7eb;border-radius:999px;padding:6px 14px;font-size:13px;color:#475569;">
                <?php wp_nonce_field( 'cw_badge_pref_' . $uid, 'cw_badge_pref_nonce' ); ?>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-weight:600;">
                    <input type="checkbox" name="cw_badge_email_opt_in" value="1" <?php checked( $opt_in ); ?> onchange="this.form.submit();">
                    <?php esc_html_e( 'Email me when I earn a badge', 'creativewings-core' ); ?>
                </label>
            </form>
        </div>
        <?php echo CW_Badges_Display::render_progress_grid( $uid ); ?>
        <?php
    }
}