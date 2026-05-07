<?php
namespace TT\Modules\Comms\Channel;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * ChannelAdapterInterface (#0066) — one adapter per delivery channel.
 *
 * v1 ships:
 *   - `EmailChannelAdapter` (pluggable, `wp_mail` default per spec Q1).
 *
 * Lands later (each in its own ship):
 *   - `PushChannelAdapter` — registers the existing `Modules\Push`
 *     module as a Comms adapter (Q4 lean: extend in place).
 *   - `SmsChannelAdapter` — provider-abstract per Q2.
 *   - `WhatsappLinkChannelAdapter` — deep-link only per Q3.
 *   - `InappChannelAdapter` — surfaces in the persona dashboard.
 *
 * Adapters are stateless. The dispatcher resolves the request →
 * recipient → channel → adapter and calls `send()` once per
 * recipient. The adapter returns a `CommsResult` per call.
 *
 * Channel keys are the values stored in `tt_comms_log.channel`
 * (`'email'` / `'push'` / `'sms'` / `'whatsapp_link'` / `'inapp'`).
 */
interface ChannelAdapterInterface {

    /** Channel key: 'email' / 'push' / 'sms' / 'whatsapp_link' / 'inapp'. */
    public function key(): string;

    /**
     * True when this adapter can deliver to the given recipient on
     * its channel. The dispatcher's channel-resolver consults this
     * before commit (e.g. SmsAdapter → `phoneE164 !== ''`,
     * PushAdapter → recipient has at least one active device token).
     */
    public function canReach( Recipient $recipient ): bool;

    /**
     * Render + dispatch one message. The renderer + payload have
     * already been resolved upstream by `TemplateRegistry`; this
     * adapter is given the rendered subject + body and only needs to
     * deliver them.
     *
     * `$uuid` is the send identifier (used as `tt_comms_log.uuid`).
     * Adapters MUST NOT generate their own; using the supplied uuid
     * keeps the audit row and any provider-side delivery report
     * cross-linkable.
     */
    public function send(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody
    ): CommsResult;
}
