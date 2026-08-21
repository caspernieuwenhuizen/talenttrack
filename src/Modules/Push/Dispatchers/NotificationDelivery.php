<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Templates\NotificationTemplate;

/**
 * NotificationDelivery (#2604) — the email dispatchers' hand-off to Comms.
 *
 * Both `EmailDispatcher` and `ParentEmailDispatcher` used to call
 * `wp_mail()` directly, which meant notifications bypassed opt-out,
 * quiet hours, the rate limit and the audit log entirely. They now route
 * through `CommsService`. This class holds the one piece of logic they
 * share, so the two cannot drift.
 *
 * ## Mapping Comms results onto the chain's boolean
 *
 * `DispatcherInterface::deliver()` returns a bool, and `DispatcherChain`
 * reads false as "this link declined, try the next one" — falling
 * through to the end records a `dispatch_dropped` audit row.
 *
 * That makes the mapping load-bearing rather than cosmetic. A message
 * deferred for quiet hours, or refused because the recipient opted out,
 * is **not** a drop: the chain must not retry it on another channel, and
 * it must not be reported as undelivered. Those return **true** — Comms
 * accepted responsibility for the message, and the audit row it wrote
 * records precisely what became of it.
 *
 * Only a genuine failure — no usable address, no adapter, an exception —
 * returns false, which is the case the chain's fall-through was built
 * for.
 */
final class NotificationDelivery {

    /**
     * @param array<string,mixed> $context  the dispatcher-chain context
     * @param Recipient[]         $recipients
     */
    public static function send( array $context, array $recipients ): bool {
        if ( $recipients === [] ) return false;

        $results = CommsDispatcher::dispatchSync(
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

        return self::claimed( $results );
    }

    /**
     * True when Comms took responsibility for at least one recipient —
     * sent it, or deliberately declined to. False only when every
     * recipient genuinely failed.
     *
     * @param CommsResult[] $results
     */
    private static function claimed( array $results ): bool {
        foreach ( $results as $result ) {
            if ( $result->isSuccess() || $result->isSkipped() ) {
                return true;
            }
        }
        return false;
    }
}
