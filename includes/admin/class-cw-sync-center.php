<?php
/**
 * Tools -> CW Sync Center
 *
 * Single page that consolidates the "sync existing data" actions:
 *   - Bulk re-evaluate badges (uses CW_Badges_Engine::reevaluate_batch)
 *   - Bulk image optimizer (links to its own page)
 *   - Cache rebuild (busts cw_cache groups)
 *   - Sponsor coupons sync (existing helper, optional)
 *   - School upload tokens sync (existing helper, optional)
 *
 * Designed for use after seeding new badges or thresholds — also a friendly
 * hub for the admin to keep the system in sync.
 *
 * @package CreativeWings
 * @since   11.0.60
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Sync_Center {

    const PROGRESS_OPTION = 'cw_sync_badges_progress';
    const NONCE_ACTION    = 'cw_sync_center';
    const BATCH_SIZE      = 50;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'admin_bar_menu', [ $this, 'register_admin_bar' ], 100 );
        add_action( 'wp_ajax_cw_sync_badges_run',     [ $this, 'ajax_badges_run' ] );
        add_action( 'wp_ajax_cw_sync_badges_reset',   [ $this, 'ajax_badges_reset' ] );
        add_action( 'wp_ajax_cw_sync_cache_bust',     [ $this, 'ajax_cache_bust' ] );
        add_action( 'wp_ajax_cw_sync_all_step',       [ $this, 'ajax_sync_all_step' ] );
        add_action( 'wp_ajax_cw_sync_tokens_step',    [ $this, 'ajax_tokens_step' ] );
        add_action( 'wp_ajax_cw_sync_coupons_step',   [ $this, 'ajax_coupons_step' ] );
        add_action( 'wp_ajax_cw_sync_images_step',    [ $this, 'ajax_images_step' ] );
        add_action( 'admin_post_cw_sync_school_tokens',   [ $this, 'handle_school_tokens_sync' ] );
        add_action( 'admin_post_cw_sync_sponsor_coupons', [ $this, 'handle_sponsor_coupons_sync' ] );
    }

    public function register_menu() {
        add_management_page(
            __( 'CW Sync Center', 'creativewings-core' ),
            __( 'CW Sync Center', 'creativewings-core' ),
            'manage_options',
            'cw-sync-center',
            [ $this, 'render_page' ]
        );
    }

    /**
     * Add a one-click shortcut into the WP admin bar so the Sync Center is
     * reachable from any backend page (and from the front-end when the admin
     * is logged in).
     */
    public function register_admin_bar( $wp_admin_bar ) {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $wp_admin_bar->add_node( [
            'id'    => 'cw-sync-center',
            'title' => '<span class="ab-icon dashicons dashicons-update" style="font-size:18px;line-height:1.6;"></span> ' . esc_html__( 'CW Sync', 'creativewings-core' ),
            'href'  => admin_url( 'tools.php?page=cw-sync-center' ),
            'meta'  => [ 'title' => __( 'CreativeWings — Sync Center', 'creativewings-core' ) ],
        ] );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Page
     * ────────────────────────────────────────────────────────────────── */

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No.' );

        $progress = (array) get_option( self::PROGRESS_OPTION, [] );
        $total    = $this->total_users();
        $done     = (int) ( $progress['processed'] ?? 0 );
        $awarded  = (int) ( $progress['awarded'] ?? 0 );
        $last_id  = (int) ( $progress['last_id'] ?? 0 );
        $running  = ! empty( $progress['running'] );
        $pct      = $total > 0 ? min( 100, (int) floor( $done / $total * 100 ) ) : 0;
        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        ?>
        <div class="wrap">
            <h1>
                <i class="dashicons dashicons-update" style="font-size:28px;vertical-align:middle;"></i>
                <?php esc_html_e( 'CreativeWings — Sync Center', 'creativewings-core' ); ?>
            </h1>
            <p class="description" style="font-size:14px;color:#475569;max-width:780px;">
                <?php esc_html_e( "Keep the system in sync after seeding new badges, changing thresholds, or upgrading the plugin. Each tile runs independently in the background — close the tab and we'll keep batches small enough to resume.", 'creativewings-core' ); ?>
            </p>
            <?php if ( isset( $_GET['cw_synced'] ) ) : ?>
                <div class="notice notice-success" style="margin-top:12px;"><p>
                    <?php echo esc_html( sprintf( __( '✓ Sync complete — %s campaigns processed.', 'creativewings-core' ), (int) $_GET['cw_synced'] ) ); ?>
                </p></div>
            <?php endif; ?>

            <!-- ── MASTER "SYNC EVERYTHING" HERO ─────────────────────── -->
            <div class="cw-sync-hero" style="margin-top:22px;max-width:1100px;">
                <div class="cw-sync-hero-inner">
                    <div class="cw-sync-hero-icon">
                        <i class="dashicons dashicons-update"></i>
                    </div>
                    <div class="cw-sync-hero-body">
                        <h2><?php esc_html_e( 'Sync everything', 'creativewings-core' ); ?></h2>
                        <p>
                            <?php esc_html_e( 'One click to run every catch-up task in order: rebuild caches, re-evaluate badges for all users, sync school upload tokens, and refresh sponsor coupons. Image optimization runs as the final step (resumable later if it gets long).', 'creativewings-core' ); ?>
                        </p>
                        <div class="cw-sync-hero-actions">
                            <button type="button" class="button button-primary button-hero" id="cw-sync-all-start">
                                <i class="dashicons dashicons-controls-play"></i> <?php esc_html_e( 'Sync everything now', 'creativewings-core' ); ?>
                            </button>
                            <button type="button" class="button" id="cw-sync-all-stop" disabled>
                                <i class="dashicons dashicons-controls-pause"></i> <?php esc_html_e( 'Stop', 'creativewings-core' ); ?>
                            </button>
                        </div>
                    </div>
                    <div class="cw-sync-hero-status" id="cw-sync-all-status">
                        <div class="cw-sync-hero-stage" id="cw-sync-all-stage"><?php esc_html_e( 'Idle — ready to sync.', 'creativewings-core' ); ?></div>
                        <div class="cw-sync-hero-bar"><div class="cw-sync-hero-bar-fill" id="cw-sync-all-bar"></div></div>
                        <ul class="cw-sync-hero-steps" id="cw-sync-all-steps">
                            <li data-step="cache"><span class="dot"></span> <?php esc_html_e( '1. Rebuild caches', 'creativewings-core' ); ?></li>
                            <li data-step="badges"><span class="dot"></span> <?php esc_html_e( '2. Re-evaluate badges', 'creativewings-core' ); ?></li>
                            <li data-step="tokens"><span class="dot"></span> <?php esc_html_e( '3. Sync school upload tokens', 'creativewings-core' ); ?></li>
                            <li data-step="coupons"><span class="dot"></span> <?php esc_html_e( '4. Sync sponsor coupons', 'creativewings-core' ); ?></li>
                            <li data-step="images"><span class="dot"></span> <?php esc_html_e( '5. Optimize images (batch)', 'creativewings-core' ); ?></li>
                        </ul>
                    </div>
                </div>
                <pre class="cw-sync-log" id="cw-sync-all-log" style="margin-top:14px;"></pre>
            </div>

            <h2 style="margin:32px 0 4px;font-size:15px;font-weight:700;color:#475569;text-transform:uppercase;letter-spacing:0.05em;">
                <?php esc_html_e( 'Or run each task individually', 'creativewings-core' ); ?>
            </h2>

            <div style="display:grid;grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));gap:18px;margin-top:14px;max-width:1100px;">

                <!-- ── BADGES ────────────────────────────────────────────── -->
                <div class="cw-sync-card">
                    <div class="cw-sync-card-head">
                        <span class="cw-sync-icon" style="background:#fef3c7;color:#92580c;"><i class="dashicons dashicons-awards"></i></span>
                        <div>
                            <h2><?php esc_html_e( 'Re-evaluate badges', 'creativewings-core' ); ?></h2>
                            <p><?php esc_html_e( 'Walk every user in batches of 50 and run the badge engine. Awards any catch-up badges from new rules or threshold changes.', 'creativewings-core' ); ?></p>
                        </div>
                    </div>
                    <div class="cw-sync-progress">
                        <div class="cw-sync-bar"><div class="cw-sync-bar-fill" id="cw-sync-bar-badges" style="width: <?php echo (int) $pct; ?>%;"></div></div>
                        <div class="cw-sync-stat" id="cw-sync-stat-badges">
                            <strong><?php echo number_format_i18n( $done ); ?></strong> / <?php echo number_format_i18n( $total ); ?>
                            <?php esc_html_e( 'users', 'creativewings-core' ); ?> —
                            <strong><?php echo number_format_i18n( $awarded ); ?></strong> <?php esc_html_e( 'awards made', 'creativewings-core' ); ?>
                        </div>
                    </div>
                    <div class="cw-sync-actions">
                        <button type="button" class="button button-primary" id="cw-sync-badges-start">
                            <i class="dashicons dashicons-controls-play"></i> <?php echo $running ? esc_html__( 'Resume', 'creativewings-core' ) : esc_html__( 'Start', 'creativewings-core' ); ?>
                        </button>
                        <button type="button" class="button" id="cw-sync-badges-stop"><i class="dashicons dashicons-controls-pause"></i> <?php esc_html_e( 'Pause', 'creativewings-core' ); ?></button>
                        <button type="button" class="button button-link-delete" id="cw-sync-badges-reset"><?php esc_html_e( 'Reset', 'creativewings-core' ); ?></button>
                    </div>
                    <pre class="cw-sync-log" id="cw-sync-badges-log"></pre>
                </div>

                <!-- ── IMAGE OPTIMIZER ────────────────────────────────── -->
                <div class="cw-sync-card">
                    <div class="cw-sync-card-head">
                        <span class="cw-sync-icon" style="background:#dbeafe;color:#1d4ed8;"><i class="dashicons dashicons-images-alt2"></i></span>
                        <div>
                            <h2><?php esc_html_e( 'Bulk image optimizer', 'creativewings-core' ); ?></h2>
                            <p><?php esc_html_e( 'Re-compress and create WebP twins for existing media uploads. Already pre-built; opens in a dedicated tool.', 'creativewings-core' ); ?></p>
                        </div>
                    </div>
                    <div class="cw-sync-actions">
                        <a class="button button-primary" href="<?php echo esc_url( admin_url( 'tools.php?page=cw-image-optimizer' ) ); ?>"><i class="dashicons dashicons-external"></i> <?php esc_html_e( 'Open optimizer', 'creativewings-core' ); ?></a>
                    </div>
                </div>

                <!-- ── CACHE ──────────────────────────────────────────── -->
                <div class="cw-sync-card">
                    <div class="cw-sync-card-head">
                        <span class="cw-sync-icon" style="background:#ede9fe;color:#6d28d9;"><i class="dashicons dashicons-database"></i></span>
                        <div>
                            <h2><?php esc_html_e( 'Rebuild caches', 'creativewings-core' ); ?></h2>
                            <p><?php esc_html_e( 'Flush directory, organizer profile, report, and wallet caches. Use after editing public profiles or seeding new badges.', 'creativewings-core' ); ?></p>
                        </div>
                    </div>
                    <div class="cw-sync-actions">
                        <button type="button" class="button button-primary" id="cw-sync-cache-bust"><i class="dashicons dashicons-trash"></i> <?php esc_html_e( 'Bust caches now', 'creativewings-core' ); ?></button>
                    </div>
                    <pre class="cw-sync-log" id="cw-sync-cache-log"></pre>
                </div>

                <!-- ── SCHOOL UPLOAD ──────────────────────────────────── -->
                <?php if ( class_exists( 'CW_Staged_Submissions' ) && method_exists( 'CW_Staged_Submissions', 'sync_school_upload_tokens' ) ) : ?>
                <div class="cw-sync-card">
                    <div class="cw-sync-card-head">
                        <span class="cw-sync-icon" style="background:#dcfce7;color:#166534;"><i class="dashicons dashicons-welcome-learn-more"></i></span>
                        <div>
                            <h2><?php esc_html_e( 'School upload tokens', 'creativewings-core' ); ?></h2>
                            <p><?php esc_html_e( 'Regenerate or refresh the school-upload tokens for every active campaign.', 'creativewings-core' ); ?></p>
                        </div>
                    </div>
                    <div class="cw-sync-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="cw_sync_school_tokens">
                            <?php wp_nonce_field( self::NONCE_ACTION, 'cw_sync_nonce' ); ?>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Sync school tokens', 'creativewings-core' ); ?></button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- ── SPONSOR COUPONS ────────────────────────────────── -->
                <?php if ( class_exists( 'CW_Sponsor_Coupons' ) && method_exists( 'CW_Sponsor_Coupons', 'sync_campaign_coupons' ) ) : ?>
                <div class="cw-sync-card">
                    <div class="cw-sync-card-head">
                        <span class="cw-sync-icon" style="background:#fee2e2;color:#b91c1c;"><i class="dashicons dashicons-tickets-alt"></i></span>
                        <div>
                            <h2><?php esc_html_e( 'Sponsor coupons', 'creativewings-core' ); ?></h2>
                            <p><?php esc_html_e( 'Rebuild the sponsor coupon ledger from current campaign configuration.', 'creativewings-core' ); ?></p>
                        </div>
                    </div>
                    <div class="cw-sync-actions">
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                            <input type="hidden" name="action" value="cw_sync_sponsor_coupons">
                            <?php wp_nonce_field( self::NONCE_ACTION, 'cw_sync_nonce' ); ?>
                            <button type="submit" class="button button-primary"><?php esc_html_e( 'Sync sponsor coupons', 'creativewings-core' ); ?></button>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div>

            <style>
                .cw-sync-card { background:#fff;border:1px solid #e5e7eb;border-radius:12px;padding:18px;box-shadow:0 2px 8px rgba(15,23,42,0.04); }
                .cw-sync-card-head { display:flex;gap:12px;align-items:flex-start;margin-bottom:14px; }
                .cw-sync-icon { width:46px;height:46px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:22px;flex-shrink:0; }
                .cw-sync-icon .dashicons { font-size:22px;width:22px;height:22px; }
                .cw-sync-card h2 { margin:0 0 4px;font-size:15px;font-weight:800;color:#0f172a; }
                .cw-sync-card p { margin:0;font-size:13px;color:#475569;line-height:1.4; }
                .cw-sync-progress { margin:8px 0 12px; }
                .cw-sync-bar { height:8px;background:#e5e7eb;border-radius:999px;overflow:hidden;margin-bottom:6px; }
                .cw-sync-bar-fill { height:100%;background:linear-gradient(90deg,#0ea5e9,#6366f1);width:0%;transition:width .35s ease; }
                .cw-sync-stat { font-size:12px;color:#475569; }
                .cw-sync-actions { display:flex;gap:8px;flex-wrap:wrap; }
                .cw-sync-log { background:#0f172a;color:#94a3b8;font-size:11px;line-height:1.5;padding:8px;border-radius:6px;max-height:140px;overflow:auto;margin-top:10px;display:none; }
                .cw-sync-log.cw-show { display:block; }

                /* Master "Sync everything" hero */
                .cw-sync-hero {
                    background: linear-gradient(135deg, #0f172a 0%, #1e3a8a 60%, #6366f1 100%);
                    color: #fff;
                    border-radius: 16px;
                    padding: 28px 30px;
                    box-shadow: 0 10px 30px rgba(15,23,42,0.18);
                    position: relative;
                    overflow: hidden;
                }
                .cw-sync-hero::after {
                    content:"";
                    position:absolute;
                    inset:0;
                    background: radial-gradient(circle at 90% 10%, rgba(255,255,255,0.18), transparent 45%);
                    pointer-events:none;
                }
                .cw-sync-hero-inner {
                    position:relative;
                    z-index:1;
                    display:grid;
                    grid-template-columns: 76px 1fr 320px;
                    gap:22px;
                    align-items:center;
                }
                .cw-sync-hero-icon {
                    width:76px;height:76px;border-radius:50%;
                    background: rgba(255,255,255,0.12);
                    display:inline-flex;align-items:center;justify-content:center;
                    box-shadow: inset 0 1px 0 rgba(255,255,255,0.18);
                }
                .cw-sync-hero-icon .dashicons {
                    font-size:36px;width:36px;height:36px;color:#fff;
                    animation: cwSyncSpin 6s linear infinite;
                    animation-play-state: paused;
                }
                .cw-sync-hero.is-running .cw-sync-hero-icon .dashicons { animation-play-state: running; }
                @keyframes cwSyncSpin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
                .cw-sync-hero-body h2 { color:#fff;margin:0 0 6px;font-size:22px;font-weight:800; }
                .cw-sync-hero-body p { color:rgba(255,255,255,0.85);margin:0 0 14px;font-size:13.5px;line-height:1.5;max-width:560px; }
                .cw-sync-hero-actions { display:flex;gap:10px;flex-wrap:wrap; }
                .cw-sync-hero-actions .button-hero {
                    background:#facc15 !important;
                    color:#0f172a !important;
                    border-color:#facc15 !important;
                    font-weight:800;
                    text-shadow:none;
                    box-shadow:0 6px 18px rgba(250,204,21,0.35);
                }
                .cw-sync-hero-actions .button-hero:hover { filter:brightness(1.04); }
                .cw-sync-hero-actions .button-hero[disabled],
                .cw-sync-hero-actions .button[disabled] { opacity:0.55; }
                .cw-sync-hero-status {
                    background: rgba(255,255,255,0.08);
                    border-radius:12px;
                    padding:14px;
                    border: 1px solid rgba(255,255,255,0.15);
                }
                .cw-sync-hero-stage { font-size:12px;color:rgba(255,255,255,0.78);margin-bottom:8px;font-weight:600; }
                .cw-sync-hero-bar { height:8px;background:rgba(255,255,255,0.15);border-radius:999px;overflow:hidden; }
                .cw-sync-hero-bar-fill { height:100%;width:0%;background:linear-gradient(90deg,#facc15,#fb923c);transition:width .35s ease; }
                .cw-sync-hero-steps { list-style:none;margin:10px 0 0;padding:0;font-size:12px;color:rgba(255,255,255,0.85);display:flex;flex-direction:column;gap:4px; }
                .cw-sync-hero-steps li { display:flex;align-items:center;gap:8px;opacity:0.7; }
                .cw-sync-hero-steps li .dot { width:8px;height:8px;border-radius:50%;background:rgba(255,255,255,0.3);flex-shrink:0; }
                .cw-sync-hero-steps li.is-active { opacity:1;color:#facc15;font-weight:600; }
                .cw-sync-hero-steps li.is-active .dot { background:#facc15;box-shadow:0 0 0 4px rgba(250,204,21,0.25); }
                .cw-sync-hero-steps li.is-done { opacity:1; }
                .cw-sync-hero-steps li.is-done .dot { background:#34d399; }

                @media (max-width: 900px) {
                    .cw-sync-hero-inner { grid-template-columns: 1fr; }
                    .cw-sync-hero-icon { width:54px;height:54px; }
                    .cw-sync-hero-icon .dashicons { font-size:24px;width:24px;height:24px; }
                }
            </style>

            <script>
            (function(){
                var nonce = '<?php echo esc_js( $nonce ); ?>';
                var totalUsers = <?php echo (int) $total; ?>;
                var badgesRunning = false;

                function log(elId, line){
                    var el = document.getElementById(elId);
                    el.classList.add('cw-show');
                    el.textContent = (el.textContent || '') + line + "\n";
                    el.scrollTop = el.scrollHeight;
                }

                function updateBadgesUI( data ) {
                    var pct = totalUsers > 0 ? Math.min(100, Math.floor(data.processed / totalUsers * 100)) : 100;
                    document.getElementById('cw-sync-bar-badges').style.width = pct + '%';
                    document.getElementById('cw-sync-stat-badges').innerHTML =
                        '<strong>' + data.processed.toLocaleString() + '</strong> / ' + totalUsers.toLocaleString() +
                        ' users — <strong>' + data.awarded.toLocaleString() + '</strong> awards made';
                }

                function badgesTick() {
                    if ( ! badgesRunning ) return;
                    fetch( ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: 'action=cw_sync_badges_run&_wpnonce=' + encodeURIComponent(nonce)
                    } )
                    .then(function(r){ return r.json(); })
                    .then(function(res){
                        if ( ! res || ! res.success ) {
                            log('cw-sync-badges-log', '✗ ' + ( res && res.data && res.data.message ? res.data.message : 'Unknown error' ) );
                            badgesRunning = false;
                            return;
                        }
                        var d = res.data;
                        updateBadgesUI(d);
                        log('cw-sync-badges-log', '• Batch processed: +' + d.batch_processed + ' users, +' + d.batch_awarded + ' awards (last user_id: ' + d.last_id + ')');
                        if ( d.has_more ) {
                            setTimeout( badgesTick, 250 );
                        } else {
                            badgesRunning = false;
                            log('cw-sync-badges-log', '✓ Done. ' + d.processed.toLocaleString() + ' users processed, ' + d.awarded.toLocaleString() + ' total awards.');
                        }
                    } )
                    .catch(function(err){
                        log('cw-sync-badges-log', '✗ ' + err);
                        badgesRunning = false;
                    } );
                }

                document.getElementById('cw-sync-badges-start').addEventListener('click', function(){
                    if ( badgesRunning ) return;
                    badgesRunning = true;
                    log('cw-sync-badges-log', '→ Starting…');
                    badgesTick();
                });
                document.getElementById('cw-sync-badges-stop').addEventListener('click', function(){
                    badgesRunning = false;
                    log('cw-sync-badges-log', '⏸ Paused. Click Start to resume.');
                });
                document.getElementById('cw-sync-badges-reset').addEventListener('click', function(){
                    if ( ! confirm('<?php echo esc_js( __( 'Reset progress and start over?', 'creativewings-core' ) ); ?>') ) return;
                    fetch( ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: 'action=cw_sync_badges_reset&_wpnonce=' + encodeURIComponent(nonce)
                    } ).then(function(){
                        document.getElementById('cw-sync-bar-badges').style.width = '0%';
                        document.getElementById('cw-sync-stat-badges').innerHTML = '<strong>0</strong> / ' + totalUsers.toLocaleString() + ' users — <strong>0</strong> awards made';
                        log('cw-sync-badges-log', '↺ Progress reset.');
                    });
                });

                document.getElementById('cw-sync-cache-bust').addEventListener('click', function(){
                    var btn = this; btn.disabled = true;
                    fetch( ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: 'action=cw_sync_cache_bust&_wpnonce=' + encodeURIComponent(nonce)
                    } ).then(function(r){ return r.json(); }).then(function(res){
                        log('cw-sync-cache-log', res && res.data && res.data.message ? res.data.message : 'Done.');
                        btn.disabled = false;
                    } ).catch(function(){ btn.disabled = false; } );
                });

                /* ─────────────────────────────────────────────────────────
                 *  Master "Sync everything" orchestrator
                 *  Runs each step in sequence, with progress + cancellation.
                 * ───────────────────────────────────────────────────────── */
                var allRunning = false;
                var heroEl     = document.querySelector('.cw-sync-hero');
                var heroBar    = document.getElementById('cw-sync-all-bar');
                var heroStage  = document.getElementById('cw-sync-all-stage');
                var heroLog    = document.getElementById('cw-sync-all-log');
                var heroStart  = document.getElementById('cw-sync-all-start');
                var heroStop   = document.getElementById('cw-sync-all-stop');
                var stepsEl    = document.getElementById('cw-sync-all-steps');

                var STEPS = [
                    { id: 'cache',   label: '<?php echo esc_js( __( 'Rebuilding caches…',           'creativewings-core' ) ); ?>' },
                    { id: 'badges',  label: '<?php echo esc_js( __( 'Re-evaluating badges…',        'creativewings-core' ) ); ?>' },
                    { id: 'tokens',  label: '<?php echo esc_js( __( 'Syncing school upload tokens…', 'creativewings-core' ) ); ?>' },
                    { id: 'coupons', label: '<?php echo esc_js( __( 'Syncing sponsor coupons…',     'creativewings-core' ) ); ?>' },
                    { id: 'images',  label: '<?php echo esc_js( __( 'Optimizing images…',           'creativewings-core' ) ); ?>' }
                ];

                function setStep( idx, state ) {
                    if ( ! stepsEl ) return;
                    var items = stepsEl.querySelectorAll('li');
                    for ( var i = 0; i < items.length; i++ ) {
                        items[i].classList.remove('is-active');
                        if ( i < idx ) items[i].classList.add('is-done');
                        if ( i === idx && state !== 'done' ) items[i].classList.add('is-active');
                    }
                }

                function setProgress( pct, stage ) {
                    heroBar.style.width = Math.min(100, Math.max(0, pct)) + '%';
                    if ( stage ) heroStage.textContent = stage;
                }

                function heroLogLine( txt ) {
                    heroLog.classList.add('cw-show');
                    heroLog.textContent += txt + "\n";
                    heroLog.scrollTop = heroLog.scrollHeight;
                }

                function callAjax( action, extra ) {
                    var body = 'action=' + action + '&_wpnonce=' + encodeURIComponent(nonce);
                    if ( extra ) body += '&' + extra;
                    return fetch( ajaxurl, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
                        body: body
                    } ).then(function(r){ return r.json(); });
                }

                function runUntilDone( action, label ) {
                    // Calls $action repeatedly until res.data.has_more is false.
                    function tick() {
                        if ( ! allRunning ) return Promise.reject('cancelled');
                        return callAjax( action ).then(function(res){
                            if ( ! res || ! res.success ) {
                                throw new Error( ( res && res.data && res.data.message ) || 'Step failed' );
                            }
                            var d = res.data;
                            if ( d.progress_msg ) heroLogLine( '  ' + d.progress_msg );
                            if ( d.has_more ) {
                                return new Promise(function(rs){ setTimeout(function(){ rs(tick()); }, 200); });
                            }
                            return d;
                        });
                    }
                    return tick();
                }

                function runAll() {
                    if ( allRunning ) return;
                    allRunning = true;
                    heroEl.classList.add('is-running');
                    heroStart.disabled = true;
                    heroStop.disabled  = false;
                    heroLog.textContent = '';
                    setProgress( 0, STEPS[0].label );
                    setStep( 0 );
                    heroLogLine( '→ Starting full sync…' );

                    // 1. Cache flush
                    callAjax('cw_sync_cache_bust').then(function(res){
                        if ( ! allRunning ) throw 'cancelled';
                        heroLogLine( '✓ ' + ( res && res.data && res.data.message ? res.data.message : 'Caches flushed.' ) );
                        setProgress( 20, STEPS[1].label );
                        setStep( 1 );

                        // 2. Badge re-evaluate (resumable batches)
                        return runUntilDone('cw_sync_all_step', '');
                    }).then(function(){
                        if ( ! allRunning ) throw 'cancelled';
                        heroLogLine( '✓ Badge re-evaluation complete.' );
                        setProgress( 50, STEPS[2].label );
                        setStep( 2 );

                        // 3. School upload tokens
                        return runUntilDone('cw_sync_tokens_step', '');
                    }).then(function(){
                        if ( ! allRunning ) throw 'cancelled';
                        heroLogLine( '✓ School upload tokens synced.' );
                        setProgress( 70, STEPS[3].label );
                        setStep( 3 );

                        // 4. Sponsor coupons
                        return runUntilDone('cw_sync_coupons_step', '');
                    }).then(function(){
                        if ( ! allRunning ) throw 'cancelled';
                        heroLogLine( '✓ Sponsor coupons synced.' );
                        setProgress( 85, STEPS[4].label );
                        setStep( 4 );

                        // 5. Image optimization (one batch — full optimizer lives on its own page)
                        return runUntilDone('cw_sync_images_step', '');
                    }).then(function(){
                        if ( ! allRunning ) return;
                        heroLogLine( '✓ Image optimization batch complete.' );
                        setProgress( 100, '<?php echo esc_js( __( 'All done.', 'creativewings-core' ) ); ?>' );
                        setStep( STEPS.length, 'done' );
                        heroLogLine( '🎉 Full sync finished.' );
                        finish();
                    }).catch(function(err){
                        if ( err === 'cancelled' ) {
                            heroLogLine( '⏸ Cancelled by user.' );
                        } else {
                            heroLogLine( '✗ ' + ( err && err.message ? err.message : err ) );
                        }
                        finish();
                    });
                }

                function finish() {
                    allRunning = false;
                    heroEl.classList.remove('is-running');
                    heroStart.disabled = false;
                    heroStop.disabled  = true;
                }

                if ( heroStart ) heroStart.addEventListener('click', runAll);
                if ( heroStop )  heroStop.addEventListener('click', function(){
                    allRunning = false;
                    heroStop.disabled = true;
                    heroLogLine( '⏸ Stopping after current batch…' );
                });
            })();
            </script>
        </div>
        <?php
    }

    /* ──────────────────────────────────────────────────────────────────
     *  AJAX
     * ────────────────────────────────────────────────────────────────── */

    public function ajax_badges_run() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );

        $progress = (array) get_option( self::PROGRESS_OPTION, [] );
        $processed = (int) ( $progress['processed'] ?? 0 );
        $awarded   = (int) ( $progress['awarded'] ?? 0 );
        $last_id   = (int) ( $progress['last_id'] ?? 0 );

        $batch = CW_Badges_Engine::reevaluate_batch( $last_id, self::BATCH_SIZE );

        $processed += (int) $batch['processed'];
        $awarded   += (int) $batch['awarded'];
        $last_id    = (int) $batch['last_id'];

        update_option( self::PROGRESS_OPTION, [
            'processed' => $processed,
            'awarded'   => $awarded,
            'last_id'   => $last_id,
            'running'   => ! empty( $batch['has_more'] ),
            'updated_at'=> current_time( 'mysql' ),
        ], false );

        wp_send_json_success( [
            'processed'       => $processed,
            'awarded'         => $awarded,
            'last_id'         => $last_id,
            'has_more'        => (bool) $batch['has_more'],
            'batch_processed' => (int) $batch['processed'],
            'batch_awarded'   => (int) $batch['awarded'],
        ] );
    }

    public function ajax_badges_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );
        delete_option( self::PROGRESS_OPTION );
        wp_send_json_success( [ 'message' => 'reset' ] );
    }

    public function ajax_cache_bust() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );
        if ( class_exists( 'CW_Cache' ) ) {
            foreach ( [ 'org_profile', 'directory', 'reports', 'biz_dash', 'wallet' ] as $g ) {
                CW_Cache::bust_group( $g );
            }
        }
        // Built-in transient bust as a safety net.
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cw_%' OR option_name LIKE '_transient_timeout_cw_%'" );

        wp_send_json_success( [ 'message' => __( '✓ Caches flushed.', 'creativewings-core' ) ] );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Master orchestrator step endpoints
     *  Each call processes ONE batch and returns has_more so the JS can
     *  keep polling until the step is finished. Cursors are option-backed
     *  so a hard refresh resumes safely.
     * ────────────────────────────────────────────────────────────────── */

    /** Step 2 of master sync: badge re-evaluation. */
    public function ajax_sync_all_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );

        $cursor_key = 'cw_sync_all_badges_cursor';
        $last_id    = (int) get_option( $cursor_key, 0 );
        $batch      = CW_Badges_Engine::reevaluate_batch( $last_id, self::BATCH_SIZE );

        if ( $batch['has_more'] ) {
            update_option( $cursor_key, (int) $batch['last_id'], false );
        } else {
            delete_option( $cursor_key );
        }

        wp_send_json_success( [
            'has_more'     => (bool) $batch['has_more'],
            'processed'    => (int) $batch['processed'],
            'awarded'      => (int) $batch['awarded'],
            'last_id'      => (int) $batch['last_id'],
            'progress_msg' => sprintf( 'Users %d → +%d evaluated, +%d awards', $last_id, $batch['processed'], $batch['awarded'] ),
        ] );
    }

    /** Step 3: school upload tokens (one campaign per call). */
    public function ajax_tokens_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );

        $cursor_key = 'cw_sync_tokens_cursor';

        if ( ! class_exists( 'CW_Staged_Submissions' )
          || ! method_exists( 'CW_Staged_Submissions', 'sync_school_upload_tokens' ) ) {
            delete_option( $cursor_key );
            wp_send_json_success( [ 'has_more' => false, 'progress_msg' => 'School-upload tokens helper not available — skipped.' ] );
        }

        global $wpdb;
        $offset = (int) get_option( $cursor_key, 0 );
        $batch_size = 10;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d, %d",
            $offset, $batch_size
        ) );

        $count = 0;
        foreach ( (array) $ids as $pid ) {
            CW_Staged_Submissions::sync_school_upload_tokens( (int) $pid );
            $count++;
        }

        $has_more = count( (array) $ids ) === $batch_size;
        if ( $has_more ) {
            update_option( $cursor_key, $offset + $batch_size, false );
        } else {
            delete_option( $cursor_key );
        }

        wp_send_json_success( [
            'has_more'     => $has_more,
            'progress_msg' => sprintf( 'Tokens %d–%d (+%d campaigns)', $offset + 1, $offset + $count, $count ),
        ] );
    }

    /** Step 4: sponsor coupons (one campaign per call). */
    public function ajax_coupons_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );

        $cursor_key = 'cw_sync_coupons_cursor';

        if ( ! class_exists( 'CW_Sponsor_Coupons' )
          || ! method_exists( 'CW_Sponsor_Coupons', 'sync_campaign_coupons' ) ) {
            delete_option( $cursor_key );
            wp_send_json_success( [ 'has_more' => false, 'progress_msg' => 'Sponsor coupons helper not available — skipped.' ] );
        }

        global $wpdb;
        $offset = (int) get_option( $cursor_key, 0 );
        $batch_size = 10;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'product' AND post_status = 'publish' ORDER BY ID ASC LIMIT %d, %d",
            $offset, $batch_size
        ) );

        $count = 0;
        foreach ( (array) $ids as $pid ) {
            CW_Sponsor_Coupons::sync_campaign_coupons( (int) $pid );
            $count++;
        }

        $has_more = count( (array) $ids ) === $batch_size;
        if ( $has_more ) {
            update_option( $cursor_key, $offset + $batch_size, false );
        } else {
            delete_option( $cursor_key );
        }

        wp_send_json_success( [
            'has_more'     => $has_more,
            'progress_msg' => sprintf( 'Coupons %d–%d (+%d campaigns)', $offset + 1, $offset + $count, $count ),
        ] );
    }

    /** Step 5: image optimization (one batch — full sweep runs in its own tool). */
    public function ajax_images_step() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( [ 'message' => 'No' ] );
        check_ajax_referer( self::NONCE_ACTION );

        // We just run a single bounded batch here so the master flow stays snappy;
        // for the full sweep the admin should use the dedicated CW Image Optimizer.
        if ( ! class_exists( 'CW_Image_Optimizer' ) ) {
            wp_send_json_success( [ 'has_more' => false, 'progress_msg' => 'Image optimizer not available — skipped.' ] );
        }

        $cursor_key = 'cw_sync_images_cursor';
        $offset     = (int) get_option( $cursor_key, 0 );
        $batch_size = 10;

        global $wpdb;
        $atts = $wpdb->get_results( $wpdb->prepare(
            "SELECT p.ID
             FROM {$wpdb->posts} p
             LEFT JOIN {$wpdb->postmeta} flag
                ON flag.post_id = p.ID AND flag.meta_key = '_cw_optimized'
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type LIKE 'image/%'
               AND flag.meta_value IS NULL
             ORDER BY p.ID ASC
             LIMIT %d, %d",
            $offset, $batch_size
        ), ARRAY_A );

        $count = 0;
        foreach ( (array) $atts as $row ) {
            CW_Image_Optimizer::optimize_attachment( (int) $row['ID'], 'attachment' );
            $count++;
        }

        // Stop after 5 batches per orchestrator run so we don't tie up the tab forever.
        $batches_processed = (int) get_option( 'cw_sync_images_batches', 0 ) + 1;
        $has_more = ( $count === $batch_size ) && ( $batches_processed < 5 );

        if ( $has_more ) {
            update_option( $cursor_key, $offset + $batch_size, false );
            update_option( 'cw_sync_images_batches', $batches_processed, false );
        } else {
            delete_option( $cursor_key );
            delete_option( 'cw_sync_images_batches' );
        }

        wp_send_json_success( [
            'has_more'     => $has_more,
            'progress_msg' => sprintf( 'Images %d–%d (+%d). For a full sweep, run CW Image Optimizer.', $offset + 1, $offset + $count, $count ),
        ] );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Helpers
     * ────────────────────────────────────────────────────────────────── */

    private function total_users() {
        global $wpdb;
        return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->users}" );
    }

    public function handle_school_tokens_sync() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No' );
        check_admin_referer( self::NONCE_ACTION, 'cw_sync_nonce' );

        $synced = 0;
        if ( class_exists( 'CW_Staged_Submissions' ) && method_exists( 'CW_Staged_Submissions', 'sync_school_upload_tokens' ) ) {
            $ids = get_posts( [
                'post_type' => 'product', 'post_status' => 'publish',
                'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            ] );
            foreach ( (array) $ids as $pid ) {
                CW_Staged_Submissions::sync_school_upload_tokens( (int) $pid );
                $synced++;
            }
        }

        wp_safe_redirect( add_query_arg( 'cw_synced', $synced, admin_url( 'tools.php?page=cw-sync-center' ) ) );
        exit;
    }

    public function handle_sponsor_coupons_sync() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( 'No' );
        check_admin_referer( self::NONCE_ACTION, 'cw_sync_nonce' );

        $synced = 0;
        if ( class_exists( 'CW_Sponsor_Coupons' ) && method_exists( 'CW_Sponsor_Coupons', 'sync_campaign_coupons' ) ) {
            $ids = get_posts( [
                'post_type' => 'product', 'post_status' => 'publish',
                'posts_per_page' => -1, 'fields' => 'ids', 'no_found_rows' => true,
            ] );
            foreach ( (array) $ids as $pid ) {
                CW_Sponsor_Coupons::sync_campaign_coupons( (int) $pid );
                $synced++;
            }
        }

        wp_safe_redirect( add_query_arg( 'cw_synced', $synced, admin_url( 'tools.php?page=cw-sync-center' ) ) );
        exit;
    }
}
