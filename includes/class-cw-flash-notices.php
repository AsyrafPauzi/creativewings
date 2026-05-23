<?php
/**
 * Centralised flash-notice popups for the CreativeWings front-end.
 *
 * Reads common query strings on logged-in pages and converts them into
 * SweetAlert2 popups, then strips the params from the address bar via
 * `history.replaceState` so the popup doesn't replay on refresh.
 *
 * Handled query args:
 *   - ?error=<msg>          -> SweetAlert2 error
 *   - ?success=<msg>        -> SweetAlert2 success
 *   - ?warning=<msg>        -> SweetAlert2 warning
 *   - ?info=<msg> / ?notice=<msg> -> SweetAlert2 info
 *   - ?linked=1             -> success: "Your submission has been linked successfully."
 *   - ?updated[=...]        -> success: "Saved successfully." (replaces the legacy
 *                              inline .cw-alert markup)
 *   - ?reset=success        -> success: "Password reset successful."
 *
 * @package CreativeWings
 * @since   11.0.61
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Flash_Notices {

    /** Maximum length of a message we'll surface (defensive — keeps the modal sane). */
    const MAX_LEN = 600;

    public function __construct() {
        // Render in the footer at priority 99 so we run AFTER every other footer
        // hook (wp_print_footer_scripts is at 20). Render in the head too as a
        // belt-and-braces measure for any theme that skips wp_footer.
        add_action( 'wp_footer', [ $this, 'maybe_render' ], 99 );
        add_action( 'wp_head',   [ $this, 'maybe_render' ], 99 );
    }

    /**
     * Build the notice payload from $_GET, output the SweetAlert2 trigger if any.
     *
     * Safe to call twice (head + footer) — a sentinel flag prevents the script
     * from being printed more than once per request.
     */
    public function maybe_render() {
        static $rendered = false;
        if ( $rendered ) return;

        if ( is_admin() ) return;
        // Notices should work for logged-out users too (e.g. registration/login
        // flows redirect back with ?error=... before the user has a session).
        $payload = $this->collect_notices();
        if ( empty( $payload ) ) return;

        $rendered = true;

        // The list of keys we strip from the URL after firing the popup. Match
        // every query key we look at so the address bar stays clean.
        $strip_keys = [ 'error', 'success', 'warning', 'info', 'notice', 'linked', 'updated', 'reset', 'requested' ];

        // CDN fallback URL — used by the loader below if SweetAlert2 isn't
        // already on the page (some non-logged-in flows or aggressive caches).
        $sa_cdn = 'https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.all.min.js';
        ?>
        <script id="cw-flash-notice-js">
        (function(){
            var payload   = <?php echo wp_json_encode( $payload ); ?>;
            var stripKeys = <?php echo wp_json_encode( $strip_keys ); ?>;
            var saCdn     = <?php echo wp_json_encode( $sa_cdn ); ?>;
            var fired     = false;

            function cleanUrl(){
                try {
                    var url = new URL( window.location.href );
                    var changed = false;
                    stripKeys.forEach(function(k){
                        if (url.searchParams.has(k)) { url.searchParams.delete(k); changed = true; }
                    });
                    if (changed) {
                        window.history.replaceState({}, document.title, url.pathname + (url.search ? url.search : '') + url.hash);
                    }
                } catch(e) { /* IE / very old browsers — leave URL as-is. */ }
            }

            function cwIconColor(icon){
                switch (icon) {
                    case 'success': return '#16a34a';
                    case 'error':   return '#dc2626';
                    case 'warning': return '#d97706';
                    case 'info':    return '#0284c7';
                    default:        return '#0F6796';
                }
            }

            function showAll(){
                function showNext(idx){
                    if (idx >= payload.length) { cleanUrl(); return; }
                    var n = payload[idx];
                    try {
                        window.Swal.fire({
                            icon:               n.icon || 'info',
                            title:              n.title || '',
                            text:               n.text  || '',
                            confirmButtonText:  n.button || 'OK',
                            confirmButtonColor: cwIconColor( n.icon ),
                            backdrop:           true,
                            showCloseButton:    true,
                            timer:              n.timer || undefined,
                            timerProgressBar:   !!n.timer
                        }).then(function(){ showNext(idx + 1); });
                    } catch(e) {
                        if (window.console) console.warn('[CW Flash] Swal.fire failed:', e);
                        showNext(idx + 1);
                    }
                }
                showNext(0);
            }

            function loadSwal(cb){
                if (document.getElementById('cw-flash-sa-fallback')) return; // already injecting
                var s = document.createElement('script');
                s.id = 'cw-flash-sa-fallback';
                s.src = saCdn;
                s.async = false;
                s.onload  = cb;
                s.onerror = function(){
                    if (window.console) console.warn('[CW Flash] Failed to load SweetAlert2 fallback from CDN.');
                    // Last-resort: native alert so the user still gets the message.
                    payload.forEach(function(n){
                        try { window.alert( (n.title || '') + (n.title ? ' — ' : '') + (n.text || '') ); } catch(e){}
                    });
                    cleanUrl();
                };
                document.head.appendChild(s);
            }

            function fire(){
                if (fired) return;
                fired = true;
                if (window.console) console.info('[CW Flash] notices ready:', payload);

                // Wait briefly for SweetAlert2 (footer-enqueued, so it might not
                // have parsed yet if our script ran from <head>). Up to 1.5s.
                var tries = 0;
                (function waitForSwal(){
                    if (typeof window.Swal !== 'undefined' && typeof window.Swal.fire === 'function') {
                        showAll();
                        return;
                    }
                    if (tries++ < 30) {
                        setTimeout(waitForSwal, 50);
                        return;
                    }
                    // Still no Swal — pull it from the CDN, then show.
                    loadSwal(function(){
                        if (typeof window.Swal !== 'undefined') { showAll(); }
                    });
                })();
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', fire);
            } else {
                fire();
            }
        })();
        </script>
        <?php
    }

    /**
     * @return array<int, array{icon:string,title:string,text:string,button?:string,timer?:int}>
     */
    private function collect_notices() {
        $out = [];

        // Generic error/success/warning/info/notice — each carries a free-text message.
        $map = [
            'error'   => [ 'icon' => 'error',   'title' => __( 'Something went wrong',     'creativewings-core' ) ],
            'success' => [ 'icon' => 'success', 'title' => __( 'Success',                  'creativewings-core' ) ],
            'warning' => [ 'icon' => 'warning', 'title' => __( 'Heads up',                 'creativewings-core' ) ],
            'info'    => [ 'icon' => 'info',    'title' => __( 'Info',                     'creativewings-core' ) ],
            'notice'  => [ 'icon' => 'info',    'title' => __( 'Notice',                   'creativewings-core' ) ],
        ];
        foreach ( $map as $key => $cfg ) {
            if ( isset( $_GET[ $key ] ) ) {
                $msg = $this->sanitize_message( wp_unslash( (string) $_GET[ $key ] ) );
                if ( $msg !== '' ) {
                    $out[] = [
                        'icon'  => $cfg['icon'],
                        'title' => $cfg['title'],
                        'text'  => $msg,
                    ];
                }
            }
        }

        // Specific known success patterns we already emit elsewhere.
        if ( isset( $_GET['linked'] ) && (string) $_GET['linked'] === '1' ) {
            $out[] = [
                'icon'  => 'success',
                'title' => __( 'Submission linked', 'creativewings-core' ),
                'text'  => __( 'Your submission code has been linked successfully. You can now continue with checkout.', 'creativewings-core' ),
                'timer' => 4500,
            ];
        }

        if ( isset( $_GET['updated'] ) ) {
            $value = (string) $_GET['updated'];
            // The dashboards emit `?updated`, `?updated=1`, `?updated=profile`, etc.
            $title = __( 'Saved', 'creativewings-core' );
            $text  = __( 'Your changes have been saved.', 'creativewings-core' );
            if ( $value === 'profile' ) {
                $text = __( 'Your profile has been updated.', 'creativewings-core' );
            } elseif ( $value === 'bank' ) {
                $text = __( 'Your bank details have been saved.', 'creativewings-core' );
            }
            $out[] = [
                'icon'  => 'success',
                'title' => $title,
                'text'  => $text,
                'timer' => 3500,
            ];
        }

        if ( isset( $_GET['reset'] ) && (string) $_GET['reset'] === 'success' ) {
            $out[] = [
                'icon'  => 'success',
                'title' => __( 'Password reset', 'creativewings-core' ),
                'text'  => __( 'Your password has been reset. You can now sign in with the new password.', 'creativewings-core' ),
            ];
        }

        if ( isset( $_GET['requested'] ) ) {
            $out[] = [
                'icon'  => 'success',
                'title' => __( 'Withdrawal request submitted', 'creativewings-core' ),
                'text'  => __( 'Your withdrawal request has been received. Our finance team will process it within 3 business days.', 'creativewings-core' ),
                'timer' => 4500,
            ];
        }

        return $out;
    }

    /**
     * Strip HTML, decode URL-encoding, normalise whitespace, cap length.
     */
    private function sanitize_message( $raw ) {
        $raw = (string) $raw;
        // The redirects use rawurlencode() but $_GET arrives URL-decoded already;
        // still, double-decode is safe (decodes a no-op the second pass).
        $raw = rawurldecode( $raw );
        $raw = wp_strip_all_tags( $raw, true );
        // Collapse runs of whitespace introduced by the strip.
        $raw = preg_replace( '/\s+/', ' ', $raw );
        $raw = trim( (string) $raw );
        if ( $raw === '' ) return '';
        if ( strlen( $raw ) > self::MAX_LEN ) {
            $raw = substr( $raw, 0, self::MAX_LEN - 1 ) . '…';
        }
        return $raw;
    }
}
