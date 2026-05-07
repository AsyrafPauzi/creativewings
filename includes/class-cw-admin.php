<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'add_custom_meta_boxes' ] );
        add_action( 'save_post', [ $this, 'save_custom_meta_data' ] );
        
        // Add columns to Admin List View
        add_filter( 'manage_cw_competition_entry_posts_columns', [ $this, 'entry_columns' ] );
        add_action( 'manage_cw_competition_entry_posts_custom_column', [ $this, 'render_entry_columns' ], 10, 2 );
        
        add_filter( 'manage_cw_activity_entry_posts_columns', [ $this, 'entry_columns' ] );
        add_action( 'manage_cw_activity_entry_posts_custom_column', [ $this, 'render_entry_columns' ], 10, 2 );
    }

    // 1. Register Meta Boxes
    public function add_custom_meta_boxes() {
        $screens = [ 'cw_competition_entry', 'cw_activity_entry' ];
        foreach ( $screens as $screen ) {
            add_meta_box(
                'cw_entry_details',
                __( 'Entry Details (Admin Editable)', 'creativewings-core' ),
                [ $this, 'render_meta_box' ],
                $screen,
                'normal',
                'high'
            );
        }
    }

    // 2. Render Meta Box HTML
    public function render_meta_box( $post ) {
        // Security Nonce
        wp_nonce_field( 'cw_save_entry_data', 'cw_entry_nonce' );

        // Fetch Meta
        $product_id = get_post_meta( $post->ID, 'product_id', true );
        $customer_id = get_post_meta( $post->ID, 'customer_id', true );
        $vote_count = get_post_meta( $post->ID, 'vote_count', true );
        $upload_doc = get_post_meta( $post->ID, 'upload_document', true );
        
        // Raw Participant JSON (The dynamic fields from form builder)
        $raw_json = get_post_meta( $post->ID, 'participant_details', true );
        $details = is_array($raw_json) ? $raw_json : []; 

        ?>
        <style>
            .cw-admin-row { margin-bottom: 15px; border-bottom: 1px solid #eee; padding-bottom: 15px; }
            .cw-admin-label { font-weight: bold; display: block; margin-bottom: 5px; }
            .cw-admin-input { width: 100%; padding: 8px; }
            .cw-json-display { background: #f9f9f9; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        </style>

        <div class="cw-admin-row">
            <label class="cw-admin-label">Linked Campaign (Product ID)</label>
            <input type="text" name="product_id" value="<?php echo esc_attr($product_id); ?>" class="cw-admin-input">
            <?php if($product_id) echo '<small><a href="'.get_edit_post_link($product_id).'">Edit Campaign</a></small>'; ?>
        </div>

        <div class="cw-admin-row">
            <label class="cw-admin-label">Customer ID</label>
            <input type="text" name="customer_id" value="<?php echo esc_attr($customer_id); ?>" class="cw-admin-input">
            <?php 
                $user = get_userdata($customer_id); 
                if($user) echo '<small>User: '.$user->display_name.' ('.$user->user_email.')</small>'; 
            ?>
        </div>

        <!-- Dynamic Fields Display & Edit -->
        <div class="cw-admin-row">
            <label class="cw-admin-label">Participant Data (From Form Builder)</label>
            <div class="cw-json-display">
                <?php 
                if(!empty($details)) {
                    foreach($details as $k => $field) {
                        $label = isset($field['label']) ? $field['label'] : 'Field '.$k;
                        $value = isset($field['value']) ? $field['value'] : '-';
                        echo '<p><strong>'.esc_html($label).':</strong> '.esc_html($value).'</p>';
                    }
                } else {
                    echo 'No dynamic data found.';
                }
                ?>
            </div>
            <p><small>To edit specific fields, use the overrides below:</small></p>
        </div>

        <!-- Overrides -->
        <div class="cw-admin-row">
            <label class="cw-admin-label">Main Uploaded Document (URL)</label>
            <input type="text" name="upload_document" value="<?php echo esc_attr($upload_doc); ?>" class="cw-admin-input">
            <?php if($upload_doc) echo '<br><a href="'.esc_url($upload_doc).'" target="_blank" class="button">View File</a>'; ?>
        </div>

        <?php if ( get_post_type($post) === 'cw_competition_entry' ): ?>
        <div class="cw-admin-row">
            <label class="cw-admin-label">Vote Count</label>
            <input type="number" name="vote_count" value="<?php echo esc_attr($vote_count); ?>" class="cw-admin-input">
        </div>
        
        <div class="cw-admin-row">
            <label class="cw-admin-label">Judging Score (0-100)</label>
            <input type="number" name="judging_score" value="<?php echo esc_attr(get_post_meta($post->ID, 'judging_score', true)); ?>" class="cw-admin-input">
        </div>
        <?php endif; ?>

        <?php
    }

    // 3. Save Logic
    public function save_custom_meta_data( $post_id ) {
        if ( ! isset( $_POST['cw_entry_nonce'] ) || ! wp_verify_nonce( $_POST['cw_entry_nonce'], 'cw_save_entry_data' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $fields = ['product_id', 'customer_id', 'upload_document', 'vote_count', 'judging_score'];
        foreach ( $fields as $field ) {
            if ( isset( $_POST[$field] ) ) {
                update_post_meta( $post_id, $field, sanitize_text_field( $_POST[$field] ) );
            }
        }
    }

    // 4. Admin Columns
    public function entry_columns( $columns ) {
        $new = [];
        $new['cb'] = $columns['cb'];
        $new['title'] = 'Entry ID / Name';
        $new['campaign'] = 'Campaign';
        $new['user'] = 'Participant';
        $new['date'] = 'Date';
        return $new;
    }

    public function render_entry_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'campaign':
                $pid = get_post_meta( $post_id, 'product_id', true );
                echo $pid ? get_the_title( $pid ) : '-';
                break;
            case 'user':
                $uid = get_post_meta( $post_id, 'customer_id', true );
                $u = get_userdata($uid);
                echo $u ? $u->display_name : 'Guest';
                break;
        }
    }
}