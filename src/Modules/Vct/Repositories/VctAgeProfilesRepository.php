<?php
namespace TT\Modules\Vct\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * VctAgeProfilesRepository — reads + writes per-club workload envelope
 * per age group. Seed lands via migration 0125.
 *
 * Every query filters by `club_id = CurrentClub::id()` (tenancy guarantee).
 */
class VctAgeProfilesRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_vct_age_profiles';
    }

    /**
     * @return array{
     *   id:int, age_group:string, session_minutes_max:int, intensity_band_max:int,
     *   md_logic_enabled:bool, min_recovery_hours_between_high:int,
     *   growth_spurt_load_reduction_pct:int, match_load_multiplier_per_minute:float,
     *   weekly_load_envelope:int
     * }|null
     */
    public function findByAgeGroup( string $age_group ): ?array {
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id, age_group, session_minutes_max, intensity_band_max,
                    md_logic_enabled, min_recovery_hours_between_high,
                    growth_spurt_load_reduction_pct, match_load_multiplier_per_minute,
                    weekly_load_envelope
               FROM {$this->table}
              WHERE club_id = %d AND age_group = %s
              LIMIT 1",
            CurrentClub::id(), $age_group
        ) );
        if ( ! $row ) return null;
        return $this->hydrate( $row );
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array {
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT id, age_group, session_minutes_max, intensity_band_max,
                    md_logic_enabled, min_recovery_hours_between_high,
                    growth_spurt_load_reduction_pct, match_load_multiplier_per_minute,
                    weekly_load_envelope
               FROM {$this->table}
              WHERE club_id = %d
              ORDER BY age_group ASC",
            CurrentClub::id()
        ) );
        if ( ! is_array( $rows ) ) return [];
        return array_map( [ $this, 'hydrate' ], $rows );
    }

    /**
     * Partial update. Accepts a sparse $patch of editable fields;
     * unknown keys are ignored. Returns true on success.
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        $allowed = [
            'session_minutes_max'              => '%d',
            'intensity_band_max'               => '%d',
            'md_logic_enabled'                 => '%d',
            'min_recovery_hours_between_high'  => '%d',
            'growth_spurt_load_reduction_pct'  => '%d',
            'match_load_multiplier_per_minute' => '%f',
            'weekly_load_envelope'             => '%d',
        ];
        $set = [];
        $formats = [];
        foreach ( $allowed as $key => $fmt ) {
            if ( array_key_exists( $key, $patch ) ) {
                $set[ $key ] = $patch[ $key ];
                $formats[] = $fmt;
            }
        }
        if ( ! $set ) return true;
        $where_formats = [ '%d', '%d' ];
        $ok = $this->wpdb->update(
            $this->table,
            $set,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ],
            $formats,
            $where_formats
        );
        return $ok !== false;
    }

    /** @param object $row */
    private function hydrate( $row ): array {
        return [
            'id'                              => (int)    $row->id,
            'age_group'                       => (string) $row->age_group,
            'session_minutes_max'             => (int)    $row->session_minutes_max,
            'intensity_band_max'              => (int)    $row->intensity_band_max,
            'md_logic_enabled'                => (bool)   ( (int) $row->md_logic_enabled ),
            'min_recovery_hours_between_high' => (int)    $row->min_recovery_hours_between_high,
            'growth_spurt_load_reduction_pct' => (int)    $row->growth_spurt_load_reduction_pct,
            'match_load_multiplier_per_minute'=> (float)  $row->match_load_multiplier_per_minute,
            'weekly_load_envelope'            => (int)    $row->weekly_load_envelope,
        ];
    }
}
