<?php
namespace TT\Modules\Comms\Channel\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * InappChannelAdapter (#0066, channel `inapp`).
 *
 * Persists the rendered message into `tt_comms_inbox` (migration 0076).
 * The persona-dashboard inbox surface (lands separately) reads from
 * this table; a future `GET /comms/inbox` REST endpoint backs the
 * web-app variant.
 *
 * "Delivery" semantics: `STATUS_SENT` is set once the row hits the
 * database. Whether the recipient actually opens it is captured by the
 * `read_at` column the inbox UI stamps on first view — that's a
 * delivery-receipt concern, not a send concern.
 *
 * Recipient eligibility (`canReach()`): any recipient with a non-zero
 * `userId` is reachable in-app, since the inbox is keyed on user_id.
 * System recipients (`Recipient::KIND_SYSTEM`) and parent-as-fallback
 * recipients with `userId === 0` (legacy guardian-email-only path) are
 * not reachable — the dispatcher's channel-resolver picks a different
 * adapter for those.
 */
final class InappChannelAdapter implements ChannelAdapterInterface {

    public function key(): string { return 'inapp'; }

    public function canReach( Recipient $recipient ): bool {
        return $recipient->userId > 0;
    }

    public function send(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody
    ): CommsResult {
        if ( $recipient->userId <= 0 ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'inapp', $recipient, 'no_user_id' );
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_inbox';

        // Defensive — if the migration hasn't run yet, fail soft so the
        // dispatcher's audit trail records the miss without breaking
        // every other channel in the same request.
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'inapp', $recipient, 'inbox_table_missing' );
        }

        $payload_json = ! empty( $request->payload )
            ? wp_json_encode( $request->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE )
            : null;

        $ok = $wpdb->insert(
            $table,
            [
                'club_id'             => (int) $request->clubId,
                'uuid'                => $uuid,
                'recipient_user_id'   => (int) $recipient->userId,
                'recipient_player_id' => $recipient->subjectPlayerId,
                'template_key'        => $request->templateKey,
                'message_type'        => $request->messageType,
                'subject'             => $renderedSubject !== '' ? mb_substr( $renderedSubject, 0, 255 ) : null,
                'body'                => $renderedBody,
                'payload_json'        => $payload_json,
            ],
            [ '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s' ]
        );

        if ( $ok === false ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'inapp', $recipient, 'insert_failed' );
        }

        do_action( 'tt_comms_inapp_delivered', [
            'uuid'      => $uuid,
            'inbox_id'  => (int) $wpdb->insert_id,
            'recipient' => $recipient,
            'request'   => $request,
        ] );

        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'inapp', $recipient );
    }
}
