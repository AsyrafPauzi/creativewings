<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Premium WooCommerce cart & checkout presentation for Creative Wings campaigns.
 */
class CW_Checkout {

    public function __construct() {
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_assets' ], 25 );
        add_filter( 'body_class', [ $this, 'body_class' ] );

        add_action( 'woocommerce_before_checkout_form', [ $this, 'render_shell_open' ], 4 );
        add_action( 'woocommerce_after_checkout_form', [ $this, 'render_shell_close' ], 99 );
        add_action( 'woocommerce_before_cart', [ $this, 'render_shell_open' ], 4 );
        add_action( 'woocommerce_after_cart', [ $this, 'render_shell_close' ], 99 );

        add_action( 'woocommerce_checkout_before_order_review', [ $this, 'render_order_review_heading' ], 4 );
        add_filter( 'woocommerce_get_item_data', [ $this, 'polish_cart_item_data' ], 99, 2 );
        add_filter( 'woocommerce_form_field_args', [ $this, 'form_field_args' ], 10, 3 );
        add_filter( 'woocommerce_checkout_fields', [ $this, 'checkout_field_classes' ] );
    }

    public function enqueue_assets() {
        if ( ! function_exists( 'is_checkout' ) || ( ! is_checkout() && ! is_cart() ) ) {
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

    /**
     * @param string[] $classes
     * @return string[]
     */
    public function body_class( $classes ) {
        if ( ( function_exists( 'is_checkout' ) && is_checkout() ) || ( function_exists( 'is_cart' ) && is_cart() ) ) {
            $classes[] = 'cw-checkout-page';
        }
        return $classes;
    }

    public function render_shell_open() {
        $is_checkout = function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page();
        $title       = $is_checkout
            ? __( 'Complete your registration', 'creativewings-core' )
            : __( 'Your cart', 'creativewings-core' );
        $subtitle    = $is_checkout
            ? __( 'Review your submission details and pay securely to confirm your child’s entry.', 'creativewings-core' )
            : __( 'Review your campaign registration before checkout.', 'creativewings-core' );
        ?>
        <div class="cw-checkout-shell" data-cw-checkout-shell>
            <header class="cw-checkout-hero">
                <p class="cw-checkout-eyebrow"><?php echo esc_html( $is_checkout ? __( 'Secure checkout', 'creativewings-core' ) : __( 'Cart', 'creativewings-core' ) ); ?></p>
                <h1 class="cw-checkout-title"><?php echo esc_html( $title ); ?></h1>
                <p class="cw-checkout-subtitle"><?php echo esc_html( $subtitle ); ?></p>
            </header>
            <div class="cw-checkout-layout">
        <?php
    }

    public function render_shell_close() {
        ?>
            </div>
        </div>
        <?php
    }

    public function render_order_review_heading() {
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

        $is_claim   = ! empty( $cart_item['cw_staged_id'] ) || ! empty( $cart_item['cw_claim_code'] );
        $seen       = [];
        $polished   = [];

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
