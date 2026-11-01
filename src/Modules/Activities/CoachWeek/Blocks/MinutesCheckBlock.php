<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #4494 — on a completed match, the minutes entered against what the match
 * holds, with the audit's own verdict: `MinutesAuditQuery::matrix()` for the
 * team and the window, read once per team, so this row, the minutes grid
 * and the audit give the same answer (#4483).
 */
final class MinutesCheckBlock extends AbstractWeekBlock {

    public function key(): string { return 'minutes'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Minutes check', 'talenttrack' ); }

    public function help(): string { return __( 'The minutes entered against what the match holds, as the minutes audit judges it.', 'talenttrack' ); }

    /**
     * persona => devices on by default (#4494's table). The audit reads every
     * team of the week at once (#4553), so the personas that see all teams
     * get the block by default too.
     */
    private const DEFAULTS = [
        'head_coach'          => [ 'desktop', 'phone' ],
        'head_of_development' => [ 'desktop' ],
        'academy_admin'       => [ 'desktop' ],
    ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    /** Every persona the table does not name opts in. */
    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function rowDefaults(): array { return [ 'minutes_check' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        $check = self::checkFor( $row, $week );
        return $check === null ? [] : [ 'minutes_check' => $check ];
    }

    /**
     * The audit's verdict for one completed match, or null when the match is
     * not completed, is a tournament day, or is not the viewer's to see.
     * Shared with the Minutes + stats button.
     *
     * @return array{status:string, status_reason:string, total_minutes:int, available_minutes:int}|null
     */
    public static function checkFor( WeekRow $row, WeekContext $week ): ?array {
        if ( ! $row->done || ! $week->canSeeTeam( $row->teamId ) ) return null;
        // #4553 — one audit read for every team with a completed match this
        // week, so the cost does not grow with the number of teams.
        $games = $week->once( 'minutes_audit', static function () use ( $week ): array {
            $teams = [];
            foreach ( $week->rows() as $r ) {
                if ( $r->done && $week->canSeeTeam( $r->teamId ) ) $teams[ $r->teamId ] = $r->teamId;
            }
            $out = [];
            foreach ( ( new MinutesAuditQuery() )->matrixForTeams( array_values( $teams ), $week->start, $week->end ) as $matrix ) {
                foreach ( $matrix['games'] as $game ) {
                    if ( ! empty( $game['is_rollup'] ) ) continue;
                    $out[ (int) $game['activity_id'] ] = [
                        'status'            => (string) $game['status'],
                        'status_reason'     => (string) $game['status_reason'],
                        'total_minutes'     => (int) $game['total_minutes'],
                        'available_minutes' => (int) $game['available_minutes'],
                    ];
                }
            }
            return $out;
        } );
        return $games[ $row->activityId ] ?? null;
    }
}
