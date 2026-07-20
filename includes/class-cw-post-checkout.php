<?php
/**
 * Post-checkout async side effects: points, badges, emails.
 *
 * Entry creation stays synchronous on payment success. Everything else is
 * queued ~30s later so the payment/thank-you request stays short under load.
 *
 * @package CreativeWings
 * @since   11.0.84
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Post_Checkout {

    const HOOK            = 'cw_post_checkout_async';
    const META_QUEUED     = '_cw_post_checkout_queued';
    const META_DONE       = '_cw_post_checkout_done';
    const DELAY_SECONDS   = 30;

    public static function register_hooks() {
        add_action( self::HOOK, [ __CLASS__, 'run' ], 10, 1 );

        // After entry creation (priority 10 on these hooks).
        add_action( 'woocommerce_payment_complete', [ __CLASS__, 'maybe_queue' ], 50, 1 );
        add_action( 'woocommerce_order_status_processing', [ __CLASS__, 'maybe_queue' ], 50, 1 );
        add_action( 'woocommerce_order_status_completed', [ __CLASS__, 'maybe_queue' ], 50, 1 );
    }

    /**
     * Whether inline points/emails should skip (async owns them).
     */
    public static function defers_side_effects() {
        return true;
    }

    /**
     * @param int $order_id
     */
    public static function maybe_queue( $order_id ) {
        self::queue( (int) $order_id );
    }

    /**
     * Schedule one async job per paid CW order.
     *
     * @param int $order_id
     * @return bool True when queued (or already queued/done).
     */
    public static function queue( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
            return false;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        if ( ! self::order_is_paid_enough( $order ) ) {
            return false;
        }

        if ( 'yes' === $order->get_meta( self::META_DONE ) ) {
            return true;
        }

        if ( 'yes' === $order->get_meta( self::META_QUEUED ) ) {
            return true;
        }

        // Prefer unique add via order meta so concurrent hooks don't double-schedule.
        $order->update_meta_data( self::META_QUEUED, 'yes' );
        $order->update_meta_data( self::META_QUEUED . '_at', time() );
        $order->save();

        if ( ! wp_next_scheduled( self::HOOK, [ $order_id ] ) ) {
            wp_schedule_single_event( time() + self::DELAY_SECONDS, self::HOOK, [ $order_id ] );
        }

        // Nudge cron on hosts that only run it on traffic.
        if ( function_exists( 'spawn_cron' ) ) {
            spawn_cron();
        }

        return true;
    }

    /**
     * Cron / async worker.
     *
     * @param int $order_id
     */
    public static function run( $order_id ) {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! function_exists( 'wc_get_order' ) ) {
            return;
        }

        $order = wc_get_order( $order_id );
        if ( ! $order ) {
            return;
        }

        if ( 'yes' === $order->get_meta( self::META_DONE ) ) {
            return;
        }

        if ( ! self::order_is_paid_enough( $order ) ) {
            return;
        }

        try {
            // Safety net: entries should already exist from the sync path.
            if ( class_exists( 'CW_Core_Platform' ) && CW_Core_Platform::instance()->shop ) {
                CW_Core_Platform::instance()->shop->create_entries_from_order( $order_id );
            } elseif ( class_exists( 'CW_Shop' ) ) {
                // Fallback without re-binding hooks: use a throwaway object only if needed.
                // Prefer static entry point when available.
                if ( method_exists( 'CW_Shop', 'create_entries_for_order_id' ) ) {
                    CW_Shop::create_entries_for_order_id( $order_id );
                }
            }

            if ( class_exists( 'CW_Points' ) ) {
                CW_Points::maybe_award_for_order( $order_id, (int) $order->get_user_id() );
            }

            $badge_users = [];
            $uid = (int) $order->get_user_id();
            if ( $uid > 0 ) {
                $badge_users[ $uid ] = true;
            }
            foreach ( $order->get_items() as $item ) {
                $pid = (int) $item->get_product_id();
                if ( $pid > 0 ) {
                    $org = (int) get_post_field( 'post_author', $pid );
                    if ( $org > 0 ) {
                        $badge_users[ $org ] = true;
                    }
                }
            }
            if ( class_exists( 'CW_Badges_Engine' ) ) {
                foreach ( array_keys( $badge_users ) as $badge_uid ) {
                    CW_Badges_Engine::evaluate_user( (int) $badge_uid, [
                        'event'    => 'post_checkout_async',
                        'order_id' => $order_id,
                    ] );
                }
            }

            if ( class_exists( 'CW_Guest_Join' ) ) {
                CW_Guest_Join::send_complete_registration_email_for_order( $order_id );
            }

            if ( class_exists( 'CW_Core_Platform' ) && CW_Core_Platform::instance()->shop
                && method_exists( CW_Core_Platform::instance()->shop, 'send_deferred_online_access_emails' ) ) {
                CW_Core_Platform::instance()->shop->send_deferred_online_access_emails( $order );
            }

            $order->update_meta_data( self::META_DONE, 'yes' );
            $order->update_meta_data( self::META_DONE . '_at', time() );
            $order->save();
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[CW_Post_Checkout] Async job failed for order #%d: %s',
                $order_id,
                $e->getMessage()
            ) );
            // Leave META_DONE unset so a later paid hook / manual reschedule can retry.
        }
    }

    /**
     * @param WC_Order $order
     */
    public static function order_is_paid_enough( $order ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return false;
        }
        if ( $order->is_paid() ) {
            return true;
        }
        return in_array( $order->get_status(), [ 'processing', 'completed' ], true );
    }
}
