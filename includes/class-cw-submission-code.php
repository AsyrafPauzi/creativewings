<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Parses submission codes: CCC + MM + SSS + SEQ(5 or 6 digits).
 */
class CW_Submission_Code {

    /**
     * @return array{valid:bool, campaign?:string, month?:string, school?:string, seq?:string, normalized?:string, error?:string}
     */
    public static function parse( $code ) {
        $code = preg_replace( '/\s+/', '', (string) $code );

        if ( ! preg_match( '/^(\d{3})(\d{2})(\d{3})(\d{5,6})$/', $code, $m ) ) {
            return [
                'valid' => false,
                'error' => __( 'Invalid code format. Expected: 3 campaign + 2 month + 3 school + 5–6 sequence digits.', 'creativewings-core' ),
            ];
        }

        $seq = $m[4];
        if ( strlen( $seq ) === 5 ) {
            $n = (int) $seq;
            if ( $n < 1 || $n > 99999 ) {
                return [ 'valid' => false, 'error' => __( 'Sequence must be 00001–99999 (5 digits) or 000001+ (6 digits).', 'creativewings-core' ) ];
            }
        } elseif ( strlen( $seq ) === 6 ) {
            $n = (int) $seq;
            if ( $n < 1 ) {
                return [ 'valid' => false, 'error' => __( 'Invalid 6-digit sequence.', 'creativewings-core' ) ];
            }
        }

        $normalized = $m[1] . $m[2] . $m[3] . $seq;

        return [
            'valid'      => true,
            'campaign'   => $m[1],
            'month'      => $m[2],
            'school'     => $m[3],
            'seq'        => $seq,
            'normalized' => $normalized,
        ];
    }

    public static function matches_campaign_serial( $parsed, $campaign_id ) {
        $serial = get_post_meta( $campaign_id, 'cw_campaign_serial', true );
        if ( ! $serial ) {
            $serial = str_pad( (string) $campaign_id, 3, '0', STR_PAD_LEFT );
        }
        $serial = str_pad( preg_replace( '/\D/', '', (string) $serial ), 3, '0', STR_PAD_LEFT );
        return isset( $parsed['campaign'] ) && $parsed['campaign'] === substr( $serial, -3 );
    }
}
