<?php
namespace TT\Modules\Measurements\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;

/**
 * MeasurementLevelsRepository (#2138).
 *
 * The operator-defined, colour-tagged levels of a status-type test (e.g.
 * "On track" green, "Watch" amber, "At risk" red). One row per level per
 * definition. Mirrors MeasurementTargetsRepository — stateless, club-scoped
 * via CurrentClub::id(), excludes soft-deleted rows (archived_at IS NULL).
 *
 * The colour is stored as a *token key* only (MeasurementLevelPalette),
 * never a raw hex — the renderer maps it to a CSS class.
 */
class MeasurementLevelsRepository {

    /**
     * Active levels for one definition, ordered (ordinal then sort_order).
     *
     * @return array<int, object>
     */
    public function listForDefinition( int $definition_id ): array {
        if ( $definition_id <= 0 ) return [];
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_levels
              WHERE definition_id = %d AND club_id = %d
                AND archived_at IS NULL AND is_active = 1
              ORDER BY ordinal ASC, sort_order ASC, id ASC",
            $definition_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * One level by its label for a definition (used to resolve a recorded
     * snapshot back to its current colour on the profile).
     */
    public function findByLabel( int $definition_id, string $label ): ?object {
        if ( $definition_id <= 0 || $label === '' ) return null;
        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$p}tt_measurement_levels
              WHERE definition_id = %d AND label = %s AND club_id = %d
                AND archived_at IS NULL LIMIT 1",
            $definition_id, $label, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create( int $definition_id, array $data ): int {
        if ( $definition_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;

        $label = trim( (string) ( $data['label'] ?? '' ) );
        if ( $label === '' ) return 0;

        $wpdb->insert( "{$p}tt_measurement_levels", [
            'club_id'       => CurrentClub::id(),
            'uuid'          => wp_generate_uuid4(),
            'definition_id' => $definition_id,
            'label'         => $label,
            'color_token'   => MeasurementLevelPalette::safe( (string) ( $data['color_token'] ?? '' ) ),
            'ordinal'       => (int) ( $data['ordinal'] ?? 0 ),
            'sort_order'    => (int) ( $data['sort_order'] ?? ( $data['ordinal'] ?? 0 ) ),
            'is_active'     => isset( $data['is_active'] ) ? (int) (bool) $data['is_active'] : 1,
            'created_by'    => get_current_user_id() ?: null,
            'created_at'    => current_time( 'mysql', true ),
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;

        $fields = [ 'updated_at' => current_time( 'mysql', true ) ];
        if ( array_key_exists( 'label', $data ) )       $fields['label']       = trim( (string) $data['label'] );
        if ( array_key_exists( 'color_token', $data ) ) $fields['color_token'] = MeasurementLevelPalette::safe( (string) $data['color_token'] );
        if ( array_key_exists( 'ordinal', $data ) )     $fields['ordinal']     = (int) $data['ordinal'];
        if ( array_key_exists( 'sort_order', $data ) )  $fields['sort_order']  = (int) $data['sort_order'];
        if ( array_key_exists( 'is_active', $data ) )   $fields['is_active']   = (int) (bool) $data['is_active'];

        return false !== $wpdb->update(
            "{$p}tt_measurement_levels",
            $fields,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Soft-archive a level (the reversible delete — the recycle-bin pattern).
     */
    public function archive( int $id, int $by_user_id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $p = $wpdb->prefix;
        return false !== $wpdb->update(
            "{$p}tt_measurement_levels",
            [ 'archived_at' => current_time( 'mysql', true ), 'archived_by' => $by_user_id ?: null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    /**
     * Replace a definition's full level set from an ordered list of
     * { label, color_token } rows. Levels not present in the new set are
     * archived; the ordinal is the row's position (1-based) so a status's
     * numeric snapshot reads "worse → better" by ordinal. Returns the
     * number of live levels after the save.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public function replaceForDefinition( int $definition_id, array $rows ): int {
        if ( $definition_id <= 0 ) return 0;

        $existing = $this->listForDefinition( $definition_id );
        $by_id    = [];
        foreach ( $existing as $row ) {
            $by_id[ (int) $row->id ] = $row;
        }

        $kept    = [];
        $ordinal = 0;
        foreach ( $rows as $row ) {
            $label = trim( (string) ( $row['label'] ?? '' ) );
            if ( $label === '' ) continue;
            $ordinal++;
            $token = MeasurementLevelPalette::safe( (string) ( $row['color_token'] ?? '' ) );
            $id    = isset( $row['id'] ) ? (int) $row['id'] : 0;

            if ( $id > 0 && isset( $by_id[ $id ] ) ) {
                $this->update( $id, [
                    'label'       => $label,
                    'color_token' => $token,
                    'ordinal'     => $ordinal,
                    'sort_order'  => $ordinal,
                    'is_active'   => 1,
                ] );
                $kept[ $id ] = true;
            } else {
                $new_id = $this->create( $definition_id, [
                    'label'       => $label,
                    'color_token' => $token,
                    'ordinal'     => $ordinal,
                    'sort_order'  => $ordinal,
                ] );
                if ( $new_id > 0 ) $kept[ $new_id ] = true;
            }
        }

        // Archive any pre-existing level the operator removed.
        foreach ( $by_id as $id => $_row ) {
            if ( empty( $kept[ $id ] ) ) {
                $this->archive( $id, get_current_user_id() );
            }
        }

        return count( $kept );
    }
}
