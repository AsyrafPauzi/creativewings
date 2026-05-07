<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Post_Types {

    public function __construct() {
        // Register CPTs on WordPress initialization
        add_action( 'init', [ $this, 'register_cpts' ] );
    }

    public function register_cpts() {
        
        // 1. Competition Entries
        // Used to store participants who join Competitions
        register_post_type( 'cw_competition_entry', [
            'labels' => [
                'name'               => __( 'Competition Entries', 'creativewings-core' ),
                'singular_name'      => __( 'Competition Entry', 'creativewings-core' ),
                'menu_name'          => __( 'Competition Entries', 'creativewings-core' ),
                'all_items'          => __( 'All Entries', 'creativewings-core' ),
                'view_item'          => __( 'View Entry', 'creativewings-core' ),
                'search_items'       => __( 'Search Entries', 'creativewings-core' ),
                'not_found'          => __( 'No entries found', 'creativewings-core' ),
            ],
            'public'       => false, // Not visible on frontend directly
            'show_ui'      => true,  // Visible in Admin Dashboard
            'show_in_menu' => true,  // Show in Sidebar
            'supports'     => ['title', 'custom-fields', 'author'],
            'menu_icon'    => 'dashicons-trophy',
            'rewrite'      => false,
            'map_meta_cap' => true,
        ]);

        // 2. Activity Participants
        // Used to store participants who join Activities
        register_post_type( 'cw_activity_entry', [
            'labels' => [
                'name'               => __( 'Activity Participants', 'creativewings-core' ),
                'singular_name'      => __( 'Participant', 'creativewings-core' ),
                'menu_name'          => __( 'Activity Participants', 'creativewings-core' ),
                'all_items'          => __( 'All Participants', 'creativewings-core' ),
                'search_items'       => __( 'Search Participants', 'creativewings-core' ),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'supports'     => ['title', 'custom-fields', 'author'],
            'menu_icon'    => 'dashicons-groups',
            'rewrite'      => false,
            'map_meta_cap' => true,
        ]);

        // 3. REMOVED: 'creator_portfolio' 
        // Reason: Handled by JetEngine Custom Content Table (CCT).
    }
}