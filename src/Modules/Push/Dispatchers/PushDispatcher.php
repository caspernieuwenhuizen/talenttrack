<?php
namespace TT\Modules\Push\Dispatchers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Identity\PhoneMeta;
use TT\Modules\Push\PushSubscriptionsRepository;
use TT\Modules\Push\WebPushSender;

/**
 * PushDispatcher — Web Push channel (#0042). Sends a payload to every
 * active subscription the target user has registered. Marks the
 * user's phone as verified the first time a push successfully lands
 * (RFC 8030 push services treat 201 Created as "queued for delivery").
 *
 * Failure semantics: a single endpoint returning 404/410 prunes that
 * row but does not fail the whole dispatch — other devices may still
 * succeed. The dispatcher returns true (claim the chain) when at
 * least one endpoint accepts the payload; false when every endpoint
 * is gone or unreachable, so the chain falls through to email.
 */
final class PushDispatcher implements DispatcherInterface {

    private PushSubscriptionsRepository $repo;
    private WebPushSender $sender;

    public function __construct( ?PushSubscriptionsRepository $repo = null, ?WebPushSender $sender = null ) {
        $this->repo   = $repo   ?? new PushSubscriptionsRepository();
        $this->sender = $sender ?? new WebPushSender();
    }

    public function key(): string { return 'push'; }

    public function applicableTo( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;
        return ! empty( $this->repo->activeForUser( $user_id ) );
    }

    public function deliver( array $context ): bool {
        $user_id = (int) ( $context['user_id'] ?? 0 );
        if ( $user_id <= 0 ) return false;

        $subs = $this->repo->activeForUser( $user_id );
        if ( empty( $subs ) ) return false;

        $payload = [
            'title' => (string) ( $context['title'] ?? '' ),
            'body'  => (string) ( $context['body']  ?? '' ),
            'url'   => (string) ( $context['url']   ?? home_url( '/' ) ),
            'tag'   => (string) ( $context['tag']   ?? 'tt' ),
            'data'  => is_array( $context['data'] ?? null ) ? $context['data'] : [],
        ];

        $any_ok = false;
        foreach ( $subs as $sub ) {
            $result = $this->sender->send( $sub, $payload );
            if ( $result['gone'] ) {
                $this->repo->deleteById( (int) $sub['id'] );
                continue;
            }
            if ( $result['ok'] ) {
                $any_ok = true;
                $this->repo->touch( (int) $sub['id'] );
                PhoneMeta::markVerified( $user_id );
            }
        }
        return $any_ok;
    }
}
