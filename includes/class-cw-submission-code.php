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
        $serial = self::campaign_serial( $campaign_id );
        return isset( $parsed['campaign'] ) && $parsed['campaign'] === $serial;
    }

    public static function campaign_serial( $campaign_id ) {
        $serial = get_post_meta( (int) $campaign_id, 'cw_campaign_serial', true );
        if ( ! $serial ) {
            $serial = str_pad( (string) $campaign_id, 3, '0', STR_PAD_LEFT );
        }
        return str_pad( preg_replace( '/\D/', '', (string) $serial ), 3, '0', STR_PAD_LEFT );
    }

    public static function pad_school( $school ) {
        return str_pad( preg_replace( '/\D/', '', (string) $school ), 3, '0', STR_PAD_LEFT );
    }

    public static function pad_month( $month ) {
        return str_pad( preg_replace( '/\D/', '', (string) $month ), 2, '0', STR_PAD_LEFT );
    }

    /**
     * @param int $seq Sequence number (1+).
     */
    public static function format_sequence( $seq ) {
        $seq = max( 1, (int) $seq );
        return str_pad( (string) $seq, $seq > 99999 ? 6 : 5, '0', STR_PAD_LEFT );
    }

    /**
     * Build a submission code: CCC + MM + SSS + SEQ.
     */
    public static function build( $campaign_id, $month, $school, $seq ) {
        return self::campaign_serial( $campaign_id )
            . self::pad_month( $month )
            . self::pad_school( $school )
            . self::format_sequence( $seq );
    }
}
