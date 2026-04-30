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
 *   location.feature→ location  (with fallback to .address / .name)
 *   description     → description
 *   updated|lastModified → last_modified
 *   cancelled       → drop the row entirely (treat like UID-disappeared)
 */
final class SpondParser {

    /**
     * Parse the JSON event list returned by `SpondClient::fetchEvents`.
     *
     * @param list<array<string,mixed>> $events
     * @return list<array{uid:string,summary:string,dtstart:string,dtend:string,location:string,description:string,last_modified:string}>
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
                'location'      => self::extractLocation( $event['location'] ?? null ),
                'description'   => trim( (string) ( $event['description'] ?? '' ) ),
                'last_modified' => self::iso8601ToMysqlUtc( (string) ( $event['updated'] ?? $event['lastModified'] ?? '' ) ),
            ];
        }
        return $out;
    }

    /**
     * Spond returns `location` as an object — `feature` is the
     * human-readable line a coach typed in. Fall back to `address` or
     * `name` so a location field that's been shaped differently by
     * upstream still ends up in the activity row.
     *
     * @param mixed $location
     */
    private static function extractLocation( $location ): string {
        if ( is_string( $location ) ) return trim( $location );
        if ( ! is_array( $location ) ) return '';
        foreach ( [ 'feature', 'address', 'name', 'displayName' ] as $key ) {
            $v = (string) ( $location[ $key ] ?? '' );
            $v = trim( $v );
            if ( $v !== '' ) return $v;
        }
        return '';
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
