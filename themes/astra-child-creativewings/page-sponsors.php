<?php
/**
 * Template for page slug: sponsors.
 *
 * Full-bleed partners stage — cloud sky, open logo grids, purposeful GSAP.
 * Plus sales packages + interest form.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$packages = [
	'champion'  => [
		'title' => __( 'Champion', 'cw-astra-child' ),
		'blurb' => __( 'Lead partner visibility across flagship campaigns and brand story moments.', 'cw-astra-child' ),
	],
	'partner'   => [
		'title' => __( 'Partner', 'cw-astra-child' ),
		'blurb' => __( 'Named support on selected campaigns with logo placement and social mentions.', 'cw-astra-child' ),
	],
	'supporter' => [
		'title' => __( 'Supporter', 'cw-astra-child' ),
		'blurb' => __( 'Community-tier presence that helps more young talents join and create.', 'cw-astra-child' ),
	],
	'inkind'    => [
		'title' => __( 'In-kind', 'cw-astra-child' ),
		'blurb' => __( 'Products, venues, media, or services that power campaigns on the ground.', 'cw-astra-child' ),
	],
];

$form_ok  = isset( $_GET['cw_sponsor_ok'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$form_err = isset( $_GET['cw_sponsor_err'] ) ? sanitize_key( wp_unslash( $_GET['cw_sponsor_err'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$students_reached = class_exists( 'CW_Business_Reports' )
	? (int) CW_Business_Reports::count_students_reached()
	: 0;
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--sponsors" data-cw-phase2 data-cw-sponsors>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-sponsors-hero" data-cw-phase2-hero>
		<div class="cw-sponsors-hero__grid">
			<div class="cw-sponsors-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'Partners', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-sponsors-hero__title" data-cw-phase2-line>
					<?php esc_html_e( 'Our Sponsors', 'cw-astra-child' ); ?>
				</h1>
				<p class="cw-phase2__intro cw-sponsors-hero__intro" data-cw-sponsors-scrub>
					<?php esc_html_e( 'Brand partners who help young talents soar — supporting campaigns that create real impact.', 'cw-astra-child' ); ?>
				</p>
				<div class="cw-sponsors-hero__actions">
					<a class="cw-sponsors-cta cw-sponsors-cta--primary" href="#cw-sponsor-interest">
						<?php esc_html_e( 'Become a partner', 'cw-astra-child' ); ?>
					</a>
					<a class="cw-sponsors-cta cw-sponsors-cta--ghost" href="<?php echo esc_url( home_url( '/get-started/' ) ); ?>">
						<?php esc_html_e( 'Start a campaign', 'cw-astra-child' ); ?>
					</a>
				</div>
			</div>
			<div class="cw-sponsors-hero__aside" aria-hidden="true" data-cw-sponsors-aside>
				<span class="cw-sponsors-hero__mark">SOAR</span>
			</div>
		</div>
	</header>

	<div class="cw-sponsors-marquee" data-cw-sponsors-marquee hidden>
		<div class="cw-sponsors-marquee__track" data-cw-sponsors-marquee-track></div>
	</div>

	<section class="cw-sponsors-offer" aria-labelledby="cw-sponsors-offer-heading">
		<div class="cw-sponsors-offer__inner">
			<p class="cw-phase2__kicker"><?php esc_html_e( 'Partnerships', 'cw-astra-child' ); ?></p>
			<h2 id="cw-sponsors-offer-heading" class="cw-sponsors-offer__title">
				<?php esc_html_e( 'Sponsorship packages', 'cw-astra-child' ); ?>
			</h2>
			<p class="cw-sponsors-offer__intro">
				<?php esc_html_e( 'Choose a level that matches your brand goals — we’ll tailor deliverables together.', 'cw-astra-child' ); ?>
			</p>
			<?php if ( $students_reached > 0 ) : ?>
				<p class="cw-sponsors-offer__proof">
					<?php
					printf(
						/* translators: %s: formatted student count */
						wp_kses(
							__( '<strong>%s</strong> students reached through Creative Wings campaigns', 'cw-astra-child' ),
							[ 'strong' => [] ]
						),
						esc_html( number_format_i18n( $students_reached ) )
					);
					?>
				</p>
			<?php endif; ?>
			<div class="cw-sponsors-offer__grid">
				<?php foreach ( $packages as $key => $pkg ) : ?>
					<article class="cw-sponsors-offer__tier" data-package="<?php echo esc_attr( $key ); ?>">
						<h3 class="cw-sponsors-offer__tier-title"><?php echo esc_html( $pkg['title'] ); ?></h3>
						<p class="cw-sponsors-offer__tier-blurb"><?php echo esc_html( $pkg['blurb'] ); ?></p>
						<a class="cw-sponsors-offer__tier-link" href="#cw-sponsor-interest" data-cw-sponsor-pkg="<?php echo esc_attr( $key ); ?>">
							<?php esc_html_e( 'Enquire', 'cw-astra-child' ); ?>
						</a>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</section>

	<div class="cw-phase2__body cw-sponsors-body" data-cw-phase2-body>
		<div class="cw-sponsors-stage" data-cw-sponsors-stage>
			<?php echo do_shortcode( '[cw_sponsors campaign_id="3361" heading=""]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</div>

		<aside class="cw-sponsors-quote" data-cw-sponsors-quote>
			<blockquote>
				<p><?php esc_html_e( 'Together, we help young talents soar.', 'cw-astra-child' ); ?></p>
			</blockquote>
			<p class="cw-sponsors-quote__meta">
				<?php esc_html_e( 'Creative Wings — community, brands, and media in one flight path.', 'cw-astra-child' ); ?>
			</p>
		</aside>

		<section class="cw-sponsors-interest" id="cw-sponsor-interest" aria-labelledby="cw-sponsor-interest-heading">
			<div class="cw-sponsors-interest__copy">
				<p class="cw-phase2__kicker"><?php esc_html_e( 'Next step', 'cw-astra-child' ); ?></p>
				<h2 id="cw-sponsor-interest-heading"><?php esc_html_e( 'Tell us about your brand', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Share a few details and we’ll follow up with package options and sample campaigns.', 'cw-astra-child' ); ?></p>
				<?php if ( $students_reached > 0 ) : ?>
					<p class="cw-sponsors-interest__proof">
						<?php
						printf(
							/* translators: %s: formatted student count */
							esc_html__( '%s students reached across our campaigns.', 'cw-astra-child' ),
							esc_html( number_format_i18n( $students_reached ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>

			<?php if ( $form_ok ) : ?>
				<p class="cw-sponsors-interest__flash cw-sponsors-interest__flash--ok" role="status">
					<?php esc_html_e( 'Thanks — your interest is in. We’ll be in touch soon.', 'cw-astra-child' ); ?>
				</p>
			<?php elseif ( $form_err ) : ?>
				<p class="cw-sponsors-interest__flash cw-sponsors-interest__flash--err" role="alert">
					<?php esc_html_e( 'Could not send. Please check the required fields and try again.', 'cw-astra-child' ); ?>
				</p>
			<?php endif; ?>

			<form class="cw-phase2-form cw-sponsors-interest__form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cw_sponsor_interest">
				<?php wp_nonce_field( 'cw_sponsor_interest', 'cw_sponsor_interest_nonce' ); ?>

				<label for="cw-sponsor-name"><?php esc_html_e( 'Name', 'cw-astra-child' ); ?></label>
				<input id="cw-sponsor-name" name="cw_sponsor_name" type="text" required autocomplete="name" placeholder="<?php esc_attr_e( 'Your name', 'cw-astra-child' ); ?>">

				<label for="cw-sponsor-email"><?php esc_html_e( 'Email', 'cw-astra-child' ); ?></label>
				<input id="cw-sponsor-email" name="cw_sponsor_email" type="email" required autocomplete="email" placeholder="you@brand.com">

				<label for="cw-sponsor-company"><?php esc_html_e( 'Company', 'cw-astra-child' ); ?></label>
				<input id="cw-sponsor-company" name="cw_sponsor_company" type="text" required autocomplete="organization" placeholder="<?php esc_attr_e( 'Brand or organisation', 'cw-astra-child' ); ?>">

				<label for="cw-sponsor-package"><?php esc_html_e( 'Package', 'cw-astra-child' ); ?></label>
				<select id="cw-sponsor-package" name="cw_sponsor_package" required>
					<?php foreach ( $packages as $key => $pkg ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $pkg['title'] ); ?></option>
					<?php endforeach; ?>
				</select>

				<label for="cw-sponsor-message"><?php esc_html_e( 'Message', 'cw-astra-child' ); ?></label>
				<textarea id="cw-sponsor-message" name="cw_sponsor_message" rows="4" placeholder="<?php esc_attr_e( 'Goals, timeline, or campaign interest…', 'cw-astra-child' ); ?>"></textarea>

				<div class="cw-sponsors-interest__hp" aria-hidden="true">
					<label for="cw-sponsor-website"><?php esc_html_e( 'Website', 'cw-astra-child' ); ?></label>
					<input id="cw-sponsor-website" name="cw_sponsor_website" type="text" tabindex="-1" autocomplete="off">
				</div>

				<p class="cw-phase2-form__hint"><?php esc_html_e( 'We reply from hello@creativewings.asia — usually within 1–2 business days.', 'cw-astra-child' ); ?></p>
				<button class="cw-phase2-btn cw-phase2-btn--primary" type="submit"><?php esc_html_e( 'Send interest', 'cw-astra-child' ); ?></button>
			</form>
		</section>
	</div>
</main>
<script>
(function () {
	document.querySelectorAll('[data-cw-sponsor-pkg]').forEach(function (link) {
		link.addEventListener('click', function () {
			var pkg = link.getAttribute('data-cw-sponsor-pkg');
			var select = document.getElementById('cw-sponsor-package');
			if (pkg && select) select.value = pkg;
		});
	});
})();
</script>
<?php
get_footer();
