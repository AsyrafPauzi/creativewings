<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-campaign certificate templates, name overlay, batch email.
 */
class CW_Certificate {

    const BATCH_HOOK   = 'cw_process_cert_email_batch';
    const BATCH_SIZE   = 15;
    const BATCH_DELAY  = 300; // 5 minutes between batches.

    /**
     * Metabox defers certificate action forms to admin_footer (nested forms break product Publish).
     *
     * @var array<string, mixed>|null
     */
    private static $footer_forms_context = null;

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'admin_footer-post.php', [ $this, 'render_external_forms' ] );
        add_action( 'admin_footer-post-new.php', [ $this, 'render_external_forms' ] );
        add_action( 'save_post_product', [ $this, 'save_product_cert_settings' ], 25, 2 );
        add_action( 'admin_post_cw_start_cert_batch', [ $this, 'handle_start_batch' ] );
        add_action( 'admin_post_cw_cert_preview', [ $this, 'handle_preview' ] );
        add_action( 'admin_post_cw_send_cert_test_email', [ $this, 'handle_test_email' ] );
        add_action( 'admin_post_nopriv_cw_download_cert', [ $this, 'handle_download' ] );
        add_action( 'admin_post_cw_download_cert', [ $this, 'handle_download' ] );
        add_action( self::BATCH_HOOK, [ $this, 'process_batch' ] );
        add_action( 'admin_notices', [ $this, 'admin_notices' ] );
    }

    public function admin_notices() {
        if ( empty( $_GET['cw_cert_notice'] ) ) {
            return;
        }
        $msg = sanitize_key( wp_unslash( $_GET['cw_cert_notice'] ) );
        if ( 'started' === $msg ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Certificate emails started — sending in batches to avoid spam filters.', 'creativewings-core' ) . '</p></div>';
        } elseif ( 'none' === $msg ) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__( 'No participants waiting for certificate email.', 'creativewings-core' ) . '</p></div>';
        } elseif ( 'test_sent' === $msg ) {
            $to = isset( $_GET['cw_cert_test_to'] ) ? sanitize_email( wp_unslash( $_GET['cw_cert_test_to'] ) ) : '';
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf( esc_html__( 'Test certificate email sent to %s.', 'creativewings-core' ), esc_html( $to ) );
            echo '</p></div>';
        } elseif ( 'test_fail' === $msg ) {
            $reason = isset( $_GET['cw_cert_test_reason'] ) ? sanitize_text_field( wp_unslash( $_GET['cw_cert_test_reason'] ) ) : '';
            echo '<div class="notice notice-error is-dismissible"><p>';
            esc_html_e( 'Test certificate email could not be sent.', 'creativewings-core' );
            if ( $reason ) {
                echo ' ' . esc_html( $reason );
            }
            echo '</p></div>';
        }
    }

    public static function default_layout() {
        return [
            'x_pct'      => 50,
            'y_pct'      => 55,
            'font_size'  => 32,
            'font_color' => '#1e293b',
            'max_width'  => 70,
            'align'      => 'center',
        ];
    }

    public static function get_layout( $product_id ) {
        $layout = [
            'x_pct'      => (float) get_post_meta( $product_id, 'cw_cert_x', true ),
            'y_pct'      => (float) get_post_meta( $product_id, 'cw_cert_y', true ),
            'font_size'  => (int) get_post_meta( $product_id, 'cw_cert_font_size', true ),
            'font_color' => get_post_meta( $product_id, 'cw_cert_font_color', true ),
            'max_width'  => (int) get_post_meta( $product_id, 'cw_cert_max_width', true ),
            'align'      => get_post_meta( $product_id, 'cw_cert_align', true ),
        ];
        $defaults = self::default_layout();
        foreach ( $defaults as $k => $v ) {
            if ( empty( $layout[ $k ] ) && 'font_color' !== $k ) {
                $layout[ $k ] = $v;
            }
        }
        if ( empty( $layout['font_color'] ) ) {
            $layout['font_color'] = $defaults['font_color'];
        }
        if ( ! in_array( $layout['align'], [ 'left', 'center', 'right' ], true ) ) {
            $layout['align'] = 'center';
        }
        return $layout;
    }

    public static function template_path( $product_id ) {
        $url = get_post_meta( (int) $product_id, 'cw_cert_template', true );
        if ( ! $url ) {
            return '';
        }
        $path = str_replace( site_url( '/' ), ABSPATH, $url );
        return file_exists( $path ) ? $path : '';
    }

    public static function font_path() {
        $candidates = [
            CW_PATH . 'assets/fonts/DejaVuSans-Bold.ttf',
            CW_PATH . 'assets/fonts/certificate.ttf',
        ];
        foreach ( $candidates as $p ) {
            if ( file_exists( $p ) ) {
                return $p;
            }
        }
        return '';
    }

    public static function participant_name( $entry_id ) {
        $name = get_post_meta( $entry_id, 'cw_participant_name', true );
        if ( $name ) {
            return $name;
        }
        $uid = (int) get_post_meta( $entry_id, 'customer_id', true );
        if ( ! $uid ) {
            $post = get_post( $entry_id );
            $uid  = $post ? (int) $post->post_author : 0;
        }
        if ( $uid ) {
            $name = get_user_meta( $uid, 'cw_full_name', true );
            if ( $name ) {
                return $name;
            }
            $u = get_userdata( $uid );
            return $u ? $u->display_name : 'Participant';
        }
        return 'Participant';
    }

    public static function can_download( $entry_id, $user_id = 0 ) {
        $entry_id = (int) $entry_id;
        $entry    = get_post( $entry_id );
        if ( ! $entry ) {
            return false;
        }
        $product_id = (int) get_post_meta( $entry_id, 'product_id', true );
        if ( get_post_meta( $product_id, 'cw_enable_certificate', true ) !== 'yes' ) {
            return false;
        }
        if ( ! self::template_path( $product_id ) ) {
            return false;
        }
        if ( get_post_meta( $entry_id, 'cw_cert_revoked', true ) === 'yes' ) {
            return false;
        }
        $issued = get_post_meta( $entry_id, 'cw_cert_issued', true ) === 'yes';
        $scored = get_post_meta( $entry_id, 'judge_score', true ) !== '';
        if ( ! $issued && ! $scored ) {
            return false;
        }
        if ( $user_id && (int) $entry->post_author !== (int) $user_id ) {
            $customer = (int) get_post_meta( $entry_id, 'customer_id', true );
            if ( $customer !== (int) $user_id ) {
                return false;
            }
        }
        return true;
    }

    public static function download_url( $entry_id, $for_user_id = 0 ) {
        $args = [
            'action'   => 'cw_download_cert',
            'entry_id' => (int) $entry_id,
        ];
        $url = add_query_arg( $args, admin_url( 'admin-post.php' ) );
        return wp_nonce_url( $url, 'cw_cert_dl_' . (int) $entry_id, 'cw_cert_nonce' );
    }

    /**
     * @return string|WP_Error Path to generated file in uploads temp.
     */
    public static function generate_file( $entry_id, $participant_name = '' ) {
        $entry_id   = (int) $entry_id;
        $product_id = (int) get_post_meta( $entry_id, 'product_id', true );
        $template   = self::template_path( $product_id );

        if ( ! $template ) {
            return new WP_Error( 'no_template', __( 'Certificate template not configured.', 'creativewings-core' ) );
        }

        if ( ! $participant_name ) {
            $participant_name = self::participant_name( $entry_id );
        }

        $ext = strtolower( pathinfo( $template, PATHINFO_EXTENSION ) );
        if ( in_array( $ext, [ 'png', 'jpg', 'jpeg', 'gif', 'webp' ], true ) ) {
            return self::render_image_certificate( $template, $participant_name, self::get_layout( $product_id ), $entry_id );
        }

        if ( 'pdf' === $ext && extension_loaded( 'imagick' ) && class_exists( 'Imagick' ) ) {
            return self::render_pdf_certificate_imagick( $template, $participant_name, self::get_layout( $product_id ), $entry_id );
        }

        return new WP_Error(
            'unsupported_template',
            __( 'Use a PNG or JPG template for name overlay. PDF requires Imagick on the server.', 'creativewings-core' )
        );
    }

    private static function render_image_certificate( $template, $name, $layout, $entry_id ) {
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
            return new WP_Error( 'no_gd', __( 'PHP GD extension is required for certificates.', 'creativewings-core' ) );
        }

        $ext = strtolower( pathinfo( $template, PATHINFO_EXTENSION ) );
        switch ( $ext ) {
            case 'jpg':
            case 'jpeg':
                $src = imagecreatefromjpeg( $template );
                break;
            case 'gif':
                $src = imagecreatefromgif( $template );
                break;
            case 'webp':
                $src = function_exists( 'imagecreatefromwebp' ) ? imagecreatefromwebp( $template ) : null;
                break;
            default:
                $src = imagecreatefrompng( $template );
                if ( $src ) {
                    imagealphablending( $src, true );
                    imagesavealpha( $src, true );
                }
        }

        if ( ! $src ) {
            return new WP_Error( 'load_failed', __( 'Could not load certificate template.', 'creativewings-core' ) );
        }

        $w = imagesx( $src );
        $h = imagesy( $src );
        self::draw_name_on_image( $src, $name, $layout, $w, $h );

        $upload_dir = wp_upload_dir();
        $dir        = trailingslashit( $upload_dir['basedir'] ) . 'cw-certificates';
        if ( ! wp_mkdir_p( $dir ) ) {
            imagedestroy( $src );
            return new WP_Error( 'dir_failed', __( 'Could not create certificate folder.', 'creativewings-core' ) );
        }

        $out_ext = 'png';
        $file    = $dir . '/cert-' . $entry_id . '-' . wp_generate_password( 6, false, false ) . '.' . $out_ext;
        imagepng( $src, $file, 6 );
        imagedestroy( $src );

        return $file;
    }

    private static function draw_name_on_image( $img, $name, $layout, $w, $h ) {
        $font_file = self::font_path();
        $size      = max( 12, (int) $layout['font_size'] );
        $rgb       = self::hex_to_rgb( $layout['font_color'] );
        $color     = imagecolorallocate( $img, $rgb['r'], $rgb['g'], $rgb['b'] );

        $x_center = ( (float) $layout['x_pct'] / 100 ) * $w;
        $y_pos    = ( (float) $layout['y_pct'] / 100 ) * $h;
        $max_w_px = ( (float) ( $layout['max_width'] ?: 70 ) / 100 ) * $w;

        if ( $font_file && function_exists( 'imagettftext' ) ) {
            while ( $size > 10 ) {
                $box = imagettfbbox( $size, 0, $font_file, $name );
                $tw  = abs( $box[2] - $box[0] );
                if ( $tw <= $max_w_px ) {
                    break;
                }
                $size -= 2;
            }
            $box = imagettfbbox( $size, 0, $font_file, $name );
            $tw  = abs( $box[2] - $box[0] );
            $th  = abs( $box[7] - $box[1] );
            $x   = self::align_x( $layout['align'], $x_center, $tw );
            $y   = $y_pos + $th;
            imagettftext( $img, $size, 0, (int) $x, (int) $y, $color, $font_file, $name );
            return;
        }

        $font = 5;
        $tw   = imagefontwidth( $font ) * strlen( $name );
        $th   = imagefontheight( $font );
        $x    = self::align_x( $layout['align'], $x_center, $tw );
        imagestring( $img, $font, (int) $x, (int) ( $y_pos - $th / 2 ), $name, $color );
    }

    private static function align_x( $align, $anchor_x, $text_width ) {
        if ( 'left' === $align ) {
            return $anchor_x;
        }
        if ( 'right' === $align ) {
            return $anchor_x - $text_width;
        }
        return $anchor_x - ( $text_width / 2 );
    }

    private static function hex_to_rgb( $hex ) {
        $hex = ltrim( (string) $hex, '#' );
        if ( strlen( $hex ) === 3 ) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [
            'r' => hexdec( substr( $hex, 0, 2 ) ?: '1e' ),
            'g' => hexdec( substr( $hex, 2, 2 ) ?: '29' ),
            'b' => hexdec( substr( $hex, 4, 2 ) ?: '3b' ),
        ];
    }

    private static function render_pdf_certificate_imagick( $template, $name, $layout, $entry_id ) {
        try {
            $im = new Imagick();
            $im->setResolution( 150, 150 );
            $im->readImage( $template );
            $im->setIteratorIndex( 0 );
            $im->setImageFormat( 'png' );
            $blob = $im->getImageBlob();
            $im->clear();

            $tmp = wp_tempnam( 'cw-cert-src' );
            file_put_contents( $tmp, $blob );
            $png_path = self::render_image_certificate( $tmp, $name, $layout, $entry_id );
            @unlink( $tmp );

            if ( is_wp_error( $png_path ) ) {
                return $png_path;
            }

            $out_pdf = preg_replace( '/\.png$/i', '.pdf', $png_path );
            $pdf     = new Imagick( $png_path );
            $pdf->setImageFormat( 'pdf' );
            $pdf->writeImage( $out_pdf );
            $pdf->clear();
            @unlink( $png_path );

            return file_exists( $out_pdf ) ? $out_pdf : $png_path;
        } catch ( Exception $e ) {
            return new WP_Error( 'imagick', $e->getMessage() );
        }
    }

    public static function get_eligible_entry_ids( $campaign_id, $resend = false ) {
        $types = [ 'cw_activity_entry', 'cw_competition_entry' ];
        $ids   = get_posts(
            [
                'post_type'      => $types,
                'posts_per_page' => -1,
                'fields'         => 'ids',
                'meta_query'     => [
                    [
                        'key'   => 'product_id',
                        'value' => (int) $campaign_id,
                    ],
                ],
            ]
        );
        if ( ! $resend ) {
            $ids = array_filter(
                $ids,
                function ( $eid ) {
                    return get_post_meta( $eid, 'cw_cert_email_sent', true ) !== 'yes';
                }
            );
        }
        return array_values( $ids );
    }


    public function register_metabox() {
        add_meta_box(
            'cw_certificate_settings',
            __( 'Certificate (per campaign)', 'creativewings-core' ),
            [ $this, 'render_metabox' ],
            'product',
            'normal',
            'high'
        );
    }

    public function render_metabox( $post ) {
        if ( ! current_user_can( 'edit_post', $post->ID ) ) {
            return;
        }

        $enabled  = get_post_meta( $post->ID, 'cw_enable_certificate', true ) === 'yes';
        $template = get_post_meta( $post->ID, 'cw_cert_template', true );
        $layout   = self::get_layout( $post->ID );
        $batch    = get_post_meta( $post->ID, 'cw_cert_batch_status', true );
        $eligible = $enabled ? count( self::get_eligible_entry_ids( $post->ID, false ) ) : 0;

        self::$footer_forms_context = [
            'post_id'  => (int) $post->ID,
            'template' => (string) $template,
            'enabled'  => $enabled,
            'eligible' => (int) $eligible,
            'batch'    => is_array( $batch ) ? $batch : [],
        ];

        wp_nonce_field( 'cw_cert_settings', 'cw_cert_settings_nonce' );
        ?>
        <p>
            <label><input type="checkbox" name="cw_enable_certificate" value="yes" <?php checked( $enabled ); ?>>
            <?php esc_html_e( 'Enable e-certificates for this campaign', 'creativewings-core' ); ?></label>
        </p>
        <p>
            <label><?php esc_html_e( 'Template image (PNG/JPG recommended)', 'creativewings-core' ); ?></label><br>
            <input type="file" name="cw_cert_template_file" accept="image/png,image/jpeg,image/jpg,application/pdf">
            <?php if ( $template ) : ?>
                <br><a href="<?php echo esc_url( $template ); ?>" target="_blank"><?php esc_html_e( 'View current template', 'creativewings-core' ); ?></a>
            <?php endif; ?>
        </p>
        <p class="description"><?php esc_html_e( 'Each campaign uses its own template. Adjust position % until the name sits correctly on the artwork.', 'creativewings-core' ); ?></p>
        <table class="form-table" style="max-width:520px;">
            <tr><th><?php esc_html_e( 'Name X (%)', 'creativewings-core' ); ?></th>
                <td><input type="number" name="cw_cert_x" value="<?php echo esc_attr( $layout['x_pct'] ); ?>" min="0" max="100" step="0.1"></td></tr>
            <tr><th><?php esc_html_e( 'Name Y (%)', 'creativewings-core' ); ?></th>
                <td><input type="number" name="cw_cert_y" value="<?php echo esc_attr( $layout['y_pct'] ); ?>" min="0" max="100" step="0.1"></td></tr>
            <tr><th><?php esc_html_e( 'Font size', 'creativewings-core' ); ?></th>
                <td><input type="number" name="cw_cert_font_size" value="<?php echo esc_attr( $layout['font_size'] ); ?>" min="10" max="120"></td></tr>
            <tr><th><?php esc_html_e( 'Font color', 'creativewings-core' ); ?></th>
                <td><input type="text" name="cw_cert_font_color" value="<?php echo esc_attr( $layout['font_color'] ); ?>" placeholder="#1e293b"></td></tr>
            <tr><th><?php esc_html_e( 'Max width (%)', 'creativewings-core' ); ?></th>
                <td><input type="number" name="cw_cert_max_width" value="<?php echo esc_attr( $layout['max_width'] ); ?>" min="20" max="100"></td></tr>
            <tr><th><?php esc_html_e( 'Align', 'creativewings-core' ); ?></th>
                <td>
                    <select name="cw_cert_align">
                        <option value="center" <?php selected( $layout['align'], 'center' ); ?>><?php esc_html_e( 'Center', 'creativewings-core' ); ?></option>
                        <option value="left" <?php selected( $layout['align'], 'left' ); ?>><?php esc_html_e( 'Left', 'creativewings-core' ); ?></option>
                        <option value="right" <?php selected( $layout['align'], 'right' ); ?>><?php esc_html_e( 'Right', 'creativewings-core' ); ?></option>
                    </select>
                </td></tr>
        </table>
        <?php
        $preview = wp_nonce_url(
            add_query_arg(
                [
                    'action'      => 'cw_cert_preview',
                    'campaign_id' => $post->ID,
                    'name'        => 'Sample Name',
                ],
                admin_url( 'admin-post.php' )
            ),
            'cw_cert_preview'
        );
        ?>
        <p>
            <a class="button" href="<?php echo esc_url( $preview ); ?>" target="_blank"><?php esc_html_e( 'Preview with sample name', 'creativewings-core' ); ?></a>
        </p>
        <hr>
        <h4><?php esc_html_e( 'Test certificate email', 'creativewings-core' ); ?></h4>
        <p class="description"><?php esc_html_e( 'Save the product after changing template or name position, then send a test to your inbox.', 'creativewings-core' ); ?></p>
        <p>
            <label for="cw_cert_test_email"><?php esc_html_e( 'Send test to email', 'creativewings-core' ); ?></label><br>
            <input type="email" id="cw_cert_test_email" form="cw-cert-test-email-form" name="test_email" class="regular-text" required
                placeholder="admin@example.com" value="<?php echo esc_attr( wp_get_current_user()->user_email ); ?>">
        </p>
        <p>
            <label for="cw_cert_test_name"><?php esc_html_e( 'Name on certificate', 'creativewings-core' ); ?></label><br>
            <input type="text" id="cw_cert_test_name" form="cw-cert-test-email-form" name="test_name" class="regular-text" value="Test Participant">
        </p>
        <button type="submit" class="button" form="cw-cert-test-email-form" <?php disabled( ! $template ); ?>><?php esc_html_e( 'Send test certificate email', 'creativewings-core' ); ?></button>
        <?php if ( ! $template ) : ?>
            <p class="description"><?php esc_html_e( 'Upload a certificate template first.', 'creativewings-core' ); ?></p>
        <?php endif; ?>
        <hr>
        <h4><?php esc_html_e( 'Send certificates by email (batched)', 'creativewings-core' ); ?></h4>
        <p><?php printf( esc_html__( '%d participants waiting (not emailed yet). Sends %d emails every %d minutes to avoid spam filters.', 'creativewings-core' ), (int) $eligible, self::BATCH_SIZE, (int) ( self::BATCH_DELAY / 60 ) ); ?></p>
        <?php if ( is_array( $batch ) && ! empty( $batch['running'] ) ) : ?>
            <p><strong><?php esc_html_e( 'Batch in progress:', 'creativewings-core' ); ?></strong>
            <?php printf( esc_html__( '%d sent, %d remaining', 'creativewings-core' ), (int) ( $batch['sent'] ?? 0 ), (int) ( $batch['remaining'] ?? 0 ) ); ?></p>
        <?php endif; ?>
        <?php if ( $enabled && $eligible > 0 ) : ?>
            <button type="submit" class="button button-primary" form="cw-cert-start-batch-form"><?php esc_html_e( 'Start sending certificates', 'creativewings-core' ); ?></button>
        <?php endif; ?>
        <?php if ( $enabled && $eligible === 0 && empty( $batch['running'] ) ) : ?>
            <p class="description"><?php esc_html_e( 'No pending recipients, or all already emailed. Check "Resend" below to email again.', 'creativewings-core' ); ?></p>
            <button type="submit" class="button" form="cw-cert-resend-batch-form"><?php esc_html_e( 'Resend to all participants', 'creativewings-core' ); ?></button>
        <?php endif; ?>
        <?php
    }

    /**
     * Certificate email/batch actions must not nest inside #post — that breaks WooCommerce Publish.
     */
    public function render_external_forms() {
        $ctx = self::$footer_forms_context;
        if ( empty( $ctx['post_id'] ) ) {
            return;
        }

        $post_id = (int) $ctx['post_id'];
        $action  = esc_url( admin_url( 'admin-post.php' ) );
        ?>
        <form id="cw-cert-test-email-form" method="post" action="<?php echo $action; ?>" style="display:none;" aria-hidden="true">
            <?php wp_nonce_field( 'cw_send_cert_test_email' ); ?>
            <input type="hidden" name="action" value="cw_send_cert_test_email">
            <input type="hidden" name="campaign_id" value="<?php echo $post_id; ?>">
        </form>
        <?php if ( ! empty( $ctx['enabled'] ) && (int) $ctx['eligible'] > 0 ) : ?>
        <form id="cw-cert-start-batch-form" method="post" action="<?php echo $action; ?>" style="display:none;" aria-hidden="true">
            <?php wp_nonce_field( 'cw_start_cert_batch' ); ?>
            <input type="hidden" name="action" value="cw_start_cert_batch">
            <input type="hidden" name="campaign_id" value="<?php echo $post_id; ?>">
        </form>
        <?php endif; ?>
        <?php if ( ! empty( $ctx['enabled'] ) && 0 === (int) $ctx['eligible'] && empty( $ctx['batch']['running'] ) ) : ?>
        <form id="cw-cert-resend-batch-form" method="post" action="<?php echo $action; ?>" style="display:none;" aria-hidden="true">
            <?php wp_nonce_field( 'cw_start_cert_batch' ); ?>
            <input type="hidden" name="action" value="cw_start_cert_batch">
            <input type="hidden" name="campaign_id" value="<?php echo $post_id; ?>">
            <input type="hidden" name="resend" value="1">
        </form>
        <?php endif;
        self::$footer_forms_context = null;
    }

    public function save_product_cert_settings( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || ! isset( $_POST['cw_cert_settings_nonce'] ) ) {
            return;
        }
        if ( ! wp_verify_nonce( $_POST['cw_cert_settings_nonce'], 'cw_cert_settings' ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }

        update_post_meta( $post_id, 'cw_enable_certificate', isset( $_POST['cw_enable_certificate'] ) ? 'yes' : 'no' );

        $fields = [ 'cw_cert_x', 'cw_cert_y', 'cw_cert_font_size', 'cw_cert_font_color', 'cw_cert_max_width', 'cw_cert_align' ];
        foreach ( $fields as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_post_meta( $post_id, $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
            }
        }

        if ( ! empty( $_FILES['cw_cert_template_file']['name'] ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            $aid = media_handle_upload( 'cw_cert_template_file', $post_id );
            if ( ! is_wp_error( $aid ) ) {
                update_post_meta( $post_id, 'cw_cert_template', wp_get_attachment_url( $aid ) );
            }
        }
    }

    public function handle_preview() {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'cw_cert_preview' );

        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        $name        = sanitize_text_field( wp_unslash( $_GET['name'] ?? 'Sample Name' ) );
        $template    = self::template_path( $campaign_id );

        if ( ! $template ) {
            wp_die( esc_html__( 'Upload a certificate template first.', 'creativewings-core' ) );
        }

        $file = self::render_image_certificate( $template, $name, self::get_layout( $campaign_id ), 0 );
        if ( is_wp_error( $file ) ) {
            wp_die( esc_html( $file->get_error_message() ) );
        }

        header( 'Content-Type: image/png' );
        header( 'Content-Disposition: inline; filename="certificate-preview.png"' );
        readfile( $file );
        @unlink( $file );
        exit;
    }

    public function handle_test_email() {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'cw_send_cert_test_email' );

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $email       = sanitize_email( wp_unslash( $_POST['test_email'] ?? '' ) );
        $name        = sanitize_text_field( wp_unslash( $_POST['test_name'] ?? 'Test Participant' ) );
        $redirect    = $campaign_id ? get_edit_post_link( $campaign_id, 'raw' ) : admin_url( 'edit.php?post_type=product' );

        if ( ! $campaign_id || ! is_email( $email ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice'       => 'test_fail',
                        'cw_cert_test_reason'  => rawurlencode( __( 'Invalid campaign or email address.', 'creativewings-core' ) ),
                    ],
                    $redirect
                )
            );
            exit;
        }

        if ( ! current_user_can( 'edit_post', $campaign_id ) ) {
            wp_die( 'Unauthorized', 403 );
        }

        if ( get_post_meta( $campaign_id, 'cw_enable_certificate', true ) !== 'yes' ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice'      => 'test_fail',
                        'cw_cert_test_reason' => rawurlencode( __( 'Enable certificates on this campaign first.', 'creativewings-core' ) ),
                    ],
                    $redirect
                )
            );
            exit;
        }

        $template = self::template_path( $campaign_id );
        if ( ! $template ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice'      => 'test_fail',
                        'cw_cert_test_reason' => rawurlencode( __( 'Upload a certificate template first.', 'creativewings-core' ) ),
                    ],
                    $redirect
                )
            );
            exit;
        }

        $file = self::render_image_certificate( $template, $name, self::get_layout( $campaign_id ), 0 );
        if ( is_wp_error( $file ) ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice'      => 'test_fail',
                        'cw_cert_test_reason' => rawurlencode( $file->get_error_message() ),
                    ],
                    $redirect
                )
            );
            exit;
        }

        $preview_url = wp_nonce_url(
            add_query_arg(
                [
                    'action'      => 'cw_cert_preview',
                    'campaign_id' => $campaign_id,
                    'name'        => rawurlencode( $name ),
                ],
                admin_url( 'admin-post.php' )
            ),
            'cw_cert_preview'
        );

        $campaign_title = get_the_title( $campaign_id );
        $subject        = sprintf(
            '[%s] %s',
            get_bloginfo( 'name' ),
            __( 'TEST — Participation certificate', 'creativewings-core' )
        );
        $body           = sprintf(
            '<p><strong>%s</strong></p>
            <p>%s</p>
            <p><strong>%s</strong><br>%s</p>
            <p><a href="%s">%s</a></p>
            <p>%s</p>',
            esc_html__( 'This is a test email from Creative Wings admin.', 'creativewings-core' ),
            esc_html__( 'If the name position looks wrong, adjust X/Y % on the product and click Update, then send another test.', 'creativewings-core' ),
            esc_html__( 'Campaign:', 'creativewings-core' ),
            esc_html( $campaign_title ),
            esc_url( $preview_url ),
            esc_html__( 'Open certificate preview in browser', 'creativewings-core' ),
            esc_html__( 'The certificate file is also attached to this email (when under 4MB).', 'creativewings-core' )
        );

        $attachments = [];
        if ( file_exists( $file ) && filesize( $file ) < 4000000 ) {
            $attachments[] = $file;
        }

        $sent = wp_mail(
            $email,
            $subject,
            wp_kses_post( $body ),
            [ 'Content-Type: text/html; charset=UTF-8' ],
            $attachments
        );

        if ( file_exists( $file ) ) {
            @unlink( $file );
        }

        if ( $sent ) {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice' => 'test_sent',
                        'cw_cert_test_to'  => rawurlencode( $email ),
                    ],
                    $redirect
                )
            );
        } else {
            wp_safe_redirect(
                add_query_arg(
                    [
                        'cw_cert_notice'      => 'test_fail',
                        'cw_cert_test_reason' => rawurlencode( __( 'wp_mail failed — check SMTP/plugin settings.', 'creativewings-core' ) ),
                    ],
                    $redirect
                )
            );
        }
        exit;
    }

    public function handle_start_batch() {
        if ( ! current_user_can( 'edit_products' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'cw_start_cert_batch' );

        $campaign_id = (int) ( $_POST['campaign_id'] ?? 0 );
        $resend      = ! empty( $_POST['resend'] );
        $entry_ids   = self::get_eligible_entry_ids( $campaign_id, $resend );

        if ( empty( $entry_ids ) ) {
            wp_safe_redirect( add_query_arg( 'cw_cert_notice', 'none', get_edit_post_link( $campaign_id, 'raw' ) ) );
            exit;
        }

        update_post_meta(
            $campaign_id,
            'cw_cert_batch_status',
            [
                'running'   => true,
                'entry_ids' => $entry_ids,
                'offset'    => 0,
                'sent'      => 0,
                'failed'    => 0,
                'remaining' => count( $entry_ids ),
                'started'   => current_time( 'mysql' ),
            ]
        );

        wp_schedule_single_event( time() + 10, self::BATCH_HOOK, [ $campaign_id ] );

        wp_safe_redirect( add_query_arg( 'cw_cert_notice', 'started', get_edit_post_link( $campaign_id, 'raw' ) ) );
        exit;
    }

    public function process_batch( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        $batch       = get_post_meta( $campaign_id, 'cw_cert_batch_status', true );
        if ( ! is_array( $batch ) || empty( $batch['running'] ) || empty( $batch['entry_ids'] ) ) {
            return;
        }

        $ids    = $batch['entry_ids'];
        $offset = (int) ( $batch['offset'] ?? 0 );
        $slice  = array_slice( $ids, $offset, self::BATCH_SIZE );

        foreach ( $slice as $entry_id ) {
            $this->send_certificate_to_entry( (int) $entry_id, $campaign_id );
        }

        $offset += count( $slice );
        $batch['offset']    = $offset;
        $batch['remaining'] = max( 0, count( $ids ) - $offset );
        $batch['running']   = $batch['remaining'] > 0;

        update_post_meta( $campaign_id, 'cw_cert_batch_status', $batch );

        if ( $batch['running'] ) {
            wp_schedule_single_event( time() + self::BATCH_DELAY, self::BATCH_HOOK, [ $campaign_id ] );
        }
    }

    private function send_certificate_to_entry( $entry_id, $campaign_id ) {
        $entry = get_post( $entry_id );
        if ( ! $entry ) {
            return;
        }

        $user_id = (int) get_post_meta( $entry_id, 'customer_id', true );
        if ( ! $user_id ) {
            $user_id = (int) $entry->post_author;
        }
        $user = get_userdata( $user_id );
        if ( ! $user || ! is_email( $user->user_email ) ) {
            return;
        }

        update_post_meta( $entry_id, 'cw_cert_issued', 'yes' );

        $file = self::generate_file( $entry_id );
        $url  = self::download_url( $entry_id );

        $subject = sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Your participation certificate', 'creativewings-core' ) );
        $body    = sprintf(
            '<p>%s</p><p><strong>%s</strong></p><p><a href="%s">%s</a></p><p>%s</p>',
            esc_html__( 'Congratulations! Your certificate for this campaign is ready.', 'creativewings-core' ),
            esc_html( self::participant_name( $entry_id ) ),
            esc_url( $url ),
            esc_html__( 'Download your certificate (PDF/PNG)', 'creativewings-core' ),
            esc_html__( 'This link is personal to your account.', 'creativewings-core' )
        );

        $attachments = [];
        if ( ! is_wp_error( $file ) && file_exists( $file ) && filesize( $file ) < 4000000 ) {
            $attachments[] = $file;
        }

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        $sent    = wp_mail( $user->user_email, $subject, wp_kses_post( $body ), $headers, $attachments );

        if ( ! is_wp_error( $file ) && file_exists( $file ) ) {
            @unlink( $file );
        }

        if ( $sent ) {
            update_post_meta( $entry_id, 'cw_cert_email_sent', 'yes' );
            update_post_meta( $entry_id, 'cw_cert_email_sent_at', current_time( 'mysql' ) );
            $batch = get_post_meta( $campaign_id, 'cw_cert_batch_status', true );
            if ( is_array( $batch ) ) {
                $batch['sent'] = (int) ( $batch['sent'] ?? 0 ) + 1;
                update_post_meta( $campaign_id, 'cw_cert_batch_status', $batch );
            }
            do_action( 'cw_certificate_ready', $user_id, $entry_id, $campaign_id );
        }
    }

    public static function entry_cert_available( $entry_id ) {
        $product_id = (int) get_post_meta( $entry_id, 'product_id', true );
        if ( get_post_meta( $product_id, 'cw_enable_certificate', true ) !== 'yes' || ! self::template_path( $product_id ) ) {
            return false;
        }
        if ( get_post_meta( $entry_id, 'cw_cert_revoked', true ) === 'yes' ) {
            return false;
        }
        $issued = get_post_meta( $entry_id, 'cw_cert_issued', true ) === 'yes';
        $scored = get_post_meta( $entry_id, 'judge_score', true ) !== '';
        return $issued || $scored;
    }

    public function handle_download() {
        $entry_id = (int) ( $_GET['entry_id'] ?? 0 );
        if ( ! $entry_id ) {
            wp_die( 'Missing entry.', 400 );
        }

        $nonce_ok = isset( $_GET['cw_cert_nonce'] ) && wp_verify_nonce(
            sanitize_text_field( wp_unslash( $_GET['cw_cert_nonce'] ) ),
            'cw_cert_dl_' . $entry_id
        );

        if ( $nonce_ok ) {
            if ( ! self::entry_cert_available( $entry_id ) ) {
                wp_die( esc_html__( 'Certificate not available yet.', 'creativewings-core' ), 404 );
            }
        } else {
            if ( ! is_user_logged_in() ) {
                wp_die( esc_html__( 'Please log in or use the link from your email.', 'creativewings-core' ), 403 );
            }
            if ( ! self::can_download( $entry_id, get_current_user_id() ) ) {
                wp_die( esc_html__( 'Access denied.', 'creativewings-core' ), 403 );
            }
        }

        $file = self::generate_file( $entry_id );
        if ( is_wp_error( $file ) ) {
            wp_die( esc_html( $file->get_error_message() ), 404 );
        }

        $name = sanitize_file_name( self::participant_name( $entry_id ) . '_Certificate' );
        $ext  = strtolower( pathinfo( $file, PATHINFO_EXTENSION ) );
        $mime = 'image/png';
        if ( 'pdf' === $ext ) {
            $mime = 'application/pdf';
        } elseif ( in_array( $ext, [ 'jpg', 'jpeg' ], true ) ) {
            $mime = 'image/jpeg';
        }

        if ( ob_get_level() ) {
            ob_end_clean();
        }
        header( 'Content-Type: ' . $mime );
        header( 'Content-Disposition: attachment; filename="' . $name . '.' . $ext . '"' );
        header( 'Content-Length: ' . filesize( $file ) );
        readfile( $file );
        @unlink( $file );
        exit;
    }
}
