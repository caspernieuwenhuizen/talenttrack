<?php
namespace TT\Modules\Comms\Queue;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;

/**
 * DeferredSendQueue (#3646) — messages held by quiet hours, waiting for
 * the window to end.
 *
 * `CommsService` writes the `quiet_hours` log row and hands the request
 * here; `DeferredSendSweep` reads it back on the next heartbeat after the
 * window and sends it against the same log row. The table is
 * `tt_comms_deferred` (migration 0273).
 *
 * What is stored is the *unrendered* request for one recipient: template
 * key, message type, payload tokens and flags, plus that recipient's
 * contact fields. The message is rendered at send time, so the log's
 * never-store-the-body rule holds everywhere except this short-lived
 * queue, and nothing here outlives the send or 24 hours.
 *
 * Two things are refused rather than held:
 *   - a request carrying `attachmentPaths`. The caller deletes its file as
 *     soon as the send returns, and copying a file about minors to keep it
 *     overnight is the wrong trade. Scheduled reports, the one caller that
 *     attaches files, bypass quiet hours for that reason
 *     (`MessageType::bypassesQuietHours`).
 *   - anything the table cannot take (missing table, rejected insert).
 * A refusal is returned as an error code; `CommsService` then logs the
 * message as failed, because a `quiet_hours` row that nothing will ever
 * send is the exact lie this queue exists to end.
 */
final class DeferredSendQueue {

    public const ERROR_WITH_ATTACHMENT = 'deferral_with_attachment';
    public const ERROR_NOT_QUEUED      = 'deferral_not_queued';
    public const ERROR_EXPIRED         = 'deferral_expired';
    public const ERROR_UNREADABLE      = 'deferral_unreadable';

    /** A message caught at 21:00 should have gone by 08:00; a day is generous. */
    public const LIFETIME_SECONDS = DAY_IN_SECONDS;

    /** @var callable():int */
    private $clock;

    /**
     * @param (callable():int)|null $clock Unix timestamp source; `time()` when omitted.
     */
    public function __construct( ?callable $clock = null ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    public function now(): int {
        return ( $this->clock )();
    }

    /**
     * Hold one recipient's copy of a request until quiet hours end.
     *
     * @return string|null Null when queued; otherwise the error code the
     *                     log row should carry.
     */
    public function enqueue( CommsRequest $request, Recipient $recipient, string $logUuid ): ?string {
        if ( $request->attachmentPaths !== [] ) {
            Logger::warning( 'Comms: a message with a file attached was caught by quiet hours and not held', [
                'template_key' => $request->templateKey,
                'message_type' => $request->messageType,
                'uuid'         => $logUuid,
            ] );
            return self::ERROR_WITH_ATTACHMENT;
        }

        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! self::tableExists() ) {
            Logger::error( 'Comms: tt_comms_deferred is missing (migration 0273 has not run), a held message cannot be queued', [
                'template_key' => $request->templateKey,
                'uuid'         => $logUuid,
            ] );
            return self::ERROR_NOT_QUEUED;
        }

        $now      = $this->now();
        $inserted = $wpdb->insert( "{$p}tt_comms_deferred", [
            'club_id'        => $request->clubId > 0 ? $request->clubId : CurrentClub::id(),
            'log_uuid'       => $logUuid,
            'request_json'   => (string) wp_json_encode( self::requestToArray( $request ) ),
            'recipient_json' => (string) wp_json_encode( self::recipientToArray( $recipient ) ),
            'attempts'       => 0,
            'created_at'     => gmdate( 'Y-m-d H:i:s', $now ),
            'expires_at'     => gmdate( 'Y-m-d H:i:s', $now + self::LIFETIME_SECONDS ),
        ] );

        if ( $inserted === false ) {
            Logger::error( 'Comms: a held message was rejected by tt_comms_deferred', [
                'template_key' => $request->templateKey,
                'uuid'         => $logUuid,
                'db_error'     => (string) $wpdb->last_error,
            ] );
            return self::ERROR_NOT_QUEUED;
        }
        return null;
    }

    /**
     * The current club's held messages, oldest first.
     *
     * @return list<array{id:int, log_uuid:string, request_json:string, recipient_json:string, expires_at:int}>
     */
    public function pending( int $limit ): array {
        if ( ! self::tableExists() ) return [];

        global $wpdb;
        $p    = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, log_uuid, request_json, recipient_json, expires_at
               FROM {$p}tt_comms_deferred
              WHERE club_id = %d
              ORDER BY created_at ASC, id ASC
              LIMIT %d",
            CurrentClub::id(),
            max( 1, $limit )
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $expires = strtotime( (string) ( $row['expires_at'] ?? '' ) . ' UTC' );
            $out[]   = [
                'id'             => (int) ( $row['id'] ?? 0 ),
                'log_uuid'       => (string) ( $row['log_uuid'] ?? '' ),
                'request_json'   => (string) ( $row['request_json'] ?? '' ),
                'recipient_json' => (string) ( $row['recipient_json'] ?? '' ),
                'expires_at'     => $expires === false ? 0 : $expires,
            ];
        }
        return $out;
    }

    /**
     * Take a row off the queue. True when this call removed it, false when
     * it was already gone — which is how a second sweep running at the same
     * moment learns the message is not its to send.
     */
    public function claim( int $id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;
        return (int) $wpdb->delete( "{$p}tt_comms_deferred", [ 'id' => $id ], [ '%d' ] ) > 0;
    }

    /** Note that a sweep looked at a row and left it for the next heartbeat. */
    public function touch( int $id ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$p}tt_comms_deferred SET attempts = LEAST(attempts + 1, 255) WHERE id = %d",
            $id
        ) );
    }

    /**
     * Rebuild the request a row holds, addressed to its one recipient.
     * Null when the stored JSON cannot be read.
     */
    public static function hydrate( string $requestJson, string $recipientJson ): ?CommsRequest {
        $r = json_decode( $requestJson, true );
        $a = json_decode( $recipientJson, true );
        if ( ! is_array( $r ) || ! is_array( $a ) ) return null;

        $template_key = (string) ( $r['template_key'] ?? '' );
        $message_type = (string) ( $r['message_type'] ?? '' );
        if ( $template_key === '' || $message_type === '' ) return null;

        $recipient = new Recipient(
            (int) ( $a['user_id'] ?? 0 ),
            (string) ( $a['kind'] ?? Recipient::KIND_SELF ),
            isset( $a['subject_player_id'] ) ? (int) $a['subject_player_id'] : null,
            (string) ( $a['email'] ?? '' ),
            (string) ( $a['phone'] ?? '' ),
            (string) ( $a['locale'] ?? '' )
        );

        $payload     = [];
        $raw_payload = $r['payload'] ?? null;
        foreach ( is_array( $raw_payload ) ? $raw_payload : [] as $k => $v ) {
            $payload[ (string) $k ] = is_scalar( $v ) ? $v : null;
        }

        return new CommsRequest(
            $template_key,
            $message_type,
            (int) ( $r['club_id'] ?? 0 ),
            (int) ( $r['sender_user_id'] ?? 0 ),
            [ $recipient ],
            $payload,
            isset( $r['force_channel'] ) ? (string) $r['force_channel'] : null,
            ! empty( $r['urgent'] ),
            isset( $r['attached_export_id'] ) ? (int) $r['attached_export_id'] : null,
            isset( $r['locale_override'] ) ? (string) $r['locale_override'] : null,
            [],
            isset( $r['subject_player_id'] ) ? (int) $r['subject_player_id'] : null,
            (string) ( $r['subject_type'] ?? '' ),
            (int) ( $r['subject_id'] ?? 0 )
        );
    }

    /**
     * Every field but the recipient list and the attachment paths: the
     * list is stored one recipient per row, and a request with paths is
     * never queued.
     *
     * @return array<string,mixed>
     */
    private static function requestToArray( CommsRequest $request ): array {
        return [
            'template_key'       => $request->templateKey,
            'message_type'       => $request->messageType,
            'club_id'            => $request->clubId,
            'sender_user_id'     => $request->senderUserId,
            'payload'            => $request->payload,
            'force_channel'      => $request->forceChannel,
            'urgent'             => $request->urgent,
            'attached_export_id' => $request->attachedExportId,
            'locale_override'    => $request->localeOverride,
            'subject_player_id'  => $request->subjectPlayerId,
            'subject_type'       => $request->subjectType,
            'subject_id'         => $request->subjectId,
        ];
    }

    /** @return array<string,mixed> */
    private static function recipientToArray( Recipient $recipient ): array {
        return [
            'user_id'           => $recipient->userId,
            'kind'              => $recipient->kind,
            'subject_player_id' => $recipient->subjectPlayerId,
            'email'             => $recipient->emailAddress,
            'phone'             => $recipient->phoneE164,
            'locale'            => $recipient->preferredLocale,
        ];
    }

    private static function tableExists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_comms_deferred';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }
}
