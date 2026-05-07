<?php
namespace TT\Modules\Comms\Channel\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * WhatsappLinkChannelAdapter (#0066, channel `whatsapp_link`).
 *
 * Per spec Q3 lean: "deep-link only in v1." No WhatsApp Business API
 * onboarding, no Meta verification, no per-message cost. Instead, this
 * adapter renders a `https://wa.me/{e164}?text=...` URL that opens the
 * recipient's WhatsApp client with a pre-filled message ready to send.
 *
 * Send semantics: the adapter does not actually deliver. It builds the
 * link and emits a `tt_comms_whatsapp_link_built` action carrying
 * `[ uuid, url, recipient, request ]` so callers can route the URL to
 * the operator's interface (e.g. a "Open WhatsApp" button rendered
 * after a coach clicks "Notify on WhatsApp" in the cancellation flow).
 *
 * Result status:
 *   - `STATUS_SENT` when the link was built — meaning Comms successfully
 *     handed the URL off; the actual delivery is the operator clicking
 *     the link in their interface. The audit row preserves the URL as
 *     the `address_blob` (truncated 255 chars).
 *   - `STATUS_FAILED` when the recipient has no usable phone number.
 */
final class WhatsappLinkChannelAdapter implements ChannelAdapterInterface {

    public function key(): string { return 'whatsapp_link'; }

    public function canReach( Recipient $recipient ): bool {
        return self::normaliseE164( $recipient->phoneE164 ) !== '';
    }

    public function send(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody
    ): CommsResult {
        $e164 = self::normaliseE164( $recipient->phoneE164 );
        if ( $e164 === '' ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'whatsapp_link', $recipient, 'no_phone' );
        }

        // WhatsApp wa.me deep-link format. Spec § 3.1
        // https://faq.whatsapp.com/5913398998672934/?locale=en_US
        // The body is appended verbatim with `text=` (URL-encoded);
        // wa.me strips a leading `+` from the phone but accepts both.
        $url = 'https://wa.me/' . rawurlencode( $e164 )
             . '?text=' . rawurlencode( $renderedBody );

        do_action( 'tt_comms_whatsapp_link_built', [
            'uuid'      => $uuid,
            'url'       => $url,
            'recipient' => $recipient,
            'request'   => $request,
        ] );

        // Result `note` carries the built URL so callers can read it
        // off the result without re-reading the action. The audit row
        // captures the recipient's existing phoneE164 in `address_blob`
        // (CommsAuditLogger uses it as the fallback when emailAddress
        // is empty); the URL itself is part of the action payload, not
        // the persisted audit row, since URLs encode message content
        // that GDPR retention should not preserve verbatim.
        return new CommsResult(
            $uuid,
            CommsResult::STATUS_SENT,
            'whatsapp_link',
            $recipient,
            null,
            $url
        );
    }

    /**
     * Normalise to a digits-only E.164-ish form. We accept anything
     * with at least 6 digits (international + national both work),
     * stripping spaces / dashes / parentheses. A leading `+` is OK
     * but stripped before the wa.me URL is built (wa.me does not
     * accept the `+`).
     */
    private static function normaliseE164( string $raw ): string {
        if ( $raw === '' ) return '';
        $digits = preg_replace( '/\D+/', '', $raw );
        if ( $digits === null || strlen( $digits ) < 6 ) return '';
        return $digits;
    }
}
