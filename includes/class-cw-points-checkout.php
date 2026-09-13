<?php
/**
 * Apply customer points as checkout credit (100 pts = RM1).
 * Debits only after payment succeeds.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Points_Checkout {

    const SESSION_KEY = 'cw_points_apply';
    const FEE_NAME    = 'Points credit';

    public function __construct() {
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_apply_ui' ], 12 );
        add_action( 'woocommerce_cart_calculate_fees', [ $this, 'apply_fee' ], 20 );
        add_action( 'woocommerce_checkout_update_order_review', [ $this, 'capture_posted_points' ] );
        add_action( 'wp_ajax_cw_apply_checkout_points', [ $this, 'ajax_apply' ] );
        add_action( 'wp_ajax_cw_clear_checkout_points', [ $this, 'ajax_clear' ] );

        add_action( 'woocommerce_checkout_create_order', [ $this, 'store_pending_on_order' ], 20, 2 );
        add_action( 'woocommerce_payment_complete', [ $this, 'debit_on_paid' ], 25 );
        add_action( 'woocommerce_order_status_processing', [ $this, 'debit_on_paid' ], 25 );
        add_action( 'woocommerce_order_status_completed', [ $this, 'debit_on_paid' ], 25 );
        add_action( 'woocommerce_order_status_cancelled', [ $this, 'maybe_refund_on_cancel' ], 20 );
        add_action( 'woocommerce_order_status_failed', [ $this, 'clear_unpaid_pending' ], 20 );

        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue' ] );
    }

    public function enqueue() {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
            return;
        }
        if ( ! is_user_logged_in() || ! class_exists( 'CW_Points' ) ) {
            return;
        }

        wp_register_script( 'cw-points-checkout', false, [ 'jquery', 'wc-checkout' ], CW_VERSION, true );
        wp_enqueue_script( 'cw-points-checkout' );
        wp_add_inline_script(
            'cw-points-checkout',
            "(function($){
                function refresh(){ $('body').trigger('update_checkout'); }
                $(document.body).on('click', '#cw-points-apply-btn', function(e){
                    e.preventDefault();
                    var pts = parseInt($('#cw-points-apply-input').val(), 10) || 0;
                    $.post('" . esc_url( admin_url( 'admin-ajax.php' ) ) . "', {
                        action: 'cw_apply_checkout_points',
                        points: pts,
                        nonce: '" . esc_js( wp_create_nonce( 'cw_checkout_points' ) ) . "'
                    }).always(refresh);
                });
                $(document.body).on('click', '#cw-points-clear-btn', function(e){
                    e.preventDefault();
                    $.post('" . esc_url( admin_url( 'admin-ajax.php' ) ) . "', {
                        action: 'cw_clear_checkout_points',
                        nonce: '" . esc_js( wp_create_nonce( 'cw_checkout_points' ) ) . "'
                    }).always(refresh);
                });
            })(jQuery);"
        );
    }

    private function session_points() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return 0;
        }
        return max( 0, (int) WC()->session->get( self::SESSION_KEY, 0 ) );
    }

    private function set_session_points( $points ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) {
            return;
        }
        $points = max( 0, (int) $points );
        if ( $points <= 0 ) {
            WC()->session->set( self::SESSION_KEY, 0 );
            return;
        }
        WC()->session->set( self::SESSION_KEY, $points );
    }

    /**
     * Max points usable = min(balance, floor(cart_due * 100)), respecting MIN_CHECKOUT_SPEND.
     */
    public static function max_applicable_points( $user_id = 0 ) {
        $user_id = $user_id ?: get_current_user_id();
        if ( $user_id <= 0 || ! class_exists( 'CW_Points' ) || ! function_exists( 'WC' ) || ! WC()->cart ) {
            return 0;
        }

        $balance = CW_Points::get_balance( $user_id );
        if ( $balance < CW_Points::MIN_CHECKOUT_SPEND ) {
            return 0;
        }

        // Due before points fee (fees are rebuilt each calc; exclude other fees that aren't ours).
        $due = (float) WC()->cart->get_cart_contents_total()
            + (float) WC()->cart->get_shipping_total()
            + (float) WC()->cart->get_taxes_total( false, false );
        foreach ( WC()->cart->get_fees() as $fee ) {
            if ( ! isset( $fee->name ) || $fee->name === self::FEE_NAME ) {
                continue;
            }
            $due += (float) $fee->total;
        }
        $due = max( 0, $due );
        $max_by_due = (int) floor( $due * CW_Points::PTS_PER_RM );

        return max( 0, min( $balance, $max_by_due ) );
    }

    public function capture_posted_points( $posted_data ) {
        parse_str( $posted_data, $data );
        if ( isset( $data['cw_points_apply'] ) ) {
            $this->set_session_points( absint( $data['cw_points_apply'] ) );
        }
    }

    public function ajax_apply() {
        check_ajax_referer( 'cw_checkout_points', 'nonce' );
        if ( ! is_user_logged_in() ) {
            wp_send_json_error( [ 'message' => 'login' ], 403 );
        }
        $requested = absint( $_POST['points'] ?? 0 );
        $max       = self::max_applicable_points();
        $apply     = min( $requested, $max );
        if ( $apply > 0 && $apply < CW_Points::MIN_CHECKOUT_SPEND ) {
            $apply = 0;
        }
        $this->set_session_points( $apply );
        wp_send_json_success( [ 'points' => $apply ] );
    }

    public function ajax_clear() {
        check_ajax_referer( 'cw_checkout_points', 'nonce' );
        $this->set_session_points( 0 );
        wp_send_json_success();
    }

    public function apply_fee( $cart ) {
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            return;
        }
        if ( ! is_user_logged_in() || ! class_exists( 'CW_Points' ) ) {
            return;
        }

        $points = $this->session_points();
        if ( $points < CW_Points::MIN_CHECKOUT_SPEND ) {
            return;
        }

        $max = self::max_applicable_points();
        if ( $points > $max ) {
            $points = $max;
            $this->set_session_points( $points );
        }
        if ( $points < CW_Points::MIN_CHECKOUT_SPEND ) {
            return;
        }

        $credit = CW_Points::points_to_rm( $points );
        if ( $credit <= 0 ) {
            return;
        }

        $cart->add_fee( self::FEE_NAME, -1 * $credit, false );
    }

    public function render_apply_ui() {
        if ( ! is_user_logged_in() || ! class_exists( 'CW_Points' ) ) {
            return;
        }
        if ( ! $this->cart_has_cw_campaign() ) {
            return;
        }

        $uid     = get_current_user_id();
        $balance = CW_Points::get_balance( $uid );
        if ( $balance < CW_Points::MIN_CHECKOUT_SPEND ) {
            return;
        }

        $applied = $this->session_points();
        $max     = self::max_applicable_points( $uid );
        $rm_bal  = CW_Points::points_to_rm( $balance );
        ?>
        <div class="cw-points-checkout" style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:16px 18px;margin:0 0 18px;">
            <h3 style="margin:0 0 6px;font-size:1.05rem;"><?php esc_html_e( 'Use your points', 'creativewings-core' ); ?></h3>
            <p style="margin:0 0 12px;color:#64748b;font-size:14px;">
                <?php
                printf(
                    /* translators: 1: balance 2: RM value */
                    esc_html__( 'You have %1$s points (≈ RM%2$s). 100 points = RM1.00 checkout credit.', 'creativewings-core' ),
                    number_format( $balance ),
                    number_format( $rm_bal, 2 )
                );
                ?>
            </p>
            <div style="display:flex;flex-wrap:wrap;gap:8px;align-items:center;">
                <label for="cw-points-apply-input" class="screen-reader-text"><?php esc_html_e( 'Points to apply', 'creativewings-core' ); ?></label>
                <input type="number" id="cw-points-apply-input" min="<?php echo (int) CW_Points::MIN_CHECKOUT_SPEND; ?>" max="<?php echo (int) $max; ?>" step="1" value="<?php echo (int) ( $applied ?: min( $balance, $max ) ); ?>" style="width:120px;">
                <button type="button" class="button" id="cw-points-apply-btn"><?php esc_html_e( 'Apply points', 'creativewings-core' ); ?></button>
                <?php if ( $applied > 0 ) : ?>
                    <button type="button" class="button" id="cw-points-clear-btn"><?php esc_html_e( 'Clear', 'creativewings-core' ); ?></button>
                    <span style="color:#0f766e;font-size:14px;">
                        <?php
                        printf(
                            /* translators: 1: points 2: RM */
                            esc_html__( 'Applying %1$s pts (−RM%2$s)', 'creativewings-core' ),
                            number_format( $applied ),
                            number_format( CW_Points::points_to_rm( $applied ), 2 )
                        );
                        ?>
                    </span>
                <?php endif; ?>
            </div>
            <p style="margin:10px 0 0;color:#94a3b8;font-size:12px;">
                <?php
                printf(
                    /* translators: %d: max points */
                    esc_html__( 'Max for this order: %s points. Points are deducted after payment succeeds.', 'creativewings-core' ),
                    number_format( $max )
                );
                ?>
            </p>
        </div>
        <?php
    }

    private function cart_has_cw_campaign() {
        if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            $product_id = (int) ( $item['product_id'] ?? 0 );
            if ( $product_id && get_post_meta( $product_id, 'cw_campaign_serial', true ) ) {
                return true;
            }
            if ( ! empty( $item['_cw_participant_data'] ) || ! empty( $item['_cw_staged_id'] ) || ! empty( $item['_cw_addons_data'] ) ) {
                return true;
            }
            if ( class_exists( 'CW_Design_Submission' ) ) {
                $flag = CW_Design_Submission::CART_FLAG;
                $art  = CW_Design_Submission::CART_ARTWORK_ID;
                if ( ! empty( $item[ $flag ] ) || ! empty( $item[ $art ] ) ) {
                    return true;
                }
            }
        }
        return false;
    }

    public function store_pending_on_order( $order, $data ) {
        $points = $this->session_points();
        if ( $points < CW_Points::MIN_CHECKOUT_SPEND || ! class_exists( 'CW_Points' ) ) {
            return;
        }
        $max = self::max_applicable_points( (int) $order->get_user_id() );
        $points = min( $points, $max, CW_Points::get_balance( (int) $order->get_user_id() ) );
        if ( $points < CW_Points::MIN_CHECKOUT_SPEND ) {
            return;
        }
        $order->update_meta_data( '_cw_points_pending', $points );
        $order->update_meta_data( '_cw_points_pending_rm', CW_Points::points_to_rm( $points ) );
    }

    public function debit_on_paid( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! class_exists( 'CW_Points' ) ) {
            return;
        }
        if ( $order->get_meta( CW_Points::ORDER_META_SPENT ) ) {
            return; // Already debited.
        }

        $points = (int) $order->get_meta( '_cw_points_pending' );
        if ( $points <= 0 ) {
            // Fallback: detect fee on order.
            foreach ( $order->get_fees() as $fee ) {
                if ( $fee->get_name() === self::FEE_NAME ) {
                    $rm = abs( (float) $fee->get_total() );
                    $points = CW_Points::rm_to_points( $rm );
                    break;
                }
            }
        }
        if ( $points <= 0 ) {
            return;
        }

        $user_id = (int) $order->get_user_id();
        if ( $user_id <= 0 ) {
            return;
        }

        $ok = CW_Points::debit(
            $user_id,
            $points,
            'spend_checkout',
            'order',
            (int) $order_id,
            sprintf( 'Checkout credit on order #%d', $order_id )
        );

        if ( $ok ) {
            $order->update_meta_data( CW_Points::ORDER_META_SPENT, $points );
            $order->delete_meta_data( '_cw_points_pending' );
            $order->save();
            $this->set_session_points( 0 );
        }
    }

    public function maybe_refund_on_cancel( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order || ! class_exists( 'CW_Points' ) ) {
            return;
        }
        $spent = (int) $order->get_meta( CW_Points::ORDER_META_SPENT );
        if ( $spent <= 0 || $order->get_meta( '_cw_points_refunded' ) ) {
            // Clear pending if never paid.
            if ( $order->get_meta( '_cw_points_pending' ) ) {
                $order->delete_meta_data( '_cw_points_pending' );
                $order->save();
            }
            return;
        }
        CW_Points::credit_refund(
            (int) $order->get_user_id(),
            $spent,
            'order',
            (int) $order_id,
            'Refund points — order cancelled'
        );
        $order->update_meta_data( '_cw_points_refunded', 'yes' );
        $order->save();
    }

    public function clear_unpaid_pending( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }
        if ( $order->get_meta( CW_Points::ORDER_META_SPENT ) ) {
            return;
        }
        if ( $order->get_meta( '_cw_points_pending' ) ) {
            $order->delete_meta_data( '_cw_points_pending' );
            $order->save();
        }
    }
}
