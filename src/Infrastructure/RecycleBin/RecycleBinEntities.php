<?php
namespace TT\Infrastructure\RecycleBin;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;

/**
 * RecycleBinEntities (#2024, epic #2018) — presentation vocabulary for the
 * centralized recycle bin: a friendly, translatable label per entity type
 * and a best-effort identity anchor for a single trashed row.
 *
 * This is composition / display data only — it derives a human label from a
 * row the domain layer already loaded (ArchiveRepository::findIncludingArchived).
 * No business logic, no queries: it answers "what should the bin call this
 * record type, and what one-line identity should the row show", nothing more.
 *
 * The entity-key allowlist is sourced from ArchiveRepository::entityMap() so
 * the bin's vocabulary can never drift from the schema's archivable tables.
 */
final class RecycleBinEntities {

    /**
     * Whether $entity is a bin-archivable entity key. Single source of truth
     * for the REST {entity} allowlist validator and the view's iteration.
     */
    public static function isValid( string $entity ): bool {
        return isset( ArchiveRepository::entityMap()[ $entity ] );
    }

    /** All bin-archivable entity keys, in map order. @return list<string> */
    public static function keys(): array {
        return array_keys( ArchiveRepository::entityMap() );
    }

    /**
     * Friendly, translatable label for one entity type (singular). Falls back
     * to a title-cased version of the key for any entity not explicitly mapped,
     * so a newly-added entity still renders a sane heading without a code edit.
     */
    public static function label( string $entity ): string {
        $labels = [
            'player'                 => __( 'Player', 'talenttrack' ),
            'team'                   => __( 'Team', 'talenttrack' ),
            'evaluation'             => __( 'Evaluation', 'talenttrack' ),
            'activity'               => __( 'Activity', 'talenttrack' ),
            'goal'                   => __( 'Goal', 'talenttrack' ),
            'person'                 => __( 'Person', 'talenttrack' ),
            'tournament'             => __( 'Tournament', 'talenttrack' ),
            'trial_case'             => __( 'Trial case', 'talenttrack' ),
            'holiday'                => __( 'Holiday', 'talenttrack' ),
            'test_training'          => __( 'Test training', 'talenttrack' ),
            'trial_track'            => __( 'Trial track', 'talenttrack' ),
            'vct_exercise'           => __( 'Training exercise', 'talenttrack' ),
            'custom_widget'          => __( 'Custom widget', 'talenttrack' ),
            'injury'                 => __( 'Injury', 'talenttrack' ),
            'scheduled_report'       => __( 'Scheduled report', 'talenttrack' ),
            'measurement_definition' => __( 'Measurement definition', 'talenttrack' ),
            'measurement_session'    => __( 'Measurement session', 'talenttrack' ),
            'measurement_target'     => __( 'Measurement target', 'talenttrack' ),
            'measurement_result'     => __( 'Measurement result', 'talenttrack' ),
            'player_attribute_def'   => __( 'Player attribute', 'talenttrack' ),
        ];
        if ( isset( $labels[ $entity ] ) ) {
            return $labels[ $entity ];
        }
        return ucwords( str_replace( '_', ' ', $entity ) );
    }

    /**
     * Best-effort one-line identity for a single trashed row. Tries the common
     * human-readable columns in priority order; falls back to "Record #<id>"
     * so a row whose table has no obvious name column still anchors to its id.
     *
     * Deliberately generic across all 20 tables rather than a per-table column
     * map: the bin is action-only (no drill-in), so the identity just needs to
     * be recognisable, not canonical. Never returns an empty string.
     *
     * @param object $row A row loaded by ArchiveRepository::findIncludingArchived().
     */
    public static function identity( object $row ): string {
        // Composite first/last name (players, people).
        $first = isset( $row->first_name ) ? trim( (string) $row->first_name ) : '';
        $last  = isset( $row->last_name ) ? trim( (string) $row->last_name ) : '';
        $full  = trim( $first . ' ' . $last );
        if ( $full !== '' ) {
            return $full;
        }

        foreach ( [ 'name', 'full_name', 'title', 'label', 'display_name' ] as $col ) {
            if ( isset( $row->{$col} ) ) {
                $v = trim( (string) $row->{$col} );
                if ( $v !== '' ) {
                    return $v;
                }
            }
        }

        $id = isset( $row->id ) ? (int) $row->id : 0;
        /* translators: %d is a record id used as a fallback identity in the recycle bin. */
        return sprintf( __( 'Record #%d', 'talenttrack' ), $id );
    }
}
