<?php
/**
 * Search results — replaces Elementor Theme Builder search template 408.
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

$q = get_search_query();

get_header();
get_template_part(
	'template-parts/phase2/shell',
	'start',
	[
		'kicker'   => __( 'Search', 'cw-astra-child' ),
		/* translators: %s: search query */
		'title'    => $q ? sprintf( __( 'Results for “%s”', 'cw-astra-child' ), $q ) : __( 'Search', 'cw-astra-child' ),
		'intro'    => __( 'Campaigns, pages, and posts across Creative Wings.', 'cw-astra-child' ),
		'modifier' => 'legal',
	]
);
?>
	<div class="cw-phase2__panel cw-search-results">
		<form class="cw-search-results__form" role="search" method="get" action="<?php echo esc_url( home_url( '/' ) ); ?>">
			<label class="screen-reader-text" for="cw-search-field"><?php esc_html_e( 'Search', 'cw-astra-child' ); ?></label>
			<input id="cw-search-field" type="search" name="s" value="<?php echo esc_attr( $q ); ?>" placeholder="<?php esc_attr_e( 'Search Creative Wings…', 'cw-astra-child' ); ?>" />
			<button type="submit"><?php esc_html_e( 'Search', 'cw-astra-child' ); ?></button>
		</form>

		<?php if ( have_posts() ) : ?>
			<ul class="cw-search-results__list">
				<?php
				while ( have_posts() ) {
					the_post();
					?>
					<li class="cw-search-results__item">
						<a href="<?php the_permalink(); ?>">
							<span class="cw-search-results__type"><?php echo esc_html( get_post_type_object( get_post_type() )->labels->singular_name ?? get_post_type() ); ?></span>
							<span class="cw-search-results__title"><?php the_title(); ?></span>
						</a>
						<?php if ( has_excerpt() || get_the_content() ) : ?>
							<p class="cw-search-results__excerpt"><?php echo esc_html( wp_trim_words( get_the_excerpt() ? get_the_excerpt() : wp_strip_all_tags( get_the_content() ), 28 ) ); ?></p>
						<?php endif; ?>
					</li>
					<?php
				}
				?>
			</ul>
			<div class="cw-search-results__nav">
				<?php the_posts_pagination( [ 'mid_size' => 1 ] ); ?>
			</div>
		<?php else : ?>
			<p class="cw-search-results__empty"><?php esc_html_e( 'No matches. Try another keyword, or browse competitions and activities.', 'cw-astra-child' ); ?></p>
			<p class="cw-search-results__links">
				<a href="<?php echo esc_url( home_url( '/competitions/' ) ); ?>"><?php esc_html_e( 'Competitions', 'cw-astra-child' ); ?></a>
				<a href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Activities', 'cw-astra-child' ); ?></a>
			</p>
		<?php endif; ?>
	</div>
<?php
get_template_part( 'template-parts/phase2/shell', 'end' );
get_footer();
