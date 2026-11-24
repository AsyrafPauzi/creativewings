<?php
/**
 * Person card — leadership / mentor.
 *
 * Expected $args: name, role?, focus?, bio?, image, image2?, modifier?
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$args     = wp_parse_args(
	$args ?? [],
	[
		'name'     => '',
		'role'     => '',
		'focus'    => '',
		'bio'      => '',
		'image'    => '',
		'image2'   => '',
		'modifier' => '',
	]
);
$modifier = $args['modifier'] ? ' cw-p3-person--' . sanitize_html_class( $args['modifier'] ) : '';
?>
<article class="cw-p3-person<?php echo esc_attr( $modifier ); ?>" data-cw-p3-card>
	<div class="cw-p3-person__media" aria-hidden="<?php echo $args['image'] ? 'false' : 'true'; ?>">
		<?php if ( $args['image'] ) : ?>
			<img src="<?php echo esc_url( $args['image'] ); ?>" alt="<?php echo esc_attr( $args['name'] ); ?>" loading="lazy" decoding="async" width="400" height="500">
		<?php endif; ?>
		<?php if ( $args['image2'] ) : ?>
			<img src="<?php echo esc_url( $args['image2'] ); ?>" alt="" loading="lazy" decoding="async" width="400" height="500">
		<?php endif; ?>
	</div>
	<div class="cw-p3-person__body">
		<h3 class="cw-p3-person__name"><?php echo esc_html( $args['name'] ); ?></h3>
		<?php if ( $args['role'] ) : ?>
			<p class="cw-p3-person__role"><?php echo esc_html( $args['role'] ); ?></p>
		<?php endif; ?>
		<?php if ( $args['focus'] ) : ?>
			<p class="cw-p3-person__focus"><?php echo esc_html( $args['focus'] ); ?></p>
		<?php endif; ?>
		<?php if ( $args['bio'] ) : ?>
			<p class="cw-p3-person__bio"><?php echo esc_html( $args['bio'] ); ?></p>
		<?php endif; ?>
	</div>
</article>
