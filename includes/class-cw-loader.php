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
        require_once CW_PATH . 'includes/class-cw-image-optimizer.php';
        require_once CW_PATH . 'includes/class-cw-cache.php';
        CW_Cache::register_invalidation_hooks();

        // Badges system (data model + engine).
        require_once CW_PATH . 'includes/badges/class-cw-badges-installer.php';
        require_once CW_PATH . 'includes/badges/class-cw-badges-cpt.php';
        require_once CW_PATH . 'includes/badges/class-cw-badges-engine.php';
        require_once CW_PATH . 'includes/badges/class-cw-badges-display.php';
        require_once CW_PATH . 'includes/class-cw-security.php';
        require_once CW_PATH . 'includes/class-cw-flash-notices.php';
        require_once CW_PATH . 'includes/class-cw-audit-log.php';
        require_once CW_PATH . 'includes/class-cw-campaign-resolver.php';
        require_once CW_PATH . 'includes/class-cw-roles.php';
        require_once CW_PATH . 'includes/class-cw-post-types.php';
        require_once CW_PATH . 'includes/class-cw-submission-code.php';
        require_once CW_PATH . 'includes/class-cw-campaign-fields.php';
        require_once CW_PATH . 'includes/class-cw-staged-submissions.php';
        require_once CW_PATH . 'includes/class-cw-pending-parent-link.php';
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
        require_once CW_PATH . 'includes/business/class-cw-business-reports.php';
        require_once CW_PATH . 'includes/business/class-cw-report-export.php';
        require_once CW_PATH . 'includes/class-cw-sponsor-coupons.php';
        require_once CW_PATH . 'includes/class-cw-moderation.php';
        require_once CW_PATH . 'includes/class-cw-shop.php';
        require_once CW_PATH . 'includes/class-cw-design-submission.php';
        require_once CW_PATH . 'includes/class-cw-checkout.php';
        require_once CW_PATH . 'includes/class-cw-shortcodes.php';
        require_once CW_PATH . 'includes/class-cw-organizer-profile.php';
        require_once CW_PATH . 'includes/class-cw-directory.php';
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

        // Always load: hooks are lightweight; avoids get_query_var() before main query exists (wp-admin fatal).
        require_once CW_PATH . 'includes/class-cw-school-upload.php';
        require_once CW_PATH . 'includes/class-cw-claim-flow.php';
        require_once CW_PATH . 'includes/class-cw-guest-join.php';

        if ( is_admin() ) {
            require_once CW_PATH . 'includes/business/class-cw-campaign-import.php';
            require_once CW_PATH . 'includes/admin/class-cw-image-bulk-optimizer.php';
            require_once CW_PATH . 'includes/admin/class-cw-badges-admin.php';
            require_once CW_PATH . 'includes/admin/class-cw-sync-center.php';
        }

        return true;
    }
}
