<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Per-campaign Supporting Partners + Mentors shown on [cw_event_detail].
 */
class CW_Campaign_Showcase {

    const META_PARTNERS = 'cw_supporting_partners';
    const META_MENTORS  = 'cw_mentors';

    /**
     * @param mixed $rows
     * @return array<int,array{name:string,attachment_id:int,url:string}>
     */
    public static function sanitize_partners( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $aid  = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            $url  = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
            if ( $name === '' || $aid <= 0 ) {
                continue;
            }
            $out[] = [
                'name'          => $name,
                'attachment_id' => $aid,
                'url'           => $url,
            ];
        }
        return $out;
    }

    /**
     * @param mixed $rows
     * @return array<int,array{name:string,title:string,attachment_id:int,bio:string,url:string}>
     */
    public static function sanitize_mentors( $rows ) {
        if ( ! is_array( $rows ) ) {
            return [];
        }
        $out = [];
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $name  = isset( $row['name'] ) ? sanitize_text_field( (string) $row['name'] ) : '';
            $title = isset( $row['title'] ) ? sanitize_text_field( (string) $row['title'] ) : '';
            $aid   = isset( $row['attachment_id'] ) ? (int) $row['attachment_id'] : 0;
            $bio   = isset( $row['bio'] ) ? sanitize_textarea_field( (string) $row['bio'] ) : '';
            $url   = isset( $row['url'] ) ? esc_url_raw( (string) $row['url'] ) : '';
            if ( $name === '' || $aid <= 0 ) {
                continue;
            }
            $out[] = [
                'name'          => $name,
                'title'         => $title,
                'attachment_id' => $aid,
                'bio'           => $bio,
                'url'           => $url,
            ];
        }
        return $out;
    }

    /**
     * @param int $product_id
     * @return array<int,array{name:string,attachment_id:int,url:string}>
     */
    public static function get_partners( $product_id ) {
        $raw = get_post_meta( (int) $product_id, self::META_PARTNERS, true );
        return self::sanitize_partners( is_array( $raw ) ? $raw : [] );
    }

    /**
     * @param int $product_id
     * @return array<int,array{name:string,title:string,attachment_id:int,bio:string,url:string}>
     */
    public static function get_mentors( $product_id ) {
        $raw = get_post_meta( (int) $product_id, self::META_MENTORS, true );
        return self::sanitize_mentors( is_array( $raw ) ? $raw : [] );
    }

    /**
     * @param array $raw
     * @param array $existing
     * @param string $name_key
     * @return array
     */
    private static function preserve_existing_attachments( array $raw, array $existing, $name_key = 'name' ) {
        if ( empty( $existing ) ) {
            return $raw;
        }
        $by_name = [];
        foreach ( $existing as $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $aid = (int) ( $row['attachment_id'] ?? 0 );
            $key = strtolower( sanitize_text_field( (string) ( $row[ $name_key ] ?? '' ) ) );
            if ( $aid > 0 && $key !== '' ) {
                $by_name[ $key ] = $aid;
            }
        }
        foreach ( $raw as $idx => $row ) {
            if ( ! is_array( $row ) || (int) ( $row['attachment_id'] ?? 0 ) > 0 ) {
                continue;
            }
            $key = strtolower( sanitize_text_field( (string) ( $row[ $name_key ] ?? '' ) ) );
            if ( $key !== '' && isset( $by_name[ $key ] ) ) {
                $raw[ $idx ]['attachment_id'] = $by_name[ $key ];
            }
        }
        return $raw;
    }

    /**
     * @param int    $campaign_id
     * @param int    $user_id
     * @param array  $raw
     * @param string $files_key e.g. cw_supporting_partner_file
     * @return array
     */
    private static function merge_uploaded_files( $campaign_id, $user_id, array $raw, $files_key ) {
        if (
            empty( $_FILES[ $files_key ]['name'] )
            || ! is_array( $_FILES[ $files_key ]['name'] )
        ) {
            return $raw;
        }

        if ( ! function_exists( 'media_handle_upload' ) ) {
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }

        foreach ( array_keys( $_FILES[ $files_key ]['name'] ) as $idx ) {
            if ( empty( $_FILES[ $files_key ]['name'][ $idx ] ) ) {
                continue;
            }
            if ( (int) ( $_FILES[ $files_key ]['error'][ $idx ] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
                continue;
            }

            $single = [
                'name'     => $_FILES[ $files_key ]['name'][ $idx ],
                'type'     => $_FILES[ $files_key ]['type'][ $idx ],
                'tmp_name' => $_FILES[ $files_key ]['tmp_name'][ $idx ],
                'error'    => $_FILES[ $files_key ]['error'][ $idx ],
                'size'     => $_FILES[ $files_key ]['size'][ $idx ],
            ];
            $_FILES['cw_showcase_one'] = $single;
            $aid = media_handle_upload( 'cw_showcase_one', (int) $campaign_id );
            unset( $_FILES['cw_showcase_one'] );
            if ( is_wp_error( $aid ) ) {
                continue;
            }

            $aid = (int) $aid;
            wp_update_post(
                [
                    'ID'          => $aid,
                    'post_author' => (int) $user_id,
                ]
            );
            if ( class_exists( 'CW_Image_Optimizer' ) ) {
                CW_Image_Optimizer::optimize_attachment( $aid, 'campaign_thumb' );
            }

            if ( isset( $raw[ $idx ] ) && is_array( $raw[ $idx ] ) ) {
                $raw[ $idx ]['attachment_id'] = $aid;
            } elseif ( isset( $raw[ (string) $idx ] ) && is_array( $raw[ (string) $idx ] ) ) {
                $raw[ (string) $idx ]['attachment_id'] = $aid;
            }
        }

        return $raw;
    }

    /**
     * @param array $raw
     * @param int   $user_id
     * @param int   $campaign_id
     * @return array
     */
    private static function validate_attachment_rows( array $raw, $user_id, $campaign_id ) {
        foreach ( $raw as $idx => $row ) {
            if ( ! is_array( $row ) ) {
                continue;
            }
            $aid = (int) ( $row['attachment_id'] ?? 0 );
            if ( $aid <= 0 ) {
                continue;
            }
            $ok = class_exists( 'CW_Design_Submission' )
                ? CW_Design_Submission::user_can_use_attachment( $aid, $user_id, $campaign_id )
                : ( (int) get_post_field( 'post_author', $aid ) === (int) $user_id );
            if ( ! $ok ) {
                $raw[ $idx ]['attachment_id'] = 0;
            }
        }
        return $raw;
    }

    /**
     * @param int $campaign_id
     * @param int $user_id
     */
    public static function persist_from_wizard( $campaign_id, $user_id ) {
        $campaign_id = (int) $campaign_id;
        $user_id     = (int) $user_id;
        if ( $campaign_id <= 0 ) {
            return;
        }

        $existing_partners = self::get_partners( $campaign_id );
        $existing_mentors  = self::get_mentors( $campaign_id );

        $raw_partners = isset( $_POST['cw_supporting_partners'] ) && is_array( $_POST['cw_supporting_partners'] )
            ? wp_unslash( $_POST['cw_supporting_partners'] )
            : [];
        $raw_mentors = isset( $_POST['cw_mentors'] ) && is_array( $_POST['cw_mentors'] )
            ? wp_unslash( $_POST['cw_mentors'] )
            : [];

        $raw_partners = self::preserve_existing_attachments( $raw_partners, $existing_partners );
        $raw_mentors  = self::preserve_existing_attachments( $raw_mentors, $existing_mentors );

        $raw_partners = self::merge_uploaded_files( $campaign_id, $user_id, $raw_partners, 'cw_supporting_partner_file' );
        $raw_mentors  = self::merge_uploaded_files( $campaign_id, $user_id, $raw_mentors, 'cw_mentor_photo_file' );

        $raw_partners = self::validate_attachment_rows( $raw_partners, $user_id, $campaign_id );
        $raw_mentors  = self::validate_attachment_rows( $raw_mentors, $user_id, $campaign_id );

        update_post_meta( $campaign_id, self::META_PARTNERS, self::sanitize_partners( $raw_partners ) );
        update_post_meta( $campaign_id, self::META_MENTORS, self::sanitize_mentors( $raw_mentors ) );
    }
}
