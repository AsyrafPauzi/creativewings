<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Campaign milestone dates (submission start / deadline / review / final).
 *
 * Stored as 'Y-m-d' (legacy, date only) or 'Y-m-d H:i' (date + time), both in
 * the site timezone. A date-only deadline lasts until the end of that day.
 */
class CW_Campaign_Dates {

    const PATTERN = '/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{1,2}):(\d{2}))?/';

    public static function date_part( $value ) {
        return preg_match( self::PATTERN, trim( (string) $value ), $m ) ? $m[1] : '';
    }

    public static function time_part( $value ) {
        if ( ! preg_match( self::PATTERN, trim( (string) $value ), $m ) || ! isset( $m[2] ) ) {
            return '';
        }
        $h = (int) $m[2];
        $i = (int) $m[3];
        return ( $h < 24 && $i < 60 ) ? sprintf( '%02d:%02d', $h, $i ) : '';
    }

    public static function has_time( $value ) {
        return '' !== self::time_part( $value );
    }

    /**
     * Unix timestamp for a stored value. Date-only values resolve to the start
     * of the day, or 23:59:59 when $end_of_day is set (deadlines).
     */
    public static function timestamp( $value, $end_of_day = false ) {
        $value = trim( (string) $value );
        if ( '' === $value ) {
            return false;
        }
        $date = self::date_part( $value );
        if ( '' === $date ) {
            $ts = strtotime( $value );
            return $ts ?: false;
        }
        $time = self::time_part( $value );
        $time = '' !== $time ? $time . ':00' : ( $end_of_day ? '23:59:59' : '00:00:00' );
        $dt   = DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $date . ' ' . $time, wp_timezone() );
        return $dt ? $dt->getTimestamp() : false;
    }

    public static function is_past( $value, $end_of_day = false ) {
        $ts = self::timestamp( $value, $end_of_day );
        return $ts && time() > $ts;
    }

    public static function is_future( $value ) {
        $ts = self::timestamp( $value );
        return $ts && time() < $ts;
    }

    /**
     * Calendar days from today (site timezone) to the value's date. 0 = today.
     */
    public static function days_until( $value ) {
        $date = self::date_part( $value );
        if ( '' === $date ) {
            $ts = self::timestamp( $value );
            if ( ! $ts ) {
                return null;
            }
            $date = wp_date( 'Y-m-d', $ts );
        }
        $tz     = wp_timezone();
        $target = DateTimeImmutable::createFromFormat( '!Y-m-d', $date, $tz );
        if ( ! $target ) {
            return null;
        }
        $today = DateTimeImmutable::createFromFormat( '!Y-m-d', wp_date( 'Y-m-d' ), $tz );
        $diff  = $today->diff( $target );
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /**
     * "31 Oct 2026" for date-only values, "31 Oct 2026, 5:30 PM" when a time is set.
     */
    public static function format( $value, $date_format = 'j M Y', $time_format = 'g:i A', $separator = ', ' ) {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return '';
        }
        $out = wp_date( $date_format, $ts );
        if ( self::has_time( $value ) ) {
            $out .= $separator . wp_date( $time_format, $ts );
        }
        return $out;
    }

    /**
     * Time label only when one was set; '' for date-only values.
     */
    public static function format_time( $value, $time_format = 'g:i A' ) {
        return self::has_time( $value ) ? wp_date( $time_format, self::timestamp( $value ) ) : '';
    }

    /**
     * ISO 8601 for schema.org. Google reads a 00:00 time as a real midnight start,
     * so date-only and midnight values stay as a plain date.
     */
    public static function schema_date( $value ) {
        $ts = self::timestamp( $value );
        if ( ! $ts ) {
            return '';
        }
        $time = self::time_part( $value );
        return ( '' === $time || '00:00' === $time ) ? wp_date( 'Y-m-d', $ts ) : wp_date( 'c', $ts );
    }

    /**
     * Combine the form's date + optional time inputs into the stored value.
     */
    public static function normalize( $date, $time = '' ) {
        $date = sanitize_text_field( (string) $date );
        if ( ! preg_match( self::PATTERN, $date, $m ) ) {
            return '';
        }
        list( $y, $mo, $d ) = array_map( 'intval', explode( '-', $m[1] ) );
        if ( ! checkdate( $mo, $d, $y ) ) {
            return '';
        }
        $t = self::time_part( '0000-00-00 ' . sanitize_text_field( (string) $time ) );
        if ( '' === $t ) {
            $t = self::time_part( $date );
        }
        return '' !== $t ? $m[1] . ' ' . $t : $m[1];
    }

    /**
     * Deadlines saved without a time close at 11:59 PM.
     */
    public static function end_of_day( $value ) {
        $value = trim( (string) $value );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value . ' 23:59' : $value;
    }

    public static function from_post( $key ) {
        return self::normalize(
            wp_unslash( $_POST[ $key ] ?? '' ),
            wp_unslash( $_POST[ $key . '_time' ] ?? '' )
        );
    }
}
