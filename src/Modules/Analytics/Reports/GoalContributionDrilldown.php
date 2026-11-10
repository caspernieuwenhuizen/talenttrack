<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * GoalContributionDrilldown (#4994) — who may open one player's goals and
 * assists for a team, and the drill-down's answer as data.
 *
 * `GET /teams/{id}/players/{player_id}/goal-contributions` returns
 * {@see payload()}, and the Goals and assists report renders it, so the page
 * and an API consumer get the same rows, totals and flags. The counting is
 * {@see GoalContributionQuery::eventsFor()}'s; this adds names, plain URLs and
 * the window.
 */
final class GoalContributionDrilldown {

    /**
     * Why this user may not open the drill-down, or null when they may.
     * The team first, so an out-of-scope team is always `forbidden_team`;
     * then the player, so a team's staff read only the players they may see.
     *
     * @return array{code:string, message:string}|null
     */
    public static function refusal( int $user_id, int $team_id, int $player_id ): ?array {
        if ( ! AllTeamsScope::canReadTeam( $user_id, $team_id ) ) {
            return [ 'code' => 'forbidden_team', 'message' => __( 'That team is not in your scope.', 'talenttrack' ) ];
        }
        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            return [ 'code' => 'forbidden_player', 'message' => __( 'You may not see this player.', 'talenttrack' ) ];
        }
        return null;
    }

    /**
     * The drill-down for a resolved window ({@see GoalContributionQuery::resolveWindow()}).
     * URLs are plain: no `tt_back`, which only means something to a page.
     *
     * @param array{from:string, to:string, season_id:int, season:string, source:string} $window
     * @return array<string, mixed>
     */
    public static function payload( int $team_id, int $player_id, array $window ): array {
        $filters = $window['source'] === 'all_time' ? [] : [ 'from' => $window['from'], 'to' => $window['to'] ];
        $result  = ( new GoalContributionQuery() )->eventsFor( $team_id, $player_id, $filters );

        $player = QueryHelpers::get_player( $player_id );
        $team   = QueryHelpers::get_team( $team_id );

        $matches = [];
        foreach ( $result['matches'] as $m ) {
            $m['match_url'] = RecordLink::detailUrlFor( 'activities', (int) $m['activity_id'] );
            $matches[]      = $m;
        }

        return [
            'team_id'     => $team_id,
            'team_name'   => $team ? (string) ( $team->name ?? '' ) : '',
            'player_id'   => $player_id,
            'player_name' => $player ? trim( (string) ( $player->first_name ?? '' ) . ' ' . (string) ( $player->last_name ?? '' ) ) : '',
            'window'      => [
                'from'      => $window['from'],
                'to'        => $window['to'],
                'season_id' => (int) $window['season_id'],
                'season'    => $window['season'],
                'source'    => $window['source'],
            ],
            'goals'       => (int) $result['goals'],
            'assists'     => (int) $result['assists'],
            'own_goals'   => (int) $result['own_goals'],
            'matches'     => $matches,
        ];
    }

    /**
     * The Goals and assists report's team table: every player who scored or
     * assisted for the team in the window, from {@see GoalContributionQuery::forTeam()}
     * (the Statistics tab's source), most goals first.
     *
     * @param array{from:string, to:string, source:string} $window
     * @return list<array{player_id:int, name:string, goals:int, assists:int}>
     */
    public static function teamRows( int $team_id, array $window ): array {
        $filters = $window['source'] === 'all_time' ? [] : [ 'from' => $window['from'], 'to' => $window['to'] ];
        $totals  = ( new GoalContributionQuery() )->forTeam( $team_id, $filters );

        $ids = array_values( array_filter( array_map( 'intval', array_keys( $totals ) ), static fn( int $id ): bool => $id > 0 ) );
        $names = [];
        if ( $ids !== [] ) {
            global $wpdb;
            $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, first_name, last_name FROM {$wpdb->prefix}tt_players WHERE club_id = %d AND id IN ({$in})",
                \TT\Infrastructure\Tenancy\CurrentClub::id(), ...$ids
            ) );
            foreach ( (array) $rows as $row ) {
                $names[ (int) $row->id ] = trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
            }
        }

        $out = [];
        foreach ( $totals as $pid => $t ) {
            if ( (int) $t['goals'] + (int) $t['assists'] <= 0 ) continue;
            $out[] = [
                'player_id' => (int) $pid,
                'name'      => $names[ (int) $pid ] ?? '',
                'goals'     => (int) $t['goals'],
                'assists'   => (int) $t['assists'],
            ];
        }
        usort( $out, static function ( array $a, array $b ): int {
            if ( $a['goals'] !== $b['goals'] ) return $b['goals'] <=> $a['goals'];
            if ( $a['assists'] !== $b['assists'] ) return $b['assists'] <=> $a['assists'];
            return strcasecmp( $a['name'], $b['name'] );
        } );
        return $out;
    }

    /** What a source key reads as. */
    public static function sourceLabel( string $source ): string {
        switch ( $source ) {
            case 'live_sheet': return __( 'Live match sheet', 'talenttrack' );
            case 'match_form': return __( 'Entered after the match', 'talenttrack' );
            case 'demo':       return __( 'Demo data', 'talenttrack' );
        }
        return $source;
    }

    /** What a flag reads as, for a coach looking for the wrong entry. */
    public static function flagLabel( string $flag ): string {
        switch ( $flag ) {
            case 'assists_exceed_score': return __( 'More assists than goals we scored in this match.', 'talenttrack' );
            case 'goals_exceed_score':   return __( 'More goals attributed than we scored in this match.', 'talenttrack' );
            case 'player_over_score':    return __( 'More goals or assists for this player than we scored in this match.', 'talenttrack' );
            case 'player_over_cap':      return __( 'No score recorded, and more goals or assists for this player than one match can hold.', 'talenttrack' );
            case 'assist_on_other_goal': return __( 'An assist recorded on an opponent goal or an own goal.', 'talenttrack' );
        }
        return $flag;
    }
}
