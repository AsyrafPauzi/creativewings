<?php
/**
 * Sponsor interest form → CPT lead + email notify.
 *
 * @package CreativeWings
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class CW_Sponsor_Leads {

    const ACTION   = 'cw_sponsor_interest';
    const NONCE    = 'cw_sponsor_interest_nonce';
    const NOTIFY   = 'hello@creativewings.asia';

    public static function register_hooks() {
        add_action( 'admin_post_nopriv_' . self::ACTION, [ __CLASS__, 'handle_submit' ] );
        add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_submit' ] );
    }

    /**
     * @return array<string,string>
     */
    public static function packages() {
        return [
            'champion'  => __( 'Champion', 'creativewings-core' ),
            'partner'   => __( 'Partner', 'creativewings-core' ),
            'supporter' => __( 'Supporter', 'creativewings-core' ),
            'inkind'    => __( 'In-kind', 'creativewings-core' ),
        ];
    }

    public static function handle_submit() {
        $redirect = wp_get_referer() ?: home_url( '/sponsors/' );
        $redirect = remove_query_arg( [ 'cw_sponsor_ok', 'cw_sponsor_err' ], $redirect );

        if ( ! isset( $_POST[ self::NONCE ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE ] ) ), self::ACTION ) ) {
            wp_safe_redirect( add_query_arg( 'cw_sponsor_err', 'nonce', $redirect ) );
            exit;
        }

        $name    = isset( $_POST['cw_sponsor_name'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_sponsor_name'] ) ) : '';
        $email   = isset( $_POST['cw_sponsor_email'] ) ? sanitize_email( wp_unslash( $_POST['cw_sponsor_email'] ) ) : '';
        $company = isset( $_POST['cw_sponsor_company'] ) ? sanitize_text_field( wp_unslash( $_POST['cw_sponsor_company'] ) ) : '';
        $package = isset( $_POST['cw_sponsor_package'] ) ? sanitize_key( wp_unslash( $_POST['cw_sponsor_package'] ) ) : '';
        $message = isset( $_POST['cw_sponsor_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['cw_sponsor_message'] ) ) : '';

        $packages = self::packages();
        if ( $name === '' || ! is_email( $email ) || $company === '' || ! isset( $packages[ $package ] ) ) {
            wp_safe_redirect( add_query_arg( 'cw_sponsor_err', 'fields', $redirect . '#cw-sponsor-interest' ) );
            exit;
        }

        // Soft honeypot.
        if ( ! empty( $_POST['cw_sponsor_website'] ) ) {
            wp_safe_redirect( add_query_arg( 'cw_sponsor_ok', '1', $redirect . '#cw-sponsor-interest' ) );
            exit;
        }

        $title = sprintf(
            /* translators: 1: company, 2: package label */
            __( 'Sponsor interest: %1$s (%2$s)', 'creativewings-core' ),
            $company,
            $packages[ $package ]
        );

        $post_id = wp_insert_post(
            [
                'post_type'    => 'cw_sponsor_lead',
                'post_status'  => 'private',
                'post_title'   => $title,
                'post_content' => $message,
                'post_author'  => 1,
            ],
            true
        );

        if ( is_wp_error( $post_id ) || ! $post_id ) {
            wp_safe_redirect( add_query_arg( 'cw_sponsor_err', 'save', $redirect . '#cw-sponsor-interest' ) );
            exit;
        }

        update_post_meta( $post_id, 'cw_sponsor_name', $name );
        update_post_meta( $post_id, 'cw_sponsor_email', $email );
        update_post_meta( $post_id, 'cw_sponsor_company', $company );
        update_post_meta( $post_id, 'cw_sponsor_package', $package );
        update_post_meta( $post_id, 'cw_sponsor_ip', isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '' );

        $body = implode(
            "\n",
            [
                'Name: ' . $name,
                'Email: ' . $email,
                'Company: ' . $company,
                'Package: ' . $packages[ $package ],
                '',
                'Message:',
                $message !== '' ? $message : '(none)',
                '',
                'Lead ID: ' . (int) $post_id,
            ]
        );

        wp_mail(
            self::NOTIFY,
            '[Creative Wings] ' . $title,
            $body,
            [
                'Content-Type: text/plain; charset=UTF-8',
                'Reply-To: ' . $name . ' <' . $email . '>',
            ]
        );

        wp_safe_redirect( add_query_arg( 'cw_sponsor_ok', '1', $redirect . '#cw-sponsor-interest' ) );
        exit;
    }
}
