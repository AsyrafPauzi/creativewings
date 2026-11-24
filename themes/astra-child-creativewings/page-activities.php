<?php
/**
 * Template for page slug: activities.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$hero = wp_get_attachment_image_url( CW_CHILD_HOME_HERO_ID, 'full' );
?>
<main id="primary" class="site-main cw-browse cw-browse--activities" data-cw-browse>
	<div class="cw-browse__sky" aria-hidden="true">
		<span class="cw-browse__cloud cw-browse__cloud--a" data-cw-browse-cloud></span>
		<span class="cw-browse__cloud cw-browse__cloud--b" data-cw-browse-cloud></span>
		<span class="cw-browse__cloud cw-browse__cloud--c" data-cw-browse-cloud></span>
	</div>

	<header
		class="cw-browse__hero"
		style="--cw-browse-hero-image:url('<?php echo esc_url( $hero ); ?>')"
		data-cw-browse-hero
	>
		<div class="cw-browse__hero-inner">
			<p class="cw-browse__kicker" data-cw-browse-line><?php esc_html_e( 'Activities', 'cw-astra-child' ); ?></p>
			<h1 class="cw-browse__title" data-cw-browse-line><?php esc_html_e( 'Activities and Events in Malaysia', 'cw-astra-child' ); ?></h1>
			<p class="cw-browse__intro" data-cw-browse-line><?php esc_html_e( 'Runs, art sessions, and talks. Open to families and adults. Pick one and register online.', 'cw-astra-child' ); ?></p>
			<div class="cw-browse__search" data-cw-browse-line data-cw-browse-search>
				<?php echo do_shortcode( '[custom_search_form theme="solid" scope="activities" show_scope="0"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</header>

	<div class="cw-browse__body">
		<div class="cw-browse__grid" data-cw-browse-grid>
			<?php echo do_shortcode( '[cw_activities_grid heading="" view_toggle="1" per_page="12" pagination="more"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<section class="cw-browse__promo" data-cw-browse-promo aria-label="<?php esc_attr_e( 'Competitions', 'cw-astra-child' ); ?>">
			<div class="cw-browse__promo-copy">
				<p class="cw-browse__promo-kicker" data-cw-browse-promo-line><?php esc_html_e( 'Also on Creative Wings', 'cw-astra-child' ); ?></p>
				<h2 class="cw-browse__promo-title" data-cw-browse-promo-line><?php esc_html_e( 'Art competitions for young talents', 'cw-astra-child' ); ?></h2>
				<p class="cw-browse__promo-text" data-cw-browse-promo-line><?php esc_html_e( 'Submit artwork, gather public votes and win prizes.', 'cw-astra-child' ); ?></p>
			</div>
			<div class="cw-browse__promo-actions" data-cw-browse-promo-line>
				<a class="cw-browse__btn cw-browse__btn--primary" href="<?php echo esc_url( home_url( '/competitions/' ) ); ?>"><?php esc_html_e( 'Browse competitions', 'cw-astra-child' ); ?></a>
				<a class="cw-browse__btn cw-browse__btn--ghost" href="<?php echo esc_url( home_url( '/brand-story/' ) ); ?>"><?php esc_html_e( 'Our story', 'cw-astra-child' ); ?></a>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
