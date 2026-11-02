<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;

/**
 * ReportSubjectOptions (#4570) — the teams and players a builder may build a
 * report about: the teams they build for whose reports they read, and those
 * teams' players whose report they read. A coach is offered their own squads,
 * a builder for every team every squad.
 */
final class ReportSubjectOptions {

    /**
     * @return array<int, array{name:string, age_group:string}> team id => name and age group
     */
    public static function teams( ReportReader $reader ): array {
        $allowed = $reader->builderTeams();
        if ( $allowed === [] ) return [];
        // #4684 — the allowed teams by id, so a team granted through a
        // functional role is offered as well as one the builder coaches.
        $rows = $allowed === null ? QueryHelpers::get_teams() : QueryHelpers::get_teams_by_ids( $allowed );

        $out = [];
        foreach ( $rows as $row ) {
            $team = (array) $row;
            $id   = (int) ( $team['id'] ?? 0 );
            if ( $id <= 0 || isset( $out[ $id ] ) ) continue;
            if ( ! $reader->readsTeam( $id ) ) continue;
            $out[ $id ] = [ 'name' => (string) ( $team['name'] ?? '' ), 'age_group' => (string) ( $team['age_group'] ?? '' ) ];
        }
        return $out;
    }

    /**
     * @return array<int, array<int,string>> team id => player id => name
     */
    public static function players( ReportReader $reader ): array {
        $by_team = [];
        $ids     = [];
        foreach ( array_keys( self::teams( $reader ) ) as $team_id ) {
            foreach ( QueryHelpers::get_players( $team_id ) as $row ) {
                $pid = (int) ( ( (array) $row )['id'] ?? 0 );
                if ( $pid <= 0 ) continue;
                $by_team[ $team_id ][ $pid ] = QueryHelpers::player_display_name( $row );
                $ids[] = $pid;
            }
        }
        $reader->primePlayers( $ids );

        $out = [];
        foreach ( $by_team as $team_id => $players ) {
            foreach ( $players as $pid => $name ) {
                if ( $reader->readsPlayer( $pid ) ) $out[ $team_id ][ $pid ] = $name !== '' ? $name : '#' . $pid;
            }
        }
        return $out;
    }
}
