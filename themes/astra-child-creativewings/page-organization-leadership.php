<?php
/**
 * Template for page slug: organization-leadership.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

require_once CW_CHILD_DIR . '/inc/phase3-data.php';

$lead = cw_child_phase3_leadership();

get_header();
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--org" data-cw-phase2 data-cw-p3>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-story-hero" data-cw-phase2-hero>
		<div class="cw-story-hero__split">
			<div class="cw-story-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'People', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-story-hero__title" data-cw-phase2-line>
					<?php esc_html_e( 'Organization & Leadership', 'cw-astra-child' ); ?>
				</h1>
				<p class="cw-phase2__intro" data-cw-p3-scrub>
					<?php esc_html_e( 'Creative Wings is powered by a multidisciplinary team of educators, creators, operators, and builders. Together, we design learning experiences that unlock youth potential and create meaningful impact across schools and communities.', 'cw-astra-child' ); ?>
				</p>
			</div>
			<aside class="cw-story-hero__rail" aria-hidden="true">
				<p class="cw-story-hero__rail-label"><?php esc_html_e( 'Team', 'cw-astra-child' ); ?></p>
				<ul class="cw-story-hero__rail-list">
					<li><?php esc_html_e( 'Founder', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Core leadership', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Cross-functional craft', 'cw-astra-child' ); ?></li>
				</ul>
			</aside>
		</div>
	</header>

	<div class="cw-phase2__body cw-p3-body" data-cw-phase2-body>
		<section class="cw-p3-section" data-cw-p3-block>
			<h2 class="cw-p3-subhead"><?php esc_html_e( 'Founder', 'cw-astra-child' ); ?></h2>
			<div class="cw-p3-people cw-p3-people--founder">
				<?php
				get_template_part(
					'template-parts/phase3/person',
					'card',
					array_merge( $lead['founder'], [ 'modifier' => 'founder' ] )
				);
				?>
			</div>
		</section>

		<section class="cw-p3-section" data-cw-p3-block>
			<div class="cw-p3-section__head">
				<h2><?php esc_html_e( 'Core Leadership Team', 'cw-astra-child' ); ?></h2>
				<p><?php esc_html_e( 'Complementary strengths across creative direction, technology, operations, partnerships, and stewardship.', 'cw-astra-child' ); ?></p>
			</div>
			<div class="cw-p3-people cw-p3-people--team">
				<?php foreach ( $lead['team'] as $person ) : ?>
					<?php get_template_part( 'template-parts/phase3/person', 'card', $person ); ?>
				<?php endforeach; ?>
			</div>
		</section>

		<section class="cw-p3-cta" data-cw-p3-block>
			<p><?php esc_html_e( 'Want the full brand story and mentor network?', 'cw-astra-child' ); ?></p>
			<div class="cw-phase2-actions">
				<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( home_url( '/brand-story/' ) ); ?>"><?php esc_html_e( 'Read Brand Story', 'cw-astra-child' ); ?></a>
				<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/contact/' ) ); ?>"><?php esc_html_e( 'Contact us', 'cw-astra-child' ); ?></a>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
