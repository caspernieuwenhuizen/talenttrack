<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\ContactResolver;
use TT\Infrastructure\People\PeopleRepository;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Templates\DirectMessageTemplate;

/**
 * DirectMessageSender (#2604) — the in-product composer's send.
 *
 * Lives here rather than in the compose view so the same send is
 * available to a REST caller or a future SaaS front end, and so the view
 * is left composing a screen rather than deciding what happens to a
 * message.
 *
 * Resolving the recipient is the part worth having in one place: a
 * person row may or may not have an account behind it, and the user id
 * is what opt-out and locale resolution need. When there is none the
 * send still goes out on the address alone — a parent contact with no
 * login is a normal thing to email.
 */
final class DirectMessageSender {

    /**
     * @return CommsResult[]  one per recipient; empty when the person has
     *                        no usable address, which the caller reports.
     */
    public function sendToPerson( int $personId, string $subject, string $body ): array {
        $recipient = self::recipientForPerson( $personId );
        if ( $recipient === null ) return [];

        return CommsDispatcher::dispatchSync(
            DirectMessageTemplate::KEY,
            [ 'subject' => $subject, 'body' => $body ],
            [ $recipient ],
            [ 'message_type' => MessageType::DIRECT_MESSAGE ]
        );
    }

    /**
     * Dry run of the same send, for warning the sender before they
     * commit. Returns `CommsService::preflight()`'s per-recipient shape.
     *
     * @return CommsResult[]
     */
    public function preflightForPerson( int $personId ): array {
        $recipient = self::recipientForPerson( $personId );
        if ( $recipient === null ) return [];

        return ( new \TT\Modules\Comms\CommsService() )->preflight(
            new \TT\Modules\Comms\Domain\CommsRequest(
                DirectMessageTemplate::KEY,
                MessageType::DIRECT_MESSAGE,
                \TT\Infrastructure\Tenancy\CurrentClub::id(),
                get_current_user_id(),
                [ $recipient ]
            )
        );
    }

    private static function recipientForPerson( int $personId ): ?Recipient {
        if ( $personId <= 0 ) return null;

        $email = (string) ( ContactResolver::emailForPerson( $personId ) ?? '' );
        if ( $email === '' || ! is_email( $email ) ) return null;

        $person  = ( new PeopleRepository() )->find( $personId );
        $user_id = (int) ( $person->wp_user_id ?? 0 );
        $locale  = $user_id > 0 ? (string) get_user_meta( $user_id, 'locale', true ) : '';

        // KIND_SELF: the person is the addressee, not a proxy for someone
        // else. The user id is 0 for a contact with no account, which
        // OptOutPolicy reads as "nothing muted" — correct, since there is
        // no account on which a preference could have been set.
        return new Recipient( $user_id, Recipient::KIND_SELF, null, $email, '', $locale );
    }
}
