<?php
/**
 * Empty cart — waiting registration (Phase 4 Pass 2).
 *
 * @package CW_Astra_Child
 * @version 7.0.1
 */

defined( 'ABSPATH' ) || exit;

remove_action( 'woocommerce_cart_is_empty', 'wc_empty_cart_message', 10 );
do_action( 'woocommerce_cart_is_empty' );

$competitions = get_page_by_path( 'competitions' );
$browse_url   = $competitions ? get_permalink( $competitions ) : home_url( '/competitions/' );

get_template_part( 'template-parts/commerce/regflow', 'steps', [ 'current' => 'review' ] );
?>
<div class="cw-regflow cw-regflow--empty" data-cw-regflow="empty">
	<div class="cw-regflow-empty">
		<p class="cw-regflow-empty__eyebrow"><?php esc_html_e( 'Registration', 'cw-astra-child' ); ?></p>
		<h2 class="cw-regflow-empty__title"><?php esc_html_e( 'Your registration is waiting', 'cw-astra-child' ); ?></h2>
		<p class="cw-regflow-empty__text"><?php esc_html_e( 'You haven’t selected a campaign yet. Explore competitions and activities to get started.', 'cw-astra-child' ); ?></p>
		<p class="cw-regflow-empty__cta return-to-shop">
			<a class="button cw-regflow-btn cw-regflow-btn--primary" href="<?php echo esc_url( $browse_url ); ?>">
				<?php esc_html_e( 'Explore campaigns', 'cw-astra-child' ); ?>
			</a>
		</p>
	</div>
</div>
