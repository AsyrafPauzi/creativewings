<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Ajax {

    public function __construct() {
        // 1. Save Competition (User Dashboard)
        add_action('wp_ajax_handle_save_competition', [ $this, 'handle_save_competition' ]);

        // 2. Add-ons Session Storage (If needed for future complex add-ons)
        add_action('wp_ajax_save_addon_data', [ $this, 'save_addon_data' ]);
        add_action('wp_ajax_nopriv_save_addon_data', [ $this, 'save_addon_data' ]);

        // 3. Checkout Quantity Update
        add_action('wp_ajax_update_checkout_quantity', [ $this, 'update_checkout_quantity' ]);
        add_action('wp_ajax_nopriv_update_checkout_quantity', [ $this, 'update_checkout_quantity' ]);
        
        // 4. Dynamic File Upload (authenticated users only)
        add_action('wp_ajax_cw_file_upload', [ $this, 'handle_dynamic_file_upload' ]);
        
        // 5. Judge Scoring
        add_action('wp_ajax_cw_save_score', [ $this, 'handle_save_score' ]);

        // 6. Public Voting (logged-in and guests)
        add_action('wp_ajax_cw_cast_vote',        [ $this, 'handle_cast_vote' ]);
        add_action('wp_ajax_nopriv_cw_cast_vote', [ $this, 'handle_cast_vote' ]);
    }

    /**
     * Handle Save/Unsave Competition
     */
    public function handle_save_competition() {
        check_ajax_referer('cw_core_nonce', 'security');
        
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('Login required', 'creativewings-core')]);
        }

        $user_id = get_current_user_id();
        $competition_id = isset($_POST['competition_id']) ? intval($_POST['competition_id']) : 0;
        $task = isset($_POST['task']) ? sanitize_text_field($_POST['task']) : '';

        if (!$competition_id) {
            wp_send_json_error(['message' => __('Invalid Competition ID', 'creativewings-core')]);
        }

        $saved = get_user_meta($user_id, 'saved_competitions', true);
        $saved = is_array($saved) ? $saved : [];

        $existing_ids = array_column($saved, 'competition_id');
        $is_saved = in_array($competition_id, $existing_ids);

        if ($task === 'save') {
            if ( $is_saved ) {
                wp_send_json_success(['message' => __('Already saved', 'creativewings-core')]);
            }
            
            $saved[] = [
                'competition_id' => $competition_id,
                'date_saved'     => current_time('mysql')
            ];
            
            update_user_meta($user_id, 'saved_competitions', $saved);
            wp_send_json_success(['message' => __('Competition saved!', 'creativewings-core')]);

        } elseif ($task === 'remove') {
            $saved = array_filter($saved, function($item) use ($competition_id) {
                return $item['competition_id'] != $competition_id;
            });
            
            update_user_meta($user_id, 'saved_competitions', array_values($saved));
            wp_send_json_success(['message' => __('Competition removed!', 'creativewings-core')]);
        }

        wp_send_json_error(['message' => __('Invalid action', 'creativewings-core')]);
    }

    /**
     * Handle Dynamic File Upload (CRITICAL FIX)
     */
    public function handle_dynamic_file_upload() {
        check_ajax_referer('cw_core_nonce', 'security');

        if (!is_user_logged_in()) {
            wp_send_json_error('Authentication required.');
        }

        if (!isset($_FILES['file_data']) || !isset($_POST['session_key'])) {
            wp_send_json_error('Missing file or key.');
        }

        $allowed_types = [
            'image/jpeg', 'image/png', 'image/gif', 'image/webp',
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf', 'doc', 'docx'];
        $max_size = 10 * 1024 * 1024; // 10MB

        $uploaded_file = $_FILES['file_data'];
        $file_ext = strtolower(pathinfo($uploaded_file['name'], PATHINFO_EXTENSION));
        $file_type = $uploaded_file['type'];

        if (!in_array($file_ext, $allowed_extensions)) {
            wp_send_json_error('File type not allowed. Accepted: ' . implode(', ', $allowed_extensions));
        }

        if (!in_array($file_type, $allowed_types)) {
            wp_send_json_error('Invalid MIME type.');
        }

        if ($uploaded_file['size'] > $max_size) {
            wp_send_json_error('File too large. Maximum size: 10MB.');
        }

        if ( ! function_exists( 'wp_handle_upload' ) ) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }

        $upload_overrides = array( 'test_form' => false );
        $move_file = wp_handle_upload( $uploaded_file, $upload_overrides );
        
        if ( $move_file && !isset( $move_file['error'] ) ) {
            // Create an Attachment Post
            $attachment = array(
                'post_mime_type' => $move_file['type'],
                'post_title'     => sanitize_file_name($move_file['file']),
                'post_content'   => '',
                'post_status'    => 'inherit'
            );
            $attach_id = wp_insert_attachment( $attachment, $move_file['file'] );
            // Ensure taxonomy functions are loaded if needed (safer approach)
            if ( ! function_exists('wp_generate_attachment_metadata') ) require_once(ABSPATH . 'wp-admin/includes/taxonomy.php');
            $attach_data = wp_generate_attachment_metadata( $attach_id, $move_file['file'] );
            wp_update_attachment_metadata( $attach_id, $attach_data );

            // Optimize raster images on the fly (skips PDF/DOC which fail the mime gate inside).
            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                CW_Image_Optimizer::optimize_attachment( (int) $attach_id, 'attachment' );
            }

            // Save Attachment ID to WooCommerce Session
            $session_key = sanitize_text_field($_POST['session_key']);
            WC()->session->set($session_key, $attach_id);

            wp_send_json_success( ['url' => wp_get_attachment_url($attach_id), 'attach_id' => $attach_id] );
        } else {
            wp_send_json_error( 'Upload failed: ' . ($move_file['error'] ?? 'Unknown error') );
        }
    }
    
    /**
     * Handle Judge Scoring
     */
    public function handle_save_score() {
    if (!current_user_can('edit_products') && !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Permission Denied.']);
    }
    check_ajax_referer('cw_core_nonce', 'security');

    $eid           = intval($_POST['entry_id']);
    $score         = floatval($_POST['score']);
    $comment       = sanitize_textarea_field($_POST['comment'] ?? '');
    $winner_status = sanitize_text_field($_POST['winner_status'] ?? 'no');
    $winner_rank   = sanitize_text_field($_POST['winner_rank']   ?? '');

    // Reject scoring for non-judged campaigns (Activity / Talk-Seminar).
    $entry_pid = (int) get_post_meta( $eid, 'product_id', true );
    if ( class_exists( 'CW_Shop' ) && ! CW_Shop::campaign_is_judged( $entry_pid ) ) {
        wp_send_json_error( [ 'message' => 'Scoring is disabled for this campaign type.' ] );
    }

    // Only allow recognised rank values
    $valid_ranks = ['1st', '2nd', '3rd', 'mention', ''];
    if (!in_array($winner_rank, $valid_ranks, true)) $winner_rank = '';

    // Clear rank when not a winner
    if ($winner_status !== 'yes') $winner_rank = '';

    if ($eid > 0 && $score >= 0 && $score <= 100) {
        update_post_meta($eid, 'judge_score',    $score);
        update_post_meta($eid, 'judge_comment',  $comment);
        update_post_meta($eid, 'winner_status',  $winner_status);
        update_post_meta($eid, 'winner_rank',    $winner_rank);

        wp_send_json_success(['message' => 'Score and Comment updated!', 'score' => $score, 'winner' => $winner_status, 'winner_rank' => $winner_rank]);
    } else {
        wp_send_json_error(['message' => 'Invalid score or entry ID.']);
    }
}


    // --- STANDARD WOOCOMMERCE AJAX HANDLERS ---
    
    public function save_addon_data() {
        check_ajax_referer('cw_core_nonce', 'security');
        if ( ! function_exists( 'WC' ) ) wp_send_json_error( 'WooCommerce not active' );

        // This function is often used for complex add-ons (not fully detailed here, but kept for structure)
        if ( isset($_POST['addons']) && is_array($_POST['addons']) ) {
            $clean_addons = [];
            // ... (Sanitization logic goes here) ...
            WC()->session->set('addon_products', $clean_addons);
            wp_send_json_success();
        }
        wp_die();
    }

    public function update_checkout_quantity() {
        check_ajax_referer('cw_core_nonce', 'security');
        if ( ! function_exists('WC') ) wp_send_json_error( 'WooCommerce not active' );

        if ( ! isset($_POST['cart_item_key']) || ! isset($_POST['quantity']) ) {
            wp_send_json_error( __('Missing parameters', 'creativewings-core') );
        }

        $cart_item_key = sanitize_text_field( $_POST['cart_item_key'] );
        $new_qty       = (int) $_POST['quantity'];

        if ( $new_qty < 0 ) $new_qty = 0;

        if ( WC()->cart->get_cart_item( $cart_item_key ) ) {
            WC()->cart->set_quantity( $cart_item_key, $new_qty, true );
            WC()->cart->calculate_totals();

            WC()->cart->calculate_shipping();
            WC()->cart->calculate_fees();
            WC()->cart->calculate_tax();

            ob_start();
            woocommerce_mini_cart();
            $mini_cart = ob_get_clean();

            wp_send_json_success([
                'cart_hash' => WC()->cart->get_cart_hash(),
                'total'     => WC()->cart->get_total(),
                'mini_cart' => $mini_cart
            ]);
        } else {
            wp_send_json_error( __('Invalid item', 'creativewings-core') );
        }
    }

    /**
     * Public Voting — IP-restricted, one vote per entry per IP
     */
    public function handle_cast_vote() {
        check_ajax_referer( 'cw_core_nonce', 'security' );

        $entry_id = intval( $_POST['entry_id'] ?? 0 );
        if ( ! $entry_id || get_post_type( $entry_id ) !== 'cw_competition_entry' ) {
            wp_send_json_error( [ 'message' => 'Invalid entry.' ] );
        }

        // Belt-and-braces: seminars currently share the competition entry type, but voting is competition-only.
        $vote_pid = (int) get_post_meta( $entry_id, 'product_id', true );
        if ( class_exists( 'CW_Shop' ) && ! CW_Shop::campaign_is_judged( $vote_pid ) ) {
            wp_send_json_error( [ 'message' => 'Voting is disabled for this campaign type.' ] );
        }

        // Resolve visitor IP (proxy-aware)
        $ip = '';
        if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            $parts = explode( ',', $_SERVER['HTTP_X_FORWARDED_FOR'] );
            $ip = trim( $parts[0] );
        }
        if ( empty( $ip ) ) {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        $ip = sanitize_text_field( $ip );

        if ( empty( $ip ) ) {
            wp_send_json_error( [ 'message' => 'Could not determine IP.' ] );
        }

        // Check if already voted
        $voters = get_post_meta( $entry_id, 'cw_voters', true );
        if ( ! is_array( $voters ) ) $voters = [];

        if ( in_array( $ip, $voters, true ) ) {
            wp_send_json_error( [ 'message' => 'You have already voted for this entry.' ] );
        }

        // Record the vote
        $voters[] = $ip;
        update_post_meta( $entry_id, 'cw_voters', $voters );

        $new_count = intval( get_post_meta( $entry_id, 'vote_count', true ) ) + 1;
        update_post_meta( $entry_id, 'vote_count', $new_count );

        wp_send_json_success( [ 'vote_count' => $new_count ] );
    }
}