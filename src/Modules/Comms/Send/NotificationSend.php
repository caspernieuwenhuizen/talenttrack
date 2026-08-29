<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Templates\NotificationTemplate;

/**
 * NotificationSend (#2604) — the hand-off for caller-composed notification
 * email.
 *
 * Four modules raise notifications whose wording they compose themselves:
 * Push's dispatcher chain, a new thread message, a workflow task
 * assignment, a development-idea status change. None of them has copy a
 * template could own, and all of them need the same three things —
 * `NotificationTemplate`, `MessageType::NOTIFICATION`, and the email
 * channel forced so a notification cannot arrive twice by also going out
 * as push.
 *
 * Lives in Comms rather than in Push because the callers are not Push
 * clients; only one of them is. It started life in
 * `Push\Dispatchers\NotificationDelivery`, which now delegates here.
 */
final class NotificationSend {

    /**
     * @param array<string,mixed> $context  keys: title, body, url, event
     * @param Recipient[]         $recipients
     * @return CommsResult[]
     */
    public static function send( array $context, array $recipients ): array {
        // An empty list is passed through deliberately rather than
        // short-circuited: `CommsService` records a `no_recipients` row for
        // it, and "we tried to tell four people and reached none of them"
        // is exactly the outcome that used to be invisible.
        return CommsDispatcher::dispatchSync(
            NotificationTemplate::KEY,
            [
                'title' => (string) ( $context['title'] ?? '' ),
                'body'  => (string) ( $context['body']  ?? '' ),
                'url'   => (string) ( $context['url']   ?? '' ),
                // Carried into the audit row so a log reader can tell a
                // task assignment from a thread reply — the copy itself
                // is caller-composed and never stored.
                'event' => (string) ( $context['event'] ?? '' ),
            ],
            $recipients,
            [
                'message_type'   => MessageType::NOTIFICATION,
                'force_channel'  => 'email',
                'sender_user_id' => 0,
            ]
        );
    }

    /**
     * True when Comms took responsibility for at least one recipient —
     * sent it, or deliberately declined to. False only when every
     * recipient genuinely failed, or the list was empty.
     *
     * This is the mapping the Push dispatcher chain reads as "did this
     * link handle the message"; see `NotificationDelivery` for why a
     * deferred or opted-out send must not read as a failure there.
     *
     * @param CommsResult[] $results
     */
    public static function claimed( array $results ): bool {
        foreach ( $results as $result ) {
            if ( $result->isSuccess() || $result->isSkipped() ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Build one email `Recipient` per WordPress user id, dropping anyone
     * with no reachable address.
     *
     * @param int[] $userIds
     * @return Recipient[]
     */
    public static function recipientsForUsers( array $userIds ): array {
        $out = [];
        foreach ( array_unique( array_map( 'intval', $userIds ) ) as $userId ) {
            $recipient = self::recipientForUser( $userId );
            if ( $recipient !== null ) $out[] = $recipient;
        }
        return $out;
    }

    /**
     * One email `Recipient` for a WordPress user, or null when the account
     * has no address Comms could reach.
     */
    public static function recipientForUser( int $userId ): ?Recipient {
        if ( $userId <= 0 ) return null;
        $email = \TT\Infrastructure\Identity\ContactResolver::emailForUser( $userId );
        if ( $email === null || $email === '' ) return null;
        $locale = (string) get_user_meta( $userId, 'locale', true );
        return Recipient::self( $userId, $email, '', $locale );
    }
}
