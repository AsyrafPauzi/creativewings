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
        add_action( 'woocommerce_checkout_after_customer_details', [ $this, 'checkout_columns_sidebar_open' ], 8 );
        add_action( 'woocommerce_checkout_after_order_review', [ $this, 'checkout_columns_close' ], 99 );
        add_action( 'woocommerce_review_order_after_payment', [ $this, 'checkout_columns_close' ], 999 );

        add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render_order_review_heading' ], 4 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'polish_cart_item_data' ], 99, 2 );
        add_filter( 'woocommerce_form_field_args', [ $this, 'form_field_args' ], 10, 3 );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_field_classes' ] );
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

    public function render_order_review_heading() {
        $has_claim = false;
        if ( WC()->cart ) {
            foreach ( WC()->cart->get_cart() as $item ) {
                if ( ! empty( $item['cw_staged_id'] ) ) {
                    $has_claim = true;
                    break;
                }
            }
        }
        if ( ! $has_claim ) {
            return;
        }
        echo '<p class="cw-order-review-note">' . esc_html__( 'School-uploaded artwork is already linked to your submission code.', 'creativewings-core' ) . '</p>';
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
}
