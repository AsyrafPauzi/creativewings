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
        add_action( 'wp_ajax_nopriv_cw_pic_lookup_code', [ $this, 'ajax_lookup_code' ] );
        add_action( 'wp_ajax_cw_pic_lookup_code', [ $this, 'ajax_lookup_code' ] );
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
        $this->render_page( $token, $campaign, $school_code, $prefill, $campaign_id );
        exit;
    }

    private function render_page( $token, $campaign, $school_code, $prefill, $campaign_id ) {
        $claimed_block = $prefill && ( $prefill['status'] ?? '' ) === 'claimed';
        $upload_fields = class_exists( 'CW_Campaign_Fields' )
            ? CW_Campaign_Fields::get_pic_upload_fields( $campaign_id )
            : [];
        $has_configured = class_exists( 'CW_Campaign_Fields' )
            ? CW_Campaign_Fields::campaign_has_configured_pic_uploads( $campaign_id )
            : true;
        $stored_fields = ( is_array( $prefill ) && class_exists( 'CW_Campaign_Fields' ) )
            ? CW_Campaign_Fields::decode_staged_field_data( $prefill )
            : [];
        $stored_by_index = [];
        foreach ( $stored_fields as $item ) {
            $stored_by_index[ (string) ( $item['index'] ?? '' ) ] = $item;
        }
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
                .cw-code-status{display:none;margin:8px 0 4px;font-size:13px;padding:10px 12px;border-radius:8px}
                .cw-code-status.is-visible{display:block}
                .cw-code-status.info{background:#eff6ff;color:#1d4ed8}
                .cw-code-status.success{background:#ecfdf5;color:#047857}
                .cw-code-status.warn{background:#fffbeb;color:#b45309}
                .cw-code-status.error{background:#fef2f2;color:#b91c1c}
                .cw-code-status.loading{background:#f8fafc;color:#64748b}
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
            <?php elseif ( ! $has_configured ) : ?>
                <div class="alert alert-warn"><?php esc_html_e( 'This campaign has no image upload field yet. The organizer must add an Image Upload (or Document Upload) field in Step 5 — Participant Form Fields before school staff can upload.', 'creativewings-core' ); ?></div>
            <?php else : ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
                <?php wp_nonce_field( 'cw_staff_submission', 'cw_staff_nonce' ); ?>
                <input type="hidden" name="action" value="cw_staff_submission_save">
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>">

                <label for="cw-submission-code"><?php esc_html_e( 'Submission code (13-14 digits)', 'creativewings-core' ); ?></label>
                <input type="text" id="cw-submission-code" name="submission_code" required minlength="13" maxlength="14" inputmode="numeric" autocomplete="off"
                    value="<?php echo esc_attr( is_array( $prefill ) ? ( $prefill['submission_code'] ?? '' ) : '' ); ?>"
                    placeholder="0020500100001">
                <div id="cw-code-status" class="cw-code-status" role="status" aria-live="polite"></div>

                <label for="cw-student-name"><?php esc_html_e( 'Student name', 'creativewings-core' ); ?></label>
                <input type="text" id="cw-student-name" name="student_name" required
                    value="<?php echo esc_attr( is_array( $prefill ) ? ( $prefill['student_name'] ?? '' ) : '' ); ?>">

                <?php
                $primary_key = CW_Campaign_Fields::get_primary_artwork_field_key( $campaign_id );
                foreach ( $upload_fields as $idx => $field ) :
                    $label    = trim( (string) ( $field['label'] ?? __( 'Upload', 'creativewings-core' ) ) );
                    $type     = strtolower( (string) ( $field['type'] ?? 'media' ) );
                    $required = ! empty( $field['required'] );
                    $input    = 'cw_field_' . $idx;
                    $stored   = $stored_by_index[ (string) $idx ] ?? null;
                    $aid      = ! empty( $stored['attachment_id'] ) ? (int) $stored['attachment_id'] : 0;
                    if ( ! $aid && $prefill && (string) $idx === (string) $primary_key && ! empty( $prefill['artwork_attachment_id'] ) ) {
                        $aid = (int) $prefill['artwork_attachment_id'];
                    }
                    $accept = ( 'media' === $type ) ? 'image/*' : '.pdf,.doc,.docx,.zip,image/*';
                    ?>
                    <label>
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $required ) : ?>
                            <span style="color:#b91c1c">*</span>
                        <?php endif; ?>
                    </label>
                    <input type="file" class="cw-field-file" name="<?php echo esc_attr( $input ); ?>" accept="<?php echo esc_attr( $accept ); ?>"
                        data-field-index="<?php echo esc_attr( (string) $idx ); ?>"
                        data-field-type="<?php echo esc_attr( $type ); ?>"
                        data-required="<?php echo $required ? '1' : '0'; ?>"
                        <?php echo ( $required && ! $aid ) ? 'required' : ''; ?>>
                    <div class="cw-field-preview" id="cw-preview-<?php echo esc_attr( (string) $idx ); ?>">
                    <?php
                    if ( $aid && 'media' === $type ) {
                        echo wp_get_attachment_image( $aid, 'medium', false, [ 'class' => 'preview' ] );
                    } elseif ( $aid ) {
                        $url = wp_get_attachment_url( $aid );
                        if ( $url ) {
                            echo '<p class="sub"><a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View current file', 'creativewings-core' ) . '</a></p>';
                        }
                    }
                    ?>
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn" id="cw-save-btn"><?php esc_html_e( 'Save submission', 'creativewings-core' ); ?></button>
            </form>
            <?php $this->render_lookup_script( $token, $school_code ); ?>
            <?php endif; ?>
        </div>
        </body>
        </html>
        <?php
    }

    private function render_lookup_script( $token, $school_code ) {
        $cfg = [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( 'cw_pic_lookup' ),
            'token'      => $token,
            'debounceMs' => 2000,
            'i18n'       => [
                'checking'  => __( 'Checking code…', 'creativewings-core' ),
                'available' => __( 'This code is available — you can enter a new submission.', 'creativewings-core' ),
                'exists'    => __( 'Existing submission loaded. Review the details below before saving.', 'creativewings-core' ),
                'claimed'   => __( 'This code is already claimed by a parent and cannot be edited here.', 'creativewings-core' ),
                'network'   => __( 'Could not check code. Try again.', 'creativewings-core' ),
                'viewFile'  => __( 'View current file', 'creativewings-core' ),
            ],
        ];
        ?>
        <script>
        (function(){
            var cfg = <?php echo wp_json_encode( $cfg ); ?>;
            var codeInput = document.getElementById('cw-submission-code');
            var nameInput = document.getElementById('cw-student-name');
            var statusEl = document.getElementById('cw-code-status');
            var saveBtn = document.getElementById('cw-save-btn');
            var timer = null;
            var lastReq = 0;

            function setStatus(kind, text) {
                statusEl.className = 'cw-code-status is-visible ' + (kind || 'info');
                statusEl.textContent = text || '';
            }
            function hideStatus() {
                statusEl.className = 'cw-code-status';
                statusEl.textContent = '';
            }
            function clearPreviews() {
                document.querySelectorAll('.cw-field-preview').forEach(function(el){ el.innerHTML = ''; });
                document.querySelectorAll('.cw-field-file').forEach(function(inp){
                    inp.value = '';
                    if (inp.getAttribute('data-required') === '1') {
                        inp.setAttribute('required', 'required');
                    } else {
                        inp.removeAttribute('required');
                    }
                });
            }
            function renderPreviews(fields) {
                clearPreviews();
                if (!fields || !fields.length) return;
                fields.forEach(function(f){
                    var box = document.getElementById('cw-preview-' + f.index);
                    var inp = document.querySelector('.cw-field-file[data-field-index="' + f.index + '"]');
                    if (!box) return;
                    if (f.preview_html) {
                        box.innerHTML = f.preview_html;
                    } else if (f.url && f.type === 'media') {
                        var img = document.createElement('img');
                        img.src = f.url;
                        img.className = 'preview';
                        img.alt = '';
                        box.appendChild(img);
                    } else if (f.url) {
                        var p = document.createElement('p');
                        p.className = 'sub';
                        var a = document.createElement('a');
                        a.href = f.url;
                        a.target = '_blank';
                        a.rel = 'noopener';
                        a.textContent = cfg.i18n.viewFile;
                        p.appendChild(a);
                        box.appendChild(p);
                    }
                    if (inp && (f.attachment_id || f.url)) {
                        inp.removeAttribute('required');
                    }
                });
            }
            function applyLookup(payload) {
                if (!payload || !payload.ok) {
                    setStatus('error', (payload && payload.message) ? payload.message : cfg.i18n.network);
                    return;
                }
                if (payload.status === 'available') {
                    nameInput.value = '';
                    clearPreviews();
                    setStatus('success', payload.message || cfg.i18n.available);
                    if (saveBtn) saveBtn.disabled = false;
                    return;
                }
                if (payload.status === 'exists' && payload.data) {
                    nameInput.value = payload.data.student_name || '';
                    renderPreviews(payload.data.fields || []);
                    setStatus('info', payload.message || cfg.i18n.exists);
                    if (saveBtn) saveBtn.disabled = false;
                    return;
                }
                if (payload.status === 'claimed') {
                    if (payload.data && payload.data.student_name) {
                        nameInput.value = payload.data.student_name;
                    }
                    renderPreviews(payload.data ? (payload.data.fields || []) : []);
                    setStatus('warn', payload.message || cfg.i18n.claimed);
                    if (saveBtn) saveBtn.disabled = true;
                    return;
                }
                nameInput.value = '';
                clearPreviews();
                setStatus('error', payload.message || '');
                if (saveBtn) saveBtn.disabled = false;
            }
            function lookupCode() {
                var raw = (codeInput.value || '').replace(/\s+/g, '');
                if (raw.length < 13) {
                    hideStatus();
                    if (saveBtn) saveBtn.disabled = false;
                    return;
                }
                var reqId = ++lastReq;
                setStatus('loading', cfg.i18n.checking);
                var body = new URLSearchParams();
                body.append('action', 'cw_pic_lookup_code');
                body.append('nonce', cfg.nonce);
                body.append('token', cfg.token);
                body.append('code', raw);
                fetch(cfg.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function(r){ return r.json(); })
                    .then(function(json){
                        if (reqId !== lastReq) return;
                        applyLookup(json);
                    })
                    .catch(function(){
                        if (reqId !== lastReq) return;
                        setStatus('error', cfg.i18n.network);
                    });
            }
            if (codeInput) {
                codeInput.addEventListener('input', function(){
                    clearTimeout(timer);
                    timer = setTimeout(lookupCode, cfg.debounceMs);
                });
                codeInput.addEventListener('blur', function(){
                    var raw = (codeInput.value || '').replace(/\s+/g, '');
                    if (raw.length >= 13) lookupCode();
                });
                if ((codeInput.value || '').replace(/\s+/g, '').length >= 13) {
                    setTimeout(lookupCode, 300);
                }
            }
        })();
        </script>
        <?php
    }

    public function ajax_lookup_code() {
        if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), 'cw_pic_lookup' ) ) {
            wp_send_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( 'Security check failed.', 'creativewings-core' ) ], 403 );
        }

        $token = sanitize_text_field( wp_unslash( $_POST['token'] ?? '' ) );
        $row   = CW_Staged_Submissions::get_token( $token );
        if ( ! $row ) {
            wp_send_json( [ 'ok' => false, 'status' => 'invalid', 'message' => __( 'Invalid upload link.', 'creativewings-core' ) ], 403 );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( 'cw_rate_pic_lookup_' . $token, 120, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_send_json( [ 'ok' => false, 'status' => 'invalid', 'message' => $rl->get_error_message() ], 429 );
            }
        }

        $campaign_id = (int) $row['campaign_id'];
        $school_code = $row['school_code'];
        $parsed      = CW_Submission_Code::parse( sanitize_text_field( wp_unslash( $_POST['code'] ?? '' ) ) );

        if ( ! $parsed['valid'] ) {
            wp_send_json( [ 'ok' => true, 'status' => 'invalid', 'message' => $parsed['error'] ] );
        }

        if ( $parsed['school'] !== $school_code ) {
            wp_send_json( [
                'ok'      => true,
                'status'  => 'school',
                'message' => __( 'School code in this number does not match this upload link.', 'creativewings-core' ),
            ] );
        }

        if ( ! CW_Submission_Code::matches_campaign_serial( $parsed, $campaign_id ) ) {
            wp_send_json( [
                'ok'      => true,
                'status'  => 'campaign',
                'message' => __( 'Campaign code does not match this campaign.', 'creativewings-core' ),
            ] );
        }

        $existing = CW_Staged_Submissions::get_by_code( $parsed['normalized'], $campaign_id );
        if ( ! $existing ) {
            wp_send_json( [
                'ok'      => true,
                'status'  => 'available',
                'message' => __( 'This code is available — you can enter a new submission.', 'creativewings-core' ),
            ] );
        }

        $fields_out = $this->format_lookup_fields( $existing, $campaign_id );

        $data = [
            'submission_code' => $existing['submission_code'],
            'student_name'    => $existing['student_name'] ?? '',
            'status'          => $existing['status'] ?? '',
            'fields'          => $fields_out,
        ];

        if ( ( $existing['status'] ?? '' ) === 'claimed' ) {
            wp_send_json( [
                'ok'      => true,
                'status'  => 'claimed',
                'message' => __( 'This code is already claimed by a parent and cannot be edited here.', 'creativewings-core' ),
                'data'    => $data,
            ] );
        }

        wp_send_json( [
            'ok'      => true,
            'status'  => 'exists',
            'message' => __( 'Existing submission loaded. Review the details below before saving.', 'creativewings-core' ),
            'data'    => $data,
        ] );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function format_lookup_fields( array $existing, $campaign_id ) {
        $fields_out = [];
        if ( ! class_exists( 'CW_Campaign_Fields' ) ) {
            return $fields_out;
        }
        $primary_key = CW_Campaign_Fields::get_primary_artwork_field_key( $campaign_id );
        foreach ( CW_Campaign_Fields::decode_staged_field_data( $existing ) as $item ) {
            $idx  = (string) ( $item['index'] ?? '' );
            $aid  = (int) ( $item['attachment_id'] ?? 0 );
            $type = strtolower( (string) ( $item['type'] ?? 'media' ) );
            $url  = $aid ? wp_get_attachment_url( $aid ) : (string) ( $item['value'] ?? '' );
            if ( ! $aid && $idx === (string) $primary_key && ! empty( $existing['artwork_attachment_id'] ) ) {
                $aid = (int) $existing['artwork_attachment_id'];
                $url = wp_get_attachment_url( $aid ) ?: $url;
            }
            $preview_html = '';
            if ( $aid && 'media' === $type ) {
                $preview_html = wp_get_attachment_image( $aid, 'medium', false, [ 'class' => 'preview' ] );
            }
            $fields_out[] = [
                'index'         => $idx,
                'label'         => (string) ( $item['label'] ?? '' ),
                'type'          => $type,
                'attachment_id' => $aid,
                'url'           => $url,
                'preview_html'  => $preview_html,
            ];
        }
        return $fields_out;
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

        if ( ! class_exists( 'CW_Campaign_Fields' ) || ! CW_Campaign_Fields::campaign_has_configured_pic_uploads( $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( __( 'This campaign has no upload fields configured.', 'creativewings-core' ) ), $base ) );
            exit;
        }

        $upload_fields = CW_Campaign_Fields::get_pic_upload_fields( $campaign_id );
        $primary_key   = CW_Campaign_Fields::get_primary_artwork_field_key( $campaign_id );
        $stored_by     = [];
        if ( $existing ) {
            foreach ( CW_Campaign_Fields::decode_staged_field_data( $existing ) as $item ) {
                $stored_by[ (string) ( $item['index'] ?? '' ) ] = $item;
            }
        }

        $field_data = [];
        foreach ( $upload_fields as $idx => $field ) {
            $input_key = 'cw_field_' . $idx;
            $type      = strtolower( (string) ( $field['type'] ?? 'media' ) );
            $label     = trim( (string) ( $field['label'] ?? __( 'Upload', 'creativewings-core' ) ) );
            $aid       = 0;
            if ( ! empty( $stored_by[ (string) $idx ]['attachment_id'] ) ) {
                $aid = (int) $stored_by[ (string) $idx ]['attachment_id'];
            }
            if ( ! empty( $_FILES[ $input_key ]['name'] ) ) {
                $uploaded = class_exists( 'CW_Security' )
                    ? CW_Security::handle_field_upload( $input_key, $type )
                    : 0;
                if ( is_wp_error( $uploaded ) ) {
                    wp_safe_redirect( add_query_arg( 'error', rawurlencode( $uploaded->get_error_message() ), $base ) );
                    exit;
                }
                $aid = (int) $uploaded;
            }
            if ( ! empty( $field['required'] ) && ! $aid ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( sprintf( __( '%s is required.', 'creativewings-core' ), $label ) ), $base ) );
                exit;
            }
            if ( $aid ) {
                $field_data[] = [
                    'index'         => $idx,
                    'label'         => $label,
                    'type'          => $type,
                    'attachment_id' => $aid,
                    'value'         => wp_get_attachment_url( $aid ) ?: '',
                ];
            }
        }

        $attachment_id = 0;
        if ( null !== $primary_key ) {
            foreach ( $field_data as $item ) {
                if ( (string) ( $item['index'] ?? '' ) === (string) $primary_key ) {
                    $attachment_id = (int) ( $item['attachment_id'] ?? 0 );
                    break;
                }
            }
        }

        if ( ! $attachment_id ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( __( 'Primary artwork image is required.', 'creativewings-core' ) ), $base ) );
            exit;
        }

        $name    = sanitize_text_field( $_POST['student_name'] ?? '' );
        $payload = [
            'submission_code'       => $parsed['normalized'],
            'campaign_id'           => $campaign_id,
            'school_code'           => $parsed['school'],
            'month_code'            => $parsed['month'],
            'seq_code'              => $parsed['seq'],
            'student_name'          => $name,
            'artwork_attachment_id' => $attachment_id,
            'field_data'            => $field_data,
        ];

        if ( $existing ) {
            $sid = (int) $existing['id'];
            CW_Staged_Submissions::update( $sid, [
                'student_name'          => $name,
                'artwork_attachment_id' => $attachment_id,
                'field_data'            => $field_data,
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

        if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
            CW_Pending_Parent_Link::on_staged_uploaded( $sid, $campaign_id, $parsed['normalized'] );
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
