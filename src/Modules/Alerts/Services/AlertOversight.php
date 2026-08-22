<?php
namespace TT\Modules\Alerts\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;

/**
 * AlertOversight (#2633, epic #2629) — the aggregate that makes epic
 * decision 7 sustainable.
 *
 * Decision 7 says an occurrence goes only to the person who can fix the
 * thing: the responsible coach and the team's head coach. A Head of
 * Development at a twenty-team academy therefore receives nothing, which is
 * correct for their inbox and useless for their job. This service is the
 * other half of that bargain: it reads the occurrences that already exist,
 * sideways, grouped by team, and tells them "four teams have unmarked
 * activities" without a single additional row being written.
 *
 * Without this surface the pressure to fan every occurrence at the HoD comes
 * straight back, and decision 7 quietly gets reversed. That is why the
 * roll-up ships in the same wave as the chips rather than "later".
 *
 * Lives in a service, not in the view, so the REST list and the rendered
 * page answer the same question (CLAUDE.md §4). Deleting every file under
 * `src/Shared/Frontend/` would leave `GET /alerts/rollup` correct.
 */
final class AlertOversight {

    /**
     * Teams the viewer oversees.
     *
     * Resolved through the same capability model every other team-scoped
     * surface uses: a settings-capable user (academy admin) oversees every
     * team; anyone else oversees the teams their role scopes grant, which is
     * re-derived per request and expires with the scope's end date. Nothing
     * here reads a role name.
     *
     * @return list<int>
     */
    public static function teamIdsFor( int $userId ): array {
        if ( $userId <= 0 ) return [];

        $teams = user_can( $userId, 'tt_edit_settings' )
            ? QueryHelpers::get_teams()
            : QueryHelpers::get_teams_for_coach( $userId );

        $out = [];
        foreach ( is_array( $teams ) ? $teams : [] as $team ) {
            $id = (int) ( $team->id ?? 0 );
            if ( $id > 0 ) $out[] = $id;
        }
        return $out;
    }

    /**
     * Whether the roll-up is worth rendering for this user.
     *
     * More than one team is the whole test. A coach with a single team
     * already sees every one of that team's conditions in their own inbox
     * and on the records themselves, so an aggregate of one row would be a
     * second way of saying the same thing. The moment a person is
     * responsible for two teams, "which of my teams is behind" becomes a
     * question they cannot answer by scrolling.
     */
    public static function isAvailableTo( int $userId ): bool {
        return count( self::teamIdsFor( $userId ) ) > 1;
    }

    /**
     * The roll-up rows for this viewer, cap-scoped.
     *
     * A team the viewer does not oversee can never appear: the scope list is
     * computed here and passed as the query's IN-list, so there is no filter
     * a request parameter could widen.
     *
     * @return list<array{team_id:int,team_name:string,count:int,severity:string}>
     */
    public static function forUser( int $userId ): array {
        $team_ids = self::teamIdsFor( $userId );
        if ( count( $team_ids ) < 1 ) return [];

        $rows = ( new AlertOccurrencesRepository() )->rollupByTeams( $team_ids );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'team_id'   => (int) ( $row->team_id ?? 0 ),
                'team_name' => (string) ( $row->team_name ?? '' ),
                'count'     => (int) ( $row->subject_count ?? 0 ),
                'severity'  => self::severityFromWeight( (int) ( $row->severity_weight ?? 0 ) ),
            ];
        }
        return $out;
    }

    /**
     * Reverse of the `FIELD(severity,'info','attention','urgent')` ordering
     * the group-by uses. MAX() over that expression is how the loudest
     * severity in a group survives aggregation; this turns the number back
     * into the value the chip CSS keys on.
     */
    private static function severityFromWeight( int $weight ): string {
        switch ( $weight ) {
            case 3:  return \TT\Modules\Alerts\Domain\Severity::URGENT;
            case 1:  return \TT\Modules\Alerts\Domain\Severity::INFO;
            default: return \TT\Modules\Alerts\Domain\Severity::ATTENTION;
        }
    }
}
