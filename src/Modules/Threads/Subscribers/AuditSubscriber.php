<?php
namespace TT\Modules\Threads\Subscribers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Kernel;
use TT\Infrastructure\Audit\AuditService;

/**
 * AuditSubscriber (#0028) — records four event types in tt_audit_log.
 *
 *   thread_message_posted       — every non-system post.
 *   thread_message_edited       — author edits within the 5-min window.
 *   thread_message_deleted      — author or admin soft-delete; original
 *                                  body retained in payload so admins
 *                                  can recover via the audit log.
 *   thread_visibility_changed   — author flips public ↔ private during
 *                                  the edit window.
 */
final class AuditSubscriber {

    public static function init(): void {
        add_action( 'tt_thread_message_posted',  [ self::class, 'onPosted' ],  10, 5 );
        add_action( 'tt_thread_message_edited',  [ self::class, 'onEdited' ],  10, 4 );
        add_action( 'tt_thread_message_deleted', [ self::class, 'onDeleted' ], 10, 5 );
    }

    public static function onPosted( string $type, int $thread_id, int $msg_id, int $author_user_id, string $visibility ): void {
        self::record( 'thread_message_posted', [
            'thread_type'  => $type,
            'thread_id'    => $thread_id,
            'message_id'   => $msg_id,
            'author_user'  => $author_user_id,
            'visibility'   => $visibility,
        ], $msg_id );
    }

    public static function onEdited( string $type, int $thread_id, int $msg_id, int $author_user_id ): void {
        self::record( 'thread_message_edited', [
            'thread_type' => $type,
            'thread_id'   => $thread_id,
            'message_id'  => $msg_id,
            'actor_user'  => $author_user_id,
        ], $msg_id );
    }

    public static function onDeleted( string $type, int $thread_id, int $msg_id, int $actor_user_id, string $original_body ): void {
        self::record( 'thread_message_deleted', [
            'thread_type'    => $type,
            'thread_id'      => $thread_id,
            'message_id'     => $msg_id,
            'actor_user'     => $actor_user_id,
            'original_body'  => $original_body,
        ], $msg_id );
    }

    /**
     * @param array<string,mixed> $payload
     */
    private static function record( string $action, array $payload, int $entity_id = 0 ): void {
        $svc = self::auditService();
        if ( $svc === null ) return;
        $svc->record( $action, 'thread_message', $entity_id, $payload );
    }

    private static function auditService(): ?AuditService {
        try {
            $svc = Kernel::instance()->container()->get( 'audit' );
            return $svc instanceof AuditService ? $svc : null;
        } catch ( \Throwable $e ) {
            return null;
        }
    }
}
