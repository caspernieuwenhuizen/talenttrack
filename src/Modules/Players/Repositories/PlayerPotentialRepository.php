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
        return $this->latestForMany( [ $player_id ] )[ $player_id ] ?? null;
    }

    /**
     * #4945 — `latestFor()` for many players in one statement: each player's
     * most recent row (`set_at`, then `id`, newest first). A player never
     * set is absent.
     *
     * @param list<int> $player_ids
     * @return array<int, object>
     */
    public function latestForMany( array $player_ids ): array {
        $ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ), static fn( int $id ): bool => $id > 0 ) ) );
        if ( $ids === [] ) return [];

        global $wpdb;
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE player_id IN ({$in}) AND club_id = %d
              ORDER BY player_id ASC, set_at DESC, id DESC",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ) );
        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $r ) {
            if ( ! is_object( $r ) ) continue;
            $pid = (int) ( $r->player_id ?? 0 );
            if ( ! isset( $out[ $pid ] ) ) $out[ $pid ] = $r;
        }
        return $out;
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
