<?php
namespace TT\Modules\Comms\Templates;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateInterface;

/**
 * #2604 — an email a staff member wrote by hand in the in-product
 * composer.
 *
 * Like `NotificationTemplate` this is a passthrough: the subject and
 * body come from the sender, so there is no shipped copy to translate
 * and no per-club override to offer. What routing it through Comms buys
 * is the rest of the chain — the recipient's opt-out is honoured, the
 * academy can switch hand-written mail off entirely, and every send
 * leaves a `tt_comms_log` row next to the automated ones instead of in
 * a separate audit stream of its own.
 *
 * Not operational: someone who has asked the academy not to email them
 * means it when a coach types the message too.
 */
final class DirectMessageTemplate implements TemplateInterface {

    public const KEY = 'direct_message';

    public function key(): string { return self::KEY; }

    public function label(): string { return __( 'Email written by a staff member', 'talenttrack' ); }

    public function supportedChannels(): array { return [ 'email' ]; }

    public function isEditable(): bool { return false; }

    /**
     * @return array{0:string,1:string} subject, body
     */
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [
            (string) ( $request->payload['subject'] ?? '' ),
            (string) ( $request->payload['body'] ?? '' ),
        ];
    }
}
