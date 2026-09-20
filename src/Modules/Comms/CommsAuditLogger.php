<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Repositories\CommsLogSchema;

/**
 * CommsAuditLogger (#0066) — writes the per-send row in `tt_comms_log`.
 *
 * One row per send attempt regardless of outcome. Captures the resolved
 * recipient, channel, status, and a SHA-256 of the rendered body so an
 * operator can answer "did the parents actually get the cancellation
 * message?" without trawling logs and without storing the body verbatim
 * (PII / GDPR retention concerns).
 *
 * Failures here MUST NOT throw — auditing is best-effort. A logger
 * failure shouldn't block delivery; the caller has already received
 * the `CommsResult` by the time we're invoked.
 *
 * GDPR retention: a future cron sweeps rows older than the per-club
 * `comms_audit_retention_months` setting (default 18 per spec Q6 lean)
 * and tombstones `address_blob` / `subject` to `''` while keeping the
 * row for safeguarding evidence.
 */
final class CommsAuditLogger {

    public function record(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody,
        CommsResult $result
    ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = "{$p}tt_comms_log";

        // Defensive: don't crash if the migration hasn't run yet. Loud,
        // though — an install where every send goes unaudited is exactly
        // the state an operator needs to hear about.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            Logger::error( 'Comms audit skipped — tt_comms_log is missing (migration 0075 has not run)', [
                'template_key' => $request->templateKey,
                'status'       => $result->status,
            ] );
            return;
        }

        try {
            $row = [
                'club_id'             => (int) $request->clubId,
                'uuid'                => $uuid,
                'template_key'        => $request->templateKey,
                'message_type'        => $request->messageType,
                'channel'             => $result->channelUsed,
                'sender_user_id'      => (int) $request->senderUserId,
                'recipient_user_id'   => $recipient->userId > 0 ? (int) $recipient->userId : null,
                'recipient_player_id' => $recipient->subjectPlayerId,
                'recipient_kind'      => $recipient->kind,
                'address_blob'        => substr( (string) ( $recipient->emailAddress ?: $recipient->phoneE164 ), 0, 255 ),
                'subject'             => $renderedSubject !== '' ? substr( $renderedSubject, 0, 255 ) : null,
                'payload_hash'        => hash( 'sha256', $renderedBody ),
                'status'              => $result->status,
                'error_code'          => $result->errorCode,
                'attempt'             => 1,
                'attached_export_id'  => $request->attachedExportId,

                // #3696 — stamp the time from the clock the product reasons
                // in, not the one the database happens to run on.
                //
                // `created_at` used to be left to the column's
                // `DEFAULT CURRENT_TIMESTAMP` (migration 0075), which is the
                // MySQL server's local time. Every time decision in Comms
                // uses `wp_timezone()` — `QuietHoursPolicy::shouldDefer()`
                // above all — so on an install whose database zone differs
                // from WordPress's, the log showed a time the decision was
                // not made at.
                //
                // Measured on the pilot install: MariaDB `NOW()` read
                // 23:26 (Europe/Berlin) where WordPress read 21:26 (UTC).
                // A `trial_input_reminder` correctly held for quiet hours at
                // 06:10 WordPress time was logged at 08:10 — outside the
                // 21:00-07:00 window it had just been deferred by. An
                // operator reading that row would reasonably conclude the
                // quiet-hours policy was broken.
                //
                // Rows written before this carry the database server's local
                // time and are deliberately left alone: there is no per-row
                // record of what that offset was, so rewriting them would
                // move timestamps that are already correct wherever the two
                // zones happened to agree.
                'created_at'          => current_time( 'mysql', true ),
            ];

            // #3383 — the second fact, written beside the status rather than
            // folded into it. NULL is a real value here: the send stopped
            // before anything about the recipient was consulted, and a row
            // that guessed would be worse than one that says so.
            if ( CommsLogSchema::hasReachable() ) {
                $row['reachable'] = $result->reachable === null ? null : ( $result->reachable ? 1 : 0 );
            }

            $inserted = $wpdb->insert( $table, $row );

            // #2603 — `$wpdb->insert()` reports a rejected row by returning
            // false, not by throwing, so the catch below never sees it. That
            // is how a `status` value one character too wide for the column
            // silently produced no audit row at all (migration 0220). Check
            // the return value: an audit write that fails must say so.
            if ( $inserted === false ) {
                Logger::error( 'Comms audit row rejected by the database', [
                    'template_key' => $request->templateKey,
                    'status'       => $result->status,
                    'uuid'         => $uuid,
                    'db_error'     => (string) $wpdb->last_error,
                ] );
            }
        } catch ( \Throwable $e ) {
            // Audit failure is non-fatal to delivery, but it must not be
            // invisible — an unlogged failure to log is the worst of both.
            Logger::error( 'Comms audit row could not be written', [
                'template_key' => $request->templateKey,
                'status'       => $result->status,
                'uuid'         => $uuid,
                'exception'    => $e->getMessage(),
            ] );
        }
    }

    /**
     * Update the existing row for a message held by quiet hours (#3646).
     *
     * One message, one row: the send that happens after the window ends
     * overwrites the `quiet_hours` status with the final outcome and moves
     * `attempt` from 1 to 2, rather than leaving a held row beside a sent
     * one for the reader to reconcile.
     *
     * `$attempted = false` is for an outcome reached without trying to
     * send — the queue row expired. Only status and error code change then,
     * and `attempt` stays where it was.
     *
     * Reachability is only overwritten when the new result established it;
     * a result that stopped before looking at the recipient keeps the fact
     * the first attempt recorded.
     */
    public function recordAttempt(
        string $uuid,
        string $renderedSubject,
        string $renderedBody,
        CommsResult $result,
        bool $attempted = true
    ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        try {
            // NULLIF turns the empty string back into the NULL `record()`
            // writes for "no error code" and "no subject".
            $error_code = $result->errorCode ?? '';

            if ( $attempted ) {
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$p}tt_comms_log
                        SET status = %s, error_code = NULLIF(%s, ''), channel = %s,
                            payload_hash = %s, subject = NULLIF(%s, ''), attempt = attempt + 1
                      WHERE uuid = %s",
                    $result->status,
                    $error_code,
                    $result->channelUsed,
                    hash( 'sha256', $renderedBody ),
                    substr( $renderedSubject, 0, 255 ),
                    $uuid
                ) );
            } else {
                $updated = $wpdb->query( $wpdb->prepare(
                    "UPDATE {$p}tt_comms_log SET status = %s, error_code = NULLIF(%s, '') WHERE uuid = %s",
                    $result->status,
                    $error_code,
                    $uuid
                ) );
            }

            if ( $result->reachable !== null && CommsLogSchema::hasReachable() ) {
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$p}tt_comms_log SET reachable = %d WHERE uuid = %s",
                    $result->reachable ? 1 : 0,
                    $uuid
                ) );
            }

            // Both statements above always change the row they match (the
            // status leaves `quiet_hours`, or `attempt` moves), so zero
            // affected rows means the row is not there.
            if ( $updated === false || $updated === 0 ) {
                Logger::error( 'Comms audit update matched no row', [
                    'uuid'     => $uuid,
                    'status'   => $result->status,
                    'db_error' => (string) $wpdb->last_error,
                ] );
            }
        } catch ( \Throwable $e ) {
            Logger::error( 'Comms audit row could not be updated', [
                'uuid'      => $uuid,
                'status'    => $result->status,
                'exception' => $e->getMessage(),
            ] );
        }
    }
}
