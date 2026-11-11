<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MigrationLinkMap (#5053) — what a migration import does with every column
 * on an imported table that points at something outside the row.
 *
 * A link between imported tables is a reference in BackupDependencyMap and is
 * remapped through the import's id maps. Every other link is listed here:
 *
 *   - accountLinks()    a WordPress account id, mapped through the user map;
 *   - mediaLinks()      a file, which no migration archive carries;
 *   - installBound()    settings that only work on the install that made them;
 *   - unarchivedLinks() a row in a table no migration archive carries;
 *   - kept()            columns copied as they are, each with its reason.
 *
 * MigrationImportLinksTest checks every `*_id` and `*_by` column of a real
 * export against refs() and these lists, so a new link column cannot be
 * imported unclassified.
 */
final class MigrationLinkMap {

    /**
     * The column that is the record's own account, not a reference to
     * somebody who acted on it. A copy on the same install is a new record:
     * the account stays with the original unless the user map says so.
     */
    public const IDENTITY_ACCOUNT = 'wp_user_id';

    /**
     * Columns holding a WordPress account id, per table.
     *
     * @return array<string, string[]>
     */
    public static function accountLinks(): array {
        return [
            'tt_players'     => [ 'wp_user_id', 'parent_user_id', 'media_consent_by', 'archived_by', 'trashed_by' ],
            'tt_teams'       => [ 'archived_by', 'trashed_by' ],
            'tt_people'      => [ 'wp_user_id', 'archived_by', 'trashed_by' ],
            'tt_evaluations' => [ 'coach_id', 'archived_by', 'trashed_by' ],
            'tt_activities'  => [ 'coach_id', 'planned_by', 'created_by', 'updated_by', 'archived_by', 'trashed_by' ],
            'tt_attendance'  => [ 'recorded_by' ], /* both-kinds-ok */
            'tt_goals'       => [ 'created_by', 'archived_by', 'trashed_by' ],
        ];
    }

    /**
     * Columns pointing at a file, per table, with the warning for the rows
     * that lose it. The file lives on the source install; on another install
     * it is cleared. On the same install the file is still there.
     *
     * @return array<string, array{columns:string[], warning:callable(int):string}>
     */
    public static function mediaLinks(): array {
        return [
            'tt_players' => [
                'columns' => [ 'photo_media_id', 'photo_url' ],
                'warning' => static fn ( int $n ): string => sprintf(
                    /* translators: %d: number of player photos */
                    _n( '%d player photo was not imported: a migration archive does not carry files.', '%d player photos were not imported: a migration archive does not carry files.', $n, 'talenttrack' ),
                    $n
                ),
            ],
        ];
    }

    /**
     * Settings that work only on the install that made them: an
     * integration's account, its credentials (encrypted with this install's
     * keys) and its sync state. Never imported, on any install: a copy of a
     * team syncing the same calendar would double every event.
     *
     * @return array<string, array{columns:string[], warning:callable(int):string}>
     */
    public static function installBound(): array {
        return [
            'tt_teams' => [
                'columns' => [
                    'spond_ical_url', 'spond_group_id', 'spond_email', 'spond_password_enc',
                    'spond_token_enc', 'spond_token_expires_at',
                    'spond_last_sync_at', 'spond_last_sync_status', 'spond_last_sync_message',
                ],
                'warning' => static fn ( int $n ): string => sprintf(
                    /* translators: %d: number of teams */
                    _n( '%d team was imported without its Spond connection. Set it up again on this install.', '%d teams were imported without their Spond connection. Set them up again on this install.', $n, 'talenttrack' ),
                    $n
                ),
            ],
        ];
    }

    /**
     * Links to tables a migration archive never carries (#5051). Copied as
     * they are, they would point at whatever row has that id on another
     * install. `same_install` says whether the link still holds when the
     * archive comes back into the install that made it.
     *
     * A PDP sign-off's evaluation never keeps its talk: the link is unique,
     * and the talk belongs to the original evaluation.
     *
     * @return array<string, array<string, array{same_install:bool, warning:callable(int):string}>>
     */
    public static function unarchivedLinks(): array {
        return [
            'tt_evaluations' => [
                'pdp_conversation_id' => [
                    'same_install' => false,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of evaluations */
                        _n( '%d evaluation from a PDP conversation was imported without its link to the conversation.', '%d evaluations from PDP conversations were imported without their link to the conversation.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
            ],
            'tt_activities' => [
                'tournament_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of activities */
                        _n( '%d activity was imported without its tournament.', '%d activities were imported without their tournament.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
                'vct_cycle_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of activities */
                        _n( '%d activity was imported without its training cycle.', '%d activities were imported without their training cycle.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
            ],
            'tt_goals' => [
                'linked_principle_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of goals */
                        _n( '%d goal was imported without its linked principle.', '%d goals were imported without their linked principle.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
                'linked_action_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of goals */
                        _n( '%d goal was imported without its linked football action.', '%d goals were imported without their linked football action.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
            ],
            'tt_teams' => [
                'methodology_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of teams */
                        _n( '%d team was imported without its methodology.', '%d teams were imported without their methodology.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
            ],
            'tt_team_people' => [
                'functional_role_id' => [
                    'same_install' => true,
                    'warning'      => static fn ( int $n ): string => sprintf(
                        /* translators: %d: number of staff assignments */
                        _n( '%d staff assignment was imported without its functional role.', '%d staff assignments were imported without their functional role.', $n, 'talenttrack' ),
                        $n
                    ),
                ],
            ],
        ];
    }

    /**
     * Link-shaped columns copied as they are, each with the reason. `*`
     * holds the columns every table treats the same way.
     *
     * @return array<string, array<string,string>>
     */
    public static function kept(): array {
        return [
            '*' => [
                'id'      => 'the source id, dropped on insert and kept only as the key of the id map',
                'club_id' => 'rewritten to the current club',
            ],
            'tt_activities' => [
                'external_id' => 'the event id in the external calendar: the same event on every install, so a reconnected sync finds the activity instead of creating it again',
            ],
        ];
    }

    /**
     * The `*_id` and `*_by` columns of a table that nothing above, nor a
     * reference in BackupDependencyMap, accounts for.
     *
     * @param string[] $columns
     * @return string[]
     */
    public static function unclassified( string $table, array $columns ): array {
        $known = array_fill_keys( array_keys( self::kept()['*'] ), true );
        foreach ( BackupDependencyMap::refs()[ $table ] ?? [] as $ref ) $known[ (string) $ref['column'] ] = true;
        foreach ( self::accountLinks()[ $table ] ?? [] as $c ) $known[ $c ] = true;
        foreach ( self::mediaLinks()[ $table ]['columns'] ?? [] as $c ) $known[ $c ] = true;
        foreach ( self::installBound()[ $table ]['columns'] ?? [] as $c ) $known[ $c ] = true;
        foreach ( array_keys( self::unarchivedLinks()[ $table ] ?? [] ) as $c ) $known[ $c ] = true;
        foreach ( array_keys( self::kept()[ $table ] ?? [] ) as $c ) $known[ $c ] = true;

        $out = [];
        foreach ( $columns as $col ) {
            $col = (string) $col;
            if ( preg_match( '/(_id|_by)$/', $col ) !== 1 && $col !== 'id' ) continue;
            if ( ! isset( $known[ $col ] ) ) $out[] = $col;
        }
        return $out;
    }
}
