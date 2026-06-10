<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Business_Form {

    private $sdg_map;
    private $step_labels = [
        1 => ['label' => 'Classify',     'icon' => 'fa-tag'],
        2 => ['label' => 'Basic Details','icon' => 'fa-pen'],
        3 => ['label' => 'Specifics',    'icon' => 'fa-cog'],
        4 => ['label' => 'SDG & Extras', 'icon' => 'fa-leaf'],
        5 => ['label' => 'Form & Publish','icon' => 'fa-rocket'],
    ];

    public function __construct() {
        if (class_exists('CW_Business')) {
            $this->sdg_map = CW_Business::get_sdg_map();
        } else {
            $this->sdg_map = [];
        }
        add_shortcode('business_create_campaign_form', [ $this, 'render_form' ]);
    }

    private function unwrap_meta($id, $key) {
        $val = get_post_meta($id, $key, true);
        if (is_array($val) && isset($val[0]) && is_array($val[0])) return $val[0];
        return is_array($val) ? $val : [];
    }

    private function get_sdg_color($id) {
        $colors = [1=>'#e5243b',2=>'#dda63a',3=>'#4c9f38',4=>'#c5192d',5=>'#ff3a21',6=>'#26bde2',7=>'#fcc30b',8=>'#a21942',9=>'#fd6925',10=>'#dd1367',11=>'#fd9d24',12=>'#bf8b2e',13=>'#3f7e44',14=>'#0a97d9',15=>'#56c02b',16=>'#00689d',17=>'#19486a'];
        return $colors[$id] ?? '#94a3b8';
    }

    // Shared field type options for both field-builder and add-ons
    private function field_type_options($selected = 'text') {
        $types = [
            'text'     => 'Short Text',
            'textarea' => 'Long Text / Paragraph',
            'number'   => 'Number',
            'email'    => 'Email',
            'phone'    => 'Phone / Tel',
            'file'     => 'Document Upload (PDF/Doc)',
            'media'    => 'Image Upload (JPG/PNG)',
            'select'   => 'Dropdown',
            'wysiwyg'  => 'Rich Text Area',
        ];
        $out = '';
        foreach ($types as $val => $label) {
            $out .= '<option value="'.esc_attr($val).'" '.selected($selected, $val, false).'>'.esc_html($label).'</option>';
        }
        return $out;
    }

    // Add-on type options (includes checkbox + all field builder types)
    private function addon_type_options($selected = 'checkbox') {
        $types = [
            'checkbox' => 'Checkbox (Yes/No)',
            'text'     => 'Short Text',
            'textarea' => 'Long Text / Paragraph',
            'number'   => 'Number',
            'email'    => 'Email',
            'phone'    => 'Phone / Tel',
            'file'     => 'Document Upload',
            'media'    => 'Image Upload',
            'select'   => 'Dropdown',
        ];
        $out = '';
        foreach ($types as $val => $label) {
            $out .= '<option value="'.esc_attr($val).'" '.selected($selected, $val, false).'>'.esc_html($label).'</option>';
        }
        return $out;
    }

    public function render_form( $atts = [], $content = null, $is_modal = false, $external_edit_id = 0 ) {
        if ( ! current_user_can( 'edit_products' ) ) return '<div class="cw-alert error">Access Denied.</div>';

        $mode = 'create'; $edit_id = 0; $campaign = null; $meta = []; $current_cat_id = 0;
        $existing_fields = []; $existing_faqs = []; $existing_prizes = []; $existing_addons = []; $selected_sdgs_bool = [];
        $existing_age_brackets = []; $existing_schools = [];
        $term = null; $parent_term = null;

        $edit_id_param = isset($_GET['edit_id']) ? intval($_GET['edit_id']) : $external_edit_id;

        if ( $edit_id_param > 0 ) {
            $edit_id  = $edit_id_param;
            $campaign = get_post($edit_id);
            if ( $campaign && ( class_exists( 'CW_Roles' ) ? CW_Roles::user_owns_campaign( $campaign->ID ) : ( (int) $campaign->post_author === get_current_user_id() ) ) ) {
                $mode  = 'edit';
                $meta  = get_post_meta($edit_id);
                $terms = get_the_terms($edit_id, 'product_cat');

                if ($terms && !is_wp_error($terms)) {
                    $term         = is_array($terms) ? reset($terms) : $terms;
                    $current_cat_id = $term->term_id;
                    $parent_term  = $term->parent ? get_term($term->parent, 'product_cat') : null;
                }

                $existing_faqs   = $this->unwrap_meta($edit_id, 'faq');
                $existing_prizes = $this->unwrap_meta($edit_id, 'prizes');
                $existing_addons = $this->unwrap_meta($edit_id, 'addon_products');
                $selected_sdgs_bool = $this->unwrap_meta($edit_id, 'sdg_goals');
                $raw_fields = get_post_meta($edit_id, 'cw_custom_fields', true) ?: [];
                $existing_fields = is_array( $raw_fields ) ? array_values( $raw_fields ) : [];
                $allowed_field_types = ['text','textarea','number','email','phone','file','media','select','wysiwyg'];
                foreach ( $existing_fields as $ek => $ef ) {
                    if ( ! is_array( $ef ) ) {
                        unset( $existing_fields[ $ek ] );
                        continue;
                    }
                    $t = isset( $ef['type'] ) ? strtolower( trim( (string) $ef['type'] ) ) : 'text';
                    if ( ! in_array( $t, $allowed_field_types, true ) ) {
                        $t = 'text';
                    }
                    $existing_fields[ $ek ]['type'] = $t;
                }
                $existing_fields = array_values( $existing_fields );
                $existing_age_brackets = get_post_meta( $edit_id, 'cw_age_brackets', true );
                $existing_schools      = get_post_meta( $edit_id, 'cw_school_sponsors', true );
                if ( ! is_array( $existing_age_brackets ) ) {
                    $existing_age_brackets = [];
                }
                if ( ! is_array( $existing_schools ) ) {
                    $existing_schools = [];
                }
            }
        }
        $val     = function($k) use ($meta) { return isset($meta[$k][0]) ? $meta[$k][0] : ''; };

        $enable_addons = $val( 'cw_enable_addons' );
        if ( $enable_addons === '' ) {
            $enable_addons = ( $mode === 'edit' && ! empty( $existing_addons ) ) ? 'yes' : 'no';
        }
        $enable_age_brackets = $val( 'cw_enable_age_brackets' );
        if ( $enable_age_brackets === '' ) {
            $enable_age_brackets = ( $mode === 'edit' && ! empty( $existing_age_brackets ) ) ? 'yes' : 'no';
        }
        $enable_school_sponsors = $val( 'cw_enable_school_sponsors' );
        if ( $enable_school_sponsors === '' ) {
            $enable_school_sponsors = ( $mode === 'edit' && ( ! empty( $existing_schools ) || $val( 'cw_campaign_serial' ) ) ) ? 'yes' : 'no';
        }
        $allow_multiple_participants = $val( 'cw_allow_multiple_participants' );
        if ( $allow_multiple_participants === '' ) {
            $max_p_meta = (int) $val( 'cw_max_participants' );
            $allow_multiple_participants = ( $mode === 'edit' && $max_p_meta > 1 ) ? 'yes' : 'no';
        }
        $use_account_fullname = $val( 'cw_use_account_fullname' );
        if ( $use_account_fullname === '' ) {
            $use_account_fullname = 'yes';
        }
        $parents = get_terms(['taxonomy' => 'product_cat', 'parent' => 0, 'hide_empty' => false]);

        $is_comp_selected     = $term && (($parent_term && $parent_term->slug === 'competitions') || $term->slug === 'competitions');
        $is_activity_selected = $term && (($parent_term && $parent_term->slug === 'activities')   || $term->slug === 'activities');
        $is_talk_selected     = $term && (
            ($parent_term && in_array( $parent_term->slug, [ 'talk-seminar', 'talks' ], true ))
            || in_array( $term->slug, [ 'talk-seminar', 'talks' ], true )
        );

        ob_start();
        ?>
        <div class="cww-shell" id="cw-wizard-shell">

            <!-- ── Header bar ── -->
            <div class="cww-header">
                <div class="cww-header-left">
                    <div class="cww-header-icon"><i class="fas fa-calendar-plus"></i></div>
                    <div>
                        <h3><?php echo $mode === 'edit' ? 'Edit Campaign' : 'Create New Campaign'; ?></h3>
                        <p><?php echo $mode === 'edit' ? esc_html($campaign->post_title) : 'Fill in 5 quick steps'; ?></p>
                    </div>
                </div>
                <!-- Close button (only relevant when rendered in a modal wrapper) -->
                <button type="button" class="cww-close-btn" id="cww-close-btn" onclick="closeCampaignModal()"><i class="fas fa-times"></i></button>
            </div>

            <!-- ── Body ── -->
            <div class="cww-body">

                <!-- Sidebar stepper -->
                <nav class="cww-sidebar" id="cww-sidebar">
                    <?php foreach ($this->step_labels as $num => $step): ?>
                    <?php if ($num > 1): ?>
                    <div class="cww-step-connector" id="cww-conn-<?php echo $num - 1; ?>"></div>
                    <?php endif; ?>
                    <div class="cww-step-item <?php echo $num === 1 ? 'active' : ''; ?>" id="cww-step-item-<?php echo $num; ?>" data-step="<?php echo $num; ?>">
                        <div class="cww-step-num" id="cww-step-num-<?php echo $num; ?>">
                            <?php echo $num; ?>
                        </div>
                        <div class="cww-step-label">
                            <strong><?php echo esc_html($step['label']); ?></strong>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </nav>

                <!-- Scrollable content -->
                <div class="cww-content">
                    <div class="cww-scroll" id="cww-scroll">
                        <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="POST" enctype="multipart/form-data" id="cw_wizard_form">
                            <input type="hidden" name="action" value="<?php echo $mode === 'edit' ? 'cw_update_campaign' : 'cw_create_campaign'; ?>">
                            <?php if ($mode === 'edit') echo '<input type="hidden" name="campaign_id" value="'.$edit_id.'">'; ?>
                            <?php wp_nonce_field($mode === 'edit' ? 'cw_update_campaign_nonce' : 'cw_create_campaign_nonce', 'cw_nonce'); ?>
                            <input type="hidden" name="cw_main_category_slug" id="cw_main_category_slug" value="<?php
                                if ($term) {
                                    echo esc_attr($parent_term ? $parent_term->slug : $term->slug);
                                }
                            ?>">

                            <!-- ════════════ STEP 1: CLASSIFY ════════════ -->
                            <div class="cw-wizard-step active" data-step="1">
                                <h4 class="cw-step-title">Let's classify your campaign</h4>
                                <p class="cw-step-subtitle">Campaigns come in three flavours: <strong>Competition</strong>, <strong>Activity</strong> or <strong>Talk / Seminar</strong>. Pick one, then choose a sub-category.</p>
                                <div class="cw-step-grid">
                                    <div class="cw-cat-selection">
                                        <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--cw-text-soft);margin-bottom:4px;">Main Category</label>
                                        <div class="cw-cat-card <?php echo $is_comp_selected ? 'selected' : ''; ?>" onclick="selectMainCat(this,'competitions')" data-type="competitions">
                                            <div class="cw-cat-icon"><i class="fas fa-trophy"></i></div><span>Competition</span>
                                        </div>
                                        <div class="cw-cat-card <?php echo $is_activity_selected ? 'selected' : ''; ?>" onclick="selectMainCat(this,'activities')" data-type="activities">
                                            <div class="cw-cat-icon"><i class="fas fa-running"></i></div><span>Activity</span>
                                        </div>
                                        <div class="cw-cat-card <?php echo $is_talk_selected ? 'selected' : ''; ?>" onclick="selectMainCat(this,'talk-seminar')" data-type="talk-seminar">
                                            <div class="cw-cat-icon"><i class="fas fa-microphone-alt"></i></div><span>Talk / Seminar</span>
                                        </div>
                                    </div>
                                    <div class="cw-sub-selection">
                                        <label style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--cw-text-soft);margin-bottom:4px;display:block;">Sub Category</label>
                                        <select name="product_cat" id="cw_sub_cat" <?php echo $current_cat_id ? '' : 'disabled'; ?>>
                                            <option value="">Select Sub Category</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- ════════════ STEP 2: BASIC DETAILS ════════════ -->
                            <div class="cw-wizard-step" data-step="2" style="display:none;">
                                <h4 class="cw-step-title">Basic Details &amp; Dates</h4>
                                <p class="cw-step-subtitle">Set up the core information about your campaign.</p>

                                <div class="cw-field">
                                    <label>Campaign Title *</label>
                                    <input type="text" name="post_title" required class="cw-input-dark" value="<?php echo $mode==='edit' ? esc_attr($campaign->post_title) : ''; ?>" placeholder="e.g., National Design Challenge 2025">
                                </div>

                                <div class="cw-form-row-2">
                                    <div class="cw-field">
                                        <label>Entry Fee (RM)</label>
                                        <input type="number" name="regular_price" step="0.01" min="0" required class="cw-input-dark" value="<?php echo $val('_regular_price') ?: '0'; ?>" placeholder="0">
                                    </div>
                                    <div class="cw-field">
                                        <label>Total Prize Value (display text)</label>
                                        <input type="text" name="cw_total_prize_value" class="cw-input-dark" value="<?php echo $val('cw_total_prize_value'); ?>" placeholder="e.g. RM 5,000 Pool">
                                    </div>
                                </div>

                                <div class="cw-field">
                                    <label>Total Prize Amount (RM, numeric &mdash; used by site-wide totals)</label>
                                    <input type="number" name="cw_total_prize_amount" step="0.01" min="0"
                                        class="cw-input-dark"
                                        value="<?php echo esc_attr( $val('cw_total_prize_amount') ); ?>"
                                        placeholder="e.g. 5000 or 3500.50">
                                    <small style="display:block;margin-top:4px;font-size:11px;color:var(--cw-text-soft);">
                                        Numbers only, no symbols. This is summed across all published campaigns by the <code>[total_prize_money]</code> shortcode in the site header.
                                    </small>
                                </div>

                                <?php
                                // ─────── KPI / Target progress bar ───────
                                // Three meta keys drive the public-facing progress widget on the campaign
                                // page (rendered by class-cw-shortcodes.php). Surfacing them here in the
                                // wizard lets organizers set a measurable goal (e.g. "500 submissions")
                                // at campaign-creation time instead of having to dig into the WP admin
                                // metabox. The participant count is computed live from completed orders;
                                // organizers only own the target + label + visibility toggle.
                                $kpi_show_val   = $val('cw_kpi_show_progress');
                                $kpi_target_val = $val('cw_kpi_target');
                                $kpi_label_val  = $val('cw_kpi_label');
                                ?>
                                <p class="cw-mini-head">Campaign KPI <small style="font-weight:400;color:var(--cw-text-soft);">(optional · public progress bar)</small></p>
                                <div class="cw-toggle-box">
                                    <div>
                                        <label for="cw_kpi_show_progress"><strong>Show KPI progress on campaign page</strong></label>
                                        <small>Displays "X of Y &lt;label&gt; · NN%" with a live progress bar. Count updates automatically as participants register.</small>
                                    </div>
                                    <input type="checkbox" name="cw_kpi_show_progress" value="yes" id="cw_kpi_show_progress" <?php checked( $kpi_show_val, 'yes' ); ?>>
                                </div>
                                <div class="cw-form-row-2" style="padding-top:10px;">
                                    <div class="cw-field">
                                        <label>KPI Target</label>
                                        <input type="number" name="cw_kpi_target" min="0" step="1" class="cw-input-dark"
                                               value="<?php echo esc_attr( $kpi_target_val ); ?>" placeholder="e.g. 500">
                                    </div>
                                    <div class="cw-field">
                                        <label>KPI Label</label>
                                        <input type="text" name="cw_kpi_label" class="cw-input-dark"
                                               value="<?php echo esc_attr( $kpi_label_val ); ?>" placeholder="e.g. submissions, participated">
                                    </div>
                                </div>

                                <div class="cw-field full">
                                    <label>Banner Image</label>
                                    <div class="cw-upload-box" onclick="document.getElementById('cwBannerFile').click()">
                                        <i class="fas fa-image"></i> Click to Upload Banner
                                    </div>
                                    <input type="file" id="cwBannerFile" name="campaign_image" accept="image/*" style="display:none;" onchange="cwPreviewBanner(this)">
                                    <?php $existing_thumb = $mode==='edit' ? get_the_post_thumbnail_url($edit_id,'medium') : ''; ?>
                                    <img id="cwBannerPreview" class="cwb-banner-preview" src="<?php echo esc_url($existing_thumb); ?>" style="display:<?php echo $existing_thumb?'block':'none'; ?>;">
                                </div>

                                <?php
                                /* ───────── Gallery uploader ─────────
                                   Multiple images stored as the WooCommerce
                                   product gallery (_product_image_gallery, CSV
                                   of attachment IDs). The public product page
                                   automatically picks them up. */
                                $existing_gallery_ids = [];
                                if ( $mode === 'edit' ) {
                                    $gv = get_post_meta( $edit_id, '_product_image_gallery', true );
                                    if ( ! empty( $gv ) ) {
                                        $existing_gallery_ids = array_filter( array_map( 'intval', explode( ',', $gv ) ) );
                                    }
                                }
                                ?>
                                <div class="cw-field full cw-gallery-field">
                                    <label>Gallery Images <small style="font-weight:400;color:var(--cw-text-soft);">(optional · multiple)</small></label>
                                    <div class="cw-upload-box cw-gallery-upload" onclick="document.getElementById('cwGalleryFiles').click()">
                                        <i class="fas fa-images"></i> Click to add gallery images
                                        <small style="display:block;margin-top:4px;font-size:11px;color:var(--cw-text-soft);">PNG / JPG / WEBP · uploaded in addition to existing gallery</small>
                                    </div>
                                    <input type="file" id="cwGalleryFiles" name="cw_gallery_files[]" accept="image/*" multiple style="display:none;" onchange="cwPreviewGallery(this)">
                                    <input type="hidden" name="cw_gallery_keep" id="cwGalleryKeep" value="<?php echo esc_attr( implode( ',', $existing_gallery_ids ) ); ?>">
                                    <div id="cwGalleryGrid" class="cw-gallery-grid">
                                        <?php foreach ( $existing_gallery_ids as $aid ):
                                            $src = wp_get_attachment_image_url( $aid, 'thumbnail' );
                                            if ( ! $src ) continue;
                                        ?>
                                        <div class="cw-gallery-tile" data-attachment-id="<?php echo (int) $aid; ?>">
                                            <img src="<?php echo esc_url( $src ); ?>" alt="" loading="lazy">
                                            <button type="button" class="cw-gallery-remove" onclick="cwGalleryRemoveExisting(this, <?php echo (int) $aid; ?>)" aria-label="Remove">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>

                                <div class="cw-field full cw-field-rich-text">
                                    <label>Description *</label>
                                    <?php wp_editor($mode==='edit' ? $campaign->post_content : '', 'post_content', ['textarea_name'=>'post_content','media_buttons'=>false,'textarea_rows'=>4,'teeny'=>true,'quicktags'=>false,'editor_class'=>'cw-slim-editor-dark']); ?>
                                </div>

                                <p class="cw-mini-head">Campaign Format</p>
                                <div class="cw-event-mode-radios">
                                    <label class="cw-radio-option"><input type="radio" name="cw_event_mode" value="physical" id="mode_physical" onchange="toggleOnlineLink()" <?php checked($val('cw_event_mode') ?: 'physical','physical'); ?>> Physical</label>
                                    <label class="cw-radio-option"><input type="radio" name="cw_event_mode" value="online" id="mode_online" onchange="toggleOnlineLink()" <?php checked($val('cw_event_mode'),'online'); ?>> Online / Virtual</label>
                                </div>
                                <div class="cw-location-input-wrap">
                                    <i class="fas fa-map-marker-alt" id="cw_location_icon"></i>
                                    <input type="text" name="cw_location_details" id="cw_location_details" value="<?php echo $val('cw_location_details'); ?>" placeholder="Full Venue Address" style="display:<?php echo $val('cw_event_mode')==='online'?'none':'block'; ?>">
                                    <input type="url" name="cw_online_link" id="cw_online_link" value="<?php echo $val('cw_online_link'); ?>" placeholder="Secure Online Link" style="display:<?php echo $val('cw_event_mode')==='online'?'block':'none'; ?>">
                                </div>
                                <small id="cw_online_link_hint" style="display:<?php echo $val('cw_event_mode')==='online' ? 'block' : 'none'; ?>;color:var(--cw-text-soft, #555555);margin-top:6px;font-size:12px;line-height:1.5;">
                                    <i class="fas fa-info-circle" style="margin-right:4px;"></i>
                                    This link will be emailed to participants automatically after they complete checkout. Do not share publicly.
                                </small>

                                <p class="cw-mini-head">Important Dates</p>
                                <div class="cw-field"><label>Submission Start *</label><input type="date" name="cw_submission_start" required class="cw-input-dark" value="<?php echo $val('cw_submission_start'); ?>"></div>
                                <div class="cw-field"><label>Submission Deadline *</label><input type="date" name="submission_deadline" required class="cw-input-dark" value="<?php echo $val('submission_deadline'); ?>"></div>
                                <div class="cw-field"><label>Review Start</label><input type="date" name="cw_review_start" class="cw-input-dark" value="<?php echo $val('cw_review_start'); ?>"></div>
                                <div class="cw-field"><label>Final Campaign Date</label><input type="date" name="cw_final_event_date" class="cw-input-dark" value="<?php echo $val('cw_final_event_date'); ?>"></div>
                            </div>

                            <!-- ════════════ STEP 3: SPECIFICS ════════════ -->
                            <div class="cw-wizard-step" data-step="3" style="display:none;">
                                <h4 class="cw-step-title">Specific Logic</h4>
                                <p class="cw-step-subtitle">Configure competition rules, prizes, or activity details.</p>

                                <div id="cw-conditional-section" style="display:none;">
                                    <!-- Competition block -->
                                    <div id="set-competition" style="display:none;" class="cw-config-card">
                                        <div class="cw-toggle-box">
                                            <div><label for="cw_voting_check">Enable Public Voting</label><small>Allow visitors to vote for submissions</small></div>
                                            <input type="checkbox" name="cw_enable_voting" value="yes" id="cw_voting_check" <?php checked($val('cw_enable_voting'),'yes'); ?>>
                                        </div>
                                        <div class="cw-toggle-box">
                                            <div><label for="cw_multi_check">Allow Multiple Submissions</label><small>One user can submit multiple entries</small></div>
                                            <input type="checkbox" name="multiple_submissions" value="true" id="cw_multi_check" onclick="toggleMultiLimits()" <?php checked($val('multiple_submissions'),'true'); ?>>
                                        </div>
                                        <div id="cw-multi-limits" class="cw-form-row-2" style="display:none;padding-top:10px;">
                                            <div class="cw-field"><label>Min Entries</label><input type="number" name="cw_multi_min" value="<?php echo $val('cw_multi_min')?:1; ?>" class="cw-input-dark" placeholder="1"></div>
                                            <div class="cw-field"><label>Max Entries</label><input type="number" name="cw_multi_max" value="<?php echo $val('cw_multi_max')?:10; ?>" class="cw-input-dark" placeholder="10"></div>
                                        </div>
                                    </div>

                                    <!-- Design submission block — only shown when the chosen sub-category slug includes 'design'. -->
                                    <?php
                                    $design_enable_val   = $val('cw_enable_design');
                                    $design_label_val    = $val('cw_design_picker_label');
                                    $design_w_val        = $val('cw_design_artwork_w');
                                    $design_h_val        = $val('cw_design_artwork_h');
                                    $design_default_val  = $val('cw_design_default_variant');
                                    $design_variants_raw = get_post_meta($edit_id, 'cw_design_variants', true);
                                    $design_variants     = is_array($design_variants_raw) ? array_values($design_variants_raw) : [];
                                    if ($design_enable_val === '' && ! empty($design_variants)) {
                                        $design_enable_val = 'yes';
                                    }
                                    ?>
                                    <div id="set-design" style="display:none;" class="cw-config-card">
                                        <div class="cw-toggle-box">
                                            <div>
                                                <label for="cw_design_enable_check"><strong>Enable Design Submission</strong></label>
                                                <small>Participants upload a PNG artwork that gets previewed on a product variant they pick at checkout.</small>
                                            </div>
                                            <input type="checkbox" name="cw_enable_design" value="yes" id="cw_design_enable_check" onchange="toggleWizardSection('cw-design-config-body', this)" <?php checked($design_enable_val, 'yes'); ?>>
                                        </div>

                                        <div id="cw-design-config-body" style="display:<?php echo $design_enable_val === 'yes' ? 'block' : 'none'; ?>;padding-top:10px;">
                                            <div class="cw-field">
                                                <label>Variant picker label (shown on checkout) *</label>
                                                <input type="text" name="cw_design_picker_label" class="cw-input-dark"
                                                       value="<?php echo esc_attr($design_label_val ?: ''); ?>"
                                                       placeholder="Choose your color">
                                            </div>

                                            <div class="cw-form-row-2">
                                                <div class="cw-field">
                                                    <label>Artwork width (px) *</label>
                                                    <input type="number" min="1" step="1" name="cw_design_artwork_w" class="cw-input-dark"
                                                           value="<?php echo esc_attr($design_w_val ?: ''); ?>" placeholder="2400">
                                                </div>
                                                <div class="cw-field">
                                                    <label>Artwork height (px) *</label>
                                                    <input type="number" min="1" step="1" name="cw_design_artwork_h" class="cw-input-dark"
                                                           value="<?php echo esc_attr($design_h_val ?: ''); ?>" placeholder="600">
                                                </div>
                                            </div>
                                            <p style="font-size:12px;color:var(--cw-text-soft);margin:-6px 0 8px;">
                                                Every participant uploads at this exact size. Each variant image you add below must also be the same dimensions so the artwork lines up pixel-perfect.
                                            </p>

                                            <p class="cw-mini-head">Product variants</p>
                                            <p style="font-size:12px;color:var(--cw-text-soft);margin:-6px 0 8px;">
                                                Add as many as you like (most campaigns use 3-6). Pick which one is the default pre-selected on checkout.
                                            </p>

                                            <div id="cw-design-variants-list">
                                                <?php
                                                if (empty($design_variants)) {
                                                    $design_variants = [ [ 'slug' => '', 'name' => '', 'attachment_id' => 0 ] ];
                                                }
                                                foreach ($design_variants as $idx => $v) :
                                                    $vname = sanitize_text_field($v['name'] ?? '');
                                                    $vslug = sanitize_title($v['slug'] ?? sanitize_title($vname));
                                                    $vaid  = (int) ($v['attachment_id'] ?? 0);
                                                    $vurl  = $vaid ? wp_get_attachment_url($vaid) : '';
                                                    $is_default = ($vslug !== '' && $vslug === $design_default_val);
                                                ?>
                                                <div class="cww-rep-row cw-design-variant-row" data-idx="<?php echo (int) $idx; ?>" style="grid-template-columns: 80px 1fr 1fr auto auto;align-items:center;">
                                                    <div class="cw-design-variant-thumb" style="width:70px;height:46px;background:<?php echo $vurl ? '#fff' : '#f1f5f9'; ?>;border:1px <?php echo $vurl ? 'solid' : 'dashed'; ?> #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;text-align:center;overflow:hidden;">
                                                        <?php if ($vurl) : ?>
                                                            <img src="<?php echo esc_url($vurl); ?>" style="max-width:100%;max-height:100%;object-fit:contain;">
                                                        <?php else : ?>
                                                            No image
                                                        <?php endif; ?>
                                                    </div>
                                                    <input type="text" name="cw_design_variants[<?php echo (int) $idx; ?>][name]" value="<?php echo esc_attr($vname); ?>" placeholder="Variant name (e.g. Midnight Blue)" class="cw-design-variant-name">
                                                    <div>
                                                        <input type="file" accept="image/png,image/jpeg,image/webp" class="cw-file-upload-input cw-design-variant-file" data-session-key="cw_design_wizard_variant_<?php echo (int) $idx; ?>" style="width:100%;font-size:12px;">
                                                        <input type="hidden" name="cw_design_variants[<?php echo (int) $idx; ?>][attachment_id]" value="<?php echo esc_attr($vaid); ?>" class="cw-design-variant-aid">
                                                        <input type="hidden" name="cw_design_variants[<?php echo (int) $idx; ?>][slug]" value="<?php echo esc_attr($vslug); ?>" class="cw-design-variant-slug">
                                                    </div>
                                                    <label style="text-align:center;font-size:11px;color:var(--cw-text-soft);">
                                                        <input type="radio" name="cw_design_default_variant" value="<?php echo esc_attr($vslug); ?>" class="cw-design-variant-default-radio" <?php checked($is_default, true); ?>>
                                                        <br>Default
                                                    </label>
                                                    <button type="button" class="cww-rep-del cw-design-variant-remove" onclick="this.closest('.cw-design-variant-row').remove()"><i class="fas fa-times"></i></button>
                                                </div>
                                                <?php endforeach; ?>
                                            </div>

                                            <button type="button" class="cww-rep-add" onclick="window.addDesignVariantRow()">
                                                <i class="fas fa-plus"></i> Add variant
                                            </button>
                                        </div>
                                    </div>

                                    <!-- Activity block -->
                                    <div id="set-activity" style="display:none;" class="cw-config-card">
                                        <div class="cw-toggle-box">
                                            <div><label for="cw_allow_multi_participants">Allow multiple participants</label><small>One registration can include more than one person (e.g. parent registers children).</small></div>
                                            <input type="checkbox" name="cw_allow_multiple_participants" value="yes" id="cw_allow_multi_participants" onchange="toggleParticipantCapacity()" <?php checked( $allow_multiple_participants, 'yes' ); ?>>
                                        </div>
                                        <div id="cw-participant-capacity" class="cw-form-row-2" style="display:<?php echo $allow_multiple_participants === 'yes' ? 'grid' : 'none'; ?>;padding-top:10px;">
                                            <div class="cw-field"><label>Min Participants</label><input type="number" name="cw_min_participants" value="<?php echo esc_attr( $val('cw_min_participants') ?: 1 ); ?>" class="cw-input-dark" min="1"></div>
                                            <div class="cw-field"><label>Max Participants</label><input type="number" name="cw_max_participants" value="<?php echo esc_attr( $val('cw_max_participants') ?: 10 ); ?>" class="cw-input-dark" min="1"></div>
                                        </div>
                                        <div id="set-talk" style="display:none;">
                                            <div class="cw-field"><label>Speaker / Host Name</label><input type="text" name="cw_talk_speaker" class="cw-input-dark" value="<?php echo $val('cw_talk_speaker'); ?>"></div>
                                        </div>
                                    </div>
                                </div>

                                <p class="cw-mini-head">Judges &amp; Criteria / Who Can Join</p>
                                <p style="font-size:12px;color:var(--cw-text-soft);margin:-6px 0 8px;">
                                    Describe how entries will be judged, who can participate, or what attendees should expect.
                                    <strong>Bullet lists, bold, italic and links are kept</strong> — click the list icons in the toolbar.
                                </p>
                                <div class="cw-field cw-field-rich-text" id="cw-judges-box">
                                    <?php wp_editor(
                                        $val('cw_judging_criteria'),
                                        'cw_judging_criteria_editor',
                                        [
                                            'textarea_name' => 'cw_judging_criteria',
                                            'media_buttons' => false,
                                            'textarea_rows' => 6,
                                            // Teeny keeps the toolbar minimal but still includes bullist /
                                            // numlist / bold / italic / link, which is exactly what the
                                            // organiser asked for. We re-enable quicktags so they can
                                            // switch to the Text tab and paste/clean HTML if a copy-paste
                                            // from Word brings in junk markup.
                                            'teeny'         => true,
                                            'quicktags'     => true,
                                            'tinymce'       => [
                                                'toolbar1'         => 'formatselect,bold,italic,underline,bullist,numlist,blockquote,link,unlink,removeformat,undo,redo',
                                                'block_formats'    => 'Paragraph=p;Heading=h4',
                                                'paste_as_text'    => false,
                                                'paste_auto_cleanup_on_paste' => true,
                                            ],
                                            'editor_class'  => 'cw-slim-editor-dark',
                                        ]
                                    ); ?>
                                </div>

                                <p class="cw-mini-head">Prizes</p>
                                <p style="font-size:12px;color:var(--cw-text-soft);margin:-6px 0 8px;">
                                    Group prizes by <strong>Category</strong> (e.g. age bracket) so they render together on the public page in coloured sections.
                                    Pick a <strong>Position</strong> to control the badge icon — Champion gets a trophy, 1st Runner-Up gets ribbon "2", 2nd Runner-Up gets ribbon "3".
                                </p>
                                <?php
                                // Prize-category autocomplete suggestions — drawn from any age brackets
                                // the organiser has defined, plus a few common defaults so the dropdown
                                // is never empty.
                                $cw_prize_cat_suggestions = [];
                                foreach ( (array) $existing_age_brackets as $b ) {
                                    if ( is_array( $b ) && ! empty( $b['label'] ) ) {
                                        $cw_prize_cat_suggestions[] = (string) $b['label'];
                                    }
                                }
                                $cw_prize_cat_suggestions = array_values( array_unique( array_filter( array_merge(
                                    $cw_prize_cat_suggestions,
                                    [ 'Primary', 'Secondary', 'Open', 'Grand Prize', 'People\'s Choice' ]
                                ) ) ) );
                                ?>
                                <datalist id="cw-prize-categories-dl">
                                    <?php foreach ( $cw_prize_cat_suggestions as $cw_sug ): ?>
                                    <option value="<?php echo esc_attr( $cw_sug ); ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                                <div id="cw-prize-container">
                                    <?php $idx=0; if($existing_prizes) foreach($existing_prizes as $p){ if(!is_array($p))continue; ?>
                                    <div class="cww-rep-row cww-rep-row-prize">
                                        <input type="text" name="cw_prizes[<?php echo $idx; ?>][prize_title]" value="<?php echo esc_attr($p['prize_title']??''); ?>" placeholder="Prize Title (e.g. Champion)">
                                        <input type="text" name="cw_prizes[<?php echo $idx; ?>][prize_category]" value="<?php echo esc_attr($p['prize_category']??''); ?>" placeholder="Category (e.g. Primary)" list="cw-prize-categories-dl">
                                        <select name="cw_prizes[<?php echo $idx; ?>][prize_position]" class="cww-rep-pos">
                                            <?php
                                                $cw_pos_val = (string) ( $p['prize_position'] ?? '' );
                                                $cw_pos_opts = [
                                                    ''             => '— Position —',
                                                    'champion'     => 'Champion (Trophy)',
                                                    'runner_up_1'  => '1st Runner-Up (Ribbon 2)',
                                                    'runner_up_2'  => '2nd Runner-Up (Ribbon 3)',
                                                    'honorable'    => 'Honorable Mention',
                                                    'participation'=> 'Participation',
                                                    'custom'       => 'Other / Custom',
                                                ];
                                                foreach ( $cw_pos_opts as $cw_pk => $cw_pl ) {
                                                    printf(
                                                        '<option value="%s"%s>%s</option>',
                                                        esc_attr( $cw_pk ),
                                                        selected( $cw_pos_val, $cw_pk, false ),
                                                        esc_html( $cw_pl )
                                                    );
                                                }
                                            ?>
                                        </select>
                                        <input type="text" name="cw_prizes[<?php echo $idx; ?>][prize_description]" value="<?php echo esc_attr($p['prize_description']??''); ?>" placeholder="Description (e.g. RM 1,000 cash)">
                                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php $idx++; } ?>
                                </div>
                                <button type="button" class="cww-rep-add" onclick="addPrizeRow()"><i class="fas fa-plus"></i> Add Prize</button>

                                <!-- ── Downloadable participant template ──────────────
                                     Lets organisers attach ONE source file (PSD / AI /
                                     PDF / ZIP / PNG / SVG / etc.) that participants
                                     download from the campaign page before submitting.
                                     Stored as a single attachment id under
                                     `cw_template_file_id`; the display label and the
                                     direct URL surface on the public detail page. -->
                                <?php
                                    $cw_tpl_id    = (int) $val('cw_template_file_id');
                                    $cw_tpl_label = $val('cw_template_label');
                                    if ( $cw_tpl_label === '' ) {
                                        $cw_tpl_label = 'Download Template';
                                    }
                                    $cw_tpl_url   = $cw_tpl_id ? (string) wp_get_attachment_url( $cw_tpl_id ) : '';
                                    $cw_tpl_name  = $cw_tpl_id ? basename( (string) get_attached_file( $cw_tpl_id ) ) : '';
                                    $cw_tpl_size  = '';
                                    if ( $cw_tpl_id ) {
                                        $cw_tpl_path = (string) get_attached_file( $cw_tpl_id );
                                        if ( $cw_tpl_path && file_exists( $cw_tpl_path ) ) {
                                            $cw_tpl_size = size_format( (int) filesize( $cw_tpl_path ), 2 );
                                        }
                                    }
                                ?>
                                <p class="cw-mini-head" style="margin-top:28px;">Participant Template <small style="font-weight:400;color:var(--cw-text-soft);">(optional)</small></p>
                                <p style="font-size:12px;color:var(--cw-text-soft);margin:-6px 0 8px;">
                                    Attach a downloadable template participants can use before submitting their entry &mdash; e.g. a print-ready <strong>PSD / AI / EPS / PDF / SVG</strong> or a <strong>ZIP</strong> bundle of assets. Only one file per campaign.
                                </p>
                                <div class="cw-field cw-template-field" id="cw-template-upload-box">
                                    <div class="cw-form-row-2" style="gap:12px;align-items:end;">
                                        <div class="cw-field" style="margin:0;">
                                            <label>Download button label</label>
                                            <input type="text" name="cw_template_label" class="cw-input-dark"
                                                value="<?php echo esc_attr( $cw_tpl_label ); ?>"
                                                placeholder="Download Template" maxlength="80">
                                        </div>
                                        <div class="cw-field" style="margin:0;">
                                            <label>Upload file (ZIP / PNG / JPG / PDF / AI / EPS / PSD / SVG)</label>
                                            <input type="file" name="cw_template_file"
                                                accept=".zip,.png,.jpg,.jpeg,.pdf,.ai,.eps,.psd,.svg,application/zip,application/x-zip-compressed,application/pdf,image/png,image/jpeg,image/svg+xml,application/postscript,application/illustrator,image/vnd.adobe.photoshop">
                                        </div>
                                    </div>

                                    <!-- Hidden ids let the save handler tell apart
                                         "nothing changed" from "user pressed Remove" -->
                                    <input type="hidden" name="cw_template_file_id_current" value="<?php echo (int) $cw_tpl_id; ?>">
                                    <input type="hidden" name="cw_template_remove" id="cw_template_remove_flag" value="0">

                                    <?php if ( $cw_tpl_id && $cw_tpl_url ): ?>
                                    <div class="cw-template-current" style="margin-top:10px;padding:10px 12px;border:1px solid var(--cw-border);border-radius:8px;background:var(--cw-bg);display:flex;align-items:center;gap:12px;justify-content:space-between;flex-wrap:wrap;">
                                        <div style="display:flex;align-items:center;gap:10px;min-width:0;">
                                            <i class="fas fa-paperclip" style="color:var(--cw-primary);"></i>
                                            <a href="<?php echo esc_url( $cw_tpl_url ); ?>" target="_blank" rel="noopener" style="font-weight:600;color:var(--cw-text);text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:42ch;" title="<?php echo esc_attr( $cw_tpl_name ); ?>">
                                                <?php echo esc_html( $cw_tpl_name ?: 'Current template' ); ?>
                                            </a>
                                            <?php if ( $cw_tpl_size ): ?>
                                            <small style="color:var(--cw-text-soft);font-size:11px;">(<?php echo esc_html( $cw_tpl_size ); ?>)</small>
                                            <?php endif; ?>
                                        </div>
                                        <button type="button" class="cw-template-remove-btn"
                                            onclick="document.getElementById('cw_template_remove_flag').value='1';this.closest('.cw-template-current').remove();"
                                            style="background:#fff;border:1px solid var(--cw-border);border-radius:6px;padding:6px 10px;font-size:12px;color:#94a3b8;cursor:pointer;">
                                            <i class="fas fa-trash"></i> Remove
                                        </button>
                                    </div>
                                    <small style="display:block;margin-top:6px;font-size:11px;color:var(--cw-text-soft);">
                                        Tip: upload a new file above to replace this one, or hit Remove to delete it entirely.
                                    </small>
                                    <?php else: ?>
                                    <small style="display:block;margin-top:6px;font-size:11px;color:var(--cw-text-soft);">
                                        Max ~25&nbsp;MB. The file is hosted in the WordPress media library and exposed as a direct download link on the campaign page.
                                    </small>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- ════════════ STEP 4: SDG & EXTRAS ════════════ -->
                            <div class="cw-wizard-step" data-step="4" style="display:none;">
                                <h4 class="cw-step-title">SDG Goals &amp; Extras</h4>
                                <p class="cw-step-subtitle">Select the Sustainable Development Goals your event supports, add FAQs, and optional add-ons.</p>

                                <!-- SDG icon grid (mirrors product detail page) -->
                                <div class="cw-wiz-sdg-grid">
                                    <?php foreach($this->sdg_map as $id=>$name): $is_checked=(isset($selected_sdgs_bool[$name])&&$selected_sdgs_bool[$name]==='true'); ?>
                                    <button type="button" class="cw-wiz-sdg-tile <?php echo $is_checked?'selected':''; ?>" data-id="<?php echo (int) $id; ?>" onclick="window.toggleSdg(this,<?php echo (int) $id; ?>)" aria-pressed="<?php echo $is_checked?'true':'false'; ?>">
                                        <span class="cw-wiz-sdg-thumb">
                                            <img src="<?php echo esc_url(CW_URL.'assets/img/sdg/E_WEB_'.str_pad($id,2,'0',STR_PAD_LEFT).'.png'); ?>" alt="<?php echo esc_attr('SDG '.(int) $id.' — '.$name); ?>" loading="lazy">
                                            <span class="cw-wiz-sdg-check" aria-hidden="true"><i class="fas fa-check"></i></span>
                                        </span>
                                        <span class="cw-wiz-sdg-caption"><?php echo esc_html($name); ?></span>
                                        <input type="checkbox" name="sdg_goals[]" value="<?php echo (int) $id; ?>" <?php checked($is_checked,true); ?> style="display:none;">
                                    </button>
                                    <?php endforeach; ?>
                                </div>

                                <div class="cw-toggle-box" style="margin-top:28px;">
                                    <div><label for="cw_enable_school_sponsors">Enable campaign code &amp; school sponsors</label><small>Submission codes, PIC upload links, and sponsor coupons for the claim flow.</small></div>
                                    <input type="checkbox" name="cw_enable_school_sponsors" value="yes" id="cw_enable_school_sponsors" onchange="toggleWizardSection('cw-section-school-sponsors', this)" <?php checked( $enable_school_sponsors, 'yes' ); ?>>
                                </div>
                                <div id="cw-section-school-sponsors" class="cw-wizard-feature-panel" style="display:<?php echo $enable_school_sponsors === 'yes' ? 'block' : 'none'; ?>;">
                <p class="cw-mini-head" style="margin-top:0;">Campaign serial</p>
                <div class="cw-field">
                    <label>Campaign serial (digits only, e.g. 002 or 09912)</label>
                    <input type="text" name="cw_campaign_serial" class="cw-input-dark" pattern="\d+" inputmode="numeric"
                        value="<?php echo esc_attr( $val('cw_campaign_serial') ); ?>" placeholder="002">
                </div>
                                <div class="cw-toggle-box">
                                    <div><label>School sponsor coupons optional</label><small>If yes, parents can pay full price without coupon</small></div>
                                    <input type="checkbox" name="cw_school_coupons_optional" value="yes" <?php checked( $val('cw_school_coupons_optional') ?: 'yes', 'yes' ); ?>>
                                </div>
                                <p class="cw-mini-head" style="margin-top:16px;">School sponsors (WooCommerce coupons)</p>
                                <div id="cw-school-container" class="cww-rep-school-rows">
                                    <?php $sidx = 0; foreach ( (array) $existing_schools as $s ) { if ( ! is_array( $s ) ) continue; ?>
                                    <div class="cww-rep-row cww-rep-row-school">
                                        <input type="text" class="cww-input-code" name="cw_school_sponsors[<?php echo $sidx; ?>][school_code]" value="<?php echo esc_attr( $s['school_code'] ?? '' ); ?>" placeholder="001" maxlength="3" title="School code (3 digits)">
                                        <input type="text" class="cww-input-name" name="cw_school_sponsors[<?php echo $sidx; ?>][school_name]" value="<?php echo esc_attr( $s['school_name'] ?? '' ); ?>" placeholder="School name">
                                        <input type="text" class="cww-input-coupon" name="cw_school_sponsors[<?php echo $sidx; ?>][coupon_code]" value="<?php echo esc_attr( $s['coupon_code'] ?? '' ); ?>" placeholder="Coupon code">
                                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()" aria-label="Remove school"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php $sidx++; } ?>
                                </div>
                                <button type="button" class="cww-rep-add" onclick="addSchoolRow()"><i class="fas fa-plus"></i> Add school</button>
                                </div>

                                <div class="cw-toggle-box" style="margin-top:20px;">
                                    <div><label for="cw_enable_age_brackets">Enable age categories</label><small>For claim flow and age-based pricing categories.</small></div>
                                    <input type="checkbox" name="cw_enable_age_brackets" value="yes" id="cw_enable_age_brackets" onchange="toggleWizardSection('cw-section-age-brackets', this)" <?php checked( $enable_age_brackets, 'yes' ); ?>>
                                </div>
                                <div id="cw-section-age-brackets" class="cw-wizard-feature-panel" style="display:<?php echo $enable_age_brackets === 'yes' ? 'block' : 'none'; ?>;">
                                <button type="button" class="cww-rep-add" onclick="cwLoadDefaultAgeBrackets()"><i class="fas fa-download"></i> Load default brackets</button>
                                <div id="cw-age-bracket-container" style="margin-top:10px;">
                                    <?php $abidx = 0; foreach ( (array) $existing_age_brackets as $b ) { if ( ! is_array( $b ) ) continue; ?>
                                    <div class="cww-rep-row">
                                        <input type="text" name="cw_age_brackets[<?php echo $abidx; ?>][label]" value="<?php echo esc_attr( $b['label'] ?? '' ); ?>" placeholder="Label">
                                        <input type="number" name="cw_age_brackets[<?php echo $abidx; ?>][min_age]" value="<?php echo esc_attr( $b['min_age'] ?? 0 ); ?>" placeholder="Min age" min="0">
                                        <input type="number" name="cw_age_brackets[<?php echo $abidx; ?>][max_age]" value="<?php echo esc_attr( $b['max_age'] ?? 99 ); ?>" placeholder="Max age" min="0">
                                        <input type="text" name="cw_age_brackets[<?php echo $abidx; ?>][product_cat_slug]" value="<?php echo esc_attr( $b['product_cat_slug'] ?? '' ); ?>" placeholder="Category slug">
                                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php $abidx++; } ?>
                                </div>
                                <button type="button" class="cww-rep-add" onclick="addAgeBracketRow()"><i class="fas fa-plus"></i> Add age bracket</button>
                                </div>

                                <div class="cw-toggle-box" style="margin-top:20px;">
                                    <div><label for="cw_enable_checkout_message_toggle">Enable checkout message (claim flow)</label></div>
                                    <input type="checkbox" name="cw_enable_checkout_message" value="yes" id="cw_enable_checkout_message_toggle" onchange="toggleWizardSection('cw-section-checkout-message', this)" <?php checked( $val('cw_enable_checkout_message'), 'yes' ); ?>>
                                </div>
                                <div id="cw-section-checkout-message" class="cw-wizard-feature-panel" style="display:<?php echo $val('cw_enable_checkout_message') === 'yes' ? 'block' : 'none'; ?>;">
                                <div class="cw-field">
                                    <label>Message field label</label>
                                    <input type="text" name="cw_checkout_message_label" class="cw-input-dark" value="<?php echo esc_attr( $val('cw_checkout_message_label') ); ?>" placeholder="Heartfelt message">
                                </div>
                                <label><input type="checkbox" name="cw_checkout_message_required" value="yes" <?php checked( $val('cw_checkout_message_required'), 'yes' ); ?>> Required</label>
                                </div>

                                <!-- FAQs -->
                                <p class="cw-mini-head" style="margin-top:28px;">FAQs</p>
                                <div id="cw-faq-container">
                                    <?php $idx=0; if($existing_faqs) foreach($existing_faqs as $f){ if(!is_array($f))continue; ?>
                                    <div class="cww-rep-row">
                                        <input type="text" name="cw_faq[<?php echo $idx; ?>][question]" value="<?php echo esc_attr($f['question']??''); ?>" placeholder="Question">
                                        <input type="text" name="cw_faq[<?php echo $idx; ?>][answer]" value="<?php echo esc_attr($f['answer']??''); ?>" placeholder="Answer">
                                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                                    </div>
                                    <?php $idx++; } ?>
                                </div>
                                <button type="button" class="cww-rep-add" onclick="addFaqRow()"><i class="fas fa-plus"></i> Add FAQ</button>

                                <div class="cw-toggle-box" style="margin-top:28px;">
                                    <div><label for="cw_enable_addons">Enable optional add-ons</label><small>Purchasable extras during registration (e.g. T-shirt, meals).</small></div>
                                    <input type="checkbox" name="cw_enable_addons" value="yes" id="cw_enable_addons" onchange="toggleWizardSection('cw-section-addons', this)" <?php checked( $enable_addons, 'yes' ); ?>>
                                </div>
                                <div id="cw-section-addons" class="cw-wizard-feature-panel" style="display:<?php echo $enable_addons === 'yes' ? 'block' : 'none'; ?>;margin-top:12px;">
                                <div id="cw-addon-container">
                                    <?php $idx=0; if($existing_addons) foreach($existing_addons as $a){ if(!is_array($a))continue;
                                        $atype = isset($a['addon_type']) ? strtolower(trim((string)$a['addon_type'])) : 'checkbox';
                                        if (!in_array($atype, ['checkbox','text','textarea','number','email','phone','file','media','select'], true)) $atype = 'checkbox';
                                        $alabel= $a['addon_label']??'';
                                        $aopts = $a['addon_opts']??'';
                                        $areq  = !empty($a['addon_required']) ? 'checked' : '';
                                        $show_label = ($atype!=='checkbox') ? '' : 'display:none;';
                                        $show_opts  = ($atype==='select') ? '' : 'display:none;';
                                    ?>
                                    <div class="cww-addon-row" data-idx="<?php echo $idx; ?>">
                                        <input type="text" name="cw_addons[<?php echo $idx; ?>][addon_title]" value="<?php echo esc_attr($a['addon_title']??''); ?>" placeholder="Item name (e.g. T-Shirt)">
                                        <input type="number" name="cw_addons[<?php echo $idx; ?>][addon_price]" value="<?php echo esc_attr($a['addon_price']??''); ?>" placeholder="RM" step="0.01" min="0">
                                        <select name="cw_addons[<?php echo $idx; ?>][addon_type]" onchange="toggleAddonInputs(this)">
                                            <?php echo $this->addon_type_options($atype); ?>
                                        </select>
                                        <label class="cww-req-label">
                                            <input type="checkbox" name="cw_addons[<?php echo $idx; ?>][addon_required]" value="1" <?php echo $areq; ?>> Required
                                        </label>
                                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-addon-row').nextElementSibling?.classList.contains('cww-addon-row-extra') && this.closest('.cww-addon-row').nextElementSibling.remove(); this.closest('.cww-addon-row').remove()"><i class="fas fa-times"></i></button>
                                    </div>
                                    <div class="cww-addon-row-extra" style="<?php echo ($atype==='checkbox'?'display:none;':''); ?>">
                                        <input type="text" name="cw_addons[<?php echo $idx; ?>][addon_label]" value="<?php echo esc_attr($alabel); ?>" placeholder="Input label shown to participant" style="<?php echo $show_label; ?>">
                                        <input type="text" name="cw_addons[<?php echo $idx; ?>][addon_opts]" value="<?php echo esc_attr($aopts); ?>" placeholder="Options: Option A, Option B, ..." style="<?php echo $show_opts; ?>">
                                    </div>
                                    <?php $idx++; } ?>
                                </div>
                                <button type="button" class="cww-rep-add" onclick="addAddonRow()"><i class="fas fa-plus"></i> Add Add-on</button>
                                </div>
                            </div>

                            <!-- ════════════ STEP 5: FORM BUILDER & PUBLISH ════════════ -->
                            <div class="cw-wizard-step" data-step="5" style="display:none;">
                                <h4 class="cw-step-title">Participant Form Fields</h4>
                                <p class="cw-step-subtitle">Define what information to collect from participants at registration.</p>

                                <div id="cw-step5-name-block" class="cw-config-card" style="margin-bottom:20px;display:none;">
                                    <p class="cw-mini-head" style="margin-top:0;">Participant name (certificate)</p>
                                    <p style="font-size:13px;color:var(--cw-text-soft);margin:0 0 12px;">Shown on the public registration form. Each participant name is printed on their e-certificate.</p>
                                    <label style="display:flex;align-items:flex-start;gap:8px;margin-bottom:8px;">
                                        <input type="checkbox" name="cw_use_account_fullname" value="yes" <?php checked( $use_account_fullname, 'yes' ); ?>>
                                        <span><strong>Use registrant account full name for Participant 1</strong><br><small>Prefills from signup (editable). Other participants always enter their own names.</small></span>
                                    </label>
                                </div>

                                <p class="cw-mini-head">Additional custom fields</p>
                                <div id="cw-fb-container">
                                    <?php $fid=1000; if($existing_fields) foreach($existing_fields as $f):
                                        $ftype = isset($f['type']) ? strtolower(trim((string)$f['type'])) : 'text';
                                        if (!in_array($ftype, ['text','textarea','number','email','phone','file','media','select','wysiwyg'], true)) $ftype = 'text';
                                        $fopts = $f['opts']??'';
                                        $freq  = !empty($f['required']) ? 'checked' : '';
                                        $show_opts = ($ftype==='select'||$ftype==='wysiwyg') ? '' : 'display:none;';
                                    ?>
                                    <div class="cw-fb-row-wrap">
                                        <input type="text" name="custom_fields[<?php echo $fid; ?>][label]" value="<?php echo esc_attr($f['label']); ?>" required placeholder="Field Label (e.g. Phone Number)">
                                        <select name="custom_fields[<?php echo $fid; ?>][type]" class="fb-type-select" onchange="window.toggleFbOptions(this)">
                                            <?php echo $this->field_type_options($ftype); ?>
                                        </select>
                                        <label class="cw-fb-req-label"><input type="checkbox" name="custom_fields[<?php echo $fid; ?>][required]" value="1" <?php echo $freq; ?>> Required</label>
                                        <button type="button" class="cw-fb-del" onclick="this.closest('.cw-fb-row-wrap').remove()"><i class="fas fa-times"></i></button>
                                        <div class="cw-fb-opts-wrap" style="<?php echo $show_opts; ?>">
                                            <input type="text" name="custom_fields[<?php echo $fid; ?>][opts]" value="<?php echo esc_attr($fopts); ?>" placeholder="Options: Option A, Option B, ...">
                                        </div>
                                    </div>
                                    <?php $fid++; endforeach; ?>
                                </div>
                                <button type="button" id="cw-add-field" class="cw-repeater-add"><i class="fas fa-plus"></i> Add Field</button>

                <div style="margin-top:28px; border-top:1px solid var(--cw-border); padding-top:20px;">
                    <p class="cw-mini-head" style="margin-top:0;">Certificate &amp; Publish</p>
                    <?php
                        $cert_layout_defaults = class_exists('CW_Certificate') ? CW_Certificate::default_layout() : [
                            'x_pct' => 50, 'y_pct' => 50, 'font_size' => 36, 'font_color' => '#000000', 'max_width' => 60, 'align' => 'center',
                        ];
                        $cert_x          = $val('cw_cert_x');     $cert_x          = $cert_x === '' ? 50 : (float) $cert_x;
                        $cert_y          = $val('cw_cert_y');     $cert_y          = $cert_y === '' ? 50 : (float) $cert_y;
                        $cert_font_size  = $val('cw_cert_font_size');  $cert_font_size  = $cert_font_size === '' ? 36 : (int) $cert_font_size;
                        $cert_font_color = $val('cw_cert_font_color'); $cert_font_color = $cert_font_color ?: '#000000';
                        $cert_max_width  = $val('cw_cert_max_width');  $cert_max_width  = $cert_max_width === '' ? 60 : (int) $cert_max_width;
                        $cert_align      = $val('cw_cert_align') ?: 'center';
                        $cert_template   = $val('cw_cert_template');
                    ?>
                    <div class="cw-config-card">
                        <div class="cw-toggle-box">
                            <div>
                                <label for="cw_cert_toggle" style="cursor:pointer;">Issue E-Certificates</label>
                                <small>Auto-generate participation certificates</small>
                            </div>
                            <input type="checkbox" name="cw_enable_certificate" value="yes" id="cw_cert_toggle" onclick="window.toggleCertSettings()" <?php checked($val('cw_enable_certificate'),'yes'); ?>>
                        </div>
                        <div id="cw-cert-settings" class="cw-cert-editor" style="display:none;margin-top:12px;padding-top:12px;border-top:1px solid var(--cw-border);">
                            <div class="cw-field">
                                <label>Certificate Template (PNG / JPG / PDF)</label>
                                <input type="file" name="cw_cert_template" class="cw-file-input cw-cert-file" accept="image/*,.pdf">
                                <?php if ($cert_template): ?>
                                    <small style="display:block;margin-top:4px;color:var(--cw-text-soft);">
                                        Current: <a href="<?php echo esc_url($cert_template); ?>" target="_blank" rel="noopener">view template</a>
                                    </small>
                                <?php endif; ?>
                            </div>

                            <div class="cw-cert-editor-grid">
                                <div class="cw-cert-preview-wrap">
                                    <label class="cw-cert-mini-label">Live preview <small>(drag the name to reposition)</small></label>
                                    <div class="cw-cert-preview" id="cw-cert-preview" data-template="<?php echo esc_attr($cert_template); ?>">
                                        <?php if ($cert_template): ?>
                                            <img class="cw-cert-preview-img" src="<?php echo esc_url($cert_template); ?>" alt="Certificate template preview">
                                        <?php else: ?>
                                            <div class="cw-cert-preview-placeholder">
                                                <i class="fas fa-image"></i>
                                                <p>Upload a template above to preview name placement.</p>
                                            </div>
                                        <?php endif; ?>
                                        <div class="cw-cert-name-overlay" id="cw-cert-name-overlay" style="left:<?php echo esc_attr($cert_x); ?>%;top:<?php echo esc_attr($cert_y); ?>%;font-size:<?php echo (int) $cert_font_size; ?>px;color:<?php echo esc_attr($cert_font_color); ?>;text-align:<?php echo esc_attr($cert_align); ?>;width:<?php echo (int) $cert_max_width; ?>%;">Sample Name</div>
                                    </div>
                                </div>

                                <div class="cw-cert-controls">
                                    <div class="cw-form-row-2">
                                        <div class="cw-field">
                                            <label>Name X (%)</label>
                                            <input type="number" name="cw_cert_x" id="cw_cert_x" class="cw-input-dark" min="0" max="100" step="0.1" value="<?php echo esc_attr($cert_x); ?>">
                                        </div>
                                        <div class="cw-field">
                                            <label>Name Y (%)</label>
                                            <input type="number" name="cw_cert_y" id="cw_cert_y" class="cw-input-dark" min="0" max="100" step="0.1" value="<?php echo esc_attr($cert_y); ?>">
                                        </div>
                                    </div>
                                    <div class="cw-form-row-2">
                                        <div class="cw-field">
                                            <label>Font size (px)</label>
                                            <input type="number" name="cw_cert_font_size" id="cw_cert_font_size" class="cw-input-dark" min="10" max="200" step="1" value="<?php echo (int) $cert_font_size; ?>">
                                        </div>
                                        <div class="cw-field">
                                            <label>Font color</label>
                                            <input type="color" name="cw_cert_font_color" id="cw_cert_font_color" class="cw-cert-color" value="<?php echo esc_attr($cert_font_color); ?>">
                                        </div>
                                    </div>
                                    <div class="cw-form-row-2">
                                        <div class="cw-field">
                                            <label>Max width (%)</label>
                                            <input type="number" name="cw_cert_max_width" id="cw_cert_max_width" class="cw-input-dark" min="10" max="100" step="1" value="<?php echo (int) $cert_max_width; ?>">
                                        </div>
                                        <div class="cw-field">
                                            <label>Align</label>
                                            <select name="cw_cert_align" id="cw_cert_align" class="cw-input-dark">
                                                <option value="left"   <?php selected($cert_align,'left'); ?>>Left</option>
                                                <option value="center" <?php selected($cert_align,'center'); ?>>Center</option>
                                                <option value="right"  <?php selected($cert_align,'right'); ?>>Right</option>
                                            </select>
                                        </div>
                                    </div>
                                    <p class="cw-cert-tip"><i class="fas fa-info-circle"></i> X/Y are percentages from the top-left of the template. The name overlay sits centered on that point.</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ── Public submissions gallery toggle ─────────────────── -->
                    <div class="cw-config-card" style="margin-top:16px;">
                        <div class="cw-toggle-box">
                            <div>
                                <label for="cw_show_submissions_gallery" style="cursor:pointer;">Show Public Submissions Gallery</label>
                                <small>Display approved entry artworks <strong>anonymously</strong> on this campaign's public page (image and optional checkout message only — no participant names or contact info).</small>
                            </div>
                            <input type="checkbox" name="cw_show_submissions_gallery" value="yes" id="cw_show_submissions_gallery" <?php checked( $val('cw_show_submissions_gallery'), 'yes' ); ?>>
                        </div>
                    </div>
                </div>

                                <div class="cw-publish-ready">
                                    <i class="fas fa-check-circle"></i>
                                    <div>
                                        <strong>Ready to Submit?</strong>
                                        <p style="margin:4px 0 0;font-size:13px;">Your campaign will be reviewed by an admin before going live. You'll be notified once it's approved.</p>
                                    </div>
                                </div>
                            </div>

                            <!-- ── Footer nav ── -->
                            <div class="cww-footer" id="cww-footer">
                                <span class="cww-footer-step-info" id="cww-footer-info">Step 1 of 5</span>
                                <div style="display:flex;gap:10px;align-items:center;">
                                    <button type="button" class="cw-btn-nav back" id="btn-back" onclick="window.changeStep(-1)" disabled><i class="fas fa-chevron-left"></i> Back</button>
                                    <button type="button" class="cw-btn-nav next" id="btn-next" onclick="window.changeStep(1)">Next Step <i class="fas fa-chevron-right"></i></button>
                                    <button type="submit" class="cw-btn-nav submit" id="btn-submit" style="display:none;">Submit Campaign</button>
                                </div>
                            </div>

                        </form>
                    </div><!-- .cww-scroll -->
                </div><!-- .cww-content -->
            </div><!-- .cww-body -->
        </div><!-- .cww-shell -->

        <script>
        document.addEventListener('DOMContentLoaded', function() {
            <?php
            $tree = [];
            $parents_q = get_terms(['taxonomy'=>'product_cat','parent'=>0,'hide_empty'=>false]);
            foreach ($parents_q as $p) {
                $subs = get_terms(['taxonomy'=>'product_cat','parent'=>$p->term_id,'hide_empty'=>false]);
                $child_data = [];
                foreach ($subs as $s) {
                    $child_data[] = ['id'=>$s->term_id,'name'=>$s->name,'slug'=>$s->slug];
                }
                $tree[$p->slug] = $child_data;
            }
            // Legacy alias kept so any cached front-end state from older
            // versions of the wizard still resolves; the new card uses the
            // real `talk-seminar` slug which is built from get_terms() above.
            $tree['talks'] = isset( $tree['talk-seminar'] ) ? $tree['talk-seminar'] : ( $tree['activities'] ?? [] );
            echo "const catTree = " . json_encode($tree) . ";";
            echo "const preSelectedSub = " . intval($current_cat_id) . ";";
            echo "const editMode = " . json_encode($mode === 'edit') . ";";
            ?>

            window.currentStep = 1;
            const totalSteps = 5;
            const stepLabels = <?php echo json_encode(array_map(fn($s)=>$s['label'], $this->step_labels)); ?>;

            // ── Sidebar + UI update ──
            window.updateWizardUI = function() {
                // Hide all steps
                document.querySelectorAll('.cw-wizard-step').forEach(e => e.style.display = 'none');
                const activeStep = document.querySelector(`.cw-wizard-step[data-step="${currentStep}"]`);
                if (activeStep) { activeStep.classList.add('active'); activeStep.style.display = 'block'; }

                // Sidebar states
                for (let i = 1; i <= totalSteps; i++) {
                    const item = document.getElementById('cww-step-item-' + i);
                    const numEl = document.getElementById('cww-step-num-' + i);
                    const conn  = document.getElementById('cww-conn-' + i);
                    if (!item) continue;
                    item.classList.remove('active','done');
                    if (i < currentStep) {
                        item.classList.add('done');
                        if (numEl) numEl.innerHTML = '<i class="fas fa-check"></i>';
                        if (conn) conn.classList.add('done');
                    } else if (i === currentStep) {
                        item.classList.add('active');
                        if (numEl) numEl.textContent = i;
                        if (conn) conn.classList.remove('done');
                    } else {
                        if (numEl) numEl.textContent = i;
                        if (conn) conn.classList.remove('done');
                    }
                }

                // Footer info
                const footerInfo = document.getElementById('cww-footer-info');
                if (footerInfo) footerInfo.textContent = `Step ${currentStep} of ${totalSteps}`;

                // Buttons
                const btnBack   = document.getElementById('btn-back');
                const btnNext   = document.getElementById('btn-next');
                const btnSubmit = document.getElementById('btn-submit');
                if (btnBack)   btnBack.disabled = (currentStep === 1);
                if (btnNext && btnSubmit) {
                    if (currentStep === totalSteps) {
                        btnNext.style.display   = 'none';
                        btnSubmit.style.display = 'inline-flex';
                        btnSubmit.textContent   = editMode ? 'Save Changes' : 'Submit Campaign';
                    } else {
                        btnNext.style.display   = 'inline-flex';
                        btnSubmit.style.display = 'none';
                    }
                }

                window.handleConditionalFields();
            };

            window.changeStep = function(dir) {
                if (dir === 1 && !window.validateStep(currentStep)) return;
                const cur = document.querySelector(`.cw-wizard-step[data-step="${currentStep}"]`);
                if (cur) cur.style.display = 'none';
                currentStep += dir;
                // Scroll content back to top
                const scroll = document.getElementById('cww-scroll');
                if (scroll) scroll.scrollTop = 0;
                window.updateWizardUI();
            };

            window.selectMainCat = function(el, type) {
                document.querySelectorAll('.cw-cat-card').forEach(c => c.classList.remove('selected'));
                el.classList.add('selected');
                document.getElementById('cw_main_category_slug').value = type;
                const sub = document.getElementById('cw_sub_cat');
                sub.innerHTML = '<option value="">Select Sub Category...</option>';
                sub.disabled = false;

                // Resolve which parent's children to show. `talk-seminar` and
                // `competitions` map 1:1 to their parent terms in catTree.
                // Legacy callers may still pass `talks` — treat it as a
                // synonym for `talk-seminar` to keep old links working.
                let lookup = type;
                if (type === 'talks') {
                    lookup = catTree['talk-seminar'] ? 'talk-seminar' : 'activities';
                }

                if (catTree[lookup]) {
                    catTree[lookup].forEach(c => {
                        // Only filter the Activities list — strip out talk /
                        // seminar / workshop entries that bled into Activities
                        // historically. Talk/Seminar parent already only
                        // contains its own children, so no filtering needed.
                        if (lookup === 'activities' && ['talk','seminar','workshop'].some(s => c.slug.includes(s))) return;
                        let opt = document.createElement('option');
                        opt.value = c.id; opt.setAttribute('data-slug', c.slug); opt.text = c.name;
                        sub.appendChild(opt);
                    });
                }
                sub.value = '';
                window.updateWizardUI();
            };

            window.handleConditionalFields = function() {
                const type = document.getElementById('cw_main_category_slug').value;
                const sub  = document.getElementById('cw_sub_cat');
                const slug = sub.options[sub.selectedIndex]?.getAttribute('data-slug') || '';

                // Treat both the canonical `talk-seminar` slug and the legacy
                // `talks` alias as the same non-judged campaign type.
                const isTalk     = (type === 'talk-seminar' || type === 'talks');
                const isActivity = (type === 'activities' || isTalk);

                document.querySelectorAll('#cw-conditional-section,#set-competition,#set-activity,#set-talk,#set-design').forEach(e => e.style.display = 'none');

                if (type === 'competitions' || slug.includes('art') || slug.includes('design')) {
                    document.getElementById('cw-conditional-section').style.display = 'block';
                    document.getElementById('set-competition').style.display = 'block';
                    // Reveal the Design block only when the sub-category itself is design-flavoured.
                    if (slug.includes('design')) {
                        var setDesign = document.getElementById('set-design');
                        if (setDesign) setDesign.style.display = 'block';
                    }
                } else if (isActivity) {
                    document.getElementById('cw-conditional-section').style.display = 'block';
                    document.getElementById('set-activity').style.display = 'block';
                    if (isTalk || slug.includes('talk') || slug.includes('seminar') || slug.includes('workshop')) {
                        document.getElementById('set-talk').style.display = 'block';
                    }
                }

                const nameBlock = document.getElementById('cw-step5-name-block');
                if (nameBlock) {
                    nameBlock.style.display = isActivity ? 'block' : 'none';
                }

                window.toggleMultiLimits();
                window.toggleParticipantCapacity();
                window.toggleOnlineLink();
                window.toggleCertSettings();
            };

            window.validateStep = function(step) {
                if (step === 1 && !document.getElementById('cw_sub_cat').value) {
                    alert('Please select a Sub Category.');
                    return false;
                }
                if (step === 2) {
                    const reqs = document.querySelectorAll('.cw-wizard-step[data-step="2"] [required]');
                    for (let r of reqs) {
                        if (r.id !== 'post_content' && !r.value) {
                            alert('Please fill all required fields in Basic Details.');
                            return false;
                        }
                    }
                }
                return true;
            };

            // ── Toggles ──
            window.toggleSdg = function(btn, id) {
                const isChecked = btn.classList.toggle('selected');
                const cb = btn.querySelector('input[type=checkbox]');
                if (cb) cb.checked = isChecked;
                btn.setAttribute('aria-pressed', isChecked ? 'true' : 'false');
            };

            window.toggleMultiLimits = function() {
                const chk = document.getElementById('cw_multi_check');
                const box = document.getElementById('cw-multi-limits');
                if (box) box.style.display = (chk && chk.checked) ? 'grid' : 'none';
            };

            window.toggleParticipantCapacity = function() {
                const chk = document.getElementById('cw_allow_multi_participants');
                const box = document.getElementById('cw-participant-capacity');
                if (box) box.style.display = (chk && chk.checked) ? 'grid' : 'none';
            };

            window.toggleWizardSection = function(panelId, checkbox) {
                const panel = document.getElementById(panelId);
                if (panel) panel.style.display = (checkbox && checkbox.checked) ? 'block' : 'none';
            };

            window.toggleCertSettings = function() {
                const box = document.getElementById('cw-cert-settings');
                const tog = document.getElementById('cw_cert_toggle');
                if (box && tog) box.style.display = tog.checked ? 'block' : 'none';
            };

            window.toggleOnlineLink = function() {
                const isOnline = document.getElementById('mode_online')?.checked;
                const loc  = document.getElementById('cw_location_details');
                const link = document.getElementById('cw_online_link');
                const icon = document.getElementById('cw_location_icon');
                const hint = document.getElementById('cw_online_link_hint');
                if (isOnline) {
                    if (loc)  { loc.style.display  = 'none';  loc.removeAttribute('required'); }
                    if (link) { link.style.display  = 'block'; }
                    if (icon) icon.className = 'fas fa-globe';
                    if (hint) hint.style.display = 'block';
                } else {
                    if (loc)  { loc.style.display  = 'block'; loc.setAttribute('required','required'); }
                    if (link) { link.style.display  = 'none'; }
                    if (icon) icon.className = 'fas fa-map-marker-alt';
                    if (hint) hint.style.display = 'none';
                }
            };

            window.toggleFbOptions = function(s) {
                const wrap = s.closest('.cw-fb-row-wrap');
                if (!wrap) return;
                const optsWrap = wrap.querySelector('.cw-fb-opts-wrap');
                if (optsWrap) optsWrap.style.display = (s.value === 'select' || s.value === 'wysiwyg') ? 'block' : 'none';
            };

            window.cwPreviewBanner = function(input) {
                const preview = document.getElementById('cwBannerPreview');
                if (input.files && input.files[0] && preview) {
                    preview.src = URL.createObjectURL(input.files[0]);
                    preview.style.display = 'block';
                }
            };

            // ── Gallery uploader ──
            window.cwPreviewGallery = function(input) {
                const grid = document.getElementById('cwGalleryGrid');
                if (!grid || !input.files || !input.files.length) return;
                Array.from(input.files).forEach(function(file) {
                    if (!file.type || file.type.indexOf('image/') !== 0) return;
                    const url = URL.createObjectURL(file);
                    const tile = document.createElement('div');
                    tile.className = 'cw-gallery-tile is-new';
                    tile.innerHTML =
                        '<img src="' + url + '" alt="" />' +
                        '<span class="cw-gallery-new-flag" aria-hidden="true">NEW</span>';
                    grid.appendChild(tile);
                });
            };
            window.cwGalleryRemoveExisting = function(btn, attachmentId) {
                const tile  = btn.closest('.cw-gallery-tile');
                const keep  = document.getElementById('cwGalleryKeep');
                if (tile) tile.remove();
                if (keep) {
                    const list = keep.value.split(',').filter(function(v) {
                        return v && parseInt(v, 10) !== parseInt(attachmentId, 10);
                    });
                    keep.value = list.join(',');
                }
            };

            // ── Design Submission variants ──
            window.addDesignVariantRow = function() {
                var list = document.getElementById('cw-design-variants-list');
                if (!list) return;
                var idx = Date.now();
                var html =
                  '<div class="cww-rep-row cw-design-variant-row" data-idx="' + idx + '" style="grid-template-columns: 80px 1fr 1fr auto auto;align-items:center;">' +
                    '<div class="cw-design-variant-thumb" style="width:70px;height:46px;background:#f1f5f9;border:1px dashed #cbd5e1;border-radius:6px;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:10px;text-align:center;overflow:hidden;">No image</div>' +
                    '<input type="text" name="cw_design_variants[' + idx + '][name]" placeholder="Variant name (e.g. Midnight Blue)" class="cw-design-variant-name">' +
                    '<div>' +
                      '<input type="file" accept="image/png,image/jpeg,image/webp" class="cw-file-upload-input cw-design-variant-file" data-session-key="cw_design_wizard_variant_' + idx + '" style="width:100%;font-size:12px;">' +
                      '<input type="hidden" name="cw_design_variants[' + idx + '][attachment_id]" value="" class="cw-design-variant-aid">' +
                      '<input type="hidden" name="cw_design_variants[' + idx + '][slug]" value="" class="cw-design-variant-slug">' +
                    '</div>' +
                    '<label style="text-align:center;font-size:11px;color:var(--cw-text-soft);">' +
                      '<input type="radio" name="cw_design_default_variant" value="" class="cw-design-variant-default-radio">' +
                      '<br>Default' +
                    '</label>' +
                    '<button type="button" class="cww-rep-del cw-design-variant-remove" onclick="this.closest(\'.cw-design-variant-row\').remove()"><i class="fas fa-times"></i></button>' +
                  '</div>';
                list.insertAdjacentHTML('beforeend', html);
            };

            // Auto-slug from name + sync the "Default" radio's value so the
            // posted value matches the (auto-generated) slug.
            document.addEventListener('input', function(e) {
                if (!e.target.classList || !e.target.classList.contains('cw-design-variant-name')) return;
                var row = e.target.closest('.cw-design-variant-row');
                if (!row) return;
                var slug = e.target.value.toString().toLowerCase()
                    .replace(/[^a-z0-9]+/g, '-')
                    .replace(/^-+|-+$/g, '');
                var slugInput = row.querySelector('.cw-design-variant-slug');
                var radio     = row.querySelector('.cw-design-variant-default-radio');
                if (slugInput) slugInput.value = slug;
                if (radio) radio.value = slug;
            });

            // Variant file upload (re-uses the cw_file_upload AJAX endpoint
            // that's already on the AJAX surface — same pattern as the
            // banner / participant-field uploads).
            jQuery(document).on('change', '.cw-design-variant-file', function(e) {
                var fileInput = jQuery(this);
                var file = fileInput[0].files[0];
                if (!file) return;
                var sessionKey = fileInput.data('session-key') || ('cw_design_wizard_variant_' + Date.now());
                var fd = new FormData();
                fd.append('action', 'cw_file_upload');
                fd.append('security', cw_vars.nonce);
                fd.append('file_data', file);
                fd.append('session_key', sessionKey);
                var row = fileInput.closest('.cw-design-variant-row');
                var aidInput = row.find('.cw-design-variant-aid');
                var thumb    = row.find('.cw-design-variant-thumb');
                thumb.html('<i class="fas fa-spinner fa-spin" style="color:#94a3b8;"></i>');

                jQuery.ajax({
                    url: cw_vars.ajax_url, type: 'POST', data: fd, processData: false, contentType: false,
                    success: function(res) {
                        if (res && res.success && res.data && res.data.attach_id) {
                            aidInput.val(res.data.attach_id);
                            thumb.css({ background: '#fff', border: '1px solid #cbd5e1' })
                                 .html('<img src="' + res.data.url + '" style="max-width:100%;max-height:100%;object-fit:contain;">');
                        } else {
                            thumb.html('<span style="color:#b91c1c;font-size:10px;">Failed</span>');
                        }
                    },
                    error: function() {
                        thumb.html('<span style="color:#b91c1c;font-size:10px;">Failed</span>');
                    }
                });
            });

            // ── Repeaters ──
            window.addPrizeRow = function() {
                const id = Date.now();
                document.getElementById('cw-prize-container').insertAdjacentHTML('beforeend',
                    `<div class="cww-rep-row cww-rep-row-prize">
                        <input type="text" name="cw_prizes[${id}][prize_title]" placeholder="Prize Title (e.g. Champion)">
                        <input type="text" name="cw_prizes[${id}][prize_category]" placeholder="Category (e.g. Primary)" list="cw-prize-categories-dl">
                        <select name="cw_prizes[${id}][prize_position]" class="cww-rep-pos">
                            <option value="">— Position —</option>
                            <option value="champion">Champion (Trophy)</option>
                            <option value="runner_up_1">1st Runner-Up (Ribbon 2)</option>
                            <option value="runner_up_2">2nd Runner-Up (Ribbon 3)</option>
                            <option value="honorable">Honorable Mention</option>
                            <option value="participation">Participation</option>
                            <option value="custom">Other / Custom</option>
                        </select>
                        <input type="text" name="cw_prizes[${id}][prize_description]" placeholder="Description (e.g. RM 1,000 cash)">
                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                    </div>`
                );
            };

            window.addFaqRow = function() {
                const id = Date.now();
                document.getElementById('cw-faq-container').insertAdjacentHTML('beforeend',
                    `<div class="cww-rep-row">
                        <input type="text" name="cw_faq[${id}][question]" placeholder="Question">
                        <input type="text" name="cw_faq[${id}][answer]" placeholder="Answer">
                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                    </div>`
                );
            };

            window.defaultAgeBrackets = <?php echo wp_json_encode( get_option( 'cw_default_age_brackets', [] ) ); ?>;

            window.addAgeBracketRow = function() {
                const id = Date.now();
                document.getElementById('cw-age-bracket-container').insertAdjacentHTML('beforeend',
                    `<div class="cww-rep-row">
                        <input type="text" name="cw_age_brackets[${id}][label]" placeholder="Label">
                        <input type="number" name="cw_age_brackets[${id}][min_age]" placeholder="Min age" min="0">
                        <input type="number" name="cw_age_brackets[${id}][max_age]" placeholder="Max age" min="0">
                        <input type="text" name="cw_age_brackets[${id}][product_cat_slug]" placeholder="Category slug">
                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                    </div>`
                );
            };
            window.cwLoadDefaultAgeBrackets = function() {
                const c = document.getElementById('cw-age-bracket-container');
                c.innerHTML = '';
                (window.defaultAgeBrackets || []).forEach((b, i) => {
                    c.insertAdjacentHTML('beforeend',
                        `<div class="cww-rep-row">
                            <input type="text" name="cw_age_brackets[${i}][label]" value="${b.label||''}" placeholder="Label">
                            <input type="number" name="cw_age_brackets[${i}][min_age]" value="${b.min_age||0}" placeholder="Min age">
                            <input type="number" name="cw_age_brackets[${i}][max_age]" value="${b.max_age||99}" placeholder="Max age">
                            <input type="text" name="cw_age_brackets[${i}][product_cat_slug]" value="${b.product_cat_slug||''}" placeholder="Category slug">
                            <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()"><i class="fas fa-times"></i></button>
                        </div>`
                    );
                });
            };
            window.addSchoolRow = function() {
                const id = Date.now();
                document.getElementById('cw-school-container').insertAdjacentHTML('beforeend',
                    `<div class="cww-rep-row cww-rep-row-school">
                        <input type="text" class="cww-input-code" name="cw_school_sponsors[${id}][school_code]" placeholder="001" maxlength="3" title="School code (3 digits)">
                        <input type="text" class="cww-input-name" name="cw_school_sponsors[${id}][school_name]" placeholder="School name">
                        <input type="text" class="cww-input-coupon" name="cw_school_sponsors[${id}][coupon_code]" placeholder="Coupon code">
                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-rep-row').remove()" aria-label="Remove school"><i class="fas fa-times"></i></button>
                    </div>`
                );
            };

            window.addAddonRow = function() {
                const id = Date.now();
                const typeOptions = `
                    <option value="checkbox">Checkbox (Yes/No)</option>
                    <option value="text">Short Text</option>
                    <option value="textarea">Long Text / Paragraph</option>
                    <option value="number">Number</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone / Tel</option>
                    <option value="file">Document Upload</option>
                    <option value="media">Image Upload</option>
                    <option value="select">Dropdown</option>`;
                const container = document.getElementById('cw-addon-container');
                container.insertAdjacentHTML('beforeend',
                    `<div class="cww-addon-row" data-idx="${id}">
                        <input type="text" name="cw_addons[${id}][addon_title]" placeholder="Item name (e.g. T-Shirt)">
                        <input type="number" name="cw_addons[${id}][addon_price]" placeholder="RM" step="0.01" min="0">
                        <select name="cw_addons[${id}][addon_type]" onchange="toggleAddonInputs(this)">${typeOptions}</select>
                        <label class="cww-req-label"><input type="checkbox" name="cw_addons[${id}][addon_required]" value="1"> Required</label>
                        <button type="button" class="cww-rep-del" onclick="this.closest('.cww-addon-row').nextElementSibling?.classList.contains('cww-addon-row-extra') && this.closest('.cww-addon-row').nextElementSibling.remove(); this.closest('.cww-addon-row').remove()"><i class="fas fa-times"></i></button>
                    </div>
                    <div class="cww-addon-row-extra" style="display:none;">
                        <input type="text" name="cw_addons[${id}][addon_label]" placeholder="Input label shown to participant" style="display:none;">
                        <input type="text" name="cw_addons[${id}][addon_opts]"  placeholder="Options: Option A, Option B, ..." style="display:none;">
                    </div>`
                );
            };

            window.toggleAddonInputs = function(sel) {
                const row   = sel.closest('.cww-addon-row');
                const extra = row?.nextElementSibling;
                if (!extra || !extra.classList.contains('cww-addon-row-extra')) return;

                const type      = sel.value;
                const labelInp  = extra.querySelector('input:first-child');
                const optsInp   = extra.querySelector('input:last-child');

                if (type === 'checkbox') {
                    extra.style.display = 'none';
                } else {
                    extra.style.display = 'grid';
                    if (labelInp) labelInp.style.display = 'block';
                    if (optsInp)  optsInp.style.display  = (type === 'select') ? 'block' : 'none';
                }
            };

            // ── Add Field button ──
            jQuery(document).ready(function($) {
                const fieldTypeOptions = `
                    <option value="text">Short Text</option>
                    <option value="textarea">Long Text / Paragraph</option>
                    <option value="number">Number</option>
                    <option value="email">Email</option>
                    <option value="phone">Phone / Tel</option>
                    <option value="file">Document Upload (PDF/Doc)</option>
                    <option value="media">Image Upload (JPG/PNG)</option>
                    <option value="select">Dropdown</option>
                    <option value="wysiwyg">Rich Text Area</option>`;

                $('#cw-add-field').on('click', function() {
                    const id = Date.now();
                    document.getElementById('cw-fb-container').insertAdjacentHTML('beforeend',
                        `<div class="cw-fb-row-wrap">
                            <input type="text" name="custom_fields[${id}][label]" placeholder="Field Label (e.g. Phone Number)" required>
                            <select name="custom_fields[${id}][type]" class="fb-type-select" onchange="window.toggleFbOptions(this)">${fieldTypeOptions}</select>
                            <label class="cw-fb-req-label"><input type="checkbox" name="custom_fields[${id}][required]" value="1"> Required</label>
                            <button type="button" class="cw-fb-del" onclick="this.closest('.cw-fb-row-wrap').remove()"><i class="fas fa-times"></i></button>
                            <div class="cw-fb-opts-wrap" style="display:none;">
                                <input type="text" name="custom_fields[${id}][opts]" placeholder="Options: Option A, Option B, ...">
                            </div>
                        </div>`
                    );
                });

                // Sync option rows + addon extra rows with saved field types (fixes wrong dropdown state on load)
                document.querySelectorAll('#cw-fb-container .fb-type-select').forEach(function(sel) { window.toggleFbOptions(sel); });
                document.querySelectorAll('#cw-addon-container .cww-addon-row select[name*="[addon_type]"]').forEach(function(sel) { window.toggleAddonInputs(sel); });

                // Init edit mode
                if (editMode && preSelectedSub > 0) {
                    const mainSlug = document.getElementById('cw_main_category_slug').value;
                    const sub = document.getElementById('cw_sub_cat');
                    if (catTree[mainSlug]) {
                        catTree[mainSlug].forEach(c => {
                            let opt = document.createElement('option');
                            opt.value = c.id; opt.setAttribute('data-slug', c.slug); opt.text = c.name;
                            if (c.id == preSelectedSub) opt.selected = true;
                            sub.appendChild(opt);
                        });
                        sub.disabled = false;
                        $(`.cw-cat-card[data-type="${mainSlug}"]`).addClass('selected');
                    }
                }

                window.currentStep = 1;
                window.updateWizardUI();
            });

            // ── Certificate editor (preview + drag + live binding) ──
            (function() {
                const fileInput  = document.querySelector('.cw-cert-file');
                const previewEl  = document.getElementById('cw-cert-preview');
                const overlay    = document.getElementById('cw-cert-name-overlay');
                if (!previewEl || !overlay) return;

                const xInput     = document.getElementById('cw_cert_x');
                const yInput     = document.getElementById('cw_cert_y');
                const sizeInput  = document.getElementById('cw_cert_font_size');
                const colorInput = document.getElementById('cw_cert_font_color');
                const widthInput = document.getElementById('cw_cert_max_width');
                const alignInput = document.getElementById('cw_cert_align');

                function clamp(v, lo, hi) { return Math.max(lo, Math.min(hi, v)); }

                function applyOverlayFromInputs() {
                    if (xInput)     overlay.style.left = clamp(parseFloat(xInput.value) || 0, 0, 100) + '%';
                    if (yInput)     overlay.style.top  = clamp(parseFloat(yInput.value) || 0, 0, 100) + '%';
                    if (sizeInput)  overlay.style.fontSize  = (parseInt(sizeInput.value, 10) || 36) + 'px';
                    if (colorInput) overlay.style.color     = colorInput.value || '#000';
                    if (widthInput) overlay.style.width     = clamp(parseInt(widthInput.value, 10) || 60, 10, 100) + '%';
                    if (alignInput) overlay.style.textAlign = alignInput.value || 'center';
                }

                [xInput, yInput, sizeInput, colorInput, widthInput, alignInput].forEach(function(el) {
                    if (el) el.addEventListener('input', applyOverlayFromInputs);
                });

                if (fileInput) {
                    fileInput.addEventListener('change', function() {
                        if (!fileInput.files || !fileInput.files[0]) return;
                        const file = fileInput.files[0];
                        if (!file.type || file.type.indexOf('image/') !== 0) {
                            // PDF or other non-image: just hide the placeholder and let the
                            // user trust the position numbers — preview only works for images.
                            return;
                        }
                        const url = URL.createObjectURL(file);
                        let img = previewEl.querySelector('img.cw-cert-preview-img');
                        const placeholder = previewEl.querySelector('.cw-cert-preview-placeholder');
                        if (placeholder) placeholder.remove();
                        if (!img) {
                            img = document.createElement('img');
                            img.className = 'cw-cert-preview-img';
                            previewEl.insertBefore(img, overlay);
                        }
                        img.src = url;
                    });
                }

                let dragging = false;
                function pointerXYToPercent(clientX, clientY) {
                    const rect = previewEl.getBoundingClientRect();
                    if (!rect.width || !rect.height) return null;
                    const x = clamp(((clientX - rect.left) / rect.width)  * 100, 0, 100);
                    const y = clamp(((clientY - rect.top)  / rect.height) * 100, 0, 100);
                    return { x: x, y: y };
                }

                function onMove(ev) {
                    if (!dragging) return;
                    const point = ev.touches && ev.touches[0]
                        ? pointerXYToPercent(ev.touches[0].clientX, ev.touches[0].clientY)
                        : pointerXYToPercent(ev.clientX, ev.clientY);
                    if (!point) return;
                    if (xInput) xInput.value = point.x.toFixed(1);
                    if (yInput) yInput.value = point.y.toFixed(1);
                    applyOverlayFromInputs();
                    if (ev.cancelable) ev.preventDefault();
                }

                function endDrag() {
                    dragging = false;
                    document.body.classList.remove('cw-cert-dragging');
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', endDrag);
                    document.removeEventListener('touchmove', onMove);
                    document.removeEventListener('touchend', endDrag);
                }

                overlay.addEventListener('mousedown', function(ev) {
                    dragging = true;
                    document.body.classList.add('cw-cert-dragging');
                    document.addEventListener('mousemove', onMove);
                    document.addEventListener('mouseup', endDrag);
                    ev.preventDefault();
                });
                overlay.addEventListener('touchstart', function(ev) {
                    dragging = true;
                    document.body.classList.add('cw-cert-dragging');
                    document.addEventListener('touchmove', onMove, { passive: false });
                    document.addEventListener('touchend', endDrag);
                }, { passive: true });

                applyOverlayFromInputs();
            })();

        }); // end DOMContentLoaded
        </script>
        <?php
        return ob_get_clean();
    }
}
