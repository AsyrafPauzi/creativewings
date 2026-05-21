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

        $prefill     = null;
        $qr_code     = '';
        $from_qr     = false;
        if ( ! empty( $_GET['code'] ) ) {
            $raw     = sanitize_text_field( wp_unslash( $_GET['code'] ) );
            $qr_code = preg_replace( '/\s+/', '', $raw );
            $from_qr = true;
            $prefill = CW_Staged_Submissions::get_by_code( $qr_code, $campaign_id );
            if ( ! $prefill && $qr_code ) {
                $prefill = [
                    'submission_code' => $qr_code,
                    'student_name'    => '',
                    'status'          => 'staged',
                ];
            }
        }

        status_header( 200 );
        nocache_headers();
        $this->render_page( $token, $campaign, $school_code, $prefill, $campaign_id, $from_qr, $qr_code );
        exit;
    }

    private function render_page( $token, $campaign, $school_code, $prefill, $campaign_id, $from_qr = false, $qr_code = '' ) {
        $claimed_block = $prefill && ( $prefill['status'] ?? '' ) === 'claimed';
        $code_value    = $qr_code ?: ( is_array( $prefill ) ? ( $prefill['submission_code'] ?? '' ) : '' );
        $code_mismatch = false;
        if ( $from_qr && $code_value && class_exists( 'CW_Submission_Code' ) ) {
            $parsed = CW_Submission_Code::parse( $code_value );
            if ( $parsed['valid'] && ( $parsed['school'] ?? '' ) !== $school_code ) {
                $code_mismatch = true;
            }
        }
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
                .preview{max-width:100%;max-height:220px;margin-top:10px;border-radius:8px;display:block;object-fit:contain}
                .cw-pic-upload{margin-bottom:8px}
                .cw-pic-upload-actions{display:flex;flex-wrap:wrap;gap:8px;margin-top:6px}
                .cw-pic-btn{flex:1;min-width:140px;display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 14px;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;border:1.5px solid #cbd5e1;background:#f8fafc;color:#0f172a;transition:background .15s,border-color .15s}
                .cw-pic-btn:hover{background:#eff6ff;border-color:#006599;color:#006599}
                .cw-pic-btn i{font-size:15px}
                .cw-pic-btn--primary{background:#006599;border-color:#006599;color:#fff}
                .cw-pic-btn--primary:hover{background:#005580;color:#fff}
                .cw-pic-hint{font-size:12px;color:#64748b;margin:6px 0 0}
                .cw-field-file{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}
                .cw-camera-modal{position:fixed;inset:0;z-index:9999;background:rgba(15,23,42,.75);display:none;align-items:center;justify-content:center;padding:16px;box-sizing:border-box}
                .cw-camera-modal.is-open{display:flex}
                .cw-camera-panel{background:#fff;border-radius:12px;max-width:480px;width:100%;padding:16px;box-shadow:0 20px 50px rgba(0,0,0,.25)}
                .cw-camera-panel video{width:100%;border-radius:8px;background:#000;max-height:60vh}
                .cw-camera-panel canvas{display:none}
                .cw-camera-actions{display:flex;gap:8px;margin-top:12px;flex-wrap:wrap}
                .cw-camera-actions .cw-pic-btn{flex:1}
                .cw-code-status{display:none;margin:8px 0 4px;font-size:13px;padding:10px 12px;border-radius:8px}
                .cw-code-status.is-visible{display:block}
                .cw-code-status.info{background:#eff6ff;color:#1d4ed8}
                .cw-code-status.success{background:#ecfdf5;color:#047857}
                .cw-code-status.warn{background:#fffbeb;color:#b45309}
                .cw-code-status.error{background:#fef2f2;color:#b91c1c}
                .cw-code-status.loading{background:#f8fafc;color:#64748b}
                input.cw-code-from-qr{background:#f0f9ff;border-color:#7dd3fc;color:#0c4a6e;font-weight:600;letter-spacing:.04em}
                .cw-code-masked{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;background:#f0f9ff;border:1px solid #7dd3fc;border-radius:8px;padding:10px 12px;margin-top:4px}
                .cw-code-masked-info{display:flex;align-items:center;gap:8px;font-size:13px;color:#0c4a6e;font-weight:600}
                .cw-code-masked-info i{color:#0284c7;font-size:14px}
                .cw-code-masked-hint{font-size:11.5px;color:#0369a1;font-weight:500}
                .cw-code-masked-dots{letter-spacing:.18em;font-weight:700}
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

                <?php if ( $from_qr && $code_value && ! $code_mismatch ) : ?>
                <div class="alert alert-success" style="margin-bottom:14px;">
                    <?php esc_html_e( 'Submission ID loaded from QR scan. Confirm the student name and upload artwork below.', 'creativewings-core' ); ?>
                </div>
                <?php elseif ( $code_mismatch ) : ?>
                <div class="alert alert-error">
                    <?php esc_html_e( 'This QR code is for a different school than this upload link. Ask your organizer for the correct school link.', 'creativewings-core' ); ?>
                </div>
                <?php endif; ?>
                <?php
                $code_locked = ( $from_qr && $code_value && ! $code_mismatch );
                $code_tail   = $code_value !== '' ? substr( preg_replace( '/\D+/', '', $code_value ), -4 ) : '';
                ?>
                <?php if ( $code_locked ) : ?>
                    <label for="cw-submission-code-masked"><?php esc_html_e( 'Submission code', 'creativewings-core' ); ?></label>
                    <div class="cw-code-masked" id="cw-submission-code-masked">
                        <span class="cw-code-masked-info">
                            <i class="fas fa-shield-alt" aria-hidden="true"></i>
                            <span><?php esc_html_e( 'Locked from QR scan', 'creativewings-core' ); ?>
                                <?php if ( $code_tail ) : ?>
                                    · <span class="cw-code-masked-dots">•••• <?php echo esc_html( $code_tail ); ?></span>
                                <?php endif; ?>
                            </span>
                        </span>
                        <span class="cw-code-masked-hint"><?php esc_html_e( 'Hidden for safety', 'creativewings-core' ); ?></span>
                    </div>
                    <input type="hidden" id="cw-submission-code" name="submission_code" value="<?php echo esc_attr( $code_value ); ?>">
                <?php else : ?>
                    <label for="cw-submission-code"><?php esc_html_e( 'Submission code (13-14 digits)', 'creativewings-core' ); ?></label>
                    <input type="text" id="cw-submission-code" name="submission_code" required minlength="13" maxlength="14" inputmode="numeric" autocomplete="off"
                        value="<?php echo esc_attr( $code_value ); ?>"
                        placeholder="0020500100001">
                <?php endif; ?>
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
                    $accept   = ( 'media' === $type ) ? 'image/*' : '.pdf,.doc,.docx,.zip,image/*';
                    $is_media = ( 'media' === $type );
                    $file_id  = 'cw-file-' . $idx;
                    ?>
                    <div class="cw-pic-upload" data-field-index="<?php echo esc_attr( (string) $idx ); ?>">
                    <label>
                        <?php echo esc_html( $label ); ?>
                        <?php if ( $required ) : ?>
                            <span style="color:#b91c1c">*</span>
                        <?php endif; ?>
                    </label>
                    <input type="file" class="cw-field-file" id="<?php echo esc_attr( $file_id ); ?>" name="<?php echo esc_attr( $input ); ?>" accept="<?php echo esc_attr( $accept ); ?>"
                        data-field-index="<?php echo esc_attr( (string) $idx ); ?>"
                        data-field-type="<?php echo esc_attr( $type ); ?>"
                        data-required="<?php echo $required ? '1' : '0'; ?>"
                        <?php echo ( $required && ! $aid ) ? 'required' : ''; ?>>
                    <div class="cw-pic-upload-actions">
                        <?php if ( $is_media ) : ?>
                        <button type="button" class="cw-pic-btn cw-pic-btn--primary cw-pic-open-camera" data-target="<?php echo esc_attr( $file_id ); ?>">
                            <i class="fas fa-camera" aria-hidden="true"></i>
                            <?php esc_html_e( 'Take photo', 'creativewings-core' ); ?>
                        </button>
                        <button type="button" class="cw-pic-btn cw-pic-open-gallery" data-target="<?php echo esc_attr( $file_id ); ?>">
                            <i class="fas fa-image" aria-hidden="true"></i>
                            <?php esc_html_e( 'Choose image', 'creativewings-core' ); ?>
                        </button>
                        <?php else : ?>
                        <button type="button" class="cw-pic-btn cw-pic-btn--primary cw-pic-open-gallery" data-target="<?php echo esc_attr( $file_id ); ?>">
                            <i class="fas fa-upload" aria-hidden="true"></i>
                            <?php esc_html_e( 'Choose file', 'creativewings-core' ); ?>
                        </button>
                        <?php endif; ?>
                    </div>
                    <?php if ( $is_media ) : ?>
                    <p class="cw-pic-hint"><?php esc_html_e( 'Use your camera or pick a photo already saved on this device.', 'creativewings-core' ); ?></p>
                    <?php endif; ?>
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
                    </div>
                <?php endforeach; ?>

                <button type="submit" class="btn" id="cw-save-btn"><?php esc_html_e( 'Save submission', 'creativewings-core' ); ?></button>
            </form>
            <?php $this->render_camera_modal(); ?>
            <?php $this->render_lookup_script( $token, $school_code, $from_qr && $code_value && ! $code_mismatch ); ?>
            <?php $this->render_upload_script(); ?>
            <?php endif; ?>
        </div>
        <script>
        (function(){
            try {
                var loc = window.location;
                if (!loc || !loc.search || !window.history || !window.history.replaceState) return;
                var qs = new URLSearchParams(loc.search);
                var changed = false;
                if (qs.has('code'))  { qs.delete('code'); changed = true; }
                if (qs.has('saved')) { qs.delete('saved'); }
                if (qs.has('error')) { qs.delete('error'); }
                if (changed || !loc.search) {
                    var cleanQs = qs.toString();
                    var newUrl  = loc.pathname + (cleanQs ? '?' + cleanQs : '') + loc.hash;
                    window.history.replaceState(null, '', newUrl);
                }
            } catch (e) {}
        })();
        </script>
        </body>
        </html>
        <?php
    }

    private function render_lookup_script( $token, $school_code, $auto_lookup = false ) {
        $cfg = [
            'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
            'nonce'       => wp_create_nonce( 'cw_pic_lookup' ),
            'token'       => $token,
            'debounceMs'  => 2000,
            'autoLookup'  => (bool) $auto_lookup,
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
            if (cfg.autoLookup && codeInput && (codeInput.value || '').replace(/\s+/g, '').length >= 13) {
                setTimeout(lookupCode, 200);
            }
        })();
        </script>
        <?php
    }

    private function render_camera_modal() {
        ?>
        <div id="cw-camera-modal" class="cw-camera-modal" aria-hidden="true">
            <div class="cw-camera-panel" role="dialog" aria-modal="true" aria-labelledby="cw-camera-title">
                <h2 id="cw-camera-title" style="margin:0 0 12px;font-size:18px;"><?php esc_html_e( 'Take a photo', 'creativewings-core' ); ?></h2>
                <video id="cw-camera-video" playsinline autoplay muted></video>
                <canvas id="cw-camera-canvas"></canvas>
                <div class="cw-camera-actions">
                    <button type="button" class="cw-pic-btn cw-pic-btn--primary" id="cw-camera-capture">
                        <i class="fas fa-camera" aria-hidden="true"></i>
                        <?php esc_html_e( 'Capture', 'creativewings-core' ); ?>
                    </button>
                    <button type="button" class="cw-pic-btn" id="cw-camera-cancel">
                        <?php esc_html_e( 'Cancel', 'creativewings-core' ); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_upload_script() {
        $i18n = [
            'cameraError' => __( 'Could not access the camera. Try “Choose image” instead.', 'creativewings-core' ),
            'fileChosen'  => __( 'File selected. Save submission when ready.', 'creativewings-core' ),
        ];
        ?>
        <script>
        (function(){
            var i18n = <?php echo wp_json_encode( $i18n ); ?>;
            var modal = document.getElementById('cw-camera-modal');
            var video = document.getElementById('cw-camera-video');
            var canvas = document.getElementById('cw-camera-canvas');
            var activeInput = null;
            var stream = null;

            function assignFile(input, file) {
                if (!input || !file) return;
                try {
                    var dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                } catch (e) {
                    return;
                }
                input.removeAttribute('required');
                updatePreview(input, file);
            }

            function updatePreview(input, file) {
                var idx = input.getAttribute('data-field-index');
                var box = document.getElementById('cw-preview-' + idx);
                if (!box) return;
                box.innerHTML = '';
                var type = (input.getAttribute('data-field-type') || '').toLowerCase();
                if (file && file.type && file.type.indexOf('image/') === 0) {
                    var img = document.createElement('img');
                    img.className = 'preview';
                    img.alt = '';
                    img.src = URL.createObjectURL(file);
                    box.appendChild(img);
                } else if (file) {
                    var p = document.createElement('p');
                    p.className = 'sub';
                    p.textContent = file.name + ' — ' + i18n.fileChosen;
                    box.appendChild(p);
                }
            }

            function stopStream() {
                if (stream) {
                    stream.getTracks().forEach(function(t){ t.stop(); });
                    stream = null;
                }
                if (video) video.srcObject = null;
            }

            function closeModal() {
                if (!modal) return;
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
                stopStream();
                activeInput = null;
            }

            function openModal(input) {
                activeInput = input;
                if (!modal || !video || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                    openNativeCamera(input);
                    return;
                }
                stopStream();
                navigator.mediaDevices.getUserMedia({
                    video: { facingMode: { ideal: 'environment' } },
                    audio: false
                }).then(function(s){
                    stream = s;
                    video.srcObject = s;
                    modal.classList.add('is-open');
                    modal.setAttribute('aria-hidden', 'false');
                }).catch(function(){
                    openNativeCamera(input);
                });
            }

            function openNativeCamera(input) {
                var cam = document.createElement('input');
                cam.type = 'file';
                cam.accept = 'image/*';
                cam.setAttribute('capture', 'environment');
                cam.style.cssText = 'position:absolute;left:-9999px;';
                document.body.appendChild(cam);
                cam.addEventListener('change', function(){
                    if (cam.files && cam.files[0]) {
                        assignFile(input, cam.files[0]);
                    }
                    cam.remove();
                });
                cam.click();
            }

            function capturePhoto() {
                if (!activeInput || !video || !canvas) return;
                var w = video.videoWidth || 1280;
                var h = video.videoHeight || 720;
                if (!w || !h) return;
                canvas.width = w;
                canvas.height = h;
                var ctx = canvas.getContext('2d');
                ctx.drawImage(video, 0, 0, w, h);
                canvas.toBlob(function(blob){
                    if (!blob) return;
                    var name = 'photo-' + Date.now() + '.jpg';
                    var file = new File([blob], name, { type: 'image/jpeg' });
                    assignFile(activeInput, file);
                    closeModal();
                }, 'image/jpeg', 0.92);
            }

            document.querySelectorAll('.cw-pic-open-gallery').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id = btn.getAttribute('data-target');
                    var input = id ? document.getElementById(id) : null;
                    if (input) input.click();
                });
            });

            document.querySelectorAll('.cw-pic-open-camera').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var id = btn.getAttribute('data-target');
                    var input = id ? document.getElementById(id) : null;
                    if (!input) return;
                    var isMobile = /Android|iPhone|iPad|iPod/i.test(navigator.userAgent);
                    if (isMobile) {
                        openNativeCamera(input);
                    } else {
                        openModal(input);
                    }
                });
            });

            document.querySelectorAll('.cw-field-file').forEach(function(input){
                input.addEventListener('change', function(){
                    if (input.files && input.files[0]) {
                        assignFile(input, input.files[0]);
                    }
                });
            });

            var capBtn = document.getElementById('cw-camera-capture');
            var cancelBtn = document.getElementById('cw-camera-cancel');
            if (capBtn) capBtn.addEventListener('click', capturePhoto);
            if (cancelBtn) cancelBtn.addEventListener('click', closeModal);
            if (modal) {
                modal.addEventListener('click', function(e){
                    if (e.target === modal) closeModal();
                });
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
            wp_safe_redirect( add_query_arg( [ 'error' => rawurlencode( 'Already claimed - cannot edit via staff link.' ) ], $base ) );
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

        wp_safe_redirect( add_query_arg( [ 'saved' => '1' ], $base ) );
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
