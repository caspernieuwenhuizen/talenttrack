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
     * Build a `.ttmig` snapshot for the selected entity groups.
     *
     * @param string[] $entity_keys Selected entity-group keys.
     * @return string Gzipped JSON bytes, or '' when nothing valid was selected.
     */
    public static function export( array $entity_keys ): string {
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

        $snapshot = BackupSerializer::snapshot( array_keys( $tables ), self::KIND );
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
