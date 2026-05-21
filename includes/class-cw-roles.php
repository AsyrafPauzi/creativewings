<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Roles {

    /**
     * Site administrators use the same dashboard and campaign tools as business partners.
     */
    public static function is_business_admin( $user = null ) {
        $user = self::resolve_user( $user );
        return $user && in_array( 'administrator', (array) $user->roles, true );
    }

    public static function is_business_user( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user ) {
            return false;
        }
        return self::is_business_admin( $user ) || in_array( 'business_role', (array) $user->roles, true );
    }

    public static function is_creator_user( $user = null ) {
        $user = self::resolve_user( $user );
        return $user && in_array( 'creator_role', (array) $user->roles, true );
    }

    /**
     * Creator portfolio at /profile/{login}/ — creators only, not business or contestant.
     */
    public static function has_public_portfolio( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user || self::is_business_user( $user ) ) {
            return false;
        }
        return self::is_creator_user( $user );
    }

    /**
     * Business organiser page at /organizer/{login}/ — campaigns, not portfolio.
     */
    public static function has_public_organizer_page( $user = null ) {
        return self::is_business_user( $user );
    }

    /**
     * Public portfolio URL for a user, or empty when not applicable.
     */
    public static function get_public_portfolio_url( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user || ! self::has_public_portfolio( $user ) || empty( $user->user_login ) ) {
            return '';
        }
        return home_url( '/profile/' . rawurlencode( $user->user_login ) . '/' );
    }

    /**
     * Public organiser URL for a business user, or empty when not applicable.
     */
    public static function get_public_organizer_url( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user || ! self::has_public_organizer_page( $user ) || empty( $user->user_login ) ) {
            return '';
        }
        return home_url( '/organizer/' . rawurlencode( $user->user_login ) . '/' );
    }

    /**
     * Required fields a business user must fill before being listed publicly
     * (in the directory). Keep this small — it is the "basic for display" set,
     * not the full company info.
     *
     * Required: business_name + business_logo + business_industry + business_about
     * + at least one of (business_city, business_country).
     *
     * @return true|string[] true when complete, or an array of missing field keys.
     */
    public static function organizer_missing_basics( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user ) {
            return [ 'business_name', 'business_logo', 'business_industry', 'business_about', 'business_location' ];
        }
        $uid = (int) $user->ID;

        $missing = [];
        if ( trim( (string) get_user_meta( $uid, 'business_name', true ) ) === '' ) {
            $missing[] = 'business_name';
        }
        $logo = get_user_meta( $uid, 'business_logo', true );
        if ( ! ( is_array( $logo ) && ! empty( $logo['url'] ) ) ) {
            $missing[] = 'business_logo';
        }
        if ( trim( (string) get_user_meta( $uid, 'business_industry', true ) ) === '' ) {
            $missing[] = 'business_industry';
        }
        if ( trim( (string) get_user_meta( $uid, 'business_about', true ) ) === '' ) {
            $missing[] = 'business_about';
        }
        $city    = trim( (string) get_user_meta( $uid, 'business_city', true ) );
        $country = trim( (string) get_user_meta( $uid, 'business_country', true ) );
        if ( $city === '' && $country === '' ) {
            $missing[] = 'business_location';
        }

        return empty( $missing ) ? true : $missing;
    }

    public static function has_complete_organizer_profile( $user = null ) {
        return self::organizer_missing_basics( $user ) === true;
    }

    /**
     * Required fields a creator must fill before being listed publicly.
     *
     * Required: display name (creator_display_name OR user display_name)
     * + creator_profile_image + creator_tagline + creator_address.
     *
     * @return true|string[] true when complete, or an array of missing field keys.
     */
    public static function creator_missing_basics( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user ) {
            return [ 'creator_display_name', 'creator_profile_image', 'creator_tagline', 'creator_address' ];
        }
        $uid = (int) $user->ID;

        $missing = [];

        $display = trim( (string) get_user_meta( $uid, 'creator_display_name', true ) );
        if ( $display === '' ) {
            $display = trim( (string) ( $user->display_name ?? '' ) );
        }
        if ( $display === '' ) {
            $missing[] = 'creator_display_name';
        }

        $avatar = get_user_meta( $uid, 'creator_profile_image', true );
        if ( ! ( is_array( $avatar ) && ! empty( $avatar['url'] ) ) ) {
            $missing[] = 'creator_profile_image';
        }

        if ( trim( (string) get_user_meta( $uid, 'creator_tagline', true ) ) === '' ) {
            $missing[] = 'creator_tagline';
        }

        if ( trim( (string) get_user_meta( $uid, 'creator_address', true ) ) === '' ) {
            $missing[] = 'creator_address';
        }

        return empty( $missing ) ? true : $missing;
    }

    public static function has_complete_creator_profile( $user = null ) {
        return self::creator_missing_basics( $user ) === true;
    }

    /**
     * Dashboard role slug: business (incl. administrator), creator, or contestant.
     */
    public static function get_dashboard_role( $user = null ) {
        $user = self::resolve_user( $user );
        if ( ! $user ) {
            return 'contestant';
        }
        if ( self::is_business_user( $user ) ) {
            return 'business';
        }
        if ( self::is_creator_user( $user ) ) {
            return 'creator';
        }
        return 'contestant';
    }

    /**
     * @return array<string, mixed> get_posts() args for campaign products.
     */
    public static function get_business_campaign_query_args( $user_id = 0 ) {
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        $args    = [
            'post_type'      => 'product',
            'post_status'    => [ 'publish', 'pending', 'draft' ],
            'posts_per_page' => -1,
        ];
        if ( ! self::is_business_admin( $user_id ) ) {
            $args['author'] = $user_id;
        }
        return $args;
    }

    public static function user_owns_campaign( $campaign_id, $user_id = 0 ) {
        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id ) {
            return false;
        }
        $user_id = $user_id ? (int) $user_id : get_current_user_id();
        if ( self::is_business_admin( $user_id ) ) {
            return true;
        }
        return (int) get_post_field( 'post_author', $campaign_id ) === $user_id;
    }

    /**
     * @param int|WP_User|null $user
     */
    private static function resolve_user( $user ) {
        if ( $user instanceof WP_User ) {
            return $user;
        }
        if ( is_numeric( $user ) && (int) $user > 0 ) {
            return get_userdata( (int) $user );
        }
        if ( is_user_logged_in() ) {
            return wp_get_current_user();
        }
        return null;
    }

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