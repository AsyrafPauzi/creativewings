<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Claim_Flow {

    public function __construct() {
        add_action( 'init', [ $this, 'register_endpoint' ] );
        add_filter( 'woocommerce_account_menu_items', [ $this, 'account_menu' ], 99 );
        add_action( 'woocommerce_account_cw-link-submission_endpoint', [ $this, 'render_endpoint' ] );

        add_action( 'admin_post_cw_claim_lookup', [ $this, 'handle_lookup' ] );
        add_action( 'admin_post_cw_claim_confirm', [ $this, 'handle_confirm' ] );

        add_filter( 'woocommerce_get_item_data', [ $this, 'display_claim_cart' ], 20, 2 );
        add_action( 'woocommerce_before_calculate_totals', [ $this, 'maybe_zero_claim_line' ], 25 );

        add_action( 'woocommerce_checkout_before_order_review', [ $this, 'checkout_message_field' ] );
        add_action( 'woocommerce_checkout_update_order_meta', [ $this, 'save_checkout_message' ] );
        add_action( 'woocommerce_checkout_process', [ $this, 'validate_checkout_message' ] );
    }

    public function register_endpoint() {
        add_rewrite_endpoint( 'cw-link-submission', EP_ROOT | EP_PAGES );
    }

    public function account_menu( $items ) {
        $new = [];
        foreach ( $items as $key => $label ) {
            $new[ $key ] = $label;
            if ( 'dashboard' === $key ) {
                $new['cw-link-submission'] = __( 'Link submission code', 'creativewings-core' );
            }
        }
        return $new;
    }

    public function render_endpoint() {
        if ( ! is_user_logged_in() ) {
            return;
        }

        $base  = wc_get_account_endpoint_url( 'cw-link-submission' );
        $step  = sanitize_text_field( $_GET['step'] ?? 'enter' );
        $token = sanitize_text_field( $_GET['claim_token'] ?? '' );

        if ( 'confirm' === $step && $token && class_exists( 'CW_Security' ) ) {
            $sess = CW_Security::get_claim_session( get_current_user_id() );
            if ( $sess && hash_equals( $sess['token'], $token ) ) {
                $this->render_confirm_step( (int) $sess['staged_id'], $base );
                return;
            }
            echo '<p class="cw-alert error">' . esc_html__( 'Session expired. Please enter your code again.', 'creativewings-core' ) . '</p>';
        }

        $this->render_enter_step( $base );
    }

    private function render_enter_step( $base ) {
        ?>
        <div class="cw-content-wrapper">
            <h2><?php esc_html_e( 'Link your submission code', 'creativewings-core' ); ?></h2>
            <p><?php esc_html_e( 'Enter the code from your school (e.g. 0020500100001).', 'creativewings-core' ); ?></p>
            <?php if ( ! empty( $_GET['error'] ) ) : ?>
                <div class="cw-alert error"><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ); ?></div>
            <?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'cw_claim_lookup', 'cw_claim_nonce' ); ?>
                <input type="hidden" name="action" value="cw_claim_lookup">
                <p>
                    <label><?php esc_html_e( 'Submission code', 'creativewings-core' ); ?></label><br>
                    <input type="text" name="submission_code" required minlength="13" maxlength="14" inputmode="numeric" class="input-text" style="width:100%;max-width:320px">
                </p>
                <button type="submit" class="button"><?php esc_html_e( 'Continue', 'creativewings-core' ); ?></button>
            </form>
        </div>
        <?php
    }

    private function render_confirm_step( $staged_id, $base ) {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d', $staged_id ), ARRAY_A );
        if ( ! $row || ( $row['status'] ?? '' ) !== 'staged' ) {
            echo '<p class="cw-alert error">' . esc_html__( 'Invalid submission.', 'creativewings-core' ) . '</p>';
            return;
        }
        $mod = $row['moderation_status'] ?? 'approved';
        if ( 'approved' !== $mod ) {
            echo '<p class="cw-alert error">' . esc_html__( 'This artwork is awaiting school approval. Please try again later.', 'creativewings-core' ) . '</p>';
            return;
        }
        $sess = class_exists( 'CW_Security' ) ? CW_Security::get_claim_session( get_current_user_id() ) : null;
        $token = $sess['token'] ?? '';
        ?>
        <div class="cw-content-wrapper">
            <h2><?php esc_html_e( 'Confirm student name', 'creativewings-core' ); ?></h2>
            <p><?php esc_html_e( 'Is this correct?', 'creativewings-core' ); ?></p>
            <p style="font-size:22px;font-weight:700;"><?php echo esc_html( $row['student_name'] ); ?></p>
            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
                <?php wp_nonce_field( 'cw_claim_confirm', 'cw_claim_nonce' ); ?>
                <input type="hidden" name="action" value="cw_claim_confirm">
                <input type="hidden" name="claim_token" value="<?php echo esc_attr( $token ); ?>">
                <input type="hidden" name="confirm" value="yes">
                <button type="submit" class="button button-primary"><?php esc_html_e( 'Correct — continue to checkout', 'creativewings-core' ); ?></button>
            </form>
            <a href="<?php echo esc_url( $base ); ?>" class="button" style="margin-left:8px"><?php esc_html_e( 'Not correct', 'creativewings-core' ); ?></a>
        </div>
        <?php
    }

    public function handle_lookup() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_lookup' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        if ( class_exists( 'CW_Security' ) ) {
            $rl = CW_Security::rate_limit( CW_Security::RATE_REGISTRATION . 'claim', 20, 3600 );
            if ( is_wp_error( $rl ) ) {
                wp_safe_redirect( add_query_arg( 'error', rawurlencode( $rl->get_error_message() ), wc_get_account_endpoint_url( 'cw-link-submission' ) ) );
                exit;
            }
        }

        $base   = wc_get_account_endpoint_url( 'cw-link-submission' );
        $parsed = CW_Submission_Code::parse( sanitize_text_field( $_POST['submission_code'] ?? '' ) );

        if ( ! $parsed['valid'] ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $parsed['error'] ), $base ) );
            exit;
        }

        $campaign_id = class_exists( 'CW_Campaign_Resolver' )
            ? CW_Campaign_Resolver::get_id_by_serial( $parsed['campaign'] )
            : 0;

        if ( ! $campaign_id || get_post_type( $campaign_id ) !== 'product' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Campaign not found for this code.' ), $base ) );
            exit;
        }
        if ( ! CW_Submission_Code::matches_campaign_serial( $parsed, $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Campaign code does not match this campaign.' ), $base ) );
            exit;
        }

        $row = CW_Staged_Submissions::get_by_code( $parsed['normalized'], $campaign_id );
        if ( ! $row ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Submission code not found.' ), $base ) );
            exit;
        }

        if ( ( $row['status'] ?? '' ) === 'claimed' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'This code is already linked to an account.' ), $base ) );
            exit;
        }

        if ( ( $row['moderation_status'] ?? 'approved' ) !== 'approved' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Artwork is not approved yet.' ), $base ) );
            exit;
        }

        $uid = get_current_user_id();
        if ( CW_Staged_Submissions::user_has_claimed_campaign( $uid, $campaign_id ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'You already linked a submission for this campaign.' ), $base ) );
            exit;
        }

        $token = class_exists( 'CW_Security' )
            ? CW_Security::set_claim_session( $uid, (int) $row['id'], $campaign_id )
            : '';

        wp_safe_redirect( add_query_arg( [ 'step' => 'confirm', 'claim_token' => $token ], $base ) );
        exit;
    }

    public function handle_confirm() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['cw_claim_nonce'] ?? '', 'cw_claim_confirm' ) ) {
            wp_die( 'Security check failed', 403 );
        }

        if ( empty( $_POST['confirm'] ) || 'yes' !== $_POST['confirm'] ) {
            wp_safe_redirect( wc_get_account_endpoint_url( 'cw-link-submission' ) );
            exit;
        }

        $uid   = get_current_user_id();
        $token = sanitize_text_field( $_POST['claim_token'] ?? '' );
        $sess  = class_exists( 'CW_Security' ) ? CW_Security::get_claim_session( $uid ) : null;

        if ( ! $sess || ! hash_equals( $sess['token'], $token ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Session expired.' ), wc_get_account_endpoint_url( 'cw-link-submission' ) ) );
            exit;
        }

        $staged_id = (int) $sess['staged_id'];
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . CW_Staged_Submissions::table() . ' WHERE id = %d', $staged_id ), ARRAY_A );

        if ( ! $row || ( $row['status'] ?? '' ) !== 'staged' ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'Invalid submission.' ), wc_get_account_endpoint_url( 'cw-link-submission' ) ) );
            exit;
        }

        if ( ! CW_Staged_Submissions::reserve_for_claim( $staged_id, $uid ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( 'This code is being claimed by another user. Try again shortly.' ), wc_get_account_endpoint_url( 'cw-link-submission' ) ) );
            exit;
        }

        $bracket = CW_Staged_Submissions::resolve_age_bracket( (int) $row['campaign_id'], CW_Staged_Submissions::get_user_birthdate( $uid ) );
        if ( is_wp_error( $bracket ) ) {
            wp_safe_redirect( add_query_arg( 'error', rawurlencode( $bracket->get_error_message() ), wc_get_account_endpoint_url( 'cw-link-submission' ) ) );
            exit;
        }

        CW_Staged_Submissions::update( $staged_id, [ 'age_bracket_key' => $bracket['key'] ] );

        if ( class_exists( 'CW_Audit_Log' ) ) {
            CW_Audit_Log::log( 'claim_checkout_start', 'staged', $staged_id, [ 'code' => $row['submission_code'] ] );
        }

        WC()->cart->empty_cart();
        WC()->cart->add_to_cart(
            (int) $row['campaign_id'],
            1,
            0,
            [],
            [
                'cw_staged_id'         => $staged_id,
                'cw_claim_code'        => $row['submission_code'],
                'cw_age_bracket_key'   => $bracket['key'],
                'cw_age_bracket_label' => $bracket['label'],
                'unique_key'           => 'cw_claim_' . $staged_id,
            ]
        );

        CW_Security::clear_claim_session( $uid );
        wp_safe_redirect( wc_get_checkout_url() );
        exit;
    }

    public function display_claim_cart( $item_data, $cart_item ) {
        if ( ! empty( $cart_item['cw_claim_code'] ) ) {
            $item_data[] = [
                'name'  => __( 'Submission code', 'creativewings-core' ),
                'value' => esc_html( $cart_item['cw_claim_code'] ),
            ];
        }
        if ( ! empty( $cart_item['cw_age_bracket_label'] ) ) {
            $item_data[] = [
                'name'  => __( 'Category', 'creativewings-core' ),
                'value' => esc_html( $cart_item['cw_age_bracket_label'] ),
            ];
        }
        return $item_data;
    }

    public function maybe_zero_claim_line( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        foreach ( $cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            foreach ( WC()->cart->get_applied_coupons() as $code ) {
                $coupon = new WC_Coupon( $code );
                $cid    = (int) get_post_meta( $coupon->get_id(), '_cw_campaign_id', true );
                if ( $cid && (int) $item['product_id'] === $cid ) {
                    $item['data']->set_price( 0 );
                }
            }
        }
    }

    public function checkout_message_field() {
        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, 'cw_enable_checkout_message', true ) !== 'yes' ) {
                return;
            }
            $label = get_post_meta( $pid, 'cw_checkout_message_label', true ) ?: __( 'Your message', 'creativewings-core' );
            $req   = get_post_meta( $pid, 'cw_checkout_message_required', true ) === 'yes';
            woocommerce_form_field(
                'cw_checkout_message',
                [
                    'type'     => 'textarea',
                    'class'    => [ 'form-row-wide' ],
                    'label'    => esc_html( $label ),
                    'required' => $req,
                ],
                WC()->checkout->get_value( 'cw_checkout_message' )
            );
            break;
        }
    }

    public function validate_checkout_message() {
        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( empty( $item['cw_staged_id'] ) ) {
                continue;
            }
            $pid = (int) $item['product_id'];
            if ( get_post_meta( $pid, 'cw_enable_checkout_message', true ) === 'yes'
                && get_post_meta( $pid, 'cw_checkout_message_required', true ) === 'yes'
                && empty( $_POST['cw_checkout_message'] ) ) {
                wc_add_notice( __( 'Please enter your message.', 'creativewings-core' ), 'error' );
            }
        }
    }

    public function save_checkout_message( $order_id ) {
        if ( empty( $_POST['cw_checkout_message'] ) ) {
            return;
        }
        $msg = sanitize_textarea_field( wp_unslash( $_POST['cw_checkout_message'] ) );
        update_post_meta( $order_id, 'cw_checkout_message', $msg );
        update_post_meta( $order_id, '_cw_campaign_product', '' );

        if ( ! WC()->cart ) {
            return;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['cw_staged_id'] ) ) {
                CW_Staged_Submissions::update( (int) $item['cw_staged_id'], [ 'checkout_message' => $msg ] );
                update_post_meta( $order_id, '_cw_campaign_product', (string) (int) $item['product_id'] );
            }
        }
    }
}
