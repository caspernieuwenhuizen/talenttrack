<?php
namespace TT\Modules\Strava;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityRepository (#2058) — player-scoped imported-activity store.
 *
 * Owns `tt_player_activities`. These are an athlete's OWN training
 * entries (runs, rides, conditioning), distinct from `tt_activities`
 * (team sessions). Rows are upserted by `(club_id, source, external_id)`
 * so a re-pushed Strava activity updates in place, and soft-archived
 * (never hard-deleted) when an activity is deleted in Strava or the
 * athlete deauthorizes.
 *
 * There is deliberately NO heart-rate / biometric column — Gate 1. The
 * mapping that feeds this repository never reads HR fields.
 *
 * Every query is `club_id`-scoped (CLAUDE.md §4).
 */
final class ActivityRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_player_activities';
    }

    /**
     * Create or update an imported activity by its external id. Returns
     * the row id. `$data` carries only the mapped non-HR fields.
     *
     * @param array<string,mixed> $data
     */
    public function upsert( int $player_id, string $source, string $external_id, array $data ): int {
        global $wpdb;
        $now = current_time( 'mysql' );

        $fields = [
            'activity_type'          => $data['activity_type'] ?? null,
            'name'                   => $data['name'] ?? null,
            'started_at'             => $data['started_at'] ?? null,
            'distance_m'             => $data['distance_m'] ?? null,
            'moving_time_s'          => $data['moving_time_s'] ?? null,
            'elapsed_time_s'         => $data['elapsed_time_s'] ?? null,
            'average_speed_ms'       => $data['average_speed_ms'] ?? null,
            'total_elevation_gain_m' => $data['total_elevation_gain_m'] ?? null,
            'updated_at'             => $now,
            // Re-surface a previously archived row if the activity is back.
            'archived_at'            => null,
            'archived_by'            => null,
        ];

        $existing = $this->findByExternalId( $player_id, $source, $external_id );
        if ( $existing ) {
            $wpdb->update( $this->table(), $fields, [ 'id' => (int) $existing->id ] );
            return (int) $existing->id;
        }

        $fields['player_id']   = $player_id;
        $fields['club_id']     = CurrentClub::id();
        $fields['uuid']        = wp_generate_uuid4();
        $fields['source']      = $source;
        $fields['external_id'] = $external_id;
        $fields['created_at']  = $now;
        $wpdb->insert( $this->table(), $fields );
        return (int) $wpdb->insert_id;
    }

    public function findByExternalId( int $player_id, string $source, string $external_id ): ?object {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE player_id = %d AND source = %s AND external_id = %s AND club_id = %d
              LIMIT 1",
            $player_id, $source, $external_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * List a player's non-archived imported activities, newest first.
     *
     * @return object[]
     */
    public function listForPlayer( int $player_id, int $limit = 50 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY started_at DESC, id DESC
              LIMIT %d",
            $player_id, CurrentClub::id(), $limit
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** Soft-archive one imported activity by external id. */
    public function archiveByExternalId( int $player_id, string $source, string $external_id ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ) ],
            [ 'player_id' => $player_id, 'source' => $source, 'external_id' => $external_id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Soft-archive every imported activity for a player (deauth / full
     * disconnect). Returns the number of rows archived.
     */
    public function archiveAllForPlayer( int $player_id, string $source ): int {
        global $wpdb;
        $n = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()}
                SET archived_at = %s, updated_at = %s
              WHERE player_id = %d AND source = %s AND club_id = %d AND archived_at IS NULL",
            current_time( 'mysql' ), current_time( 'mysql' ),
            $player_id, $source, CurrentClub::id()
        ) );
        return (int) $n;
    }
}
