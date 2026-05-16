<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Campaign custom fields shared by public registration and PIC school upload.
 */
class CW_Campaign_Fields {

    public static function get_custom_fields( $campaign_id ) {
        $fields = get_post_meta( (int) $campaign_id, 'cw_custom_fields', true );
        if ( ! is_array( $fields ) ) {
            return [];
        }
        return array_values( $fields );
    }

    /**
     * Upload fields shown on PIC school link (media + file types from campaign builder).
     *
     * @return array<int|string, array> index => field config
     */
    public static function get_pic_upload_fields( $campaign_id ) {
        $out = [];
        foreach ( self::get_custom_fields( $campaign_id ) as $idx => $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $type = strtolower( trim( (string) ( $f['type'] ?? 'text' ) ) );
            if ( in_array( $type, [ 'media', 'file' ], true ) ) {
                $out[ (int) $idx ] = $f;
            }
        }
        return $out;
    }

    public static function campaign_has_configured_pic_uploads( $campaign_id ) {
        foreach ( self::get_custom_fields( $campaign_id ) as $f ) {
            if ( ! is_array( $f ) ) {
                continue;
            }
            $type = strtolower( trim( (string) ( $f['type'] ?? '' ) ) );
            if ( in_array( $type, [ 'media', 'file' ], true ) ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Which PIC field index supplies artwork_attachment_id (certificate / claim ready).
     *
     * @return int|string|null
     */
    public static function get_primary_artwork_field_key( $campaign_id ) {
        $uploads = self::get_pic_upload_fields( $campaign_id );
        foreach ( $uploads as $idx => $f ) {
            $label = strtolower( trim( (string) ( $f['label'] ?? '' ) ) );
            if ( $label === 'artwork' || str_contains( $label, 'artwork' ) ) {
                return $idx;
            }
        }
        foreach ( $uploads as $idx => $f ) {
            if ( ( $f['type'] ?? '' ) === 'media' ) {
                return $idx;
            }
        }
        foreach ( array_keys( $uploads ) as $idx ) {
            return $idx;
        }
        return null;
    }

    public static function decode_staged_field_data( $row ) {
        if ( ! is_array( $row ) ) {
            return [];
        }
        if ( ! empty( $row['field_data'] ) ) {
            $decoded = json_decode( $row['field_data'], true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }
        if ( ! empty( $row['artwork_attachment_id'] ) ) {
            $key = self::get_primary_artwork_field_key( (int) ( $row['campaign_id'] ?? 0 ) );
            return [
                [
                    'index'          => $key,
                    'label'          => __( 'Artwork', 'creativewings-core' ),
                    'type'           => 'media',
                    'attachment_id'  => (int) $row['artwork_attachment_id'],
                    'value'          => wp_get_attachment_url( (int) $row['artwork_attachment_id'] ),
                ],
            ];
        }
        return [];
    }

    public static function get_staged_field_attachment( $row, $field_key ) {
        foreach ( self::decode_staged_field_data( $row ) as $item ) {
            if ( (string) ( $item['index'] ?? '' ) === (string) $field_key && ! empty( $item['attachment_id'] ) ) {
                return (int) $item['attachment_id'];
            }
        }
        return 0;
    }

    /**
     * @return array<int, array{label:string,type:string,value:string,attachment_id:int}>
     */
    public static function build_participant_details_from_staged( $row ) {
        $details = [
            [ 'label' => 'Name', 'value' => $row['student_name'] ?? '' ],
            [ 'label' => __( 'Submission code', 'creativewings-core' ), 'value' => $row['submission_code'] ?? '' ],
        ];
        foreach ( self::decode_staged_field_data( $row ) as $item ) {
            if ( empty( $item['label'] ) ) {
                continue;
            }
            $val = $item['value'] ?? '';
            if ( ! $val && ! empty( $item['attachment_id'] ) ) {
                $val = wp_get_attachment_url( (int) $item['attachment_id'] );
            }
            if ( $val ) {
                $details[] = [
                    'label' => $item['label'],
                    'value' => $val,
                ];
            }
        }
        return $details;
    }

    public static function get_primary_artwork_attachment_id( $row, $campaign_id ) {
        $key = self::get_primary_artwork_field_key( $campaign_id );
        $aid = ( null !== $key ) ? self::get_staged_field_attachment( $row, $key ) : 0;
        if ( ! $aid && ! empty( $row['artwork_attachment_id'] ) ) {
            $aid = (int) $row['artwork_attachment_id'];
        }
        return $aid;
    }

    public static function staged_has_required_uploads( $row, $campaign_id ) {
        return self::get_primary_artwork_attachment_id( $row, $campaign_id ) > 0;
    }
}
