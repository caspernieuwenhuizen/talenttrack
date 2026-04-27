<?php
namespace TT\Modules\Invitations;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * InvitationsRepository — CRUD over `tt_invitations`.
 *
 * Status transitions go through dedicated methods so the audit logger
 * + `tt_invitation_*` action hooks fire from a single chokepoint.
 */
class InvitationsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_invitations';
    }

    /** @param array<string,mixed> $data */
    public function insert( array $data ): int {
        $defaults = [
            'kind'           => InvitationKind::PLAYER,
            'status'         => InvitationStatus::PENDING,
            'created_by'     => get_current_user_id(),
        ];
        $row = array_merge( $defaults, $data );
        $this->wpdb->insert( $this->table, $row );
        return (int) $this->wpdb->insert_id;
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE id = %d", $id )
        );
        return $row ?: null;
    }

    public function findByToken( string $token ): ?object {
        if ( $token === '' ) return null;
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare( "SELECT * FROM {$this->table} WHERE token = %s", $token )
        );
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
        return $ok !== false;
    }

    /**
     * Atomically flip an invitation from `pending` to `accepted`.
     * Returns the affected row count — caller treats >0 as "I won the
     * accept race" and proceeds with WP user creation + linking. Two
     * simultaneous accept clicks can never both succeed.
     */
    public function claimForAcceptance( int $id, int $userId ): bool {
        $now = current_time( 'mysql' );
        $affected = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET status = %s, accepted_at = %s, accepted_user_id = %d
              WHERE id = %d AND status = %s",
            InvitationStatus::ACCEPTED,
            $now,
            $userId,
            $id,
            InvitationStatus::PENDING
        ) );
        return is_int( $affected ) && $affected > 0;
    }

    /** @return list<object> */
    public function listAll( int $limit = 200, ?string $status = null ): array {
        if ( $status !== null ) {
            $rows = $this->wpdb->get_results( $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
                $status,
                $limit
            ) );
        } else {
            $rows = $this->wpdb->get_results( $this->wpdb->prepare(
                "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
                $limit
            ) );
        }
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Pending invitation matching a target row + kind, if one exists
     * and hasn't expired. Used to avoid generating a second invite
     * when one is already in flight.
     */
    public function findPendingFor( string $kind, ?int $playerId, ?int $personId ): ?object {
        $now = current_time( 'mysql' );
        if ( $kind === InvitationKind::STAFF && $personId !== null ) {
            $row = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE status = %s AND kind = %s
                    AND target_person_id = %d AND expires_at > %s
                  ORDER BY created_at DESC LIMIT 1",
                InvitationStatus::PENDING,
                InvitationKind::STAFF,
                $personId,
                $now
            ) );
            return $row ?: null;
        }
        if ( $playerId !== null ) {
            $row = $this->wpdb->get_row( $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                  WHERE status = %s AND kind = %s
                    AND target_player_id = %d AND expires_at > %s
                  ORDER BY created_at DESC LIMIT 1",
                InvitationStatus::PENDING,
                $kind,
                $playerId,
                $now
            ) );
            return $row ?: null;
        }
        return null;
    }

    /**
     * Count invitations created by a user in the last 24 hours.
     * Used by the rate-limiter.
     */
    public function countCreatedByUserSince( int $userId, string $since ): int {
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table}
              WHERE created_by = %d AND created_at >= %s",
            $userId,
            $since
        ) );
    }

    /**
     * Sweep expired pending rows up to `expired` so the admin list
     * doesn't show stale "Pending" entries. Called from the
     * acceptance handler + admin list render. Idempotent.
     *
     * @return int rows updated
     */
    public function sweepExpired(): int {
        $now = current_time( 'mysql' );
        $affected = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET status = %s
              WHERE status = %s AND expires_at <= %s",
            InvitationStatus::EXPIRED,
            InvitationStatus::PENDING,
            $now
        ) );
        return is_int( $affected ) ? $affected : 0;
    }
}
