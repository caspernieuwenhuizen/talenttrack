<?php
namespace TT\Modules\Players\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerPotentialRepository (#0057 Sprint 1) — `tt_player_potential`.
 *
 * Trainer's stated belief about how high the player can reach. Stored
 * as history (one row per change) so the trajectory is preserved;
 * `latestFor()` returns the most-recent row for the calculator.
 * Append-only.
 */
final class PlayerPotentialRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_potential';
    }

    /**
     * @param array{player_id:int,set_at?:string,set_by?:int,potential_band:string,notes?:string} $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
            'club_id'        => CurrentClub::id(),
            'player_id'      => (int) $data['player_id'],
            'set_at'         => (string) ( $data['set_at'] ?? current_time( 'mysql' ) ),
            'set_by'         => (int) ( $data['set_by'] ?? get_current_user_id() ),
            'potential_band' => (string) $data['potential_band'],
            'notes'          => isset( $data['notes'] ) ? (string) $data['notes'] : null,
        ];
        $wpdb->insert( $this->table, $row );
        return (int) $wpdb->insert_id;
    }

    /**
     * Most recent potential row for a player, or null if never set.
     */
    public function latestFor( int $player_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id = %d AND club_id = %d
              ORDER BY set_at DESC, id DESC
              LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Full history for a player, newest first.
     *
     * @return list<object>
     */
    public function historyFor( int $player_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id = %d AND club_id = %d
              ORDER BY set_at DESC, id DESC",
            $player_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
