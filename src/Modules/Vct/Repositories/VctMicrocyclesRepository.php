<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctMicrocyclesRepository — weekly aggregates per team. Slim CRUD;
 * primary writer is the nightly task (VCT-7).
 */
class VctMicrocyclesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_microcycles';
    }

    /** @return array<string,mixed>|null */
    public function findForWeek( int $team_id, string $week_starts_on ): ?array {
        if ( $team_id <= 0 ) return null;
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $week_starts_on ) ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND team_id = %d AND week_starts_on = %s
              LIMIT 1",
            CurrentClub::id(), $team_id, $week_starts_on
        ) );
        if ( ! $row ) return null;
        return [
            'id'                => (int) $row->id,
            'team_id'           => (int) $row->team_id,
            'week_starts_on'    => (string) $row->week_starts_on,
            'match_date'        => $row->match_date !== null ? (string) $row->match_date : null,
            'total_load_target' => (int) $row->total_load_target,
            'total_load_actual' => (int) $row->total_load_actual,
            'notes'             => (string) ( $row->notes ?? '' ),
        ];
    }

    public function upsert( int $team_id, string $week_starts_on, ?string $match_date, int $total_load_target, int $total_load_actual, string $notes = '' ): bool {
        $existing = $this->findForWeek( $team_id, $week_starts_on );
        $data = [
            'match_date'        => $match_date,
            'total_load_target' => max( 0, $total_load_target ),
            'total_load_actual' => max( 0, $total_load_actual ),
            'notes'             => $notes,
        ];
        if ( $existing ) {
            $ok = $this->wpdb->update(
                $this->table,
                $data,
                [ 'id' => $existing['id'], 'club_id' => CurrentClub::id() ]
            );
            return $ok !== false;
        }
        $ok = $this->wpdb->insert( $this->table, array_merge( $data, [
            'club_id'        => CurrentClub::id(),
            'team_id'        => $team_id,
            'week_starts_on' => $week_starts_on,
        ] ) );
        return $ok !== false;
    }
}
