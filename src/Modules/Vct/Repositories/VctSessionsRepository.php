<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctSessionsRepository — root sessions table.
 *
 * `delete()` is transactional: it removes the session row AND its child
 * blocks in a single transaction so the no-DB-CASCADE convention (per
 * spec § Schema) doesn't leave orphan blocks. Integration coverage for
 * this lands with the engine's tests.
 */
class VctSessionsRepository {

    private \wpdb $wpdb;
    private string $table;
    private string $blocks_table;

    public function __construct() {
        global $wpdb;
        $this->wpdb         = $wpdb;
        $this->table        = $wpdb->prefix . 'tt_vct_sessions';
        $this->blocks_table = $wpdb->prefix . 'tt_vct_session_blocks';
    }

    /**
     * Create a session row. Returns the new id or 0 on failure.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        $row = array_merge( [
            'club_id'                 => CurrentClub::id(),
            'uuid'                    => wp_generate_uuid4(),
            'status'                  => 'draft',
            'generated_at'            => current_time( 'mysql', true ),
            'total_load'              => 0,
        ], $data );

        $ok = $this->wpdb->insert( $this->table, $row );
        if ( $ok === false ) return 0;
        return (int) $this->wpdb->insert_id;
    }

    /** @return array<string,mixed>|null */
    public function find( int $id ): ?array {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE club_id = %d AND id = %d LIMIT 1",
            CurrentClub::id(), $id
        ) );
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listForTeam( int $team_id, ?string $status = null, int $limit = 50 ): array {
        $sql = "SELECT * FROM {$this->table} WHERE club_id = %d AND team_id = %d";
        $params = [ CurrentClub::id(), $team_id ];
        if ( $status !== null ) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }
        $sql .= " ORDER BY session_date DESC, id DESC LIMIT %d";
        $params[] = max( 1, min( 200, $limit ) );

        $rows = $this->wpdb->get_results( $this->wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /**
     * Update status + timestamps as a session moves through its lifecycle.
     * Status enum is `draft|published|completed|archived`.
     */
    public function updateStatus( int $id, string $status, ?int $bound_activity_id = null ): bool {
        $now = current_time( 'mysql', true );
        $data = [ 'status' => $status ];
        switch ( $status ) {
            case 'published': $data['published_at'] = $now; break;
            case 'completed': $data['completed_at'] = $now; break;
            case 'archived':  $data['archived_at']  = $now; break;
        }
        if ( $bound_activity_id !== null ) {
            $data['activity_id'] = $bound_activity_id > 0 ? $bound_activity_id : null;
        }
        $ok = $this->wpdb->update(
            $this->table,
            $data,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Recompute + persist total_load after the engine swaps a block or
     * reruns validate().
     */
    public function updateTotalLoad( int $id, int $total_load ): bool {
        $ok = $this->wpdb->update(
            $this->table,
            [ 'total_load' => max( 0, $total_load ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Transactional delete: removes child blocks first, then the
     * session row. Returns true if both succeeded. No DB CASCADE per
     * codebase convention; cleanup is the repository's responsibility.
     */
    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        $club_id = CurrentClub::id();

        $this->wpdb->query( 'START TRANSACTION' );
        $this->wpdb->delete( $this->blocks_table, [ 'club_id' => $club_id, 'vct_session_id' => $id ] );
        $ok = $this->wpdb->delete( $this->table, [ 'club_id' => $club_id, 'id' => $id ] );

        if ( $ok === false || $ok === 0 ) {
            $this->wpdb->query( 'ROLLBACK' );
            return false;
        }
        $this->wpdb->query( 'COMMIT' );
        return true;
    }

    /**
     * Find sessions a player belongs to (via their team) that are
     * `published` or `completed` within the window. Used by
     * RecoveryRule + the nightly workload aggregator (VCT-7).
     *
     * @return list<array<string,mixed>>
     */
    public function listForPlayerWindow( int $player_id, string $window_start, string $window_end ): array {
        global $wpdb;
        $p_players = $wpdb->prefix . 'tt_players';
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT s.* FROM {$this->table} s
               JOIN {$p_players} pl ON pl.team_id = s.team_id
              WHERE s.club_id = %d
                AND pl.id = %d
                AND s.status IN ('published','completed')
                AND s.session_date BETWEEN %s AND %s
              ORDER BY s.session_date DESC",
            CurrentClub::id(), $player_id, $window_start, $window_end
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        return [
            'id'                     => (int)    $row->id,
            'uuid'                   => (string) $row->uuid,
            'team_id'                => (int)    $row->team_id,
            'activity_id'            => $row->activity_id !== null ? (int) $row->activity_id : null,
            'session_date'           => (string) $row->session_date,
            'start_time'             => $row->start_time !== null ? (string) $row->start_time : null,
            'age_group'              => (string) $row->age_group,
            'md_context'             => (string) $row->md_context,
            'tactical_theme'         => $row->tactical_theme !== null ? (string) $row->tactical_theme : null,
            'total_duration_minutes' => (int)    $row->total_duration_minutes,
            'total_load'             => (int)    $row->total_load,
            'coach_notes'            => (string) ( $row->coach_notes ?? '' ),
            'status'                 => (string) $row->status,
            'generated_by'           => (int)    $row->generated_by,
            'generated_at'           => (string) $row->generated_at,
            'published_at'           => $row->published_at !== null ? (string) $row->published_at : null,
            'completed_at'           => $row->completed_at !== null ? (string) $row->completed_at : null,
            'archived_at'            => $row->archived_at !== null ? (string) $row->archived_at : null,
        ];
    }
}
