<?php
/**
 * Cart — registration ticket review (Phase 4 Pass 2).
 *
 * @package CW_Astra_Child
 * @version 10.1.0
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_before_cart' );

get_template_part( 'template-parts/commerce/regflow', 'steps', [ 'current' => 'review' ] );
?>

<div class="cw-regflow cw-regflow--ticket" data-cw-regflow="review">
	<div class="cw-regflow-ticket" data-cw-regflow-ticket>

		<form class="woocommerce-cart-form cw-regflow-form" action="<?php echo esc_url( wc_get_cart_url() ); ?>" method="post">
			<?php do_action( 'woocommerce_before_cart_table' ); ?>
			<?php do_action( 'woocommerce_before_cart_contents' ); ?>

			<ul class="cw-regflow-entries" role="list">
				<?php
				foreach ( WC()->cart->get_cart() as $cart_item_key => $cart_item ) {
					$_product     = apply_filters( 'woocommerce_cart_item_product', $cart_item['data'], $cart_item, $cart_item_key );
					$product_id   = apply_filters( 'woocommerce_cart_item_product_id', $cart_item['product_id'], $cart_item, $cart_item_key );
					$product_name = apply_filters( 'woocommerce_cart_item_name', $_product->get_name(), $cart_item, $cart_item_key );

					if ( ! $_product || ! $_product->exists() || $cart_item['quantity'] <= 0 || ! apply_filters( 'woocommerce_cart_item_visible', true, $cart_item, $cart_item_key ) ) {
						continue;
					}

					$product_permalink = apply_filters( 'woocommerce_cart_item_permalink', $_product->is_visible() ? $_product->get_permalink( $cart_item ) : '', $cart_item, $cart_item_key );
					$thumbnail         = apply_filters( 'woocommerce_cart_item_thumbnail', $_product->get_image( 'woocommerce_single' ), $cart_item, $cart_item_key );
					?>
					<li class="cw-regflow-entry <?php echo esc_attr( apply_filters( 'woocommerce_cart_item_class', 'cart_item', $cart_item, $cart_item_key ) ); ?>" data-cw-regflow-entry>
						<div class="cw-regflow-entry__media">
							<?php
							if ( ! $product_permalink ) {
								echo $thumbnail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							} else {
								printf( '<a class="cw-regflow-entry__media-link" href="%s" tabindex="-1">%s</a>', esc_url( $product_permalink ), $thumbnail ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							}
							?>
						</div>
						<div class="cw-regflow-entry__body">
							<div class="cw-regflow-entry__top">
								<p class="cw-regflow-entry__kicker"><?php esc_html_e( 'Campaign entry', 'cw-astra-child' ); ?></p>
								<?php
								echo apply_filters( // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
									'woocommerce_cart_item_remove_link',
									sprintf(
										'<a role="button" href="%s" class="cw-regflow-entry__remove remove" aria-label="%s" data-product_id="%s" data-product_sku="%s">%s</a>',
										esc_url( wc_get_cart_remove_url( $cart_item_key ) ),
										esc_attr( sprintf( __( 'Remove %s from entry', 'cw-astra-child' ), wp_strip_all_tags( $product_name ) ) ),
										esc_attr( (string) $product_id ),
										esc_attr( $_product->get_sku() ),
										esc_html__( 'Remove entry', 'cw-astra-child' )
									),
									$cart_item_key
								);
								?>
							</div>
							<h2 class="cw-regflow-entry__title">
								<?php
								if ( ! $product_permalink ) {
									echo wp_kses_post( $product_name );
								} else {
									echo wp_kses_post( sprintf( '<a href="%s">%s</a>', esc_url( $product_permalink ), $product_name ) );
								}
								?>
							</h2>
							<?php
							do_action( 'woocommerce_after_cart_item_name', $cart_item, $cart_item_key );
							echo wc_get_formatted_cart_item_data( $cart_item ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
							?>
							<input type="hidden" name="cart[<?php echo esc_attr( $cart_item_key ); ?>][qty]" value="<?php echo esc_attr( (string) $cart_item['quantity'] ); ?>" />
						</div>
					</li>
					<?php
				}
				?>
			</ul>

			<?php do_action( 'woocommerce_cart_contents' ); ?>

			<?php if ( wc_coupons_enabled() ) : ?>
				<details class="cw-regflow-coupon" data-cw-regflow-coupon>
					<summary class="cw-regflow-coupon__summary"><?php esc_html_e( 'Have a school / sponsor code?', 'cw-astra-child' ); ?></summary>
					<div class="cw-regflow-coupon__panel coupon">
						<label for="coupon_code" class="screen-reader-text"><?php esc_html_e( 'Coupon code', 'cw-astra-child' ); ?></label>
						<input type="text" name="coupon_code" class="input-text" id="coupon_code" value="" placeholder="<?php esc_attr_e( 'Enter code', 'cw-astra-child' ); ?>" />
						<button type="submit" class="button cw-regflow-coupon__apply" name="apply_coupon" value="<?php esc_attr_e( 'Apply code', 'cw-astra-child' ); ?>">
							<?php esc_html_e( 'Apply code', 'cw-astra-child' ); ?>
						</button>
						<?php do_action( 'woocommerce_cart_coupon' ); ?>
					</div>
				</details>
			<?php endif; ?>

			<?php do_action( 'woocommerce_cart_actions' ); ?>
			<?php wp_nonce_field( 'woocommerce-cart', 'woocommerce-cart-nonce' ); ?>

			<?php do_action( 'woocommerce_after_cart_contents' ); ?>
			<?php do_action( 'woocommerce_after_cart_table' ); ?>
		</form>

		<?php do_action( 'woocommerce_before_cart_collaterals' ); ?>

		<aside class="cw-regflow-paybar cart-collaterals" data-cw-regflow-summary>
			<?php do_action( 'woocommerce_cart_collaterals' ); ?>
		</aside>
	</div>
</div>

<?php do_action( 'woocommerce_after_cart' ); ?>
