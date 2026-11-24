<?php
/**
 * QR upload email linking: when a parent/guardian registers on Creative Wings,
 * attach any staged QR submissions that used their email.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CW_Qr_Email_Link {

	public static function init() {
		add_action( 'user_register', [ __CLASS__, 'on_user_registered' ], 30, 1 );
		add_action( 'woocommerce_created_customer', [ __CLASS__, 'on_user_registered' ], 30, 1 );
		if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
			CW_Pending_Parent_Link::maybe_allow_multi_per_user_campaign();
		}
	}

	/**
	 * After a QR Save: if email belongs to an existing user, create pending claim link.
	 * If not, parent_email on the staged row is enough for later registration match.
	 *
	 * @param int    $staged_id
	 * @param int    $campaign_id
	 * @param string $submission_code
	 * @param string $email
	 */
	public static function after_qr_save( $staged_id, $campaign_id, $submission_code, $email ) {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return;
		}
		$user = get_user_by( 'email', $email );
		if ( ! $user ) {
			return;
		}
		$parsed = CW_Submission_Code::parse( $submission_code );
		if ( empty( $parsed['valid'] ) ) {
			return;
		}
		if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
			CW_Pending_Parent_Link::save( (int) $user->ID, $parsed, (int) $campaign_id );
		}
		if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
			CW_Pending_Parent_Link::on_staged_uploaded( (int) $staged_id, (int) $campaign_id, $parsed['normalized'] );
		}
	}

	/**
	 * @param int $user_id
	 */
	public static function on_user_registered( $user_id ) {
		$user_id = (int) $user_id;
		$user    = get_userdata( $user_id );
		if ( ! $user || ! $user->user_email ) {
			return;
		}
		$email = sanitize_email( $user->user_email );
		if ( ! $email ) {
			return;
		}
		if ( ! class_exists( 'CW_Staged_Submissions' ) ) {
			return;
		}
		$rows = CW_Staged_Submissions::list_by_parent_email( $email );
		if ( empty( $rows ) ) {
			return;
		}
		$linked = 0;
		foreach ( $rows as $row ) {
			if ( ( $row['status'] ?? '' ) === 'claimed' ) {
				continue;
			}
			$parsed = CW_Submission_Code::parse( $row['submission_code'] ?? '' );
			if ( empty( $parsed['valid'] ) ) {
				continue;
			}
			$campaign_id = (int) ( $row['campaign_id'] ?? 0 );
			if ( class_exists( 'CW_Pending_Parent_Link' ) ) {
				CW_Pending_Parent_Link::save( $user_id, $parsed, $campaign_id );
				CW_Pending_Parent_Link::on_staged_uploaded( (int) $row['id'], $campaign_id, $parsed['normalized'] );
			}
			$linked++;
		}
		if ( $linked > 0 ) {
			update_user_meta(
				$user_id,
				'cw_qr_link_notice',
				sprintf(
					/* translators: %d: number of artworks */
					_n( '%d QR artwork is now linked to your account.', '%d QR artworks are now linked to your account.', $linked, 'creativewings-core' ),
					$linked
				)
			);
		}
	}

	/**
	 * @param string $email
	 * @return array{exists:bool,message:string}
	 */
	public static function lookup_email_status( $email ) {
		$email = sanitize_email( $email );
		if ( ! $email || ! is_email( $email ) ) {
			return [
				'exists'  => false,
				'valid'   => false,
				'message' => __( 'Enter a valid email address.', 'creativewings-core' ),
			];
		}
		$user = get_user_by( 'email', $email );
		if ( $user ) {
			return [
				'exists'  => true,
				'valid'   => true,
				'message' => __( 'Account found — after Save, this artwork links to your account. Same email OK for siblings (each child needs their own QR).', 'creativewings-core' ),
			];
		}
		return [
			'exists'  => false,
			'valid'   => true,
			'message' => __( 'No account for this email yet. You can still Save — register later with the same email to link (siblings can share this email; each needs their own QR).', 'creativewings-core' ),
		];
	}
}
