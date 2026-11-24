<?php
/**
 * Site footer — Elementor exit Phase 1 (parity with template 163).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$logo_html = wp_get_attachment_image(
	CW_CHILD_LOGO_ID,
	'full',
	false,
	[
		'alt'      => 'Creative Wings',
		'decoding' => 'async',
	]
);
$sdg_html = wp_get_attachment_image(
	CW_CHILD_SDG_ID,
	'full',
	false,
	[
		'alt'      => 'Sustainable Development Goals',
		'decoding' => 'async',
	]
);
$badge_html = wp_get_attachment_image(
	CW_CHILD_FOOTER_BADGE_ID,
	'full',
	false,
	[
		'alt'      => '',
		'decoding' => 'async',
	]
);
?>
<footer class="cw-footer" role="contentinfo">
	<div class="cw-footer__inner">
		<div class="cw-footer__top">
			<a class="cw-footer__logo" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>
			<div class="cw-footer__badges">
				<?php echo $sdg_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				<?php echo $badge_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<p class="cw-footer__tagline"><?php echo esc_html( '“Together, We Help Young Talents Soar”' ); ?></p>
		</div>

		<p class="cw-footer__chat">
			<a href="mailto:hello@creativewings.asia"><?php echo esc_html( 'Let’s Chat : hello@creativewings.asia' ); ?></a>
		</p>

		<?php
		wp_nav_menu(
			[
				'menu'       => 'footer',
				'container'  => false,
				'menu_class' => 'cw-footer__menu',
				'depth'      => 1,
				'fallback_cb'=> false,
			]
		);
		?>

		<p class="cw-footer__copy">
			<?php
			echo wp_kses(
				'Copyright © 2026 Creative Wings Sdn. Bhd. (202601027243) – Creative Wings.<br>All rights reserved. Created by yiboncreative.com',
				[ 'br' => [] ]
			);
			?>
		</p>
	</div>
</footer>
