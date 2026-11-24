<?php
/**
 * Phase 2 page shell — opening markup.
 *
 * Expected $args: kicker, title, intro, modifier (auth|directory|content).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$args     = wp_parse_args(
	$args ?? [],
	[
		'kicker'   => '',
		'title'    => '',
		'intro'    => '',
		'modifier' => 'content',
	]
);
$modifier = sanitize_html_class( (string) $args['modifier'] );
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--<?php echo esc_attr( $modifier ); ?>" data-cw-phase2>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero" data-cw-phase2-hero>
		<div class="cw-phase2__hero-inner">
			<?php if ( $args['kicker'] ) : ?>
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php echo esc_html( $args['kicker'] ); ?></p>
			<?php endif; ?>
			<h1 class="cw-phase2__title" data-cw-phase2-line><?php echo esc_html( $args['title'] ); ?></h1>
			<?php if ( $args['intro'] ) : ?>
				<p class="cw-phase2__intro" data-cw-phase2-line><?php echo esc_html( $args['intro'] ); ?></p>
			<?php endif; ?>
		</div>
	</header>

	<div class="cw-phase2__body" data-cw-phase2-body>
