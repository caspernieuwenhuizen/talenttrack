<?php
namespace TT\Modules\Invitations\Notifications;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Invitations\InvitationsRepository;

/**
 * InvitationAuditLogger — subscribes to `tt_invitation_*` actions and
 * writes one row per event to the existing `tt_audit_log` table.
 *
 * Captures: created / accepted / revoked / cap_overridden. The
 * acceptance event additionally records IP + user-agent for forensics;
 * other events record actor + entity only.
 */
class InvitationAuditLogger {

    public static function register(): void {
        add_action( 'tt_invitation_created', [ self::class, 'onCreated' ], 10, 2 );
        add_action( 'tt_invitation_accepted', [ self::class, 'onAccepted' ], 10, 3 );
        add_action( 'tt_invitation_revoked', [ self::class, 'onRevoked' ], 10, 1 );
        add_action( 'tt_invitation_cap_overridden', [ self::class, 'onCapOverridden' ], 10, 3 );
    }

    public static function onCreated( int $id, string $kind ): void {
        self::log( 'invitation.created', $id, [ 'kind' => $kind ] );
    }

    public static function onAccepted( int $id, string $kind, int $userId ): void {
        $extra = [
            'kind'        => $kind,
            'user_id'     => $userId,
            'ip'          => self::ip(),
            'user_agent'  => self::userAgent(),
        ];
        self::log( 'invitation.accepted', $id, $extra );
    }

    public static function onRevoked( int $id ): void {
        self::log( 'invitation.revoked', $id, [] );
    }

    public static function onCapOverridden( int $userId, int $invitationId, string $reason ): void {
        self::log( 'invitation.cap_overridden', $invitationId, [
            'overridden_by_user_id' => $userId,
            'reason'                => $reason,
        ] );
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function log( string $event, int $entityId, array $context ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) return;

        $wpdb->insert( $table, [
            'user_id'     => get_current_user_id(),
            'action'      => $event,
            'entity_type' => 'invitation',
            'entity_id'   => $entityId,
            'payload'     => wp_json_encode( $context ),
            'ip_address'  => self::ip(),
            'created_at'  => current_time( 'mysql' ),
        ] );
    }

    private static function ip(): string {
        if ( ! empty( $_SERVER['REMOTE_ADDR'] ) ) {
            return (string) wp_unslash( $_SERVER['REMOTE_ADDR'] );
        }
        return '';
    }

    private static function userAgent(): string {
        if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
            return mb_substr( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ), 0, 255 );
        }
        return '';
    }
}
