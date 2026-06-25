<?php
namespace TT\Modules\Measurements\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MeasurementTargetsRepository (#1856).
 *
 * Per-age-group target bands for a definition. A recorded value is flagged
 * green / amber / red against the band for the player's age group. The
 * band-resolution logic lives here (a repository/service), NOT in a view —
 * the REST controller and the PHP view both call flagFor() so a future
 * SaaS front end gets the same answer (CLAUDE.md §4).
 *
 * Flag values: 'ok' (green) · 'warn' (amber) · 'bad' (red) · '' (no target).
 */
class MeasurementTargetsRepository {

    /**
     * The target row for one definition + age group, or null.
     */
    public function forDefinitionAndAge( int $definition_id, string $age_group ): ?object {
        if ( $definition_id <= 0 || $age_group === '' ) return null;
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_targets
              WHERE definition_id = %d AND age_group = %s
                AND club_id = %d AND archived_at IS NULL LIMIT 1",
            $definition_id, $age_group, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @return array<int, object>
     */
    public function listForDefinition( int $definition_id ): array {
        if ( $definition_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_targets
              WHERE definition_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY age_group ASC",
            $definition_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Resolve a value to a flag against a target band.
     *
     * Bands (numeric line): red | amber | GREEN | amber | red. A value
     * inside [green_min, green_max] is green; inside the wider
     * [amber_min, amber_max] but outside green is amber; outside amber is
     * red. Missing band edges are treated as open (no bound on that side).
     *
     * `direction` does not change the band maths — bands are absolute — but
     * is accepted so callers can stay direction-aware for sorting/labels.
     *
     * @param float|null $value
     */
    public function flagFor( ?float $value, ?object $target, string $direction = 'higher' ): string {
        if ( $value === null || ! $target ) return '';

        $green_min = $this->num( $target->green_min ?? null );
        $green_max = $this->num( $target->green_max ?? null );
        $amber_min = $this->num( $target->amber_min ?? null );
        $amber_max = $this->num( $target->amber_max ?? null );

        $in_green = ( $green_min === null || $value >= $green_min )
                 && ( $green_max === null || $value <= $green_max );
        if ( $in_green && ( $green_min !== null || $green_max !== null ) ) {
            return 'ok';
        }

        $in_amber = ( $amber_min === null || $value >= $amber_min )
                 && ( $amber_max === null || $value <= $amber_max );
        if ( $in_amber && ( $amber_min !== null || $amber_max !== null ) ) {
            return 'warn';
        }

        // A target exists with at least one band edge, and the value sits
        // outside both bands → red. With no edges at all there's nothing
        // to flag.
        $has_any = $green_min !== null || $green_max !== null
                || $amber_min !== null || $amber_max !== null;
        return $has_any ? 'bad' : '';
    }

    /**
     * @param array<string, mixed> $data
     */
    public function upsert( int $definition_id, string $age_group, array $data ): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $existing = $this->forDefinitionAndAge( $definition_id, $age_group );
        $fields = [
            'green_min' => $this->numOrNull( $data['green_min'] ?? null ),
            'green_max' => $this->numOrNull( $data['green_max'] ?? null ),
            'amber_min' => $this->numOrNull( $data['amber_min'] ?? null ),
            'amber_max' => $this->numOrNull( $data['amber_max'] ?? null ),
            'updated_at' => current_time( 'mysql', true ),
        ];

        if ( $existing ) {
            $wpdb->update( "{$p}tt_measurement_targets", $fields, [ 'id' => (int) $existing->id, 'club_id' => CurrentClub::id() ] );
            return (int) $existing->id;
        }

        $wpdb->insert( "{$p}tt_measurement_targets", array_merge( $fields, [
            'club_id'       => CurrentClub::id(),
            'uuid'          => wp_generate_uuid4(),
            'definition_id' => $definition_id,
            'age_group'     => $age_group,
            'created_at'    => current_time( 'mysql', true ),
        ] ) );
        return (int) $wpdb->insert_id;
    }

    private function num( $v ): ?float {
        return $v === null || $v === '' ? null : (float) $v;
    }

    private function numOrNull( $v ): ?float {
        return $v === null || $v === '' ? null : (float) $v;
    }
}
