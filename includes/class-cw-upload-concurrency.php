<?php
/**
 * Limits concurrent PIC / school artwork uploads so PHP-FPM + MySQL
 * are not saturated when many phones Save at once.
 *
 * Fast-fail (no long sleep): callers should ask the client to retry.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CW_Upload_Concurrency {

	const OPTION_KEY   = 'cw_upload_inflight';
	const LOCK_NAME    = 'cw_upload_concurrency';
	const DEFAULT_MAX  = 15;
	const STALE_SECONDS = 180;

	/**
	 * @return int
	 */
	public static function max_slots() {
		$max = (int) apply_filters( 'cw_upload_concurrency_max', self::DEFAULT_MAX );
		return max( 1, min( 50, $max ) );
	}

	/**
	 * @return bool True if a slot was acquired.
	 */
	public static function acquire() {
		global $wpdb;

		$locked = self::get_lock( 2 );
		if ( ! $locked ) {
			return false;
		}

		try {
			$state = self::read_state();
			$now   = time();
			// Drop stale holds (crashed workers that never released). Keep token keys.
			$fresh = [];
			foreach ( (array) ( $state['holders'] ?? [] ) as $tok => $ts ) {
				if ( is_numeric( $ts ) && ( $now - (int) $ts ) < self::STALE_SECONDS ) {
					$fresh[ (string) $tok ] = (int) $ts;
				}
			}
			$state['holders'] = $fresh;

			if ( count( $state['holders'] ) >= self::max_slots() ) {
				self::write_state( $state );
				return false;
			}

			$token = self::token();
			$state['holders'][ $token ] = $now;
			self::write_state( $state );

			// Stash token for release() in this request.
			$GLOBALS['cw_upload_slot_token'] = $token;
			return true;
		} finally {
			self::release_lock();
		}
	}

	/**
	 * Release the slot held by this request (if any).
	 */
	public static function release() {
		$token = isset( $GLOBALS['cw_upload_slot_token'] ) ? (string) $GLOBALS['cw_upload_slot_token'] : '';
		if ( $token === '' ) {
			return;
		}
		unset( $GLOBALS['cw_upload_slot_token'] );

		if ( ! self::get_lock( 2 ) ) {
			return;
		}
		try {
			$state = self::read_state();
			if ( isset( $state['holders'][ $token ] ) ) {
				unset( $state['holders'][ $token ] );
				self::write_state( $state );
			}
		} finally {
			self::release_lock();
		}
	}

	/**
	 * @return int
	 */
	public static function inflight_count() {
		$state = self::read_state();
		return count( (array) ( $state['holders'] ?? [] ) );
	}

	/**
	 * Lighten media work while a slot is held (fewer thumbs = less CPU/RAM).
	 */
	public static function begin_light_media() {
		add_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'filter_light_sizes' ], 99 );
		add_filter( 'cw_skip_image_optimize', '__return_true' );
	}

	public static function end_light_media() {
		remove_filter( 'intermediate_image_sizes_advanced', [ __CLASS__, 'filter_light_sizes' ], 99 );
		remove_filter( 'cw_skip_image_optimize', '__return_true' );
	}

	/**
	 * @param array<string, mixed> $sizes
	 * @return array<string, mixed>
	 */
	public static function filter_light_sizes( $sizes ) {
		if ( ! is_array( $sizes ) ) {
			return [];
		}
		$keep = [ 'thumbnail', 'medium' ];
		$out  = [];
		foreach ( $keep as $k ) {
			if ( isset( $sizes[ $k ] ) ) {
				$out[ $k ] = $sizes[ $k ];
			}
		}
		return $out;
	}

	/**
	 * @return array{holders: array<string, int>}
	 */
	private static function read_state() {
		$raw = get_option( self::OPTION_KEY, [] );
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		if ( ! isset( $raw['holders'] ) || ! is_array( $raw['holders'] ) ) {
			$raw['holders'] = [];
		}
		return $raw;
	}

	/**
	 * @param array{holders: array<string, int>} $state
	 */
	private static function write_state( array $state ) {
		update_option( self::OPTION_KEY, $state, false );
	}

	private static function token() {
		return wp_generate_password( 12, false, false ) . '-' . getmypid();
	}

	/**
	 * @param int $timeout_seconds
	 * @return bool
	 */
	private static function get_lock( $timeout_seconds = 2 ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return true; // best-effort without DB
		}
		$got = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::LOCK_NAME, (int) $timeout_seconds ) );
		return (string) $got === '1';
	}

	private static function release_lock() {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return;
		}
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::LOCK_NAME ) );
	}
}
