<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Vct\Rules\Providers\VctPhvFlagsProvider;

/**
 * VctPhvFlagsRepository — per-player Peak Height Velocity flag.
 *
 * `WorkloadCapRule` reads `activeForRoster()` and applies the configured
 * `growth_spurt_load_reduction_pct` to flagged players' load contribution.
 * Coaches flag (cap-gated `tt_vct_plan` with per-player scope); HoD/admin
 * clear.
 *
 * Implements the VctPhvFlagsProvider interface so rule passes can
 * inject the interface (testable with in-memory fakes) while
 * production code wires the repository.
 */
class VctPhvFlagsRepository implements VctPhvFlagsProvider {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_phv_flags';
    }

    /**
     * Return the player_ids in $roster_player_ids whose PHV flag is
     * currently active (`is_active = 1` and `cleared_at IS NULL`).
     *
     * @param list<int> $roster_player_ids
     * @return list<int>
     */
    public function activeForRoster( array $roster_player_ids ): array {
        if ( ! $roster_player_ids ) return [];
        $ids = array_values( array_filter( array_map( 'intval', $roster_player_ids ), static fn( int $n ): bool => $n > 0 ) );
        if ( ! $ids ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params       = array_merge( [ CurrentClub::id() ], $ids );

        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT player_id FROM {$this->table}
              WHERE club_id = %d
                AND is_active = 1
                AND cleared_at IS NULL
                AND player_id IN ({$placeholders})",
            $params
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( 'intval', $rows );
    }

    /**
     * Upsert the flag for a single player. $is_active=true flags the
     * player (sets flagged_at, flagged_by, clears cleared_at);
     * $is_active=false clears the flag (sets cleared_at, cleared_by).
     */
    public function setFlag( int $player_id, bool $is_active, int $actor_user_id, string $notes = '' ): bool {
        $club_id = CurrentClub::id();
        $now     = current_time( 'mysql', true );

        $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE club_id = %d AND player_id = %d LIMIT 1",
            $club_id, $player_id
        ) );

        if ( $is_active ) {
            $data = [
                'is_active'  => 1,
                'flagged_at' => $now,
                'flagged_by' => $actor_user_id,
                'cleared_at' => null,
                'cleared_by' => null,
                'notes'      => $notes,
            ];
        } else {
            $data = [
                'is_active'  => 0,
                'cleared_at' => $now,
                'cleared_by' => $actor_user_id,
            ];
            if ( $notes !== '' ) $data['notes'] = $notes;
        }

        if ( $existing > 0 ) {
            $ok = $this->wpdb->update(
                $this->table,
                $data,
                [ 'id' => $existing, 'club_id' => $club_id ]
            );
            return $ok !== false;
        }
        $ok = $this->wpdb->insert( $this->table, array_merge( $data, [
            'club_id'   => $club_id,
            'player_id' => $player_id,
        ] ) );
        return $ok !== false;
    }
}
