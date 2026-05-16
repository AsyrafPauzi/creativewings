<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Conditional module bootstrap.
 */
class CW_Loader {

    public static function init_core() {
        require_once CW_PATH . 'includes/class-cw-activator.php';
        require_once CW_PATH . 'includes/class-cw-security.php';
        require_once CW_PATH . 'includes/class-cw-audit-log.php';
        require_once CW_PATH . 'includes/class-cw-campaign-resolver.php';
        require_once CW_PATH . 'includes/class-cw-roles.php';
        require_once CW_PATH . 'includes/class-cw-post-types.php';
        require_once CW_PATH . 'includes/class-cw-submission-code.php';
        require_once CW_PATH . 'includes/class-cw-staged-submissions.php';
        require_once CW_PATH . 'includes/class-cw-email.php';
        require_once CW_PATH . 'includes/class-cw-cron.php';
    }

    public static function init_woocommerce() {
        if ( ! class_exists( 'WooCommerce' ) ) {
            return false;
        }

        require_once CW_PATH . 'includes/class-cw-auth.php';
        require_once CW_PATH . 'includes/class-cw-users.php';
        require_once CW_PATH . 'includes/class-cw-onboarding.php';
        require_once CW_PATH . 'includes/class-cw-business.php';
        require_once CW_PATH . 'includes/business/class-cw-campaign-persistence.php';
        require_once CW_PATH . 'includes/class-cw-sponsor-coupons.php';
        require_once CW_PATH . 'includes/class-cw-moderation.php';
        require_once CW_PATH . 'includes/class-cw-shop.php';
        require_once CW_PATH . 'includes/class-cw-shortcodes.php';
        require_once CW_PATH . 'includes/class-cw-ajax.php';
        require_once CW_PATH . 'includes/class-cw-wallet.php';
        require_once CW_PATH . 'includes/class-cw-admin.php';
        require_once CW_PATH . 'includes/class-cw-export.php';
        require_once CW_PATH . 'includes/class-cw-campaign-admin.php';
        require_once CW_PATH . 'includes/class-cw-certificate.php';
        require_once CW_PATH . 'includes/class-cw-rest-api.php';
        require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-manager.php';
        require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-creator.php';
        require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-business.php';
        require_once CW_PATH . 'includes/dashboard/class-cw-dashboard-contestant.php';

        if ( self::needs_school_upload() ) {
            require_once CW_PATH . 'includes/class-cw-school-upload.php';
        }
        if ( self::needs_claim_flow() ) {
            require_once CW_PATH . 'includes/class-cw-claim-flow.php';
        }
        if ( is_admin() ) {
            require_once CW_PATH . 'includes/business/class-cw-campaign-import.php';
        }

        return true;
    }

    public static function needs_school_upload() {
        if ( isset( $_POST['action'] ) && in_array( $_POST['action'], [ 'cw_staff_submission_save', 'cw_generate_upload_token' ], true ) ) {
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return ( false !== strpos( $uri, '/cw-school-upload/' ) ) || (bool) get_query_var( 'cw_school_upload_token' );
    }

    public static function needs_claim_flow() {
        if ( isset( $_POST['action'] ) && in_array( $_POST['action'], [ 'cw_claim_lookup', 'cw_claim_confirm' ], true ) ) {
            return true;
        }
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ( false !== strpos( $uri, 'my-account' ) || false !== strpos( $uri, 'checkout' ) || false !== strpos( $uri, 'order-received' ) ) {
            return true;
        }
        if ( function_exists( 'is_account_page' ) && is_account_page() ) {
            return true;
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() ) {
            return true;
        }
        return false;
    }
}
