<?php
namespace TT\Modules\Spond;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SpondParser (#0062) — normaliser for Spond's JSON event payloads.
 *
 * The original (#0031) parsed iCal VEVENT blocks; that contract turned
 * out not to exist (Spond never published iCal). The replacement runs
 * against the JSON shape `/core/v1/sponds/?groupId=…` actually returns,
 * mapping the relevant fields onto the same array shape `SpondSync`
 * already consumes — so SpondSync didn't need to change.
 *
 * Field map (Spond → normalized):
 *   id              → uid
 *   heading         → summary
 *   startTimestamp  → dtstart   (ISO 8601 → MySQL UTC)
 *   endTimestamp    → dtend
 *   meetupTimestamp | (startTimestamp − meetupPrior minutes) → meetup
 *   location.feature + location.address → location  (one line:
 *                    "Venue | Address"; either alone when only one present)
 *   description     → description
 *   updated|lastModified → last_modified
 *   cancelled       → drop the row entirely (treat like UID-disappeared)
 */
final class SpondParser {

    /**
     * Parse the JSON event list returned by `SpondClient::fetchEvents`.
     *
     * @param list<array<string,mixed>> $events
     * @return list<array{uid:string,summary:string,dtstart:string,dtend:string,meetup:string,location:string,description:string,last_modified:string}>
     */
    public static function parse( array $events ): array {
        $out = [];
        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) continue;
            if ( ! empty( $event['cancelled'] ) ) continue;

            $uid = (string) ( $event['id'] ?? '' );
            if ( $uid === '' ) continue;

            $out[] = [
                'uid'           => $uid,
                'summary'       => trim( (string) ( $event['heading'] ?? '' ) ),
                'dtstart'       => self::iso8601ToMysqlUtc( (string) ( $event['startTimestamp'] ?? '' ) ),
                'dtend'         => self::iso8601ToMysqlUtc( (string) ( $event['endTimestamp']   ?? '' ) ),
                'meetup'        => self::extractMeetup( $event ),
                'location'      => self::extractLocation( $event['location'] ?? null ),
                'description'   => trim( (string) ( $event['description'] ?? '' ) ),
                'last_modified' => self::iso8601ToMysqlUtc( (string) ( $event['updated'] ?? $event['lastModified'] ?? '' ) ),
            ];
        }
        return $out;
    }

    /**
     * Resolve the meet-up / "be present by" time as an absolute MySQL UTC
     * datetime. Spond expresses this either as an explicit
     * `meetupTimestamp` or — more commonly — as `meetupPrior`, the number
     * of minutes before `startTimestamp` participants should arrive.
     * Returns an empty string when neither is present or parseable.
     *
     * @param array<string,mixed> $event
     */
    private static function extractMeetup( array $event ): string {
        $absolute = self::iso8601ToMysqlUtc( (string) ( $event['meetupTimestamp'] ?? '' ) );
        if ( $absolute !== '' ) return $absolute;

        $prior = $event['meetupPrior'] ?? null;
        $start = (string) ( $event['startTimestamp'] ?? '' );
        if ( ! is_numeric( $prior ) || (int) $prior <= 0 || trim( $start ) === '' ) {
            return '';
        }
        try {
            $dt = new \DateTimeImmutable( trim( $start ) );
            $dt = $dt->setTimezone( new \DateTimeZone( 'UTC' ) )
                     ->sub( new \DateInterval( 'PT' . (int) $prior . 'M' ) );
            return $dt->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            return '';
        }
    }

    /** Separator between the venue name and the street address. */
    private const LOCATION_SEPARATOR = ' | ';

    /**
     * Spond returns `location` as an object with a `feature` (the venue
     * name a coach typed in, falling back to `name` / `displayName`) and
     * a separate `address` (the full street address). Capture BOTH on one
     * line — `Venue | Address` — so the activity row keeps the address,
     * not just the label. When only one is present, return that single
     * value; when the address already contains (or equals) the label,
     * don't double it.
     *
     * @param mixed $location
     */
    private static function extractLocation( $location ): string {
        if ( is_string( $location ) ) return trim( $location );
        if ( ! is_array( $location ) ) return '';

        $label = '';
        foreach ( [ 'feature', 'name', 'displayName' ] as $key ) {
            $v = trim( (string) ( $location[ $key ] ?? '' ) );
            if ( $v !== '' ) { $label = $v; break; }
        }
        $address = trim( (string) ( $location['address'] ?? '' ) );

        if ( $label === '' )   return $address;
        if ( $address === '' ) return $label;

        // Avoid "name | name" when the address already carries the label
        // (or vice-versa) — keep the more complete single value.
        if ( stripos( $address, $label ) !== false ) return $address;
        if ( stripos( $label, $address ) !== false ) return $label;

        return $label . self::LOCATION_SEPARATOR . $address;
    }

    /**
     * Spond's timestamps are ISO 8601 with millisecond precision and
     * a `Z` suffix (e.g. `"2026-05-13T18:30:00.000Z"`). Map onto the
     * MySQL `Y-m-d H:i:s` shape `tt_activities` expects, in UTC.
     * Returns an empty string for unparseable input.
     */
    private static function iso8601ToMysqlUtc( string $iso ): string {
        $iso = trim( $iso );
        if ( $iso === '' ) return '';
        try {
            $dt = new \DateTimeImmutable( $iso );
            return $dt->setTimezone( new \DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        } catch ( \Exception $e ) {
            return '';
        }
    }
}
