<?php
/**
 * Template for page slug: homepage (front page).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$hero = wp_get_attachment_image_url( CW_CHILD_HOME_HERO_ID, 'full' );

$campaigns = do_shortcode( '[product_count]' );
$prize     = do_shortcode( '[total_prize_money]' );

$smile_query = new WP_Query(
	[
		'post_type'              => 'attachment',
		'post_status'            => 'inherit',
		'posts_per_page'         => 18,
		'fields'                 => 'ids',
		'orderby'                => 'date',
		'order'                  => 'DESC',
		's'                      => 'osow-smile',
		'no_found_rows'          => false,
		'update_post_meta_cache' => false,
		'update_post_term_cache' => false,
	]
);
$smile_ids   = $smile_query->posts;
$smile_count = (int) $smile_query->found_posts;
$login_url = home_url( '/login/' );
$osow_url  = get_permalink( 3361 ) ?: home_url( '/competitions/' );
?>
<main id="primary" class="site-main cw-home" data-cw-home>
	<div class="cw-home__sky" aria-hidden="true">
		<span class="cw-home__cloud cw-home__cloud--a" data-cw-cloud></span>
		<span class="cw-home__cloud cw-home__cloud--b" data-cw-cloud></span>
		<span class="cw-home__cloud cw-home__cloud--c" data-cw-cloud></span>
		<span class="cw-home__cloud cw-home__cloud--d" data-cw-cloud></span>
		<span class="cw-home__cloud cw-home__cloud--e" data-cw-cloud></span>
	</div>
	<section
		class="cw-home__hero"
		style="--cw-hero-image:url('<?php echo esc_url( $hero ); ?>')"
		data-cw-hero
	>
		<div class="cw-home__hero-inner">
			<p class="cw-home__tagline" data-cw-hero-line><?php echo esc_html( 'Together, We Help Young Talents Soar' ); ?></p>
			<h1 class="cw-home__seo-title" data-cw-hero-line><?php esc_html_e( 'Competitions and Activities in Malaysia', 'cw-astra-child' ); ?></h1>
			<p class="cw-home__intro" data-cw-hero-line><?php esc_html_e( 'Open to students and adults. Join a competition or an activity and register online.', 'cw-astra-child' ); ?></p>
			<div data-cw-hero-line>
				<?php echo do_shortcode( '[custom_search_form]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
			</div>
		</div>
	</section>

	<div class="cw-home__sections">
		<section class="cw-home-impact" data-cw-impact aria-label="<?php esc_attr_e( 'Creative Wings impact', 'cw-astra-child' ); ?>">
			<div class="cw-home-impact__grid">
				<article class="cw-home-impact__item" data-cw-impact-item>
					<span class="cw-home-impact__label"><?php esc_html_e( 'Site Campaigns', 'cw-astra-child' ); ?></span>
					<span class="cw-home-impact__value cw-home-impact__value--coral" data-cw-count="<?php echo esc_attr( (string) absint( $campaigns ) ); ?>">0</span>
				</article>
				<article class="cw-home-impact__item" data-cw-impact-item>
					<span class="cw-home-impact__label"><?php esc_html_e( 'Site Prize Pool', 'cw-astra-child' ); ?></span>
					<span class="cw-home-impact__value cw-home-impact__value--blue" data-cw-count="<?php echo esc_attr( preg_replace( '/[^0-9.]/', '', (string) $prize ) ); ?>" data-cw-count-decimals="2" data-cw-count-prefix="">0</span>
				</article>
				<article class="cw-home-impact__item" data-cw-impact-item>
					<span class="cw-home-impact__label"><?php esc_html_e( 'Smiles Shared', 'cw-astra-child' ); ?></span>
					<span class="cw-home-impact__value cw-home-impact__value--coral" data-cw-count="<?php echo esc_attr( (string) max( $smile_count, 1 ) ); ?>">0</span>
				</article>
			</div>
		</section>

		<?php echo do_shortcode( '[cw_featured_campaign]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<?php if ( ! empty( $smile_ids ) ) : ?>
			<section class="cw-home-smiles" data-cw-smiles aria-label="<?php esc_attr_e( 'One Smile, One World gallery', 'cw-astra-child' ); ?>">
				<div class="cw-home-smiles__head">
					<h2 class="cw-home-section-heading"><?php esc_html_e( 'Smiles Across Malaysia', 'cw-astra-child' ); ?></h2>
					<p class="cw-home-smiles__lead"><?php esc_html_e( 'A living wall of young artists from One Smile, One World — each face a small act of kindness made visible.', 'cw-astra-child' ); ?></p>
				</div>
				<div class="cw-home-smiles__viewport" data-cw-smiles-track-wrap>
					<div class="cw-home-smiles__track" data-cw-smiles-track>
						<?php
						$loop = array_merge( $smile_ids, $smile_ids );
						foreach ( $loop as $i => $sid ) :
							$html = wp_get_attachment_image(
								(int) $sid,
								'medium',
								false,
								[
									'class'    => 'cw-home-smiles__img',
									'loading'  => 'lazy',
									'decoding' => 'async',
									'alt'      => 'One Smile, One World artwork',
								]
							);
							if ( ! $html ) {
								continue;
							}
							?>
							<figure class="cw-home-smiles__item" data-cw-smile-item>
								<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
							</figure>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="cw-home-smiles__action">
					<a class="cw-home-smiles__link" href="<?php echo esc_url( $osow_url ); ?>"><?php esc_html_e( 'Explore the campaign', 'cw-astra-child' ); ?></a>
				</div>
			</section>
		<?php endif; ?>

		<?php echo do_shortcode( '[cw_how_it_works]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo do_shortcode( '[cw_partners_marquee heading="Our Partners" campaign_id="3361"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<?php echo do_shortcode( '[cw_events_grid limit="6" columns="3" heading="Open Campaigns"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>

		<section class="cw-home-cta" data-cw-cta aria-label="<?php esc_attr_e( 'Get started', 'cw-astra-child' ); ?>">
			<div class="cw-home-cta__inner">
				<h2 class="cw-home-cta__title" data-cw-cta-line><?php esc_html_e( 'Ready to create the next big campaign?', 'cw-astra-child' ); ?></h2>
				<p class="cw-home-cta__text" data-cw-cta-line><?php esc_html_e( 'Host a programme for your community, or join as a young creator and put your talent on the map.', 'cw-astra-child' ); ?></p>
				<div class="cw-home-cta__actions" data-cw-cta-line>
					<a class="cw-home-cta__btn cw-home-cta__btn--primary" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Host a campaign', 'cw-astra-child' ); ?></a>
					<a class="cw-home-cta__btn cw-home-cta__btn--ghost" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Join as creator', 'cw-astra-child' ); ?></a>
				</div>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
