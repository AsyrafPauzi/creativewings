<?php
/**
 * Tools → CW Payment Export
 *
 * Lightweight WooCommerce order/payment export for accounts reconciliation.
 * Filters: date range, status, coupon used, paid-only. Column picker + CSV/XLSX.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CW_Payment_Export {

	const NONCE = 'cw_payment_export';

	/** @var array<string,string> */
	private static $columns = [
		'order_id'        => 'Order ID',
		'name'            => 'Name',
		'email'           => 'Email',
		'phone'           => 'Phone',
		'total'           => 'Price (Total)',
		'subtotal'        => 'Subtotal',
		'discount'        => 'Discount',
		'currency'        => 'Currency',
		'coupon_used'     => 'Coupon Used',
		'coupon_codes'    => 'Coupon Codes',
		'date_created'    => 'Date Created',
		'date_paid'       => 'Date Paid',
		'status'          => 'Status',
		'payment_method'  => 'Payment Method',
		'transaction_id'  => 'Transaction ID',
		'products'        => 'Products',
	];

	public function __construct() {
		add_action( 'admin_menu', [ $this, 'register_menu' ] );
		add_action( 'admin_post_cw_payment_export', [ $this, 'handle_export' ] );
	}

	public function register_menu() {
		add_management_page(
			__( 'CW Payment Export', 'creativewings-core' ),
			__( 'CW Payment Export', 'creativewings-core' ),
			'manage_woocommerce',
			'cw-payment-export',
			[ $this, 'render_page' ]
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
		}

		$default_cols = [ 'order_id', 'name', 'email', 'total', 'coupon_used', 'coupon_codes', 'date_created', 'date_paid', 'status', 'payment_method', 'products' ];
		$from         = gmdate( 'Y-m-01', strtotime( 'first day of last month' ) );
		$to           = gmdate( 'Y-m-t', strtotime( 'last day of last month' ) );
		$statuses     = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : [];
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'CW Payment Export', 'creativewings-core' ); ?></h1>
			<p><?php esc_html_e( 'Export WooCommerce orders for accounts reconciliation. Pick a date range, status, and columns.', 'creativewings-core' ); ?></p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="cw_payment_export" />
				<?php wp_nonce_field( self::NONCE ); ?>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cw_pe_from"><?php esc_html_e( 'Date from', 'creativewings-core' ); ?></label></th>
						<td><input type="date" id="cw_pe_from" name="date_from" value="<?php echo esc_attr( $from ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><label for="cw_pe_to"><?php esc_html_e( 'Date to', 'creativewings-core' ); ?></label></th>
						<td><input type="date" id="cw_pe_to" name="date_to" value="<?php echo esc_attr( $to ); ?>" required /></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Date field', 'creativewings-core' ); ?></th>
						<td>
							<label><input type="radio" name="date_field" value="date_created" checked /> <?php esc_html_e( 'Order created', 'creativewings-core' ); ?></label><br />
							<label><input type="radio" name="date_field" value="date_paid" /> <?php esc_html_e( 'Date paid', 'creativewings-core' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Status', 'creativewings-core' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:4px;"><input type="checkbox" name="status[]" value="any" checked /> <?php esc_html_e( 'All statuses', 'creativewings-core' ); ?></label>
							<?php foreach ( $statuses as $key => $label ) : ?>
								<?php $slug = str_replace( 'wc-', '', $key ); ?>
								<label style="display:inline-block;min-width:160px;margin:2px 8px 2px 0;"><input type="checkbox" name="status[]" value="<?php echo esc_attr( $slug ); ?>" /> <?php echo esc_html( $label ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Extras', 'creativewings-core' ); ?></th>
						<td>
							<label><input type="checkbox" name="paid_only" value="1" /> <?php esc_html_e( 'Paid only (total &gt; 0)', 'creativewings-core' ); ?></label><br />
							<label><input type="checkbox" name="coupon_only" value="1" /> <?php esc_html_e( 'Coupon used only', 'creativewings-core' ); ?></label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Columns', 'creativewings-core' ); ?></th>
						<td>
							<?php foreach ( self::$columns as $key => $label ) : ?>
								<label style="display:inline-block;min-width:180px;margin:2px 8px 2px 0;">
									<input type="checkbox" name="columns[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $default_cols, true ) ); ?> />
									<?php echo esc_html( $label ); ?>
								</label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Format', 'creativewings-core' ); ?></th>
						<td>
							<label><input type="radio" name="format" value="xlsx" checked /> Excel (.xlsx)</label>
							&nbsp;&nbsp;
							<label><input type="radio" name="format" value="csv" /> CSV</label>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Download export', 'creativewings-core' ) ); ?>
			</form>
		</div>
		<?php
	}

	public function handle_export() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Unauthorized', 'creativewings-core' ), 403 );
		}
		check_admin_referer( self::NONCE );

		if ( ! function_exists( 'wc_get_orders' ) ) {
			wp_die( esc_html__( 'WooCommerce is required.', 'creativewings-core' ), 500 );
		}

		$from       = sanitize_text_field( wp_unslash( $_POST['date_from'] ?? '' ) );
		$to         = sanitize_text_field( wp_unslash( $_POST['date_to'] ?? '' ) );
		$date_field = sanitize_key( wp_unslash( $_POST['date_field'] ?? 'date_created' ) );
		if ( ! in_array( $date_field, [ 'date_created', 'date_paid' ], true ) ) {
			$date_field = 'date_created';
		}
		$format     = sanitize_key( wp_unslash( $_POST['format'] ?? 'xlsx' ) );
		$paid_only  = ! empty( $_POST['paid_only'] );
		$coupon_only = ! empty( $_POST['coupon_only'] );

		$statuses = isset( $_POST['status'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['status'] ) ) : [ 'any' ];
		if ( in_array( 'any', $statuses, true ) || empty( $statuses ) ) {
			$statuses = array_map(
				static function ( $k ) {
					return str_replace( 'wc-', '', $k );
				},
				array_keys( wc_get_order_statuses() )
			);
		}

		$cols = isset( $_POST['columns'] ) ? array_map( 'sanitize_key', (array) wp_unslash( $_POST['columns'] ) ) : [];
		$cols = array_values( array_intersect( array_keys( self::$columns ), $cols ) );
		if ( empty( $cols ) ) {
			$cols = [ 'order_id', 'name', 'total', 'coupon_used', 'date_created', 'status' ];
		}

		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $to ) ) {
			wp_die( esc_html__( 'Invalid date range.', 'creativewings-core' ), 400 );
		}

		$args = [
			'limit'    => -1,
			'type'     => 'shop_order',
			'status'   => $statuses,
			'orderby'  => 'ID',
			'order'    => 'ASC',
			'return'   => 'objects',
		];
		if ( $date_field === 'date_paid' ) {
			$args['date_paid'] = $from . '...' . $to;
		} else {
			$args['date_created'] = $from . '...' . $to;
		}

		$orders = wc_get_orders( $args );
		$rows   = [];
		foreach ( $orders as $o ) {
			$row = $this->map_order( $o );
			if ( $paid_only && (float) $row['total'] <= 0 ) {
				continue;
			}
			if ( $coupon_only && $row['coupon_used'] !== 'Yes' ) {
				continue;
			}
			$rows[] = $row;
		}

		$filename = 'cw-payments-' . $from . '_to_' . $to . '-' . gmdate( 'Ymd-His' );
		if ( $format === 'csv' ) {
			$this->stream_csv( $filename . '.csv', $cols, $rows );
		}
		$this->stream_xlsx( $filename . '.xlsx', $cols, $rows, $from, $to );
	}

	/**
	 * @param WC_Order $o Order.
	 * @return array<string,mixed>
	 */
	private function map_order( $o ) {
		$coupons  = $o->get_coupon_codes();
		$discount = (float) $o->get_discount_total();
		$items    = [];
		foreach ( $o->get_items() as $item ) {
			$items[] = $item->get_name() . ' x' . $item->get_quantity();
		}

		return [
			'order_id'       => $o->get_id(),
			'name'           => trim( $o->get_formatted_billing_full_name() ),
			'email'          => $o->get_billing_email(),
			'phone'          => $o->get_billing_phone(),
			'total'          => (float) $o->get_total(),
			'subtotal'       => (float) $o->get_subtotal(),
			'discount'       => $discount,
			'currency'       => $o->get_currency(),
			'coupon_used'    => ( ! empty( $coupons ) || $discount > 0 ) ? 'Yes' : 'No',
			'coupon_codes'   => implode( ', ', $coupons ),
			'date_created'   => $o->get_date_created() ? $o->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			'date_paid'      => $o->get_date_paid() ? $o->get_date_paid()->date( 'Y-m-d H:i:s' ) : '',
			'status'         => wc_get_order_status_name( $o->get_status() ),
			'payment_method' => $o->get_payment_method_title(),
			'transaction_id' => $o->get_transaction_id(),
			'products'       => implode( ' | ', $items ),
		];
	}

	/**
	 * @param string               $filename Filename.
	 * @param array<int,string>    $cols     Column keys.
	 * @param array<int,array>     $rows     Data rows.
	 */
	private function stream_csv( $filename, $cols, $rows ) {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array_map( static function ( $k ) {
			return self::$columns[ $k ] ?? $k;
		}, $cols ) );
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $cols as $k ) {
				$line[] = $row[ $k ] ?? '';
			}
			fputcsv( $out, $line );
		}
		fclose( $out );
		exit;
	}

	/**
	 * @param string               $filename Filename.
	 * @param array<int,string>    $cols     Column keys.
	 * @param array<int,array>     $rows     Data rows.
	 * @param string               $from     From date.
	 * @param string               $to       To date.
	 */
	private function stream_xlsx( $filename, $cols, $rows, $from, $to ) {
		if ( ! class_exists( '\PhpOffice\PhpSpreadsheet\Spreadsheet' ) ) {
			// Fallback to CSV if PhpSpreadsheet missing.
			$this->stream_csv( preg_replace( '/\.xlsx$/', '.csv', $filename ), $cols, $rows );
		}

		$ss = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
		$ws = $ss->getActiveSheet();
		$ws->setTitle( 'Payments' );

		$headers = array_map( static function ( $k ) {
			return self::$columns[ $k ] ?? $k;
		}, $cols );
		$ws->fromArray( $headers, null, 'A1' );

		$r = 2;
		foreach ( $rows as $row ) {
			$line = [];
			foreach ( $cols as $k ) {
				$line[] = $row[ $k ] ?? '';
			}
			$ws->fromArray( $line, null, 'A' . $r );
			$r++;
		}

		$info = $ss->createSheet( 0 );
		$info->setTitle( 'Summary' );
		$info->fromArray(
			[
				[ 'Report', 'CW Payment Export' ],
				[ 'Period', $from . ' to ' . $to ],
				[ 'Orders', count( $rows ) ],
				[ 'Gross total', array_sum( array_map( static function ( $row ) {
					return (float) ( $row['total'] ?? 0 );
				}, $rows ) ) ],
				[ 'Generated', gmdate( 'Y-m-d H:i:s' ) . ' UTC' ],
			],
			null,
			'A1'
		);

		nocache_headers();
		header( 'Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' );
		header( 'Content-Disposition: attachment; filename=' . $filename );
		$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx( $ss );
		$writer->save( 'php://output' );
		exit;
	}
}
