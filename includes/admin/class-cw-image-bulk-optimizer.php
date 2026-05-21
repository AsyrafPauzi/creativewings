<?php
/**
 * Tools -> CW Image Optimizer
 *
 * One-time bulk reprocessor for existing media. Three modes:
 *   - Dry run         : scan candidates, estimate savings, no changes.
 *   - Process batch   : optimize a batch of N attachments (default 25).
 *   - Restore         : copy original from /_cw-backup/ back to the attachment path.
 *
 * Each processed attachment is:
 *   1. Backed up to /wp-content/uploads/_cw-backup/{year}/{month}/{filename} (once).
 *   2. Optimized in place via CW_Image_Optimizer::optimize_attachment().
 *   3. Marked with attachment meta `_cw_optimized = 1` so it's skipped next run.
 *
 * Resumable: progress is stored in the `cw_bulk_opt_progress` option. The cron
 * fallback (Phase 5.2) picks up where the admin left off.
 *
 * @package CreativeWings
 * @since   11.0.59
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Image_Bulk_Optimizer {

    const PROGRESS_OPTION = 'cw_bulk_opt_progress';
    const NONCE_ACTION    = 'cw_bulk_opt';
    const BATCH_DEFAULT   = 25;
    const BATCH_MAX       = 200;
    const MIN_BYTES       = 200 * 1024; // 200 KB
    const CRON_HOOK       = 'cw_bulk_opt_tick';
    const CRON_BATCH      = 50;

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'register_menu' ] );
        add_action( 'wp_ajax_cw_bulk_opt_run',     [ $this, 'ajax_run' ] );
        add_action( 'wp_ajax_cw_bulk_opt_reset',   [ $this, 'ajax_reset' ] );
        add_action( 'wp_ajax_cw_bulk_opt_restore', [ $this, 'ajax_restore' ] );

        // Cron-based fallback so the queue keeps draining if the admin closes the tab.
        add_action( self::CRON_HOOK, [ $this, 'cron_tick' ] );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Menu + page
     * ────────────────────────────────────────────────────────────────── */

    public function register_menu() {
        add_management_page(
            __( 'CW Image Optimizer', 'creativewings-core' ),
            __( 'CW Image Optimizer', 'creativewings-core' ),
            'manage_options',
            'cw-image-optimizer',
            [ $this, 'render_page' ]
        );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ) );
        }
        $progress = self::get_progress();
        $totals   = $this->scan_totals();
        $nonce    = wp_create_nonce( self::NONCE_ACTION );
        $cron_on  = wp_next_scheduled( self::CRON_HOOK );
        ?>
        <div class="wrap cw-img-opt-wrap">
            <h1><?php esc_html_e( 'CW Image Optimizer', 'creativewings-core' ); ?></h1>
            <p style="max-width:760px;">
                <?php esc_html_e( 'Resize, recompress, and emit WebP siblings for the existing media library. New uploads are already optimized — this tool processes legacy images you uploaded before the optimizer was active.', 'creativewings-core' ); ?>
            </p>
            <p style="max-width:760px;">
                <a class="button" href="<?php echo esc_url( admin_url( 'tools.php?page=cw-sync-center' ) ); ?>">
                    <i class="dashicons dashicons-update" style="font-size:16px;line-height:1.5;vertical-align:middle;"></i>
                    <?php esc_html_e( 'Back to Sync Center', 'creativewings-core' ); ?>
                </a>
            </p>

            <div class="cw-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-top:16px;max-width:760px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Library status', 'creativewings-core' ); ?></h2>
                <ul style="list-style:disc;margin:8px 0 0 20px;">
                    <li><?php printf( esc_html__( 'Total image attachments: %s', 'creativewings-core' ), '<strong>' . esc_html( number_format_i18n( $totals['total'] ) ) . '</strong>' ); ?></li>
                    <li><?php printf( esc_html__( 'Eligible (not yet processed, ≥%1$s): %2$s', 'creativewings-core' ), esc_html( size_format( self::MIN_BYTES ) ), '<strong>' . esc_html( number_format_i18n( $totals['eligible'] ) ) . '</strong>' ); ?></li>
                    <li><?php printf( esc_html__( 'Already processed: %s', 'creativewings-core' ), '<strong>' . esc_html( number_format_i18n( $totals['processed'] ) ) . '</strong>' ); ?></li>
                </ul>
            </div>

            <div class="cw-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-top:16px;max-width:760px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Progress', 'creativewings-core' ); ?></h2>
                <ul id="cw-bulk-opt-progress" style="list-style:disc;margin:8px 0 0 20px;">
                    <li><?php printf( esc_html__( 'Last attachment scanned ID: %s', 'creativewings-core' ), '<strong id="cw-opt-last">' . esc_html( $progress['last_id'] ?? 0 ) . '</strong>' ); ?></li>
                    <li><?php printf( esc_html__( 'Processed: %s', 'creativewings-core' ), '<strong id="cw-opt-processed">' . esc_html( $progress['processed'] ?? 0 ) . '</strong>' ); ?></li>
                    <li><?php printf( esc_html__( 'Skipped: %s', 'creativewings-core' ), '<strong id="cw-opt-skipped">' . esc_html( $progress['skipped'] ?? 0 ) . '</strong>' ); ?></li>
                    <li><?php printf( esc_html__( 'Bytes saved: %s', 'creativewings-core' ), '<strong id="cw-opt-bytes">' . esc_html( size_format( (int) ( $progress['bytes_saved'] ?? 0 ) ) ) . '</strong>' ); ?></li>
                </ul>
                <p style="margin-top:14px;">
                    <strong><?php esc_html_e( 'Cron fallback:', 'creativewings-core' ); ?></strong>
                    <?php if ( $cron_on ) {
                        printf( esc_html__( 'enabled — next batch in %s', 'creativewings-core' ), human_time_diff( time(), $cron_on ) );
                    } else {
                        esc_html_e( 'disabled (toggled on automatically after the first batch)', 'creativewings-core' );
                    } ?>
                </p>
            </div>

            <div class="cw-card" style="background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:18px;margin-top:16px;max-width:760px;">
                <h2 style="margin-top:0;"><?php esc_html_e( 'Actions', 'creativewings-core' ); ?></h2>
                <p>
                    <label for="cw-opt-batch"><?php esc_html_e( 'Batch size:', 'creativewings-core' ); ?></label>
                    <input id="cw-opt-batch" type="number" min="1" max="<?php echo (int) self::BATCH_MAX; ?>" value="<?php echo (int) self::BATCH_DEFAULT; ?>" style="width:90px;">
                </p>
                <p>
                    <button type="button" class="button" id="cw-opt-dry"><?php esc_html_e( 'Dry run (estimate only)', 'creativewings-core' ); ?></button>
                    <button type="button" class="button button-primary" id="cw-opt-process"><?php esc_html_e( 'Process batch', 'creativewings-core' ); ?></button>
                    <button type="button" class="button" id="cw-opt-reset"><?php esc_html_e( 'Reset progress', 'creativewings-core' ); ?></button>
                </p>
                <pre id="cw-opt-log" style="background:#0b1020;color:#d4ecff;border-radius:8px;padding:12px;max-height:300px;overflow:auto;white-space:pre-wrap;"></pre>
            </div>
        </div>

        <script>
        (function ($) {
            const ajaxUrl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
            const nonce   = '<?php echo esc_js( $nonce ); ?>';
            const log     = document.getElementById('cw-opt-log');
            function append(line) { log.textContent += line + "\n"; log.scrollTop = log.scrollHeight; }

            function run(mode) {
                const batch = parseInt(document.getElementById('cw-opt-batch').value, 10) || <?php echo (int) self::BATCH_DEFAULT; ?>;
                append('▶ ' + mode + ' (batch=' + batch + ')…');
                $.post(ajaxUrl, { action: 'cw_bulk_opt_run', _nonce: nonce, mode: mode, batch: batch })
                    .done(function (r) {
                        if (!r || !r.success) {
                            append('✗ failed: ' + (r && r.data ? r.data : 'unknown'));
                            return;
                        }
                        const d = r.data;
                        document.getElementById('cw-opt-last').textContent      = d.progress.last_id;
                        document.getElementById('cw-opt-processed').textContent = d.progress.processed;
                        document.getElementById('cw-opt-skipped').textContent   = d.progress.skipped;
                        document.getElementById('cw-opt-bytes').textContent     = d.bytes_saved_human;
                        append('✓ ' + d.summary);
                        if (d.lines && d.lines.length) {
                            d.lines.forEach(append);
                        }
                    })
                    .fail(function () { append('✗ network error'); });
            }

            document.getElementById('cw-opt-dry').addEventListener('click', function(){ run('dry'); });
            document.getElementById('cw-opt-process').addEventListener('click', function(){ run('process'); });
            document.getElementById('cw-opt-reset').addEventListener('click', function () {
                if (!confirm('<?php echo esc_js( __( 'Reset progress counter? Backups are not removed.', 'creativewings-core' ) ); ?>')) return;
                $.post(ajaxUrl, { action: 'cw_bulk_opt_reset', _nonce: nonce })
                    .done(function () {
                        document.getElementById('cw-opt-last').textContent = 0;
                        document.getElementById('cw-opt-processed').textContent = 0;
                        document.getElementById('cw-opt-skipped').textContent = 0;
                        document.getElementById('cw-opt-bytes').textContent = '0 B';
                        append('↺ progress reset');
                    });
            });
        })(jQuery);
        </script>
        <?php
    }

    /* ──────────────────────────────────────────────────────────────────
     *  AJAX endpoints
     * ────────────────────────────────────────────────────────────────── */

    public function ajax_run() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauth', 403 );
        check_ajax_referer( self::NONCE_ACTION, '_nonce' );

        $mode  = ( $_POST['mode'] ?? '' ) === 'dry' ? 'dry' : 'process';
        $batch = max( 1, min( self::BATCH_MAX, (int) ( $_POST['batch'] ?? self::BATCH_DEFAULT ) ) );

        $result = $this->run_batch( $batch, $mode );

        // Schedule cron tick if we still have work to do and aren't dry-running.
        if ( $mode === 'process' && ! $result['done'] && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK );
        }

        wp_send_json_success( $result );
    }

    public function ajax_reset() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauth', 403 );
        check_ajax_referer( self::NONCE_ACTION, '_nonce' );
        delete_option( self::PROGRESS_OPTION );
        wp_send_json_success();
    }

    public function ajax_restore() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( 'unauth', 403 );
        check_ajax_referer( self::NONCE_ACTION, '_nonce' );
        $aid = (int) ( $_POST['attach_id'] ?? 0 );
        if ( ! $aid ) wp_send_json_error( 'missing id' );
        $ok = $this->restore_backup( $aid );
        $ok ? wp_send_json_success() : wp_send_json_error( 'no backup' );
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Batch engine
     * ────────────────────────────────────────────────────────────────── */

    public function cron_tick() {
        $this->run_batch( self::CRON_BATCH, 'process' );
        // If there's more, reschedule for 5 minutes later.
        $progress = self::get_progress();
        if ( ! empty( $progress['has_more'] ) && ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_single_event( time() + 5 * MINUTE_IN_SECONDS, self::CRON_HOOK );
        }
    }

    /**
     * Process one batch starting after the last_id watermark.
     */
    private function run_batch( $batch, $mode ) {
        @set_time_limit( 0 );

        $progress = self::get_progress();
        $last_id  = (int) ( $progress['last_id'] ?? 0 );

        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'attachment'
               AND post_mime_type IN ('image/jpeg','image/png')
               AND ID > %d
             ORDER BY ID ASC
             LIMIT %d",
            $last_id,
            (int) $batch
        ) );

        $processed   = 0;
        $skipped     = 0;
        $bytes_saved = 0;
        $lines       = [];

        foreach ( (array) $ids as $aid ) {
            $aid = (int) $aid;
            $last_id = $aid;
            $path = get_attached_file( $aid );
            if ( ! $path || ! file_exists( $path ) ) {
                $skipped++;
                continue;
            }
            $size = (int) filesize( $path );
            if ( $size < self::MIN_BYTES ) {
                $skipped++;
                continue;
            }
            if ( get_post_meta( $aid, CW_Image_Optimizer::ATTACH_META_OPTIMIZED, true ) === '1' ) {
                $skipped++;
                continue;
            }

            if ( $mode === 'dry' ) {
                // Conservative 30% estimate to avoid raising user expectations too high.
                $estimate     = (int) round( $size * 0.30 );
                $bytes_saved += $estimate;
                $lines[]      = sprintf( '~%s saveable @ #%d (%s)', size_format( $estimate ), $aid, basename( $path ) );
                $skipped++;
                continue;
            }

            // Real run: back up first, then optimize.
            $this->backup_original( $aid, $path );

            $result = CW_Image_Optimizer::optimize_attachment( $aid, 'attachment' );
            if ( is_wp_error( $result ) ) {
                $lines[] = sprintf( '✗ #%d %s', $aid, $result->get_error_message() );
                $skipped++;
                continue;
            }
            $saved        = max( 0, (int) $result['bytes_before'] - (int) $result['bytes_after'] );
            $bytes_saved += $saved;
            $processed++;
            $lines[] = sprintf( '✓ #%d  -%s  (%s)', $aid, size_format( $saved ), basename( $path ) );
        }

        $progress['last_id']     = $last_id;
        $progress['processed']   = (int) ( $progress['processed'] ?? 0 ) + $processed;
        $progress['skipped']     = (int) ( $progress['skipped'] ?? 0 ) + $skipped;
        $progress['bytes_saved'] = (int) ( $progress['bytes_saved'] ?? 0 ) + $bytes_saved;
        $progress['has_more']    = count( (array) $ids ) === (int) $batch;
        update_option( self::PROGRESS_OPTION, $progress, false );

        return [
            'progress'          => $progress,
            'bytes_saved_human' => size_format( (int) $progress['bytes_saved'] ),
            'lines'             => $lines,
            'summary'           => sprintf(
                '%s pass — processed=%d skipped=%d saved=%s%s',
                $mode === 'dry' ? 'dry-run' : 'live',
                $processed,
                $skipped,
                size_format( $bytes_saved ),
                $progress['has_more'] ? ' (more remaining)' : ' (queue drained)'
            ),
            'done'              => ! $progress['has_more'],
        ];
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Backup / restore
     * ────────────────────────────────────────────────────────────────── */

    private function backup_original( $attach_id, $path ) {
        $upload   = wp_get_upload_dir();
        $rel      = ltrim( str_replace( $upload['basedir'], '', $path ), '/' );
        $backup   = trailingslashit( $upload['basedir'] ) . '_cw-backup/' . $rel;
        if ( file_exists( $backup ) ) {
            return; // Only ever backup the first untouched original.
        }
        wp_mkdir_p( dirname( $backup ) );
        @copy( $path, $backup );
        update_post_meta( $attach_id, '_cw_backup_path', $backup );
    }

    public function restore_backup( $attach_id ) {
        $backup = (string) get_post_meta( (int) $attach_id, '_cw_backup_path', true );
        $path   = get_attached_file( (int) $attach_id );
        if ( ! $backup || ! $path || ! file_exists( $backup ) ) {
            return false;
        }
        if ( ! @copy( $backup, $path ) ) {
            return false;
        }
        delete_post_meta( $attach_id, CW_Image_Optimizer::ATTACH_META_OPTIMIZED );
        delete_post_meta( $attach_id, CW_Image_Optimizer::ATTACH_META_WEBP_URL );
        return true;
    }

    /* ──────────────────────────────────────────────────────────────────
     *  Helpers
     * ────────────────────────────────────────────────────────────────── */

    public static function get_progress() {
        $p = get_option( self::PROGRESS_OPTION, [] );
        if ( ! is_array( $p ) ) $p = [];
        return wp_parse_args( $p, [
            'last_id'     => 0,
            'processed'   => 0,
            'skipped'     => 0,
            'bytes_saved' => 0,
            'has_more'    => true,
        ] );
    }

    private function scan_totals() {
        global $wpdb;
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->posts}
             WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg','image/png')"
        );
        $processed = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID
             WHERE p.post_type = 'attachment'
               AND p.post_mime_type IN ('image/jpeg','image/png')
               AND m.meta_key = %s
               AND m.meta_value = %s",
            CW_Image_Optimizer::ATTACH_META_OPTIMIZED,
            '1'
        ) );
        return [
            'total'     => $total,
            'processed' => $processed,
            'eligible'  => max( 0, $total - $processed ),
        ];
    }
}
