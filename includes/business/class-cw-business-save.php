<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( 'wp_kses_post' ) ) {
    require_once ABSPATH . 'wp-includes/kses.php';
}

class CW_Business_Save {

    public function __construct() {
        add_action( 'admin_post_cw_create_campaign', [ $this, 'handle_submission' ] );
        add_action( 'admin_post_cw_update_campaign', [ $this, 'handle_update' ] );
        add_action( 'admin_post_cw_save_biz_info', [ $this, 'handle_save_biz_info' ] );
    }

    public function handle_submission() {
        if ( ! isset( $_POST['cw_nonce'] ) || ! wp_verify_nonce( $_POST['cw_nonce'], 'cw_create_campaign_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $this->save_campaign( 0 );
    }

    public function handle_update() {
        if ( ! isset( $_POST['cw_nonce'] ) || ! wp_verify_nonce( $_POST['cw_nonce'], 'cw_update_campaign_nonce' ) ) {
            wp_die( 'Security check failed.' );
        }
        $pid = intval( $_POST['campaign_id'] );
        if ( class_exists( 'CW_Roles' ) ? CW_Roles::user_owns_campaign( $pid ) : ( (int) get_post_field( 'post_author', $pid ) === get_current_user_id() ) ) {
            $this->save_campaign( $pid );
        } else {
            wp_die( 'Unauthorized' );
        }
    }

    private function save_campaign( $pid ) {
        if ( ! is_user_logged_in() ) {
            wp_die( 'Error' );
        }
        $uid = get_current_user_id();

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        require_once CW_PATH . 'includes/business/class-cw-campaign-persistence.php';

        $data = CW_Campaign_Persistence::array_from_post();
        $result = CW_Campaign_Persistence::save_from_array( $data, (int) $pid, $uid );

        if ( is_wp_error( $result ) ) {
            wp_die( esc_html( $result->get_error_message() ) );
        }

        $pid           = (int) $result;
        $is_new_campaign = ! isset( $_POST['campaign_id'] ) || ! (int) $_POST['campaign_id'];

        if ( ! empty( $_FILES['campaign_image']['name'] ) ) {
            $aid = media_handle_upload( 'campaign_image', $pid );
            if ( ! is_wp_error( $aid ) ) {
                set_post_thumbnail( $pid, $aid );
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $aid, 'campaign_thumb' );
                }
            }
        }
        if ( ! empty( $_FILES['cw_cert_template']['name'] ) ) {
            $cid = media_handle_upload( 'cw_cert_template', $pid );
            if ( ! is_wp_error( $cid ) ) {
                update_post_meta( $pid, 'cw_cert_template', wp_get_attachment_url( $cid ) );
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $cid, 'hero' );
                }
            }
        }

        if ( class_exists( 'CW_Sponsor_Coupons' ) ) {
            CW_Sponsor_Coupons::sync_campaign_coupons( $pid );
        }
        if ( class_exists( 'CW_Staged_Submissions' ) ) {
            CW_Staged_Submissions::sync_school_upload_tokens( $pid );
        }

        $message = $is_new_campaign ? 'New Campaign created successfully!' : 'Campaign updated successfully!';
        set_transient( 'cw_popup_msg_uid_' . $uid, $message, 60 );
        set_transient( 'cw_popup_type_uid_' . $uid, 'success', 60 );

        wp_safe_redirect( add_query_arg( 'tab', 'campaigns', get_permalink( wc_get_page_id( 'myaccount' ) ) ) );
        exit;
    }

    public function handle_save_biz_info() {
        if ( ! is_user_logged_in() || ! wp_verify_nonce( $_POST['_wpnonce'], 'cw_biz_info_nonce' ) ) {
            wp_die( 'Security Error' );
        }

        $uid = get_current_user_id();

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        // Plain text fields (single-line text / select / address textarea / tagline).
        $text_keys = [
            // Existing
            'business_name', 'business_phone', 'business_address', 'business_ssm',
            // New basics / story / location
            'business_tagline', 'business_industry', 'business_team_size',
            'business_city', 'business_country',
        ];
        foreach ( $text_keys as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_user_meta( $uid, $f, sanitize_text_field( wp_unslash( $_POST[ $f ] ) ) );
            }
        }

        // URLs — sanitize with esc_url_raw to preserve scheme + query.
        $url_keys = [
            'business_website',
            'Facebook_url', 'instagram_url', 'linkeden_url', 'twitter_url',
            'behave_url', 'youtube_url', 'tiktok_url',
        ];
        foreach ( $url_keys as $f ) {
            if ( isset( $_POST[ $f ] ) ) {
                update_user_meta( $uid, $f, esc_url_raw( wp_unslash( $_POST[ $f ] ) ) );
            }
        }

        // Long-form description — allow basic post-style HTML (links, lists, bold, etc).
        if ( isset( $_POST['business_about'] ) ) {
            update_user_meta( $uid, 'business_about', wp_kses_post( wp_unslash( $_POST['business_about'] ) ) );
        }

        // Founded year — bounded integer.
        if ( isset( $_POST['business_founded_year'] ) ) {
            $year = intval( $_POST['business_founded_year'] );
            update_user_meta( $uid, 'business_founded_year', $year > 0 ? $year : '' );
        }

        // Public-visibility toggles for the organiser card.
        // Hidden input ensures the field is always submitted as '0' if the checkbox is unticked.
        foreach ( [ 'cw_show_org_email', 'cw_show_org_phone' ] as $vf ) {
            if ( isset( $_POST[ $vf ] ) ) {
                $val = ( $_POST[ $vf ] === '1' || $_POST[ $vf ] === 1 ) ? '1' : '0';
                update_user_meta( $uid, $vf, $val );
            }
        }

        // Business logo upload — also mirror to creator_profile_image so the
        // public organiser profile renderer (CW_Users::render_public_profile_html)
        // can pick up the avatar without extra wiring.
        if ( ! empty( $_FILES['business_logo']['name'] ) ) {
            $lid = media_handle_upload( 'business_logo', 0 );
            if ( ! is_wp_error( $lid ) ) {
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $lid, 'logo' );
                }
                $logo_data = [ 'id' => $lid, 'url' => wp_get_attachment_url( $lid ) ];
                update_user_meta( $uid, 'business_logo', $logo_data );
                update_user_meta( $uid, 'creator_profile_image', $logo_data );
            }
        }

        // Business cover upload — saved under business_cover and mirrored to
        // creator_header_image so the public profile hero banner renders it.
        if ( ! empty( $_FILES['business_cover']['name'] ) ) {
            $cid = media_handle_upload( 'business_cover', 0 );
            if ( ! is_wp_error( $cid ) ) {
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $cid, 'cover' );
                }
                $cover_data = [ 'id' => $cid, 'url' => wp_get_attachment_url( $cid ) ];
                update_user_meta( $uid, 'business_cover', $cover_data );
                update_user_meta( $uid, 'creator_header_image', $cover_data );
            }
        }

        set_transient( 'cw_popup_msg_uid_' . $uid, 'Profile updated successfully.', 60 );
        set_transient( 'cw_popup_type_uid_' . $uid, 'success', 60 );

        $url = wp_get_referer() ?: home_url( '/my-account/' );
        wp_safe_redirect( remove_query_arg( [ 'updated' ], $url ) );
        exit;
    }
}
