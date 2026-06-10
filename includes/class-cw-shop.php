<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Shop {

    public function __construct() {
        if ( ! class_exists( 'WooCommerce' ) ) return;

        // Frontend Render
        add_action('woocommerce_before_add_to_cart_button', [ $this, 'render_dynamic_fields' ]);
        
        // Validation & Cart
        add_filter( 'woocommerce_add_to_cart_validation', [ $this, 'validate_dynamic_data' ], 10, 6 );
        add_filter('woocommerce_add_cart_item_data', [ $this, 'save_custom_data_to_cart' ], 10, 2);
        
        // Price Calculation
        add_action('woocommerce_before_calculate_totals', [ $this, 'calculate_cart_totals' ], 20, 1);
        
        // Display
        add_filter('woocommerce_get_item_data', [ $this, 'display_custom_data_in_cart' ], 10, 2);
        add_action('woocommerce_checkout_create_order_line_item', [ $this, 'save_custom_data_to_order' ], 10, 4);
        add_filter('woocommerce_get_cart_item_from_session', [ $this, 'restore_claim_cart_session' ], 10, 2 );
        
        // Processing — create entries when payment succeeds (incl. free / coupon checkouts).
        add_action( 'woocommerce_payment_complete', [ $this, 'create_entries_from_order' ], 10, 1 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'create_entries_from_order' ], 10, 1 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'create_entries_from_order' ], 10, 1 );
        
        // UI
        add_filter('woocommerce_add_to_cart_redirect', [ $this, 'redirect_to_checkout' ]);
        add_action('wp', [ $this, 'remove_loop_add_to_cart' ]);

        // Hide /shop/ archive — campaigns live on /activities/ and /competitions/ instead.
        add_action( 'template_redirect', [ $this, 'redirect_shop_archive' ], 1 );
        add_filter( 'woocommerce_return_to_shop_redirect', [ $this, 'filter_return_to_shop_url' ] );
        
        // Deadline Check
        add_filter( 'woocommerce_is_purchasable', [ $this, 'check_deadline_status' ], 99, 2 );
        add_filter( 'woocommerce_cart_item_is_purchasable', [ $this, 'cart_item_school_claim_purchasable' ], 99, 3 );
        add_filter( 'woocommerce_add_cart_item', [ $this, 'preserve_claim_cart_item' ], 10, 2 );

        add_action( 'woocommerce_cart_emptied', [ $this, 'clear_school_claim_checkout_session' ] );
        add_action( 'woocommerce_thankyou', [ $this, 'clear_school_claim_checkout_session' ] );
    }

    /**
     * Remember link-submission checkout in WC session (survives cart validation even if line meta is missing).
     */
    public static function set_school_claim_checkout_session( $product_id, $staged_id = 0 ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        WC()->session->set( 'cw_school_claim_product_id', (int) $product_id );
        WC()->session->set( 'cw_school_claim_staged_id', (int) $staged_id );
    }

    public function clear_school_claim_checkout_session() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        WC()->session->__unset( 'cw_school_claim_product_id' );
        WC()->session->__unset( 'cw_school_claim_staged_id' );
    }

    /**
     * @param array<string, mixed> $cart_item
     * @return array<string, mixed>
     */
    public function preserve_claim_cart_item( $cart_item, $cart_item_key ) {
        foreach ( [ 'cw_staged_id', 'cw_claim_code', 'cw_age_bracket_key', 'cw_age_bracket_label' ] as $key ) {
            if ( isset( $cart_item[ $key ] ) ) {
                continue;
            }
            $session_cart = WC()->session ? WC()->session->get( 'cart' ) : null;
            if ( is_array( $session_cart ) && isset( $session_cart[ $cart_item_key ][ $key ] ) ) {
                $cart_item[ $key ] = $session_cart[ $cart_item_key ][ $key ];
            }
        }
        return $cart_item;
    }

    /**
     * Keep school-link lines in the cart when public registration has not opened yet.
     *
     * @param bool       $purchasable
     * @param array      $cart_item
     * @param string|int $cart_item_key
     */
    public function cart_item_school_claim_purchasable( $purchasable, $cart_item, $cart_item_key ) {
        if ( $purchasable ) {
            return $purchasable;
        }
        if ( empty( $cart_item['cw_staged_id'] ) && empty( $cart_item['cw_claim_code'] ) ) {
            return $purchasable;
        }

        $product_id = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
        if ( ! $product_id && isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
            $product_id = (int) $cart_item['data']->get_id();
        }
        if ( ! $product_id ) {
            return $purchasable;
        }

        if ( self::get_registration_block_reason( $product_id, true ) ) {
            return false;
        }

        return true;
    }

    public function check_deadline_status( $is_purchasable, $product ) {
        if ( ! $product ) {
            return $is_purchasable;
        }

        $product_id   = (int) $product->get_id();
        $school_claim = self::is_school_claim_checkout( $product_id );

        if ( $school_claim ) {
            return self::get_registration_block_reason( $product_id, true ) ? false : true;
        }

        $reason = self::get_registration_block_reason( $product_id, false );
        if ( $reason ) {
            return false;
        }

        return $is_purchasable;
    }

    /**
     * Parent link-submission checkout (school PIC upload → confirm → pay).
     * Must stay purchasable on cart/checkout, not only during admin-post add_to_cart.
     *
     * @param int $product_id Optional product ID to match a specific cart line.
     */
    public static function is_school_claim_checkout( $product_id = 0 ) {
        if ( ! empty( $GLOBALS['cw_claim_checkout_flow'] ) ) {
            return true;
        }

        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return false;
        }

        $product_id = (int) $product_id;
        $session_pid = (int) WC()->session->get( 'cw_school_claim_product_id' );
        if ( $session_pid && ( ! $product_id || $session_pid === $product_id ) ) {
            return true;
        }

        if ( ! WC()->cart ) {
            return false;
        }

        $session_cart = WC()->session->get( 'cart' );
        if ( is_array( $session_cart ) ) {
            foreach ( $session_cart as $values ) {
                if ( empty( $values['cw_staged_id'] ) && empty( $values['cw_claim_code'] ) ) {
                    continue;
                }
                if ( ! $product_id ) {
                    return true;
                }
                $line_product_id = isset( $values['product_id'] ) ? (int) $values['product_id'] : 0;
                if ( $line_product_id === $product_id ) {
                    return true;
                }
            }
        }

        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) && empty( $item['cw_claim_code'] ) ) {
                continue;
            }
            if ( ! $product_id ) {
                return true;
            }
            $line_product_id = isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
            if ( ! $line_product_id && isset( $item['data'] ) && is_object( $item['data'] ) ) {
                $line_product_id = (int) $item['data']->get_id();
            }
            if ( $line_product_id === $product_id ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Why a campaign cannot be checked out (null = no plugin block; WC may still reject).
     *
     * @param int  $product_id Campaign product ID.
     * @param bool $school_claim Parent completing after school PIC upload (skips public open date).
     * @return string|null
     */
    public static function get_registration_block_reason( $product_id, $school_claim = false ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return __( 'Campaign not found.', 'creativewings-core' );
        }

        $status = get_post_status( $product_id );
        if ( ! $status || 'publish' !== $status ) {
            return __( 'This campaign is not published yet. Ask the organiser to publish it in WooCommerce.', 'creativewings-core' );
        }

        $deadline = get_post_meta( $product_id, 'submission_deadline', true );
        if ( $deadline && time() > strtotime( $deadline . ' 23:59:59' ) ) {
            return sprintf(
                /* translators: %s: formatted date */
                __( 'Registration is closed — the submission deadline was %s.', 'creativewings-core' ),
                date_i18n( 'j M Y', strtotime( $deadline ) )
            );
        }

        if ( ! $school_claim ) {
            $start = get_post_meta( $product_id, 'cw_submission_start', true );
            if ( $start && time() < strtotime( $start . ' 00:00:00' ) ) {
                return sprintf(
                    /* translators: %s: formatted date */
                    __( 'Registration opens on %s.', 'creativewings-core' ),
                    date_i18n( 'j M Y', strtotime( $start ) )
                );
            }
        }

        return null;
    }

    public function calculate_cart_totals( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) return;
        foreach ( $cart->get_cart() as $cart_item ) {
            $product = $cart_item['data'];
            $base_price = floatval( $product->get_price() );
            if ( isset( $cart_item['cw_addons_total'] ) ) {
                $base_price += floatval( $cart_item['cw_addons_total'] );
            }
            $product->set_price( $base_price );
        }
    }

    public function render_dynamic_fields() {
        global $product;
        if ( ! $product ) return;

        $post_id = $product->get_id();
        
        // Deadline Visual Check
        $deadline = get_post_meta( $post_id, 'submission_deadline', true );
        if ( $deadline && time() > strtotime( $deadline . ' 23:59:59' ) ) {
            echo '<div class="cw-alert error" style="text-align:center;">Submissions Closed</div>';
            echo '<style>.single_add_to_cart_button, .quantity, .cw-shop-container { display: none !important; }</style>';
            return;
        }

        $fields  = get_post_meta( $post_id, 'cw_custom_fields', true ); 
        
        // --- LOGIC: CONTEXT DETECTION ---
        $allow_multi_p = false;
        $is_activity = has_term( 'activities', 'product_cat', $post_id );
        if(!$is_activity) {
            $terms = get_the_terms($post_id, 'product_cat');
            if($terms && !is_wp_error($terms)) {
                foreach($terms as $t) {
                    if($t->parent) { $p = get_term($t->parent, 'product_cat'); if($p && $p->slug==='activities') { $is_activity=true; break; } }
                }
            }
        }

        if ( $is_activity ) {
            $allow_multi_p = get_post_meta( $post_id, 'cw_allow_multiple_participants', true ) === 'yes';
            $is_multi      = false;
            $min_p         = $allow_multi_p ? ( (int) get_post_meta( $post_id, 'cw_min_participants', true ) ?: 1 ) : 1;
            $max_p         = $allow_multi_p ? ( (int) get_post_meta( $post_id, 'cw_max_participants', true ) ?: 10 ) : 1;
            $label_text    = 'Participant';
            $btn_text      = '+ Add Participant';
            $calc_mode     = 'team';
        } else {
            // Competition (Artwork) - NO NAMES (Use Billing Name)
            $is_multi = get_post_meta( $post_id, 'multiple_submissions', true ) === 'true';
            $min_p = (int) get_post_meta( $post_id, 'cw_multi_min', true ) ?: 1;
            $max_p = $is_multi ? ((int) get_post_meta( $post_id, 'cw_multi_max', true ) ?: 50) : 1;
            $label_text = 'Artwork Entry';
            $btn_text = '+ Add Artwork';
            $calc_mode = 'entry'; // Qty scales
        }

        $addons = [];
        if ( get_post_meta( $post_id, 'cw_enable_addons', true ) === 'yes' ) {
            $addons = get_post_meta( $post_id, 'addon_products', true );
            if ( isset( $addons[0] ) ) {
                $addons = $addons[0];
            }
        }

        $use_account_fn = get_post_meta( $post_id, 'cw_use_account_fullname', true );
        if ( $use_account_fn === '' ) {
            $use_account_fn = 'yes';
        }
        $account_full_name = '';
        if ( is_user_logged_in() ) {
            $uid = get_current_user_id();
            $account_full_name = get_user_meta( $uid, 'cw_full_name', true );
            if ( ! $account_full_name ) {
                $account_full_name = wp_get_current_user()->display_name;
            }
        }

        $js_config = [
            'fields'              => $fields,
            'min'                 => $min_p,
            'max'                 => $max_p,
            'is_multi'            => $is_multi,
            'label'               => $label_text,
            'btn_text'            => $btn_text,
            'calc_mode'           => $calc_mode,
            'is_activity'         => $is_activity,
            'allow_multiple'      => ! empty( $allow_multi_p ),
            'use_account_fullname'=> ( $use_account_fn === 'yes' ),
            'account_full_name'   => $account_full_name,
            'addons'              => $addons,
            'post_id'             => $post_id,
        ];

        echo '<script>var cwConfig = ' . json_encode($js_config) . ';</script>';
        echo '<div class="cw-shop-container">';
        echo '<div id="cw-dynamic-scroll" class="cw-scroll-box"><div id="cw-rows-wrapper"></div></div>';
        echo '<div class="cw-shop-controls"><div style="display:flex; align-items:center; gap:10px;"><button type="button" class="cw-btn-pink" id="cw-add-row">' . esc_html($btn_text) . '</button><span id="cw-limit-msg"></span></div></div>';
        
        // Addons Render
        if($addons && is_array($addons)) {
            echo '<div class="cw-addons-compact"><h4 style="color:#105B9A;">Optional Add-ons</h4><div id="cw-addons-wrapper"></div><div class="cw-addon-buttons">';
            foreach($addons as $k => $addon) {
                if(empty($addon['addon_title'])) continue;
                echo '<button type="button" class="cw-btn-white small" onclick="addAddonRow(\''.$k.'\')">+ ' . esc_html($addon['addon_title']) . ' (' . wc_price($addon['addon_price']) . ')</button>';
            }
            echo '</div></div>';
        }
        echo '</div>';

        // --- JS LOGIC ---
        ?>
        <style>.quantity, .qty { display: none !important; }</style>
        <script>
        window.addAddonRow = function(key) {
            const addon = cwConfig.addons[key];
            if(!addon) return;
            const id = Date.now() + Math.floor(Math.random() * 1000);
            const productId = cwConfig.post_id;
            const t = (addon.addon_type || 'checkbox').toLowerCase();
            const lab = (addon.addon_label || addon.addon_title || '').replace(/"/g, '&quot;');
            const optsStr = addon.addon_opts || '';
            let inputHtml = `<input type="hidden" name="cw_addon_rows[${key}][${id}][val]" value="1">`;
            if (t === 'checkbox') {
                inputHtml = `<input type="hidden" name="cw_addon_rows[${key}][${id}][val]" value="1">`;
            } else if (t === 'select') {
                const opts = optsStr.split(',').map(o => o.trim()).filter(Boolean);
                inputHtml = `<select name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input">`;
                opts.forEach(opt => { inputHtml += `<option value="${opt}">${opt}</option>`; });
                inputHtml += `</select>`;
            } else if (t === 'textarea') {
                inputHtml = `<textarea name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input" rows="2" placeholder="${lab}"></textarea>`;
            } else if (t === 'number') {
                inputHtml = `<input type="number" name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input" placeholder="${lab}">`;
            } else if (t === 'email') {
                inputHtml = `<input type="email" name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input" placeholder="${lab}">`;
            } else if (t === 'phone') {
                inputHtml = `<input type="tel" name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input" placeholder="${lab}">`;
            } else if (t === 'file' || t === 'media') {
                const sk = `cw_temp_file_${productId}_addon_${key}_${id}`;
                const accept = t === 'media' ? 'image/*' : '.pdf,.doc,.docx,.zip';
                inputHtml = `<div class="cw-addon-file-wrap"><input type="file" class="cw-file-upload-input cw-addon-file" data-session-key="${sk}" accept="${accept}" style="width:100%">
                    <input type="hidden" name="cw_addon_rows[${key}][${id}][val]" value="" class="cw-file-url-input"></div>`;
            } else {
                inputHtml = `<input type="text" name="cw_addon_rows[${key}][${id}][val]" class="cw-addon-input" placeholder="${lab}">`;
            }
            const html = `<div class="cw-addon-row-item"><div class="cw-addon-label">${addon.addon_title} <small>(+${addon.addon_price})</small></div><div>${inputHtml}</div><div><input type="number" name="cw_addon_rows[${key}][${id}][qty]" class="cw-addon-input cw-addon-qty" value="1" min="1"></div><div class="cw-del-addon" onclick="this.parentElement.remove()">X</div></div>`;
            jQuery('#cw-addons-wrapper').append(html);
        };

        jQuery(document).ready(function($){
            const container = $('#cw-rows-wrapper');
            const btn = $('#cw-add-row');
            const msg = $('#cw-limit-msg');
            const min = parseInt(cwConfig.min) || 1;
            const max = parseInt(cwConfig.max) || 1;
            const labelText = cwConfig.label;
            const isActivity = cwConfig.is_activity; // Check flag

            container.empty();
            let startCount = 1;
            if(min > 1 && cwConfig.calc_mode === 'team') startCount = 1;
            for(let i=0; i<startCount; i++) renderRowHTML(i + 1);
            updateIndices(); 

            function renderRowHTML(rowNum) {
                let nameField = '';
                const allowMulti = cwConfig.allow_multiple || cwConfig.max > 1;
                let rowTitle = labelText;
                if (allowMulti) {
                    rowTitle = labelText + ' ' + rowNum;
                }

                if (isActivity) {
                    let nameVal = '';
                    let hint = '';
                    if (rowNum === 1 && cwConfig.use_account_fullname && cwConfig.account_full_name) {
                        nameVal = cwConfig.account_full_name.replace(/"/g, '&quot;');
                        hint = '<p style="font-size:12px;color:#555555;margin:4px 0 8px;">Prefilled from your account — edit if needed.</p>';
                    } else if (rowNum > 1 && cwConfig.use_account_fullname) {
                        hint = '<p style="font-size:12px;color:#555555;margin:4px 0 8px;">Enter full name for this participant (certificate).</p>';
                    }
                    nameField = `<div class="cw-field-row"><label>Full Name <span style="color:red">*</span></label>${hint}<input type="text" class="cw-frontend-input cw-input-name" value="${nameVal}" required style="width:100%"></div>`;
                } else {
                    nameField = `<input type="hidden" class="cw-input-name" value="Self">`;
                }

                let html = `<div class="cw-entry-row" data-row-num="${rowNum}">
                    <span class="cw-remove-row">×</span>
                    <h4 class="cw-row-title">${rowTitle}</h4>
                    ${nameField}`;

                if(cwConfig.fields) {
                    cwConfig.fields.forEach((f, idx) => {
                        let input = '';
                        const ftype = (f.type || 'text').toLowerCase();
                        const req = f.required == 1 ? 'required' : '';
                        const reqMark = f.required == 1 ? ' <span style="color:red">*</span>' : '';

                        if (ftype === 'file') {
                            const fileId = `cw_file_${rowNum}_${idx}`;
                            input = `<div class="cw-file-wrapper">
                                <input type="file" id="${fileId}" class="cw-dyn-field cw-file-upload-input" data-idx="${idx}" data-row-num="${rowNum}" accept=".pdf,.doc,.docx,.zip" style="width:100%" ${req}>
                                <input type="hidden" name="cw_data[${rowNum}][${idx}]" value="" class="cw-file-url-input">
                            </div>`;
                        } else if (ftype === 'media') {
                            const mediaId = `cw_media_${rowNum}_${idx}`;
                            input = `<div class="cw-file-wrapper">
                                <input type="file" id="${mediaId}" class="cw-dyn-field cw-file-upload-input" data-idx="${idx}" data-row-num="${rowNum}" accept="image/*,video/*" style="width:100%" ${req}
                                    onchange="(function(el){var r=new FileReader();r.onload=function(e){var p=el.parentNode.querySelector('.cw-media-preview');if(p){p.src=e.target.result;p.style.display='block';}};r.readAsDataURL(el.files[0]);})(this)">
                                <input type="hidden" name="cw_data[${rowNum}][${idx}]" value="" class="cw-file-url-input">
                                <img class="cw-media-preview" src="" alt="" style="width:80px;height:80px;object-fit:cover;border-radius:8px;margin-top:6px;border:1.5px solid #e2e8f0;display:none;">
                            </div>`;
                        } else if (ftype === 'textarea') {
                            input = `<textarea class="cw-frontend-input cw-dyn-field" data-idx="${idx}" placeholder="${f.label}" style="width:100%;min-height:80px;resize:vertical;padding:10px;border:1.5px solid #e2e8f0;border-radius:8px;font-family:inherit;font-size:14px;" ${req}></textarea>`;
                        } else {
                            // text, number, email, phone, select all handled via type attribute
                            const inputType = ftype === 'phone' ? 'tel' : ftype;
                            input = `<input type="${inputType}" class="cw-frontend-input cw-dyn-field" data-idx="${idx}" placeholder="${f.label}" style="width:100%" ${req}>`;
                        }
                        html += `<div class="cw-field-row"><label>${f.label}${reqMark}</label>${input}</div>`;
                    });
                }
                html += `</div>`;
                container.append(html);
            }

            btn.off('click').on('click', function() {
                if($('.cw-entry-row').length >= max) return;
                renderRowHTML($('.cw-entry-row').length + 1);
                updateIndices();
            });

            $(document).on('click', '.cw-remove-row', function(){
                if($('.cw-entry-row').length <= min) return;
                $(this).parent().remove();
                updateIndices();
            });

            function updateIndices() {
                let rows = $('.cw-entry-row');
                rows.each(function(index) {
                    let num = index + 1;
                    let row = $(this);
                    row.data('row-num', num);
                    row.find('.cw-row-title').text(`${labelText} ${num}`);
                    row.find('.cw-input-name').attr('name', `cw_names[${num}]`);
                    
                    row.find('.cw-dyn-field:not(.cw-file-upload-input)').each(function() {
                        let fieldIdx = $(this).data('idx');
                        $(this).attr('name', `cw_data[${num}][${fieldIdx}]`);
                    });
                    
                    row.find('.cw-file-upload-input').each(function() {
                         let fieldIdx = $(this).data('idx');
                         const fileId = `cw_file_${num}_${fieldIdx}`;
                         $(this).siblings('.cw-file-url-input').attr('name', `cw_data[${num}][${fieldIdx}]`);
                         $(this).data('row-num', num);
                    });
                });
                
                let wcQty = (cwConfig.calc_mode === 'team') ? 1 : rows.length;
                $('form.cart').find('input[name="quantity"]').val(wcQty).trigger('change');
                
                if(rows.length >= max) btn.prop('disabled', true).text('Max Reached');
                else btn.prop('disabled', false).text(cwConfig.btn_text);
            }

            // AJAX UPLOAD (participant fields + optional add-ons)
            $(document).on('change', '.cw-file-upload-input', function(e) {
                const fileInput = $(this);
                const file = fileInput[0].files[0];
                if (!file) return;
                const rowNum = fileInput.data('row-num'); 
                const fieldIndex = fileInput.data('idx');
                const productId = cwConfig.post_id;
                const customSk = fileInput.attr('data-session-key');
                const sessionKey = customSk || `cw_temp_file_${productId}_${rowNum}_${fieldIndex}`; 
                const formData = new FormData();
                formData.append('action', 'cw_file_upload');
                formData.append('security', cw_vars.nonce);
                formData.append('file_data', file);
                formData.append('session_key', sessionKey);

                $.ajax({
                    url: cw_vars.ajax_url, type: 'POST', data: formData, processData: false, contentType: false,
                    beforeSend: () => { fileInput.hide().after('<i class="fas fa-spinner fa-spin cw-upload-status"></i>'); },
                    success: (res) => {
                        $('.cw-upload-status').remove();
                        if (res.success) {
                            fileInput.siblings('.cw-file-url-input').val(res.data.url);
                            fileInput.after(`<span class="cw-file-uploaded"><a href="${res.data.url}" target="_blank">View File</a> <i class="fas fa-check" style="color:green;"></i></span>`);
                        } else {
                            fileInput.show(); alert('Upload Error');
                        }
                    }
                });
            });
        });
        </script>
        <?php
    }

    // 3. VALIDATION
    public function validate_dynamic_data( $passed, $product_id, $qty, $variation_id = 0, $variations = [], $cart_item_data = [] ) {
        // School PIC + parent claim flow adds via admin-post with staged meta, not product form POST.
        if ( ! empty( $cart_item_data['cw_staged_id'] ) || ! empty( $cart_item_data['cw_claim_code'] ) ) {
            return $passed;
        }
        if ( ! empty( $GLOBALS['cw_claim_checkout_flow'] ) ) {
            return $passed;
        }

        $block = self::get_registration_block_reason( (int) $product_id, false );
        if ( $block ) {
            wc_add_notice( $block, 'error' );
            return false;
        }

        // Anti-spam: one entry per user (= per email). Default ON for any campaign that
        // hasn't explicitly opted out via the admin toggle.
        $uid = get_current_user_id();
        if ( $uid && self::campaign_limits_to_one_entry( (int) $product_id ) && self::user_already_has_entry( $uid, (int) $product_id ) ) {
            wc_add_notice(
                __( 'You have already registered for this campaign with this account. Only one entry per user is allowed.', 'creativewings-core' ),
                'error'
            );
            return false;
        }

        $names = $_POST['cw_names'] ?? [];
        if ( empty( $names ) ) {
            wc_add_notice( 'Details are required.', 'error' );
            return false;
        }
        return $passed;
    }

    /**
     * Whether a campaign is configured to allow only one entry per user (= per email).
     * Default ON for any campaign whose meta has never been touched.
     *
     * @param int $product_id
     * @return bool
     */
    public static function campaign_limits_to_one_entry( $product_id ) {
        $raw = get_post_meta( (int) $product_id, 'cw_one_entry_per_user', true );
        return ( $raw === '' || $raw === 'yes' );
    }

    /**
     * Whether a given user already has a submission for a campaign product.
     *
     * @param int $user_id
     * @param int $product_id
     * @return bool
     */
    public static function user_already_has_entry( $user_id, $product_id ) {
        $existing = get_posts( [
            'post_type'      => self::entry_post_types(),
            'meta_query'     => [
                [ 'key' => 'customer_id', 'value' => (int) $user_id ],
                [ 'key' => 'product_id',  'value' => (int) $product_id ],
            ],
            'posts_per_page' => 1,
            'fields'         => 'ids',
            'no_found_rows'  => true,
        ] );
        return ! empty( $existing );
    }
    
    /**
     * Handle direct multipart uploads for cw_data[i][x] (e.g. event-detail registration modal — no AJAX).
     */
    private function get_uploaded_cw_data_url( $product_id, $row, $field_idx ) {
        if ( empty( $_FILES['cw_data']['tmp_name'][ $row ][ $field_idx ] ) || ! is_uploaded_file( $_FILES['cw_data']['tmp_name'][ $row ][ $field_idx ] ) ) {
            return '';
        }
        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = [
            'name'     => $_FILES['cw_data']['name'][ $row ][ $field_idx ],
            'type'     => $_FILES['cw_data']['type'][ $row ][ $field_idx ],
            'tmp_name' => $_FILES['cw_data']['tmp_name'][ $row ][ $field_idx ],
            'error'    => $_FILES['cw_data']['error'][ $row ][ $field_idx ],
            'size'     => $_FILES['cw_data']['size'][ $row ][ $field_idx ],
        ];
        if ( ! empty( $file['error'] ) ) {
            return '';
        }
        $move = wp_handle_upload( $file, [ 'test_form' => false ] );
        if ( isset( $move['error'] ) || empty( $move['url'] ) ) {
            return '';
        }
        // Best-effort: optimize raster uploads in place (no attachment id yet).
        if ( ! empty( $move['file'] ) && class_exists( 'CW_Image_Optimizer' ) ) {
            $ext = strtolower( pathinfo( $move['file'], PATHINFO_EXTENSION ) );
            if ( in_array( $ext, [ 'jpg', 'jpeg', 'png', 'webp' ], true ) ) {
                CW_Image_Optimizer::optimize_path( $move['file'], 'attachment' );
            }
        }
        return esc_url_raw( $move['url'] );
    }

    // 4. SAVE CART (Retain Session Logic)
    public function save_custom_data_to_cart($d,$id){ 
        if ( ! empty( $d['cw_staged_id'] ) && ! empty( $d['cw_claim_code'] ) ) {
            return $this->merge_claim_staged_cart_data( $d, (int) $d['cw_staged_id'], (int) $id );
        }

        $f = get_post_meta( $id, 'cw_custom_fields', true );
        $f = is_array( $f ) ? array_values( $f ) : [];
        $post = isset( $_POST['cw_data'] ) && is_array( $_POST['cw_data'] ) ? wp_unslash( $_POST['cw_data'] ) : [];
        $names = isset( $_POST['cw_names'] ) && is_array( $_POST['cw_names'] ) ? wp_unslash( $_POST['cw_names'] ) : [];
        $d['cw_participants']=[]; $d['cw_addons_meta']=[]; $d['cw_addons_total'] = 0;

        foreach($names as $i=>$n){
            // Competition modal sends hidden name "Self" as placeholder — do not store or show in cart (billing name is used at checkout).
            $pd = [];
            $name_clean = sanitize_text_field( $n );
            if ( strcasecmp( trim( $name_clean ), 'Self' ) !== 0 ) {
                $pd[] = ['label' => 'Name', 'value' => $name_clean];
            }
            if($f){
                foreach($f as $x=>$fi){
                    $v='';
                    $ftype = isset( $fi['type'] ) ? $fi['type'] : 'text';
                    // File / media: session (product page AJAX) or direct upload (modal), then POST fallback
                    if ( $ftype === 'file' || $ftype === 'media' ) {
                        $session_key = 'cw_temp_file_'.$id.'_'.$i.'_'.$x;
                        $aid = WC()->session ? WC()->session->get( $session_key ) : null;
                        if ( $aid ) {
                            $v = wp_get_attachment_url( $aid );
                            if ( WC()->session ) {
                                WC()->session->__unset( $session_key );
                            }
                        }
                        if ( empty( $v ) ) {
                            $v = $this->get_uploaded_cw_data_url( $id, $i, $x );
                        }
                        if ( empty( $v ) && isset( $post[ $i ][ $x ] ) ) {
                            $v = sanitize_text_field( $post[ $i ][ $x ] );
                        }
                    } else {
                        $v = isset( $post[ $i ][ $x ] ) ? sanitize_text_field( $post[ $i ][ $x ] ) : '';
                    }
                    if($v)$pd[]=['label'=>$fi['label'],'value'=>$v];
                }
            }
            $d['cw_participants'][$i]=$pd;
        }
        
        // Addons Logic (Keep existing)
        $addon_rows = $_POST['cw_addon_rows'] ?? [];
        $all_addons = get_post_meta($id, 'addon_products', true);
        if(isset($all_addons[0])) $all_addons = $all_addons[0];
        foreach($addon_rows as $key => $rows) {
            if(isset($all_addons[$key])) {
                $atype = isset($all_addons[$key]['addon_type']) ? strtolower(trim((string)$all_addons[$key]['addon_type'])) : 'checkbox';
                foreach($rows as $unique_id => $data) {
                    $qty = intval($data['qty'] ?? 0);
                    if($qty < 1) continue;
                    $unit = floatval($all_addons[$key]['addon_price']);
                    $val  = isset($data['val']) ? sanitize_text_field(wp_unslash((string)$data['val'])) : '';
                    if ( ($atype === 'file' || $atype === 'media') && $val === '' && WC()->session ) {
                        $sk = 'cw_temp_file_' . $id . '_addon_' . $key . '_' . $unique_id;
                        $aid = WC()->session->get($sk);
                        if ($aid) {
                            $val = wp_get_attachment_url($aid) ?: '';
                            WC()->session->__unset($sk);
                        }
                    }
                    $title = $all_addons[$key]['addon_title'];
                    if ($val !== '') {
                        $title .= ': ' . $val;
                    }
                    $title .= ' × ' . $qty;
                    $d['cw_addons_meta'][] = ['title' => $title, 'price' => $unit, 'total_cost' => $unit * $qty];
                    $d['cw_addons_total'] += ($unit * $qty);
                }
            }
        }
        return $d;
    }

    /**
     * Cart line for parent claim after school upload (no product-page POST fields).
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private function merge_claim_staged_cart_data( array $d, $staged_id, $product_id ) {
        global $wpdb;
        if ( ! class_exists( 'CW_Staged_Submissions' ) ) {
            return $d;
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d AND campaign_id = %d',
            $staged_id,
            $product_id
        ), ARRAY_A );
        if ( ! $row ) {
            return $d;
        }

        $participant = class_exists( 'CW_Campaign_Fields' )
            ? CW_Campaign_Fields::build_participant_details_from_staged( $row )
            : [
                [ 'label' => 'Name', 'value' => $row['student_name'] ?? '' ],
                [ 'label' => 'Submission code', 'value' => $row['submission_code'] ?? '' ],
            ];

        $d['cw_participants']  = [ $participant ];
        $d['cw_addons_meta']   = $d['cw_addons_meta'] ?? [];
        $d['cw_addons_total']  = $d['cw_addons_total'] ?? 0;

        return $d;
    }

    public function display_custom_data_in_cart($d,$i){
        $is_claim = ! empty( $i['cw_staged_id'] ) || ! empty( $i['cw_claim_code'] );
        if(isset($i['cw_participants'])) foreach($i['cw_participants'] as $n=>$fs) foreach($fs as $f) {
            if ( isset( $f['label'], $f['value'] ) && $f['label'] === 'Name' && strcasecmp( trim( (string) $f['value'] ), 'Self' ) === 0 ) {
                continue;
            }
            $label_lc = strtolower( trim( (string) ( $f['label'] ?? '' ) ) );
            if ( $is_claim && in_array( $label_lc, [ 'name', 'submission code' ], true ) ) {
                continue;
            }
            $label = ( $is_claim && count( $i['cw_participants'] ) <= 1 )
                ? $f['label']
                : "Entry $n: " . $f['label'];
            $d[]=['key'=>$label,'value'=>$f['value']];
        }
        if(isset($i['cw_addons_meta'])) foreach($i['cw_addons_meta'] as $a) $d[]=['key'=>'Add-on','value'=>$a['title'].' (+'.wc_price($a['total_cost']).')'];
        return $d;
    }
    
    public function restore_claim_cart_session( $cart_item, $values ) {
        foreach ( [ 'cw_staged_id', 'cw_claim_code', 'cw_age_bracket_key', 'cw_age_bracket_label' ] as $key ) {
            if ( isset( $values[ $key ] ) ) {
                $cart_item[ $key ] = $values[ $key ];
            }
        }
        return $cart_item;
    }

    public function save_custom_data_to_order($it,$k,$v,$o){
        if(isset($v['cw_participants'])) { $it->add_meta_data('_cw_participant_data',json_encode($v['cw_participants'])); }
        if(isset($v['cw_addons_meta'])) { $it->add_meta_data('_cw_addons_data',json_encode($v['cw_addons_meta'])); }
        if ( ! empty( $v['cw_staged_id'] ) ) {
            $it->add_meta_data( '_cw_staged_id', (int) $v['cw_staged_id'] );
            $it->add_meta_data( '_cw_claim_code', sanitize_text_field( $v['cw_claim_code'] ?? '' ) );
            $it->add_meta_data( '_cw_age_bracket_key', sanitize_text_field( $v['cw_age_bracket_key'] ?? '' ) );
        }
    }

    /**
     * Entry post types used across dashboards (both competition + activity campaigns).
     *
     * @return string[]
     */
    public static function entry_post_types() {
        return [ 'cw_competition_entry', 'cw_activity_entry' ];
    }

    /**
     * Whether a campaign is judged (i.e. accepts scores, winners, judge comments, voting).
     *
     * Talk/seminar and activity-rooted campaigns are non-judged; only competitions are.
     * Used at every render and write site so behavior is consistent.
     */
    public static function campaign_is_judged( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return true;
        }

        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( ! $terms || is_wp_error( $terms ) ) {
            return true;
        }

        foreach ( $terms as $t ) {
            $root_slug = strtolower( (string) $t->slug );
            if ( $t->parent ) {
                $parent = get_term( $t->parent, 'product_cat' );
                if ( $parent && ! is_wp_error( $parent ) ) {
                    $root_slug = strtolower( (string) $parent->slug );
                }
            }

            if ( in_array( $root_slug, [ 'activities', 'talk-seminar' ], true ) ) {
                return false;
            }
            if ( false !== strpos( $root_slug, 'seminar' ) || false !== strpos( $root_slug, 'talk' ) ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Resolve which entry CPT a campaign product should use.
     */
    public static function get_entry_post_type_for_product( $product_id ) {
        $product_id = (int) $product_id;
        if ( ! $product_id ) {
            return 'cw_competition_entry';
        }

        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( $term->slug === 'activities' ) {
                    return 'cw_activity_entry';
                }
                if ( $term->parent ) {
                    $parent = get_term( $term->parent, 'product_cat' );
                    if ( $parent && ! is_wp_error( $parent ) && $parent->slug === 'activities' ) {
                        return 'cw_activity_entry';
                    }
                }
            }
        }

        return 'cw_competition_entry';
    }

    // 6. CREATE ENTRIES (Logic for Certificates vs Artworks)
    public function create_entries_from_order( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) return;

        $existing_entries = get_posts( [
            'post_type'   => self::entry_post_types(),
            'meta_key'    => 'order_id',
            'meta_value'  => $order_id,
            'numberposts' => 1,
            'fields'      => 'ids',
        ] );
        if ( ! empty( $existing_entries ) ) return; 

        if ( ! add_post_meta( $order_id, '_cw_entry_lock', 'processing', true ) ) return;

        $user_id = $order->get_user_id();
        $billing_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();

        foreach ( $order->get_items() as $item ) {
            $product_id = $item->get_product_id();
            
            $post_type   = self::get_entry_post_type_for_product( $product_id );
            $is_activity = ( 'cw_activity_entry' === $post_type );

            $staged_id = (int) $item->get_meta( '_cw_staged_id' );
            if ( $staged_id && class_exists( 'CW_Staged_Submissions' ) ) {
                $this->create_entry_from_staged( $staged_id, $order_id, $user_id, $product_id, $item, $post_type );
                $this->maybe_send_online_access_link( $order, $product_id );
                continue;
            }

            $meta_json = $item->get_meta( '_cw_participant_data' );
            
            if ( $meta_json ) {
                $participants = json_decode( $meta_json, true );
                foreach ( $participants as $p_num => $fields ) {
                    
                    // NAME LOGIC:
                    // If Activity, use the name they typed (Certificate needs specific name).
                    // If Competition, use the Billing Name (Certificate goes to account owner).
                    $final_name = $billing_name;
                    if($is_activity) {
                        foreach($fields as $f) { if($f['label'] == 'Name') $final_name = $f['value']; }
                    }

                    // Create 1 Post PER Entry (Crucial for individual Scoring)
                    $entry_id = wp_insert_post([
                        'post_title'  => $item->get_name() . ' - ' . $final_name . ' (' . $p_num . ')',
                        'post_type'   => $post_type,
                        'post_status' => 'publish',
                        'post_author' => $user_id
                    ]);

                    if ( ! is_wp_error( $entry_id ) ) {
                        update_post_meta( $entry_id, 'order_id', $order_id );
                        update_post_meta( $entry_id, 'product_id', $product_id );
                        update_post_meta( $entry_id, 'customer_id', $user_id );
                        update_post_meta( $entry_id, 'cw_participant_name', $final_name );
                        update_post_meta( $entry_id, 'participant_details', $fields );

                        // Only judged campaigns (Competitions) get score / vote_count placeholders.
                        if ( self::campaign_is_judged( $product_id ) ) {
                            update_post_meta($entry_id, 'vote_count', 0);
                            update_post_meta($entry_id, 'judge_score', 0); // Init Score
                        }

                        foreach ($fields as $f) {
                            if ( isset($f['value']) && preg_match('/\.(jpg|jpeg|png|pdf)$/i', $f['value']) ) update_post_meta( $entry_id, 'upload_document', $f['value'] );
                        }

                        // Lets feature modules (Design Submission, etc.) stamp extra meta
                        // onto the freshly-created entry from the corresponding order line.
                        // `$p_num` is the 1-based participant index inside this line
                        // and lets modules look up per-row arrays (artworks, variants,
                        // etc.) by slot rather than relying on call-order heuristics.
                        do_action( 'cw_entry_created_from_order', (int) $entry_id, $item, $order, (int) $p_num );
                    }
                }
            } 

            $this->maybe_send_online_access_link( $order, (int) $product_id );
        }
        $order->update_meta_data( '_cw_entries_created', 'yes' );
        $order->save();
    }

    /**
     * Send the online meeting link to the customer once per (order, product) pair.
     *
     * Uses add_post_meta($order_id, $key, $value, $unique = true) as the
     * idempotency guard so even if WooCommerce fires payment_complete /
     * order_status_completed / order_status_processing in the same request
     * (or across retries), we only send once per product line. Email failure
     * never blocks entry creation — CW_Email::send_online_access_link logs
     * and returns false on its own.
     *
     * @param WC_Order $order
     * @param int      $product_id
     */
    private function maybe_send_online_access_link( $order, $product_id ) {
        if ( ! ( $order instanceof WC_Order ) || $product_id <= 0 ) {
            return;
        }
        if ( ! class_exists( 'CW_Email' ) ) {
            return;
        }
        if ( 'online' !== get_post_meta( $product_id, 'cw_event_mode', true ) ) {
            return;
        }

        // Skip silently when there's no link configured yet — don't burn the
        // idempotency guard so the business can still email retroactively if
        // they fix the meta and a future status hook re-runs entry creation.
        $link = trim( (string) get_post_meta( $product_id, 'cw_online_link', true ) );
        if ( '' === $link ) {
            return;
        }

        $guard_key = '_cw_online_link_sent_pid_' . $product_id;
        // add_post_meta with $unique = true returns false if the key already exists,
        // making this a single-shot guard per (order, product).
        if ( ! add_post_meta( $order->get_id(), $guard_key, time(), true ) ) {
            return;
        }

        try {
            CW_Email::send_online_access_link( $order, $product_id );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[CW_Shop] Online-access email dispatch threw for order #%d / product #%d: %s',
                (int) $order->get_id(),
                (int) $product_id,
                $e->getMessage()
            ) );
        }
    }

    private function create_entry_from_staged( $staged_id, $order_id, $user_id, $product_id, $item, $post_type ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d',
            $staged_id
        ), ARRAY_A );

        if ( ! $row || ( $row['status'] ?? '' ) === 'claimed' ) {
            return;
        }

        global $wpdb;
        $locked = $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . CW_Staged_Submissions::table() . ' SET status = %s, claimed_by_user_id = %d, order_id = %d, updated_at = %s WHERE id = %d AND status = %s',
                'claimed',
                (int) $user_id,
                (int) $order_id,
                current_time( 'mysql' ),
                (int) $staged_id,
                'staged'
            )
        );
        if ( ! $locked ) {
            return;
        }

        if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
            CW_Pending_Parent_Link::delete_by_code( $row['submission_code'], (int) $row['campaign_id'] );
        }

        $name = $row['student_name'];
        $campaign_id = (int) ( $row['campaign_id'] ?? 0 );
        $art_aid = class_exists( 'CW_Campaign_Fields' )
            ? CW_Campaign_Fields::get_primary_artwork_attachment_id( $row, $campaign_id )
            : (int) ( $row['artwork_attachment_id'] ?? 0 );
        $art_url = $art_aid ? wp_get_attachment_url( $art_aid ) : '';

        $fields = class_exists( 'CW_Campaign_Fields' )
            ? CW_Campaign_Fields::build_participant_details_from_staged( $row )
            : [
                [ 'label' => 'Name', 'value' => $name ],
                [ 'label' => 'Submission code', 'value' => $row['submission_code'] ],
            ];
        $msg = $row['checkout_message'] ?? '';
        if ( ! $msg ) {
            $msg = get_post_meta( $order_id, 'cw_checkout_message', true );
        }
        if ( $msg ) {
            $fields[] = [
                'label' => get_post_meta( $product_id, 'cw_checkout_message_label', true ) ?: 'Message',
                'value' => wp_kses_post( $msg ),
            ];
        }

        $entry_id = wp_insert_post( [
            'post_title'  => $item->get_name() . ' - ' . $name,
            'post_type'   => $post_type,
            'post_status' => 'publish',
            'post_author' => $user_id,
        ] );

        if ( is_wp_error( $entry_id ) ) {
            return;
        }

        update_post_meta( $entry_id, 'order_id', $order_id );
        update_post_meta( $entry_id, 'product_id', $product_id );
        update_post_meta( $entry_id, 'customer_id', $user_id );
        update_post_meta( $entry_id, 'cw_participant_name', $name );
        update_post_meta( $entry_id, 'participant_details', $fields );
        update_post_meta( $entry_id, 'cw_submission_code', $row['submission_code'] );
        update_post_meta( $entry_id, 'cw_age_bracket_key', $row['age_bracket_key'] ?? $item->get_meta( '_cw_age_bracket_key' ) );

        if ( $art_url ) {
            update_post_meta( $entry_id, 'upload_document', $art_url );
        }

        if ( self::campaign_is_judged( $product_id ) ) {
            update_post_meta( $entry_id, 'vote_count', 0 );
            update_post_meta( $entry_id, 'judge_score', 0 );
        }

        CW_Staged_Submissions::update( $staged_id, [ 'entry_id' => $entry_id ] );

        if ( class_exists( 'CW_Audit_Log' ) ) {
            CW_Audit_Log::log( 'staged_claimed', 'staged', $staged_id, [ 'order_id' => $order_id, 'entry_id' => $entry_id ] );
        }
        do_action( 'cw_staged_claimed', $user_id, $row, $product_id );
        do_action( 'cw_order_entry_created', $user_id, $entry_id, $product_id, $order_id );
    }

    public function redirect_to_checkout( $url ) { return wc_get_checkout_url(); }
    public function remove_loop_add_to_cart() { if(is_shop()||is_product_category()||is_product_tag()){remove_action('woocommerce_after_shop_loop_item','woocommerce_template_loop_add_to_cart',10);add_action('woocommerce_after_shop_loop_item',[$this,'replace_loop_button'],10);}}
    public function replace_loop_button(){global $product;echo '<a href="'.$product->get_permalink().'" class="button">' . __('View Details', 'creativewings-core') . '</a>';}

    /**
     * Redirect the WooCommerce shop archive (/shop/) to the Activities page.
     * Campaigns are surfaced via /activities/ and /competitions/ shortcode pages
     * instead, so the generic shop archive is redundant.
     *
     * Only the shop archive itself is redirected — single product pages and
     * product category / tag archives are left intact.
     */
    public function redirect_shop_archive() {
        if ( is_admin() ) {
            return;
        }
        if ( ! function_exists( 'is_shop' ) || ! is_shop() ) {
            return;
        }
        $target = self::get_shop_fallback_url();
        if ( $target ) {
            wp_safe_redirect( $target, 301 );
            exit;
        }
    }

    /**
     * Replace WooCommerce's "Return to shop" URL (empty cart, checkout failure, etc.)
     * with the Activities page.
     */
    public function filter_return_to_shop_url( $url ) {
        $target = self::get_shop_fallback_url();
        return $target ?: $url;
    }

    /**
     * Resolve the URL to use anywhere we'd normally send shoppers to /shop/.
     * Prefers /activities/ when that page exists, otherwise /competitions/, otherwise home.
     */
    public static function get_shop_fallback_url() {
        $candidates = [ 'activities', 'competitions' ];
        foreach ( $candidates as $slug ) {
            $page = get_page_by_path( $slug );
            if ( $page instanceof WP_Post && $page->post_status === 'publish' ) {
                return get_permalink( $page );
            }
        }
        // Fall back to a hard-coded /activities/ URL (matches the convention used
        // elsewhere in the plugin), then home as a last resort.
        return home_url( '/activities/' );
    }
}