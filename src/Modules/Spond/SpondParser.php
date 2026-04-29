<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondParser (#0031) — minimal in-house iCal parser, VEVENT-only.
 *
 * Spond's feeds are well-formed standard iCalendar; we only need
 * VEVENT block parsing. Sabre\VObject would handle the long tail of
 * RFC 5545 quirks but isn't bundled with WordPress core, so this stays
 * dependency-free. If a feed appears that this parser can't read, the
 * sync surfaces a per-event skip rather than failing the whole feed.
 */
final class SpondParser {

    /**
     * Parse a raw iCal body into a list of normalised events.
     *
     * @return list<array{uid:string,summary:string,dtstart:string,dtend:string,location:string,description:string,last_modified:string}>
     */
    public static function parse( string $body ): array {
        // Unfold continuation lines per RFC 5545 §3.1: any line beginning
        // with whitespace is a continuation of the previous line.
        $body = preg_replace( "/\r\n[ \t]/", '', $body );
        $body = (string) $body;

        $events = [];
        if ( ! preg_match_all( '/BEGIN:VEVENT(.*?)END:VEVENT/s', $body, $matches ) ) {
            return $events;
        }

        foreach ( $matches[1] as $block ) {
            $event = self::parseEventBlock( (string) $block );
            if ( $event !== null && $event['uid'] !== '' ) {
                $events[] = $event;
            }
        }
        return $events;
    }

    /**
     * @return array{uid:string,summary:string,dtstart:string,dtend:string,location:string,description:string,last_modified:string}|null
     */
    private static function parseEventBlock( string $block ): ?array {
        $event = [
            'uid'           => '',
            'summary'       => '',
            'dtstart'       => '',
            'dtend'         => '',
            'location'      => '',
            'description'   => '',
            'last_modified' => '',
        ];

        $lines = preg_split( "/\r?\n/", trim( $block ) );
        foreach ( $lines as $line ) {
            $line = (string) $line;
            if ( $line === '' ) continue;

            // Split on the first colon, respecting that property params
            // (DTSTART;TZID=Europe/Amsterdam:...) sit before the colon.
            $colon = strpos( $line, ':' );
            if ( $colon === false ) continue;

            $key_full = substr( $line, 0, $colon );
            $value    = substr( $line, $colon + 1 );

            // Property name is everything before the first ';'.
            $semi = strpos( $key_full, ';' );
            $key  = strtoupper( $semi === false ? $key_full : substr( $key_full, 0, $semi ) );

            switch ( $key ) {
                case 'UID':           $event['uid']           = self::unescape( $value ); break;
                case 'SUMMARY':       $event['summary']       = self::unescape( $value ); break;
                case 'DTSTART':       $event['dtstart']       = self::parseDateTime( $value ); break;
                case 'DTEND':         $event['dtend']         = self::parseDateTime( $value ); break;
                case 'LOCATION':      $event['location']      = self::unescape( $value ); break;
                case 'DESCRIPTION':   $event['description']   = self::unescape( $value ); break;
                case 'LAST-MODIFIED': $event['last_modified'] = self::parseDateTime( $value ); break;
            }
        }

        return $event;
    }

    /** Decode iCal text-value escapes (\n \, \; \\). */
    private static function unescape( string $value ): string {
        $value = str_replace( [ '\\n', '\\N', '\\,', '\\;', '\\\\' ], [ "\n", "\n", ',', ';', '\\' ], $value );
        return trim( $value );
    }

    /**
     * Convert an iCal datetime to the MySQL `Y-m-d H:i:s` shape, in UTC.
     * Accepts:
     *   - 20260513T080000Z       (UTC)
     *   - 20260513T080000        (floating, treat as UTC)
     *   - 20260513               (date-only — treated as midnight UTC)
     */
    private static function parseDateTime( string $value ): string {
        $value = trim( $value );
        if ( $value === '' ) return '';

        $is_utc = ( substr( $value, -1 ) === 'Z' );
        $clean  = rtrim( $value, 'Z' );

        if ( strlen( $clean ) === 8 ) {
            $clean .= 'T000000';
        }
        if ( strlen( $clean ) !== 15 || $clean[8] !== 'T' ) {
            return '';
        }

        $year   = substr( $clean, 0, 4 );
        $month  = substr( $clean, 4, 2 );
        $day    = substr( $clean, 6, 2 );
        $hour   = substr( $clean, 9, 2 );
        $minute = substr( $clean, 11, 2 );
        $second = substr( $clean, 13, 2 );

        $iso = sprintf( '%s-%s-%s %s:%s:%s', $year, $month, $day, $hour, $minute, $second );

        if ( ! $is_utc ) {
            // Floating times are spec-violating but common; treat as
            // site-local and convert to UTC for storage.
            $tz = wp_timezone();
            try {
                $dt = new \DateTimeImmutable( $iso, $tz );
                return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            } catch ( \Exception $e ) {
                return '';
            }
        }
        return $iso;
    }
}
