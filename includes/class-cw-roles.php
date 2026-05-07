<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Roles {

    public function __construct() {
        // Run on init to ensure roles exist
        add_action( 'init', [ $this, 'register_roles' ] );
    }

    /**
     * Register Custom Roles
     */
    public function register_roles() {
        
        // 1. Contestant (Default New User)
        // Basic read access. Can submit entries but not create campaigns.
        if ( ! get_role( 'contestant' ) ) {
            add_role( 'contestant', __('Contestant', 'creativewings-core'), [
                'read' => true,
            ]);
        }

        // 2. Creator Role (Upgraded User)
        // Can upload files for portfolios.
        $creator_caps = [
            'read'         => true,
            'upload_files' => true, // Essential for Portfolio & Submissions
        ];
        
        // Update/Add Creator Role
        $this->add_or_update_role( 'creator_role', __('Creator', 'creativewings-core'), $creator_caps );


        // 3. Business Role (Partner)
        // Needs WooCommerce Product capabilities to create campaigns.
        $business_caps = [
            'read'                      => true,
            'upload_files'              => true,
            // WooCommerce Product Caps
            'edit_products'             => true,
            'publish_products'          => true, // Allows them to submit, status is usually forced to 'pending' via code
            'edit_published_products'   => true, // Edit their own live campaigns
            'delete_products'           => true, 
            'delete_published_products' => true,
            'assign_product_terms'      => true,
        ];

        // Update/Add Business Role
        $this->add_or_update_role( 'business_role', __('Business Partner', 'creativewings-core'), $business_caps );
    }

    /**
     * Helper to add role or update capabilities if it exists
     */
    private function add_or_update_role( $role_id, $display_name, $caps ) {
        $role = get_role( $role_id );

        if ( ! $role ) {
            // Create fresh
            add_role( $role_id, $display_name, $caps );
        } else {
            // Ensure capabilities are up to date
            foreach ( $caps as $cap => $grant ) {
                if ( ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap, $grant );
                }
            }
        }
    }
}