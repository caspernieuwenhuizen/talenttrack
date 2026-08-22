<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateInterface;

/**
 * NotificationTemplate (#2604) — passthrough for caller-composed copy.
 *
 * The other templates own their wording. This one does not: the Push
 * module's dispatcher chain carries notifications whose title and body
 * are composed by whichever module raised them — a workflow task
 * assignment, a thread reply, a trial reminder. There is no shared copy
 * to translate, because the copy is different every time.
 *
 * It exists so those sends can go through `CommsService` at all. Without
 * a registered template the orchestrator has nothing to resolve, and the
 * whole point of #2604 is that every outgoing message passes the same
 * opt-out, quiet-hours, rate-limit and audit path. What the audit log
 * gains is a row per notification with the raising module's `event` key
 * in the payload.
 *
 * Not extending `AbstractTemplate`: that base exists to resolve locale,
 * apply per-club overrides and substitute tokens into fixed copy, none
 * of which applies to text the caller already rendered. Overriding all
 * of it to do nothing would be the more confusing choice.
 *
 * Email only. Push has its own adapter and its own dispatcher; routing
 * push through here as well would double-send.
 */
final class NotificationTemplate implements TemplateInterface {

    public const KEY = 'notification';

    public function key(): string { return self::KEY; }

    public function label(): string {
        return __( 'In-product notifications', 'talenttrack' );
    }

    public function supportedChannels(): array { return [ 'email' ]; }

    /**
     * Not editable per club: there is no fixed wording to edit. The
     * academy-wide switch on this template still works — turning it off
     * stops notification email, which is a legitimate thing to want.
     */
    public function isEditable(): bool { return false; }

    /**
     * @return array{0:string,1:string} subject, body
     */
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        $title = (string) ( $request->payload['title'] ?? '' );
        $body  = (string) ( $request->payload['body']  ?? '' );
        $url   = (string) ( $request->payload['url']   ?? '' );

        $lines = [ $body ];
        if ( $url !== '' ) {
            $lines[] = '';
            $lines[] = $url;
        }

        return [ $title, implode( "\n", $lines ) ];
    }
}
