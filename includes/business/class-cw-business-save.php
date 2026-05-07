<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// CRITICAL FIX: Ensure essential WordPress functions are available for early calls (like wp_kses_post).
if ( ! function_exists( 'wp_kses_post' ) ) {
    require_once(ABSPATH . 'wp-includes/kses.php');
}


class CW_Business_Save {

    private $sdg_map;

    public function __construct() {
        // Access shared SDG map from Controller
        if (class_exists('CW_Business')) {
            $this->sdg_map = CW_Business::get_sdg_map();
        } else {
            $this->sdg_map = [1 => 'No Poverty'];
        }
        
        add_action('admin_post_cw_create_campaign', [ $this, 'handle_submission' ]);
        add_action('admin_post_cw_update_campaign', [ $this, 'handle_update' ]);
        add_action('admin_post_cw_save_biz_info', [ $this, 'handle_save_biz_info' ]);
    }

    public function handle_submission() {
        if (!isset($_POST['cw_nonce']) || !wp_verify_nonce($_POST['cw_nonce'], 'cw_create_campaign_nonce')) {
            wp_die('Security check failed.');
        }
        $this->save_campaign(0);
    }

    public function handle_update() {
        if (!isset($_POST['cw_nonce']) || !wp_verify_nonce($_POST['cw_nonce'], 'cw_update_campaign_nonce')) {
            wp_die('Security check failed.');
        }
        $pid = intval($_POST['campaign_id']);
        if (get_post_field('post_author', $pid) == get_current_user_id() || current_user_can('administrator')) {
            $this->save_campaign($pid);
        } else {
            wp_die('Unauthorized');
        }
    }

    private function save_campaign($pid) {
        if(!is_user_logged_in()) wp_die('Error');
        $uid = get_current_user_id();
        
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once(ABSPATH.'wp-admin/includes/image.php'); 
            require_once(ABSPATH.'wp-admin/includes/file.php'); 
            require_once(ABSPATH.'wp-admin/includes/media.php');
        }
        
        // --- 1. Basic Post Data ---
        $title = sanitize_text_field($_POST['post_title']);
        $content = wp_kses_post($_POST['post_content']); 
        $args = ['post_title'=>$title, 'post_content'=>$content, 'post_type'=>'product', 'post_author'=>$uid];
        
        $visibility = isset($_POST['cw_visibility']) ? sanitize_text_field($_POST['cw_visibility']) : 'visible';
        
        $is_new_campaign = ($pid === 0);
        if($is_new_campaign) { 
            $args['post_status']='draft'; 
            $pid=wp_insert_post($args); 
        } else { 
            $args['ID']=$pid; 
            wp_update_post($args); 
        }
        
        if (is_wp_error($pid)) wp_die('Error saving post: ' . $pid->get_error_message());


        // --- 2. Taxonomy & Meta Updates ---
        if(!empty($_POST['product_cat'])) wp_set_object_terms($pid, intval($_POST['product_cat']), 'product_cat');
        
        // Handle Visibility
        if ($visibility === 'hidden') wp_set_object_terms($pid, ['exclude-from-catalog', 'exclude-from-search'], 'product_visibility'); 
        else wp_set_object_terms($pid, [], 'product_visibility');
        
        $price = sanitize_text_field($_POST['regular_price']);
        update_post_meta($pid, '_regular_price', $price); 
        update_post_meta($pid, '_price', $price); 
        update_post_meta($pid, '_virtual', 'yes'); 
        update_post_meta($pid, 'organizer_id', $uid);

        foreach(['submission_deadline','cw_total_prize_value','cw_min_participants','cw_max_participants',
                 'cw_submission_start','cw_review_start','cw_final_event_date','cw_location_details','cw_enable_certificate','cw_judging_criteria',
                 'cw_talk_speaker','cw_talk_type','cw_cert_x','cw_cert_y', 'cw_event_mode', 'cw_online_link', 'cw_multi_min', 'cw_multi_max'] as $k) {
            if(isset($_POST[$k])) update_post_meta($pid, $k, sanitize_text_field($_POST[$k]));
        }
        // Checkboxes: must explicitly save 'no'/'false' when unchecked (not present in POST)
        update_post_meta($pid, 'cw_enable_voting',    isset($_POST['cw_enable_voting'])    ? 'yes'  : 'no');
        update_post_meta($pid, 'multiple_submissions', isset($_POST['multiple_submissions']) ? 'true' : 'false');

        // --- 3. REPEATER SAVES ---
        if(isset($_POST['cw_faq'])) { $f = []; $i=0; foreach((array)$_POST['cw_faq'] as $row) { if(isset($row['question']) && $row['question']) { $f['item-'.$i] = $row; $i++; } } update_post_meta($pid, 'faq', $f); }
        if(isset($_POST['cw_prizes'])) { $p = []; $i=0; foreach((array)$_POST['cw_prizes'] as $row) { if(isset($row['prize_title']) && $row['prize_title']) { $p['item-'.$i] = $row; $i++; } } update_post_meta($pid, 'prizes', $p); }
        
        // Save Addons
        if(isset($_POST['cw_addons'])) { 
            $allowed_at = ['checkbox','text','textarea','number','email','phone','file','media','select'];
            $a = []; $i=0; 
            foreach((array)$_POST['cw_addons'] as $row) { 
                if(isset($row['addon_title']) && $row['addon_title']) { 
                    $at = strtolower(trim(sanitize_text_field($row['addon_type'] ?? 'checkbox')));
                    if (!in_array($at, $allowed_at, true)) $at = 'checkbox';
                    $row['addon_type'] = $at;
                    $row['addon_label'] = sanitize_text_field($row['addon_label']); 
                    $row['addon_opts'] = sanitize_text_field($row['addon_opts']); 
                    $a['item-'.$i] = $row; 
                    $i++; 
                } 
            } 
            update_post_meta($pid, 'addon_products', $a); 
        }

        if(isset($_POST['sdg_goals'])) {
            $selected_ids = (array)$_POST['sdg_goals']; $sdg_bool_map = [];
            foreach($this->sdg_map as $id => $name) { $sdg_bool_map[$name] = in_array($id, $selected_ids) ? 'true' : 'false'; }
            update_post_meta($pid, 'sdg_goals', $sdg_bool_map);
        }

        if(isset($_POST['custom_fields'])){
            $allowed_cf = ['text','textarea','number','email','phone','file','media','select','wysiwyg'];
            $fields=[]; foreach((array)$_POST['custom_fields'] as $key => $f) {
                if(!empty($f['label'])) {
                    $t = strtolower(trim(sanitize_text_field($f['type'] ?? 'text')));
                    if (!in_array($t, $allowed_cf, true)) $t = 'text';
                    $fields[] = [
                        'label'    => sanitize_text_field($f['label']),
                        'type'     => $t,
                        'opts'     => isset($f['opts']) ? sanitize_text_field($f['opts']) : '',
                        'required' => !empty($f['required']) ? 1 : 0,
                    ];
                }
            }
            update_post_meta($pid, 'cw_custom_fields', array_values($fields));
        }

        // --- 4. File Uploads (Functions already loaded above) ---
        if(!empty($_FILES['campaign_image']['name'])) {
            $aid = media_handle_upload('campaign_image', $pid); 
            if(!is_wp_error($aid)) set_post_thumbnail($pid, $aid);
        }
        if(!empty($_FILES['cw_cert_template']['name'])) {
            $cid = media_handle_upload('cw_cert_template', $pid); 
            if(!is_wp_error($cid)) update_post_meta($pid, 'cw_cert_template', wp_get_attachment_url($cid));
        }
        
        // --- 5. REDIRECT & FLASH MESSAGE ---
        $message = $is_new_campaign ? 'New Campaign created successfully!' : 'Campaign updated successfully!';
        set_transient( 'cw_popup_msg_uid_' . $uid, $message, 60 ); 
        set_transient( 'cw_popup_type_uid_' . $uid, 'success', 60 );

        $my_account_page_url = get_permalink( wc_get_page_id( 'myaccount' ) );
        $target_url = add_query_arg( 'tab', 'campaigns', $my_account_page_url );

        wp_safe_redirect($target_url);
        exit;
    }
    
    public function handle_save_biz_info() {
        if(!is_user_logged_in() || !wp_verify_nonce($_POST['_wpnonce'], 'cw_biz_info_nonce')) wp_die('Security Error');
        
        $uid = get_current_user_id();
        
        // CRITICAL FIX: Load media dependencies for logo upload
        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once(ABSPATH.'wp-admin/includes/image.php'); 
            require_once(ABSPATH.'wp-admin/includes/file.php'); 
            require_once(ABSPATH.'wp-admin/includes/media.php');
        }
        
        $fields = ['business_name', 'business_phone', 'business_address', 'business_website', 'business_ssm'];
        
        foreach ( $fields as $f ) { 
            if ( isset( $_POST[$f] ) ) update_user_meta( $uid, $f, sanitize_text_field( $_POST[$f] ) ); 
        }
        
        if(!empty($_FILES['business_logo']['name'])) {
            $lid = media_handle_upload('business_logo', 0);
            if(!is_wp_error($lid)) update_user_meta($uid, 'business_logo', ['id'=>$lid, 'url'=>wp_get_attachment_url($lid)]);
        }
        
        // --- FIXED REDIRECT (Returns to previous page) ---
        set_transient( 'cw_popup_msg_uid_' . $uid, 'Profile updated successfully.', 60 );
        set_transient( 'cw_popup_type_uid_' . $uid, 'success', 60 );
        
        $url = wp_get_referer();
        if(!$url) $url = home_url('/my-account/');
        
        $url = remove_query_arg(['updated'], $url); 
        
        wp_safe_redirect($url); 
        exit;
    }
}