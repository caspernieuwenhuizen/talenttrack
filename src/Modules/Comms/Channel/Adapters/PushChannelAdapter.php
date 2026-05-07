<?php
namespace TT\Modules\Comms\Channel\Adapters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Push\PushSubscriptionsRepository;
use TT\Modules\Push\WebPushSender;

/**
 * PushChannelAdapter (#0066, channel `push`).
 *
 * Per spec Q4 lean — "extend existing Push module + register Push as a
 * Comms channel adapter" — this adapter wraps the existing
 * `Modules\Push` infrastructure rather than re-implementing it. The
 * Push module owns:
 *
 *   - VAPID key management (`VapidKeyManager`)
 *   - Subscription storage (`PushSubscriptionsRepository`)
 *   - The actual Web Push protocol implementation (`WebPushSender`)
 *
 * This adapter just resolves a `Recipient` to that user's active
 * subscriptions and dispatches the payload via `WebPushSender::send()`.
 *
 * Pluggable transport hook: callers (or alternative push backends)
 * can short-circuit the default by registering `tt_comms_push_send`,
 * mirroring the email adapter's pattern. A truthy return means the
 * filter handled delivery; null falls through to `WebPushSender`.
 *
 * Reachability (`canReach()`): true when the recipient has at least
 * one active subscription within the last 90 days.
 */
final class PushChannelAdapter implements ChannelAdapterInterface {

    public function key(): string { return 'push'; }

    public function canReach( Recipient $recipient ): bool {
        if ( $recipient->userId <= 0 ) return false;
        if ( ! class_exists( PushSubscriptionsRepository::class ) ) return false;
        $repo = new PushSubscriptionsRepository();
        $subs = $repo->activeForUser( $recipient->userId, 90 );
        return is_array( $subs ) && $subs !== [];
    }

    public function send(
        CommsRequest $request,
        Recipient $recipient,
        string $uuid,
        string $renderedSubject,
        string $renderedBody
    ): CommsResult {
        if ( $recipient->userId <= 0 ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'push', $recipient, 'no_user_id' );
        }

        $payload = [
            'uuid'         => $uuid,
            'title'        => $renderedSubject !== '' ? $renderedSubject : __( 'TalentTrack', 'talenttrack' ),
            'body'         => self::truncateBody( $renderedBody ),
            'template_key' => $request->templateKey,
            'message_type' => $request->messageType,
            // Deep-link target — `payload['deep_link']` is the convention
            // when the use case needs the click to land on a specific
            // surface (player profile, goal detail, etc.).
            'url'          => isset( $request->payload['deep_link'] )
                ? (string) $request->payload['deep_link']
                : '',
        ];

        // Pluggable transport hook — callers can swap the Web Push
        // backend or queue dispatch; null falls through to the default.
        $accepted = apply_filters(
            'tt_comms_push_send',
            null,
            [
                'uuid'      => $uuid,
                'payload'   => $payload,
                'recipient' => $recipient,
                'request'   => $request,
            ]
        );

        if ( $accepted !== null ) {
            return new CommsResult(
                $uuid,
                $accepted ? CommsResult::STATUS_SENT : CommsResult::STATUS_FAILED,
                'push',
                $recipient,
                $accepted ? null : 'transport_rejected'
            );
        }

        if ( ! class_exists( PushSubscriptionsRepository::class ) || ! class_exists( WebPushSender::class ) ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'push', $recipient, 'push_module_unavailable' );
        }

        $repo = new PushSubscriptionsRepository();
        $sender = new WebPushSender();
        $subs = $repo->activeForUser( $recipient->userId, 90 );
        if ( ! is_array( $subs ) || $subs === [] ) {
            return new CommsResult( $uuid, CommsResult::STATUS_FAILED, 'push', $recipient, 'no_subscription' );
        }

        $any_ok = false;
        foreach ( $subs as $sub ) {
            $result = $sender->send( $sub, $payload );
            if ( ! empty( $result['gone'] ) ) {
                // 410 Gone: the subscription is dead, prune it so we
                // stop trying. Mirrors the existing #0042 lifecycle rule.
                $endpoint = (string) ( $sub['endpoint'] ?? '' );
                if ( $endpoint !== '' ) $repo->deleteByEndpoint( $endpoint );
                continue;
            }
            if ( ! empty( $result['ok'] ) ) {
                $any_ok = true;
                if ( isset( $sub['id'] ) ) $repo->touch( (int) $sub['id'] );
            }
        }

        return new CommsResult(
            $uuid,
            $any_ok ? CommsResult::STATUS_SENT : CommsResult::STATUS_FAILED,
            'push',
            $recipient,
            $any_ok ? null : 'all_subscriptions_failed'
        );
    }

    /**
     * Web Push payloads are tiny (4KB ceiling); truncate body to keep
     * the JSON envelope well under the encryption-overhead-adjusted
     * 4000-byte plaintext budget that `WebPushSender` enforces.
     */
    private static function truncateBody( string $body ): string {
        // Strip HTML — push notifications don't render markup.
        $plain = trim( wp_strip_all_tags( $body ) );
        return mb_substr( $plain, 0, 280 );
    }
}
