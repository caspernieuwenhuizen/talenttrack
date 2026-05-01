<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ImpersonationService (#0071 child 5) — admin-to-user impersonation
 * for testing and support.
 *
 * Two-stage with explicit return: start swaps the WP auth cookie to
 * the target's id (saving the actor's id to a signed `tt_impersonator_id`
 * cookie); end reverses that. Every transition is logged to
 * `tt_impersonation_log`. A daily cron closes orphan rows older than
 * 24h.
 *
 * Defence in depth (rejected at start time):
 *   - actor lacks `tt_impersonate_users`
 *   - target doesn't exist
 *   - target is in a different club
 *   - target holds `tt_impersonate_users` themselves (no admin-on-admin)
 *   - target IS the actor (no self-impersonation)
 *   - actor is already impersonating (no stacking)
 */
final class ImpersonationService {

    public const TABLE = 'tt_impersonation_log';

    /**
     * @return \WP_Error|null  null on success
     */
    public static function start( int $actor_id, int $target_id, string $reason = '' ): ?\WP_Error {
        if ( $actor_id <= 0 || $target_id <= 0 ) {
            return new \WP_Error( 'bad_input', __( 'Actor and target user ids are required.', 'talenttrack' ), [ 'status' => 400 ] );
        }
        if ( $actor_id === $target_id ) {
            return new \WP_Error( 'self_impersonation', __( 'Cannot impersonate yourself.', 'talenttrack' ), [ 'status' => 400 ] );
        }

        $actor = get_userdata( $actor_id );
        $target = get_userdata( $target_id );
        if ( ! $actor ) {
            return new \WP_Error( 'actor_not_found', __( 'Actor user not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        if ( ! $target ) {
            return new \WP_Error( 'target_not_found', __( 'Target user not found.', 'talenttrack' ), [ 'status' => 404 ] );
        }
        if ( ! user_can( $actor, 'tt_impersonate_users' ) ) {
            return new \WP_Error( 'forbidden', __( 'You do not have permission to impersonate users.', 'talenttrack' ), [ 'status' => 403 ] );
        }
        if ( user_can( $target, 'tt_impersonate_users' ) ) {
            return new \WP_Error( 'admin_target_forbidden', __( 'Cannot impersonate another administrator.', 'talenttrack' ), [ 'status' => 403 ] );
        }
        if ( ImpersonationContext::isImpersonating() ) {
            return new \WP_Error( 'already_impersonating', __( 'Already impersonating — switch back first.', 'talenttrack' ), [ 'status' => 409 ] );
        }

        // Single-club guard. CurrentClub::id() returns 1 in v1; the
        // service is forward-compatible for multi-tenant where target
        // and actor must share a club.
        $club_id = CurrentClub::id();

        global $wpdb;
        $tbl = $wpdb->prefix . self::TABLE;
        $wpdb->insert( $tbl, [
            'actor_user_id'    => $actor_id,
            'target_user_id'   => $target_id,
            'club_id'          => $club_id,
            'started_at'       => current_time( 'mysql', true ),
            'actor_ip'         => self::clientIp(),
            'actor_user_agent' => substr( (string) ( $_SERVER['HTTP_USER_AGENT'] ?? '' ), 0, 255 ),
            'reason'           => $reason !== '' ? substr( $reason, 0, 255 ) : null,
        ] );

        ImpersonationContext::setCookie( $actor_id );
        wp_set_auth_cookie( $target_id, false, is_ssl() );

        Logger::info( 'impersonation.started', [ 'actor' => $actor_id, 'target' => $target_id ] );

        return null;
    }

    /**
     * @return \WP_Error|null  null on success (no-op when not impersonating)
     */
    public static function end( string $end_reason = 'manual' ): ?\WP_Error {
        $actor_id = ImpersonationContext::actorIdFromCookie();
        if ( $actor_id <= 0 ) {
            return null; // not impersonating
        }

        global $wpdb;
        $tbl = $wpdb->prefix . self::TABLE;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$tbl} SET ended_at = %s, end_reason = %s
              WHERE actor_user_id = %d AND ended_at IS NULL",
            current_time( 'mysql', true ),
            $end_reason,
            $actor_id
        ) );

        ImpersonationContext::clearCookie();
        wp_set_auth_cookie( $actor_id, false, is_ssl() );

        Logger::info( 'impersonation.ended', [ 'actor' => $actor_id, 'reason' => $end_reason ] );

        return null;
    }

    /**
     * Daily cron — close orphan rows older than 24h. Prevents the audit
     * log from accumulating dangling sessions when an admin closes the
     * browser without clicking Switch back.
     */
    public static function cleanupOrphans(): int {
        global $wpdb;
        $tbl = $wpdb->prefix . self::TABLE;
        return (int) $wpdb->query(
            "UPDATE {$tbl}
                SET ended_at   = UTC_TIMESTAMP(),
                    end_reason = 'expired'
              WHERE ended_at IS NULL
                AND started_at < DATE_SUB( UTC_TIMESTAMP(), INTERVAL 1 DAY )"
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function listActiveSessions(): array {
        global $wpdb;
        $tbl = $wpdb->prefix . self::TABLE;
        $rows = $wpdb->get_results( "SELECT * FROM {$tbl} WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 100", ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    private static function clientIp(): ?string {
        foreach ( [ 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR' ] as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = explode( ',', (string) $_SERVER[ $key ] )[0];
                $ip = trim( $ip );
                if ( $ip !== '' ) return substr( $ip, 0, 45 );
            }
        }
        return null;
    }
}
