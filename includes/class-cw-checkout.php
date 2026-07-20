<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Unified WooCommerce cart, checkout & shop presentation.
 */
class CW_Checkout {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 25 );
        add_filter( 'body_class', [ $this, 'body_class' ] );

        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_checkout_shell_open' ], 4 );
        add_action( 'woocommerce_after_checkout_form', [ $this, 'render_shell_close' ], 99 );
        add_action( 'woocommerce_before_cart', [ $this, 'render_cart_shell_open' ], 4 );
        add_action( 'woocommerce_after_cart', [ $this, 'render_shell_close' ], 99 );

        add_action( 'woocommerce_before_main_content', [ $this, 'render_shop_shell_open' ], 4 );
        add_action( 'woocommerce_after_main_content', [ $this, 'render_shell_close' ], 99 );

        add_action( 'woocommerce_checkout_before_customer_details', [ $this, 'checkout_columns_open' ], 2 );
        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_claim_order_notice' ], 15 );
        add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'checkout_columns_sidebar_open' ], 8 );
        add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'order_review_stack_open' ], 9 );
        add_action( 'woocommerce_checkout_after_order_review', [ $this, 'order_review_stack_close' ], 5 );
        add_action( 'woocommerce_checkout_after_order_review', [ $this, 'checkout_columns_close' ], 99 );
        add_action( 'woocommerce_review_order_after_payment', [ $this, 'checkout_columns_close' ], 999 );

        add_filter( 'woocommerce_get_item_data', [ $this, 'polish_cart_item_data' ], 99, 2 );
        add_filter( 'woocommerce_form_field_args', [ $this, 'form_field_args' ], 10, 3 );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_field_classes' ] );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'maybe_hide_shipping_fields' ], 20 );

        // Phase 1: congratulations modal → campaign product page
        add_action( 'woocommerce_thankyou', [ $this, 'render_success_redirect_modal' ], 5 );
    }

    public function enqueue_assets() {
        if ( ! $this->is_commerce_page() ) {
            return;
        }

        wp_enqueue_style(
            'cw-fonts-inter',
            'https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap',
            [],
            null
        );
        wp_enqueue_style(
            'cw-style-checkout',
            CW_URL . 'assets/css/cw-style-checkout.css',
            [ 'cw-style-general', 'cw-fonts-inter' ],
            CW_VERSION
        );
    }

    private function is_commerce_page() {
        if ( ! function_exists( 'is_woocommerce' ) ) {
            return false;
        }
        return is_cart()
            || is_checkout()
            || ( function_exists( 'is_order_received_page' ) && is_order_received_page() )
            || is_shop()
            || is_product()
            || is_product_category()
            || is_product_tag();
    }

    /**
     * @param string[] $classes
     * @return string[]
     */
    public function body_class( $classes ) {
        if ( $this->is_commerce_page() ) {
            $classes[] = 'cw-commerce-page';
        }
        if ( function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
            if ( $this->cart_has_school_claim() ) {
                $classes[] = 'cw-checkout-school-claim';
            }
            if ( WC()->cart && ! WC()->cart->needs_shipping_address() ) {
                $classes[] = 'cw-checkout-no-shipping-col';
            }
        }
        return $classes;
    }

    private function render_shell_header( $eyebrow, $title, $subtitle ) {
        ?>
        <header class="cw-commerce-hero">
            <p class="cw-commerce-eyebrow"><?php echo esc_html( $eyebrow ); ?></p>
            <h1 class="cw-commerce-title"><?php echo esc_html( $title ); ?></h1>
            <?php if ( $subtitle ) : ?>
                <p class="cw-commerce-subtitle"><?php echo esc_html( $subtitle ); ?></p>
            <?php endif; ?>
        </header>
        <?php
    }

    public function render_checkout_shell_open() {
        if ( is_order_received_page() ) {
            return;
        }
        ?>
        <div class="cw-commerce-shell cw-commerce-shell--checkout">
            <?php
            $this->render_shell_header(
                __( 'Secure checkout', 'creativewings-core' ),
                __( 'Complete your registration', 'creativewings-core' ),
                ''
            );
            ?>
            <div class="cw-commerce-body">
        <?php
    }

    public function render_cart_shell_open() {
        ?>
        <div class="cw-commerce-shell cw-commerce-shell--cart">
            <?php
            $this->render_shell_header(
                __( 'Cart', 'creativewings-core' ),
                __( 'Your cart', 'creativewings-core' ),
                __( 'Review your campaign registration before checkout.', 'creativewings-core' )
            );
            ?>
            <div class="cw-commerce-body cw-commerce-body--cart">
        <?php
    }

    public function render_shop_shell_open() {
        if ( is_product() ) {
            return;
        }
        ?>
        <div class="cw-commerce-shell cw-commerce-shell--shop">
            <?php
            $this->render_shell_header(
                __( 'Campaigns', 'creativewings-core' ),
                __( 'Explore activities & competitions', 'creativewings-core' ),
                ''
            );
            ?>
            <div class="cw-commerce-body cw-commerce-body--shop">
        <?php
    }

    public function render_shell_close() {
        echo '</div></div>';
    }

    public function checkout_columns_open() {
        echo '<div class="cw-checkout-grid"><div class="cw-checkout-grid__main">';
    }

    public function checkout_columns_sidebar_open() {
        echo '</div><div class="cw-checkout-grid__sidebar">';
    }

    private static $checkout_columns_closed = false;

    public function checkout_columns_close() {
        if ( self::$checkout_columns_closed ) {
            return;
        }
        self::$checkout_columns_closed = true;
        echo '</div></div>';
    }

    /**
     * Claim-flow info note — rendered at top of order sidebar (above "Your order").
     */
    public function render_claim_order_notice() {
        if ( is_order_received_page() || ! $this->cart_has_school_claim() ) {
            return;
        }
        echo '<div class="cw-checkout-claim-notice">';
        echo '<p class="cw-order-review-note">' . esc_html__( 'School-uploaded artwork is already linked to your submission code.', 'creativewings-core' ) . '</p>';
        echo '</div>';
    }

    public function order_review_stack_open() {
        echo '<div class="cw-order-review-stack">';
    }

    public function order_review_stack_close() {
        echo '</div>';
    }

    private function cart_has_school_claim() {
        if ( ! WC()->cart ) {
            return false;
        }
        foreach ( WC()->cart->get_cart() as $item ) {
            if ( ! empty( $item['cw_staged_id'] ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Drop empty shipping column when cart does not need a separate shipping address.
     *
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function maybe_hide_shipping_fields( $fields ) {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return $fields;
        }
        if ( WC()->cart && ! WC()->cart->needs_shipping_address() ) {
            unset( $fields['shipping'] );
        }
        return $fields;
    }

    /**
     * @param array<int, array<string, mixed>> $item_data
     * @param array<string, mixed>           $cart_item
     * @return array<int, array<string, mixed>>
     */
    public function polish_cart_item_data( $item_data, $cart_item ) {
        if ( empty( $item_data ) ) {
            return $item_data;
        }

        $is_claim = ! empty( $cart_item['cw_staged_id'] ) || ! empty( $cart_item['cw_claim_code'] );
        $seen     = [];
        $polished = [];

        foreach ( $item_data as $row ) {
            $label = (string) ( $row['name'] ?? $row['key'] ?? '' );
            $value = $row['value'] ?? $row['display'] ?? '';

            $label = preg_replace( '/^Entry \d+:\s*/', '', $label );
            $label = trim( $label );
            if ( '' === $label ) {
                continue;
            }

            $dedupe_key = strtolower( wp_strip_all_tags( $label ) );
            if ( isset( $seen[ $dedupe_key ] ) ) {
                continue;
            }

            $plain_value = trim( wp_strip_all_tags( (string) $value ) );
            if ( '' === $plain_value ) {
                continue;
            }

            if ( self::is_url_value( $plain_value ) ) {
                $value = sprintf(
                    '<a href="%s" class="cw-checkout-meta-link" target="_blank" rel="noopener noreferrer">%s</a>',
                    esc_url( $plain_value ),
                    esc_html( self::link_label_for_field( $label ) )
                );
            } elseif ( $is_claim && strlen( $plain_value ) > 80 ) {
                $value = '<span class="cw-checkout-meta-truncate">' . esc_html( $plain_value ) . '</span>';
            } else {
                $value = esc_html( $plain_value );
            }

            $seen[ $dedupe_key ] = true;
            $polished[]          = [
                'key'     => $label,
                'name'    => $label,
                'value'   => $value,
                'display' => $value,
            ];
        }

        return $polished;
    }

    private static function is_url_value( $value ) {
        return (bool) preg_match( '#^https?://#i', $value );
    }

    private static function link_label_for_field( $label ) {
        $l = strtolower( $label );
        if ( str_contains( $l, 'artwork' ) || str_contains( $l, 'image' ) || str_contains( $l, 'document' ) ) {
            return __( 'View artwork', 'creativewings-core' );
        }
        return __( 'View file', 'creativewings-core' );
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function form_field_args( $args, $key, $value ) {
        if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
            return $args;
        }
        if ( ! isset( $args['class'] ) || ! is_array( $args['class'] ) ) {
            $args['class'] = [];
        }
        $args['class'][] = 'cw-checkout-field';
        if ( 'cw_checkout_message' === $key ) {
            $args['class'][] = 'cw-checkout-message-field';
        }
        return $args;
    }

    /**
     * @param array<string, array<string, mixed>> $fields
     * @return array<string, array<string, mixed>>
     */
    public function checkout_field_classes( $fields ) {
        foreach ( $fields as $group => &$group_fields ) {
            if ( ! is_array( $group_fields ) ) {
                continue;
            }
            foreach ( $group_fields as $key => &$field ) {
                if ( ! isset( $field['class'] ) || ! is_array( $field['class'] ) ) {
                    $field['class'] = [];
                }
                $field['class'][] = 'cw-checkout-input-row';
                if ( in_array( $group, [ 'billing', 'shipping' ], true ) ) {
                    if ( ! isset( $field['input_class'] ) || ! is_array( $field['input_class'] ) ) {
                        $field['input_class'] = [];
                    }
                    $field['input_class'][] = 'cw-checkout-input';
                }
            }
        }
        return $fields;
    }

    /**
     * Congrats modal on order-received; after ~5s redirect to the campaign product page.
     *
     * @param int $order_id
     */
    public function render_success_redirect_modal( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        $campaign = $this->resolve_campaign_from_order( $order );
        if ( ! $campaign ) {
            return;
        }

        $redirect_url = $campaign['url'];
        $title        = $campaign['title'];
        $seconds      = 5;

        // Ensure checkout CSS is present on thank-you (is_checkout is true, but belt-and-braces).
        wp_enqueue_style(
            'cw-style-checkout',
            CW_URL . 'assets/css/cw-style-checkout.css',
            [ 'cw-style-general' ],
            CW_VERSION
        );
        ?>
        <div id="cw-success-redirect-modal" class="cw-success-modal" role="dialog" aria-modal="true" aria-labelledby="cw-success-redirect-title" data-redirect-url="<?php echo esc_url( $redirect_url ); ?>" data-seconds="<?php echo (int) $seconds; ?>">
            <div class="cw-success-modal__backdrop"></div>
            <div class="cw-success-modal__panel">
                <div class="cw-success-modal__icon" aria-hidden="true">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="24" fill="#DCFCE7"/>
                        <path d="M14 24.5L21 31.5L34 16.5" stroke="#16A34A" stroke-width="3.2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </div>
                <h2 id="cw-success-redirect-title" class="cw-success-modal__title"><?php esc_html_e( 'Congratulations!', 'creativewings-core' ); ?></h2>
                <p class="cw-success-modal__text">
                    <?php
                    echo esc_html(
                        sprintf(
                            /* translators: %s: campaign title */
                            __( 'Your registration for “%s” is confirmed.', 'creativewings-core' ),
                            $title
                        )
                    );
                    ?>
                </p>
                <p class="cw-success-modal__countdown">
                    <?php
                    echo wp_kses_post(
                        sprintf(
                            /* translators: %s: seconds remaining (HTML) */
                            __( 'Taking you back to the campaign in %s…', 'creativewings-core' ),
                            '<strong id="cw-success-redirect-count">' . (int) $seconds . '</strong>'
                        )
                    );
                    ?>
                </p>
                <a class="cw-success-modal__cta" href="<?php echo esc_url( $redirect_url ); ?>">
                    <?php esc_html_e( 'Go to campaign now', 'creativewings-core' ); ?>
                </a>
            </div>
        </div>
        <script>
        (function () {
            var root = document.getElementById('cw-success-redirect-modal');
            if (!root) return;
            var url = root.getAttribute('data-redirect-url') || '';
            var left = parseInt(root.getAttribute('data-seconds') || '5', 10);
            var countEl = document.getElementById('cw-success-redirect-count');
            if (!url) return;

            document.documentElement.classList.add('cw-success-modal-open');

            var timer = window.setInterval(function () {
                left -= 1;
                if (countEl) countEl.textContent = String(Math.max(left, 0));
                if (left <= 0) {
                    window.clearInterval(timer);
                    window.location.href = url;
                }
            }, 1000);
        })();
        </script>
        <?php
    }

    /**
     * First CW campaign product on the order (product ID = campaign page).
     *
     * @param WC_Order $order
     * @return array{id:int,url:string,title:string}|null
     */
    private function resolve_campaign_from_order( $order ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return null;
        }

        foreach ( $order->get_items() as $item ) {
            if ( ! ( $item instanceof WC_Order_Item_Product ) ) {
                continue;
            }

            $product_id = (int) $item->get_product_id();
            if ( $product_id <= 0 ) {
                continue;
            }

            if ( ! $this->product_is_cw_campaign( $product_id, $item ) ) {
                continue;
            }

            $url = get_permalink( $product_id );
            if ( ! $url ) {
                continue;
            }

            return [
                'id'    => $product_id,
                'url'   => $url,
                'title' => get_the_title( $product_id ) ?: __( 'campaign', 'creativewings-core' ),
            ];
        }

        return null;
    }

    /**
     * @param int                  $product_id
     * @param WC_Order_Item_Product $item
     */
    private function product_is_cw_campaign( $product_id, $item ) {
        if ( get_post_meta( $product_id, 'cw_campaign_serial', true ) ) {
            return true;
        }

        if ( $item->get_meta( '_cw_participant_data' ) || $item->get_meta( '_cw_staged_id' ) || $item->get_meta( '_cw_addons_data' ) ) {
            return true;
        }

        if ( class_exists( 'CW_Design_Submission' ) ) {
            if ( $item->get_meta( '_' . CW_Design_Submission::CART_FLAG )
                || $item->get_meta( '_' . CW_Design_Submission::CART_ARTWORK_ID )
                || $item->get_meta( '_' . CW_Design_Submission::CART_ARTWORK_IDS ) ) {
                return true;
            }
        }

        return false;
    }
}
