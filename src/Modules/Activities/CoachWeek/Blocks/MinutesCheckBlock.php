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
     * The audit reads one team at a time, so a week across every academy
     * team would cost its statements once per team. Until a cross-team read
     * exists, the two personas that see all teams get the block only when an
     * academy switches it on for them.
     */
    private const DEFAULTS = [ 'head_coach' => [ 'desktop', 'phone' ] ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    /** Every persona the table does not name opts in, the two all-teams personas included. */
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
        $games = $week->once( 'minutes_audit:' . $row->teamId, static function () use ( $row, $week ): array {
            $out = [];
            foreach ( ( new MinutesAuditQuery() )->matrix( $row->teamId, $week->start, $week->end )['games'] as $game ) {
                if ( ! empty( $game['is_rollup'] ) ) continue;
                $out[ (int) $game['activity_id'] ] = [
                    'status'            => (string) $game['status'],
                    'status_reason'     => (string) $game['status_reason'],
                    'total_minutes'     => (int) $game['total_minutes'],
                    'available_minutes' => (int) $game['available_minutes'],
                ];
            }
            return $out;
        } );
        return $games[ $row->activityId ] ?? null;
    }
}
