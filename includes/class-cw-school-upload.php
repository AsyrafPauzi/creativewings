<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_School_Upload {

    public function __construct() {
        add_filter( 'query_vars', [ $this, 'query_vars' ] );
        add_action( 'template_redirect', [ $this, 'maybe_render' ] );
        add_action( 'admin_post_nopriv_cw_staff_submission_save', [ $this, 'handle_save' ] );
        add_action( 'admin_post_cw_staff_submission_save', [ $this, 'handle_save' ] );
        add_action( 'admin_post_cw_generate_upload_token', [ $this, 'handle_generate_token' ] );
    }

    public function query_vars( $vars ) {
        $vars[] = 'cw_school_upload_token';
        return $vars;
    }

    public function maybe_render() {
        $token = get_query_var( 'cw_school_upload_token' );
        if ( ! $token ) {
            return;
        }

        $row = CW_Staged_Submissions::get_token( $token );
        if ( ! $row ) {
            wp_die( esc_html__( 'Invalid or expired upload link.', 'creativewings-core' ), 403 );
        }

        $campaign_id = (int) $row['campaign_id'];
        $school_code = $row['school_code'];
        $campaign    = get_post( $campaign_id );

        $prefill = null;
        if ( ! empty( $_GET['code'] ) ) {
            $prefill = CW_Staged_Submissions::get_by_code(
                sanitize_text_field( wp_unslash( $_GET['code'] ) ),
                $campaign_id
            );
        }

        status_header( 200 );
        nocache_headers();
        $this->render_page( $token, $campaign, $school_code, $prefill );
        exit;
    }

    private function render_page( $token, $campaign, $school_code, $prefill ) {
        $claimed_block = $prefill && ( $prefill['status'] ?? '' ) === 'claimed';
        ?>
        <!DOCTYPE html>
        <html <?php language_attributes(); ?>>
        <head>
            <meta charset="<?php bloginfo( 'charset' ); ?>">
            <meta name="viewport" content="width=device-width, initial-scale=1">
            <title><?php esc_html_e( 'School Submission Upload', 'creativewings-core' ); ?></title>
            <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css" />
            <style>
                body{font-family:system-ui,sans-serif;background:#f1f5f9;margin:0;padding:24px}
                .cw-box{max-width:520px;margin:0 auto;background:#fff;border-radius:12px;padding:28px;box-shadow:0 4px 20px rgba(0,0,0,.08)}
                h1{font-size:22px;margin:0 0 8px;color:#0f172a}
                .sub{color:#64748b;font-size:14px;margin-bottom:20px}
                label{display:block;font-weight:600;font-size:13px;margin:12px 0 6px}
                input[type=text],input[type=file]{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:8px;box-sizing:border-box}
                .btn{background:#006599;color:#fff;border:none;padding:12px 20px;border-radius:8px;font-weight:700;cursor:pointer;width:100%;margin-top:16px}
                .alert{padding:12px;border-radius:8px;margin-bottom:16px;font-size:14px}
                .alert-error{background:#fef2f2;color:#b91c1c}
                .alert-success{background:#ecfdf5;color:#047857}
                .alert-warn{background:#fffbeb;color:#b45309}
                .preview{max-width:200px;margin-top:10px;border-radius:8px}
            </style>
        </head>
        <body>
        <div class="cw-box" style="display:block">
            <h1><?php echo esc_html( $campaign ? $campaign->post_title : 'Campaign' ); ?></h1>
            <p class="sub"><?php printf( esc_html__( 'School code: %s', 'creativewings-core' ), esc_html( $school_code ) ); ?></p>

            <?php if ( ! empty( $_GET['saved'] ) ) : ?>
                <div class="alert alert-success"><?php esc_html_e( 'Submission saved successfully.', 'creativewings-core' ); ?></div>
            <?php endif; ?>
            <?php if ( ! empty( $_GET['error'] ) ) : ?>
                <div class="alert alert-error"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></div>
            <?php endif; ?>

            <?php if ( $claimed_block ) : ?>
                <div class="alert alert-warn"><?php esc_html_e( 'This submission is already claimed by a parent. Contact Creative Wings admin to make changes.', 'creativewings-core' ); ?></div>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'cw_staff_submission', 'cw_staff_nonce' ); ?>
                <input type="hidden" name="action" value="cw_staff_submission_save">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

                <label><?php esc_html_e( 'Submission code (13-14 digits)', 'creativewings-core' ); ?></label>
                <input type="text" name="submission_code" required minlength="13" maxlength="14" inputmode="numeric"
                    value="<?php echo esc_attr( is_array( $prefill ) ? ( $prefill['submission_code'] ?? '' ) : '' ); ?>"
                    placeholder="0020500100001">

                <label><?php esc_html_e( 'Student name', 'creativewings-core' ); ?></label>
                <input type="text" name="student_name" required
                    value="<?php echo esc_attr( is_array( $prefill ) ? ( $prefill['student_name'] ?? '' ) : '' ); ?>">

                <label><?php esc_html_e( 'Artwork image', 'creativewings-core' ); ?></label>
                <input type="file" name="artwork" accept="image/*" <?php echo $prefill ? '' : 'required'; ?>>
                <?php
                if ( $prefill && ! empty( $prefill['artwork_attachment_id'] ) ) {
                    echo wp_get_attachment_image( (int) $prefill['artwork_attachment_id'], 'medium', false, [ 'class' => 'preview' ] );
                }
                ?>

                <button type="submit" class="btn"><?php esc_html_e( 'Save submission', 'creativewings-core' ); ?></button>
            </form>
            <?php endif; ?>
        </div>
        </body>
        </html>
        <?php
    }

    public function handle_save() {
        if ( ! isset( $_POST['cw_staff_nonce'] ) || ! wp_verify_nonce( $_POST['cw_staff_nonce'], 'cw_staff_submission' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_PIC_UPLOAD . sanitize_text_field( $_POST['token'] ?? '' ), 60, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_die( esc_html( $rl->get_error_message() ), 429 );
            }
        }

        $token = sanitize_text_field( $_POST['token'] ?? '' );
        $row   = CW_Staged_Submissions::get_token( $token );
        $base  = home_url( '/cw-school-upload/' . $token . '/' );

        if ( ! $row ) {
            wp_die( 'Invalid token', 403 );
        }

        $campaign_id = (int) $row['campaign_id'];
        $school_code = $row['school_code'];
        $code_raw    = sanitize_text_field( $_POST['submission_code'] ?? '' );
        $parsed      = CW_Submission_Code::parse( $code_raw );

        if ( ! $parsed['valid'] ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $parsed['error'] ), $base ) );
            exit;
        }

        if ( $parsed['school'] !== $school_code ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'School code in submission does not match this upload link.' ), $base ) );
            exit;
        }

        if ( ! CW_Submission_Code::matches_campaign_serial( $parsed, $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Campaign code does not match this campaign.' ), $base ) );
            exit;
        }

        $existing = CW_Staged_Submissions::get_by_code( $parsed['normalized'], $campaign_id );

        if ( $existing && ( $existing['status'] ?? '' ) === 'claimed' ) {
            wp_safe_redirect( add_query_arg( [ 'code' => $parsed['normalized'], 'error' => rawurlencode( 'Already claimed - cannot edit via staff link.' ) ], $base ) );
            exit;
        }

        $attachment_id = $existing ? (int) $existing['artwork_attachment_id'] : 0;
        if ( ! empty( $_FILES['artwork']['name'] ) ) {
            $aid = class_exists( 'CW_Security' ) ? CW_Security::handle_image_upload( 'artwork' ) : 0;
            if ( is_wp_error( $aid ) ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( $aid->get_error_message() ), $base ) );
                exit;
            }
            $attachment_id = (int) $aid;
        }

        if ( ! $attachment_id ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Artwork image is required.' ), $base ) );
            exit;
        }

        $name = sanitize_text_field( $_POST['student_name'] ?? '' );
        $payload = [
            'submission_code'       => $parsed['normalized'],
            'campaign_id'           => $campaign_id,
            'school_code'           => $parsed['school'],
            'month_code'            => $parsed['month'],
            'seq_code'              => $parsed['seq'],
            'student_name'          => $name,
            'artwork_attachment_id' => $attachment_id,
        ];

        if ( $existing ) {
            $sid = (int) $existing['id'];
            CW_Staged_Submissions::update( $sid, [
                'student_name'          => $name,
                'artwork_attachment_id' => $attachment_id,
            ] );
            if ( class_exists( 'CW_Audit_Log' ) ) {
                CW_Audit_Log::log( 'staged_update', 'staged', $sid, [ 'code' => $parsed['normalized'] ] );
            }
        } else {
            $sid = (int) CW_Staged_Submissions::insert( $payload );
            if ( class_exists( 'CW_Audit_Log' ) ) {
                CW_Audit_Log::log( 'staged_create', 'staged', $sid, [ 'code' => $parsed['normalized'] ] );
            }
        }

        wp_safe_redirect( add_query_arg( [ 'saved' => '1', 'code' => $parsed['normalized'] ], $base ) );
        exit;
    }

    public function handle_generate_token() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'cw_generate_upload_token' );

        $campaign_id = absint( $_POST['campaign_id'] ?? 0 );
        $school_code = sanitize_text_field( $_POST['school_code'] ?? '' );

        $token = CW_Staged_Submissions::create_token( $campaign_id, $school_code );
        $url   = home_url( '/cw-school-upload/' . $token . '/' );

        set_transient( 'cw_upload_link_' . get_current_user_id(), $url, 300 );
        wp_safe_redirect( wp_get_referer() ?: admin_url() );
        exit;
    }
}
