<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctTeamSchedulesRepository — per-team weekly preferences.
 *
 * `weekdays_bitmask` is 7 bits: bit 0 = Monday, bit 6 = Sunday.
 * Operator edits via the inline team-detail panel (Phase 2 UI).
 */
class VctTeamSchedulesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_team_schedules';
    }

    /**
     * @return array{id:int, team_id:int, season_id:int, weekdays_bitmask:int, default_start_time:?string, default_duration_minutes:?int}|null
     */
    public function findForTeamSeason( int $team_id, int $season_id ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id, team_id, season_id, weekdays_bitmask, default_start_time, default_duration_minutes
               FROM {$this->table}
              WHERE club_id = %d AND team_id = %d AND season_id = %d
                AND archived_at IS NULL
              LIMIT 1",
            CurrentClub::id(), $team_id, $season_id
        ) );
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /**
     * Upsert: insert new schedule or update existing one in place.
     */
    public function upsert( int $team_id, int $season_id, int $weekdays_bitmask, ?string $default_start_time, ?int $default_duration_minutes, int $updated_by ): bool {
        $existing = $this->findForTeamSeason( $team_id, $season_id );
        if ( $existing ) {
            $ok = $this->wpdb->update(
                $this->table,
                [
                    'weekdays_bitmask'         => $weekdays_bitmask,
                    'default_start_time'       => $default_start_time,
                    'default_duration_minutes' => $default_duration_minutes,
                    'updated_by'               => $updated_by,
                ],
                [ 'id' => $existing['id'], 'club_id' => CurrentClub::id() ]
            );
            return $ok !== false;
        }
        $ok = $this->wpdb->insert( $this->table, [
            'club_id'                  => CurrentClub::id(),
            'uuid'                     => wp_generate_uuid4(),
            'team_id'                  => $team_id,
            'season_id'                => $season_id,
            'weekdays_bitmask'         => $weekdays_bitmask,
            'default_start_time'       => $default_start_time,
            'default_duration_minutes' => $default_duration_minutes,
            'updated_by'               => $updated_by,
        ] );
        return $ok !== false;
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        return [
            'id'                       => (int)    $row->id,
            'team_id'                  => (int)    $row->team_id,
            'season_id'                => (int)    $row->season_id,
            'weekdays_bitmask'         => (int)    $row->weekdays_bitmask,
            'default_start_time'       => $row->default_start_time !== null ? (string) $row->default_start_time : null,
            'default_duration_minutes' => $row->default_duration_minutes !== null ? (int) $row->default_duration_minutes : null,
        ];
    }
}
