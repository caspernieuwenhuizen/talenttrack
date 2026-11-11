<?php
namespace TT\Modules\ReportStudio\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;
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
     * Every team's roster comes from one query (#4719), the roster
     * `get_players()` reads per team, so an academy with 30 teams costs what
     * one with 3 does.
     *
     * @param array<int, array{name:string, age_group:string}>|null $teams `teams()`, when the caller already has it
     * @return array<int, array<int,string>> team id => player id => name
     */
    public static function players( ReportReader $reader, ?array $teams = null ): array {
        $team_ids = array_keys( $teams ?? self::teams( $reader ) );
        $by_team  = array_fill_keys( $team_ids, [] );
        $ids      = [];
        foreach ( QueryHelpers::get_players_for_teams( $team_ids ) as $row ) {
            $data    = (array) $row;
            $pid     = (int) ( $data['id'] ?? 0 );
            $team_id = (int) ( $data['team_id'] ?? 0 );
            if ( $pid <= 0 || ! isset( $by_team[ $team_id ] ) ) continue;
            $by_team[ $team_id ][ $pid ] = QueryHelpers::player_display_name( $row );
            $ids[] = $pid;
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

    /**
     * #4964 — the positions a group can be narrowed to: the academy's
     * position codes, in their configured order, upper-cased as a group
     * matches them, each with its name in the viewer's language.
     *
     * @return array<string,string> code => label
     */
    public static function positions(): array {
        $out = [];
        foreach ( QueryHelpers::get_lookup_names( 'position' ) as $name ) {
            $code = strtoupper( trim( (string) $name ) );
            if ( $code === '' || isset( $out[ $code ] ) ) continue;
            $out[ $code ] = LabelTranslator::positionLabel( (string) $name );
        }
        return $out;
    }
}
