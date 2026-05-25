<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Design Submission feature.
 *
 * Opt-in per-campaign workflow:
 *   - Organizer configures the campaign (artwork dimensions, picker label,
 *     an unbounded list of product variants — colours, sizes, casings, etc.)
 *     via the admin metabox or the create-campaign wizard.
 *   - Participant uploads a PNG artwork (dimensions enforced both client-
 *     and server-side) plus an optional vector source file on the campaign
 *     product page; attachment IDs flow into the cart-item-data.
 *   - On the WooCommerce checkout page a per-line variant picker renders
 *     swatches + a live HTML5 canvas mockup. The chosen variant slug is
 *     stamped onto the order line at checkout and copied onto the resulting
 *     `cw_competition_entry` so admins / printers have everything they
 *     need to produce the physical product for every participant.
 */
class CW_Design_Submission {

    const META_ENABLE          = 'cw_enable_design';
    const META_PICKER_LABEL    = 'cw_design_picker_label';
    const META_WIDTH           = 'cw_design_artwork_w';
    const META_HEIGHT          = 'cw_design_artwork_h';
    const META_VARIANTS        = 'cw_design_variants';
    const META_DEFAULT_VARIANT = 'cw_design_default_variant';

    const CART_FLAG       = 'cw_design_enabled';
    const CART_ARTWORK_ID = 'cw_design_artwork_id';
    const CART_SOURCE_ID  = 'cw_design_source_id';
    const CART_VARIANT    = 'cw_design_variant';

    const ENTRY_ARTWORK = 'cw_design_artwork_id';
    const ENTRY_SOURCE  = 'cw_design_source_id';
    const ENTRY_VARIANT = 'cw_design_variant';

    const NONCE_METABOX = 'cw_design_metabox_nonce';
    const NONCE_AJAX    = 'cw_design_nonce';
    const AJAX_ACTION   = 'cw_design_artwork_upload';

    /** Vector source-file MIME whitelist. Extensions cross-checked separately. */
    const SOURCE_MIME_WHITELIST = [
        'application/postscript',         // .ai / .eps on most servers
        'application/illustrator',        // legacy .ai
        'application/octet-stream',       // last-resort fallback some hosts return for .ai
        'application/pdf',
        'image/svg+xml',
    ];
    const SOURCE_EXT_WHITELIST = [ 'ai', 'pdf', 'svg', 'eps' ];

    /** Max file size for artwork + source uploads, in bytes. */
    const MAX_UPLOAD_BYTES = 25 * 1024 * 1024; // 25 MB

    public function __construct() {
        // Admin metabox + save handler.
        add_action( 'add_meta_boxes', [ $this, 'register_metabox' ] );
        add_action( 'save_post_product', [ $this, 'save_metabox' ], 30, 2 );
        add_action( 'admin_enqueue_scripts', [ $this, 'maybe_enqueue_media_picker' ] );

        // AJAX upload (artwork + optional source).
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_artwork_upload' ] );

        // Campaign registration modal — the `[cw_event_detail]` shortcode bypasses
        // the standard WooCommerce single-product template, so we render the
        // upload UI inside its registration modal via our own hook.
        add_action( 'cw_reg_modal_before_rows', [ $this, 'render_upload_fields_for_product' ], 5, 1 );

        // WooCommerce integration.
        if ( class_exists( 'WooCommerce' ) ) {
            // Belt-and-braces: also render under the standard WC product template
            // for any theme / site that still serves the default product page.
            add_action( 'woocommerce_before_add_to_cart_button', [ $this, 'render_upload_fields' ], 5 );
            add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_cart_addition' ], 20, 6 );
            add_filter( 'woocommerce_add_cart_item_data', [ $this, 'save_to_cart_item_data' ], 20, 2 );
            add_filter( 'woocommerce_get_cart_item_from_session', [ $this, 'restore_cart_item_session' ], 10, 2 );
            add_filter( 'woocommerce_get_item_data', [ $this, 'display_cart_item_meta' ], 30, 2 );
            add_action( 'woocommerce_checkout_create_order_line_item', [ $this, 'save_to_order_line' ], 20, 4 );

            add_action( 'woocommerce_after_order_notes', [ $this, 'render_checkout_picker' ] );
            add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout' ] );

            // Copy from order line to entry CPT after CW_Shop creates entries.
            add_action( 'cw_entry_created_from_order', [ $this, 'copy_to_entry' ], 10, 3 );
        }

        // Assets — campaign page (upload) + checkout (picker).
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 30 );
    }

    // ─────────────────────────────────────────────────────────────────────
    // PUBLIC HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Is the Design Submission feature enabled on this campaign?
     */
    public static function is_enabled( $product_id ) {
        return 'yes' === get_post_meta( (int) $product_id, self::META_ENABLE, true );
    }

    /**
     * Returns the full design config for a campaign, with defaults applied.
     *
     * @return array{enabled:bool,label:string,width:int,height:int,variants:array,default:string}
     */
    public static function get_config( $product_id ) {
        $product_id = (int) $product_id;
        $variants   = get_post_meta( $product_id, self::META_VARIANTS, true );
        $variants   = is_array( $variants ) ? array_values( $variants ) : [];
        $default    = (string) get_post_meta( $product_id, self::META_DEFAULT_VARIANT, true );

        if ( $default === '' && ! empty( $variants[0]['slug'] ) ) {
            $default = (string) $variants[0]['slug'];
        }

        $label = (string) get_post_meta( $product_id, self::META_PICKER_LABEL, true );
        if ( $label === '' ) {
            $label = __( 'Choose your option', 'creativewings-core' );
        }

        return [
            'enabled'  => self::is_enabled( $product_id ),
            'label'    => $label,
            'width'    => max( 1, (int) get_post_meta( $product_id, self::META_WIDTH, true ) ),
            'height'   => max( 1, (int) get_post_meta( $product_id, self::META_HEIGHT, true ) ),
            'variants' => $variants,
            'default'  => $default,
        ];
    }

    /**
     * Lookup a single variant on a campaign by slug.
     *
     * @return array|null { slug, name, attachment_id, image_url? } or null when not found.
     */
    public static function get_variant( $product_id, $variant_slug ) {
        $cfg = self::get_config( $product_id );
        foreach ( $cfg['variants'] as $v ) {
            if ( isset( $v['slug'] ) && $v['slug'] === $variant_slug ) {
                $v['image_url'] = isset( $v['attachment_id'] ) ? (string) wp_get_attachment_url( (int) $v['attachment_id'] ) : '';
                return $v;
            }
        }
        return null;
    }

    /**
     * Sanitiser for the variants repeater. Drops empty rows. Guarantees unique
     * slugs by suffixing collisions with -2, -3, … Used both by the metabox
     * save path and by the wizard persistence layer.
     *
     * @param array $rows raw repeater rows (each: name, attachment_id, slug?)
     * @return array sanitised, indexed-from-zero list of { slug, name, attachment_id }
     */
    public static function sanitize_variants( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }

        $out   = [];
        $seen  = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $aid  = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            if ( $name === '' || $aid <= 0 ) {
                continue;
            }

            $base_slug = isset( $row['slug'] ) ? sanitize_title( $row['slug'] ) : '';
            if ( $base_slug === '' ) {
                $base_slug = sanitize_title( $name );
            }
            if ( $base_slug === '' ) {
                $base_slug = 'variant';
            }

            $slug = $base_slug;
            $n    = 2;
            while ( isset( $seen[ $slug ] ) ) {
                $slug = $base_slug . '-' . $n;
                $n++;
            }
            $seen[ $slug ] = true;

            $out[] = [
                'slug'          => $slug,
                'name'          => $name,
                'attachment_id' => $aid,
            ];
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ADMIN METABOX
    // ─────────────────────────────────────────────────────────────────────

    public function register_metabox() {
        add_meta_box(
            'cw_campaign_design',
            __( 'Design Submission', 'creativewings-core' ),
            [ $this, 'render_metabox' ],
            'product',
            'normal',
            'default'
        );
    }

    /**
     * The variant repeater uses `wp.media` to pick attachment IDs — make sure
     * the library is enqueued on the product editor screen even when other
     * plugins have suppressed it.
     */
    public function maybe_enqueue_media_picker( $hook ) {
        if ( ! in_array( $hook, [ 'post.php', 'post-new.php' ], true ) ) {
            return;
        }
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        if ( ! $screen || 'product' !== $screen->post_type ) {
            return;
        }
        wp_enqueue_media();
    }

    public function render_metabox( $post ) {
        wp_nonce_field( self::NONCE_METABOX, self::NONCE_METABOX );
        $cfg = self::get_config( $post->ID );
        ?>
        <p style="margin-top:0;">
            <?php esc_html_e( 'Lets participants upload a PNG artwork that gets composited onto a product variant the organizer pre-configures here.', 'creativewings-core' ); ?>
        </p>

        <p>
            <label>
                <input type="checkbox" name="<?php echo esc_attr( self::META_ENABLE ); ?>" value="yes" <?php checked( $cfg['enabled'], true ); ?>>
                <strong><?php esc_html_e( 'Enable Design Submission for this campaign', 'creativewings-core' ); ?></strong>
            </label>
        </p>

        <div class="cw-design-config" style="<?php echo $cfg['enabled'] ? '' : 'opacity:.55;'; ?>">
            <p>
                <label for="cw_design_picker_label" style="font-weight:600;">
                    <?php esc_html_e( 'Variant picker label (shown above the swatches on checkout)', 'creativewings-core' ); ?>
                </label><br>
                <input type="text" id="cw_design_picker_label" name="<?php echo esc_attr( self::META_PICKER_LABEL ); ?>"
                       value="<?php echo esc_attr( $cfg['label'] ); ?>"
                       placeholder="<?php esc_attr_e( 'Choose your color', 'creativewings-core' ); ?>"
                       class="regular-text" style="width:100%;max-width:480px;">
            </p>

            <p style="display:flex;gap:24px;flex-wrap:wrap;">
                <span>
                    <label for="cw_design_artwork_w" style="font-weight:600;">
                        <?php esc_html_e( 'Artwork width (px)', 'creativewings-core' ); ?>
                    </label><br>
                    <input type="number" min="1" step="1" id="cw_design_artwork_w"
                           name="<?php echo esc_attr( self::META_WIDTH ); ?>"
                           value="<?php echo esc_attr( $cfg['width'] > 1 ? $cfg['width'] : '' ); ?>"
                           placeholder="2400" style="width:160px;">
                </span>
                <span>
                    <label for="cw_design_artwork_h" style="font-weight:600;">
                        <?php esc_html_e( 'Artwork height (px)', 'creativewings-core' ); ?>
                    </label><br>
                    <input type="number" min="1" step="1" id="cw_design_artwork_h"
                           name="<?php echo esc_attr( self::META_HEIGHT ); ?>"
                           value="<?php echo esc_attr( $cfg['height'] > 1 ? $cfg['height'] : '' ); ?>"
                           placeholder="600" style="width:160px;">
                </span>
            </p>
            <p style="color:#555;font-size:12px;margin-top:-4px;">
                <?php esc_html_e( 'Every participant uploads at this exact size. Each variant image you upload below must also be the same size so the artwork lines up pixel-perfect.', 'creativewings-core' ); ?>
            </p>

            <h4 style="margin:18px 0 4px;"><?php esc_html_e( 'Product variants', 'creativewings-core' ); ?></h4>
            <p style="color:#555;font-size:12px;margin-top:0;">
                <?php esc_html_e( 'Add as many variants as you like (most campaigns use 3-6). Each variant needs a name and a base image at the artwork dimensions above. The "Default" radio decides which variant is pre-selected on checkout.', 'creativewings-core' ); ?>
            </p>

            <table class="widefat striped cw-design-variants" style="max-width:760px;">
                <thead>
                    <tr>
                        <th style="width:120px;"><?php esc_html_e( 'Image', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'Name', 'creativewings-core' ); ?></th>
                        <th style="width:60px;text-align:center;"><?php esc_html_e( 'Default', 'creativewings-core' ); ?></th>
                        <th style="width:60px;"></th>
                    </tr>
                </thead>
                <tbody id="cw-design-variants-tbody">
                    <?php
                    $rows = $cfg['variants'];
                    if ( empty( $rows ) ) {
                        $rows = [ [ 'slug' => '', 'name' => '', 'attachment_id' => 0 ] ];
                    }
                    foreach ( $rows as $i => $v ) {
                        $this->render_variant_row( $i, (array) $v, $cfg['default'] );
                    }
                    ?>
                </tbody>
            </table>

            <p style="margin-top:8px;">
                <button type="button" class="button" id="cw-design-add-variant">
                    <span class="dashicons dashicons-plus" style="vertical-align:middle;"></span>
                    <?php esc_html_e( 'Add variant', 'creativewings-core' ); ?>
                </button>
            </p>
        </div>

        <script>
        (function(){
            var tbody  = document.getElementById('cw-design-variants-tbody');
            var addBtn = document.getElementById('cw-design-add-variant');
            if (!tbody || !addBtn) return;

            function nextIndex(){
                var rows = tbody.querySelectorAll('tr');
                var max = -1;
                rows.forEach(function(r){
                    var idx = parseInt(r.getAttribute('data-idx') || '0', 10);
                    if (idx > max) max = idx;
                });
                return max + 1;
            }

            function blankRowHTML(i){
                var base = <?php echo wp_json_encode( esc_attr( self::META_VARIANTS ) ); ?>;
                return ''+
                  '<tr data-idx="'+i+'">'+
                    '<td>'+
                      '<div class="cw-design-variant-thumb" style="width:90px;height:60px;background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;text-align:center;">No image</div>'+
                      '<input type="hidden" name="'+base+'['+i+'][attachment_id]" value="" class="cw-design-variant-aid">'+
                      '<button type="button" class="button button-small cw-design-variant-pick" style="margin-top:4px;">'+
                        <?php echo wp_json_encode( esc_html__( 'Select image', 'creativewings-core' ) ); ?>+
                      '</button>'+
                    '</td>'+
                    '<td>'+
                      '<input type="text" name="'+base+'['+i+'][name]" value="" placeholder="'+<?php echo wp_json_encode( esc_attr__( 'e.g. Midnight Blue', 'creativewings-core' ) ); ?>+'" class="regular-text" style="width:100%;">'+
                      '<input type="hidden" name="'+base+'['+i+'][slug]" value="" class="cw-design-variant-slug">'+
                    '</td>'+
                    '<td style="text-align:center;">'+
                      '<input type="radio" name="<?php echo esc_attr( self::META_DEFAULT_VARIANT ); ?>" value="" class="cw-design-variant-default">'+
                    '</td>'+
                    '<td>'+
                      '<button type="button" class="button-link-delete cw-design-variant-remove">'+
                        <?php echo wp_json_encode( esc_html__( 'Remove', 'creativewings-core' ) ); ?>+
                      '</button>'+
                    '</td>'+
                  '</tr>';
            }

            addBtn.addEventListener('click', function(){
                var i = nextIndex();
                tbody.insertAdjacentHTML('beforeend', blankRowHTML(i));
            });

            tbody.addEventListener('click', function(e){
                var t = e.target;
                if (t.classList.contains('cw-design-variant-remove')) {
                    var row = t.closest('tr');
                    if (row) row.parentNode.removeChild(row);
                }
                if (t.classList.contains('cw-design-variant-pick')) {
                    e.preventDefault();
                    if (typeof wp === 'undefined' || !wp.media) {
                        alert('Media library not available.');
                        return;
                    }
                    var row = t.closest('tr');
                    var frame = wp.media({
                        title: <?php echo wp_json_encode( __( 'Select variant image', 'creativewings-core' ) ); ?>,
                        button: { text: <?php echo wp_json_encode( __( 'Use this image', 'creativewings-core' ) ); ?> },
                        library: { type: 'image' },
                        multiple: false
                    });
                    frame.on('select', function(){
                        var att = frame.state().get('selection').first().toJSON();
                        row.querySelector('.cw-design-variant-aid').value = att.id;
                        var thumb = row.querySelector('.cw-design-variant-thumb');
                        thumb.innerHTML = '';
                        thumb.style.background = '#fff';
                        thumb.style.border = '1px solid #cbd5e1';
                        var img = document.createElement('img');
                        img.src = (att.sizes && att.sizes.thumbnail ? att.sizes.thumbnail.url : att.url);
                        img.style.maxWidth = '90px';
                        img.style.maxHeight = '60px';
                        img.style.objectFit = 'contain';
                        thumb.appendChild(img);
                    });
                    frame.open();
                }
            });

            // Auto-fill slug + default-radio value from the name field as you type.
            tbody.addEventListener('input', function(e){
                if (e.target.matches('input[type="text"]')) {
                    var row = e.target.closest('tr');
                    var slugInput = row.querySelector('.cw-design-variant-slug');
                    var radio     = row.querySelector('.cw-design-variant-default');
                    var slug = e.target.value.toString().toLowerCase()
                        .replace(/[^a-z0-9]+/g, '-')
                        .replace(/^-+|-+$/g, '');
                    if (slugInput) slugInput.value = slug;
                    if (radio) radio.value = slug;
                }
            });
        })();
        </script>
        <?php
    }

    private function render_variant_row( $i, array $v, $default_slug ) {
        $i        = (int) $i;
        $name     = (string) ( $v['name'] ?? '' );
        $slug     = (string) ( $v['slug'] ?? sanitize_title( $name ) );
        $aid      = (int) ( $v['attachment_id'] ?? 0 );
        $base     = self::META_VARIANTS;
        $thumb    = $aid ? wp_get_attachment_image( $aid, [ 90, 60 ], false, [ 'style' => 'max-width:90px;max-height:60px;object-fit:contain;' ] ) : '';
        $is_default = $slug !== '' && $slug === $default_slug;
        ?>
        <tr data-idx="<?php echo $i; ?>">
            <td>
                <div class="cw-design-variant-thumb" style="width:90px;height:60px;background:<?php echo $thumb ? '#fff' : '#f1f5f9'; ?>;border:<?php echo $thumb ? '1px solid #cbd5e1' : '1px dashed #cbd5e1'; ?>;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:11px;text-align:center;">
                    <?php echo $thumb ? $thumb : esc_html__( 'No image', 'creativewings-core' ); ?>
                </div>
                <input type="hidden" name="<?php echo esc_attr( $base ); ?>[<?php echo $i; ?>][attachment_id]" value="<?php echo esc_attr( (string) $aid ); ?>" class="cw-design-variant-aid">
                <button type="button" class="button button-small cw-design-variant-pick" style="margin-top:4px;">
                    <?php $thumb ? esc_html_e( 'Change image', 'creativewings-core' ) : esc_html_e( 'Select image', 'creativewings-core' ); ?>
                </button>
            </td>
            <td>
                <input type="text" name="<?php echo esc_attr( $base ); ?>[<?php echo $i; ?>][name]" value="<?php echo esc_attr( $name ); ?>" placeholder="<?php esc_attr_e( 'e.g. Midnight Blue', 'creativewings-core' ); ?>" class="regular-text" style="width:100%;">
                <input type="hidden" name="<?php echo esc_attr( $base ); ?>[<?php echo $i; ?>][slug]" value="<?php echo esc_attr( $slug ); ?>" class="cw-design-variant-slug">
            </td>
            <td style="text-align:center;">
                <input type="radio" name="<?php echo esc_attr( self::META_DEFAULT_VARIANT ); ?>" value="<?php echo esc_attr( $slug ); ?>" class="cw-design-variant-default" <?php checked( $is_default, true ); ?>>
            </td>
            <td>
                <button type="button" class="button-link-delete cw-design-variant-remove">
                    <?php esc_html_e( 'Remove', 'creativewings-core' ); ?>
                </button>
            </td>
        </tr>
        <?php
    }

    public function save_metabox( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        if ( ! isset( $_POST[ self::NONCE_METABOX ] ) ) {
            return; // Metabox not on this submission.
        }
        if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_METABOX ] ) ), self::NONCE_METABOX ) ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        $enabled = ! empty( $_POST[ self::META_ENABLE ] ) ? 'yes' : 'no';
        update_post_meta( $post_id, self::META_ENABLE, $enabled );

        $label = isset( $_POST[ self::META_PICKER_LABEL ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::META_PICKER_LABEL ] ) ) : '';
        update_post_meta( $post_id, self::META_PICKER_LABEL, $label );

        $w = isset( $_POST[ self::META_WIDTH ] ) ? max( 0, (int) $_POST[ self::META_WIDTH ] ) : 0;
        $h = isset( $_POST[ self::META_HEIGHT ] ) ? max( 0, (int) $_POST[ self::META_HEIGHT ] ) : 0;
        update_post_meta( $post_id, self::META_WIDTH, $w );
        update_post_meta( $post_id, self::META_HEIGHT, $h );

        $raw_variants = isset( $_POST[ self::META_VARIANTS ] ) && is_array( $_POST[ self::META_VARIANTS ] )
            ? wp_unslash( $_POST[ self::META_VARIANTS ] )
            : [];
        $variants = self::sanitize_variants( $raw_variants );
        update_post_meta( $post_id, self::META_VARIANTS, $variants );

        $default = isset( $_POST[ self::META_DEFAULT_VARIANT ] ) ? sanitize_title( wp_unslash( $_POST[ self::META_DEFAULT_VARIANT ] ) ) : '';
        // Make sure the chosen default actually still exists post-sanitisation;
        // otherwise fall back to the first variant so the picker has something
        // to pre-select on checkout.
        $valid_default = '';
        foreach ( $variants as $v ) {
            if ( $v['slug'] === $default ) {
                $valid_default = $default;
                break;
            }
        }
        if ( $valid_default === '' && ! empty( $variants ) ) {
            $valid_default = (string) $variants[0]['slug'];
        }
        update_post_meta( $post_id, self::META_DEFAULT_VARIANT, $valid_default );

        if ( class_exists( 'CW_Campaign_Resolver' ) ) {
            CW_Campaign_Resolver::flush_serial_cache( $post_id );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // AJAX UPLOAD (artwork PNG + optional source file)
    // ─────────────────────────────────────────────────────────────────────

    public function handle_artwork_upload() {
        check_ajax_referer( self::NONCE_AJAX, 'security' );

        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => __( 'Please log in before uploading.', 'creativewings-core' ) ] );
        }

        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;
        $role       = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'artwork';

        if ( $product_id <= 0 || ! self::is_enabled( $product_id ) ) {
            wp_send_json_error( [ 'message' => __( 'This campaign does not accept design submissions.', 'creativewings-core' ) ] );
        }
        if ( ! in_array( $role, [ 'artwork', 'source' ], true ) ) {
            wp_send_json_error( [ 'message' => __( 'Invalid upload role.', 'creativewings-core' ) ] );
        }
        if ( empty( $_FILES['file_data'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Missing file.', 'creativewings-core' ) ] );
        }

        $file = $_FILES['file_data'];
        if ( ! empty( $file['error'] ) && (int) $file['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( [ 'message' => __( 'Upload error. Please try again.', 'creativewings-core' ) ] );
        }
        if ( (int) $file['size'] > self::MAX_UPLOAD_BYTES ) {
            wp_send_json_error( [
                'message' => sprintf(
                    /* translators: %d size in megabytes */
                    __( 'File too large. Maximum allowed: %d MB.', 'creativewings-core' ),
                    (int) ( self::MAX_UPLOAD_BYTES / 1024 / 1024 )
                ),
            ] );
        }

        $ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );

        // ── ROLE: artwork (PNG only, dimension-locked) ──
        if ( $role === 'artwork' ) {
            if ( $ext !== 'png' ) {
                wp_send_json_error( [ 'message' => __( 'Artwork must be a PNG file (.png).', 'creativewings-core' ) ] );
            }
            $mime = isset( $file['type'] ) ? (string) $file['type'] : '';
            if ( $mime !== 'image/png' ) {
                wp_send_json_error( [ 'message' => __( 'Artwork must be a PNG image (MIME mismatch).', 'creativewings-core' ) ] );
            }
        } else {
            // ── ROLE: source (AI / PDF / SVG / EPS) ──
            if ( ! in_array( $ext, self::SOURCE_EXT_WHITELIST, true ) ) {
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: %s allowed extensions */
                        __( 'Source file must be one of: %s.', 'creativewings-core' ),
                        '.' . implode( ', .', self::SOURCE_EXT_WHITELIST )
                    ),
                ] );
            }
            $mime = isset( $file['type'] ) ? (string) $file['type'] : '';
            // Octet-stream is a frequent fallback for .ai/.eps; we already
            // gated on extension so accepting it here is safe.
            if ( ! in_array( $mime, self::SOURCE_MIME_WHITELIST, true ) ) {
                wp_send_json_error( [ 'message' => __( 'Source file MIME type not allowed.', 'creativewings-core' ) ] );
            }
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        $move = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( ! $move || isset( $move['error'] ) ) {
            wp_send_json_error( [ 'message' => __( 'Upload failed: ', 'creativewings-core' ) . ( $move['error'] ?? 'unknown' ) ] );
        }

        // Artwork: enforce exact pixel dimensions via getimagesize() — the
        // server-side gate the client validator can't be trusted to provide.
        if ( $role === 'artwork' ) {
            $cfg = self::get_config( $product_id );
            $info = @getimagesize( $move['file'] );
            if ( ! is_array( $info ) || (int) $info[0] !== (int) $cfg['width'] || (int) $info[1] !== (int) $cfg['height'] ) {
                @unlink( $move['file'] );
                wp_send_json_error( [
                    'message' => sprintf(
                        /* translators: 1: required width, 2: required height */
                        __( 'Artwork must be exactly %1$d x %2$d pixels.', 'creativewings-core' ),
                        (int) $cfg['width'],
                        (int) $cfg['height']
                    ),
                ] );
            }
        }

        $attachment_id = wp_insert_attachment(
            [
                'post_mime_type' => $move['type'],
                'post_title'     => sanitize_file_name( basename( $move['file'] ) ),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ],
            $move['file']
        );

        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            @unlink( $move['file'] );
            wp_send_json_error( [ 'message' => __( 'Could not register attachment.', 'creativewings-core' ) ] );
        }

        $meta = wp_generate_attachment_metadata( $attachment_id, $move['file'] );
        wp_update_attachment_metadata( $attachment_id, $meta );

        // Track in WC session so we can pull it into cart-item-data on
        // add-to-cart. Mirrors the pattern CW_Ajax::handle_dynamic_file_upload
        // uses for the existing custom-fields uploader.
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_key = self::session_key( $product_id, $role );
            WC()->session->set( $session_key, (int) $attachment_id );
        }

        wp_send_json_success( [
            'attach_id' => (int) $attachment_id,
            'url'       => wp_get_attachment_url( $attachment_id ),
            'role'      => $role,
        ] );
    }

    private static function session_key( $product_id, $role ) {
        return 'cw_design_' . sanitize_key( $role ) . '_' . (int) $product_id;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CAMPAIGN PAGE — render upload UI before "Add to cart"
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Modal-context entrypoint: receives the product id explicitly because the
     * `[cw_event_detail]` shortcode invokes us outside a WC product template
     * (no `global $product`).
     */
    public function render_upload_fields_for_product( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id || ! self::is_enabled( $product_id ) ) {
            return;
        }
        $this->print_upload_html( $product_id );
    }

    public function render_upload_fields() {
        global $product;
        if ( ! $product ) {
            return;
        }
        $product_id = $product->get_id();
        if ( ! self::is_enabled( $product_id ) ) {
            return;
        }
        $this->print_upload_html( (int) $product_id );
    }

    private function print_upload_html( $product_id ) {
        $cfg = self::get_config( $product_id );
        $art_session    = self::session_key( $product_id, 'artwork' );
        $source_session = self::session_key( $product_id, 'source' );
        $art_id    = ( function_exists( 'WC' ) && WC()->session ) ? (int) WC()->session->get( $art_session ) : 0;
        $source_id = ( function_exists( 'WC' ) && WC()->session ) ? (int) WC()->session->get( $source_session ) : 0;
        ?>
        <div class="cw-design-upload" data-product-id="<?php echo (int) $product_id; ?>"
             data-width="<?php echo (int) $cfg['width']; ?>"
             data-height="<?php echo (int) $cfg['height']; ?>">

            <h3 class="cw-design-upload__title">
                <?php esc_html_e( 'Submit your design', 'creativewings-core' ); ?>
            </h3>
            <p class="cw-design-upload__intro">
                <?php
                printf(
                    /* translators: 1: width, 2: height */
                    esc_html__( 'Your artwork must be a PNG file at exactly %1$d x %2$d pixels.', 'creativewings-core' ),
                    (int) $cfg['width'],
                    (int) $cfg['height']
                );
                ?>
            </p>

            <div class="cw-design-upload__row">
                <label class="cw-design-upload__label">
                    <?php esc_html_e( 'Artwork (PNG)', 'creativewings-core' ); ?>
                    <span class="cw-design-required">*</span>
                </label>
                <input type="file" accept="image/png" class="cw-design-file" data-role="artwork">
                <input type="hidden" name="<?php echo esc_attr( self::CART_ARTWORK_ID ); ?>" value="<?php echo esc_attr( $art_id ); ?>" class="cw-design-aid" data-role="artwork">
                <div class="cw-design-feedback" data-role="artwork" aria-live="polite">
                    <?php if ( $art_id ) : ?>
                        <span class="cw-design-feedback__ok">
                            <?php esc_html_e( 'Artwork ready: ', 'creativewings-core' ); ?>
                            <a href="<?php echo esc_url( (string) wp_get_attachment_url( $art_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) get_attached_file( $art_id ) ) ); ?></a>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cw-design-upload__row">
                <label class="cw-design-upload__label">
                    <?php esc_html_e( 'Source file (AI / PDF / SVG / EPS) — optional', 'creativewings-core' ); ?>
                </label>
                <input type="file" accept=".ai,.pdf,.svg,.eps,application/postscript,application/illustrator,application/pdf,image/svg+xml" class="cw-design-file" data-role="source">
                <input type="hidden" name="<?php echo esc_attr( self::CART_SOURCE_ID ); ?>" value="<?php echo esc_attr( $source_id ); ?>" class="cw-design-aid" data-role="source">
                <div class="cw-design-feedback" data-role="source" aria-live="polite">
                    <?php if ( $source_id ) : ?>
                        <span class="cw-design-feedback__ok">
                            <?php esc_html_e( 'Source ready: ', 'creativewings-core' ); ?>
                            <a href="<?php echo esc_url( (string) wp_get_attachment_url( $source_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) get_attached_file( $source_id ) ) ); ?></a>
                        </span>
                    <?php endif; ?>
                </div>
                <p class="cw-design-hint">
                    <?php esc_html_e( 'Optional but recommended. Helps the printer reproduce your design at the highest quality.', 'creativewings-core' ); ?>
                </p>
            </div>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // CART / ORDER / ENTRY WIRING
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Block add-to-cart if the participant hasn't uploaded a PNG yet.
     */
    public function validate_cart_addition( $passed, $product_id, $qty, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        if ( ! self::is_enabled( $product_id ) ) {
            return $passed;
        }
        // School / claim flow doesn't go through the product form.
        if ( ! empty( $cart_item_data['cw_staged_id'] ) || ! empty( $cart_item_data['cw_claim_code'] ) ) {
            return $passed;
        }

        $art_id    = isset( $_POST[ self::CART_ARTWORK_ID ] ) ? (int) $_POST[ self::CART_ARTWORK_ID ] : 0;
        $session_k = self::session_key( (int) $product_id, 'artwork' );
        if ( ! $art_id && function_exists( 'WC' ) && WC()->session ) {
            $art_id = (int) WC()->session->get( $session_k );
        }

        if ( $art_id <= 0 ) {
            wc_add_notice( __( 'Please upload your artwork PNG before joining.', 'creativewings-core' ), 'error' );
            return false;
        }
        return $passed;
    }

    public function save_to_cart_item_data( $cart_item_data, $product_id ) {
        if ( ! self::is_enabled( $product_id ) ) {
            return $cart_item_data;
        }
        if ( ! empty( $cart_item_data['cw_staged_id'] ) || ! empty( $cart_item_data['cw_claim_code'] ) ) {
            return $cart_item_data; // School / claim path doesn't go through the upload form.
        }

        $art_id    = isset( $_POST[ self::CART_ARTWORK_ID ] ) ? (int) $_POST[ self::CART_ARTWORK_ID ] : 0;
        $source_id = isset( $_POST[ self::CART_SOURCE_ID ] ) ? (int) $_POST[ self::CART_SOURCE_ID ] : 0;

        if ( ! $art_id && function_exists( 'WC' ) && WC()->session ) {
            $art_id = (int) WC()->session->get( self::session_key( (int) $product_id, 'artwork' ) );
        }
        if ( ! $source_id && function_exists( 'WC' ) && WC()->session ) {
            $source_id = (int) WC()->session->get( self::session_key( (int) $product_id, 'source' ) );
        }

        if ( $art_id <= 0 ) {
            return $cart_item_data;
        }

        $cart_item_data[ self::CART_FLAG ]       = 'yes';
        $cart_item_data[ self::CART_ARTWORK_ID ] = $art_id;
        $cart_item_data[ self::CART_SOURCE_ID ]  = $source_id;

        // Clear the session keys so a subsequent visit doesn't pre-fill the
        // form with this submission's attachment (mirrors the existing
        // CW_Shop file-upload pattern).
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::session_key( (int) $product_id, 'artwork' ) );
            WC()->session->__unset( self::session_key( (int) $product_id, 'source' ) );
        }

        return $cart_item_data;
    }

    public function restore_cart_item_session( $cart_item, $values ) {
        foreach ( [ self::CART_FLAG, self::CART_ARTWORK_ID, self::CART_SOURCE_ID, self::CART_VARIANT ] as $k ) {
            if ( isset( $values[ $k ] ) ) {
                $cart_item[ $k ] = $values[ $k ];
            }
        }
        return $cart_item;
    }

    public function display_cart_item_meta( $item_data, $cart_item ) {
        if ( empty( $cart_item[ self::CART_FLAG ] ) ) {
            return $item_data;
        }
        if ( ! empty( $cart_item[ self::CART_ARTWORK_ID ] ) ) {
            $url = (string) wp_get_attachment_url( (int) $cart_item[ self::CART_ARTWORK_ID ] );
            if ( $url ) {
                $item_data[] = [
                    'key'   => __( 'Artwork', 'creativewings-core' ),
                    'value' => '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View uploaded PNG', 'creativewings-core' ) . '</a>',
                ];
            }
        }
        if ( ! empty( $cart_item[ self::CART_SOURCE_ID ] ) ) {
            $url = (string) wp_get_attachment_url( (int) $cart_item[ self::CART_SOURCE_ID ] );
            if ( $url ) {
                $item_data[] = [
                    'key'   => __( 'Source file', 'creativewings-core' ),
                    'value' => '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View source file', 'creativewings-core' ) . '</a>',
                ];
            }
        }
        if ( ! empty( $cart_item[ self::CART_VARIANT ] ) ) {
            $product_id = (int) ( $cart_item['product_id'] ?? 0 );
            $variant    = self::get_variant( $product_id, (string) $cart_item[ self::CART_VARIANT ] );
            if ( $variant && ! empty( $variant['name'] ) ) {
                $cfg = self::get_config( $product_id );
                $item_data[] = [
                    'key'   => $cfg['label'],
                    'value' => $variant['name'],
                ];
            }
        }
        return $item_data;
    }

    public function save_to_order_line( $item, $cart_item_key, $values, $order ) {
        if ( empty( $values[ self::CART_FLAG ] ) ) {
            return;
        }

        if ( ! empty( $values[ self::CART_ARTWORK_ID ] ) ) {
            $item->add_meta_data( '_' . self::CART_ARTWORK_ID, (int) $values[ self::CART_ARTWORK_ID ] );
        }
        if ( ! empty( $values[ self::CART_SOURCE_ID ] ) ) {
            $item->add_meta_data( '_' . self::CART_SOURCE_ID, (int) $values[ self::CART_SOURCE_ID ] );
        }

        // Variant is posted at checkout, keyed by cart_item_key so multi-line
        // carts with different design campaigns work side-by-side.
        $posted   = isset( $_POST[ self::CART_VARIANT ] ) ? wp_unslash( $_POST[ self::CART_VARIANT ] ) : null;
        $chosen   = '';
        if ( is_array( $posted ) ) {
            $chosen = isset( $posted[ $cart_item_key ] ) ? sanitize_title( $posted[ $cart_item_key ] ) : '';
        } elseif ( is_string( $posted ) && '' !== $posted ) {
            // Fallback for single-line carts where the field renders without a key suffix.
            $chosen = sanitize_title( $posted );
        }
        if ( '' === $chosen && ! empty( $values[ self::CART_VARIANT ] ) ) {
            $chosen = sanitize_title( (string) $values[ self::CART_VARIANT ] );
        }
        if ( '' === $chosen ) {
            $cfg = self::get_config( (int) ( $values['product_id'] ?? 0 ) );
            $chosen = $cfg['default'];
        }

        if ( '' !== $chosen ) {
            $item->add_meta_data( '_' . self::CART_VARIANT, $chosen );
            $variant = self::get_variant( (int) ( $values['product_id'] ?? 0 ), $chosen );
            if ( $variant && ! empty( $variant['name'] ) ) {
                // Human-readable label on the order item (visible to admin in
                // WooCommerce → Orders without needing custom rendering).
                $cfg = self::get_config( (int) ( $values['product_id'] ?? 0 ) );
                $item->add_meta_data( $cfg['label'], (string) $variant['name'] );
            }
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // CHECKOUT PICKER
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Returns the design-enabled cart items: [ cart_item_key => cart_item ].
     */
    private function design_cart_items() {
        $out = [];
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return $out;
        }
        foreach ( WC()->cart->get_cart() as $key => $item ) {
            if ( ! empty( $item[ self::CART_FLAG ] ) ) {
                $out[ $key ] = $item;
            }
        }
        return $out;
    }

    public function render_checkout_picker() {
        $items = $this->design_cart_items();
        if ( empty( $items ) ) {
            return;
        }
        echo '<div class="cw-design-checkout-pickers" id="cw-design-checkout-pickers">';

        foreach ( $items as $key => $item ) {
            $product_id = (int) $item['product_id'];
            $cfg        = self::get_config( $product_id );
            if ( empty( $cfg['variants'] ) ) {
                continue;
            }

            $artwork_url = ! empty( $item[ self::CART_ARTWORK_ID ] )
                ? (string) wp_get_attachment_url( (int) $item[ self::CART_ARTWORK_ID ] )
                : '';
            $chosen = ! empty( $item[ self::CART_VARIANT ] )
                ? (string) $item[ self::CART_VARIANT ]
                : $cfg['default'];

            $picker_data = [
                'cartKey'   => $key,
                'productId' => $product_id,
                'width'     => $cfg['width'],
                'height'    => $cfg['height'],
                'artwork'   => $artwork_url,
                'default'   => $chosen,
                'variants'  => [],
            ];
            foreach ( $cfg['variants'] as $v ) {
                $picker_data['variants'][] = [
                    'slug' => (string) ( $v['slug'] ?? '' ),
                    'name' => (string) ( $v['name'] ?? '' ),
                    'url'  => (string) wp_get_attachment_url( (int) ( $v['attachment_id'] ?? 0 ) ),
                ];
            }
            ?>
            <div class="cw-design-picker form-row form-row-wide"
                 data-cart-key="<?php echo esc_attr( $key ); ?>"
                 data-config="<?php echo esc_attr( wp_json_encode( $picker_data ) ); ?>">
                <h3 class="cw-design-picker__heading">
                    <?php echo esc_html( $cfg['label'] ); ?>
                </h3>

                <div class="cw-design-picker__swatches" role="radiogroup" aria-label="<?php echo esc_attr( $cfg['label'] ); ?>">
                    <?php foreach ( $cfg['variants'] as $v ) :
                        $vslug = (string) ( $v['slug'] ?? '' );
                        $vname = (string) ( $v['name'] ?? '' );
                        $vimg  = (string) wp_get_attachment_image_url( (int) ( $v['attachment_id'] ?? 0 ), 'thumbnail' );
                        $is_on = ( $vslug === $chosen );
                    ?>
                        <button type="button"
                                class="cw-design-swatch <?php echo $is_on ? 'is-selected' : ''; ?>"
                                data-variant="<?php echo esc_attr( $vslug ); ?>"
                                role="radio"
                                aria-checked="<?php echo $is_on ? 'true' : 'false'; ?>"
                                title="<?php echo esc_attr( $vname ); ?>">
                            <?php if ( $vimg ) : ?>
                                <img src="<?php echo esc_url( $vimg ); ?>" alt="<?php echo esc_attr( $vname ); ?>">
                            <?php endif; ?>
                            <span class="cw-design-swatch__name"><?php echo esc_html( $vname ); ?></span>
                        </button>
                    <?php endforeach; ?>
                </div>

                <?php
                // Compute the display size server-side so the canvas always renders at
                // a phone-sized portrait regardless of aspect ratio. The drawing buffer
                // (the `width`/`height` attributes) stays at the full artwork dimensions
                // so the composited image is still crisp; only the CSS pixels shrink.
                $buf_w = max( 1, (int) $cfg['width'] );
                $buf_h = max( 1, (int) $cfg['height'] );
                $max_w = 220; // portrait-friendly cap
                $max_h = 420; // landscape-friendly cap
                $scale = min( $max_w / $buf_w, $max_h / $buf_h, 1 );
                $disp_w = (int) round( $buf_w * $scale );
                $disp_h = (int) round( $buf_h * $scale );
                ?>
                <div class="cw-design-picker__preview-wrap">
                    <canvas class="cw-design-picker__canvas"
                            width="<?php echo $buf_w; ?>"
                            height="<?php echo $buf_h; ?>"
                            style="width:<?php echo $disp_w; ?>px;height:<?php echo $disp_h; ?>px;"></canvas>
                    <div class="cw-design-picker__loading"><?php esc_html_e( 'Loading preview…', 'creativewings-core' ); ?></div>
                </div>

                <input type="hidden" name="<?php echo esc_attr( self::CART_VARIANT ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $chosen ); ?>" class="cw-design-picker__field">
            </div>
            <?php
        }

        echo '</div>';
    }

    public function validate_checkout() {
        $items = $this->design_cart_items();
        if ( empty( $items ) ) {
            return;
        }

        $posted = isset( $_POST[ self::CART_VARIANT ] ) ? wp_unslash( $_POST[ self::CART_VARIANT ] ) : [];
        foreach ( $items as $key => $item ) {
            $product_id = (int) $item['product_id'];
            $cfg        = self::get_config( $product_id );
            if ( empty( $cfg['variants'] ) ) {
                continue;
            }
            $chosen = is_array( $posted ) ? sanitize_title( $posted[ $key ] ?? '' ) : sanitize_title( (string) $posted );
            $valid = false;
            foreach ( $cfg['variants'] as $v ) {
                if ( isset( $v['slug'] ) && $v['slug'] === $chosen ) {
                    $valid = true;
                    break;
                }
            }
            if ( ! $valid ) {
                wc_add_notice(
                    sprintf(
                        /* translators: %s picker label */
                        __( 'Please select an option for "%s" before placing your order.', 'creativewings-core' ),
                        $cfg['label']
                    ),
                    'error'
                );
            }
        }
    }

    /**
     * Copy design line meta onto the entry post that CW_Shop just created.
     *
     * Fired by `do_action( 'cw_entry_created_from_order', $entry_id, $item, $order )`
     * which CW_Shop emits at the end of `create_entries_from_order`.
     *
     * @param int                  $entry_id
     * @param WC_Order_Item_Product $item
     * @param WC_Order             $order
     */
    public function copy_to_entry( $entry_id, $item, $order ) {
        if ( ! $entry_id || ! $item ) {
            return;
        }

        $art_id    = (int) $item->get_meta( '_' . self::CART_ARTWORK_ID );
        $source_id = (int) $item->get_meta( '_' . self::CART_SOURCE_ID );
        $variant   = (string) $item->get_meta( '_' . self::CART_VARIANT );

        if ( $art_id > 0 ) {
            update_post_meta( $entry_id, self::ENTRY_ARTWORK, $art_id );
            // Also surface the artwork URL on the standard `upload_document`
            // meta the existing dashboards already render — so judges see the
            // PNG on the entry detail view without any extra work.
            $url = wp_get_attachment_url( $art_id );
            if ( $url ) {
                update_post_meta( $entry_id, 'upload_document', $url );
            }
        }
        if ( $source_id > 0 ) {
            update_post_meta( $entry_id, self::ENTRY_SOURCE, $source_id );
        }
        if ( $variant !== '' ) {
            update_post_meta( $entry_id, self::ENTRY_VARIANT, $variant );
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // ASSET ENQUEUE
    // ─────────────────────────────────────────────────────────────────────

    public function enqueue_assets() {
        $is_design_product  = is_singular( 'product' ) && self::is_enabled( (int) get_the_ID() );
        $is_design_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! empty( $this->design_cart_items() );

        if ( ! $is_design_product && ! $is_design_checkout ) {
            return;
        }

        // CSS — shared across product page + checkout.
        if ( method_exists( 'CW_Core_Platform', 'asset' ) ) {
            $css = CW_Core_Platform::asset( 'assets/css/cw-style-design.css' );
            wp_enqueue_style(
                'cw-style-design',
                $css['url'],
                [],
                $css['version']
            );
        } else {
            wp_enqueue_style(
                'cw-style-design',
                CW_URL . 'assets/css/cw-style-design.css',
                [],
                defined( 'CW_VERSION' ) ? CW_VERSION : null
            );
        }

        // JS — handles client-side PNG dimension validation, AJAX upload,
        // and canvas mockup compositing. Vanilla, no jQuery dependency.
        wp_enqueue_script(
            'cw-design-preview',
            CW_URL . 'assets/js/cw-design-preview.js',
            [],
            defined( 'CW_VERSION' ) ? CW_VERSION : null,
            true
        );
        wp_localize_script( 'cw-design-preview', 'cwDesignVars', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( self::NONCE_AJAX ),
            'action'     => self::AJAX_ACTION,
            'messages'   => [
                'wrongExtension' => __( 'Please choose a PNG file.', 'creativewings-core' ),
                'wrongDimensions' => __( 'Artwork must be exactly %dpx × %dpx.', 'creativewings-core' ),
                'uploading'      => __( 'Uploading…', 'creativewings-core' ),
                'uploaded'       => __( 'Uploaded ✓', 'creativewings-core' ),
                'sourceUploaded' => __( 'Source file uploaded ✓', 'creativewings-core' ),
                'genericError'   => __( 'Upload failed. Please try again.', 'creativewings-core' ),
            ],
        ] );
    }
}
