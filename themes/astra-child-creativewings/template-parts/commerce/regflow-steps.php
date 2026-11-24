<?php
/**
 * Registration flow step rail (cart → checkout).
 *
 * @package CW_Astra_Child
 *
 * @var array{current?: string} $args
 */

defined( 'ABSPATH' ) || exit;

$current = isset( $args['current'] ) ? (string) $args['current'] : 'review';
$cart_url = function_exists( 'wc_get_cart_url' ) ? wc_get_cart_url() : home_url( '/cart/' );
$pay_url  = function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/checkout/' );
$has_items = function_exists( 'WC' ) && WC()->cart && WC()->cart->get_cart_contents_count() > 0;

$steps = [
	'review' => [
		'label' => __( 'Review entry', 'cw-astra-child' ),
		'url'   => $cart_url,
	],
	'pay'    => [
		'label' => __( 'Complete & pay', 'cw-astra-child' ),
		'url'   => $pay_url,
	],
];
?>
<nav class="cw-regflow-steps" aria-label="<?php esc_attr_e( 'Registration steps', 'cw-astra-child' ); ?>" data-cw-regflow-steps>
	<ol class="cw-regflow-steps__list">
		<?php
		$i = 0;
		foreach ( $steps as $key => $step ) :
			$i++;
			$is_current = ( $key === $current );
			$is_done    = ( 'pay' === $current && 'review' === $key );
			$can_link   = ( 'review' === $key ) || ( 'pay' === $key && $has_items );
			$classes    = [ 'cw-regflow-steps__item' ];
			if ( $is_current ) {
				$classes[] = 'is-current';
			}
			if ( $is_done ) {
				$classes[] = 'is-done';
			}
			?>
			<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
				<?php if ( $can_link && ! $is_current ) : ?>
					<a class="cw-regflow-steps__link" href="<?php echo esc_url( $step['url'] ); ?>">
						<span class="cw-regflow-steps__num" aria-hidden="true"><?php echo (int) $i; ?></span>
						<span class="cw-regflow-steps__label"><?php echo esc_html( $step['label'] ); ?></span>
					</a>
				<?php else : ?>
					<span class="cw-regflow-steps__link" <?php echo $is_current ? 'aria-current="step"' : 'aria-disabled="true"'; ?>>
						<span class="cw-regflow-steps__num" aria-hidden="true"><?php echo (int) $i; ?></span>
						<span class="cw-regflow-steps__label"><?php echo esc_html( $step['label'] ); ?></span>
					</span>
				<?php endif; ?>
			</li>
			<?php if ( $i < count( $steps ) ) : ?>
				<li class="cw-regflow-steps__rule" aria-hidden="true"></li>
			<?php endif; ?>
		<?php endforeach; ?>
	</ol>
</nav>
