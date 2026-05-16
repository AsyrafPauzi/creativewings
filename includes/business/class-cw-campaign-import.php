<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Campaign_Import {

    public function __construct() {
        add_action( 'admin_post_cw_import_campaign_json', [ $this, 'handle_import' ] );
        add_action( 'admin_menu', [ $this, 'admin_menu' ] );
    }

    public function admin_menu() {
        add_submenu_page(
            'woocommerce',
            __( 'Import CW Campaign', 'creativewings-core' ),
            __( 'Import CW Campaign', 'creativewings-core' ),
            'manage_woocommerce',
            'cw-import-campaign',
            [ $this, 'render_admin_page' ]
        );
    }

    public function render_admin_page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized' );
        }
        $sample = CW_PATH . 'docs/campaign-import-schema-v1.json';
        ?>
        <div class="wrap">
            <h1><?php esc_html_e( 'Import Creative Wings Campaign (JSON)', 'creativewings-core' ); ?></h1>
            <?php if ( ! empty( $_GET['imported'] ) ) : ?>
                <div class="notice notice-success"><p><?php printf( esc_html__( 'Campaign imported as draft. Product ID: %d', 'creativewings-core' ), (int) $_GET['imported'] ); ?></p></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['error'] ) ) : ?>
                <div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></p></div>
            <?php endif; ?>
            <p>
                <a href="<?php echo esc_url( CW_URL . 'docs/campaign-import-one-smile-one-world-2026.json' ); ?>" download><strong><?php esc_html_e( 'Download One Smile, One World 2026 (production)', 'creativewings-core' ); ?></strong></a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( CW_URL . 'docs/campaign-import-test-dummy.json' ); ?>" download><?php esc_html_e( 'Download TEST dummy', 'creativewings-core' ); ?></a>
                &nbsp;|&nbsp;
                <a href="<?php echo esc_url( CW_URL . 'docs/campaign-import-schema-v1.json' ); ?>" download><?php esc_html_e( 'Schema sample', 'creativewings-core' ); ?></a>
            </p>
            <?php
            $wh = get_option( 'cw_webhook_secret', '' );
            if ( $wh ) {
                echo '<p style="font-size:12px;"><strong>REST webhook secret:</strong> <code>' . esc_html( $wh ) . '</code><br>';
                echo esc_html__( 'Send header X-CW-Webhook-Secret on API requests.', 'creativewings-core' ) . '</p>';
            }
            ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'cw_import_campaign_json', 'cw_import_nonce' ); ?>
                <input type="hidden" name="action" value="cw_import_campaign_json">
                <p><input type="file" name="campaign_json" accept=".json,application/json" required></p>
                <p>
                    <label><?php esc_html_e( 'Organizer user ID (optional)', 'creativewings-core' ); ?>
                        <input type="number" name="organizer_user_id" value="<?php echo esc_attr( get_current_user_id() ); ?>" min="1">
                    </label>
                </p>
                <p>
                    <label><?php esc_html_e( 'Update existing product ID (optional — re-import)', 'creativewings-core' ); ?>
                        <input type="number" name="update_product_id" value="" min="1" placeholder="<?php esc_attr_e( 'Leave empty to create new draft', 'creativewings-core' ); ?>">
                    </label>
                </p>
                <?php submit_button( __( 'Import as draft', 'creativewings-core' ) ); ?>
            </form>
        </div>
        <?php
    }

    public function handle_import() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! wp_verify_nonce( $_POST['cw_import_nonce'] ?? '', 'cw_import_campaign_json' ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        require_once CW_PATH . 'includes/business/class-cw-campaign-persistence.php';

        if ( empty( $_FILES['campaign_json']['tmp_name'] ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'No file uploaded.' ), admin_url( 'admin.php?page=cw-import-campaign' ) ) );
            exit;
        }

        $raw  = file_get_contents( $_FILES['campaign_json']['tmp_name'] );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) || empty( $data['campaign'] ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Invalid JSON structure.' ), admin_url( 'admin.php?page=cw-import-campaign' ) ) );
            exit;
        }

        $campaign = $data['campaign'];
        $update_id = ! empty( $_POST['update_product_id'] ) ? (int) $_POST['update_product_id'] : (int) ( $campaign['product_id'] ?? $data['product_id'] ?? 0 );
        if ( ! $update_id ) {
            $campaign['post_status'] = 'draft';
        }
        $campaign['organizer_id'] = ! empty( $_POST['organizer_user_id'] ) ? (int) $_POST['organizer_user_id'] : get_current_user_id();

        if ( ! empty( $campaign['banner_image_url'] ) ) {
            $aid = $this->sideload( $campaign['banner_image_url'] );
            if ( $aid ) {
                $campaign['banner_attachment_id'] = $aid;
            }
        }
        if ( ! empty( $campaign['cert_template_url'] ) ) {
            $cid = $this->sideload( $campaign['cert_template_url'] );
            if ( $cid ) {
                $campaign['cw_cert_template'] = wp_get_attachment_url( $cid );
            }
        }

        $result = CW_Campaign_Persistence::save_from_array( $campaign, $update_id, (int) $campaign['organizer_id'] );

        if ( is_wp_error( $result ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $result->get_error_message() ), admin_url( 'admin.php?page=cw-import-campaign' ) ) );
            exit;
        }

        if ( class_exists( 'CW_Staged_Submissions' ) ) {
            CW_Staged_Submissions::sync_school_upload_tokens( (int) $result );
        }

        wp_safe_redirect( add_query_arg( 'imported', (int) $result, admin_url( 'admin.php?page=cw-import-campaign' ) ) );
        exit;
    }

    private function sideload( $url ) {
        if ( ! function_exists( 'media_sideload_image' ) ) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }
        $id = media_sideload_image( $url, 0, null, 'id' );
        return is_wp_error( $id ) ? 0 : (int) $id;
    }
}
