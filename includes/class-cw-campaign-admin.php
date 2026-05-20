<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Campaign_Admin {

    public function __construct() {
        add_action( 'add_meta_boxes', [ $this, 'metaboxes' ] );
        add_action( 'admin_post_cw_bulk_codes', [ $this, 'render_bulk_codes' ] );
        add_action( 'admin_post_cw_bulk_qr', [ $this, 'render_bulk_qr' ] );
        add_action( 'save_post_product', [ $this, 'ensure_woocommerce_product_defaults' ], 5, 2 );
        add_action( 'save_post_product', [ $this, 'save_product_flags' ], 20, 2 );
        add_filter( 'redirect_post_location', [ $this, 'fix_product_save_redirect' ], 99, 2 );
        add_action( 'admin_notices', [ $this, 'product_save_admin_notice' ] );
        add_action( 'admin_notices', [ $this, 'pending_queue_admin_notice' ] );
        add_action( 'edit_form_top', [ $this, 'warn_if_not_product_campaign' ] );

        add_action( 'init', [ $this, 'seed_default_subcategories' ] );
        add_filter( 'post_row_actions', [ $this, 'add_approve_row_action' ], 10, 2 );
        add_action( 'admin_post_cw_approve_campaign', [ $this, 'handle_approve_campaign' ] );
        add_action( 'admin_menu', [ $this, 'register_pending_queue_submenu' ] );
    }

    /**
     * Idempotent seeder for default sub-categories that should always exist
     * (e.g. "Art" under Activity). Runs on init and only inserts when missing.
     */
    public function seed_default_subcategories() {
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return;
        }

        $parent = get_term_by( 'slug', 'activities', 'product_cat' );
        if ( ! $parent || is_wp_error( $parent ) ) {
            return;
        }

        $desired_slug = 'art-activities';

        // Find an existing "Art" child term under Activities, regardless of its current slug.
        $existing = get_terms( [
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'parent'     => (int) $parent->term_id,
            'name'       => 'Art',
        ] );

        if ( empty( $existing ) || is_wp_error( $existing ) ) {
            wp_insert_term(
                'Art',
                'product_cat',
                [
                    'slug'   => $desired_slug,
                    'parent' => (int) $parent->term_id,
                ]
            );
            return;
        }

        // Realign existing term's slug to the canonical one if it drifted.
        $term = $existing[0];
        if ( isset( $term->slug ) && $term->slug !== $desired_slug ) {
            wp_update_term( (int) $term->term_id, 'product_cat', [ 'slug' => $desired_slug ] );
        }
    }

    /**
     * Inline "Approve campaign" row action on the products list for pending campaigns.
     *
     * @param array   $actions
     * @param WP_Post $post
     * @return array
     */
    public function add_approve_row_action( $actions, $post ) {
        if ( ! $post || 'product' !== $post->post_type ) {
            return $actions;
        }
        if ( 'pending' !== $post->post_status ) {
            return $actions;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return $actions;
        }

        $url = wp_nonce_url(
            add_query_arg(
                [
                    'action' => 'cw_approve_campaign',
                    'post'   => (int) $post->ID,
                ],
                admin_url( 'admin-post.php' )
            ),
            'cw_approve_campaign_' . (int) $post->ID
        );

        $new = [
            'cw_approve' => '<a href="' . esc_url( $url ) . '" style="color:#15803d;font-weight:600;">' .
                esc_html__( 'Approve campaign', 'creativewings-core' ) . '</a>',
        ];
        return array_merge( $new, $actions );
    }

    /**
     * One-click approve handler — flips a pending product to publish.
     */
    public function handle_approve_campaign() {
        $pid = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
        if ( ! $pid ) {
            wp_die( esc_html__( 'Missing campaign id.', 'creativewings-core' ), '', [ 'response' => 400 ] );
        }
        check_admin_referer( 'cw_approve_campaign_' . $pid );

        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), '', [ 'response' => 403 ] );
        }

        $post = get_post( $pid );
        if ( ! $post || 'product' !== $post->post_type ) {
            wp_die( esc_html__( 'Not a campaign.', 'creativewings-core' ), '', [ 'response' => 400 ] );
        }

        wp_update_post(
            [
                'ID'          => $pid,
                'post_status' => 'publish',
            ]
        );

        do_action( 'cw_campaign_approved', $pid, (int) $post->post_author );

        $referer = wp_get_referer();
        wp_safe_redirect(
            add_query_arg(
                'cw_campaign_approved',
                $pid,
                $referer ? $referer : admin_url( 'edit.php?post_type=product&post_status=pending' )
            )
        );
        exit;
    }

    /**
     * Admin notice on product list when filtered to pending — encourages review.
     */
    public function pending_queue_admin_notice() {
        if ( ! is_admin() || ! function_exists( 'get_current_screen' ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || 'edit-product' !== $screen->id ) {
            return;
        }
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }

        if ( ! empty( $_GET['cw_campaign_approved'] ) ) {
            $pid = (int) $_GET['cw_campaign_approved'];
            echo '<div class="notice notice-success is-dismissible"><p><strong>' .
                esc_html__( 'Creative Wings:', 'creativewings-core' ) . '</strong> ';
            printf(
                /* translators: %s: campaign title */
                esc_html__( 'Campaign “%s” is now published.', 'creativewings-core' ),
                esc_html( get_the_title( $pid ) )
            );
            echo '</p></div>';
        }

        $status = isset( $_GET['post_status'] ) ? sanitize_key( wp_unslash( $_GET['post_status'] ) ) : '';
        if ( 'pending' !== $status ) {
            return;
        }
        $count = wp_count_posts( 'product' );
        $pending_count = isset( $count->pending ) ? (int) $count->pending : 0;
        if ( $pending_count <= 0 ) {
            return;
        }
        echo '<div class="notice notice-info"><p><strong>' .
            esc_html__( 'Creative Wings:', 'creativewings-core' ) . '</strong> ';
        printf(
            esc_html(
                _n(
                    '%d campaign is awaiting your approval. Hover a row and click “Approve campaign” to publish.',
                    '%d campaigns are awaiting your approval. Hover a row and click “Approve campaign” to publish.',
                    $pending_count,
                    'creativewings-core'
                )
            ),
            $pending_count
        );
        echo '</p></div>';
    }

    /**
     * Add a quick "Pending campaigns" link under WooCommerce → for fast review.
     */
    public function register_pending_queue_submenu() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) {
            return;
        }
        $count         = wp_count_posts( 'product' );
        $pending_count = isset( $count->pending ) ? (int) $count->pending : 0;
        $bubble        = $pending_count > 0
            ? ' <span class="awaiting-mod">' . (int) $pending_count . '</span>'
            : '';

        add_submenu_page(
            'woocommerce',
            __( 'Pending campaigns', 'creativewings-core' ),
            __( 'Pending campaigns', 'creativewings-core' ) . $bubble,
            'manage_woocommerce',
            'edit.php?post_type=product&post_status=pending'
        );
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
        if ( ! isset( $_POST['cw_product_flags_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['cw_product_flags_nonce'] ) ), 'cw_product_flags' ) ) {
            return;
        }
        if ( isset( $_POST['cw_enable_moderation'] ) ) {
            update_post_meta( $post_id, 'cw_enable_moderation', 'yes' );
        } else {
            delete_post_meta( $post_id, 'cw_enable_moderation' );
        }

        // Anti-spam: only mutate when the meta box was actually rendered/submitted
        // (the hidden _present marker). When checked → 'yes'. When unchecked → 'no'.
        if ( isset( $_POST['cw_one_entry_per_user_present'] ) ) {
            if ( isset( $_POST['cw_one_entry_per_user'] ) ) {
                update_post_meta( $post_id, 'cw_one_entry_per_user', 'yes' );
            } else {
                update_post_meta( $post_id, 'cw_one_entry_per_user', 'no' );
            }
        }

        if ( isset( $_POST['cw_kpi_show_progress'] ) ) {
            update_post_meta( $post_id, 'cw_kpi_show_progress', 'yes' );
        } else {
            delete_post_meta( $post_id, 'cw_kpi_show_progress' );
        }

        if ( isset( $_POST['cw_kpi_target'] ) ) {
            $target = max( 0, (int) $_POST['cw_kpi_target'] );
            if ( $target > 0 ) {
                update_post_meta( $post_id, 'cw_kpi_target', $target );
            } else {
                delete_post_meta( $post_id, 'cw_kpi_target' );
            }
        }

        if ( isset( $_POST['cw_kpi_label'] ) ) {
            $label = sanitize_text_field( wp_unslash( $_POST['cw_kpi_label'] ) );
            if ( $label !== '' ) {
                update_post_meta( $post_id, 'cw_kpi_label', $label );
            } else {
                delete_post_meta( $post_id, 'cw_kpi_label' );
            }
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
            'staged'      => $staged,
            'claimed'     => $claimed,
            'pending'     => $pending,
            'total'       => $staged + $claimed,
            'revenue'     => $revenue,
            'participants'=> self::get_participant_count( (int) $campaign_id ),
        ];
    }

    /**
     * Number of individual participants who completed checkout for a campaign.
     *
     * Each cw_activity_entry / cw_competition_entry post = one participant
     * (entries are created during order processing in CW_Shop::create_entries_for_order).
     * This gives a stable "people joined" count for both free and paid campaigns.
     *
     * @param int $campaign_id WooCommerce product ID.
     * @return int
     */
    public static function get_participant_count( $campaign_id ) {
        $campaign_id = (int) $campaign_id;
        if ( ! $campaign_id ) {
            return 0;
        }
        global $wpdb;
        $sql = "SELECT COUNT(p.ID)
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'product_id'
                WHERE p.post_type IN ('cw_activity_entry','cw_competition_entry')
                  AND p.post_status = 'publish'
                  AND pm.meta_value = %s";
        return (int) $wpdb->get_var( $wpdb->prepare( $sql, (string) $campaign_id ) );
    }

    public function render_kpis( $post ) {
        $k = self::get_kpis( $post->ID );
        echo '<ul style="margin:0;font-size:13px;line-height:1.8;">';
        echo '<li><strong>' . esc_html__( 'Participants', 'creativewings-core' ) . ':</strong> ' . (int) $k['participants'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Staged', 'creativewings-core' ) . ':</strong> ' . (int) $k['staged'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Claimed', 'creativewings-core' ) . ':</strong> ' . (int) $k['claimed'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Moderation pending', 'creativewings-core' ) . ':</strong> ' . (int) $k['pending'] . '</li>';
        echo '<li><strong>' . esc_html__( 'Revenue (est.)', 'creativewings-core' ) . ':</strong> RM ' . esc_html( number_format( $k['revenue'], 2 ) ) . '</li>';
        echo '</ul>';
        wp_nonce_field( 'cw_product_flags', 'cw_product_flags_nonce' );

        $mod = get_post_meta( $post->ID, 'cw_enable_moderation', true ) === 'yes';
        echo '<p style="margin:14px 0 4px;"><label><input type="checkbox" name="cw_enable_moderation" value="1" ' . checked( $mod, true, false ) . '> ';
        echo esc_html__( 'Require artwork moderation before gallery', 'creativewings-core' ) . '</label></p>';

        // Anti-spam: one entry per user (= one email, since WP enforces unique emails per account).
        // Default ON for new campaigns. Stored 'no' only when the admin explicitly unticks.
        $one_entry_raw  = get_post_meta( $post->ID, 'cw_one_entry_per_user', true );
        $one_entry_on   = ( $one_entry_raw === '' || $one_entry_raw === 'yes' );
        echo '<input type="hidden" name="cw_one_entry_per_user_present" value="1">';
        echo '<p style="margin:8px 0 4px;"><label><input type="checkbox" name="cw_one_entry_per_user" value="1" ' . checked( $one_entry_on, true, false ) . '> ';
        echo esc_html__( 'Limit to one entry per user (anti-spam)', 'creativewings-core' ) . '</label></p>';
        echo '<p class="description" style="margin:2px 0 0;font-size:11px;color:#64748b;">';
        echo esc_html__( 'Each account (email) can only submit once. Different emails count as different users.', 'creativewings-core' );
        echo '</p>';

        $kpi_on     = get_post_meta( $post->ID, 'cw_kpi_show_progress', true ) === 'yes';
        $kpi_target = (int) get_post_meta( $post->ID, 'cw_kpi_target', true );
        $kpi_label  = (string) get_post_meta( $post->ID, 'cw_kpi_label', true );

        echo '<hr style="margin:14px 0 10px;border:none;border-top:1px solid #e2e8f0;">';
        echo '<p style="margin:0 0 6px;"><label style="font-weight:600;">';
        echo '<input type="checkbox" name="cw_kpi_show_progress" value="1" ' . checked( $kpi_on, true, false ) . '> ';
        echo esc_html__( 'Display KPI progress on product page', 'creativewings-core' ) . '</label></p>';

        echo '<p style="margin:6px 0 2px;font-size:12px;color:#475569;">' . esc_html__( 'Target number of submissions (the goal)', 'creativewings-core' ) . '</p>';
        echo '<p style="margin:0 0 6px;"><input type="number" name="cw_kpi_target" min="0" step="1" value="' . esc_attr( (string) $kpi_target ) . '" style="width:100%;" placeholder="e.g. 100"></p>';

        echo '<p style="margin:6px 0 2px;font-size:12px;color:#475569;">' . esc_html__( 'Label (optional, e.g. "participated")', 'creativewings-core' ) . '</p>';
        echo '<p style="margin:0 0 6px;"><input type="text" name="cw_kpi_label" value="' . esc_attr( $kpi_label ) . '" style="width:100%;" placeholder="participated"></p>';

        echo '<p class="description" style="margin:6px 0 0;font-size:11px;color:#64748b;">';
        echo esc_html__( 'Each completed checkout / registration linked to this campaign increases the count automatically.', 'creativewings-core' );
        echo '</p>';
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
        echo '<p><a class="button" href="' . esc_url( $bulk ) . '" target="_blank">' . esc_html__( 'Print code list (no QR)', 'creativewings-core' ) . '</a></p>';

        $schools = get_post_meta( $pid, 'cw_school_sponsors', true );
        if ( ! is_array( $schools ) ) {
            $schools = [];
        }
        if ( class_exists( 'CW_Staged_Submissions' ) ) {
            CW_Staged_Submissions::sync_school_upload_tokens( $pid );
        }

        echo '<h4 style="margin-top:20px;">' . esc_html__( 'Bulk QR codes for PIC scan', 'creativewings-core' ) . '</h4>';
        echo '<p class="description">' . esc_html__( 'Print QR tiles for posters — scan opens PIC upload with submission ID filled in.', 'creativewings-core' ) . '</p>';

        if ( empty( $schools ) ) {
            echo '<p class="description">' . esc_html__( 'Add schools in the campaign wizard (Step 4), then save this product.', 'creativewings-core' ) . '</p>';
        } else {
            $qr_base = wp_nonce_url(
                add_query_arg(
                    [
                        'action'      => 'cw_bulk_qr',
                        'campaign_id' => (int) $pid,
                    ],
                    admin_url( 'admin-post.php' )
                ),
                'cw_bulk_qr'
            );
            ?>
            <div id="cw-bulk-qr-panel" class="cw-bulk-qr-panel" data-base-url="<?php echo esc_attr( $qr_base ); ?>" style="max-width:520px;margin:12px 0 20px;padding:14px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;">
                <p style="margin:0 0 8px;">
                    <label for="cw-bulk-qr-school" style="font-weight:600;"><?php esc_html_e( 'School', 'creativewings-core' ); ?></label><br>
                    <select id="cw-bulk-qr-school" style="width:100%;margin-top:4px;">
                        <?php
                        foreach ( $schools as $s ) {
                            if ( empty( $s['school_code'] ) ) {
                                continue;
                            }
                            $sc    = CW_Submission_Code::pad_school( $s['school_code'] );
                            $label = trim( $sc . ' — ' . ( $s['school_name'] ?? '' ) );
                            echo '<option value="' . esc_attr( $sc ) . '">' . esc_html( $label ) . '</option>';
                        }
                        ?>
                    </select>
                </p>
                <p style="margin:0 0 8px;display:flex;gap:10px;">
                    <span style="flex:1;">
                        <label for="cw-bulk-qr-month" style="font-weight:600;"><?php esc_html_e( 'Month (MM)', 'creativewings-core' ); ?></label><br>
                        <input type="text" id="cw-bulk-qr-month" value="<?php echo esc_attr( gmdate( 'm' ) ); ?>" maxlength="2" style="width:100%;margin-top:4px;">
                    </span>
                    <span style="flex:1;">
                        <label for="cw-bulk-qr-start" style="font-weight:600;"><?php esc_html_e( 'Start #', 'creativewings-core' ); ?></label><br>
                        <input type="number" id="cw-bulk-qr-start" value="1" min="1" style="width:100%;margin-top:4px;">
                    </span>
                    <span style="flex:1;">
                        <label for="cw-bulk-qr-count" style="font-weight:600;"><?php esc_html_e( 'How many', 'creativewings-core' ); ?></label><br>
                        <input type="number" id="cw-bulk-qr-count" value="50" min="1" max="500" style="width:100%;margin-top:4px;">
                    </span>
                </p>
                <p style="margin:12px 0 0;">
                    <button type="button" class="button button-primary" id="cw-bulk-qr-open"><?php esc_html_e( 'Open printable QR sheet', 'creativewings-core' ); ?></button>
                </p>
            </div>
            <script>(function(){var p=document.getElementById("cw-bulk-qr-panel"),b=document.getElementById("cw-bulk-qr-open");if(!p||!b)return;b.onclick=function(ev){ev.preventDefault();ev.stopPropagation();var u=new URL(p.getAttribute("data-base-url"),location.origin);u.searchParams.set("school",document.getElementById("cw-bulk-qr-school").value||"001");u.searchParams.set("month",document.getElementById("cw-bulk-qr-month").value||"01");u.searchParams.set("start",document.getElementById("cw-bulk-qr-start").value||"1");u.searchParams.set("count",document.getElementById("cw-bulk-qr-count").value||"50");window.open(u.toString(),"_blank","noopener");};})();</script>
            <?php
        }

        $links = get_post_meta( $pid, 'cw_school_upload_links', true );
        if ( is_array( $links ) && ! empty( $links ) ) {
            echo '<h4>' . esc_html__( 'School PIC link (general)', 'creativewings-core' ) . '</h4>';
            echo '<p class="description">' . esc_html__( 'Optional: one link per school if staff type codes manually. Prefer per-student QR sheets above.', 'creativewings-core' ) . '</p>';
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
        echo '<table><thead><tr><th>#</th><th>' . esc_html__( 'Submission code', 'creativewings-core' ) . '</th><th>' . esc_html__( 'Student name', 'creativewings-core' ) . '</th></tr></thead><tbody>';
        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        for ( $i = 0; $i < $count; $i++ ) {
            $seq  = $start + $i;
            $code = class_exists( 'CW_Submission_Code' )
                ? CW_Submission_Code::build( $campaign_id, $month, $school, $seq )
                : $serial . $month . $school . str_pad( (string) $seq, $seq > 99999 ? 6 : 5, '0', STR_PAD_LEFT );
            echo '<tr><td>' . ( $i + 1 ) . '</td><td><strong>' . esc_html( $code ) . '</strong></td><td style="width:40%"></td></tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * Printable grid: one QR per submission code (scan → PIC form with code prefilled).
     */
    public function render_bulk_qr() {
        if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'edit_products' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), '', [ 'response' => 403 ] );
        }
        check_admin_referer( 'cw_bulk_qr' );

        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        $school      = CW_Submission_Code::pad_school( sanitize_text_field( $_GET['school'] ?? '001' ) );
        $month       = CW_Submission_Code::pad_month( sanitize_text_field( $_GET['month'] ?? gmdate( 'm' ) ) );
        $start       = max( 1, (int) ( $_GET['start'] ?? 1 ) );
        $count       = min( 500, max( 1, (int) ( $_GET['count'] ?? 50 ) ) );
        $title       = get_the_title( $campaign_id );

        if ( ! class_exists( 'CW_Staged_Submissions' ) ) {
            wp_die( esc_html__( 'Upload module not available.', 'creativewings-core' ), '', [ 'response' => 500 ] );
        }

        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html__( 'PIC QR sheet', 'creativewings-core' ) . '</title>';
        echo '<style>
            body{font-family:system-ui,sans-serif;margin:0;padding:14px;color:#0f172a;background:#fff}
            .hdr{margin-bottom:12px}
            .hdr h1{font-size:18px;margin:0 0 4px}
            .hdr p{margin:0;color:#64748b;font-size:13px}
            .grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:10px}
            .card{border:1px solid #e2e8f0;border-radius:8px;padding:8px 8px 10px;text-align:center;page-break-inside:avoid;background:#fff}
            .card img{width:100%;max-width:128px;height:auto;aspect-ratio:1;display:block;margin:0 auto}
            .card .code{margin-top:6px;font-size:11px;font-weight:700;letter-spacing:.04em;color:#0f172a;word-break:break-all;font-variant-numeric:tabular-nums}
            @media print{
                .np,.hdr{display:none}
                body{padding:4px}
                .grid{gap:6px}
                .card{border:1px solid #cbd5e1;padding:4px 4px 6px}
            }
        </style></head><body>';
        echo '<div class="hdr"><h1>' . esc_html( $title ) . '</h1><p>' . esc_html( sprintf( __( 'School %1$s · Month %2$s · %3$d codes (screen preview)', 'creativewings-core' ), $school, $month, $count ) ) . '</p></div>';
        echo '<p class="np"><button type="button" onclick="window.print()">' . esc_html__( 'Print', 'creativewings-core' ) . '</button></p>';
        echo '<div class="grid">';

        for ( $i = 0; $i < $count; $i++ ) {
            $seq     = $start + $i;
            $code    = CW_Submission_Code::build( $campaign_id, $month, $school, $seq );
            $pic_url = CW_Staged_Submissions::get_pic_qr_url( $campaign_id, $school, $code );
            $qr_src  = 'https://api.qrserver.com/v1/create-qr-code/?size=128x128&margin=6&data=' . rawurlencode( $pic_url );
            echo '<div class="card">';
            echo '<img src="' . esc_url( $qr_src ) . '" width="128" height="128" alt="" loading="lazy">';
            echo '<div class="code">' . esc_html( $code ) . '</div>';
            echo '</div>';
        }

        echo '</div></body></html>';
        exit;
    }
}
