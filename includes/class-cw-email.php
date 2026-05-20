<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Email {

    public function __construct() {
        add_action( 'cw_staged_claimed', [ $this, 'on_claimed' ], 10, 3 );
        add_action( 'cw_pending_ready_for_claim', [ $this, 'on_pending_ready' ], 10, 3 );
        add_action( 'cw_order_entry_created', [ $this, 'on_order_complete' ], 10, 4 );
        add_action( 'cw_certificate_ready', [ $this, 'on_cert_ready' ], 10, 3 );
    }

    public static function send( $to, $subject, $body ) {
        if ( ! $to || ! is_email( $to ) ) {
            return false;
        }
        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];
        return wp_mail( $to, $subject, wp_kses_post( $body ), $headers );
    }

    /**
     * Send the online meeting/event link to the customer for a paid line item.
     *
     * Fires from CW_Shop::create_entries_from_order() once per (order, product),
     * guarded by an order-level meta flag in the caller so retries from
     * payment_complete / status_completed / status_processing hooks don't
     * double-send.
     *
     * @param WC_Order $order      The completed order.
     * @param int      $product_id Campaign product ID with cw_event_mode = online.
     * @return bool true on wp_mail success, false otherwise (always non-fatal).
     */
    public static function send_online_access_link( $order, $product_id ) {
        if ( ! ( $order instanceof WC_Order ) ) {
            return false;
        }
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return false;
        }

        $event_mode = get_post_meta( $product_id, 'cw_event_mode', true );
        if ( 'online' !== $event_mode ) {
            return false;
        }

        $online_link = trim( (string) get_post_meta( $product_id, 'cw_online_link', true ) );
        if ( '' === $online_link ) {
            return false;
        }

        $valid_url = wp_http_validate_url( $online_link );
        if ( ! $valid_url ) {
            $fallback = filter_var( $online_link, FILTER_VALIDATE_URL );
            if ( ! $fallback ) {
                error_log( sprintf(
                    '[CW_Email] Skipped online-access email for order #%d / product #%d — invalid cw_online_link: %s',
                    (int) $order->get_id(),
                    $product_id,
                    $online_link
                ) );
                return false;
            }
            $valid_url = $fallback;
        }
        $online_link = esc_url_raw( $valid_url );

        $to = $order->get_billing_email();
        if ( ! $to ) {
            $user_id = (int) $order->get_user_id();
            if ( $user_id ) {
                $user = get_userdata( $user_id );
                if ( $user && ! empty( $user->user_email ) ) {
                    $to = $user->user_email;
                }
            }
        }
        if ( ! $to || ! is_email( $to ) ) {
            error_log( sprintf(
                '[CW_Email] Skipped online-access email for order #%d / product #%d — no valid recipient.',
                (int) $order->get_id(),
                $product_id
            ) );
            return false;
        }

        $campaign_title = get_the_title( $product_id );
        if ( ! $campaign_title ) {
            $campaign_title = __( 'your campaign', 'creativewings-core' );
        }

        $first_name = trim( (string) $order->get_billing_first_name() );
        if ( '' === $first_name ) {
            $user_id = (int) $order->get_user_id();
            if ( $user_id ) {
                $u = get_userdata( $user_id );
                if ( $u ) {
                    $first_name = $u->first_name ?: $u->display_name;
                }
            }
        }
        if ( '' === $first_name ) {
            $first_name = __( 'there', 'creativewings-core' );
        }

        $org_id   = (int) get_post_meta( $product_id, 'organizer_id', true );
        $org_name = '';
        if ( $org_id ) {
            $org_name = (string) get_user_meta( $org_id, 'business_name', true );
            if ( '' === $org_name ) {
                $org_user = get_userdata( $org_id );
                if ( $org_user ) {
                    $org_name = $org_user->display_name ?: $org_user->user_login;
                }
            }
        }
        if ( '' === $org_name ) {
            $org_name = 'Creative Wings';
        }

        $is_activity = false;
        $terms = get_the_terms( $product_id, 'product_cat' );
        if ( $terms && ! is_wp_error( $terms ) ) {
            foreach ( $terms as $term ) {
                if ( $term->slug === 'activities' ) {
                    $is_activity = true;
                    break;
                }
                if ( ! empty( $term->parent ) ) {
                    $parent = get_term( $term->parent, 'product_cat' );
                    if ( $parent && ! is_wp_error( $parent ) && $parent->slug === 'activities' ) {
                        $is_activity = true;
                        break;
                    }
                }
            }
        }
        $category_label = $is_activity
            ? __( 'Event', 'creativewings-core' )
            : __( 'Campaign', 'creativewings-core' );

        $start_raw    = (string) get_post_meta( $product_id, 'cw_submission_start', true );
        $deadline_raw = (string) get_post_meta( $product_id, 'submission_deadline', true );
        $when_line    = '';
        $start_ts     = $start_raw ? strtotime( $start_raw ) : false;
        $deadline_ts  = $deadline_raw ? strtotime( $deadline_raw ) : false;
        if ( $start_ts && $deadline_ts ) {
            $when_line = sprintf(
                /* translators: 1: campaign start date, 2: campaign end date */
                esc_html__( 'Runs from %1$s to %2$s.', 'creativewings-core' ),
                date_i18n( 'j M Y', $start_ts ),
                date_i18n( 'j M Y', $deadline_ts )
            );
        } elseif ( $start_ts ) {
            $when_line = sprintf(
                /* translators: %s: campaign start date */
                esc_html__( 'Starts on %s.', 'creativewings-core' ),
                date_i18n( 'j M Y', $start_ts )
            );
        } elseif ( $deadline_ts ) {
            $when_line = sprintf(
                /* translators: %s: campaign end/submission date */
                esc_html__( 'Closes on %s.', 'creativewings-core' ),
                date_i18n( 'j M Y', $deadline_ts )
            );
        }

        $subject = sprintf(
            /* translators: %s: campaign title */
            __( 'Your access link for %s', 'creativewings-core' ),
            $campaign_title
        );

        $body = self::build_online_access_link_html_body( [
            'first_name'     => $first_name,
            'campaign_title' => $campaign_title,
            'when_line'      => $when_line,
            'online_link'    => $online_link,
            'category_label' => $category_label,
            'organiser_name' => $org_name,
        ] );

        $html = self::email_template_wrap( [
            'heading' => __( 'Your access link is ready', 'creativewings-core' ),
            'body'    => $body,
        ] );

        $headers = [ 'Content-Type: text/html; charset=UTF-8' ];

        // Scoped from-address branding so we don't disturb other plugins' emails.
        $force_from_addr = static function () {
            $host = wp_parse_url( home_url(), PHP_URL_HOST );
            $host = preg_replace( '/^www\./i', '', (string) $host );
            return $host ? ( 'no-reply@' . $host ) : 'no-reply@creativewings.asia';
        };
        $force_from_name = static function () {
            return 'Creative Wings';
        };
        add_filter( 'wp_mail_from', $force_from_addr, 99 );
        add_filter( 'wp_mail_from_name', $force_from_name, 99 );

        $sent = false;
        try {
            $sent = (bool) wp_mail( $to, $subject, $html, $headers );
        } catch ( \Throwable $e ) {
            error_log( sprintf(
                '[CW_Email] wp_mail threw while sending online-access email for order #%d / product #%d: %s',
                (int) $order->get_id(),
                $product_id,
                $e->getMessage()
            ) );
            $sent = false;
        }

        remove_filter( 'wp_mail_from', $force_from_addr, 99 );
        remove_filter( 'wp_mail_from_name', $force_from_name, 99 );

        if ( ! $sent ) {
            error_log( sprintf(
                '[CW_Email] wp_mail returned false for online-access email — order #%d / product #%d / to %s',
                (int) $order->get_id(),
                $product_id,
                $to
            ) );
        }

        return $sent;
    }

    /**
     * Inner HTML body for the online-access email (without the brand shell).
     *
     * @param array{first_name:string,campaign_title:string,when_line:string,online_link:string,category_label:string,organiser_name:string} $args
     */
    private static function build_online_access_link_html_body( array $args ) {
        $first_name     = esc_html( $args['first_name'] );
        $campaign_title = esc_html( $args['campaign_title'] );
        $when_line      = $args['when_line']; // already escaped above
        $url_esc        = esc_url( $args['online_link'] );
        $category_label = esc_html( $args['category_label'] );
        $organiser_name = esc_html( $args['organiser_name'] );

        $hi_line     = sprintf( __( 'Hi %s,', 'creativewings-core' ), '<strong>' . $first_name . '</strong>' );
        $registered  = sprintf(
            /* translators: %s: campaign title */
            esc_html__( "You're registered for %s.", 'creativewings-core' ),
            '<strong>' . $campaign_title . '</strong>'
        );
        $cta_label   = sprintf(
            /* translators: %s: lowercased category label, e.g. event / campaign */
            esc_html__( 'Join the %s', 'creativewings-core' ),
            $category_label
        );
        $fallback    = esc_html__( "If the button doesn't work, copy this link into your browser:", 'creativewings-core' );
        $hosted_by   = sprintf(
            /* translators: %s: organiser display name */
            esc_html__( 'Hosted by %s.', 'creativewings-core' ),
            '<strong>' . $organiser_name . '</strong>'
        );
        $privacy_note = esc_html__( 'This link is for you — please don\'t share it publicly.', 'creativewings-core' );

        $body  = '<p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">' . $hi_line . '</p>';
        $body .= '<p style="margin:0 0 18px;font-size:15px;line-height:1.65;color:#475569;">' . $registered . '</p>';
        if ( $when_line ) {
            $body .= '<p style="margin:0 0 18px;font-size:14px;line-height:1.6;color:#64748b;">' . $when_line . '</p>';
        }
        $body .= '<p style="text-align:center;margin:28px 0;"><a href="' . $url_esc . '" style="display:inline-block;background:#0F6796;color:#ffffff;text-decoration:none;padding:14px 32px;border-radius:999px;font-weight:700;font-size:15px;line-height:1;">' . $cta_label . '</a></p>';
        $body .= '<p style="margin:0 0 10px;font-size:13px;line-height:1.65;color:#64748b;">' . $fallback . '</p>';
        $body .= '<p style="margin:0 0 22px;font-size:13px;line-height:1.65;color:#0F6796;word-break:break-all;"><a href="' . $url_esc . '" style="color:#0F6796;">' . esc_html( $args['online_link'] ) . '</a></p>';
        $body .= '<p style="margin:0 0 6px;font-size:14px;line-height:1.65;color:#475569;">' . $hosted_by . '</p>';
        $body .= '<p style="margin:18px 0 0;padding:14px 16px;background:#f1f5f9;border-radius:10px;font-size:13px;line-height:1.6;color:#64748b;">' . $privacy_note . '</p>';

        return $body;
    }

    /**
     * Creative Wings branded HTML email shell (matches the password-reset email).
     * Table-based + inline styles for max email-client compatibility.
     *
     * @param array{heading:string,body:string} $args
     */
    private static function email_template_wrap( array $args ) {
        $heading      = isset( $args['heading'] ) ? $args['heading'] : '';
        $body         = isset( $args['body'] ) ? $args['body'] : '';
        $year         = date( 'Y' );
        $site_url     = esc_url( home_url() );
        $heading_esc  = esc_html( $heading );
        $brand        = 'Creative Wings';

        $footer_copy = sprintf(
            /* translators: 1: year, 2: brand name */
            esc_html__( '© %1$d %2$s. All rights reserved.', 'creativewings-core' ),
            $year,
            $brand
        );
        $footer_note = esc_html__( "This is an automated message — please don't reply directly.", 'creativewings-core' );

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>{$heading_esc}</title>
</head>
<body style="margin:0;padding:0;background:#f4f6f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1e293b;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#f4f6f9;padding:32px 16px;">
        <tr>
            <td align="center">
                <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:560px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e8edf2;">
                    <tr>
                        <td style="background:#006599;padding:28px 32px;text-align:center;">
                            <a href="{$site_url}" style="color:#ffffff;text-decoration:none;font-size:22px;font-weight:800;letter-spacing:-0.3px;">{$brand}</a>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:36px 32px 28px;">
                            <h2 style="margin:0 0 18px;font-size:22px;font-weight:800;color:#1e293b;letter-spacing:-0.2px;">{$heading_esc}</h2>
                            {$body}
                        </td>
                    </tr>
                    <tr>
                        <td style="background:#f8fafc;padding:20px 32px;text-align:center;color:#94a3b8;font-size:12px;border-top:1px solid #e8edf2;">
                            <p style="margin:0;">{$footer_copy}</p>
                            <p style="margin:6px 0 0;">{$footer_note}</p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
HTML;
    }

    public function on_pending_ready( $user_id, $staged_row, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $link = ( function_exists( 'cw_core' ) && cw_core()->claim_flow )
            ? cw_core()->claim_flow->get_link_submission_url( [ 'step' => 'waiting' ] )
            : add_query_arg( 'step', 'waiting', wc_get_account_endpoint_url( 'cw-link-submission' ) );
        $title = get_the_title( $campaign_id );
        $body  = sprintf(
            '<p>%s</p><p><strong>%s</strong> — %s</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Your school has uploaded the artwork for your submission code. You can now confirm the student name and complete registration.', 'creativewings-core' ),
            esc_html( $staged_row['student_name'] ?? '' ),
            esc_html( $staged_row['submission_code'] ?? '' ),
            esc_url( $link ),
            esc_html__( 'Continue linking your code', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Artwork ready — complete your registration', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_claimed( $user_id, $staged_row, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $title = get_the_title( $campaign_id );
        $body  = sprintf(
            '<p>%s</p><p><strong>%s</strong> — %s</p><p>%s</p>',
            esc_html__( 'Your submission code has been linked successfully.', 'creativewings-core' ),
            esc_html( $staged_row['student_name'] ?? '' ),
            esc_html( $staged_row['submission_code'] ?? '' ),
            esc_html( $title )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Submission linked', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_order_complete( $user_id, $entry_id, $campaign_id, $order_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $account = wc_get_account_endpoint_url( 'orders' );
        $body    = sprintf(
            '<p>%s</p><p>%s: #%d</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Thank you — your campaign registration is complete.', 'creativewings-core' ),
            esc_html__( 'Order', 'creativewings-core' ),
            (int) $order_id,
            esc_url( $account ),
            esc_html__( 'View your account', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Registration complete', 'creativewings-core' ) ),
            $body
        );
    }

    public function on_cert_ready( $user_id, $entry_id, $campaign_id ) {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }
        $url  = class_exists( 'CW_Certificate' ) ? CW_Certificate::download_url( $entry_id ) : '';
        $body = sprintf(
            '<p>%s</p><p><a href="%s">%s</a></p>',
            esc_html__( 'Your participation certificate is ready to download.', 'creativewings-core' ),
            esc_url( $url ),
            esc_html__( 'Download certificate', 'creativewings-core' )
        );
        self::send(
            $user->user_email,
            sprintf( '[%s] %s', get_bloginfo( 'name' ), __( 'Certificate ready', 'creativewings-core' ) ),
            $body
        );
    }
}
