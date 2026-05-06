<?php
namespace TT\Modules\Export\Format\Renderers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\Domain\ExportResult;
use TT\Modules\Export\Format\FormatRendererInterface;

/**
 * IcsRenderer (#0063) — minimal RFC 5545 iCal output, pure PHP.
 *
 * No external dependency (no Sabre\VObject). The format is small
 * enough to handcode safely: PRODID + VERSION + N × VEVENT, each with
 * UID / DTSTAMP / DTSTART / DTEND / SUMMARY / LOCATION / DESCRIPTION.
 * Lines are CRLF-terminated and folded at 75 octets per the spec.
 *
 * Payload shape:
 *   [
 *     'calendar_name' => 'My team activities',
 *     'events' => [
 *       [
 *         'uid'         => 'tt-activity-42@academy.tld',
 *         'starts_at'   => '2026-05-12 18:00:00',  // local server time
 *         'ends_at'     => '2026-05-12 19:30:00',
 *         'summary'     => 'Training U12',
 *         'location'    => 'Field 3',
 *         'description' => 'Possession + 4v4 endgame',
 *       ],
 *       …
 *     ],
 *   ]
 *
 * Times are emitted as UTC (DTSTART:20260512T180000Z) — every iCal
 * client converts to the user's local timezone on display. The
 * exporter is responsible for the local→UTC conversion.
 */
final class IcsRenderer implements FormatRendererInterface {

    public function format(): string { return 'ics'; }

    public function render( ExportRequest $request, $payload ): ExportResult {
        $events = isset( $payload['events'] ) && is_array( $payload['events'] ) ? $payload['events'] : [];
        $cal_name = isset( $payload['calendar_name'] ) ? (string) $payload['calendar_name'] : 'TalentTrack';

        $lines = [];
        $lines[] = 'BEGIN:VCALENDAR';
        $lines[] = 'VERSION:2.0';
        $lines[] = 'PRODID:-//TalentTrack//TT ' . ( defined( 'TT_VERSION' ) ? TT_VERSION : 'dev' ) . '//EN';
        $lines[] = 'CALSCALE:GREGORIAN';
        $lines[] = 'METHOD:PUBLISH';
        $lines[] = 'X-WR-CALNAME:' . self::escapeText( $cal_name );

        foreach ( $events as $event ) {
            if ( ! is_array( $event ) ) continue;
            $uid     = isset( $event['uid'] ) ? (string) $event['uid'] : '';
            $summary = isset( $event['summary'] ) ? (string) $event['summary'] : '';
            if ( $uid === '' ) continue;

            $all_day = ! empty( $event['all_day'] );
            if ( $all_day ) {
                $start_date = self::toDateOnly( $event['starts_at'] ?? null );
                $end_date   = self::toDateOnly( $event['ends_at']   ?? $event['starts_at'] ?? null, true );
                if ( $start_date === '' || $end_date === '' ) continue;
                $dtstart_line = 'DTSTART;VALUE=DATE:' . $start_date;
                $dtend_line   = 'DTEND;VALUE=DATE:' . $end_date;
            } else {
                $starts = self::toUtcZ( $event['starts_at'] ?? null );
                $ends   = self::toUtcZ( $event['ends_at']   ?? null );
                if ( $starts === '' || $ends === '' ) continue;
                $dtstart_line = 'DTSTART:' . $starts;
                $dtend_line   = 'DTEND:' . $ends;
            }

            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $uid;
            $lines[] = 'DTSTAMP:' . gmdate( 'Ymd\THis\Z' );
            $lines[] = $dtstart_line;
            $lines[] = $dtend_line;
            $lines[] = 'SUMMARY:' . self::escapeText( $summary );
            if ( ! empty( $event['location'] ) ) {
                $lines[] = 'LOCATION:' . self::escapeText( (string) $event['location'] );
            }
            if ( ! empty( $event['description'] ) ) {
                $lines[] = 'DESCRIPTION:' . self::escapeText( (string) $event['description'] );
            }
            $lines[] = 'END:VEVENT';
        }

        $lines[] = 'END:VCALENDAR';
        $folded = array_map( [ __CLASS__, 'foldLine' ], $lines );

        $bytes    = implode( "\r\n", $folded ) . "\r\n";
        $filename = $request->exporterKey . '-' . gmdate( 'Y-m-d' ) . '.ics';
        $note     = sprintf( '%d event%s', count( $events ), count( $events ) === 1 ? '' : 's' );
        return ExportResult::fromString( $bytes, 'text/calendar; charset=utf-8', $filename, $note );
    }

    /**
     * Escape iCal text per RFC 5545 § 3.3.11: backslash, semicolon,
     * comma, and newline get escaped.
     */
    private static function escapeText( string $value ): string {
        return strtr( $value, [
            "\\" => "\\\\",
            ';'  => '\\;',
            ','  => '\\,',
            "\n" => '\\n',
            "\r" => '',
        ] );
    }

    /**
     * Fold a content line at 75 octets per RFC 5545 § 3.1 (continuation
     * lines start with a single space).
     */
    private static function foldLine( string $line ): string {
        if ( strlen( $line ) <= 75 ) return $line;
        $out = '';
        $offset = 0;
        $len = strlen( $line );
        $first = true;
        while ( $offset < $len ) {
            $chunk_len = $first ? 75 : 74;  // continuation lines start with a space, leaving 74 for content
            $chunk = substr( $line, $offset, $chunk_len );
            $out .= ( $first ? '' : "\r\n " ) . $chunk;
            $offset += $chunk_len;
            $first = false;
        }
        return $out;
    }

    /**
     * Local-time string (Y-m-d H:i:s) → UTC `YYYYMMDDTHHMMSSZ`.
     * Falls back to "now" on parse failure rather than emitting an
     * invalid event — the iCal spec requires DTSTART/DTEND.
     */
    private static function toUtcZ( $value ): string {
        if ( ! is_string( $value ) || $value === '' ) return '';
        try {
            $dt = new \DateTime( $value, wp_timezone() );
            $dt->setTimezone( new \DateTimeZone( 'UTC' ) );
            return $dt->format( 'Ymd\THis\Z' );
        } catch ( \Throwable $e ) {
            return '';
        }
    }

    /**
     * `Y-m-d` (or any parseable date) → `YYYYMMDD`. RFC 5545 all-day
     * VEVENTs use DATE values without a Z suffix; DTEND is exclusive
     * and gets bumped one day for single-day events when `$bumpEnd`.
     */
    private static function toDateOnly( $value, bool $bumpEnd = false ): string {
        if ( ! is_string( $value ) || $value === '' ) return '';
        try {
            $dt = new \DateTime( $value, wp_timezone() );
            if ( $bumpEnd ) $dt->modify( '+1 day' );
            return $dt->format( 'Ymd' );
        } catch ( \Throwable $e ) {
            return '';
        }
    }
}
