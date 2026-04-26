<?php
namespace TT\Modules\Development;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * IdeaRepository — CRUD helpers around `tt_dev_ideas`.
 *
 * Thin wrapper over `$wpdb` so the rest of the module — submission UI,
 * refinement list, kanban, GitHub promoter — never composes raw SQL.
 * Status transitions go through dedicated methods so author-facing
 * notifications can hook into a single chokepoint.
 */
class IdeaRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_dev_ideas';
    }

    /** @param array<string,mixed> $data */
    public function insert( array $data ): int {
        $defaults = [
            'title'          => '',
            'body'           => '',
            'slug'           => '',
            'type'           => IdeaType::NEEDS_TRIAGE,
            'status'         => IdeaStatus::SUBMITTED,
            'author_user_id' => get_current_user_id(),
            'player_id'      => null,
            'team_id'        => null,
            'track_id'       => null,
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

    /**
     * @param array<string,mixed> $data
     */
    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->update( $this->table, $data, [ 'id' => $id ] );
        return $ok !== false;
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        return (bool) $this->wpdb->delete( $this->table, [ 'id' => $id ] );
    }

    /**
     * Move an idea to a new status. Captures the side-effect fields
     * (refined_by, promoted_at, rejection_note, etc.) according to the
     * destination status.
     *
     * @param array<string,mixed> $extra Extra column writes (e.g. rejection_note).
     */
    public function transition( int $id, string $newStatus, array $extra = [] ): bool {
        $data = array_merge( [ 'status' => $newStatus ], $extra );

        if ( in_array( $newStatus, [ IdeaStatus::REFINING, IdeaStatus::READY_FOR_APPROVAL ], true ) ) {
            $data['refined_at'] = current_time( 'mysql' );
            $data['refined_by'] = get_current_user_id();
        }
        if ( $newStatus === IdeaStatus::PROMOTED ) {
            $data['promoted_at'] = current_time( 'mysql' );
        }

        $ok = $this->update( $id, $data );
        if ( $ok ) {
            do_action( 'tt_dev_idea_status_changed', $id, $newStatus );
        }
        return $ok;
    }

    /** @return list<object> */
    public function listByStatus( string $status, int $limit = 200 ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at DESC LIMIT %d",
            $status,
            $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return list<object> */
    public function listAll( int $limit = 500 ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} ORDER BY created_at DESC LIMIT %d",
            $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return list<object> */
    public function listByAuthor( int $userId, int $limit = 100 ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE author_user_id = %d ORDER BY created_at DESC LIMIT %d",
            $userId,
            $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return list<object> */
    public function listByTrack( int $trackId ): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE track_id = %d ORDER BY created_at DESC",
            $trackId
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<string,int> status => count */
    public function countByStatus(): array {
        $rows = $this->wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$this->table} GROUP BY status"
        );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r->status ] = (int) $r->n;
        }
        return $out;
    }

    /**
     * Atomic claim of a `ready-for-approval` row for promotion. Returns
     * the affected row count — the caller treats >0 as "I won the
     * lock". Prevents two simultaneous "promote" clicks both calling
     * the GitHub API.
     */
    public function claimForPromotion( int $id ): bool {
        $now = current_time( 'mysql' );
        $affected = $this->wpdb->query( $this->wpdb->prepare(
            "UPDATE {$this->table}
                SET status = %s, refined_by = %d, refined_at = %s
              WHERE id = %d AND status = %s",
            IdeaStatus::PROMOTING,
            get_current_user_id(),
            $now,
            $id,
            IdeaStatus::READY_FOR_APPROVAL
        ) );
        return is_int( $affected ) && $affected > 0;
    }
}
