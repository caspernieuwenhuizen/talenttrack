<?php
namespace TT\Modules\Comms;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

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

        // Defensive: don't crash if the migration hasn't run yet.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        try {
            $wpdb->insert( $table, [
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
            ] );
        } catch ( \Throwable $e ) {
            // Audit failure is non-fatal.
        }
    }
}
