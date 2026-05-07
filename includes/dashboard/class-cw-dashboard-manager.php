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
        $current_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'overview';
        $role = '';
        $menu_items = [];
        
        $base_url = get_permalink( wc_get_page_id( 'myaccount' ) );

        // Define Menus based on Role (FINAL STRUCTURE)
        if ( in_array( 'creator_role', (array) $user->roles ) ) {
            $role = 'creator';
            $menu_items = [
                'overview'      => ['icon' => 'fa-th-large', 'label' => 'Dashboard'],
                'explore'       => ['icon' => 'fa-bolt',     'label' => 'Explore Opportunities'], // NEW TAB
                'activities'    => ['icon' => 'fa-running',  'label' => 'My Activities'], // CONSOLIDATED ENGAGEMENTS
                'portfolio'     => ['icon' => 'fa-briefcase','label' => 'Portfolio'],
                'profile'       => ['icon' => 'fa-user-cog', 'label' => 'Profile Settings'],
                // Removed 'competitions' as it's now part of 'activities'
            ];
        } elseif ( in_array( 'business_role', (array) $user->roles ) ) {
            $role = 'business';
            $menu_items = [
                'overview'  => ['icon' => 'fa-th-large', 'label' => 'Dashboard'],
                'campaigns' => ['icon' => 'fa-bullhorn', 'label' => 'My Campaigns'],
                'wallet'    => ['icon' => 'fa-wallet',   'label' => 'Wallet'],
                'biz-info'  => ['icon' => 'fa-building', 'label' => 'Company Profile'],
            ];
        } else {
            $role = 'contestant';
            $menu_items = [
                'overview'   => ['icon' => 'fa-th-large', 'label' => 'Overview'],
                'activities' => ['icon' => 'fa-running',  'label' => 'My Activities'],
                'upgrade'    => ['icon' => 'fa-arrow-up', 'label' => 'Upgrade Account'],
                'settings'   => ['icon' => 'fa-cog',      'label' => 'Settings'],
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
                            <p><?php echo ucfirst($role); ?> Portal</p>
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
                                <span class="cw-sidebar-user-role"><?php echo ucfirst( $role ); ?></span>
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
                case 'biz-info': 
                    if ( $this->business_dashboard ) $this->business_dashboard->render_settings(); 
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
                case 'portfolio': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_portfolio(); 
                    break;
                case 'profile': 
                    if ( $this->creator_dashboard ) $this->creator_dashboard->render_profile(); 
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
}