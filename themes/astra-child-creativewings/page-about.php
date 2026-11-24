<?php
/**
 * Template for page slug: about.
 *
 * Editorial story page + cloud shell + partners marquee.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$partners = '[cw_partners_marquee heading="" ids="4014,4015,4021,4022,4023,4024,4025,4026,4027,4028,4029,4030,4031,4032,4033,4034,4790,4035,4036,4068,4069,4070,4071" names="Idaman Asal,ST & Partners,Yibon Creative,Malaysia Vegan Life,Shark Saver,Relationship Studio,Sinar Harian,Sin Chew Daily,Along With Love,Karang Kraf,Sim Lim,hearthwood,hanan,emotion pod,Brigthen,Sin Chew Daily — Vitality,Bentong 文冬 大大 LOOK,azaaz,MovaTree,Persatuan Guru Sekolah-Sekolah Cina Bentong,Kicap Bentong,IPEL,Cheong & Kimmy" links=",,https://yiboncreative.com/,https://www.facebook.com/m007.veganlife/,https://sharksavers.org.my/,https://relationshipstudio.sg/,http://www.sinarharian.com.my/,https://www.sinchew.com.my/,,https://www.karangkraf.com/,,,,https://emotionpod.com/,https://www.brightensphere.com/,,,,,,https://kicapbentong.com/,," speed="42"]';
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--about" data-cw-phase2 data-cw-story>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-story-hero" data-cw-phase2-hero>
		<div class="cw-story-hero__split">
			<div class="cw-story-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'Creative Wings Sdn Bhd', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-story-hero__title" data-cw-phase2-line>
					<?php
					echo wp_kses(
						__( 'About <span class="cw-story-inline">Creative Wings</span>', 'cw-astra-child' ),
						[ 'span' => [ 'class' => true ] ]
					);
					?>
				</h1>
				<p class="cw-phase2__intro cw-story-hero__intro" data-cw-story-scrub>
					<?php esc_html_e( 'We build competitions, activities, and campaigns so students, creators, and communities across Malaysia can create with purpose.', 'cw-astra-child' ); ?>
				</p>
				<div class="cw-story-hero__actions">
					<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Browse campaigns', 'cw-astra-child' ); ?></a>
					<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact us', 'cw-astra-child' ); ?></a>
				</div>
			</div>
			<aside class="cw-story-hero__rail cw-story-hero__rail--stats" data-cw-story-rail aria-label="<?php esc_attr_e( 'Creative Wings snapshot', 'cw-astra-child' ); ?>">
				<div class="cw-story-stat">
					<strong>4+</strong>
					<span><?php esc_html_e( 'Active campaigns', 'cw-astra-child' ); ?></span>
				</div>
				<div class="cw-story-stat">
					<strong>RM 11.2k</strong>
					<span><?php esc_html_e( 'Site prize pool', 'cw-astra-child' ); ?></span>
				</div>
				<div class="cw-story-stat">
					<strong><?php esc_html_e( 'Nationwide', 'cw-astra-child' ); ?></strong>
					<span><?php esc_html_e( 'Malaysia service area', 'cw-astra-child' ); ?></span>
				</div>
			</aside>
		</div>
	</header>

	<div class="cw-phase2__body cw-story-body" data-cw-phase2-body>
		<div class="cw-story-prose" data-cw-story-block>
			<section>
				<h2><?php esc_html_e( 'Why we exist', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Young talent across Malaysia often lacks a simple path from idea to stage. Creative Wings closes that gap — connecting schools, brands, and creators through campaigns that are easy to join and meaningful to finish.', 'cw-astra-child' ); ?></p>
			</section>

			<section>
				<h2><?php esc_html_e( 'What we do', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'We connect organisers with participants through an easy join flow, campaign showcases, and tools for schools and brands — from Kuala Lumpur and Selangor to communities nationwide.', 'cw-astra-child' ); ?></p>
				<ul class="cw-phase2-content__list">
					<li><?php esc_html_e( 'Student and youth competitions', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Creative activities and workshops', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Community and brand campaigns', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Tools for organisers, creators, and participants', 'cw-astra-child' ); ?></li>
				</ul>
			</section>
		</div>

		<section class="cw-story-partners" data-cw-story-block>
			<div class="cw-story-partners__head">
				<h2><?php esc_html_e( 'Partners who soar with us', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Media and brand collaborators across Malaysia.', 'cw-astra-child' ); ?></p>
			</div>
			<?php echo do_shortcode( $partners ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>

		<section class="cw-story-layout cw-story-layout--tight" data-cw-story-block>
			<div class="cw-story-block">
				<h2><?php esc_html_e( 'Where we are', 'cw-astra-child' ); ?></h2>
				<address>
					<strong>Creative Wings Sdn Bhd</strong><br>
					NO. 12A, Jalan Setia Impian U13/5E<br>
					Setia Alam Seksyen U13<br>
					40170 Shah Alam, Selangor<br>
					Malaysia
				</address>
			</div>
			<div class="cw-story-block cw-story-block--meta">
				<div class="cw-phase2-content__meta">
					<div>
						<strong><?php esc_html_e( 'Service area', 'cw-astra-child' ); ?></strong>
						<?php esc_html_e( 'All of Malaysia', 'cw-astra-child' ); ?>
					</div>
					<div>
						<strong><?php esc_html_e( 'Phone', 'cw-astra-child' ); ?></strong>
						<a href="tel:+60126618338">+60 12-661 8338</a>
					</div>
					<div>
						<strong><?php esc_html_e( 'Email', 'cw-astra-child' ); ?></strong>
						<a href="mailto:hello@creativewings.asia">hello@creativewings.asia</a>
					</div>
				</div>
			</div>
		</section>

		<section class="cw-story-cta" data-cw-story-block>
			<p><?php esc_html_e( 'Ready to join a campaign or partner with us?', 'cw-astra-child' ); ?></p>
			<div class="cw-phase2-actions">
				<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Get in touch', 'cw-astra-child' ); ?></a>
				<a class="cw-phase2-btn cw-phase2-btn--ghost" href="https://wa.me/60126618338" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'WhatsApp', 'cw-astra-child' ); ?></a>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
