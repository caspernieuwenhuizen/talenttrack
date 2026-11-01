<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;

/**
 * #4495 — three figures at the top of the panel for its window and teams:
 * trainings, matches, and the attendance % over the activities with a
 * recorded register. The percentage is the team attendance report's own:
 * `AttendanceRankingQuery::teamRows()` pooled by
 * `AttendanceFlagService::pooledPresentPct()`, so present and late count as
 * attended and the two agree for the same dates and teams.
 */
final class SummaryBlock extends AbstractWeekBlock {

    /** Persona => [ desktop, phone ] when the academy has not chosen. */
    private const DEFAULTS = [
        'head_coach'          => [ true, false ],
        'head_of_development' => [ true, true ],
        'academy_admin'       => [ true, true ],
    ];

    public function key(): string { return 'summary'; }

    public function group(): string { return WeekBlockGroup::OVERVIEW; }

    public function label(): string { return __( 'Week summary', 'talenttrack' ); }

    public function help(): string { return __( 'Trainings, matches and attendance for the window, above the list.', 'talenttrack' ); }

    public function defaultFor( string $persona, string $device ): bool {
        $on = self::DEFAULTS[ $persona ] ?? [ false, false ];
        return $device === 'phone' ? $on[1] : $on[0];
    }

    /**
     * Any other persona gets the strip only when the academy switches it on,
     * so the older persona list's "whole desktop panel" does not hand it to
     * an assistant coach or a team manager.
     */
    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function panelDefaults(): array { return [ 'summary' => null ]; }

    public function panelFields( WeekContext $week ): array {
        $trainings = 0;
        $matches   = 0;
        foreach ( $week->rows() as $row ) {
            if ( ! $week->canSeeTeam( $row->teamId ) ) continue;
            if ( $row->isMatch ) {
                $matches++;
            } elseif ( $row->type === ActivityTypeKey::TRAINING ) {
                $trainings++;
            }
        }

        $team_ids = [];
        foreach ( array_keys( $week->teams ) as $team_id ) {
            if ( $week->canSeeTeam( (int) $team_id ) ) $team_ids[] = (int) $team_id;
        }
        $pct = $week->once( 'summary_attendance', static function () use ( $week, $team_ids ): ?float {
            if ( $team_ids === [] ) return null;
            return AttendanceFlagService::pooledPresentPct(
                ( new AttendanceRankingQuery() )->teamRows( $week->start, $week->end, $team_ids )
            );
        } );

        return [ 'summary' => [
            'trainings'      => $trainings,
            'matches'        => $matches,
            'attendance_pct' => $pct,
        ] ];
    }
}
