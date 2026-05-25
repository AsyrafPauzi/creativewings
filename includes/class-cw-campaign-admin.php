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
     * Idempotent seeder for the default campaign taxonomy structure.
     *
     * - Ensures the "Art" sub-category exists under Activities.
     * - Ensures a top-level "Talk / Seminar" parent category exists, plus a
     *   couple of generic sub-categories underneath so the create-campaign
     *   wizard has options to show when the organizer picks it.
     * - Ensures the "Competitions" parent exists and a "Design" child sits
     *   under it (powers the Design Submission feature — organizers won't see
     *   the Design wizard branch unless this term exists).
     *
     * Runs on `init` and only inserts when missing.
     */
    public function seed_default_subcategories() {
        if ( ! taxonomy_exists( 'product_cat' ) ) {
            return;
        }

        // ─────────────────────────────────────────────────────────────────
        // "Art" sub-category under Activities (original behaviour).
        // ─────────────────────────────────────────────────────────────────
        $activities_parent = get_term_by( 'slug', 'activities', 'product_cat' );
        if ( $activities_parent && ! is_wp_error( $activities_parent ) ) {
            $desired_slug = 'art-activities';

            $existing = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => (int) $activities_parent->term_id,
                'name'       => 'Art',
            ] );

            if ( empty( $existing ) || is_wp_error( $existing ) ) {
                wp_insert_term(
                    'Art',
                    'product_cat',
                    [
                        'slug'   => $desired_slug,
                        'parent' => (int) $activities_parent->term_id,
                    ]
                );
            } else {
                $term = $existing[0];
                if ( isset( $term->slug ) && $term->slug !== $desired_slug ) {
                    wp_update_term( (int) $term->term_id, 'product_cat', [ 'slug' => $desired_slug ] );
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // Top-level "Talk / Seminar" parent + default children.
        // The rest of the codebase already treats `talk-seminar` as a
        // first-class campaign type (badges, reports, shortcodes, shop),
        // but the wizard never had a card for it — and the parent term
        // was never seeded — so organizers couldn't pick it.
        // ─────────────────────────────────────────────────────────────────
        $talk_parent = get_term_by( 'slug', 'talk-seminar', 'product_cat' );
        if ( ! $talk_parent || is_wp_error( $talk_parent ) ) {
            $inserted = wp_insert_term(
                'Talk / Seminar',
                'product_cat',
                [
                    'slug'        => 'talk-seminar',
                    'description' => 'Talks, webinars and seminars hosted by organizers.',
                ]
            );
            if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
                $talk_parent = get_term( (int) $inserted['term_id'], 'product_cat' );
            }
        }

        if ( $talk_parent && ! is_wp_error( $talk_parent ) ) {
            // Use prefixed slugs to avoid colliding with any unrelated top-level
            // "Talk"/"Seminar" term that an admin may have created previously.
            // The wizard renders children by parent term, so the prefix has no
            // UX impact.
            $default_children = [
                [ 'name' => 'Seminar', 'slug' => 'talk-seminar-seminar' ],
                [ 'name' => 'Talk',    'slug' => 'talk-seminar-talk' ],
                [ 'name' => 'Webinar', 'slug' => 'talk-seminar-webinar' ],
            ];

            // Only seed children if the parent currently has none, so we don't
            // shadow custom children the admin has added in the Products UI.
            $children = get_terms( [
                'taxonomy'   => 'product_cat',
                'hide_empty' => false,
                'parent'     => (int) $talk_parent->term_id,
            ] );

            if ( empty( $children ) || is_wp_error( $children ) ) {
                foreach ( $default_children as $child ) {
                    if ( get_term_by( 'slug', $child['slug'], 'product_cat' ) ) {
                        continue; // Already inserted on a previous run.
                    }
                    wp_insert_term(
                        $child['name'],
                        'product_cat',
                        [
                            'slug'   => $child['slug'],
                            'parent' => (int) $talk_parent->term_id,
                        ]
                    );
                }
            }
        }

        // ─────────────────────────────────────────────────────────────────
        // Top-level "Competitions" parent + "Design" child.
        // The wizard JS already routes the `design` slug into the
        // set-competition conditional branch (handleConditionalFields), but
        // historically neither term was auto-seeded — organizers had to
        // create them by hand. Seeding here is idempotent so it's safe on
        // installs where an admin already created them in the Products UI.
        // ─────────────────────────────────────────────────────────────────
        $competitions_parent = get_term_by( 'slug', 'competitions', 'product_cat' );
        if ( ! $competitions_parent || is_wp_error( $competitions_parent ) ) {
            $inserted = wp_insert_term(
                'Competitions',
                'product_cat',
                [
                    'slug'        => 'competitions',
                    'description' => 'Judged campaigns where participants submit entries and winners receive prizes.',
                ]
            );
            if ( ! is_wp_error( $inserted ) && ! empty( $inserted['term_id'] ) ) {
                $competitions_parent = get_term( (int) $inserted['term_id'], 'product_cat' );
            }
        }

        if ( $competitions_parent && ! is_wp_error( $competitions_parent ) ) {
            // Prefix the slug with the parent name to avoid colliding with any
            // unrelated top-level "Design" term that an admin may have created
            // elsewhere (matches the `talk-seminar-*` namespacing convention).
            $design_slug = 'competitions-design';
            $existing_design = get_term_by( 'slug', $design_slug, 'product_cat' );

            if ( ! $existing_design || is_wp_error( $existing_design ) ) {
                wp_insert_term(
                    'Design',
                    'product_cat',
                    [
                        'slug'        => $design_slug,
                        'parent'      => (int) $competitions_parent->term_id,
                        'description' => 'Design submission competitions — participants upload artwork that gets printed onto a configured product variant.',
                    ]
                );
            }
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
        echo '<p class="description" style="margin:2px 0 0;font-size:11px;color:#555555;">';
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

        echo '<p class="description" style="margin:6px 0 0;font-size:11px;color:#555555;">';
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
                $qr = self::qr_image_url( $url, 150 );
                echo '<div style="display:flex;gap:12px;align-items:flex-start;margin-bottom:16px;border:1px solid #e2e8f0;padding:10px;border-radius:8px;">';
                echo '<img src="' . esc_url( $qr ) . '" width="100" height="100" alt="QR">';
                echo '<div><strong>' . esc_html( ( $row['school_code'] ?? '' ) . ' ' . ( $row['school_name'] ?? '' ) ) . '</strong><br>';
                echo '<input readonly style="width:100%;max-width:400px;font-size:11px" value="' . esc_attr( $url ) . '" onclick="this.select()"></div></div>';
            }
        }
    }

    /**
     * Generate a printable / downloadable list of submission codes for a
     * campaign + school + month + sequence range.
     *
     * Output formats:
     *   - html  Printable HTML (default).
     *   - csv   text/csv attachment.
     *   - pdf   application/pdf attachment via Dompdf.
     *
     * Each row contains: # / Submission code / PIC scan URL.
     * The previously emitted "Student name" column was dropped — write-by-
     * hand workflows still work on the HTML view (organisers can pencil
     * names next to the row) and the digital CSV/PDF flows don't need it.
     */
    public function render_bulk_codes() {
        check_admin_referer( 'cw_bulk_codes' );

        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        if ( ! $this->user_can_generate_codes_for( $campaign_id ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), '', [ 'response' => 403 ] );
        }

        $school = CW_Submission_Code::pad_school( sanitize_text_field( $_GET['school'] ?? '001' ) );
        $month  = CW_Submission_Code::pad_month( sanitize_text_field( $_GET['month'] ?? gmdate( 'm' ) ) );
        $start  = max( 1, (int) ( $_GET['start'] ?? 1 ) );
        $count  = min( 1000, max( 1, (int) ( $_GET['count'] ?? 50 ) ) );
        $format = strtolower( sanitize_key( $_GET['format'] ?? 'html' ) );
        $title  = $campaign_id ? get_the_title( $campaign_id ) : '';

        $rows = $this->build_code_rows( $campaign_id, $school, $month, $start, $count );

        if ( 'csv' === $format ) {
            $this->stream_codes_csv( $rows, $title, $school, $month );
            return;
        }

        if ( 'pdf' === $format ) {
            $this->stream_codes_pdf( $rows, $title, $school, $month, false );
            return;
        }

        // Default: printable HTML view.
        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>' . esc_html__( 'Bulk codes', 'creativewings-core' ) . '</title>';
        echo '<style>
            body{font-family:system-ui,sans-serif;padding:20px;color:#0f172a}
            h1{margin:0 0 4px;font-size:20px}
            .meta{margin:0 0 14px;color:#475569;font-size:13px}
            table{border-collapse:collapse;width:100%;font-size:12px}
            th,td{border:1px solid #cbd5e1;padding:8px 10px;text-align:left;vertical-align:top}
            th{background:#f1f5f9;font-weight:700}
            td.idx{width:48px;text-align:right;font-variant-numeric:tabular-nums;color:#64748b}
            td.code{font-weight:700;font-family:ui-monospace,Menlo,Consolas,monospace;letter-spacing:.02em}
            td.url{font-family:ui-monospace,Menlo,Consolas,monospace;font-size:11px;color:#334155;word-break:break-all}
            @media print{.no-print{display:none}body{padding:6px}th,td{padding:5px 7px}}
        </style></head><body>';
        echo '<p class="no-print"><button onclick="window.print()">' . esc_html__( 'Print', 'creativewings-core' ) . '</button></p>';
        echo '<h1>' . esc_html( $title ) . '</h1>';
        echo '<p class="meta">' . esc_html(
            sprintf(
                /* translators: 1: school code, 2: month, 3: count, 4: start, 5: end */
                __( 'School %1$s · Month %2$s · %3$d codes (#%4$d – #%5$d)', 'creativewings-core' ),
                $school,
                $month,
                $count,
                $start,
                $start + $count - 1
            )
        ) . '</p>';

        echo '<table><thead><tr>';
        echo '<th>#</th>';
        echo '<th>' . esc_html__( 'Submission code', 'creativewings-core' ) . '</th>';
        echo '<th>' . esc_html__( 'PIC scan URL', 'creativewings-core' ) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            echo '<tr>';
            echo '<td class="idx">' . (int) $row['idx'] . '</td>';
            echo '<td class="code">' . esc_html( $row['code'] ) . '</td>';
            echo '<td class="url">' . esc_html( $row['url'] ) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table></body></html>';
        exit;
    }

    /**
     * Resolve campaign rows once for every output format.
     *
     * @return array<int, array{idx:int, code:string, url:string}>
     */
    private function build_code_rows( $campaign_id, $school, $month, $start, $count ) {
        $rows = [];
        for ( $i = 0; $i < $count; $i++ ) {
            $seq  = $start + $i;
            $code = CW_Submission_Code::build( $campaign_id, $month, $school, $seq );
            $url  = class_exists( 'CW_Staged_Submissions' )
                ? CW_Staged_Submissions::get_pic_qr_url( $campaign_id, $school, $code )
                : '';
            $rows[] = [
                'idx'  => $i + 1,
                'code' => $code,
                'url'  => $url,
            ];
        }
        return $rows;
    }

    /**
     * Stream a CSV file with: #, Submission code, PIC scan URL.
     */
    private function stream_codes_csv( array $rows, $title, $school, $month ) {
        $filename = sanitize_file_name(
            sprintf( 'cw-codes-%s-school-%s-m%s-%s.csv', sanitize_title( $title ), $school, $month, gmdate( 'Ymd-His' ) )
        );

        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        $out = fopen( 'php://output', 'w' );
        fwrite( $out, "\xEF\xBB\xBF" ); // UTF-8 BOM so Excel opens cleanly.
        fputcsv( $out, [ __( '#', 'creativewings-core' ), __( 'Submission code', 'creativewings-core' ), __( 'PIC scan URL', 'creativewings-core' ) ] );
        foreach ( $rows as $row ) {
            fputcsv( $out, [ $row['idx'], $row['code'], $row['url'] ] );
        }
        fclose( $out );
        exit;
    }

    /**
     * Stream a PDF file via Dompdf.
     *
     * @param bool $with_qr  When true (QR sheet mode) embeds a QR image per row.
     */
    private function stream_codes_pdf( array $rows, $title, $school, $month, $with_qr ) {
        if ( ! class_exists( '\\Dompdf\\Dompdf' ) ) {
            wp_die( esc_html__( 'PDF exporter is not installed. Run composer install.', 'creativewings-core' ), '', [ 'response' => 500 ] );
        }

        $count = count( $rows );
        $first = $rows ? (int) $rows[0]['idx'] : 1;
        $last  = $rows ? (int) end( $rows )['idx'] : $count;

        ob_start();
        ?>
        <html><head><meta charset="utf-8">
        <style>
            @page { margin: 14mm 12mm; }
            body { font-family: DejaVu Sans, sans-serif; color: #0f172a; font-size: 11px; }
            h1   { margin: 0 0 4px; font-size: 16px; }
            .meta { color: #475569; font-size: 10px; margin: 0 0 10px; }
            table { width: 100%; border-collapse: collapse; }
            th,td { border: 1px solid #cbd5e1; padding: 4px 6px; vertical-align: middle; }
            th    { background: #f1f5f9; font-weight: 700; text-align: left; }
            td.idx { width: 32px; text-align: right; color: #64748b; }
            td.code { font-weight: 700; font-family: DejaVu Sans Mono, monospace; }
            td.url  { font-family: DejaVu Sans Mono, monospace; font-size: 9px; color: #334155; word-break: break-all; }
            td.qr   { width: 90px; text-align: center; }
            td.qr img { width: 80px; height: 80px; }
        </style></head><body>
            <h1><?php echo esc_html( $title ); ?></h1>
            <p class="meta">
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: school code, 2: month, 3: count, 4: start, 5: end */
                        __( 'School %1$s · Month %2$s · %3$d codes (#%4$d – #%5$d)', 'creativewings-core' ),
                        $school,
                        $month,
                        $count,
                        $first,
                        $last
                    )
                );
                ?>
            </p>
            <table>
                <thead>
                    <tr>
                        <th>#</th>
                        <?php if ( $with_qr ) : ?><th><?php esc_html_e( 'QR', 'creativewings-core' ); ?></th><?php endif; ?>
                        <th><?php esc_html_e( 'Submission code', 'creativewings-core' ); ?></th>
                        <th><?php esc_html_e( 'PIC scan URL', 'creativewings-core' ); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ( $rows as $row ) : ?>
                        <tr>
                            <td class="idx"><?php echo (int) $row['idx']; ?></td>
                            <?php if ( $with_qr ) : ?>
                                <td class="qr"><img src="<?php echo esc_url( self::qr_image_url( $row['url'], 160, 2 ) ); ?>"></td>
                            <?php endif; ?>
                            <td class="code"><?php echo esc_html( $row['code'] ); ?></td>
                            <td class="url"><?php echo esc_html( $row['url'] ); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </body></html>
        <?php
        $html = ob_get_clean();

        $options = new \Dompdf\Options();
        // QR images come from api.qrserver.com so we need remote fetches
        // enabled when the QR column is included. Text-only codes don't
        // need it but enabling here is harmless.
        $options->set( 'isRemoteEnabled', true );
        $options->set( 'defaultFont', 'DejaVu Sans' );

        $dompdf = new \Dompdf\Dompdf( $options );
        $dompdf->loadHtml( $html, 'UTF-8' );
        $dompdf->setPaper( 'A4', $with_qr ? 'portrait' : 'portrait' );
        $dompdf->render();

        $filename = sanitize_file_name(
            sprintf(
                '%s-%s-school-%s-m%s-%s.pdf',
                $with_qr ? 'cw-qr-sheet' : 'cw-codes',
                sanitize_title( $title ),
                $school,
                $month,
                gmdate( 'Ymd-His' )
            )
        );

        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        echo $dompdf->output();
        exit;
    }

    /**
     * Authorise the current user to generate codes/QR for $campaign_id.
     * Allowed for: WC managers and the organiser who owns the campaign.
     */
    private function user_can_generate_codes_for( $campaign_id ) {
        if ( current_user_can( 'manage_woocommerce' ) || current_user_can( 'edit_products' ) ) {
            return true;
        }
        if ( $campaign_id && class_exists( 'CW_Roles' ) ) {
            return CW_Roles::user_owns_campaign( (int) $campaign_id, get_current_user_id() );
        }
        return false;
    }

    /**
     * Centralised QR image URL builder. We currently round-trip through the
     * public qrserver.com endpoint — the goal is to have a single place to
     * swap that out later (e.g. for a self-hosted endroid/qr-code generator)
     * without touching every caller.
     *
     * @param string $data    The text the QR should encode (typically a URL).
     * @param int    $size    Width/height in pixels (square).
     * @param int    $margin  Quiet-zone margin in modules (0-30).
     * @return string         Fully escaped URL safe to drop into an <img src="…">.
     */
    public static function qr_image_url( $data, $size = 200, $margin = 6 ) {
        $size   = max( 60, min( 1000, (int) $size ) );
        $margin = max( 0, min( 30, (int) $margin ) );
        return 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
             . '&margin=' . $margin
             . '&data=' . rawurlencode( (string) $data );
    }

    /**
     * Printable grid: one QR per submission code (scan → PIC form with code prefilled).
     *
     * Output formats:
     *   - html  Printable QR grid (default).
     *   - csv   text/csv with #, Submission code, PIC scan URL.
     *   - pdf   application/pdf via Dompdf — same grid, downloadable.
     */
    public function render_bulk_qr() {
        check_admin_referer( 'cw_bulk_qr' );

        $campaign_id = (int) ( $_GET['campaign_id'] ?? 0 );
        if ( ! $this->user_can_generate_codes_for( $campaign_id ) ) {
            wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), '', [ 'response' => 403 ] );
        }

        $school = CW_Submission_Code::pad_school( sanitize_text_field( $_GET['school'] ?? '001' ) );
        $month  = CW_Submission_Code::pad_month( sanitize_text_field( $_GET['month'] ?? gmdate( 'm' ) ) );
        $start  = max( 1, (int) ( $_GET['start'] ?? 1 ) );
        $count  = min( 1000, max( 1, (int) ( $_GET['count'] ?? 50 ) ) );
        $format = strtolower( sanitize_key( $_GET['format'] ?? 'html' ) );
        $title  = $campaign_id ? get_the_title( $campaign_id ) : '';

        if ( ! class_exists( 'CW_Staged_Submissions' ) ) {
            wp_die( esc_html__( 'Upload module not available.', 'creativewings-core' ), '', [ 'response' => 500 ] );
        }

        $rows = $this->build_code_rows( $campaign_id, $school, $month, $start, $count );

        if ( 'csv' === $format ) {
            $this->stream_codes_csv( $rows, $title, $school, $month );
            return;
        }
        if ( 'pdf' === $format ) {
            $this->stream_codes_pdf( $rows, $title, $school, $month, true );
            return;
        }

        header( 'Content-Type: text/html; charset=utf-8' );
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
        echo '<title>' . esc_html__( 'PIC QR sheet', 'creativewings-core' ) . '</title>';
        echo '<style>
            body{font-family:system-ui,sans-serif;margin:0;padding:14px;color:#0f172a;background:#fff}
            .hdr{margin-bottom:12px}
            .hdr h1{font-size:18px;margin:0 0 4px}
            .hdr p{margin:0;color:#555555;font-size:13px}
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

        foreach ( $rows as $row ) {
            $qr_src = self::qr_image_url( $row['url'], 128, 6 );
            echo '<div class="card">';
            echo '<img src="' . esc_url( $qr_src ) . '" width="128" height="128" alt="" loading="lazy">';
            echo '<div class="code">' . esc_html( $row['code'] ) . '</div>';
            echo '</div>';
        }

        echo '</div></body></html>';
        exit;
    }
}
