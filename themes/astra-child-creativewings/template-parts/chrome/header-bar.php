<?php
/**
 * Site header — Elementor exit Phase 1 (parity with templates 389 / 161).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$logo_html = wp_get_attachment_image(
	CW_CHILD_LOGO_ID,
	'full',
	false,
	[
		'class'    => 'cw-header__logo',
		'alt'      => 'Creative Wings',
		'decoding' => 'async',
	]
);
$sdg_html = wp_get_attachment_image(
	CW_CHILD_SDG_ID,
	'full',
	false,
	[
		'class'    => 'cw-header__sdg-img',
		'alt'      => 'Sustainable Development Goals',
		'decoding' => 'async',
	]
);
$mobile_logo = wp_get_attachment_image(
	CW_CHILD_MOBILE_LOGO_ID,
	'full',
	false,
	[
		'alt'      => 'Creative Wings',
		'decoding' => 'async',
	]
);

$brand_story  = home_url( '/brand-story/' );
$activities   = home_url( '/activities/' );
$competitions = home_url( '/competitions/' );
$sdgs         = home_url( '/sdgs/' );
$login        = home_url( '/login/' );
$account      = home_url( '/my-account/' );
$logged_in    = is_user_logged_in();
?>
<header class="cw-header" role="banner">
	<div class="cw-header__inner">
		<div class="cw-header__left">
			<a class="cw-header__brand" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php echo $logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>

			<a class="cw-header__sdg" href="<?php echo esc_url( $sdgs ); ?>">
				<?php echo $sdg_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</a>

			<nav class="cw-header__nav" aria-label="<?php esc_attr_e( 'Primary', 'cw-astra-child' ); ?>">
				<a class="cw-header__link<?php echo cw_child_nav_is_current( '/brand-story' ) ? ' is-current' : ''; ?>"<?php echo cw_child_nav_is_current( '/brand-story' ) ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $brand_story ); ?>">BRAND<br>STORY</a>
				<a class="cw-header__link<?php echo cw_child_nav_is_current( '/activities' ) ? ' is-current' : ''; ?>"<?php echo cw_child_nav_is_current( '/activities' ) ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $activities ); ?>">ACTIVI-<br>TIES</a>
				<a class="cw-header__link<?php echo cw_child_nav_is_current( '/competitions' ) ? ' is-current' : ''; ?>"<?php echo cw_child_nav_is_current( '/competitions' ) ? ' aria-current="page"' : ''; ?> href="<?php echo esc_url( $competitions ); ?>">COMPETI-<br>TIONS</a>
			</nav>
		</div>

		<div class="cw-header__kpi" aria-label="<?php esc_attr_e( 'Site stats', 'cw-astra-child' ); ?>">
			<div class="cw-header__kpi-item">
				<span class="cw-header__kpi-label"><?php esc_html_e( 'Site Campaigns', 'cw-astra-child' ); ?></span>
				<span class="cw-header__kpi-value cw-header__kpi-value--campaigns">000<?php echo do_shortcode( '[product_count]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
			<div class="cw-header__kpi-divider" aria-hidden="true"></div>
			<div class="cw-header__kpi-item">
				<span class="cw-header__kpi-label"><?php esc_html_e( 'Site Prize Pool', 'cw-astra-child' ); ?></span>
				<span class="cw-header__kpi-value cw-header__kpi-value--prize"><?php echo do_shortcode( '[total_prize_money]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
		</div>

		<div class="cw-header__right">
			<div class="cw-header__auth">
				<?php if ( $logged_in ) : ?>
					<a class="cw-header__link" href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>">LOG-<br>OUT</a>
					<a class="cw-header__link" href="<?php echo esc_url( $account ); ?>">MY<br>ACCOUNT</a>
				<?php else : ?>
					<a class="cw-header__link" href="<?php echo esc_url( $login ); ?>">LOGIN /<br>SIGNUP</a>
				<?php endif; ?>
			</div>

			<button
				type="button"
				class="cw-header__toggle"
				data-cw-nav-toggle
				aria-expanded="false"
				aria-controls="cw-nav-panel"
				aria-label="<?php esc_attr_e( 'Open menu', 'cw-astra-child' ); ?>"
			>
				<span class="cw-header__toggle-box" aria-hidden="true">
					<span class="cw-header__toggle-line"></span>
					<span class="cw-header__toggle-line"></span>
					<span class="cw-header__toggle-line"></span>
				</span>
			</button>
		</div>
	</div>

	<div class="cw-header__overlay" data-cw-nav-overlay aria-hidden="true" hidden></div>

	<div id="cw-nav-panel" class="cw-header__drawer" data-cw-nav-panel aria-hidden="true">
		<div class="cw-header__drawer-top">
			<div class="cw-header__drawer-brand">
				<?php echo $mobile_logo; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
			<button type="button" class="cw-header__drawer-close" data-cw-nav-close aria-label="<?php esc_attr_e( 'Close menu', 'cw-astra-child' ); ?>">
				<span class="cw-header__drawer-close-icon" aria-hidden="true"></span>
			</button>
		</div>

		<nav class="cw-header__drawer-nav" aria-label="<?php esc_attr_e( 'Mobile', 'cw-astra-child' ); ?>">
			<a class="<?php echo cw_child_nav_is_current( '/brand-story' ) ? 'is-current' : ''; ?>" href="<?php echo esc_url( $brand_story ); ?>"><?php esc_html_e( 'Brand Story', 'cw-astra-child' ); ?></a>
			<a class="<?php echo cw_child_nav_is_current( '/activities' ) ? 'is-current' : ''; ?>" href="<?php echo esc_url( $activities ); ?>"><?php esc_html_e( 'Activities', 'cw-astra-child' ); ?></a>
			<a class="<?php echo cw_child_nav_is_current( '/competitions' ) ? 'is-current' : ''; ?>" href="<?php echo esc_url( $competitions ); ?>"><?php esc_html_e( 'Competitions', 'cw-astra-child' ); ?></a>
			<a href="<?php echo esc_url( $sdgs ); ?>"><?php esc_html_e( 'SDGs', 'cw-astra-child' ); ?></a>
			<?php if ( $logged_in ) : ?>
				<a href="<?php echo esc_url( $account ); ?>"><?php esc_html_e( 'My Account', 'cw-astra-child' ); ?></a>
				<a href="<?php echo esc_url( wp_logout_url( home_url( '/' ) ) ); ?>"><?php esc_html_e( 'Log out', 'cw-astra-child' ); ?></a>
			<?php else : ?>
				<a href="<?php echo esc_url( $login ); ?>"><?php esc_html_e( 'Login / Signup', 'cw-astra-child' ); ?></a>
			<?php endif; ?>
		</nav>

		<div class="cw-header__drawer-kpi" aria-label="<?php esc_attr_e( 'Site stats', 'cw-astra-child' ); ?>">
			<div class="cw-header__kpi-item">
				<span class="cw-header__kpi-label"><?php esc_html_e( 'Site Campaigns', 'cw-astra-child' ); ?></span>
				<span class="cw-header__kpi-value cw-header__kpi-value--campaigns">000<?php echo do_shortcode( '[product_count]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
			<div class="cw-header__kpi-divider" aria-hidden="true"></div>
			<div class="cw-header__kpi-item">
				<span class="cw-header__kpi-label"><?php esc_html_e( 'Site Prize Pool', 'cw-astra-child' ); ?></span>
				<span class="cw-header__kpi-value cw-header__kpi-value--prize"><?php echo do_shortcode( '[total_prize_money]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
			</div>
		</div>
	</div>
</header>
