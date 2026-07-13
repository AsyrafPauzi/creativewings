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

    const CART_FLAG        = 'cw_design_enabled';
    const CART_ARTWORK_ID  = 'cw_design_artwork_id';   // legacy singular (kept = artwork at slot 1)
    const CART_SOURCE_ID   = 'cw_design_source_id';    // legacy singular
    const CART_VARIANT     = 'cw_design_variant';      // legacy singular
    const CART_ARTWORK_IDS = 'cw_design_artwork_ids';  // NEW: array keyed by row num (1, 2, …)
    const CART_SOURCE_IDS  = 'cw_design_source_ids';   // NEW: array keyed by row num
    const CART_VARIANTS    = 'cw_design_variants_per'; // NEW: array of variant slugs keyed by row num

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

        // AJAX upload (artwork + optional source) — logged-in and guest registration.
        add_action( 'wp_ajax_' . self::AJAX_ACTION, [ $this, 'handle_artwork_upload' ] );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, [ $this, 'handle_artwork_upload' ] );

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

            // Post-payment mockup: shows the locked-in artwork-on-casing
            // preview with a per-slot "Download mockup PNG" button on
            // both the order-received (thank-you) page AND the My-Account
            // → Orders → View Order page (same hook fires on both).
            add_action( 'woocommerce_order_details_after_order_table', [ $this, 'render_order_mockups' ], 30, 1 );

            // Copy from order line to entry CPT after CW_Shop creates entries.
            add_action( 'cw_entry_created_from_order', [ $this, 'copy_to_entry' ], 10, 4 );
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
     * Multi-design = Design Submission enabled AND the campaign allows multiple
     * entries per cart line (competition `multiple_submissions=true` OR
     * activity `cw_allow_multiple_participants=yes`). In that case each
     * participant row uploads its own PNG + picks its own variant.
     */
    public static function is_multi_design( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! self::is_enabled( $product_id ) ) {
            return false;
        }
        if ( get_post_meta( $product_id, 'multiple_submissions', true ) === 'true' ) {
            return true;
        }
        if ( get_post_meta( $product_id, 'cw_allow_multiple_participants', true ) === 'yes' ) {
            return true;
        }
        return false;
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
     * Build a "mockup config" describing how to composite a competition
     * entry's PNG onto the casing variant it was submitted with. Used by
     * the business judging dashboard (entry cards, evaluation modal, and
     * a shared lightbox) so judges see the participant's design exactly
     * as it'll be printed on the product.
     *
     * Returns null when the entry isn't a design-submission entry (no
     * artwork id, missing variant config, or the source campaign has no
     * variants). In that case the dashboard falls back to its existing
     * plain image preview.
     *
     * @param int $entry_id  cw_competition_entry post id
     * @return array|null    [ artwork_url, variant_url, variant_name,
     *                        variant_slug, width, height, art_filename ]
     */
    public static function entry_mockup_data( $entry_id ) {
        $entry_id = (int) $entry_id;
        if ( ! $entry_id ) return null;

        $art_id = (int) get_post_meta( $entry_id, self::ENTRY_ARTWORK, true );
        if ( $art_id <= 0 ) {
            return null;
        }

        $product_id = (int) get_post_meta( $entry_id, 'product_id', true );
        if ( ! $product_id || ! self::is_enabled( $product_id ) ) {
            return null;
        }

        $cfg = self::get_config( $product_id );
        if ( empty( $cfg['variants'] ) ) {
            return null;
        }

        $slug = (string) get_post_meta( $entry_id, self::ENTRY_VARIANT, true );
        if ( $slug === '' ) {
            $slug = (string) $cfg['default'];
        }
        $variant = self::get_variant( $product_id, $slug );
        if ( ! $variant ) {
            // Fall back to the first variant so we still produce a mockup
            // rather than silently degrading to plain artwork.
            $variant = $cfg['variants'][0];
            $variant['image_url'] = isset( $variant['attachment_id'] ) ? (string) wp_get_attachment_url( (int) $variant['attachment_id'] ) : '';
            $slug = (string) ( $variant['slug'] ?? $slug );
        }

        $art_url = (string) wp_get_attachment_url( $art_id );
        if ( $art_url === '' ) {
            return null;
        }
        $art_path = (string) get_attached_file( $art_id );

        return [
            'artwork_url'  => $art_url,
            'art_filename' => $art_path ? basename( $art_path ) : '',
            'variant_url'  => (string) ( $variant['image_url'] ?? '' ),
            'variant_name' => (string) ( $variant['name'] ?? $slug ),
            'variant_slug' => $slug,
            'width'        => max( 1, (int) $cfg['width'] ),
            'height'       => max( 1, (int) $cfg['height'] ),
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

        if ( ! is_user_logged_in() && class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_PIC_UPLOAD . 'design_artwork', 60, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_send_json_error( [ 'message' => $rl->get_error_message() ] );
            }
        }

        $product_id = isset( $_POST['product_id'] ) ? (int) $_POST['product_id'] : 0;

        if ( class_exists( 'CW_Shop' ) ) {
            $block = CW_Shop::get_registration_block_reason( $product_id, false );
            if ( $block ) {
                wp_send_json_error( [ 'message' => $block ] );
            }
        }
        $role       = isset( $_POST['role'] ) ? sanitize_key( wp_unslash( $_POST['role'] ) ) : 'artwork';
        // `slot` lets a multi-design campaign upload one PNG per participant row.
        // 0 (or missing) keeps the legacy single-design behaviour intact.
        $slot       = isset( $_POST['slot'] ) ? max( 0, (int) $_POST['slot'] ) : 0;

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
        // uses for the existing custom-fields uploader. For multi-design the
        // slot suffix lets us stash one attachment per participant row.
        if ( function_exists( 'WC' ) && WC()->session ) {
            $session_key = self::session_key( $product_id, $role, $slot );
            WC()->session->set( $session_key, (int) $attachment_id );
        }

        wp_send_json_success( [
            'attach_id' => (int) $attachment_id,
            'url'       => wp_get_attachment_url( $attachment_id ),
            'role'      => $role,
            'slot'      => $slot,
        ] );
    }

    /**
     * Session key for a per-product, per-role, optionally per-slot upload stash.
     * Slot 0 (or omitted) preserves the legacy single-design key so existing
     * sessions stay valid.
     */
    private static function session_key( $product_id, $role, $slot = 0 ) {
        $key = 'cw_design_' . sanitize_key( $role ) . '_' . (int) $product_id;
        $slot = (int) $slot;
        if ( $slot > 0 ) {
            $key .= '_s' . $slot;
        }
        return $key;
    }

    // ─────────────────────────────────────────────────────────────────────
    // CAMPAIGN PAGE — render upload UI before "Add to cart"
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Modal-context entrypoint: receives the product id explicitly because the
     * `[cw_event_detail]` shortcode invokes us outside a WC product template
     * (no `global $product`).
     *
     * For multi-design campaigns we render NOTHING here — the modal JS injects
     * a per-row uploader inside each participant row using the template
     * returned by `widget_template_for_slot()`.
     */
    public function render_upload_fields_for_product( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id || ! self::is_enabled( $product_id ) ) {
            return;
        }
        if ( self::is_multi_design( $product_id ) ) {
            $cfg = self::get_config( $product_id );
            ?>
            <div class="cw-design-multi-banner">
                <p class="cw-design-multi-banner__title">
                    <i class="fas fa-image" aria-hidden="true"></i>
                    <?php esc_html_e( 'Each participant uploads their own design', 'creativewings-core' ); ?>
                </p>
                <p class="cw-design-multi-banner__hint">
                    <?php
                    printf(
                        /* translators: 1: width, 2: height */
                        esc_html__( 'Use the upload field inside each participant row below. PNG only, exactly %1$d x %2$d pixels per design.', 'creativewings-core' ),
                        (int) $cfg['width'],
                        (int) $cfg['height']
                    );
                    ?>
                </p>
            </div>
            <?php
            return;
        }
        self::print_upload_html( $product_id, 0 );
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
        self::print_upload_html( (int) $product_id, 0 );
    }

    /**
     * Builds an HTML template string for ONE per-row upload widget, with the
     * literal `{SLOT}` placeholder where the row number should be substituted
     * at JS injection time. Returned to PHP (then JSON-encoded into the modal
     * config) so the modal's `cwdBuildRow(num)` can plug it into every row.
     */
    public static function widget_template_for_slot( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id || ! self::is_enabled( $product_id ) ) {
            return '';
        }
        ob_start();
        self::print_upload_html( $product_id, '__SLOT__' );
        return ob_get_clean();
    }

    /**
     * @param int            $product_id
     * @param int|string|0   $slot   0 = legacy single (uses singular field names)
     *                               int >= 1 = multi (uses cw_design_artwork_ids[N])
     *                               '__SLOT__' = template-string placeholder (replaced
     *                               in JS); treated as multi-mode for markup purposes.
     */
    private static function print_upload_html( $product_id, $slot = 0 ) {
        $cfg          = self::get_config( $product_id );
        $is_template  = ( $slot === '__SLOT__' );
        $is_multi     = $is_template || ( is_int( $slot ) && $slot >= 1 );

        // Session-stored attachments — only meaningful for the legacy (non-multi)
        // path; the multi-flow always starts fresh per row because slots aren't
        // known until the modal opens.
        $art_id = $source_id = 0;
        if ( ! $is_multi && function_exists( 'WC' ) && WC()->session ) {
            $art_id    = (int) WC()->session->get( self::session_key( $product_id, 'artwork' ) );
            $source_id = (int) WC()->session->get( self::session_key( $product_id, 'source' ) );
        }

        // Field naming: arrays for multi, singular for legacy single-design.
        $artwork_name = $is_multi
            ? self::CART_ARTWORK_IDS . '[' . ( $is_template ? '{SLOT}' : (int) $slot ) . ']'
            : self::CART_ARTWORK_ID;
        $source_name  = $is_multi
            ? self::CART_SOURCE_IDS . '[' . ( $is_template ? '{SLOT}' : (int) $slot ) . ']'
            : self::CART_SOURCE_ID;
        $slot_attr    = $is_template ? '{SLOT}' : (int) $slot;

        $title = $is_multi
            ? sprintf( __( 'Submit design for Participant %s', 'creativewings-core' ), $is_template ? '{SLOT}' : (string) $slot )
            : __( 'Submit your design', 'creativewings-core' );
        ?>
        <div class="cw-design-upload<?php echo $is_multi ? ' is-per-row' : ''; ?>"
             data-product-id="<?php echo (int) $product_id; ?>"
             data-width="<?php echo (int) $cfg['width']; ?>"
             data-height="<?php echo (int) $cfg['height']; ?>"
             data-slot="<?php echo esc_attr( $slot_attr ); ?>">

            <?php if ( ! $is_multi ) : ?>
            <h3 class="cw-design-upload__title"><?php echo esc_html( $title ); ?></h3>
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
            <?php else : ?>
            <h4 class="cw-design-upload__title cw-design-upload__title--row"><?php echo esc_html( $title ); ?></h4>
            <?php endif; ?>

            <div class="cw-design-upload__row">
                <label class="cw-design-upload__label">
                    <?php esc_html_e( 'Artwork (PNG)', 'creativewings-core' ); ?>
                    <span class="cw-design-required">*</span>
                </label>
                <input type="file" accept="image/png" class="cw-design-file" data-role="artwork">
                <input type="hidden" name="<?php echo esc_attr( $artwork_name ); ?>" value="<?php echo esc_attr( $art_id ); ?>" class="cw-design-aid" data-role="artwork">
                <div class="cw-design-feedback" data-role="artwork" aria-live="polite">
                    <?php if ( $art_id ) : ?>
                        <span class="cw-design-feedback__ok">
                            <?php esc_html_e( 'Artwork ready: ', 'creativewings-core' ); ?>
                            <a href="<?php echo esc_url( (string) wp_get_attachment_url( $art_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) get_attached_file( $art_id ) ) ); ?></a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ( $is_multi && ! $is_template ) : /* row >= 2: offer prefill from row 1 */ ?>
                <?php if ( (int) $slot >= 2 ) : ?>
                <button type="button" class="cw-design-prefill-btn" data-fill-from-slot="1">
                    <i class="fas fa-clone" aria-hidden="true"></i> <?php esc_html_e( 'Use same artwork as participant 1', 'creativewings-core' ); ?>
                </button>
                <?php endif; ?>
                <?php endif; ?>
            </div>

            <div class="cw-design-upload__row">
                <label class="cw-design-upload__label">
                    <?php esc_html_e( 'Source file (AI / PDF / SVG / EPS) — optional', 'creativewings-core' ); ?>
                </label>
                <input type="file" accept=".ai,.pdf,.svg,.eps,application/postscript,application/illustrator,application/pdf,image/svg+xml" class="cw-design-file" data-role="source">
                <input type="hidden" name="<?php echo esc_attr( $source_name ); ?>" value="<?php echo esc_attr( $source_id ); ?>" class="cw-design-aid" data-role="source">
                <div class="cw-design-feedback" data-role="source" aria-live="polite">
                    <?php if ( $source_id ) : ?>
                        <span class="cw-design-feedback__ok">
                            <?php esc_html_e( 'Source ready: ', 'creativewings-core' ); ?>
                            <a href="<?php echo esc_url( (string) wp_get_attachment_url( $source_id ) ); ?>" target="_blank" rel="noopener"><?php echo esc_html( basename( (string) get_attached_file( $source_id ) ) ); ?></a>
                        </span>
                    <?php endif; ?>
                </div>
                <?php if ( ! $is_multi ) : ?>
                <p class="cw-design-hint">
                    <?php esc_html_e( 'Optional but recommended. Helps the printer reproduce your design at the highest quality.', 'creativewings-core' ); ?>
                </p>
                <?php endif; ?>
            </div>

            <?php
            // For the JS template version, add prefill button as a one-off that
            // appears only on rows >= 2 (the JS branches on slot at injection).
            if ( $is_template ) : ?>
            <button type="button" class="cw-design-prefill-btn cw-design-prefill-btn--template" data-fill-from-slot="1" style="display:none;">
                <i class="fas fa-clone" aria-hidden="true"></i> <?php esc_html_e( 'Use same artwork as participant 1', 'creativewings-core' ); ?>
            </button>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────────────────
    // CART / ORDER / ENTRY WIRING
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Read the artwork/source attachment IDs the participant POSTed (or stashed
     * in session) for the given product. Returns slot-keyed arrays:
     *
     *   [
     *     'artworks' => [ 1 => 123, 2 => 456, … ],
     *     'sources'  => [ 1 => 789, 2 => 0,   … ],
     *   ]
     *
     * For multi-design campaigns the modal sends `cw_design_artwork_ids[N]`.
     * For legacy single-design (standard WC product page) it sends the
     * singular `cw_design_artwork_id` — that gets remapped to slot 1.
     */
    private static function collect_posted_uploads( $product_id ) {
        $product_id = (int) $product_id;
        $artworks   = [];
        $sources    = [];

        // ── Multi: indexed arrays
        if ( isset( $_POST[ self::CART_ARTWORK_IDS ] ) && is_array( $_POST[ self::CART_ARTWORK_IDS ] ) ) {
            foreach ( wp_unslash( $_POST[ self::CART_ARTWORK_IDS ] ) as $slot => $aid ) {
                $slot = (int) $slot;
                $aid  = (int) $aid;
                if ( $slot >= 1 && $aid > 0 ) {
                    $artworks[ $slot ] = $aid;
                }
            }
        }
        if ( isset( $_POST[ self::CART_SOURCE_IDS ] ) && is_array( $_POST[ self::CART_SOURCE_IDS ] ) ) {
            foreach ( wp_unslash( $_POST[ self::CART_SOURCE_IDS ] ) as $slot => $sid ) {
                $slot = (int) $slot;
                $sid  = (int) $sid;
                if ( $slot >= 1 && $sid > 0 ) {
                    $sources[ $slot ] = $sid;
                }
            }
        }

        // ── Session fallback for slots the POST might have missed (e.g. JS
        //    didn't rehydrate the hidden field after AJAX). Walk slots 1..50.
        if ( function_exists( 'WC' ) && WC()->session ) {
            for ( $slot = 1; $slot <= 50; $slot++ ) {
                if ( empty( $artworks[ $slot ] ) ) {
                    $sid = (int) WC()->session->get( self::session_key( $product_id, 'artwork', $slot ) );
                    if ( $sid > 0 ) $artworks[ $slot ] = $sid;
                }
                if ( empty( $sources[ $slot ] ) ) {
                    $sid2 = (int) WC()->session->get( self::session_key( $product_id, 'source', $slot ) );
                    if ( $sid2 > 0 ) $sources[ $slot ] = $sid2;
                }
            }
        }

        // ── Legacy single-design fallback
        if ( empty( $artworks ) ) {
            $single = isset( $_POST[ self::CART_ARTWORK_ID ] ) ? (int) $_POST[ self::CART_ARTWORK_ID ] : 0;
            if ( ! $single && function_exists( 'WC' ) && WC()->session ) {
                $single = (int) WC()->session->get( self::session_key( $product_id, 'artwork' ) );
            }
            if ( $single > 0 ) {
                $artworks[1] = $single;
            }
        }
        if ( empty( $sources ) ) {
            $single_src = isset( $_POST[ self::CART_SOURCE_ID ] ) ? (int) $_POST[ self::CART_SOURCE_ID ] : 0;
            if ( ! $single_src && function_exists( 'WC' ) && WC()->session ) {
                $single_src = (int) WC()->session->get( self::session_key( $product_id, 'source' ) );
            }
            if ( $single_src > 0 ) {
                $sources[1] = $single_src;
            }
        }

        ksort( $artworks );
        ksort( $sources );
        return [ 'artworks' => $artworks, 'sources' => $sources ];
    }

    /**
     * Block add-to-cart if any required PNG slot is missing.
     * For multi-design: every participant row must have a PNG.
     */
    public function validate_cart_addition( $passed, $product_id, $qty, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        if ( ! self::is_enabled( $product_id ) ) {
            return $passed;
        }
        // School / claim flow doesn't go through the product form.
        if ( ! empty( $cart_item_data['cw_staged_id'] ) || ! empty( $cart_item_data['cw_claim_code'] ) ) {
            return $passed;
        }

        $uploads  = self::collect_posted_uploads( (int) $product_id );
        $artworks = $uploads['artworks'];

        if ( empty( $artworks ) ) {
            wc_add_notice( __( 'Please upload your artwork PNG before joining.', 'creativewings-core' ), 'error' );
            return false;
        }

        // Multi-design: every participant row must have its own PNG.
        if ( self::is_multi_design( (int) $product_id ) ) {
            $names = isset( $_POST['cw_names'] ) && is_array( $_POST['cw_names'] ) ? wp_unslash( $_POST['cw_names'] ) : [];
            $count = count( $names );
            if ( $count > 1 ) {
                for ( $slot = 1; $slot <= $count; $slot++ ) {
                    if ( empty( $artworks[ $slot ] ) ) {
                        wc_add_notice(
                            sprintf(
                                /* translators: %d row number */
                                __( 'Please upload an artwork PNG for participant %d.', 'creativewings-core' ),
                                $slot
                            ),
                            'error'
                        );
                        return false;
                    }
                }
            }
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

        $uploads  = self::collect_posted_uploads( (int) $product_id );
        $artworks = $uploads['artworks'];
        $sources  = $uploads['sources'];

        if ( empty( $artworks ) ) {
            return $cart_item_data;
        }

        $first_art = (int) reset( $artworks );
        $first_src = ! empty( $sources ) ? (int) reset( $sources ) : 0;

        $cart_item_data[ self::CART_FLAG ]        = 'yes';
        $cart_item_data[ self::CART_ARTWORK_IDS ] = $artworks;
        $cart_item_data[ self::CART_SOURCE_IDS ]  = $sources;
        // Keep the singular keys populated with slot 1 so any third-party code /
        // legacy display rendering that reads them keeps working.
        $cart_item_data[ self::CART_ARTWORK_ID ]  = $first_art;
        $cart_item_data[ self::CART_SOURCE_ID ]   = $first_src;

        // Clear the session keys so a subsequent visit doesn't pre-fill the
        // form with this submission's attachments.
        if ( function_exists( 'WC' ) && WC()->session ) {
            WC()->session->__unset( self::session_key( (int) $product_id, 'artwork' ) );
            WC()->session->__unset( self::session_key( (int) $product_id, 'source' ) );
            for ( $slot = 1; $slot <= 50; $slot++ ) {
                WC()->session->__unset( self::session_key( (int) $product_id, 'artwork', $slot ) );
                WC()->session->__unset( self::session_key( (int) $product_id, 'source', $slot ) );
            }
        }

        return $cart_item_data;
    }

    public function restore_cart_item_session( $cart_item, $values ) {
        foreach ( [
            self::CART_FLAG,
            self::CART_ARTWORK_ID, self::CART_SOURCE_ID, self::CART_VARIANT,
            self::CART_ARTWORK_IDS, self::CART_SOURCE_IDS, self::CART_VARIANTS,
        ] as $k ) {
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
        $product_id = (int) ( $cart_item['product_id'] ?? 0 );

        // Multi-design rendering: list each participant's artwork / variant
        // on its own row. Falls back to the legacy singular display when only
        // one slot exists (or for old cart items predating the array keys).
        $artworks  = isset( $cart_item[ self::CART_ARTWORK_IDS ] ) && is_array( $cart_item[ self::CART_ARTWORK_IDS ] )
            ? $cart_item[ self::CART_ARTWORK_IDS ]
            : [];
        $sources   = isset( $cart_item[ self::CART_SOURCE_IDS ] ) && is_array( $cart_item[ self::CART_SOURCE_IDS ] )
            ? $cart_item[ self::CART_SOURCE_IDS ]
            : [];
        $variants  = isset( $cart_item[ self::CART_VARIANTS ] ) && is_array( $cart_item[ self::CART_VARIANTS ] )
            ? $cart_item[ self::CART_VARIANTS ]
            : [];
        if ( empty( $artworks ) && ! empty( $cart_item[ self::CART_ARTWORK_ID ] ) ) {
            $artworks = [ 1 => (int) $cart_item[ self::CART_ARTWORK_ID ] ];
        }
        if ( empty( $sources ) && ! empty( $cart_item[ self::CART_SOURCE_ID ] ) ) {
            $sources = [ 1 => (int) $cart_item[ self::CART_SOURCE_ID ] ];
        }
        if ( empty( $variants ) && ! empty( $cart_item[ self::CART_VARIANT ] ) ) {
            $variants = [ 1 => (string) $cart_item[ self::CART_VARIANT ] ];
        }

        $cfg       = self::get_config( $product_id );
        $is_multi  = count( $artworks ) > 1;

        foreach ( $artworks as $slot => $aid ) {
            $url = (string) wp_get_attachment_url( (int) $aid );
            if ( $url ) {
                $item_data[] = [
                    'key'   => $is_multi
                        ? sprintf( __( 'Artwork #%d', 'creativewings-core' ), (int) $slot )
                        : __( 'Artwork', 'creativewings-core' ),
                    'value' => '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View uploaded PNG', 'creativewings-core' ) . '</a>',
                ];
            }
        }
        foreach ( $sources as $slot => $sid ) {
            $url = (string) wp_get_attachment_url( (int) $sid );
            if ( $url ) {
                $item_data[] = [
                    'key'   => $is_multi
                        ? sprintf( __( 'Source file #%d', 'creativewings-core' ), (int) $slot )
                        : __( 'Source file', 'creativewings-core' ),
                    'value' => '<a href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html__( 'View source file', 'creativewings-core' ) . '</a>',
                ];
            }
        }
        foreach ( $variants as $slot => $vslug ) {
            if ( $vslug === '' ) continue;
            $variant = self::get_variant( $product_id, (string) $vslug );
            if ( $variant && ! empty( $variant['name'] ) ) {
                $item_data[] = [
                    'key'   => $is_multi
                        ? sprintf( '%s #%d', $cfg['label'], (int) $slot )
                        : $cfg['label'],
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

        $product_id = (int) ( $values['product_id'] ?? 0 );
        $cfg        = self::get_config( $product_id );

        // ── Normalise to arrays so we always handle the multi-design shape.
        $artworks = isset( $values[ self::CART_ARTWORK_IDS ] ) && is_array( $values[ self::CART_ARTWORK_IDS ] )
            ? array_map( 'intval', $values[ self::CART_ARTWORK_IDS ] )
            : [];
        $sources  = isset( $values[ self::CART_SOURCE_IDS ] ) && is_array( $values[ self::CART_SOURCE_IDS ] )
            ? array_map( 'intval', $values[ self::CART_SOURCE_IDS ] )
            : [];
        if ( empty( $artworks ) && ! empty( $values[ self::CART_ARTWORK_ID ] ) ) {
            $artworks = [ 1 => (int) $values[ self::CART_ARTWORK_ID ] ];
        }
        if ( empty( $sources ) && ! empty( $values[ self::CART_SOURCE_ID ] ) ) {
            $sources = [ 1 => (int) $values[ self::CART_SOURCE_ID ] ];
        }
        ksort( $artworks );
        ksort( $sources );

        // Singular keys = slot 1 for backwards compatibility with any tooling
        // that reads them; array keys = full list (new entry copy path uses it).
        if ( ! empty( $artworks ) ) {
            $item->add_meta_data( '_' . self::CART_ARTWORK_ID,  (int) reset( $artworks ) );
            $item->add_meta_data( '_' . self::CART_ARTWORK_IDS, wp_json_encode( $artworks ) );
        }
        if ( ! empty( $sources ) ) {
            $item->add_meta_data( '_' . self::CART_SOURCE_ID,  (int) reset( $sources ) );
            $item->add_meta_data( '_' . self::CART_SOURCE_IDS, wp_json_encode( $sources ) );
        }

        // ── Variants: posted from checkout, keyed by cart_item_key. Each
        //    cart line can be either:
        //        $_POST['cw_design_variant'][cart_key]            = 'slug'         (single)
        //        $_POST['cw_design_variant'][cart_key][slot]      = 'slug'         (multi)
        $posted_root = isset( $_POST[ self::CART_VARIANT ] ) ? wp_unslash( $_POST[ self::CART_VARIANT ] ) : null;
        $posted_for_line = null;
        if ( is_array( $posted_root ) && isset( $posted_root[ $cart_item_key ] ) ) {
            $posted_for_line = $posted_root[ $cart_item_key ];
        } elseif ( is_string( $posted_root ) && '' !== $posted_root ) {
            // Single-line legacy fallback.
            $posted_for_line = $posted_root;
        }

        $variants_arr = []; // [ slot => slug ]
        if ( is_array( $posted_for_line ) ) {
            foreach ( $posted_for_line as $slot => $slug ) {
                $slot = (int) $slot;
                $slug = sanitize_title( (string) $slug );
                if ( $slot >= 1 && $slug !== '' ) {
                    $variants_arr[ $slot ] = $slug;
                }
            }
        } elseif ( is_string( $posted_for_line ) && $posted_for_line !== '' ) {
            $variants_arr[1] = sanitize_title( $posted_for_line );
        }

        // Fall back to whatever was already on the cart line (kept across page loads).
        if ( empty( $variants_arr ) && isset( $values[ self::CART_VARIANTS ] ) && is_array( $values[ self::CART_VARIANTS ] ) ) {
            foreach ( $values[ self::CART_VARIANTS ] as $slot => $slug ) {
                $slot = (int) $slot;
                $slug = sanitize_title( (string) $slug );
                if ( $slot >= 1 && $slug !== '' ) {
                    $variants_arr[ $slot ] = $slug;
                }
            }
        }
        if ( empty( $variants_arr ) && ! empty( $values[ self::CART_VARIANT ] ) ) {
            $variants_arr[1] = sanitize_title( (string) $values[ self::CART_VARIANT ] );
        }

        // Last-resort: default variant for every artwork slot we collected.
        if ( empty( $variants_arr ) && $cfg['default'] !== '' ) {
            foreach ( $artworks as $slot => $_aid ) {
                $variants_arr[ $slot ] = $cfg['default'];
            }
        }
        ksort( $variants_arr );

        if ( ! empty( $variants_arr ) ) {
            $first_slug = (string) reset( $variants_arr );
            $item->add_meta_data( '_' . self::CART_VARIANT,  $first_slug );
            $item->add_meta_data( '_' . self::CART_VARIANTS, wp_json_encode( $variants_arr ) );

            // Human-readable label for the order admin UI.
            if ( count( $variants_arr ) === 1 ) {
                $v = self::get_variant( $product_id, $first_slug );
                if ( $v && ! empty( $v['name'] ) ) {
                    $item->add_meta_data( $cfg['label'], (string) $v['name'] );
                }
            } else {
                $parts = [];
                foreach ( $variants_arr as $slot => $slug ) {
                    $v = self::get_variant( $product_id, $slug );
                    $name = ( $v && ! empty( $v['name'] ) ) ? (string) $v['name'] : $slug;
                    $parts[] = '#' . (int) $slot . ' ' . $name;
                }
                $item->add_meta_data( $cfg['label'], implode( ' · ', $parts ) );
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

            // ── Normalise this cart item into slot-keyed artwork + variant arrays.
            $artworks = isset( $item[ self::CART_ARTWORK_IDS ] ) && is_array( $item[ self::CART_ARTWORK_IDS ] )
                ? $item[ self::CART_ARTWORK_IDS ]
                : [];
            if ( empty( $artworks ) && ! empty( $item[ self::CART_ARTWORK_ID ] ) ) {
                $artworks = [ 1 => (int) $item[ self::CART_ARTWORK_ID ] ];
            }
            if ( empty( $artworks ) ) {
                continue; // Nothing to preview without an artwork.
            }
            ksort( $artworks );

            $chosen_per_slot = [];
            if ( isset( $item[ self::CART_VARIANTS ] ) && is_array( $item[ self::CART_VARIANTS ] ) ) {
                foreach ( $item[ self::CART_VARIANTS ] as $slot => $slug ) {
                    $chosen_per_slot[ (int) $slot ] = (string) $slug;
                }
            }
            if ( empty( $chosen_per_slot ) && ! empty( $item[ self::CART_VARIANT ] ) ) {
                $chosen_per_slot[1] = (string) $item[ self::CART_VARIANT ];
            }

            // Participant labels — try to surface the typed-in name beside the
            // picker so users can tell which design they're configuring. Falls
            // back to "Participant N".
            $participant_names = [];
            if ( isset( $item['cw_participants'] ) && is_array( $item['cw_participants'] ) ) {
                foreach ( $item['cw_participants'] as $idx => $fields ) {
                    $slot = (int) $idx;
                    if ( $slot < 1 ) $slot = 1;
                    if ( is_array( $fields ) ) {
                        foreach ( $fields as $f ) {
                            if ( isset( $f['label'], $f['value'] ) && strcasecmp( (string) $f['label'], 'Name' ) === 0 ) {
                                $nm = trim( (string) $f['value'] );
                                if ( $nm !== '' && strcasecmp( $nm, 'Self' ) !== 0 ) {
                                    $participant_names[ $slot ] = $nm;
                                }
                                break;
                            }
                        }
                    }
                }
            }

            $is_multi_line = count( $artworks ) > 1;

            // One outer container per cart line; one .cw-design-picker per slot
            // so the JS picker initialiser keeps working unchanged per-picker.
            ?>
            <div class="cw-design-checkout-line<?php echo $is_multi_line ? ' is-multi' : ''; ?>"
                 data-cart-key="<?php echo esc_attr( $key ); ?>">
                <?php
                if ( $is_multi_line ) {
                    echo '<h3 class="cw-design-checkout-line__heading">' . esc_html( $cfg['label'] ) . '</h3>';
                    echo '<p class="cw-design-checkout-line__hint">' . esc_html__( 'Pick a variant for each participant\'s artwork.', 'creativewings-core' ) . '</p>';
                }

                foreach ( $artworks as $slot => $aid ) :
                    $slot        = (int) $slot;
                    $artwork_url = (string) wp_get_attachment_url( $aid );
                    $chosen      = isset( $chosen_per_slot[ $slot ] ) && $chosen_per_slot[ $slot ] !== ''
                        ? $chosen_per_slot[ $slot ]
                        : $cfg['default'];
                    $picker_data = [
                        'cartKey'   => $key,
                        'productId' => $product_id,
                        'slot'      => $slot,
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

                    $heading_label = $is_multi_line
                        ? ( isset( $participant_names[ $slot ] )
                            ? sprintf( __( 'Design for %s', 'creativewings-core' ), $participant_names[ $slot ] )
                            : sprintf( __( 'Participant %d design', 'creativewings-core' ), $slot ) )
                        : $cfg['label'];

                    // Field name: cw_design_variant[cart_key][slot] for multi,
                    //            cw_design_variant[cart_key]        for single.
                    $field_name = $is_multi_line
                        ? sprintf( '%s[%s][%d]', self::CART_VARIANT, $key, $slot )
                        : sprintf( '%s[%s]',     self::CART_VARIANT, $key );

                    // Server-side display sizing — same logic as before, cached per loop.
                    $buf_w = max( 1, (int) $cfg['width'] );
                    $buf_h = max( 1, (int) $cfg['height'] );
                    $max_w = $is_multi_line ? 180 : 220;
                    $max_h = $is_multi_line ? 340 : 420;
                    $scale = min( $max_w / $buf_w, $max_h / $buf_h, 1 );
                    $disp_w = (int) round( $buf_w * $scale );
                    $disp_h = (int) round( $buf_h * $scale );
                ?>
                <div class="cw-design-picker form-row form-row-wide"
                     data-cart-key="<?php echo esc_attr( $key ); ?>"
                     data-slot="<?php echo (int) $slot; ?>"
                     data-config="<?php echo esc_attr( wp_json_encode( $picker_data ) ); ?>">
                    <h3 class="cw-design-picker__heading">
                        <?php echo esc_html( $heading_label ); ?>
                    </h3>

                    <?php
                        // "Your uploaded artwork" verification strip — gives the
                        // participant a quick way to confirm the bare PNG (and
                        // its filename) before they pay. The picker's compose
                        // canvas underneath then shows the same artwork on the
                        // chosen casing for visual sanity.
                        $art_path = (string) get_attached_file( (int) $aid );
                        $art_name = $art_path ? basename( $art_path ) : '';
                        $art_size = ( $art_path && file_exists( $art_path ) ) ? size_format( (int) filesize( $art_path ), 1 ) : '';
                    ?>
                    <div class="cw-design-picker__artwork">
                        <div class="cw-design-picker__artwork-thumb">
                            <?php if ( $artwork_url ): ?>
                            <img src="<?php echo esc_url( $artwork_url ); ?>" alt="<?php esc_attr_e( 'Uploaded artwork preview', 'creativewings-core' ); ?>" loading="lazy">
                            <?php endif; ?>
                        </div>
                        <div class="cw-design-picker__artwork-info">
                            <span class="cw-design-picker__artwork-label"><?php esc_html_e( 'Your uploaded artwork', 'creativewings-core' ); ?></span>
                            <?php if ( $art_name ): ?>
                            <span class="cw-design-picker__artwork-file" title="<?php echo esc_attr( $art_name ); ?>"><?php echo esc_html( $art_name ); ?></span>
                            <?php endif; ?>
                            <?php if ( $art_size ): ?>
                            <span class="cw-design-picker__artwork-meta"><?php echo esc_html( $art_size ); ?></span>
                            <?php endif; ?>
                            <?php if ( $artwork_url ): ?>
                            <a class="cw-design-picker__artwork-view" href="<?php echo esc_url( $artwork_url ); ?>" target="_blank" rel="noopener">
                                <i class="fas fa-external-link-alt" aria-hidden="true"></i> <?php esc_html_e( 'View full PNG', 'creativewings-core' ); ?>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="cw-design-picker__swatches" role="radiogroup" aria-label="<?php echo esc_attr( $heading_label ); ?>">
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

                    <div class="cw-design-picker__preview-wrap">
                        <canvas class="cw-design-picker__canvas"
                                width="<?php echo $buf_w; ?>"
                                height="<?php echo $buf_h; ?>"
                                style="width:<?php echo $disp_w; ?>px;height:<?php echo $disp_h; ?>px;"></canvas>
                        <div class="cw-design-picker__loading"><?php esc_html_e( 'Loading preview…', 'creativewings-core' ); ?></div>
                    </div>

                    <input type="hidden" name="<?php echo esc_attr( $field_name ); ?>" value="<?php echo esc_attr( $chosen ); ?>" class="cw-design-picker__field">
                </div>
                <?php endforeach; ?>
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

        $posted_root = isset( $_POST[ self::CART_VARIANT ] ) ? wp_unslash( $_POST[ self::CART_VARIANT ] ) : [];
        foreach ( $items as $key => $item ) {
            $product_id = (int) $item['product_id'];
            $cfg        = self::get_config( $product_id );
            if ( empty( $cfg['variants'] ) ) {
                continue;
            }

            // Build a slug → true lookup for fast validation.
            $valid_slugs = [];
            foreach ( $cfg['variants'] as $v ) {
                if ( isset( $v['slug'] ) ) $valid_slugs[ (string) $v['slug'] ] = true;
            }

            // Resolve how many slots this cart line has (= artworks).
            $artworks = isset( $item[ self::CART_ARTWORK_IDS ] ) && is_array( $item[ self::CART_ARTWORK_IDS ] )
                ? $item[ self::CART_ARTWORK_IDS ]
                : [];
            if ( empty( $artworks ) && ! empty( $item[ self::CART_ARTWORK_ID ] ) ) {
                $artworks = [ 1 => (int) $item[ self::CART_ARTWORK_ID ] ];
            }
            $expected_slots = ! empty( $artworks ) ? array_keys( $artworks ) : [ 1 ];

            // Extract the posted variant(s) for this cart line.
            $posted_for_line = null;
            if ( is_array( $posted_root ) && isset( $posted_root[ $key ] ) ) {
                $posted_for_line = $posted_root[ $key ];
            } elseif ( is_string( $posted_root ) ) {
                $posted_for_line = $posted_root;
            }

            $all_ok = true;
            $bad_slot = 0;
            foreach ( $expected_slots as $slot ) {
                $slot = (int) $slot;
                if ( is_array( $posted_for_line ) ) {
                    $slug = sanitize_title( (string) ( $posted_for_line[ $slot ] ?? '' ) );
                } else {
                    $slug = sanitize_title( (string) ( $posted_for_line ?? '' ) );
                }
                if ( ! isset( $valid_slugs[ $slug ] ) ) {
                    $all_ok = false;
                    $bad_slot = $slot;
                    break;
                }
            }
            if ( ! $all_ok ) {
                if ( count( $expected_slots ) > 1 ) {
                    wc_add_notice(
                        sprintf(
                            /* translators: 1: picker label, 2: participant slot */
                            __( 'Please select an option for "%1$s" (participant %2$d) before placing your order.', 'creativewings-core' ),
                            $cfg['label'],
                            $bad_slot
                        ),
                        'error'
                    );
                } else {
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
    }

    // ─────────────────────────────────────────────────────────────────────
    // POST-PAYMENT MOCKUPS (order-received + view-order)
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Render the locked-in artwork-on-casing mockup for every design-enabled
     * line item on an order, with a per-slot "Download mockup PNG" button.
     *
     * Hooked on `woocommerce_order_details_after_order_table` so it appears
     * on BOTH the order-received (thank-you) page AND the My-Account →
     * View Order page without duplicate registration.
     *
     * Same-origin uploads + variant images mean the <canvas> stays untainted
     * and `canvas.toBlob()` works for the client-side download — no server-
     * side composite (GD/Imagick) needed.
     *
     * @param WC_Order $order
     */
    public function render_order_mockups( $order ) {
        if ( ! $order || ! is_a( $order, 'WC_Order' ) ) {
            return;
        }

        // Collect renderable lines first so we can short-circuit cleanly if
        // the order has none (avoids printing an empty section header).
        $blocks = [];
        foreach ( $order->get_items() as $item_id => $item ) {
            if ( ! is_a( $item, 'WC_Order_Item_Product' ) ) {
                continue;
            }
            $product_id = (int) $item->get_product_id();
            if ( ! self::is_enabled( $product_id ) ) {
                continue;
            }
            $cfg = self::get_config( $product_id );
            if ( empty( $cfg['variants'] ) ) {
                continue;
            }

            $artworks = self::decode_indexed_meta( (string) $item->get_meta( '_' . self::CART_ARTWORK_IDS ) );
            $variants = self::decode_indexed_meta( (string) $item->get_meta( '_' . self::CART_VARIANTS ) );

            if ( empty( $artworks ) ) {
                $legacy_art = (int) $item->get_meta( '_' . self::CART_ARTWORK_ID );
                if ( $legacy_art > 0 ) {
                    $artworks = [ 1 => $legacy_art ];
                }
            }
            if ( empty( $variants ) ) {
                $legacy_var = (string) $item->get_meta( '_' . self::CART_VARIANT );
                if ( $legacy_var !== '' ) {
                    $variants = [ 1 => $legacy_var ];
                }
            }
            if ( empty( $artworks ) ) {
                continue;
            }
            ksort( $artworks );

            $line_mockups = [];
            foreach ( $artworks as $slot => $aid ) {
                $slot     = (int) $slot;
                $aid      = (int) $aid;
                $art_url  = (string) wp_get_attachment_url( $aid );
                if ( $art_url === '' ) {
                    continue;
                }
                $chosen   = isset( $variants[ $slot ] ) && $variants[ $slot ] !== '' ? (string) $variants[ $slot ] : $cfg['default'];
                $variant  = self::get_variant( $product_id, $chosen );
                $vname    = ( $variant && ! empty( $variant['name'] ) ) ? (string) $variant['name'] : $chosen;
                $vurl     = ( $variant && ! empty( $variant['image_url'] ) ) ? (string) $variant['image_url'] : '';

                $line_mockups[] = [
                    'slot'      => $slot,
                    'art_url'   => $art_url,
                    'art_name'  => basename( (string) get_attached_file( $aid ) ),
                    'variant'   => $chosen,
                    'v_name'    => $vname,
                    'v_url'     => $vurl,
                    'config'    => [
                        'orderId'    => (int) $order->get_id(),
                        'itemId'     => (int) $item_id,
                        'productId'  => $product_id,
                        'slot'       => $slot,
                        'width'      => (int) $cfg['width'],
                        'height'     => (int) $cfg['height'],
                        'artwork'    => $art_url,
                        'variant'    => $chosen,
                        'variantUrl' => $vurl,
                        'variantName'=> $vname,
                        'filename'   => sprintf( 'mockup-order-%d-item-%d-slot-%d.png', (int) $order->get_id(), (int) $item_id, $slot ),
                    ],
                ];
            }
            if ( empty( $line_mockups ) ) {
                continue;
            }
            $blocks[] = [
                'product_id' => $product_id,
                'product'    => $item->get_name(),
                'cfg'        => $cfg,
                'mockups'    => $line_mockups,
            ];
        }

        if ( empty( $blocks ) ) {
            return;
        }
        ?>
        <section class="cw-design-mockups">
            <h2 class="cw-design-mockups__title">
                <i class="fas fa-image" aria-hidden="true"></i>
                <?php esc_html_e( 'Your mockups', 'creativewings-core' ); ?>
            </h2>
            <p class="cw-design-mockups__hint">
                <?php esc_html_e( 'A preview of each artwork on its chosen casing. Click "Download mockup" to save a high-resolution PNG.', 'creativewings-core' ); ?>
            </p>

            <?php foreach ( $blocks as $block ):
                $is_multi = count( $block['mockups'] ) > 1;
            ?>
            <div class="cw-design-mockups__line<?php echo $is_multi ? ' is-multi' : ''; ?>">
                <h3 class="cw-design-mockups__line-title"><?php echo esc_html( $block['product'] ); ?></h3>

                <div class="cw-design-mockups__grid">
                    <?php foreach ( $block['mockups'] as $m ):
                        $buf_w = max( 1, (int) $block['cfg']['width'] );
                        $buf_h = max( 1, (int) $block['cfg']['height'] );
                        $max_w = 260; $max_h = 360;
                        $scale = min( $max_w / $buf_w, $max_h / $buf_h, 1 );
                        $disp_w = (int) round( $buf_w * $scale );
                        $disp_h = (int) round( $buf_h * $scale );
                    ?>
                    <div class="cw-design-mockup"
                         data-config="<?php echo esc_attr( wp_json_encode( $m['config'] ) ); ?>">
                        <?php if ( $is_multi ): ?>
                        <div class="cw-design-mockup__slot"><?php printf( esc_html__( 'Participant %d', 'creativewings-core' ), (int) $m['slot'] ); ?></div>
                        <?php endif; ?>

                        <div class="cw-design-mockup__canvas-wrap">
                            <canvas class="cw-design-mockup__canvas"
                                width="<?php echo $buf_w; ?>"
                                height="<?php echo $buf_h; ?>"
                                style="width:<?php echo $disp_w; ?>px;height:<?php echo $disp_h; ?>px;"></canvas>
                            <div class="cw-design-mockup__loading"><?php esc_html_e( 'Building preview…', 'creativewings-core' ); ?></div>
                        </div>

                        <div class="cw-design-mockup__meta">
                            <div class="cw-design-mockup__variant"><i class="fas fa-palette" aria-hidden="true"></i> <?php echo esc_html( $m['v_name'] ); ?></div>
                            <?php if ( $m['art_name'] ): ?>
                            <div class="cw-design-mockup__file" title="<?php echo esc_attr( $m['art_name'] ); ?>"><i class="fas fa-file-image" aria-hidden="true"></i> <?php echo esc_html( $m['art_name'] ); ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="cw-design-mockup__actions">
                            <button type="button" class="cw-design-mockup__download-btn">
                                <i class="fas fa-download" aria-hidden="true"></i>
                                <?php esc_html_e( 'Download mockup (PNG)', 'creativewings-core' ); ?>
                            </button>
                            <a href="<?php echo esc_url( $m['art_url'] ); ?>" class="cw-design-mockup__artwork-link" target="_blank" rel="noopener" download>
                                <i class="fas fa-file-export" aria-hidden="true"></i>
                                <?php esc_html_e( 'Download original artwork', 'creativewings-core' ); ?>
                            </a>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </section>
        <?php
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
    public function copy_to_entry( $entry_id, $item, $order, $participant_num = 0 ) {
        if ( ! $entry_id || ! $item ) {
            return;
        }

        // CW_Shop now passes the 1-based participant index as the 4th argument
        // so we can map artwork[i] → entry[i] reliably. For older callers that
        // still emit the 3-arg signature, fall back to a static (order, item)
        // counter that increments per call.
        $slot = (int) $participant_num;
        if ( $slot <= 0 ) {
            static $line_counter = [];
            $order_id  = $order ? (int) $order->get_id() : 0;
            $item_id   = method_exists( $item, 'get_id' ) ? (int) $item->get_id() : 0;
            $line_key  = $order_id . ':' . $item_id;
            $line_counter[ $line_key ] = isset( $line_counter[ $line_key ] ) ? $line_counter[ $line_key ] + 1 : 1;
            $slot      = $line_counter[ $line_key ];
        }

        $artworks  = self::decode_indexed_meta( (string) $item->get_meta( '_' . self::CART_ARTWORK_IDS ) );
        $sources   = self::decode_indexed_meta( (string) $item->get_meta( '_' . self::CART_SOURCE_IDS ) );
        $variants  = self::decode_indexed_meta( (string) $item->get_meta( '_' . self::CART_VARIANTS ) );

        // Slot → attachment id / variant slug. Fall back to legacy singular meta
        // (which is always slot 1) when the arrays are missing entirely.
        $art_id    = isset( $artworks[ $slot ] ) ? (int) $artworks[ $slot ] : 0;
        $source_id = isset( $sources[ $slot ] )  ? (int) $sources[ $slot ]  : 0;
        $variant   = isset( $variants[ $slot ] ) ? (string) $variants[ $slot ] : '';

        if ( ! $art_id ) {
            $art_id = (int) $item->get_meta( '_' . self::CART_ARTWORK_ID );
        }
        if ( ! $source_id ) {
            $source_id = (int) $item->get_meta( '_' . self::CART_SOURCE_ID );
        }
        if ( $variant === '' ) {
            $variant = (string) $item->get_meta( '_' . self::CART_VARIANT );
        }

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

    /**
     * Decode an order-item JSON meta back into a slot-keyed array of ints/strings.
     * Returns [] on any decode failure.
     */
    private static function decode_indexed_meta( $raw ) {
        if ( $raw === '' ) return [];
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) return [];
        $out = [];
        foreach ( $decoded as $slot => $val ) {
            $out[ (int) $slot ] = $val;
        }
        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // ASSET ENQUEUE
    // ─────────────────────────────────────────────────────────────────────

    public function enqueue_assets() {
        $is_design_product  = is_singular( 'product' ) && self::is_enabled( (int) get_the_ID() );
        $is_design_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() && ! empty( $this->design_cart_items() );

        // Order-received (thank-you) page + My-Account → Orders → View Order
        // — both run the standard `wc_get_template( 'order/order-details.php' )`
        // which we hook for the mockup download. Quick heuristic: any time
        // we're inside an order context, gate based on whether *any* item on
        // the order belongs to a design-enabled campaign.
        $is_design_order = false;
        if ( function_exists( 'is_order_received_page' ) && function_exists( 'is_view_order_page' )
             && ( is_order_received_page() || is_view_order_page() ) ) {
            $order_id = 0;
            if ( is_order_received_page() ) {
                $order_id = (int) ( get_query_var( 'order-received' ) ?: 0 );
            } elseif ( is_view_order_page() ) {
                $order_id = (int) ( get_query_var( 'view-order' ) ?: 0 );
            }
            if ( $order_id ) {
                $o = wc_get_order( $order_id );
                if ( $o ) {
                    foreach ( $o->get_items() as $item ) {
                        if ( is_a( $item, 'WC_Order_Item_Product' ) && self::is_enabled( (int) $item->get_product_id() ) ) {
                            $is_design_order = true;
                            break;
                        }
                    }
                }
            }
        }

        // Business dashboard → "Manage Entries" tab: judges need the
        // canvas compositing JS + CSS to render the artwork-on-variant
        // mockups inside entry cards, the evaluation modal, and the
        // shared zoom lightbox. We can't cheaply detect "this campaign
        // is design-enabled" from the URL alone, so we load the assets
        // whenever the judging UI may be visible — the JS is a few kb
        // and bails out cleanly when no mockup elements exist.
        $is_judge_view = false;
        if ( function_exists( 'is_account_page' ) && is_account_page()
             && isset( $_GET['tab'] ) && $_GET['tab'] === 'manage_entries' ) {
            $is_judge_view = true;
        }

        if ( ! $is_design_product && ! $is_design_checkout && ! $is_design_order && ! $is_judge_view ) {
            return;
        }

        // CSS — shared across product page + checkout.
        //
        // Version with filemtime() so each edit busts the browser cache
        // automatically. The static CW_VERSION constant doesn't change
        // between feature drops, so without this judges and customers
        // see stale styles (most visibly: the mockup-zoom lightbox
        // rendering inline instead of as a fixed overlay).
        $css_path = CW_PATH . 'assets/css/cw-style-design.css';
        $css_ver  = file_exists( $css_path ) ? (string) filemtime( $css_path ) : ( defined( 'CW_VERSION' ) ? CW_VERSION : null );
        wp_enqueue_style(
            'cw-style-design',
            CW_URL . 'assets/css/cw-style-design.css',
            [],
            $css_ver
        );

        // JS — handles client-side PNG dimension validation, AJAX upload,
        // and canvas mockup compositing. Vanilla, no jQuery dependency.
        $js_path = CW_PATH . 'assets/js/cw-design-preview.js';
        $js_ver  = file_exists( $js_path ) ? (string) filemtime( $js_path ) : ( defined( 'CW_VERSION' ) ? CW_VERSION : null );
        wp_enqueue_script(
            'cw-design-preview',
            CW_URL . 'assets/js/cw-design-preview.js',
            [],
            $js_ver,
            true
        );
        wp_localize_script( 'cw-design-preview', 'cwDesignVars', [
            'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
            'nonce'      => wp_create_nonce( self::NONCE_AJAX ),
            'action'     => self::AJAX_ACTION,
            'messages'   => [
                'wrongExtension'  => __( 'Please choose a PNG file.', 'creativewings-core' ),
                'wrongDimensions' => __( 'Artwork must be exactly %dpx × %dpx.', 'creativewings-core' ),
                'uploading'       => __( 'Uploading…', 'creativewings-core' ),
                'uploaded'        => __( 'Uploaded ✓', 'creativewings-core' ),
                'sourceUploaded'  => __( 'Source file uploaded ✓', 'creativewings-core' ),
                'genericError'    => __( 'Upload failed. Please try again.', 'creativewings-core' ),
                'prefilled'       => __( 'Using participant 1\'s artwork ✓', 'creativewings-core' ),
                'prefillMissing'  => __( 'Participant 1 hasn\'t uploaded an artwork yet.', 'creativewings-core' ),
            ],
        ] );
    }
}
