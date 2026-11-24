<?php
/**
 * Template for page slug: brand-story.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

require_once CW_CHILD_DIR . '/inc/phase3-data.php';

$journey  = cw_child_phase3_journey();
$lead     = cw_child_phase3_leadership();
$mentors  = cw_child_phase3_mentors();
$logo_url = 'https://creativewings.asia/wp-content/uploads/2026/01/cropped-CW-Logo.png';
$hero_img = 'https://creativewings.asia/wp-content/uploads/2025/09/happy-child-playing-with-toy-wings3x-1.png';

get_header();
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--brand" data-cw-phase2 data-cw-p3>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-p3-hero" data-cw-phase2-hero>
		<div class="cw-p3-hero__grid">
			<div class="cw-p3-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'Brand Story', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-p3-hero__title" data-cw-phase2-line>
					<?php esc_html_e( 'Together, We Help Young Talents Soar', 'cw-astra-child' ); ?>
				</h1>
				<p class="cw-phase2__intro" data-cw-p3-scrub>
					<?php esc_html_e( 'Every child carries hidden talents waiting to take flight. At Creative Wings, we believe students deserve more than classroom grades — they deserve a platform to showcase their creativity, skills, and passions.', 'cw-astra-child' ); ?>
				</p>
				<p class="cw-p3-hero__more">
					<?php esc_html_e( 'Born from a vision to nurture the next generation, Creative Wings is a safe, inspiring space where young people can learn, compete, and shine — from art to sports, STEM to storytelling — and build real portfolios that reflect who they truly are.', 'cw-astra-child' ); ?>
				</p>
				<img class="cw-p3-hero__logo" src="<?php echo esc_url( $logo_url ); ?>" alt="Creative Wings" width="280" height="88" decoding="async">
			</div>
			<figure class="cw-p3-hero__figure" data-cw-p3-media>
				<img src="<?php echo esc_url( $hero_img ); ?>" alt="" loading="eager" decoding="async" width="720" height="900">
			</figure>
		</div>
	</header>

	<div class="cw-phase2__body cw-p3-body" data-cw-phase2-body>

		<section class="cw-p3-section" data-cw-p3-block>
			<div class="cw-p3-section__head">
				<h2><?php esc_html_e( 'Our Journey', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Scroll to read each milestone.', 'cw-astra-child' ); ?></p>
			</div>
			<ol class="cw-p3-timeline" data-cw-p3-timeline>
				<?php foreach ( $journey as $item ) : ?>
					<li class="cw-p3-timeline__item" data-cw-p3-card>
						<span class="cw-p3-timeline__date"><?php echo esc_html( $item['date'] ); ?></span>
						<div class="cw-p3-timeline__card">
							<h3><?php echo esc_html( $item['title'] ); ?></h3>
							<p><?php echo esc_html( $item['detail'] ); ?></p>
						</div>
					</li>
				<?php endforeach; ?>
			</ol>
		</section>

		<section class="cw-p3-vm" data-cw-p3-block>
			<div class="cw-p3-vm__card">
				<h2><?php esc_html_e( 'Vision', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'To be Asia\'s most trusted youth platform where every student can discover their creativity, build confidence, and soar into a brighter future.', 'cw-astra-child' ); ?></p>
			</div>
			<div class="cw-p3-vm__card">
				<h2><?php esc_html_e( 'Mission', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Empowering students through fun, accessible contests that nurture creativity and diverse skills. Connecting young talents with mentors, schools, NGOs, and industry partners. Showcasing achievements through online portfolios and offline exhibitions. Preparing the next generation with confidence, creativity, and community spirit.', 'cw-astra-child' ); ?></p>
			</div>
		</section>

		<section class="cw-p3-section cw-p3-partners" data-cw-p3-block>
			<div class="cw-p3-section__head">
				<h2><?php esc_html_e( 'Supporting Partners', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'NGOs, media, and community collaborators who help students soar.', 'cw-astra-child' ); ?></p>
			</div>
			<?php echo do_shortcode( cw_child_phase3_partners_shortcode() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		</section>

		<section class="cw-p3-section" data-cw-p3-block id="leadership">
			<div class="cw-p3-section__head">
				<h2><?php esc_html_e( 'Organization & Leadership', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'A multidisciplinary team of educators, creators, operators, and builders.', 'cw-astra-child' ); ?></p>
			</div>

			<h3 class="cw-p3-subhead"><?php esc_html_e( 'Founder', 'cw-astra-child' ); ?></h3>
			<div class="cw-p3-people cw-p3-people--founder">
				<?php
				get_template_part(
					'template-parts/phase3/person',
					'card',
					array_merge( $lead['founder'], [ 'modifier' => 'founder' ] )
				);
				?>
			</div>

			<h3 class="cw-p3-subhead"><?php esc_html_e( 'Core Leadership Team', 'cw-astra-child' ); ?></h3>
			<p class="cw-p3-lede"><?php esc_html_e( 'Complementary strengths across creative direction, technology, operations, partnerships, and stewardship.', 'cw-astra-child' ); ?></p>
			<div class="cw-p3-people cw-p3-people--team">
				<?php foreach ( $lead['team'] as $person ) : ?>
					<?php get_template_part( 'template-parts/phase3/person', 'card', $person ); ?>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="cw-p3-section" data-cw-p3-block>
			<div class="cw-p3-section__head">
				<h2><?php esc_html_e( 'Mentors', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Industry leaders who volunteer their time to guide participants.', 'cw-astra-child' ); ?></p>
			</div>
			<div class="cw-p3-people cw-p3-people--mentors">
				<?php foreach ( $mentors as $person ) : ?>
					<?php
					get_template_part(
						'template-parts/phase3/person',
						'card',
						array_merge( $person, [ 'modifier' => 'mentor' ] )
					);
					?>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="cw-p3-cta" data-cw-p3-block>
			<p><?php esc_html_e( 'Ready to join a campaign or partner with us?', 'cw-astra-child' ); ?></p>
			<div class="cw-phase2-actions">
				<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Browse campaigns', 'cw-astra-child' ); ?></a>
				<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/organization-leadership/' ) ); ?>"><?php esc_html_e( 'Meet the team', 'cw-astra-child' ); ?></a>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
