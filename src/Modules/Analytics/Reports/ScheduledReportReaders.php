<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\ExportGate;

/**
 * ScheduledReportReaders (#4991) — everyone a scheduled report's PDF is
 * composed for.
 *
 * A run builds one PDF and sends that same file to every recipient, so it is
 * composed for all of them together: each section holds only what every
 * reader may read (the owner's decision of 9 November 2026). A reader with
 * more rights gets less than they could see on screen; that is the price of
 * one shared document.
 *
 * The readers, in order:
 *
 *  - the person who set up the schedule: the report is composed as them;
 *  - every account that receives the PDF: it may export files
 *    (`ExportGate`) and passes the report's own read rule (`$receives`);
 *  - a reader of 0 — no rights at all — for every recipient whose rights
 *    cannot be checked: an address with no account here, a role member
 *    outside the club, a role with no members. Also when nobody receives it.
 *
 * An account that fails the read rule receives nothing (the runner names it
 * on the schedule), and so does not narrow what the others get.
 *
 * Resolved from `ScheduledReportsRunner::resolveRecipients()` when asked,
 * never at save time, so a right lost after saving shrinks the next PDF.
 *
 * @phpstan-type Resolved array{recipients: list<\TT\Modules\Comms\Domain\Recipient>, via: array<int, string>, skipped: list<array{email: string, user_id: int, reason: string, via: string}>, unresolved: list<string>}
 */
final class ScheduledReportReaders {

    /** Skip reasons that leave a recipient's rights unknown. */
    private const UNCHECKABLE = [ 'no_account', 'not_in_club' ];

    /**
     * The accounts among the recipients that receive the PDF.
     *
     * @param Resolved            $resolved
     * @param callable(int): bool $receives the report's own read rule
     * @return list<int>
     */
    public static function receivers( array $resolved, callable $receives ): array {
        $out = [];
        foreach ( $resolved['recipients'] as $recipient ) {
            $user_id = (int) $recipient->userId;
            if ( $user_id <= 0 || in_array( $user_id, $out, true ) ) continue;
            if ( ! ExportGate::mayExport( $user_id ) ) continue;
            if ( ! $receives( $user_id ) ) continue;
            $out[] = $user_id;
        }
        return $out;
    }

    /**
     * The readers, the owner first.
     *
     * @param Resolved            $resolved
     * @param int                 $owner_id the schedule's `created_by`
     * @param callable(int): bool $receives the report's own read rule
     * @return list<int>
     */
    public static function readers( array $resolved, int $owner_id, callable $receives ): array {
        $readers   = [ $owner_id ];
        $receivers = self::receivers( $resolved, $receives );
        foreach ( $receivers as $user_id ) {
            if ( ! in_array( $user_id, $readers, true ) ) $readers[] = $user_id;
        }

        $unchecked = $receivers === [] || $resolved['unresolved'] !== [];
        foreach ( $resolved['skipped'] as $skip ) {
            if ( in_array( $skip['reason'], self::UNCHECKABLE, true ) ) $unchecked = true;
        }
        if ( $unchecked && ! in_array( 0, $readers, true ) ) $readers[] = 0;

        return $readers;
    }
}
