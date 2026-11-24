<?php
/**
 * Template for page slug: rewards.
 *
 * Public points / rewards explainer (B6).
 *
 * @package CW_Astra_Child
 */

defined( 'ABSPATH' ) || exit;

get_header();

$logged_in   = is_user_logged_in();
$points_url  = ( $logged_in && function_exists( 'wc_get_page_id' ) )
	? add_query_arg( 'tab', 'points', get_permalink( wc_get_page_id( 'myaccount' ) ) )
	: '';
$login_url   = home_url( '/login/' );
$register_url = home_url( '/registration/' );
$bonus       = class_exists( 'CW_Invite' ) ? (int) CW_Invite::BONUS_POINTS : 50;
?>
<main id="primary" class="site-main cw-phase2 cw-phase2--story cw-phase2--rewards" data-cw-phase2 data-cw-story>
	<div class="cw-phase2__sky" aria-hidden="true">
		<span class="cw-phase2__cloud cw-phase2__cloud--a" data-cw-phase2-cloud></span>
		<span class="cw-phase2__cloud cw-phase2__cloud--b" data-cw-phase2-cloud></span>
	</div>

	<header class="cw-phase2__hero cw-story-hero" data-cw-phase2-hero>
		<div class="cw-story-hero__split">
			<div class="cw-story-hero__copy">
				<p class="cw-phase2__kicker" data-cw-phase2-line><?php esc_html_e( 'Rewards', 'cw-astra-child' ); ?></p>
				<h1 class="cw-phase2__title cw-story-hero__title" data-cw-phase2-line>
					<?php esc_html_e( 'How Creative Wings points work', 'cw-astra-child' ); ?>
				</h1>
				<p class="cw-phase2__intro cw-story-hero__intro" data-cw-story-scrub>
					<?php esc_html_e( 'Earn when you join and invite friends. Spend at checkout or redeem merch — simple, honest rules.', 'cw-astra-child' ); ?>
				</p>
				<div class="cw-story-hero__actions">
					<?php if ( $logged_in && $points_url ) : ?>
						<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( $points_url ); ?>"><?php esc_html_e( 'My Points', 'cw-astra-child' ); ?></a>
						<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/competitions/' ) ); ?>"><?php esc_html_e( 'Browse campaigns', 'cw-astra-child' ); ?></a>
					<?php else : ?>
						<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( $register_url ); ?>"><?php esc_html_e( 'Create account', 'cw-astra-child' ); ?></a>
						<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( $login_url ); ?>"><?php esc_html_e( 'Log in', 'cw-astra-child' ); ?></a>
					<?php endif; ?>
				</div>
			</div>
			<aside class="cw-story-hero__rail" data-cw-story-rail aria-label="<?php esc_attr_e( 'Quick facts', 'cw-astra-child' ); ?>">
				<p class="cw-story-hero__rail-label"><?php esc_html_e( 'At a glance', 'cw-astra-child' ); ?></p>
				<ul class="cw-story-hero__rail-list">
					<li><?php printf( esc_html__( '+%d welcome bonus', 'cw-astra-child' ), $bonus ); ?></li>
					<li><?php printf( esc_html__( '+%d first join', 'cw-astra-child' ), $bonus ); ?></li>
					<li><?php printf( esc_html__( '+%d per friend invited', 'cw-astra-child' ), $bonus ); ?></li>
					<li><?php esc_html_e( '100 pts = RM1 checkout', 'cw-astra-child' ); ?></li>
				</ul>
			</aside>
		</div>
	</header>

	<div class="cw-phase2__body cw-story-body" data-cw-phase2-body>
		<div class="cw-rewards-grid">
			<section class="cw-story-block" data-cw-story-block>
				<h2><?php esc_html_e( 'How to earn', 'cw-astra-child' ); ?></h2>
				<ul class="cw-phase2-content__list">
					<li>
						<?php
						printf(
							/* translators: %d: bonus points */
							esc_html__( 'Create an account — welcome bonus +%d points.', 'cw-astra-child' ),
							$bonus
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %d: bonus points */
							esc_html__( 'Finish your first campaign join — +%d once (even if the fee is free).', 'cw-astra-child' ),
							$bonus
						);
						?>
					</li>
					<li>
						<?php
						printf(
							/* translators: %d: bonus points */
							esc_html__( 'Share your invite link — you get +%d when a friend registers.', 'cw-astra-child' ),
							$bonus
						);
						?>
					</li>
					<li><?php esc_html_e( 'Pay to join a campaign — 1 point per RM1 cash paid (coupon / free joins earn 0 from this rule).', 'cw-astra-child' ); ?></li>
				</ul>
			</section>

			<section class="cw-story-block" data-cw-story-block>
				<h2><?php esc_html_e( 'How to spend', 'cw-astra-child' ); ?></h2>
				<ul class="cw-phase2-content__list">
					<li><?php esc_html_e( '100 points = RM1.00 credit at checkout on your next campaign join.', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Redeem merchandise from My Account → Points when items are in stock.', 'cw-astra-child' ); ?></li>
					<li><?php esc_html_e( 'Points expire 12 months after your last join unless you join again.', 'cw-astra-child' ); ?></li>
				</ul>
			</section>
		</div>

		<section class="cw-story-block cw-rewards-cta-block" data-cw-story-block>
			<h2><?php esc_html_e( 'Ready to start?', 'cw-astra-child' ); ?></h2>
			<p><?php esc_html_e( 'Join a campaign, invite a friend, and watch your balance grow on the Points tab.', 'cw-astra-child' ); ?></p>
			<div class="cw-phase2-actions">
				<a class="cw-phase2-btn cw-phase2-btn--primary" href="<?php echo esc_url( home_url( '/competitions/' ) ); ?>"><?php esc_html_e( 'Browse competitions', 'cw-astra-child' ); ?></a>
				<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( home_url( '/activities/' ) ); ?>"><?php esc_html_e( 'Browse activities', 'cw-astra-child' ); ?></a>
				<?php if ( $logged_in && $points_url ) : ?>
					<a class="cw-phase2-btn cw-phase2-btn--ghost" href="<?php echo esc_url( $points_url ); ?>"><?php esc_html_e( 'Open My Points', 'cw-astra-child' ); ?></a>
				<?php endif; ?>
			</div>
		</section>
	</div>
</main>
<?php
get_footer();
