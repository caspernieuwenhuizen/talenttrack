<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Export\ExportGate;

/**
 * TeamMonthlyReportRecipients (#4991) — what a scheduled monthly team report
 * may contain, given everyone it goes to.
 *
 * Each run builds one PDF for all recipients, with the rights of the
 * least-privileged one (the owner's decision of 9 November 2026). A section
 * is included only when every recipient may read it. A recipient with more
 * rights gets less than they could see on screen; that is the price of one
 * shared document.
 *
 * Today one section has a read rule of its own: attendance answers to
 * `AuthorizationService::canReadTeamAttendance()` (#4949), and dropping it
 * is `TeamMonthlyReport::withoutAttendance()`, the same removal the screen,
 * the print and a snapshot use. Every other section follows the team report
 * read rule (`TeamReportAccess::canRead()`), which already decides who
 * receives the PDF at all.
 *
 * Who counts:
 *
 *  - the person who set up the schedule: the report is composed as them;
 *  - every account that receives the PDF: it may export files
 *    (`ExportGate`) and read the team's reports;
 *  - every recipient whose rights cannot be checked: an address with no
 *    account here, a role member outside the club, a role with no members.
 *    They count as having no rights, so they take the attendance out.
 *
 * An account on the schedule that may not read the team's reports receives
 * nothing (the runner names it on the schedule), and so does not narrow
 * what the others get.
 *
 * The runner and the schedules list both resolve the recipients through
 * `ScheduledReportsRunner::resolveRecipients()` when they ask, never at save
 * time, so a right lost after saving shrinks the next PDF.
 *
 * @phpstan-type Resolved array{recipients: list<\TT\Modules\Comms\Domain\Recipient>, via: array<int, string>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>, unresolved: list<string>}
 */
final class TeamMonthlyReportRecipients {

    /** Skip reasons that leave a recipient's rights unknown. */
    private const UNCHECKABLE = [ 'no_account', 'not_in_club' ];

    /**
     * The accounts among these that receive the PDF.
     *
     * @param list<int> $user_ids
     * @return list<int>
     */
    public static function receivers( int $team_id, array $user_ids ): array {
        $out = [];
        foreach ( $user_ids as $user_id ) {
            $user_id = (int) $user_id;
            if ( $user_id <= 0 ) continue;
            if ( ! ExportGate::mayExport( $user_id ) ) continue;
            if ( ! TeamReportAccess::canRead( $user_id, $team_id ) ) continue;
            $out[] = $user_id;
        }
        return $out;
    }

    /**
     * Whether every reader of the PDF may read the team's attendance: every
     * recipient, and the person who set up the schedule. The report is
     * composed as the owner (`TeamReportContext::readsAttendance()`), so an
     * owner without the right takes the attendance out whoever receives it,
     * and the list must not promise it.
     *
     * @param Resolved $resolved
     * @param int      $owner_id the schedule's `created_by`
     */
    public static function readsAttendance( int $team_id, array $resolved, int $owner_id ): bool {
        if ( ! AuthorizationService::canReadTeamAttendance( $owner_id, $team_id ) ) return false;
        if ( $resolved['unresolved'] !== [] ) return false;
        foreach ( $resolved['skipped'] as $skip ) {
            if ( in_array( $skip['reason'], self::UNCHECKABLE, true ) ) return false;
        }

        $user_ids = array_map( static fn( $r ): int => (int) $r->userId, $resolved['recipients'] );
        $receivers = self::receivers( $team_id, $user_ids );
        if ( $receivers === [] ) return false;

        foreach ( $receivers as $user_id ) {
            if ( ! AuthorizationService::canReadTeamAttendance( $user_id, $team_id ) ) return false;
        }
        return true;
    }

    /**
     * A composed report as all these recipients may read it.
     *
     * @param array{data: array<string, array<string, mixed>>, blocks: list<string>, from: string, to: string} $report
     * @param Resolved $resolved
     * @param int      $owner_id the schedule's `created_by`, as whom the report was composed
     * @return array{data: array<string, array<string, mixed>>, blocks: list<string>, from: string, to: string}
     */
    public static function forRecipients( array $report, int $team_id, array $resolved, int $owner_id ): array {
        return self::readsAttendance( $team_id, $resolved, $owner_id )
            ? $report
            : TeamMonthlyReport::withoutAttendance( $report );
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
