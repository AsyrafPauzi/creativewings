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

        add_filter( 'manage_edit-product_columns', [ $this, 'product_columns' ] );
        add_action( 'manage_product_posts_custom_column', [ $this, 'render_product_columns' ], 10, 2 );
        add_action( 'admin_footer', [ $this, 'product_list_copy_script' ] );
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

        add_meta_box(
            'cw_school_upload_links',
            __( 'School PIC upload links', 'creativewings-core' ),
            [ $this, 'render_school_upload_metabox' ],
            'product',
            'side',
            'default'
        );
    }

    public function render_school_upload_metabox( $post ) {
        $links = get_post_meta( $post->ID, 'cw_school_upload_links', true );
        if ( ! is_array( $links ) || empty( $links ) ) {
            if ( class_exists( 'CW_Staged_Submissions' ) ) {
                $links = CW_Staged_Submissions::sync_school_upload_tokens( $post->ID );
            }
        }
        $serial = get_post_meta( $post->ID, 'cw_campaign_serial', true );
        if ( $serial ) {
            echo '<p style="font-size:12px;"><strong>' . esc_html__( 'Campaign code:', 'creativewings-core' ) . '</strong> ' . esc_html( str_pad( preg_replace( '/\D/', '', (string) $serial ), 3, '0', STR_PAD_LEFT ) ) . '</p>';
        }
        if ( empty( $links ) ) {
            echo '<p style="font-size:12px;">' . esc_html__( 'Add schools in the campaign wizard (Step 4), then save to generate PIC links.', 'creativewings-core' ) . '</p>';
            return;
        }
        echo '<p style="font-size:12px;margin-bottom:8px;">' . esc_html__( 'Copy links for event PIC staff:', 'creativewings-core' ) . '</p>';
        foreach ( $links as $row ) {
            $label = trim( ( $row['school_code'] ?? '' ) . ' ' . ( $row['school_name'] ?? '' ) );
            $url   = $row['url'] ?? '';
            if ( ! $url ) {
                continue;
            }
            echo '<div style="margin-bottom:10px;">';
            echo '<strong style="display:block;font-size:11px;">' . esc_html( $label ) . '</strong>';
            echo '<input type="text" readonly class="cw-pic-link-input" value="' . esc_attr( $url ) . '" style="width:100%;font-size:11px;margin:4px 0;" onclick="this.select()">';
            echo '<button type="button" class="button button-small cw-copy-pic-link" data-url="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'creativewings-core' ) . '</button>';
            echo '</div>';
        }
    }

    public function product_columns( $columns ) {
        $new = [];
        foreach ( $columns as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'name' === $key ) {
                $new['cw_pic_links'] = __( 'PIC staff links', 'creativewings-core' );
            }
        }
        if ( ! isset( $new['cw_pic_links'] ) ) {
            $new['cw_pic_links'] = __( 'PIC staff links', 'creativewings-core' );
        }
        return $new;
    }

    public function render_product_columns( $column, $post_id ) {
        if ( 'cw_pic_links' !== $column ) {
            return;
        }
        $links = get_post_meta( $post_id, 'cw_school_upload_links', true );
        if ( empty( $links ) || ! is_array( $links ) ) {
            echo '<span style="color:#94a3b8;font-size:11px;">' . esc_html__( 'Save campaign with schools to generate links.', 'creativewings-core' ) . '</span>';
            return;
        }
        echo '<div class="cw-pic-links-col" style="max-width:320px;font-size:11px;line-height:1.5;">';
        foreach ( $links as $row ) {
            $url = $row['url'] ?? '';
            if ( ! $url ) {
                continue;
            }
            $label = esc_html( ( $row['school_code'] ?? '' ) . ( ! empty( $row['school_name'] ) ? ' — ' . $row['school_name'] : '' ) );
            echo '<div style="margin-bottom:6px;">';
            echo '<strong>' . $label . '</strong><br>';
            echo '<input type="text" readonly value="' . esc_attr( $url ) . '" style="width:100%;font-size:10px;margin:2px 0;" onclick="this.select()"> ';
            echo '<button type="button" class="button button-small cw-copy-pic-link" data-url="' . esc_attr( $url ) . '">' . esc_html__( 'Copy', 'creativewings-core' ) . '</button>';
            echo '</div>';
        }
        echo '</div>';
    }

    public function product_list_copy_script() {
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }
        ?>
        <script>
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.cw-copy-pic-link');
            if (!btn) return;
            e.preventDefault();
            var url = btn.getAttribute('data-url') || '';
            if (!url) return;
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(url).then(function() {
                    var t = btn.textContent;
                    btn.textContent = 'Copied!';
                    setTimeout(function() { btn.textContent = t; }, 1500);
                });
            } else {
                var inp = btn.parentElement.querySelector('input');
                if (inp) { inp.select(); document.execCommand('copy'); }
            }
        });
        </script>
        <?php
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