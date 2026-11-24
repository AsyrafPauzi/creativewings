<?php
/**
 * Platform invite codes + register / invite / first-join point bonuses.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Invite {

    const COOKIE           = 'cw_ref';
    const META_CODE        = 'cw_invite_code';
    const META_INVITED_BY  = 'cw_invited_by';
    const META_FIRST_JOIN  = 'cw_first_join_bonus';
    const META_REG_BONUS   = 'cw_register_bonus';
    const BONUS_POINTS     = 50;
    const COOKIE_DAYS      = 7;
    const CODE_LENGTH      = 8;

    public static function register_hooks() {
        add_action( 'init', [ __CLASS__, 'capture_ref_cookie' ], 1 );
        add_action( 'cw_entry_created_from_order', [ __CLASS__, 'maybe_award_first_join' ], 50, 4 );
        add_action( 'cw_order_entry_created', [ __CLASS__, 'maybe_award_first_join' ], 50, 4 );
        add_action( 'cw_guest_account_attached', [ __CLASS__, 'on_guest_account_attached' ], 20, 2 );
    }

    /**
     * Persist ?cw_ref= for 7 days so later register paths can attribute.
     */
    public static function capture_ref_cookie() {
        if ( empty( $_GET['cw_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return;
        }
        $code = self::sanitize_code( wp_unslash( $_GET['cw_ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        if ( $code === '' ) {
            return;
        }
        if ( ! headers_sent() ) {
            setcookie(
                self::COOKIE,
                $code,
                time() + ( self::COOKIE_DAYS * DAY_IN_SECONDS ),
                COOKIEPATH ? COOKIEPATH : '/',
                COOKIE_DOMAIN,
                is_ssl(),
                true
            );
        }
        $_COOKIE[ self::COOKIE ] = $code;
    }

    /**
     * Shared post-create hook for all contestant registration paths.
     *
     * @param int $user_id Newly created user.
     */
    public static function on_user_registered( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }

        self::ensure_invite_code( $user_id );
        self::award_register_bonus( $user_id );
        self::apply_invite_attribution( $user_id );
    }

    /**
     * @param int $user_id
     * @return string
     */
    public static function ensure_invite_code( $user_id ) {
        $user_id = (int) $user_id;
        $existing = (string) get_user_meta( $user_id, self::META_CODE, true );
        if ( $existing !== '' ) {
            return strtoupper( $existing );
        }

        $code = self::generate_unique_code();
        update_user_meta( $user_id, self::META_CODE, $code );
        return $code;
    }

    /**
     * Platform invite URL. Default destination is the site homepage.
     *
     * @param int    $user_id
     * @param string $base_url Optional absolute URL to attach cw_ref onto.
     * @return string Absolute invite URL.
     */
    public static function get_invite_url( $user_id, $base_url = '' ) {
        $code = self::ensure_invite_code( (int) $user_id );
        $base = is_string( $base_url ) ? trim( $base_url ) : '';
        if ( $base === '' ) {
            $base = home_url( '/' );
        }
        return add_query_arg( 'cw_ref', $code, $base );
    }

    /**
     * Invite URL that lands on a specific campaign product.
     *
     * @param int $user_id
     * @param int $product_id
     * @return string
     */
    public static function get_campaign_invite_url( $user_id, $product_id ) {
        $product_id = (int) $product_id;
        $permalink  = $product_id > 0 ? get_permalink( $product_id ) : '';
        if ( ! $permalink ) {
            return self::get_invite_url( $user_id );
        }
        return self::get_invite_url( $user_id, $permalink );
    }

    /**
     * @param int $user_id
     * @return string
     */
    public static function get_invite_code( $user_id ) {
        return self::ensure_invite_code( (int) $user_id );
    }

    private static function award_register_bonus( $user_id ) {
        if ( 'yes' === get_user_meta( $user_id, self::META_REG_BONUS, true ) ) {
            return;
        }
        if ( ! class_exists( 'CW_Points' ) ) {
            return;
        }
        $ok = CW_Points::credit_bonus(
            $user_id,
            self::BONUS_POINTS,
            'register',
            $user_id,
            __( 'Welcome bonus for creating an account', 'creativewings-core' )
        );
        if ( $ok ) {
            update_user_meta( $user_id, self::META_REG_BONUS, 'yes' );
        }
    }

    private static function apply_invite_attribution( $user_id ) {
        $existing_inviter = (int) get_user_meta( $user_id, self::META_INVITED_BY, true );
        if ( $existing_inviter > 0 ) {
            // Already attributed — retry invite credit only if a prior credit failed.
            if ( $existing_inviter !== $user_id ) {
                self::credit_invite_bonus( $existing_inviter, $user_id );
            }
            return;
        }

        $code = '';
        if ( ! empty( $_COOKIE[ self::COOKIE ] ) ) {
            $code = self::sanitize_code( wp_unslash( $_COOKIE[ self::COOKIE ] ) );
        }
        if ( $code === '' && ! empty( $_GET['cw_ref'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $code = self::sanitize_code( wp_unslash( $_GET['cw_ref'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        }
        if ( $code === '' ) {
            return;
        }

        $inviter_id = self::find_user_by_code( $code );
        if ( $inviter_id <= 0 || $inviter_id === $user_id ) {
            return;
        }

        update_user_meta( $user_id, self::META_INVITED_BY, $inviter_id );
        self::credit_invite_bonus( $inviter_id, $user_id );
    }

    /**
     * Credit inviter +50 once per invitee (ledger-gated).
     *
     * @param int $inviter_id
     * @param int $invitee_id
     */
    private static function credit_invite_bonus( $inviter_id, $invitee_id ) {
        $inviter_id = (int) $inviter_id;
        $invitee_id = (int) $invitee_id;
        if ( $inviter_id <= 0 || $invitee_id <= 0 || $inviter_id === $invitee_id ) {
            return;
        }
        if ( ! class_exists( 'CW_Points' ) ) {
            return;
        }
        if ( self::invite_bonus_already_credited( $invitee_id ) ) {
            return;
        }

        CW_Points::credit_bonus(
            $inviter_id,
            self::BONUS_POINTS,
            'invite',
            $invitee_id,
            sprintf(
                /* translators: %d: invited user id */
                __( 'Invite bonus — friend joined (#%d)', 'creativewings-core' ),
                $invitee_id
            )
        );
    }

    /**
     * True if ledger already has an invite earn for this invitee (ref_id).
     *
     * @param int $invitee_id
     * @return bool
     */
    private static function invite_bonus_already_credited( $invitee_id ) {
        global $wpdb;
        $invitee_id = (int) $invitee_id;
        if ( $invitee_id <= 0 || ! class_exists( 'CW_Points' ) ) {
            return false;
        }
        $table = CW_Points::table();
        $found = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$table} WHERE ref_type = %s AND ref_id = %d AND type = %s LIMIT 1",
                'invite',
                $invitee_id,
                'earn'
            )
        );
        return $found > 0;
    }

    /**
     * First successful campaign join → +50 once.
     *
     * @param int $order_id
     * @param int $user_id
     * @param mixed $entry_id
     * @param mixed $product_id
     */
    public static function maybe_award_first_join( $order_id, $user_id = 0, $entry_id = 0, $product_id = 0 ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 && $order_id ) {
            $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null;
            if ( $order ) {
                $user_id = (int) $order->get_user_id();
            }
        }
        self::award_first_join_bonus( $user_id );
    }

    /**
     * @param int $order_id
     * @param int $user_id
     */
    public static function on_guest_account_attached( $order_id, $user_id ) {
        self::award_first_join_bonus( (int) $user_id );
    }

    private static function award_first_join_bonus( $user_id ) {
        $user_id = (int) $user_id;
        if ( $user_id <= 0 ) {
            return;
        }
        if ( 'yes' === get_user_meta( $user_id, self::META_FIRST_JOIN, true ) ) {
            return;
        }
        if ( ! class_exists( 'CW_Points' ) ) {
            return;
        }

        $ok = CW_Points::credit_bonus(
            $user_id,
            self::BONUS_POINTS,
            'first_join',
            $user_id,
            __( 'First campaign join bonus', 'creativewings-core' )
        );
        if ( $ok ) {
            update_user_meta( $user_id, self::META_FIRST_JOIN, 'yes' );
        }
    }

    /**
     * @param string $code
     * @return int User ID or 0.
     */
    public static function find_user_by_code( $code ) {
        $code = self::sanitize_code( $code );
        if ( $code === '' ) {
            return 0;
        }
        $users = get_users(
            [
                'meta_key'   => self::META_CODE,
                'meta_value' => $code,
                'number'     => 1,
                'fields'     => 'ID',
            ]
        );
        return ! empty( $users[0] ) ? (int) $users[0] : 0;
    }

    private static function generate_unique_code() {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        for ( $attempt = 0; $attempt < 12; $attempt++ ) {
            $code = '';
            for ( $i = 0; $i < self::CODE_LENGTH; $i++ ) {
                $code .= $alphabet[ wp_rand( 0, strlen( $alphabet ) - 1 ) ];
            }
            if ( self::find_user_by_code( $code ) === 0 ) {
                return $code;
            }
        }
        return strtoupper( substr( md5( uniqid( (string) wp_rand(), true ) ), 0, self::CODE_LENGTH ) );
    }

    /**
     * @param string $code
     * @return string
     */
    public static function sanitize_code( $code ) {
        $code = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $code ) );
        if ( strlen( $code ) < 4 || strlen( $code ) > 16 ) {
            return '';
        }
        return $code;
    }
}
