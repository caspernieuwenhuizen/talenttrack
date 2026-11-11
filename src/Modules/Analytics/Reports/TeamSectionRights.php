<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Journey\PlayerEventsRepository;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;

/**
 * TeamSectionRights (#4961) — a team monthly report section composed for one
 * reader, cut to what another reader may read now, by the report's own
 * rules (`TeamReportContext`):
 *
 * - the attendance right: the attendance figures go as
 *   `TeamMonthlyReport::withoutAttendance()` takes them out;
 * - what changed: the journey entries at the reader's levels that pass each
 *   entry's own record right, and the injury count only for a reader of the
 *   squad's injuries.
 */
final class TeamSectionRights {

    /**
     * @param array<string,mixed> $data the section's composer payload
     * @return array<string,mixed>|null null when the reader may not read the section at all
     */
    public static function cut( string $section, array $data, int $reader, int $team_id, string $from, string $to ): ?array {
        $ctx = new TeamReportContext( $team_id, $from, $to, $reader );

        if ( ! $ctx->readsAttendance() ) {
            $report = TeamMonthlyReport::withoutAttendance( [ 'blocks' => [ $section ], 'data' => [ $section => $data ], 'from' => $from, 'to' => $to ] );
            if ( ! is_array( $report['data'][ $section ] ?? null ) ) return null;
            $data = $report['data'][ $section ];
        }

        if ( $section === TeamMonthlyReportBlock::CHANGES ) {
            $visible = self::readableChanges( $ctx );
            self::$last_read[ $team_id . '|' . $from . '|' . $to . '|' . $reader ] = $visible;

            $data['events'] = array_values( array_filter(
                is_array( $data['events'] ?? null ) ? $data['events'] : [],
                static fn( $i ): bool => is_array( $i ) && isset( $visible[ self::eventKey( (int) ( $i['player_id'] ?? 0 ), (string) ( $i['event_type'] ?? '' ), (string) ( $i['date'] ?? '' ), (string) ( $i['summary'] ?? '' ) ) ] )
            ) );
            if ( ! $ctx->readsInjuries() ) unset( $data['open_injuries'] );
        }
        return $data;
    }

    /**
     * Of the "what changed" entries as this reader reads them (after `cut()`),
     * how many per player are medical or safeguarding entries. The frozen
     * entries carry no visibility, so it comes from the entries `cut()` read
     * back, without reading them again when `cut()` has just run.
     *
     * @param array<string,mixed> $data the section after `cut()`
     * @return array<int,int> player id => sensitive entries shown
     */
    public static function sensitiveChanges( array $data, int $reader, int $team_id, string $from, string $to ): array {
        $memo = $team_id . '|' . $from . '|' . $to . '|' . $reader;
        $visible = self::$last_read[ $memo ] ?? self::readableChanges( new TeamReportContext( $team_id, $from, $to, $reader ) );
        unset( self::$last_read[ $memo ] );
        $counts  = [];
        foreach ( is_array( $data['events'] ?? null ) ? $data['events'] : [] as $i ) {
            if ( ! is_array( $i ) ) continue;
            $key = self::eventKey( (int) ( $i['player_id'] ?? 0 ), (string) ( $i['event_type'] ?? '' ), (string) ( $i['date'] ?? '' ), (string) ( $i['summary'] ?? '' ) );
            if ( ! in_array( $visible[ $key ] ?? '', [ 'medical', 'safeguarding' ], true ) ) continue;
            $pid = (int) ( $i['player_id'] ?? 0 );
            $counts[ $pid ] = ( $counts[ $pid ] ?? 0 ) + 1;
        }
        return $counts;
    }

    /**
     * The read `cut()` made, left for the `sensitiveChanges()` call that
     * follows it and taken by that call, so the entries are read once.
     *
     * @var array<string, array<string,string>>
     */
    private static array $last_read = [];

    /**
     * The team's journey entries in the window this reader may read, by
     * entry key, with each entry's visibility.
     *
     * @return array<string,string>
     */
    private static function readableChanges( TeamReportContext $ctx ): array {
        $levels = $ctx->journeyVisibilities();
        $events = $levels === [] ? [] : $ctx->readableEntries( ( new PlayerEventsRepository() )->forTeamBetween(
            $ctx->team_id, $ctx->from, $ctx->to, TeamMonthlyReport::CHANGE_EVENT_TYPES, $levels
        ) );
        $out = [];
        foreach ( $events as $e ) {
            $out[ self::eventKey( (int) ( $e->player_id ?? 0 ), (string) ( $e->event_type ?? '' ), substr( (string) ( $e->event_date ?? '' ), 0, 10 ), (string) ( $e->summary ?? '' ) ) ] = (string) ( $e->visibility ?? '' );
        }
        return $out;
    }

    /** An entry as "what changed" lists it: who, what, when and what it says. */
    private static function eventKey( int $player_id, string $type, string $date, string $summary ): string {
        return $player_id . '|' . $type . '|' . $date . '|' . md5( $summary );
    }
}
