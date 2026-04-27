<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * BackupDependencyMap — declares foreign-key-like references between
 * `tt_*` tables for partial restore scope resolution.
 *
 * Entries describe "this table's column REFERENCES that table's id":
 *
 *   tt_evaluations.player_id    → tt_players.id
 *   tt_evaluations.coach_id     → wp_users.ID  (skipped — outside tt_*)
 *   tt_eval_ratings.eval_id     → tt_evaluations.id
 *   tt_eval_ratings.category_id → tt_eval_categories.id
 *
 * The map drives two things:
 *
 *   1. Closure walk — given a starting set of records (e.g. one player),
 *      include the parents that must exist for the child rows to be
 *      consistent (e.g. their team, eval categories) plus any children
 *      the user opted to bring along (their evaluations + ratings).
 *
 *   2. Restore order — children are restored AFTER their parents so
 *      foreign-key-like checks pass at insert time.
 *
 * The map is intentionally small for v1 — covers the core entities
 * the partial-restore UI offers as scope. Adding a new entity is a
 * one-row addition here; the diff/restore code is generic.
 */
class BackupDependencyMap {

    /**
     * Foreign-key-like references inside the tt_* domain.
     *
     * Format: child_table => [
     *   ['column' => string, 'parent_table' => string, 'parent_column' => string],
     *   ...
     * ]
     *
     * Returns only references between tt_* tables — references that
     * point at WP core tables (wp_users, etc.) are explicitly excluded
     * because those tables aren't in the backup scope.
     *
     * @return array<string, array<int, array{column:string, parent_table:string, parent_column:string}>>
     */
    public static function refs(): array {
        return [
            'tt_players' => [
                [ 'column' => 'team_id', 'parent_table' => 'tt_teams', 'parent_column' => 'id' ],
            ],
            'tt_evaluations' => [
                [ 'column' => 'player_id',    'parent_table' => 'tt_players',  'parent_column' => 'id' ],
                [ 'column' => 'eval_type_id', 'parent_table' => 'tt_lookups',  'parent_column' => 'id' ],
            ],
            'tt_eval_ratings' => [
                [ 'column' => 'evaluation_id', 'parent_table' => 'tt_evaluations',     'parent_column' => 'id' ],
                [ 'column' => 'category_id',   'parent_table' => 'tt_eval_categories', 'parent_column' => 'id' ],
            ],
            'tt_activities' => [
                [ 'column' => 'team_id', 'parent_table' => 'tt_teams', 'parent_column' => 'id' ],
            ],
            'tt_attendance' => [
                [ 'column' => 'activity_id', 'parent_table' => 'tt_activities', 'parent_column' => 'id' ],
                [ 'column' => 'player_id',  'parent_table' => 'tt_players',  'parent_column' => 'id' ],
            ],
            'tt_goals' => [
                [ 'column' => 'player_id', 'parent_table' => 'tt_players', 'parent_column' => 'id' ],
            ],
            'tt_team_people' => [
                [ 'column' => 'team_id',   'parent_table' => 'tt_teams',  'parent_column' => 'id' ],
                [ 'column' => 'person_id', 'parent_table' => 'tt_people', 'parent_column' => 'id' ],
            ],
            'tt_functional_role_assignments' => [
                [ 'column' => 'role_type_id', 'parent_table' => 'tt_functional_role_types', 'parent_column' => 'id' ],
                [ 'column' => 'team_id',      'parent_table' => 'tt_teams',                 'parent_column' => 'id' ],
                [ 'column' => 'person_id',    'parent_table' => 'tt_people',                'parent_column' => 'id' ],
            ],
            'tt_custom_values' => [
                [ 'column' => 'field_id', 'parent_table' => 'tt_custom_fields', 'parent_column' => 'id' ],
            ],
            'tt_eval_category_weights' => [
                [ 'column' => 'category_id', 'parent_table' => 'tt_eval_categories', 'parent_column' => 'id' ],
            ],
        ];
    }

    /**
     * Reverse map: parent_table => [child_table => [column, ...]]
     * Used by closure walk to find children of a given parent.
     *
     * @return array<string, array<string, string[]>>
     */
    public static function inverse(): array {
        $out = [];
        foreach ( self::refs() as $child => $references ) {
            foreach ( $references as $ref ) {
                $parent = (string) $ref['parent_table'];
                $col    = (string) $ref['column'];
                $out[ $parent ][ $child ][] = $col;
            }
        }
        return $out;
    }

    /**
     * Topological order for restore: parents before children. Tables
     * not in the dependency map go first (they have no parents inside
     * tt_*). Returns the input list reordered.
     *
     * @param string[] $tables
     * @return string[]
     */
    public static function restoreOrder( array $tables ): array {
        $refs    = self::refs();
        $set     = array_fill_keys( $tables, true );
        $ordered = [];
        $seen    = [];

        $visit = function ( string $table ) use ( &$visit, &$ordered, &$seen, $refs, $set ) {
            if ( isset( $seen[ $table ] ) ) return;
            $seen[ $table ] = true;
            foreach ( $refs[ $table ] ?? [] as $ref ) {
                $parent = (string) $ref['parent_table'];
                if ( isset( $set[ $parent ] ) ) {
                    $visit( $parent );
                }
            }
            if ( isset( $set[ $table ] ) ) {
                $ordered[] = $table;
            }
        };

        foreach ( $tables as $t ) $visit( $t );
        return $ordered;
    }
}
