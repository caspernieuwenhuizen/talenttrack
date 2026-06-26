<?php
namespace TT\Modules\TeamDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ChemistryPositionMatrixRepository (#1912) — the configurable Position
 * Relationship Matrix (default group-level weights seeded in 0178).
 * Phase 3 reads it to weight pairs; Phase 5's editor writes it.
 *
 * Pairs are symmetric: a weight stored for (a,b) also answers (b,a).
 */
class ChemistryPositionMatrixRepository {

    /**
     * @return array<int, object>
     */
    public function all(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, position_a, position_b, weight
               FROM {$p}tt_chemistry_position_matrix
              WHERE club_id = %d
              ORDER BY position_a ASC, position_b ASC",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Symmetric weight lookup for a position pair; null when unset.
     */
    public function weightFor( string $a, string $b ): ?float {
        if ( $a === '' || $b === '' ) return null;
        global $wpdb;
        $p = $wpdb->prefix;
        $val = $wpdb->get_var( $wpdb->prepare(
            "SELECT weight FROM {$p}tt_chemistry_position_matrix
              WHERE club_id = %d
                AND ( ( position_a = %s AND position_b = %s )
                   OR ( position_a = %s AND position_b = %s ) )
              LIMIT 1",
            CurrentClub::id(), $a, $b, $b, $a
        ) );
        return $val === null ? null : (float) $val;
    }

    public function upsert( string $a, string $b, float $weight ): bool {
        if ( $a === '' || $b === '' ) return false;
        $weight = max( 0.0, min( 1.0, $weight ) );
        global $wpdb;
        $p = $wpdb->prefix;

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_chemistry_position_matrix
              WHERE club_id = %d
                AND ( ( position_a = %s AND position_b = %s )
                   OR ( position_a = %s AND position_b = %s ) ) LIMIT 1",
            CurrentClub::id(), $a, $b, $b, $a
        ) );

        if ( $existing > 0 ) {
            return false !== $wpdb->update(
                "{$p}tt_chemistry_position_matrix",
                [ 'weight' => $weight, 'updated_at' => current_time( 'mysql', true ) ],
                [ 'id' => $existing, 'club_id' => CurrentClub::id() ]
            );
        }
        return false !== $wpdb->insert( "{$p}tt_chemistry_position_matrix", [
            'club_id'    => CurrentClub::id(),
            'uuid'       => wp_generate_uuid4(),
            'position_a' => $a,
            'position_b' => $b,
            'weight'     => $weight,
            'created_at' => current_time( 'mysql', true ),
        ] );
    }
}
