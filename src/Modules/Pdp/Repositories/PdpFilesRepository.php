<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PdpFilesRepository — one row per (player, season).
 *
 * Files own conversations + at-most-one verdict. cycle_size is the
 * effective count of conversations, derived from the team override
 * → club default at create time.
 */
class PdpFilesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_pdp_files';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d",
            $id
        ) );
        return $row ?: null;
    }

    /** @return object|null */
    public function findByPlayerSeason( int $player_id, int $season_id ): ?object {
        if ( $player_id <= 0 || $season_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE player_id = %d AND season_id = %d",
            $player_id, $season_id
        ) );
        return $row ?: null;
    }

    /** @return object[] */
    public function listForCoach( int $coach_user_id, int $season_id ): array {
        if ( $coach_user_id <= 0 || $season_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE owner_coach_id = %d AND season_id = %d
              ORDER BY updated_at DESC",
            $coach_user_id, $season_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return object[] */
    public function listForSeason( int $season_id ): array {
        if ( $season_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE season_id = %d ORDER BY updated_at DESC",
            $season_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array{
     *   player_id:int, season_id:int,
     *   owner_coach_id?:int|null,
     *   cycle_size?:int|null,
     *   notes?:string,
     * } $data
     * @return int Inserted ID, or 0 on failure or duplicate.
     */
    public function create( array $data ): int {
        $player_id = (int) ( $data['player_id'] ?? 0 );
        $season_id = (int) ( $data['season_id'] ?? 0 );
        if ( $player_id <= 0 || $season_id <= 0 ) return 0;

        if ( $this->findByPlayerSeason( $player_id, $season_id ) ) return 0;

        $ok = $this->wpdb->insert( $this->table, [
            'player_id'      => $player_id,
            'season_id'      => $season_id,
            'owner_coach_id' => isset( $data['owner_coach_id'] ) ? (int) $data['owner_coach_id'] : null,
            'cycle_size'     => isset( $data['cycle_size'] ) ? (int) $data['cycle_size'] : null,
            'status'         => 'open',
            'notes'          => isset( $data['notes'] ) ? (string) $data['notes'] : null,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function setStatus( int $file_id, string $status ): bool {
        if ( $file_id <= 0 || ! in_array( $status, [ 'open', 'completed', 'archived' ], true ) ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'status' => $status ],
            [ 'id'     => $file_id ]
        );
        return $ok !== false;
    }

    public function setOwner( int $file_id, ?int $coach_user_id ): bool {
        if ( $file_id <= 0 ) return false;
        $ok = $this->wpdb->update(
            $this->table,
            [ 'owner_coach_id' => $coach_user_id !== null ? (int) $coach_user_id : null ],
            [ 'id' => $file_id ]
        );
        return $ok !== false;
    }
}
