<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PairingsRepository — coach-marked "always start these two together"
 * notes per team. Sprint 4 of #0018.
 *
 * Storage: tt_team_chemistry_pairings, unique on (team_id, player_a_id,
 * player_b_id). The repository normalizes pairs (smaller id first) so
 * (A=10, B=20) and (A=20, B=10) collapse to one row.
 */
class PairingsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_team_chemistry_pairings';
    }

    /** @return list<array{id:int, team_id:int, player_a_id:int, player_b_id:int, note:?string, created_by:?int, created_at:string}> */
    public function listForTeam( int $team_id ): array {
        if ( $team_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE team_id = %d ORDER BY created_at DESC",
            $team_id
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [
                'id'           => (int) $r->id,
                'team_id'      => (int) $r->team_id,
                'player_a_id'  => (int) $r->player_a_id,
                'player_b_id'  => (int) $r->player_b_id,
                'note'         => $r->note,
                'created_by'   => $r->created_by !== null ? (int) $r->created_by : null,
                'created_at'   => (string) $r->created_at,
            ];
        }
        return $out;
    }

    /** @return list<int> player ids paired with `$player_id` on the given team. */
    public function partnersFor( int $team_id, int $player_id ): array {
        if ( $team_id <= 0 || $player_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT player_a_id, player_b_id FROM {$this->table}
              WHERE team_id = %d AND ( player_a_id = %d OR player_b_id = %d )",
            $team_id, $player_id, $player_id
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = (int) $r->player_a_id === $player_id ? (int) $r->player_b_id : (int) $r->player_a_id;
        }
        return $out;
    }

    public function add( int $team_id, int $player_a_id, int $player_b_id, ?string $note, ?int $created_by ): int {
        if ( $team_id <= 0 || $player_a_id <= 0 || $player_b_id <= 0 || $player_a_id === $player_b_id ) return 0;
        [ $a, $b ] = self::normalize( $player_a_id, $player_b_id );

        $existing = (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT id FROM {$this->table} WHERE team_id = %d AND player_a_id = %d AND player_b_id = %d",
            $team_id, $a, $b
        ) );
        if ( $existing > 0 ) return $existing;

        $ok = $this->wpdb->insert( $this->table, [
            'team_id'     => $team_id,
            'player_a_id' => $a,
            'player_b_id' => $b,
            'note'        => $note !== null && $note !== '' ? $note : null,
            'created_by'  => $created_by,
        ] );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function remove( int $id ): bool {
        if ( $id <= 0 ) return false;
        $ok = $this->wpdb->delete( $this->table, [ 'id' => $id ] );
        return $ok !== false;
    }

    /** @return array{0:int, 1:int} */
    private static function normalize( int $a, int $b ): array {
        return $a < $b ? [ $a, $b ] : [ $b, $a ];
    }
}
