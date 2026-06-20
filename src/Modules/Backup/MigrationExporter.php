<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationExporter (#1464, phase 1) — produces a portable `.ttmig`
 * snapshot for moving selected TalentTrack data from one install to
 * another.
 *
 * Reuses BackupSerializer for the table snapshot + envelope, but stamps
 * `kind = migration` and the selected entity set so a future importer
 * (#1464 phases 2-4) can present the matching options. Export is
 * read-only — it never writes to the database.
 *
 * Data only: like backups, this carries the plugin's own `tt_*` tables,
 * not WordPress users or media. Cross-install identity (`wp_user_id`) is
 * resolved on import, not exported.
 */
class MigrationExporter {

    public const KIND     = 'migration';
    public const FILE_EXT = 'ttmig';

    /**
     * Entity groups offered in the migration UI, each mapping to the
     * tables it covers. Keys are stable identifiers carried in the
     * snapshot so the importer can reason about what's inside.
     *
     * @return array<string, array{label:string, tables:string[]}>
     */
    public static function entityGroups(): array {
        return [
            'players'     => [ 'label' => __( 'Players', 'talenttrack' ),                'tables' => [ 'tt_players' ] ],
            'teams'       => [ 'label' => __( 'Teams', 'talenttrack' ),                  'tables' => [ 'tt_teams', 'tt_team_people' ] ],
            'people'      => [ 'label' => __( 'Staff & roles', 'talenttrack' ),          'tables' => [ 'tt_people', 'tt_functional_role_types', 'tt_functional_role_assignments' ] ],
            'evaluations' => [ 'label' => __( 'Evaluations', 'talenttrack' ),            'tables' => [ 'tt_evaluations', 'tt_eval_ratings' ] ],
            'activities'  => [ 'label' => __( 'Activities & attendance', 'talenttrack' ),'tables' => [ 'tt_activities', 'tt_attendance' ] ],
            'goals'       => [ 'label' => __( 'Goals', 'talenttrack' ),                  'tables' => [ 'tt_goals' ] ],
            'config'      => [ 'label' => __( 'Lookups & configuration', 'talenttrack' ),'tables' => [ 'tt_lookups', 'tt_eval_categories', 'tt_eval_category_weights', 'tt_custom_fields', 'tt_custom_values', 'tt_config' ] ],
        ];
    }

    /** @return string[] valid entity-group keys */
    public static function entityKeys(): array {
        return array_keys( self::entityGroups() );
    }

    /**
     * #1517 — entity groups that offer per-record include/exclude
     * selection. Each maps to its primary record table plus the child
     * tables whose rows follow the primary's selection (excluding a
     * primary record also drops its child rows). Keyed `child_table =>
     * foreign-key column pointing at the primary's id`. "Lookups &
     * configuration" is intentionally absent — it is reference data, not
     * test records, and stays all-or-nothing.
     *
     * @return array<string, array{table:string, children:array<string,string>}>
     */
    public static function recordEntities(): array {
        return [
            'players'     => [ 'table' => 'tt_players',     'children' => [] ],
            'teams'       => [ 'table' => 'tt_teams',       'children' => [ 'tt_team_people' => 'team_id' ] ],
            'people'      => [ 'table' => 'tt_people',      'children' => [ 'tt_functional_role_assignments' => 'person_id' ] ],
            'evaluations' => [ 'table' => 'tt_evaluations', 'children' => [ 'tt_eval_ratings' => 'evaluation_id' ] ],
            'activities'  => [ 'table' => 'tt_activities',  'children' => [ 'tt_attendance' => 'activity_id' ] ],
            'goals'       => [ 'table' => 'tt_goals',       'children' => [] ],
        ];
    }

    /**
     * Translate a per-entity exclusion set into the per-table row filter
     * BackupSerializer::snapshot() consumes. Each entity's excluded ids
     * filter its primary table by `id` and every child table by its
     * foreign-key column.
     *
     * @param array<string, array<int,int>> $exclusions entity => excluded primary ids
     * @return array<string, array{column:string, ids:array<int,int>}>
     */
    public static function buildRowFilters( array $exclusions ): array {
        $entities = self::recordEntities();
        $filters  = [];
        foreach ( $exclusions as $entity => $ids ) {
            $entity = (string) $entity;
            if ( ! isset( $entities[ $entity ] ) ) continue;
            $ids = array_values( array_unique( array_map( 'intval', (array) $ids ) ) );
            if ( empty( $ids ) ) continue;

            $primary = $entities[ $entity ]['table'];
            $filters[ $primary ] = [ 'column' => 'id', 'ids' => $ids ];
            foreach ( $entities[ $entity ]['children'] as $child_table => $fk ) {
                $filters[ (string) $child_table ] = [ 'column' => (string) $fk, 'ids' => $ids ];
            }
        }
        return $filters;
    }

    /**
     * Build a `.ttmig` snapshot for the selected entity groups.
     *
     * @param string[] $entity_keys Selected entity-group keys.
     * @param array<string, array<int,int>> $exclusions #1517 — per-entity
     *        excluded primary record ids; empty exports every row.
     * @return string Gzipped JSON bytes, or '' when nothing valid was selected.
     */
    public static function export( array $entity_keys, array $exclusions = [] ): string {
        $groups = self::entityGroups();
        $valid  = [];
        $tables = [];
        foreach ( $entity_keys as $key ) {
            $key = (string) $key;
            if ( ! isset( $groups[ $key ] ) ) continue;
            $valid[] = $key;
            foreach ( $groups[ $key ]['tables'] as $t ) {
                $tables[ $t ] = true;
            }
        }
        if ( empty( $tables ) ) {
            return '';
        }

        // #1517 — keep only exclusions for entities actually being exported.
        $exclusions  = array_intersect_key( $exclusions, array_flip( $valid ) );
        $row_filters = self::buildRowFilters( $exclusions );

        $snapshot = BackupSerializer::snapshot( array_keys( $tables ), self::KIND, $row_filters );
        // Envelope additions — the checksum is computed over the `tables`
        // subtree only, so stamping these extra header fields doesn't
        // invalidate it.
        $snapshot['kind']     = self::KIND;
        $snapshot['entities'] = array_values( array_unique( $valid ) );

        return BackupSerializer::toGzippedJson( $snapshot );
    }

    /**
     * Filename for a freshly-exported migration archive. Timestamp is
     * passed in (callers stamp it) so this stays deterministic/testable.
     */
    public static function filename( string $stamp ): string {
        $stamp = preg_replace( '/[^0-9A-Za-z_-]/', '', $stamp ) ?: 'export';
        return 'talenttrack-migration-' . $stamp . '.' . self::FILE_EXT;
    }
}
