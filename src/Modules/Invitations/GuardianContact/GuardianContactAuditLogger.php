<?php
namespace TT\Modules\Invitations\GuardianContact;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GuardianContactAuditLogger (#3794) — what was asked of a family, and
 * what they changed.
 *
 * The write this logs is unauthenticated and irreversible without it: a
 * family answers on a link and two columns on a child's record move. The
 * audit entry records the previous value of every field that changed,
 * which is what makes the write **revertable** — an admin reading the
 * trail can see what stood before and put it back.
 *
 * Both entries are filed against the **player**, not the invitation row,
 * so they surface where someone looking into a child's record will find
 * them. The request id travels in the payload.
 *
 * Follows `Invitations\Notifications\InvitationAuditLogger`.
 */
final class GuardianContactAuditLogger {

    public static function register(): void {
        add_action( 'tt_guardian_contact_requested', [ self::class, 'onRequested' ], 10, 3 );
        add_action( 'tt_guardian_contact_submitted', [ self::class, 'onSubmitted' ], 10, 3 );
    }

    public static function onRequested( int $request_id, int $player_id, string $email ): void {
        self::log( 'guardian_contact.requested', $player_id, [
            'request_id' => $request_id,
            'sent_to'    => $email,
        ] );
    }

    /**
     * @param array<string,array{from:string,to:string}> $changes
     */
    public static function onSubmitted( int $request_id, int $player_id, array $changes ): void {
        self::log( 'guardian_contact.submitted', $player_id, [
            'request_id' => $request_id,
            // The old value of each field, so the write can be undone.
            'changes'    => $changes,
            'consent'    => true,
            'user_agent' => self::userAgent(),
        ] );
    }

    /**
     * @param array<string,mixed> $context
     */
    private static function log( string $event, int $player_id, array $context ): void {
        global $wpdb;
        $table  = $wpdb->prefix . 'tt_audit_log';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) return;

        $wpdb->insert( $table, [
            'club_id'     => CurrentClub::id(),
            // Zero on the family's submission: nobody was logged in, which
            // is the point of the link. The IP and the request id are what
            // identify the writer there.
            'user_id'     => get_current_user_id(),
            'action'      => $event,
            'entity_type' => 'player',
            'entity_id'   => $player_id,
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
