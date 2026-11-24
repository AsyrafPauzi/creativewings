<?php
/**
 * Shared windowed pagination (Prev · 1 … 4 5 6 … 24 · Next).
 * One markup + class set for every dashboard / directory list.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CW_Pagination {

	/**
	 * @param array{
	 *   current?: int,
	 *   total?: int,
	 *   base_url?: string,
	 *   page_arg?: string,
	 *   query_args?: array<string, mixed>,
	 *   range?: int,
	 *   aria_label?: string,
	 *   class?: string
	 * } $args
	 */
	public static function render( array $args ) {
		$current    = max( 1, (int) ( $args['current'] ?? 1 ) );
		$total      = max( 1, (int) ( $args['total'] ?? 1 ) );
		$base_url   = (string) ( $args['base_url'] ?? '' );
		$page_arg   = (string) ( $args['page_arg'] ?? 'cw_page' );
		$query_args = is_array( $args['query_args'] ?? null ) ? $args['query_args'] : [];
		$range      = max( 1, (int) ( $args['range'] ?? 2 ) );
		$aria       = (string) ( $args['aria_label'] ?? __( 'Pagination', 'creativewings-core' ) );
		$nav_class  = trim( 'cw-pagination-nav ' . (string) ( $args['class'] ?? '' ) );

		if ( $total <= 1 || $base_url === '' ) {
			return;
		}

		$url = static function ( $page ) use ( $base_url, $page_arg, $query_args ) {
			$args = array_merge( $query_args, [ $page_arg => (int) $page ] );
			return esc_url( add_query_arg( $args, $base_url ) );
		};

		$start = max( 1, $current - $range );
		$end   = min( $total, $current + $range );
		?>
		<nav class="<?php echo esc_attr( $nav_class ); ?>" role="navigation" aria-label="<?php echo esc_attr( $aria ); ?>">
			<?php if ( $current > 1 ) : ?>
				<a href="<?php echo $url( $current - 1 ); ?>" class="cw-page-btn prev" aria-label="<?php esc_attr_e( 'Previous page', 'creativewings-core' ); ?>">
					<i class="fas fa-chevron-left" aria-hidden="true"></i>
				</a>
			<?php else : ?>
				<span class="cw-page-btn prev disabled" aria-disabled="true"><i class="fas fa-chevron-left" aria-hidden="true"></i></span>
			<?php endif; ?>

			<?php
			if ( $start > 1 ) {
				echo '<a href="' . $url( 1 ) . '" class="cw-page-btn">1</a>';
				if ( $start > 2 ) {
					echo '<span class="cw-page-ellipsis" aria-hidden="true">…</span>';
				}
			}
			for ( $i = $start; $i <= $end; $i++ ) {
				$cls = 'cw-page-btn' . ( $i === $current ? ' active' : '' );
				$cur = ( $i === $current ) ? ' aria-current="page"' : '';
				echo '<a href="' . $url( $i ) . '" class="' . esc_attr( $cls ) . '"' . $cur . '>' . (int) $i . '</a>';
			}
			if ( $end < $total ) {
				if ( $end < $total - 1 ) {
					echo '<span class="cw-page-ellipsis" aria-hidden="true">…</span>';
				}
				echo '<a href="' . $url( $total ) . '" class="cw-page-btn">' . (int) $total . '</a>';
			}
			?>

			<?php if ( $current < $total ) : ?>
				<a href="<?php echo $url( $current + 1 ); ?>" class="cw-page-btn next" aria-label="<?php esc_attr_e( 'Next page', 'creativewings-core' ); ?>">
					<i class="fas fa-chevron-right" aria-hidden="true"></i>
				</a>
			<?php else : ?>
				<span class="cw-page-btn next disabled" aria-disabled="true"><i class="fas fa-chevron-right" aria-hidden="true"></i></span>
			<?php endif; ?>
		</nav>
		<?php
	}
}
