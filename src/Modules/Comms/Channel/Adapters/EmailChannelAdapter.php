<?php
namespace TT\Modules\Comms\Channel\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;

/**
 * EmailChannelAdapter (#0066) — `wp_mail`-default email channel.
 *
 * Per spec Q1 lean ("pluggable with `wp_mail` default"): the adapter
 * delegates to the `tt_comms_email_send` filter when registered, so a
 * club running Mailgun / SES / Postmark can plug in without modifying
 * Comms. The default behaviour is `wp_mail()`, which suffices for low
 * volume and the pilot install.
 *
 * Filter contract: takes the rendered email + recipient + uuid; returns
 * a boolean. A truthy return means delivery accepted by the transport;
 * the adapter logs the result accordingly.
 *
 *   add_filter( 'tt_comms_email_send', function ( $accepted, $args ) {
 *     // $args = [ 'uuid', 'to', 'subject', 'body', 'recipient', 'request' ]
 *     // … call your transport, return true on accept, false on reject …
 *     return $accepted;
 *   }, 10, 2 );
 */
final class EmailChannelAdapter implements ChannelAdapterInterface {

    public function key(): string { return 'email'; }

    public function canReach( Recipient $recipient ): bool {
        $email = trim( $recipient->emailAddress );
        if ( $email === '' ) return false;
        return is_email( $email ) !== false;
    }

    public function send(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody
    ): CommsResult {
        $to = $recipient->emailAddress;
        if ( ! is_email( $to ) ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'email', $recipient, 'no_address' );
        }

        $headers = [
            'Content-Type: text/html; charset=UTF-8',
            'X-TT-Uuid: ' . $uuid,
            'X-TT-Template: ' . $request->templateKey,
        ];

        // Pluggable transport hook — default is `wp_mail`.
        $accepted = apply_filters(
            'tt_comms_email_send',
            null,
            [
                'uuid'      => $uuid,
                'to'        => $to,
                'subject'   => $renderedSubject,
                'body'      => $renderedBody,
                'headers'   => $headers,
                'recipient' => $recipient,
                'request'   => $request,
            ]
        );

        if ( $accepted === null ) {
            // Default path: use wp_mail.
            $accepted = (bool) wp_mail( $to, $renderedSubject, $renderedBody, $headers );
        } else {
            $accepted = (bool) $accepted;
        }

        return new CommsResult(
            $uuid,
            $accepted ? CommsResult::STATUS_SENT : CommsResult::STATUS_FAILED,
            'email',
            $recipient,
            $accepted ? null : 'transport_rejected'
        );
    }
}
