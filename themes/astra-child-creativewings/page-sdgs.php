<?php
/**
 * Template for page slug: sdgs.
 *
 * GSAP: hero entrance, stack parallax, manifesto scrub,
 * goals grid + feature panel. Reduced-motion safe.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

require_once CW_CHILD_DIR . '/inc/phase3-data.php';

$icons = cw_child_phase3_sdg_icons();
$first = $icons[0] ?? null;
$stack = array_values(
	array_filter(
		$icons,
		static function ( $g ) {
			return in_array( (int) $g['n'], [ 4, 13, 17 ], true );
		}
	)
);

get_header();
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--sdgs cw-sdgs" data-cw-phase2 data-cw-p3 data-cw-sdgs>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-sdgs-hero" data-cw-sdgs-hero>
		<div class="cw-sdgs-hero__copy">
			<p class="cw-phase2__kicker" data-cw-sdgs-line><?php esc_html_e( 'United Nations · Agenda 2030', 'cw-astra-child' ); ?></p>
			<h1 class="cw-sdgs-hero__title" data-cw-sdgs-line>
				<?php esc_html_e( 'Sustainable', 'cw-astra-child' ); ?>
				<?php if ( ! empty( $stack[0] ) ) : ?>
					<span class="cw-sdgs-inline" aria-hidden="true">
						<img src="<?php echo esc_url( $stack[0]['url'] ); ?>" alt="" width="96" height="96" loading="eager" decoding="async">
					</span>
				<?php endif; ?>
				<?php esc_html_e( 'Development Goals', 'cw-astra-child' ); ?>
			</h1>
			<p class="cw-sdgs-hero__intro" data-cw-sdgs-scrub>
				<?php esc_html_e( 'Seventeen interconnected goals. One shared blueprint for peace and prosperity for people and the planet — adopted by the United Nations in September 2015, aiming for 2030.', 'cw-astra-child' ); ?>
			</p>
			<div class="cw-sdgs-hero__actions" data-cw-sdgs-line>
				<a class="cw-phase2-btn cw-phase2-btn--primary" href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'UN SDG hub', 'cw-astra-child' ); ?></a>
				<a class="cw-phase2-btn cw-phase2-btn--ghost" href="#cw-sdgs-goals"><?php esc_html_e( 'Explore the 17', 'cw-astra-child' ); ?></a>
			</div>
		</div>

		<div class="cw-sdgs-hero__stack" data-cw-sdgs-stack aria-hidden="true">
			<?php foreach ( $stack as $i => $goal ) : ?>
				<figure class="cw-sdgs-hero__card" data-cw-sdgs-stack-card data-depth="<?php echo esc_attr( (string) ( $i + 1 ) ); ?>">
					<img src="<?php echo esc_url( $goal['url'] ); ?>" alt="" width="320" height="320" loading="eager" decoding="async">
				</figure>
			<?php endforeach; ?>
		</div>
	</header>

	<section class="cw-sdgs-manifesto" data-cw-sdgs-manifesto-wrap>
		<p class="cw-sdgs-manifesto__text" data-cw-sdgs-manifesto>
			<?php esc_html_e( 'Creative Wings aligns campaigns, mentorship, and youth programmes with this global agenda — so every activity can point to a concrete goal, not just a slogan.', 'cw-astra-child' ); ?>
		</p>
	</section>

	<section class="cw-sdgs-goals" id="cw-sdgs-goals" data-cw-sdgs-goals aria-label="<?php esc_attr_e( 'The 17 Goals', 'cw-astra-child' ); ?>">
		<div class="cw-sdgs-goals__head">
			<h2><?php esc_html_e( 'The 17 Goals', 'cw-astra-child' ); ?></h2>
			<p><?php esc_html_e( 'Select a goal on the left — details stay visible on the right.', 'cw-astra-child' ); ?></p>
		</div>

		<div class="cw-sdgs-goals__split">
			<ul class="cw-sdgs-grid" role="list">
				<?php foreach ( $icons as $i => $goal ) : ?>
					<li class="cw-sdgs-tile-wrap">
						<button
							type="button"
							class="cw-sdgs-tile<?php echo 0 === $i ? ' is-active' : ''; ?>"
							data-cw-sdgs-tile
							data-n="<?php echo esc_attr( (string) $goal['n'] ); ?>"
							data-label="<?php echo esc_attr( $goal['label'] ); ?>"
							data-blurb="<?php echo esc_attr( $goal['blurb'] ?? '' ); ?>"
							data-src="<?php echo esc_url( $goal['url'] ); ?>"
							aria-pressed="<?php echo 0 === $i ? 'true' : 'false'; ?>"
							aria-label="<?php echo esc_attr( sprintf( /* translators: 1: goal number, 2: goal name */ __( 'Goal %1$d: %2$s', 'cw-astra-child' ), $goal['n'], $goal['label'] ) ); ?>"
						>
							<img src="<?php echo esc_url( $goal['url'] ); ?>" alt="" width="160" height="160" loading="lazy" decoding="async">
							<span class="cw-sdgs-tile__n"><?php echo esc_html( (string) $goal['n'] ); ?></span>
						</button>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( $first ) : ?>
				<aside class="cw-sdgs-feature-rail">
					<div class="cw-sdgs-feature" data-cw-sdgs-feature aria-live="polite">
						<figure class="cw-sdgs-feature__media">
							<img
								data-cw-sdgs-feature-img
								src="<?php echo esc_url( $first['url'] ); ?>"
								alt="<?php echo esc_attr( sprintf( /* translators: 1: goal number, 2: goal name */ __( 'SDG %1$d — %2$s', 'cw-astra-child' ), $first['n'], $first['label'] ) ); ?>"
								width="320"
								height="320"
								decoding="async"
							>
						</figure>
						<div class="cw-sdgs-feature__copy">
							<p class="cw-sdgs-feature__n" data-cw-sdgs-feature-n><?php echo esc_html( sprintf( /* translators: %d: goal number */ __( 'Goal %d', 'cw-astra-child' ), $first['n'] ) ); ?></p>
							<h3 class="cw-sdgs-feature__name" data-cw-sdgs-feature-name><?php echo esc_html( $first['label'] ); ?></h3>
							<p class="cw-sdgs-feature__blurb" data-cw-sdgs-feature-blurb><?php echo esc_html( $first['blurb'] ?? '' ); ?></p>
							<a
								class="cw-sdgs-feature__link"
								data-cw-sdgs-feature-link
								href="<?php echo esc_url( 'https://sdgs.un.org/goals/goal' . (int) $first['n'] ); ?>"
								target="_blank"
								rel="noopener noreferrer"
							><?php esc_html_e( 'Read on sdgs.un.org', 'cw-astra-child' ); ?></a>
						</div>
					</div>
				</aside>
			<?php endif; ?>
		</div>
	</section>

	<section class="cw-sdgs-cta" data-cw-sdgs-cta>
		<p><?php esc_html_e( 'Read the full framework on the United Nations site, or see how Creative Wings runs campaigns on the ground.', 'cw-astra-child' ); ?></p>
		<div class="cw-phase2-actions">
			<a class="cw-phase2-btn cw-phase2-btn--primary" href="https://sdgs.un.org/goals" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'sdgs.un.org', 'cw-astra-child' ); ?></a>
			<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Our campaigns', 'cw-astra-child' ); ?></a>
		</div>
	</section>
</main>
<?php
get_footer();
