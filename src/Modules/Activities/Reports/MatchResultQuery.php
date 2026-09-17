<?php
namespace TT\Modules\Activities\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Shared\Club\ClubIdentity;

/**
 * MatchResultQuery (#3530, epic #3529) — everything a surface needs to show
 * or edit one match's result, resolved in one place.
 *
 * Until this existed, `tt_activities.home_score` / `away_score` had exactly
 * one writer in the plugin: the end-of-match copy off the execution row
 * (`MatchExecutionRestController::route_finish`). A club doing its admin on
 * a Sunday evening therefore had no way to record that a match finished 3-1,
 * and no way at all to record the opponent's goals — those are not
 * attributable to a player, so the minutes grid's `G` column (#3094) could
 * never reach them.
 *
 * WHAT THE TWO COLUMNS ACTUALLY MEAN
 *
 * `home_score` is what **we** scored and `away_score` what **they** scored,
 * whatever the venue. That is what `route_finish` copies: the execution's
 * "home" side is `ClubIdentity::shortCode()` and its "away" side is the
 * activity's opponent, and migration 0235 states the same convention for the
 * goal-event rows. The names are misnomers in exactly the way migration 0246
 * flagged `tt_match_execution_goal_events` as one; renaming them is a data
 * migration across ~20 readers and is deliberately left undone. This class
 * exposes them as `our_score` / `their_score` so no caller has to know.
 *
 * WHO MAY WRITE IT
 *
 * `owned_by_execution` decides, and the match decides that rather than the
 * coach. A match with a `tt_match_execution` row has a scoreline derived from
 * its goal log (#2857 removed the free-standing stepper precisely so there
 * would be no second place to record a goal); the Result card renders a
 * readout and the write endpoint refuses. A match without one is typed in.
 *
 * `attributed_goals` against `our_score` is the same reconciliation the
 * minutes grid footer prints, and carries the same ruling: information, never
 * a validation gate. "We do not know who scored the third" is a true state of
 * the world.
 *
 * Tenant-scoped on `club_id` (structural for the SaaS migration, §4).
 */
final class MatchResultQuery {

    /**
     * One match's result, or null when the activity does not exist, is not
     * visible to this club, or is not a fixture.
     *
     * Tournaments are excluded on purpose: a tournament is a multi-game day
     * (#2686) and one score line cannot describe it. #3532 owns that case.
     *
     * @return array{
     *   activity_id:int, team_id:int, our_score:?int, their_score:?int,
     *   has_score:bool, owned_by_execution:bool, attributed_goals:int,
     *   opponent:string, home_away:string, our_abbr:string, their_abbr:string,
     *   goal_log:list<array{minute:?int, team:string, is_own_goal:bool, player_id:int, scorer:string}>
     * }|null
     */
    public function forActivity( int $activity_id ): ?array {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        // Demo scope, like every other activity read — a seeded academy's
        // fixtures must not surface through this endpoint on a live install.
        $scope = QueryHelpers::apply_demo_scope( 'a', 'activity' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.id, a.team_id, a.activity_type_key, a.opponent, a.home_away,
                    a.home_score, a.away_score
               FROM {$wpdb->prefix}tt_activities a
              WHERE a.id = %d AND a.club_id = %d AND a.archived_at IS NULL {$scope}",
            $activity_id, (int) CurrentClub::id()
        ) );
        if ( ! $row ) return null;
        if ( ! self::isFixture( (string) ( $row->activity_type_key ?? '' ) ) ) return null;

        $exec     = new MatchExecutionRepository();
        $owned    = $exec->existsForActivity( $activity_id );
        $opponent = trim( (string) ( $row->opponent ?? '' ) );

        // Null is not zero. A match nobody has recorded a result for is not a
        // goalless draw, and saying "0 - 0" would invent a fact (#3529).
        $ours   = $row->home_score !== null ? (int) $row->home_score : null;
        $theirs = $row->away_score !== null ? (int) $row->away_score : null;

        return [
            'activity_id'        => $activity_id,
            'team_id'            => (int) ( $row->team_id ?? 0 ),
            'our_score'          => $ours,
            'their_score'        => $theirs,
            'has_score'          => $ours !== null && $theirs !== null,
            'owned_by_execution' => $owned,
            'attributed_goals'   => (int) ( $exec->attributedGoalsByActivity( [ $activity_id ] )[ $activity_id ] ?? 0 ),
            'opponent'           => $opponent,
            'home_away'          => strtolower( (string) ( $row->home_away ?? '' ) ),
            'our_abbr'           => ClubIdentity::shortCode(),
            'their_abbr'         => ClubIdentity::abbreviateOpponent( $opponent ),
            'goal_log'           => $owned ? self::goalLog( $exec, $activity_id ) : [],
        ];
    }

    /**
     * A single fixture: the current `game` key or the legacy `match` value.
     * `tournament` is deliberately absent (see {@see forActivity}).
     */
    private static function isFixture( string $type_key ): bool {
        return in_array( strtolower( $type_key ), [ ActivityTypeKey::GAME, 'match' ], true );
    }

    /**
     * The goal log for an execution-owned match, flattened to what a readout
     * needs: an absolute match minute and a name.
     *
     * A goal typed in after the fact carries no half and no minute — 0246
     * refused to invent one, because a fabricated 45' would flow into the
     * timeline as though somebody had watched it happen — so `minute` stays
     * nullable all the way to the surface.
     *
     * `scorer` resolves here rather than in the view, so the REST payload and
     * the rendered card cannot disagree about what a goal reads as (§4).
     * Migration 0235 made three states representable and each says something
     * different: an own goal, a goal nobody attributed, and a named scorer.
     *
     * @return list<array{minute:?int, team:string, is_own_goal:bool, player_id:int, scorer:string}>
     */
    private static function goalLog( MatchExecutionRepository $exec, int $activity_id ): array {
        $execution = $exec->findByActivity( $activity_id );
        if ( ! $execution ) return [];

        $out = [];
        foreach ( $exec->listGoalEvents( (int) $execution->id ) as $ev ) {
            $half      = isset( $ev->half ) && $ev->half !== null ? (int) $ev->half : null;
            $in_half   = isset( $ev->minute_in_half ) && $ev->minute_in_half !== null ? (int) $ev->minute_in_half : null;
            $player_id = (int) ( $ev->player_id ?? 0 );
            $own       = ! empty( $ev->is_own_goal );
            $for_them  = ( (string) ( $ev->team ?? 'home' ) ) === 'away';

            if ( $own ) {
                $scorer = _x( 'Own goal', 'goal put in by the side it counts against', 'talenttrack' );
            } elseif ( $for_them ) {
                $scorer = __( 'Goal against', 'talenttrack' );
            } else {
                $player = $player_id > 0 ? QueryHelpers::get_player( $player_id ) : null;
                $scorer = $player ? QueryHelpers::player_display_name( $player ) : __( 'Scorer not recorded', 'talenttrack' );
            }

            $out[] = [
                // Absolute match minute: the second half continues from 45.
                'minute'      => ( $half !== null && $in_half !== null ) ? ( $half === 2 ? 45 : 0 ) + $in_half : null,
                'team'        => $for_them ? 'them' : 'us',
                'is_own_goal' => $own,
                'player_id'   => $player_id,
                'scorer'      => $scorer,
            ];
        }
        return $out;
    }
}
