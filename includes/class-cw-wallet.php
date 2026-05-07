<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Wallet {

    public function __construct() {
        // 1. CPT & Admin UI
        add_action( 'init', [ $this, 'register_cpt' ] );
        add_filter( 'manage_cw_withdrawal_posts_columns', [ $this, 'add_admin_columns' ] );
        add_action( 'manage_cw_withdrawal_posts_custom_column', [ $this, 'render_admin_columns' ], 10, 2 );

        // 2. Form Handlers
        add_action( 'admin_post_cw_request_withdrawal', [ $this, 'handle_withdrawal' ] );
        add_action( 'admin_post_cw_save_bank_details', [ $this, 'handle_save_bank' ] );
        
        // 3. Export Handler (NEW)
        add_action( 'admin_post_cw_export_wallet', [ $this, 'export_history' ] );
    }

    /* ==========================================================================
       1. REGISTER CPT
       ========================================================================== */
    public function register_cpt() {
        register_post_type( 'cw_withdrawal', [
            'labels' => [
                'name'               => __('Withdrawals', 'creativewings-core'),
                'singular_name'      => __('Withdrawal', 'creativewings-core'),
                'add_new'            => __('Add New', 'creativewings-core'),
                'add_new_item'       => __('Add New Withdrawal', 'creativewings-core'),
                'edit_item'          => __('Edit Withdrawal', 'creativewings-core'),
                'all_items'          => __('Withdrawal Requests', 'creativewings-core'),
            ],
            'public'       => false,
            'show_ui'      => true,
            'show_in_menu' => true,
            'supports'     => ['title'], 
            'menu_icon'    => 'dashicons-money-alt',
            'capabilities' => [ 'create_posts' => 'do_not_allow' ], 
            'map_meta_cap' => true,
        ]);
    }

    /* ==========================================================================
       2. ADMIN COLUMNS
       ========================================================================== */
    public function add_admin_columns( $columns ) {
        $new_columns = [];
        $new_columns['cb'] = $columns['cb'];
        $new_columns['title'] = __('Reference', 'creativewings-core');
        $new_columns['cw_user'] = __('Requested By', 'creativewings-core');
        $new_columns['cw_amount'] = __('Amount', 'creativewings-core');
        $new_columns['cw_bank'] = __('Bank Details', 'creativewings-core');
        $new_columns['date'] = $columns['date'];
        return $new_columns;
    }

    public function render_admin_columns( $column, $post_id ) {
        switch ( $column ) {
            case 'cw_user':
                $uid = get_post_meta( $post_id, 'cw_user_id', true );
                $user = get_userdata( $uid );
                echo $user ? '<a href="'.get_edit_user_link($uid).'">'.esc_html($user->display_name).'</a>' : 'Unknown';
                break;
            case 'cw_amount':
                $amt = get_post_meta( $post_id, 'cw_amount', true );
                echo '<strong>' . wc_price( $amt ) . '</strong>';
                break;
            case 'cw_bank':
                echo esc_html( get_post_meta( $post_id, 'cw_bank_snapshot', true ) );
                break;
        }
    }

    /* ==========================================================================
       3. WALLET LOGIC
       ========================================================================== */
    public static function get_wallet_stats( $user_id ) {
        $cache_key = 'cw_wallet_stats_v3_' . $user_id;
        $stats = get_transient( $cache_key );

        if ( false === $stats ) {
            global $wpdb;

            $product_ids = get_posts([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'author'         => $user_id,
                'fields'         => 'ids'
            ]);

            $total_sales = 0;
            $pending     = 0;
            $available   = 0;

            if ( ! empty( $product_ids ) ) {
                $placeholders = implode( ',', array_fill( 0, count( $product_ids ), '%d' ) );

                // Single query to get earnings per product
                $sql = "SELECT pid_meta.meta_value AS product_id, SUM(total_meta.meta_value) AS earnings
                    FROM {$wpdb->prefix}woocommerce_order_itemmeta AS total_meta
                    JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON total_meta.order_item_id = woi.order_item_id
                    JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid_meta ON woi.order_item_id = pid_meta.order_item_id AND pid_meta.meta_key = '_product_id'
                    JOIN {$wpdb->posts} AS p ON woi.order_id = p.ID
                    WHERE woi.order_item_type = 'line_item'
                      AND total_meta.meta_key = '_line_total'
                      AND p.post_status IN ('wc-completed', 'wc-processing')
                      AND pid_meta.meta_value IN ($placeholders)
                    GROUP BY pid_meta.meta_value";

                $results = $wpdb->get_results( $wpdb->prepare( $sql, $product_ids ) );

                $earnings_map = [];
                foreach ( $results as $row ) {
                    $earnings_map[ (int) $row->product_id ] = floatval( $row->earnings );
                }

                $now = time();
                foreach ( $product_ids as $pid ) {
                    $product_earnings = isset( $earnings_map[ $pid ] ) ? $earnings_map[ $pid ] : 0;
                    if ( $product_earnings <= 0 ) continue;

                    $total_sales += $product_earnings;

                    $event_date = get_post_meta( $pid, 'cw_final_event_date', true );
                    $deadline   = get_post_meta( $pid, 'submission_deadline', true );
                    $check_date = ! empty( $event_date ) ? $event_date : $deadline;
                    $is_mature  = $check_date && $now > strtotime( $check_date );

                    if ( $is_mature ) {
                        $available += $product_earnings;
                    } else {
                        $pending += $product_earnings;
                    }
                }
            }

            // Single query to get total withdrawals
            $total_withdrawn = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(pm.meta_value), 0)
                 FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE p.post_type = 'cw_withdrawal'
                   AND p.post_status IN ('publish', 'pending', 'draft')
                   AND pm.meta_key = 'cw_amount'
                   AND p.post_id IN (
                       SELECT post_id FROM {$wpdb->postmeta}
                       WHERE meta_key = 'cw_user_id' AND meta_value = %d
                   )",
                $user_id
            ) );

            $stats = [
                'total_earned' => $total_sales,
                'pending'      => $pending,
                'available'    => max( 0, $available - $total_withdrawn ),
                'withdrawn'    => $total_withdrawn
            ];

            set_transient( $cache_key, $stats, 5 * MINUTE_IN_SECONDS );
        }

        return $stats;
    }

    public static function get_product_earnings( $product_id ) {
        global $wpdb;
        $sql = "SELECT SUM(total_meta.meta_value)
            FROM {$wpdb->prefix}woocommerce_order_itemmeta AS total_meta
            JOIN {$wpdb->prefix}woocommerce_order_items AS woi ON total_meta.order_item_id = woi.order_item_id
            JOIN {$wpdb->prefix}woocommerce_order_itemmeta AS pid_meta ON woi.order_item_id = pid_meta.order_item_id AND pid_meta.meta_key = '_product_id'
            JOIN {$wpdb->posts} AS p ON woi.order_id = p.ID
            WHERE woi.order_item_type = 'line_item'
              AND total_meta.meta_key = '_line_total'
              AND p.post_status IN ('wc-completed', 'wc-processing')
              AND pid_meta.meta_value = %d";
        $total = $wpdb->get_var( $wpdb->prepare( $sql, $product_id ) );
        return $total ? floatval( $total ) : 0.00;
    }

    /* ==========================================================================
       4. EXPORT HANDLER (NEW)
       ========================================================================== */
    public function export_history() {
        if ( ! is_user_logged_in() ) wp_die('Unauthorized');

        $uid = get_current_user_id();
        
        // 1. Headers for Download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=Wallet_History_' . date('Y-m-d') . '.csv');
        
        // 2. Open Output Stream
        $output = fopen('php://output', 'w');
        
        // 3. Column Headings
        fputcsv($output, ['Date', 'Transaction ID', 'Type', 'Status', 'Amount (RM)', 'Bank Snapshot']);

        // 4. Fetch Data
        $withdrawals = get_posts([
            'post_type'      => 'cw_withdrawal',
            'posts_per_page' => -1, // All history
            'author'         => $uid,
            'post_status'    => ['publish', 'pending', 'draft'] 
        ]);

        foreach ( $withdrawals as $w ) {
            $amt = get_post_meta( $w->ID, 'cw_amount', true );
            $status = get_post_status( $w->ID ) == 'publish' ? 'Paid' : 'Pending';
            $bank = get_post_meta( $w->ID, 'cw_bank_snapshot', true );
            
            fputcsv($output, [
                get_the_date('Y-m-d H:i', $w->ID),
                $w->ID,
                'Payout Request',
                $status,
                $amt,
                $bank
            ]);
        }

        fclose($output);
        exit;
    }

    /* ==========================================================================
       5. HANDLERS
       ========================================================================== */
    public function handle_withdrawal() {
        if ( ! is_user_logged_in() || ! isset($_POST['cw_withdraw_nonce']) || ! wp_verify_nonce( $_POST['cw_withdraw_nonce'], 'cw_request_withdraw' ) ) {
            wp_die( __('Security Check Failed', 'creativewings-core') );
        }

        $uid = get_current_user_id();
        $amount = isset($_POST['withdraw_amount']) ? floatval( $_POST['withdraw_amount'] ) : 0;
        
        delete_transient( 'cw_wallet_stats_v3_' . $uid );
        $stats = self::get_wallet_stats( $uid );

        if ( $amount <= 0 ) {
            $this->redirect_with_msg( 'error', __('Invalid amount.', 'creativewings-core') );
        }

        if ( $amount > $stats['available'] ) {
            $this->redirect_with_msg( 'error', __('Insufficient available funds.', 'creativewings-core') );
        }

        $user_data = get_userdata( $uid );
        $title = sprintf( 'Withdrawal - %s - %s', $user_data->display_name, date( 'Y-m-d H:i' ) );

        $post_id = wp_insert_post([
            'post_type'   => 'cw_withdrawal',
            'post_title'  => $title,
            'post_status' => 'pending',
            'post_author' => $uid
        ]);

        if ( $post_id ) {
            update_post_meta( $post_id, 'cw_user_id', $uid );
            update_post_meta( $post_id, 'cw_amount', $amount );
            
            $bank_name = get_user_meta( $uid, 'cw_bank_name', true );
            $bank_acc  = get_user_meta( $uid, 'cw_bank_acc', true );
            $bank_hold = get_user_meta( $uid, 'cw_bank_holder', true );
            update_post_meta( $post_id, 'cw_bank_snapshot', "$bank_name | $bank_acc | $bank_hold" );

            delete_transient( 'cw_wallet_stats_v3_' . $uid );

            $admin_email = get_option( 'admin_email' );
            wp_mail( $admin_email, 'New Withdrawal: '.wc_price($amount), "User: {$user_data->display_name}\nAmount: ".wc_price($amount) );

            $this->redirect_with_msg( 'success', __('Withdrawal request submitted.', 'creativewings-core') );
        } else {
            $this->redirect_with_msg( 'error', __('System error.', 'creativewings-core') );
        }
    }

    public function handle_save_bank() {
        if ( ! is_user_logged_in() || ! isset($_POST['cw_bank_nonce']) || ! wp_verify_nonce( $_POST['cw_bank_nonce'], 'cw_save_bank' ) ) {
            wp_die( __('Security Check Failed', 'creativewings-core') );
        }

        $uid = get_current_user_id();
        $fields = ['cw_bank_name', 'cw_bank_acc', 'cw_bank_holder'];
        
        foreach ( $fields as $f ) {
            if ( isset( $_POST[$f] ) ) update_user_meta( $uid, $f, sanitize_text_field( $_POST[$f] ) );
        }

        $this->redirect_with_msg( 'success', __('Bank details saved.', 'creativewings-core') );
    }

    private function redirect_with_msg( $type, $message ) {
        if ( function_exists( 'wc_add_notice' ) ) {
            wc_add_notice( $message, $type );
        }
        
        $url = wp_get_referer();
        if ( ! $url ) $url = wc_get_account_endpoint_url( 'cw-biz-wallet' );
        
        $url = remove_query_arg(['requested', 'updated'], $url);
        if ( strpos($message, 'Withdrawal') !== false ) { $url = add_query_arg('requested', '1', $url); } 
        else { $url = add_query_arg('updated', '1', $url); }

        wp_safe_redirect( $url );
        exit;
    }
}