<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Campaign_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'metaboxes' ] );
        add_action( 'admin_post_cw_bulk_codes', [ $this, 'render_bulk_codes' ] );
        add_action( 'save_post_product', [ $this, 'ensure_woocommerce_product_defaults' ], 5, 2 );
        add_action( 'save_post_product', [ $this, 'save_product_flags' ], 20, 2 );
        add_filter( 'redirect_post_location', [ $this, 'fix_product_save_redirect' ], 99, 2 );
        add_action( 'admin_notices', [ $this, 'product_save_admin_notice' ] );
        add_action( 'edit_form_top', [ $this, 'warn_if_not_product_campaign' ] );
    }

    /**
     * @param WP_Post $post
     */
    public function warn_if_not_product_campaign( $post ) {
        if ( ! $post || 'product' === $post->post_type ) {
            return;
        }
        echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Not a WooCommerce product', 'creativewings-core' ) . '</strong> ';
        printf(
            /* translators: %s: post type slug */
            esc_html__( 'This item is post type “%s”. Campaigns must be edited under WooCommerce → Products, not the blog Posts screen.', 'creativewings-core' ),
            esc_html( $post->post_type )
        );
        echo '</p></div>';
    }

    /**
     * Campaign products created via import/wizard may lack WC type/stock meta; admin Publish needs these.
     */
    public function ensure_woocommerce_product_defaults( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
            return;
        }
        if ( ! $post || 'product' !== $post->post_type ) {
            return;
        }

        $terms = wp_get_object_terms( $post_id, 'product_type', [ 'fields' => 'slugs' ] );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            wp_set_object_terms( $post_id, 'simple', 'product_type' );
        }

        if ( '' === get_post_meta( $post_id, '_stock_status', true ) ) {
            update_post_meta( $post_id, '_stock_status', 'instock' );
        }
        update_post_meta( $post_id, '_manage_stock', 'no' );

        if ( '' === get_post_meta( $post_id, '_virtual', true ) ) {
            update_post_meta( $post_id, '_virtual', 'yes' );
        }

        if ( get_post_meta( $post_id, '_price', true ) === '' && get_post_meta( $post_id, '_regular_price', true ) === '' ) {
            update_post_meta( $post_id, '_regular_price', '0' );
            update_post_meta( $post_id, '_price', '0' );
        }

        if ( function_exists( 'wc_delete_product_transients' ) ) {
            wc_delete_product_transients( $post_id );
        }
    }

    public function product_save_admin_notice() {
        if ( ! isset( $_GET['post'], $_GET['message'] ) ) {
            return;
        }
        $post_id = (int) $_GET['post'];
        if ( 'product' !== get_post_type( $post_id ) ) {
            return;
        }
        if ( ! in_array( (int) $_GET['message'], [ 6, 10 ], true ) ) {
            return;
        }
        if ( 'publish' === get_post_status( $post_id ) ) {
            return;
        }
        echo '<div class="notice notice-warning"><p><strong>' . esc_html__( 'Creative Wings:', 'creativewings-core' ) . '</strong> ';
        esc_html_e( 'Save completed but this campaign is still not Published. In the Product data box set a price, choose Simple product, then click Publish again. If it keeps failing, deploy the latest plugin (certificate nested-form fix).', 'creativewings-core' );
        echo '</p></div>';
    }

    /**
     * After saving a campaign product, stay on the product editor or WooCommerce product list.
     *
     * @param string $location
     * @param int    $post_id
     * @return string
     */
    public function fix_product_save_redirect( $location, $post_id ) {
        if ( 'product' !== get_post_type( $post_id ) ) {
            return $location;
        }

        $query = [];
        if ( false !== strpos( $location, '?' ) ) {
            parse_str( (string) wp_parse_url( $location, PHP_URL_QUERY ), $query );
        }

        $edit_link = get_edit_post_link( $post_id, 'raw' );
        if ( $edit_link ) {
            if ( ! empty( $query['message'] ) ) {
                return add_query_arg( 'message', (int) $query['message'], $edit_link );
            }
            return $edit_link;
        }

        $query['post_type'] = 'product';
        return add_query_arg( $query, admin_url( 'edit.php' ) );
    }

    public function save_product_flags( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( isset( $_POST['cw_enable_moderation'] ) ) {
            update_post_meta( $post_id, 'cw_enable_moderation', 'yes' );
        } else {
            delete_post_meta( $post_id, 'cw_enable_moderation' );
        }
        if ( class_exists( 'CW_Campaign_Resolver' ) ) {
            CW_Campaign_Resolver::flush_serial_cache( $post_id );
        }
    }

    public function metaboxes() {
        add_meta_box( 'cw_campaign_kpis', __( 'Campaign KPIs', 'creativewings-core' ), [ $this, 'render_kpis' ], 'product', 'side', 'high' );
        add_meta_box( 'cw_campaign_tools', __( 'Campaign tools', 'creativewings-core' ), [ $this, 'render_tools' ], 'product', 'normal', 'default' );
    }

    public static function get_kpis( $campaign_id ) {
        global $wpdb;
        $table = CW_Staged_Submissions::table();
        $staged   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = 'staged'", $campaign_id ) );
        $claimed  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND status = 'claimed'", $campaign_id ) );
        $pending  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE campaign_id = %d AND moderation_status = 'pending'", $campaign_id ) );
        $revenue  = 0.0;
        if ( function_exists( 'wc_get_orders' ) ) {
            $orders = wc_get_orders(
                [
                    'limit'      => -1,
                    'status'     => [ 'completed', 'processing' ],
                    'return'     => 'ids',
                    'meta_query' => [
                        [
                            'key'   => '_cw_campaign_product',
                            'value' => (string) $campaign_id,
                        ],
                    ],
                ]
            );
            foreach ( $orders as $oid ) {
                $order = wc_get_order( $oid );
                if ( $order ) {
                    $revenue += (float) $order->get_total();
                }
            }
        }
        if ( ! $revenue && class_exists( 'CW_Wallet' ) ) {
            $revenue = (float) CW_Wallet::get_product_earnings( $campaign_id );
        }
        return [
            'staged'  => $staged,
            'claimed' => $claimed,
            'pending' => $pending,
            'total'   => $staged + $claimed,
            'revenue' => $revenue,
        ];
    }

    public function render_kpis( $post ) {
        $k = self::get_kpis( $post->ID );
        echo '<ul style="margin:0;font-size:13px;line-height:1.8;">';
        echo '<li><strong>' . esc_html__( 'Staged', 'creativewings-core' ) . ':</strong> ' . (int) $k['staged'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Claimed', 'creativewings-core' ) . ':</strong> ' . (int) $k['claimed'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Moderation pending', 'creativewings-core' ) . ':</strong> ' . (int) $k['pending'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Revenue (est.)', 'creativewings-core' ) . ':</strong> RM ' . esc_html( number_format( $k['revenue'], 2 ) ) . '</li>';
        echo '</ul>';
        wp_nonce_field( 'cw_product_flags', 'cw_product_flags_nonce' );
        $mod = get_post_meta( $post->ID, 'cw_enable_moderation', true ) === 'yes';
        echo '<p><label><input type="checkbox" name="cw_enable_moderation" value="1" ' . checked( $mod, true, false ) . '> ';
        echo esc_html__( 'Require artwork moderation before gallery', 'creativewings-core' ) . '</label></p>';
    }

    public function render_tools( $post ) {
        $pid    = $post->ID;
        $serial = str_pad( preg_replace( '/\D/', '', (string) get_post_meta( $pid, 'cw_campaign_serial', true ) ), 3, '0', STR_PAD_LEFT );
        $export = wp_nonce_url(
            add_query_arg( [ 'action' => 'cw_export_submissions', 'campaign_id' => $pid ], admin_url( 'admin-post.php' ) ),
            'cw_export_submissions'
        );
        $export_school = wp_nonce_url(
            add_query_arg( [ 'action' => 'cw_export_submissions', 'campaign_id' => $pid, 'school_code' => '001' ], admin_url( 'admin-post.php' ) ),
            'cw_export_submissions'
        );
        $bulk = wp_nonce_url(
            add_query_arg(
                [
                    'action'      => 'cw_bulk_codes',
                    'campaign_id' => $pid,
                    'serial'      => $serial,
                    'school'      => '001',
                    'month'       => gmdate( 'm' ),
                    'start'       => 1,
                    'count'       => 50,
                ],
                admin_url( 'admin-post.php' )
            ),
            'cw_bulk_codes'
        );
        echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export all submissions (CSV)', 'creativewings-core' ) . '</a></p>';
        echo '<p><a class="button" href="' . esc_url( $bulk ) . '" target="_blank">' . esc_html__( 'Print bulk code sheet (50 codes)', 'creativewings-core' ) . '</a></p>';
        echo '<p class="description">' . esc_html__( 'Adjust school/month/start/count in URL after opening bulk sheet, or use query args.', 'creativewings-core' ) . '</p>';

        $links = get_post_meta( $pid, 'cw_school_upload_links', true );
        if ( is_array( $links ) && ! empty( $links ) ) {
            echo '<h4>' . esc_html__( 'PIC links + QR', 'creativewings-core' ) . '</h4>';
            foreach ( $links as $row ) {
                $url = $row['url'] ?? '';
                if ( ! $url ) {
                    continue;
                }
                $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=150x150&data=' . rawurlencode( $url );
                echo '<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;border:1px solid #e2e8f0;padding:10px;border-radius:8px;">';
                echo '<img src="' . esc_url( $qr ) . '" width="100" height="100" alt="QR">';
                echo '<div><strong>' . esc_html( ( $row['school_code'] ?? '' ) . ' ' . ( $row['school_name'] ?? '' ) ) . '</strong><br>';
                echo '<input readonly style="width:100%;max-width:400px;font-size:11px" value="' . esc_attr( $url ) . '" onclick="this.select()"></div></div>';
            }
        }
    }

    public function render_bulk_codes() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( 'Unauthorized', 403 );
        }
        check_admin_referer( 'cw_bulk_codes' );

        $serial = str_pad( preg_replace( '/\D/', '', sanitize_text_field( $_GET['serial'] ?? '002' ) ), 3, '0', STR_PAD_LEFT );
        $school = str_pad( preg_replace( '/\D/', '', sanitize_text_field( $_GET['school'] ?? '001' ) ), 3, '0', STR_PAD_LEFT );
        $month  = str_pad( preg_replace( '/\D/', '', sanitize_text_field( $_GET['month'] ?? gmdate( 'm' ) ) ), 2, '0', STR_PAD_LEFT );
        $start  = max( 1, (int) ( $_GET['start'] ?? 1 ) );
        $count  = min( 500, max( 1, (int) ( $_GET['count'] ?? 50 ) ) );
        $title  = get_the_title( (int) ( $_GET['campaign_id'] ?? 0 ) );

        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Bulk codes</title>';
        echo '<style>body{font-family:system-ui;padding:20px}table{border-collapse:collapse;width:100%}td,th{border:1px solid #ccc;padding:8px;font-size:12px}@media print{.no-print{display:none}}</style></head><body>';
        echo '<p class="no-print"><button onclick="window.print()">Print</button></p>';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p>School ' . esc_html( $school ) . ' · Month ' . esc_html( $month ) . '</p>';
        echo '<table><thead><tr><th>#</th><th>Submission code</th><th>Student name</th></tr></thead><tbody>';
        for ( $i = 0; $i < $count; $i++ ) {
            $seq  = $start + $i;
            $seqs = str_pad( (string) $seq, $seq > 99999 ? 6 : 5, '0', STR_PAD_LEFT );
            $code = $serial . $month . $school . $seqs;
            echo '<tr><td>' . ( $i + 1 ) . '</td><td><strong>' . esc_html( $code ) . '</strong></td><td style="width:40%"></td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }
}
