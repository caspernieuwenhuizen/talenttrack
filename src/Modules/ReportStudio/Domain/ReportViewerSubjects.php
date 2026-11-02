<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * ReportViewerSubjects (#4591) — the subjects a reader of a published report
 * may switch between, by the report's binding:
 *
 *   - fixed: none, the report is about its stored subject;
 *   - chosen when opened: the teams whose reports the reader reads, or the
 *     players on them whose report the reader reads;
 *   - follows the viewer: the reader's own teams (a player follows their
 *     own record, so there is nothing to choose).
 *
 * The list only offers; `GET /reports/{id}/data` checks the subject again on
 * every call. Teams and players are each read in one statement.
 *
 * @phpstan-import-type Report from ReportsRepository
 */
final class ReportViewerSubjects {

    /**
     * @param Report $report
     * @return array{teams: list<array{id:int, name:string}>, players: list<array{id:int, name:string, team_id:int}>}
     */
    public static function for( ReportReader $reader, array $report ): array {
        $none  = [ 'teams' => [], 'players' => [] ];
        $scope = $report['scope'];
        if ( $scope !== ReportContext::SCOPE_TEAM && $scope !== ReportContext::SCOPE_PLAYER ) return $none;

        $binding = $report['binding'];
        if ( $binding === ReportStartRules::BINDING_FIXED ) return $none;
        if ( $binding === ReportStartRules::BINDING_VIEWER && $scope === ReportContext::SCOPE_PLAYER ) return $none;

        $teams = $binding === ReportStartRules::BINDING_VIEWER
            ? self::ownTeams( $reader )
            : self::readableTeams( $reader );
        if ( $scope === ReportContext::SCOPE_TEAM ) return [ 'teams' => $teams, 'players' => [] ];

        $by_id = [];
        foreach ( $teams as $team ) $by_id[ $team['id'] ] = true;
        $rows = QueryHelpers::get_players_for_teams( array_keys( $by_id ) );
        $ids  = [];
        foreach ( $rows as $row ) $ids[] = (int) ( $row->id ?? 0 );
        $reader->primePlayers( $ids );

        $players = [];
        foreach ( $rows as $row ) {
            $pid = (int) ( $row->id ?? 0 );
            if ( $pid <= 0 || ! $reader->readsPlayer( $pid ) ) continue;
            $name      = QueryHelpers::player_display_name( $row );
            $players[] = [ 'id' => $pid, 'name' => $name !== '' ? $name : '#' . $pid, 'team_id' => (int) ( $row->team_id ?? 0 ) ];
        }
        return [ 'teams' => $teams, 'players' => $players ];
    }

    /** @return list<array{id:int, name:string}> */
    private static function readableTeams( ReportReader $reader ): array {
        $allowed = $reader->reportTeams();
        $rows    = $allowed === null ? QueryHelpers::get_teams() : QueryHelpers::get_teams_for_coach( $reader->userId );
        return self::teamList( $rows, $allowed );
    }

    /** @return list<array{id:int, name:string}> */
    private static function ownTeams( ReportReader $reader ): array {
        return self::teamList( QueryHelpers::get_teams_for_coach( $reader->userId ), $reader->reportTeams() );
    }

    /**
     * @param array<mixed>   $rows
     * @param list<int>|null $allowed
     * @return list<array{id:int, name:string}>
     */
    private static function teamList( array $rows, ?array $allowed ): array {
        $out = [];
        foreach ( $rows as $row ) {
            $team = (array) $row;
            $id   = (int) ( $team['id'] ?? 0 );
            if ( $id <= 0 || isset( $out[ $id ] ) ) continue;
            if ( $allowed !== null && ! in_array( $id, $allowed, true ) ) continue;
            $out[ $id ] = [ 'id' => $id, 'name' => (string) ( $team['name'] ?? '' ) ];
        }
        return array_values( $out );
    }
}
