<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\AttendanceComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\TeamReportContext;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Attendance per player (#4572) — the team monthly report's attendance
 * section at its details level, read through its composer: every squad
 * player's activities and statuses and their attendance, in shirt order,
 * with the squad's attendance beside it. The counting is
 * `AttendanceRankingQuery::rows()`'s, so the attendance reports agree.
 *
 * It carries neither the monthly report's 70 % line nor the flag
 * thresholds: who is flagged is the "players flagged on attendance" block,
 * with one meaning. Staff only: a per-player table is another child's
 * attendance to a family or a scout.
 */
final class AttendancePerPlayerBlock implements ReportBlock {

    public function key(): string {
        return 'attendance_per_player';
    }

    public function label(): string {
        return __( 'Attendance per player', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM ];
    }

    public function audiences(): array {
        return [ PlayerReportAudience::INTERNAL ];
    }

    public function capability(): string {
        return 'tt_view_reports';
    }

    public function options( ReportContext $context ): array {
        return [];
    }

    /**
     * The attendance read, the gate the attendance reports answer to, not
     * the reports read: a reader who may open a team's reports but not its
     * attendance does not get its attendance here either. A listing names no
     * team, so it asks whether the reader reads any team's attendance.
     */
    public function available( ReportContext $context ): bool {
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        return $context->teamId > 0
            ? AuthorizationService::canReadTeamAttendance( $context->userId, $context->teamId )
            : AuthorizationService::attendanceReadableTeamIds( $context->userId ) !== [];
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        $section = SharedReads::remember(
            'team_attendance_section',
            $context,
            static fn(): array => AttendanceComposer::compose( new TeamReportContext( $context->teamId, $context->from, $context->to, $context->userId ) )
        );

        $rows = [];
        foreach ( (array) ( $section['rows'] ?? [] ) as $r ) {
            if ( ! is_array( $r ) ) continue;
            $rows[] = [
                'player_id'      => (int) ( $r['player_id'] ?? 0 ),
                'name'           => (string) ( $r['name'] ?? '' ),
                'activities'     => (int) ( $r['activities'] ?? 0 ),
                'present'        => (int) ( $r['present'] ?? 0 ),
                'late'           => (int) ( $r['late'] ?? 0 ),
                'absent'         => (int) ( $r['absent'] ?? 0 ),
                'excused'        => (int) ( $r['excused'] ?? 0 ),
                'injured'        => (int) ( $r['injured'] ?? 0 ),
                'suspended'      => (int) ( $r['suspended'] ?? 0 ),
                'attendance_pct' => isset( $r['present_pct'] ) ? (float) $r['present_pct'] : null,
            ];
        }

        return [
            'breakdown'    => 'player_id',
            'rows'         => $rows,
            'team_avg_pct' => $section['team_avg_pct'] ?? null,
            'absences'     => is_array( $section['absences'] ?? null ) ? $section['absences'] : [],
        ];
    }
}
