<?php
namespace TT\Modules\ReportStudio\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Domain\AttendanceFlagService;
use TT\Modules\Analytics\Reports\AttendanceRankingQuery;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\SharedReads;

/**
 * Players flagged on attendance (#4572) — `AttendanceRankingQuery::atRisk()`,
 * the at-risk list the attendance report and `GET /reports/attendance-at-risk`
 * read: players over the configured flag thresholds for missed activities or
 * for lateness (`AttendanceFlagService`), worst first, each with why.
 *
 * One meaning only: these are the configured thresholds, not the team
 * monthly report's 70 % line, and the label and the source text say so.
 * Staff only — the at-risk list is a judgement about players.
 */
final class AttendanceFlagsBlock implements ReportBlock {

    public function key(): string {
        return 'attendance_flags';
    }

    public function label(): string {
        return __( 'Players flagged on attendance', 'talenttrack' );
    }

    public function scopes(): array {
        return [ ReportContext::SCOPE_TEAM, ReportContext::SCOPE_ACADEMY ];
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
     * The at-risk route's gate, the attendance read (#4579), not the reports
     * read: a team's list for a reader who reads that team's attendance, the
     * academy's for one who reads every team's. A listing names no team.
     */
    public function available( ReportContext $context ): bool {
        if ( $context->scope === ReportContext::SCOPE_ACADEMY ) {
            return AuthorizationService::attendanceReadableTeamIds( $context->userId ) === null;
        }
        if ( $context->scope !== ReportContext::SCOPE_TEAM ) return false;
        return $context->teamId > 0
            ? AuthorizationService::canReadTeamAttendance( $context->userId, $context->teamId )
            : AuthorizationService::attendanceReadableTeamIds( $context->userId ) !== [];
    }

    public function externalKeys(): array {
        return [];
    }

    public function render( ReportContext $context, array $options ): array {
        // A team reads its own rows; the academy every team's, which only a
        // club-wide reader reaches (`ReportSubjectAccess`).
        $team_id = $context->scope === ReportContext::SCOPE_TEAM ? $context->teamId : 0;
        $flagged = SharedReads::remember(
            'attendance_at_risk',
            $context,
            static fn(): array => ( new AttendanceRankingQuery() )->atRisk( $context->from, $context->to, $team_id )
        );

        $rows = [];
        foreach ( $flagged as $r ) {
            if ( ! is_array( $r ) ) continue;
            $row = [
                'player_id' => (int) ( $r['player_id'] ?? 0 ),
                'name'      => trim( (string) ( $r['first_name'] ?? '' ) . ' ' . (string) ( $r['last_name'] ?? '' ) ),
            ];
            if ( $team_id === 0 ) $row['team_name'] = (string) ( $r['team_name'] ?? '' );
            $row += [
                'activities'     => (int) ( $r['activities'] ?? 0 ),
                'attendance_pct' => isset( $r['present_pct'] ) ? (float) $r['present_pct'] : null,
                'reason'         => AttendanceFlagService::reasonText( $r ),
            ];
            $rows[] = $row;
        }

        return [
            'breakdown'      => 'player_id',
            'rows'           => $rows,
            'threshold'      => AttendanceFlagService::threshold(),
            'late_threshold' => AttendanceFlagService::lateThreshold(),
        ];
    }
}
