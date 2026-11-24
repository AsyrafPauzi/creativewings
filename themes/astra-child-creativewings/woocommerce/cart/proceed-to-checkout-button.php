<?php
/**
 * Proceed to checkout — Continue to payment (Phase 4 Pass 2).
 *
 * @package CW_Astra_Child
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;
?>
<a href="<?php echo esc_url( wc_get_checkout_url() ); ?>" class="checkout-button button alt wc-forward cw-regflow-btn cw-regflow-btn--primary<?php echo esc_attr( wc_wp_theme_get_element_class_name( 'button' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '' ); ?>">
	<?php esc_html_e( 'Continue to payment', 'cw-astra-child' ); ?>
</a>
