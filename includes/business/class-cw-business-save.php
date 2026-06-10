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
            wp_die( esc_html__( 'You must be logged in to save a campaign.', 'creativewings-core' ) );
        }
        $uid = get_current_user_id();

        // Buffer ALL output during the save flow. Stray PHP notices, theme
        // hooks that echo HTML during `wp_update_post`, image-optimiser
        // libraries that print debug strings, etc. would otherwise corrupt
        // the response and silently kill the final `wp_safe_redirect`,
        // leaving the user staring at /wp-admin/admin-post.php — visible
        // as an apparent blank page. We discard the buffer right before
        // redirecting so the redirect headers always send cleanly.
        if ( ! headers_sent() ) {
            ob_start();
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        require_once CW_PATH . 'includes/business/class-cw-campaign-persistence.php';

        $data = CW_Campaign_Persistence::array_from_post();
        $result = CW_Campaign_Persistence::save_from_array( $data, (int) $pid, $uid );

        if ( is_wp_error( $result ) ) {
            // Surface the real error message instead of relying on `wp_die`
            // alone (which can look like a blank page on themes that aren't
            // styled for it). Falls back to a friendly default when the
            // WP_Error itself is empty.
            if ( ob_get_level() > 0 ) {
                ob_end_clean();
            }
            $msg = $result->get_error_message();
            if ( $msg === '' ) {
                $msg = __( 'Could not save the campaign. Please try again or contact support.', 'creativewings-core' );
            }
            wp_die(
                esc_html( $msg ),
                esc_html__( 'Campaign save failed', 'creativewings-core' ),
                [ 'response' => 400, 'back_link' => true ]
            );
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

        // ─── Gallery uploads ──────────────────────────────────────────
        // Persisted as the WooCommerce product gallery (_product_image_gallery,
        // CSV of attachment IDs). The public product page picks them up
        // automatically via standard WC rendering.
        $gallery_ids = [];

        // 1) Keep-list: IDs of pre-existing gallery items the editor didn't
        //    remove. Empty string means "keep nothing".
        if ( isset( $_POST['cw_gallery_keep'] ) ) {
            $raw = (string) wp_unslash( $_POST['cw_gallery_keep'] );
            if ( $raw !== '' ) {
                foreach ( explode( ',', $raw ) as $token ) {
                    $id = (int) trim( $token );
                    if ( $id > 0 ) {
                        $gallery_ids[] = $id;
                    }
                }
            }
        } elseif ( ! empty( $_POST['campaign_id'] ) ) {
            // Form was submitted without the hidden field (older payload). Keep
            // whatever was already on the post so we don't accidentally nuke
            // the gallery.
            $existing = (string) get_post_meta( $pid, '_product_image_gallery', true );
            if ( $existing !== '' ) {
                foreach ( explode( ',', $existing ) as $token ) {
                    $id = (int) trim( $token );
                    if ( $id > 0 ) {
                        $gallery_ids[] = $id;
                    }
                }
            }
        }

        // 2) New uploads.
        if ( ! empty( $_FILES['cw_gallery_files']['name'] ) && is_array( $_FILES['cw_gallery_files']['name'] ) ) {
            $names = $_FILES['cw_gallery_files']['name'];
            $count = count( $names );
            for ( $i = 0; $i < $count; $i++ ) {
                if ( empty( $names[ $i ] ) ) {
                    continue;
                }
                $single = [
                    'name'     => $_FILES['cw_gallery_files']['name'][ $i ],
                    'type'     => $_FILES['cw_gallery_files']['type'][ $i ],
                    'tmp_name' => $_FILES['cw_gallery_files']['tmp_name'][ $i ],
                    'error'    => $_FILES['cw_gallery_files']['error'][ $i ],
                    'size'     => $_FILES['cw_gallery_files']['size'][ $i ],
                ];
                if ( $single['error'] !== UPLOAD_ERR_OK ) {
                    continue;
                }
                // Reassign the global $_FILES slot for media_handle_upload().
                $_FILES['cw_gallery_one'] = $single;
                $new_id = media_handle_upload( 'cw_gallery_one', $pid );
                if ( ! is_wp_error( $new_id ) ) {
                    $gallery_ids[] = (int) $new_id;
                    if ( class_exists( 'CW_Image_Optimizer' ) ) {
                        CW_Image_Optimizer::optimize_attachment( $new_id, 'campaign_thumb' );
                    }
                }
            }
            unset( $_FILES['cw_gallery_one'] );
        }

        // 3) Write the gallery meta (dedupe, preserve order).
        $gallery_ids = array_values( array_unique( array_filter( $gallery_ids ) ) );
        update_post_meta( $pid, '_product_image_gallery', implode( ',', $gallery_ids ) );
        if ( ! empty( $_FILES['cw_cert_template']['name'] ) ) {
            $cid = media_handle_upload( 'cw_cert_template', $pid );
            if ( ! is_wp_error( $cid ) ) {
                update_post_meta( $pid, 'cw_cert_template', wp_get_attachment_url( $cid ) );
                if ( class_exists( 'CW_Image_Optimizer' ) ) {
                    CW_Image_Optimizer::optimize_attachment( $cid, 'hero' );
                }
            }
        }

        // ─── Participant Template (downloadable resource) ─────────────
        // Single attachment per campaign. Stored as `cw_template_file_id` so
        // we can re-resolve the URL (and delete the old file) cleanly when
        // the organiser swaps or removes it.
        $tpl_remove   = ! empty( $_POST['cw_template_remove'] ) && (string) $_POST['cw_template_remove'] !== '0';
        $tpl_existing = (int) get_post_meta( $pid, 'cw_template_file_id', true );
        $tpl_new_id   = 0;

        if ( ! empty( $_FILES['cw_template_file']['name'] ) ) {
            // Templates legitimately include vector source formats WordPress
            // doesn't whitelist by default (.ai, .psd, .eps). Allow them
            // temporarily during JUST this upload so we don't widen the
            // attack surface of the site-wide media library.
            $mime_filter = function ( $mimes ) {
                $mimes['ai']  = 'application/postscript';
                $mimes['eps'] = 'application/postscript';
                $mimes['psd'] = 'image/vnd.adobe.photoshop';
                $mimes['zip'] = 'application/zip';
                // Explicit PDF entry: WordPress' default mime set already
                // includes PDF, but some security plugins (e.g. Wordfence,
                // SecuPress) strip it. Re-declaring it here guarantees the
                // template upload still works on those installs.
                $mimes['pdf'] = 'application/pdf';
                return $mimes;
            };
            // Skip WP's "real MIME vs extension" guard for .ai/.eps/.psd because
            // they're all served as `application/octet-stream` by many editors —
            // wp_check_filetype_and_ext would reject them otherwise.
            $ext_filter = function ( $checked, $file, $filename, $mimes, $real_mime ) {
                $ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
                if ( in_array( $ext, [ 'ai', 'eps', 'psd', 'zip', 'pdf' ], true ) ) {
                    if ( empty( $checked['ext'] ) || empty( $checked['type'] ) ) {
                        $checked['ext']  = $ext;
                        $checked['type'] = $mimes[ $ext ] ?? 'application/octet-stream';
                        $checked['proper_filename'] = false;
                    }
                }
                return $checked;
            };

            add_filter( 'upload_mimes', $mime_filter );
            add_filter( 'wp_check_filetype_and_ext', $ext_filter, 10, 5 );

            $maybe_new = media_handle_upload( 'cw_template_file', $pid );

            remove_filter( 'upload_mimes', $mime_filter );
            remove_filter( 'wp_check_filetype_and_ext', $ext_filter, 10 );

            if ( ! is_wp_error( $maybe_new ) ) {
                $tpl_new_id = (int) $maybe_new;
                update_post_meta( $pid, 'cw_template_file_id', $tpl_new_id );
                // Drop the previous template attachment — it's not referenced
                // anywhere else (no gallery, no thumbnail, just this one slot).
                if ( $tpl_existing && $tpl_existing !== $tpl_new_id ) {
                    wp_delete_attachment( $tpl_existing, true );
                }
            }
        } elseif ( $tpl_remove && $tpl_existing ) {
            // No new file but the user pressed "Remove" → fully detach.
            wp_delete_attachment( $tpl_existing, true );
            delete_post_meta( $pid, 'cw_template_file_id' );
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

        // Discard any buffered output before redirecting. Anything noisy
        // during save (plugin notices, optimiser debug lines, etc.) would
        // otherwise prevent the Location: header from being sent and the
        // user would land on a blank admin-post.php response.
        if ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        // Resolve the destination defensively. `wc_get_page_id` can return
        // -1 / 0 on installs where the My Account page slug got renamed or
        // never created; `get_permalink()` then returns false, and
        // `wp_safe_redirect( false )` falls back to /wp-admin which also
        // looks broken to a logged-in business user. Fall back to home URL.
        $myaccount_id  = function_exists( 'wc_get_page_id' ) ? (int) wc_get_page_id( 'myaccount' ) : 0;
        $myaccount_url = $myaccount_id > 0 ? get_permalink( $myaccount_id ) : '';
        if ( ! $myaccount_url ) {
            $myaccount_url = home_url( '/my-account/' );
        }
        $redirect_to = add_query_arg( 'tab', 'campaigns', $myaccount_url );

        wp_safe_redirect( $redirect_to );
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
