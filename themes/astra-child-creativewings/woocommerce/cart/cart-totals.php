<?php
/**
 * Cart totals — ticket paybar (Phase 4 Pass 2).
 *
 * @package CW_Astra_Child
 * @version 2.3.6
 */

defined( 'ABSPATH' ) || exit;

$has_discount = (bool) WC()->cart->get_coupons() || (float) WC()->cart->get_discount_total() > 0;
$classes      = [ 'cart_totals', 'cw-regflow-totals' ];
if ( WC()->customer->has_calculated_shipping() ) {
	$classes[] = 'calculated_shipping';
}
if ( $has_discount ) {
	$classes[] = 'has-discount';
}

$competitions = get_page_by_path( 'competitions' );
$browse_url   = $competitions ? get_permalink( $competitions ) : home_url( '/competitions/' );
?>
<div class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">

	<?php do_action( 'woocommerce_before_cart_totals' ); ?>

	<div class="cw-regflow-paybar__main">
		<div class="cw-regflow-paybar__figures">
			<p class="cw-regflow-paybar__kicker"><?php esc_html_e( 'Entry fee', 'cw-astra-child' ); ?></p>
			<table cellspacing="0" class="shop_table shop_table_responsive cw-regflow-totals__table">
				<tr class="cart-subtotal">
					<th><?php esc_html_e( 'Subtotal', 'cw-astra-child' ); ?></th>
					<td data-title="<?php esc_attr_e( 'Subtotal', 'cw-astra-child' ); ?>"><?php wc_cart_totals_subtotal_html(); ?></td>
				</tr>

				<?php foreach ( WC()->cart->get_coupons() as $code => $coupon ) : ?>
					<tr class="cart-discount coupon-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
						<th><?php wc_cart_totals_coupon_label( $coupon ); ?></th>
						<td data-title="<?php echo esc_attr( wc_cart_totals_coupon_label( $coupon, false ) ); ?>"><?php wc_cart_totals_coupon_html( $coupon ); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php foreach ( WC()->cart->get_fees() as $fee ) : ?>
					<tr class="fee">
						<th><?php echo esc_html( $fee->name ); ?></th>
						<td data-title="<?php echo esc_attr( $fee->name ); ?>"><?php wc_cart_totals_fee_html( $fee ); ?></td>
					</tr>
				<?php endforeach; ?>

				<?php
				if ( wc_tax_enabled() && ! WC()->cart->display_prices_including_tax() ) {
					if ( 'itemized' === get_option( 'woocommerce_tax_total_display' ) ) {
						foreach ( WC()->cart->get_tax_totals() as $code => $tax ) { // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
							?>
							<tr class="tax-rate tax-rate-<?php echo esc_attr( sanitize_title( $code ) ); ?>">
								<th><?php echo esc_html( $tax->label ); ?></th>
								<td data-title="<?php echo esc_attr( $tax->label ); ?>"><?php echo wp_kses_post( $tax->formatted_amount ); ?></td>
							</tr>
							<?php
						}
					} else {
						?>
						<tr class="tax-total">
							<th><?php echo esc_html( WC()->countries->tax_or_vat() ); ?></th>
							<td data-title="<?php echo esc_attr( WC()->countries->tax_or_vat() ); ?>"><?php wc_cart_totals_taxes_total_html(); ?></td>
						</tr>
						<?php
					}
				}
				?>

				<?php do_action( 'woocommerce_cart_totals_before_order_total' ); ?>

				<tr class="order-total">
					<th><?php esc_html_e( 'Total due', 'cw-astra-child' ); ?></th>
					<td data-title="<?php esc_attr_e( 'Total due', 'cw-astra-child' ); ?>"><?php wc_cart_totals_order_total_html(); ?></td>
				</tr>

				<?php do_action( 'woocommerce_cart_totals_after_order_total' ); ?>
			</table>
			<p class="cw-regflow-trust"><?php esc_html_e( 'Secure checkout · CommercePay', 'cw-astra-child' ); ?></p>
		</div>

		<div class="cw-regflow-paybar__actions">
			<div class="wc-proceed-to-checkout">
				<?php do_action( 'woocommerce_proceed_to_checkout' ); ?>
			</div>
			<p class="cw-regflow-secondary">
				<a class="cw-regflow-link" href="<?php echo esc_url( $browse_url ); ?>">
					<?php esc_html_e( 'Explore campaigns', 'cw-astra-child' ); ?>
				</a>
			</p>
		</div>
	</div>

	<?php do_action( 'woocommerce_after_cart_totals' ); ?>

</div>
