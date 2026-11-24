<?php
/**
 * Template for page slug: contact.
 *
 * Editorial split + cloud shell.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--contact" data-cw-phase2 data-cw-story>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-story-hero" data-cw-phase2-hero>
		<div class="cw-story-hero__split">
			<div class="cw-story-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'Malaysia', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-story-hero__title" data-cw-phase2-line>
					<?php esc_html_e( 'Contact Creative Wings', 'cw-astra-child' ); ?>
				</h1>
				<p class="cw-phase2__intro cw-story-hero__intro" data-cw-story-scrub>
					<?php esc_html_e( 'Talk to us about joining a campaign, organising a school programme, or partnering as a brand or creator.', 'cw-astra-child' ); ?>
				</p>
				<div class="cw-story-hero__actions">
					<a class="cw-phase2-btn cw-phase2-btn--primary" href="https://wa.me/60126618338?text=Hi%20Creative%20Wings%2C%20I%20would%20like%20to%20enquire%20about%20..." target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WhatsApp us', 'cw-astra-child' ); ?></a>
					<a class="cw-phase2-btn cw-phase2-btn--blue" href="mailto:hello@creativewings.asia"><?php esc_html_e( 'Email', 'cw-astra-child' ); ?></a>
					<a class="cw-phase2-btn cw-phase2-btn--ghost" href="tel:+60126618338"><?php esc_html_e( 'Call', 'cw-astra-child' ); ?></a>
				</div>
			</div>
			<aside class="cw-story-hero__rail" data-cw-story-rail>
				<p class="cw-story-hero__rail-label"><?php esc_html_e( 'Reach', 'cw-astra-child' ); ?></p>
				<ul class="cw-story-hero__rail-list">
					<li><a href="tel:+60126618338">+60 12-661 8338</a></li>
					<li><a href="mailto:hello@creativewings.asia">hello@creativewings.asia</a></li>
					<li><?php esc_html_e( 'Shah Alam, Selangor', 'cw-astra-child' ); ?></li>
				</ul>
			</aside>
		</div>
	</header>

	<div class="cw-phase2__body cw-story-body" data-cw-phase2-body>
		<div class="cw-story-layout">
			<section class="cw-story-block" data-cw-story-block>
				<h2><?php esc_html_e( 'Company details', 'cw-astra-child' ); ?></h2>
				<address>
					<strong>Creative Wings Sdn Bhd (202601027243)</strong><br>
					NO. 12A, Jalan Setia Impian U13/5E<br>
					Setia Alam Seksyen U13<br>
					40170 Shah Alam, Selangor<br>
					Malaysia
				</address>

				<h2><?php esc_html_e( 'How we can help', 'cw-astra-child' ); ?></h2>
				<ul class="cw-phase2-content__list">
					<li><?php esc_html_e( 'Join a campaign or competition as a participant', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Organise or sponsor a school / community campaign', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Creator and business partnerships', 'cw-astra-child' ); ?></li>
				</ul>
				<div class="cw-phase2-actions">
					<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Browse campaigns', 'cw-astra-child' ); ?></a>
					<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/creators-directory/' ) ); ?>"><?php esc_html_e( 'Creators directory', 'cw-astra-child' ); ?></a>
				</div>
			</section>

			<section class="cw-story-block cw-story-block--form" data-cw-story-block>
				<h2><?php esc_html_e( 'Send a message', 'cw-astra-child' ); ?></h2>
				<form class="cw-phase2-form" id="cw-contact-form" action="mailto:hello@creativewings.asia" method="get" enctype="text/plain">
					<label for="cw-c-name"><?php esc_html_e( 'Name', 'cw-astra-child' ); ?></label>
					<input id="cw-c-name" name="name" type="text" required autocomplete="name" placeholder="<?php esc_attr_e( 'Your name', 'cw-astra-child' ); ?>">

					<label for="cw-c-email"><?php esc_html_e( 'Email', 'cw-astra-child' ); ?></label>
					<input id="cw-c-email" name="email" type="email" required autocomplete="email" placeholder="you@example.com">

					<label for="cw-c-topic"><?php esc_html_e( 'Topic', 'cw-astra-child' ); ?></label>
					<select id="cw-c-topic" name="topic">
						<option><?php esc_html_e( 'Join a campaign', 'cw-astra-child' ); ?></option>
						<option><?php esc_html_e( 'Organise / sponsor', 'cw-astra-child' ); ?></option>
						<option><?php esc_html_e( 'Creator partnership', 'cw-astra-child' ); ?></option>
						<option><?php esc_html_e( 'Business partnership', 'cw-astra-child' ); ?></option>
						<option><?php esc_html_e( 'Other', 'cw-astra-child' ); ?></option>
					</select>

					<label for="cw-c-msg"><?php esc_html_e( 'Message', 'cw-astra-child' ); ?></label>
					<textarea id="cw-c-msg" name="body" required placeholder="<?php esc_attr_e( 'How can we help?', 'cw-astra-child' ); ?>"></textarea>

					<p class="cw-phase2-form__hint"><?php esc_html_e( 'Opens your email app with the message ready — or use WhatsApp for a faster reply.', 'cw-astra-child' ); ?></p>
					<button class="cw-phase2-btn cw-phase2-btn--primary" type="submit"><?php esc_html_e( 'Send message', 'cw-astra-child' ); ?></button>
				</form>
			</section>
		</div>

		<section class="cw-story-map" data-cw-story-media aria-label="<?php esc_attr_e( 'Headquarters map', 'cw-astra-child' ); ?>">
			<div class="cw-story-map__copy">
				<h2><?php esc_html_e( 'Headquarters', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Shah Alam, Selangor — serving campaigns across Malaysia.', 'cw-astra-child' ); ?></p>
			</div>
			<iframe
				title="<?php esc_attr_e( 'Creative Wings office map', 'cw-astra-child' ); ?>"
				loading="lazy"
				referrerpolicy="no-referrer-when-downgrade"
				src="https://maps.google.com/maps?q=NO.%2012A%2C%20Jalan%20Setia%20Impian%20U13%2F5E%2C%20Setia%20Alam%2C%20Shah%20Alam&amp;z=15&amp;output=embed"
			></iframe>
		</section>
	</div>
</main>
<?php
get_footer();
