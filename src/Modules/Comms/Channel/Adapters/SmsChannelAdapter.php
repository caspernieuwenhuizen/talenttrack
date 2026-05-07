<?php
namespace TT\Modules\Comms\Channel\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * SmsChannelAdapter (#0066, channel `sms`).
 *
 * Per spec Q2 lean — "abstract from day one" — this adapter ships as
 * a provider-agnostic shell. It does NOT pick a transport itself;
 * delivery happens via the `tt_comms_sms_send` filter that a per-club
 * provider plugin registers (Twilio, MessageBird, Infobip, etc.).
 *
 * No filter registered = no delivery. The adapter returns
 * `STATUS_FAILED` with `error_code = 'no_sms_provider'` so the audit
 * trail records the miss, the operator's "did the message reach
 * them?" query still answers cleanly, and the caller's UI can prompt
 * the operator to install / configure an SMS provider.
 *
 * Filter contract (mirrors the email adapter's `tt_comms_email_send`):
 *
 *   add_filter( 'tt_comms_sms_send', function ( $accepted, $args ) {
 *     // $args = [ 'uuid', 'to_e164', 'body', 'recipient', 'request' ]
 *     // … call your transport, return true on accept, false on reject …
 *     return $accepted;  // null = pass through to next filter; bool = handled
 *   }, 10, 2 );
 *
 * Per-message-cost guard (deferred): a future SmsCostGuard could
 * cap monthly spend; today the only protection is the `RateLimiter`
 * (50/sender/hour default).
 *
 * Reachability (`canReach()`): true when the recipient carries a
 * non-empty `phoneE164`. The dispatcher's channel-resolver consults
 * this before commit so SMS is skipped for recipients without phones.
 */
final class SmsChannelAdapter implements ChannelAdapterInterface {

    public function key(): string { return 'sms'; }

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
        $to = self::normaliseE164( $recipient->phoneE164 );
        if ( $to === '' ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'sms', $recipient, 'no_phone' );
        }

        // SMS is body-only. The renderedSubject is included as a leading
        // line when present (some templates render a short subject — it
        // gives the recipient a "what is this" context).
        $body = self::buildBody( $renderedSubject, $renderedBody );

        $accepted = apply_filters(
            'tt_comms_sms_send',
            null,
            [
                'uuid'      => $uuid,
                'to_e164'   => $to,
                'body'      => $body,
                'recipient' => $recipient,
                'request'   => $request,
            ]
        );

        if ( $accepted === null ) {
            // No provider registered — operator hasn't set up SMS yet.
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'sms', $recipient, 'no_sms_provider' );
        }

        return new CommsResult(
            $uuid,
            $accepted ? CommsResult::STATUS_SENT : CommsResult::STATUS_FAILED,
            'sms',
            $recipient,
            $accepted ? null : 'transport_rejected'
        );
    }

    /**
     * Normalise to a digits-only E.164-ish form. We accept anything
     * with at least 6 digits; strip spaces / dashes / parentheses;
     * preserve a leading `+` so transports that require canonical
     * E.164 ("+31612345678") get the prefix they expect.
     */
    private static function normaliseE164( string $raw ): string {
        $raw = trim( $raw );
        if ( $raw === '' ) return '';
        $plus = strncmp( $raw, '+', 1 ) === 0 ? '+' : '';
        $digits = preg_replace( '/\D+/', '', $raw );
        if ( $digits === null || strlen( $digits ) < 6 ) return '';
        return $plus . $digits;
    }

    private static function buildBody( string $subject, string $body ): string {
        // Strip HTML — SMS is plain text.
        $plain = trim( wp_strip_all_tags( $body ) );
        $subject = trim( wp_strip_all_tags( $subject ) );
        if ( $subject !== '' && stripos( $plain, $subject ) !== 0 ) {
            $plain = $subject . "\n" . $plain;
        }
        // 480 chars (~3 SMS segments) — long-form delivery costs more
        // and should be rare; truncate aggressively to keep cost
        // predictable. Real notifications fit in one segment.
        return mb_substr( $plain, 0, 480 );
    }
}
