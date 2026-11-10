<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;

/**
 * TeamMonthlyReportRecipients (#4991) — who a scheduled monthly team report
 * is composed for, and what its schedule says it will contain.
 *
 * The readers are `ScheduledReportReaders`' — the owner, every account that
 * receives the PDF, and a reader with no rights for every recipient whose
 * rights cannot be checked — with the team report's read rule
 * (`TeamReportAccess::canRead()`) deciding who receives it. The delivery
 * composes the report for all of them at once
 * (`TeamMonthlyReport::forReaders()`), so every section holds only what each
 * of them may read:
 *
 *  - the attendance section, the attendance tile and the attendance columns,
 *    only when every reader may read the team's attendance;
 *  - "what changed", with only the journey entries every reader may see:
 *    the visibility levels they all hold (coaching staff, medical,
 *    safeguarding), and an entry about a record of its own (a PDP verdict,
 *    an injury, a suspension, a trial) only when every reader may read that
 *    record.
 *
 * @phpstan-import-type Resolved from ScheduledReportReaders
 */
final class TeamMonthlyReportRecipients {

    /**
     * Everyone the PDF is composed for, the owner first.
     *
     * @param Resolved $resolved
     * @param int      $owner_id the schedule's `created_by`
     * @return list<int>
     */
    public static function readers( int $team_id, array $resolved, int $owner_id ): array {
        return ScheduledReportReaders::readers(
            $resolved,
            $owner_id,
            static fn( int $user_id ): bool => TeamReportAccess::canRead( $user_id, $team_id )
        );
    }

    /**
     * Whether every reader of the PDF may read the team's attendance: the
     * same rule `TeamReportContext::readsAttendance()` applies when the
     * report is composed for these readers.
     *
     * @param Resolved $resolved
     * @param int      $owner_id the schedule's `created_by`
     */
    public static function readsAttendance( int $team_id, array $resolved, int $owner_id ): bool {
        foreach ( self::readers( $team_id, $resolved, $owner_id ) as $reader ) {
            if ( ! AuthorizationService::canReadTeamAttendance( $reader, $team_id ) ) return false;
        }
        return true;
    }

    /**
     * What a saved schedule's recipients get, resolved now, for the
     * schedules list: the section titles in print order, and whether the
     * attendance figures are left out.
     *
     * @param array<string,mixed> $schedule a hydrated `ScheduledReportsRepository` row
     * @return array{sections: list<string>, without_attendance: bool}
     */
    public static function preview( array $schedule ): array {
        $composition = TeamMonthlyReportComposition::normalise( is_array( $schedule['composition'] ?? null ) ? $schedule['composition'] : [] );
        $resolved    = ScheduledReportsRunner::resolveRecipients( array_map( 'strval', (array) ( $schedule['recipients'] ?? [] ) ) );
        $attendance  = self::readsAttendance( $composition['team_id'], $resolved, (int) ( $schedule['created_by'] ?? 0 ) );

        $sections = [];
        foreach ( TeamMonthlyReportBlock::normalise( $composition['blocks'] ) as $block ) {
            if ( ! in_array( $block, TeamMonthlyReportBlock::HEADED, true ) ) continue;
            if ( ! $attendance && $block === TeamMonthlyReportBlock::ATTENDANCE ) continue;
            $sections[] = TeamMonthlyReportBlock::title( $block );
        }
        return [ 'sections' => $sections, 'without_attendance' => ! $attendance ];
    }
}
