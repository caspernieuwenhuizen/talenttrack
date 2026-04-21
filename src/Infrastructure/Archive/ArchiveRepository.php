<?php
namespace TT\Infrastructure\Archive;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ArchiveRepository — archive / restore / hard-delete across entity tables.
 *
 * Sprint v2.17.0. The archive pattern is uniform across tables — each
 * entity has `archived_at DATETIME NULL` + `archived_by BIGINT NULL`
 * columns (see migration 0010). This repository encapsulates that
 * pattern so UI code doesn't need to know which table or columns.
 *
 * Supported entities — identified by a short string key that maps to
 * a DB table name:
 *   'player'     → tt_players
 *   'team'       → tt_teams
 *   'evaluation' → tt_evaluations
 *   'session'    → tt_sessions
 *   'goal'       → tt_goals
 *   'person'     → tt_people
 *
 * All methods accept arrays of ids for bulk operations. Empty/invalid
 * ids are filtered out; methods return the number of rows actually
 * affected.
 */
class ArchiveRepository {

    /** Entity-key → table-name (without prefix) */
    private const TABLE_MAP = [
        'player'     => 'tt_players',
        'team'       => 'tt_teams',
        'evaluation' => 'tt_evaluations',
        'session'    => 'tt_sessions',
        'goal'       => 'tt_goals',
        'person'     => 'tt_people',
    ];

    /* ═══════════════ Public API ═══════════════ */

    /**
     * Archive N rows. Stamps archived_at + archived_by. Rows that are
     * already archived are left untouched (idempotent).
     *
     * @param string $entity  'player'|'team'|'evaluation'|'session'|'goal'|'person'
     * @param int[]  $ids
     * @param int    $by_user_id  wp user id of the person archiving
     * @return int  Number of rows affected.
     */
    public function archive( string $entity, array $ids, int $by_user_id ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET archived_at = %s, archived_by = %d
                WHERE id IN ({$ph}) AND archived_at IS NULL";
        $args = array_merge( [ current_time( 'mysql' ), $by_user_id ], $ids );
        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$args ) );
    }

    /**
     * Restore N archived rows. Clears archived_at + archived_by.
     *
     * @param string $entity
     * @param int[]  $ids
     * @return int
     */
    public function restore( string $entity, array $ids ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "UPDATE {$table}
                SET archived_at = NULL, archived_by = NULL
                WHERE id IN ({$ph}) AND archived_at IS NOT NULL";
        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
    }

    /**
     * Hard-delete N rows. Irreversible. Caller must verify permissions
     * before calling this — anyone holding the repo can wipe data.
     *
     * Cascade rules are NOT handled here — this method deletes exactly
     * the rows from the entity table. If an entity has dependent rows
     * (e.g. player has evaluations), the caller should either delete
     * those first or accept the orphaning. Foreign-key constraints in
     * the schema would enforce cascades at the DB level; currently
     * TalentTrack tables don't declare FKs.
     *
     * @param string $entity
     * @param int[]  $ids
     * @return int
     */
    public function deletePermanently( string $entity, array $ids ): int {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return 0;
        $ids = $this->cleanIds( $ids );
        if ( empty( $ids ) ) return 0;

        global $wpdb;
        $ph = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $sql = "DELETE FROM {$table} WHERE id IN ({$ph})";
        return (int) $wpdb->query( $wpdb->prepare( $sql, ...$ids ) );
    }

    /**
     * Count active, archived, and total rows — used for the
     * "Active (N) | Archived (N) | All (N)" tab bar above list views.
     *
     * @return array{active:int, archived:int, all:int}
     */
    public function counts( string $entity ): array {
        $table = $this->resolveTable( $entity );
        if ( $table === null ) return [ 'active' => 0, 'archived' => 0, 'all' => 0 ];

        global $wpdb;
        $all      = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $archived = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE archived_at IS NOT NULL" );
        return [
            'active'   => $all - $archived,
            'archived' => $archived,
            'all'      => $all,
        ];
    }

    /**
     * SQL fragment appending the archive filter. Callers build their
     * WHERE clauses; this returns one of:
     *   'active'   →  'archived_at IS NULL'
     *   'archived' →  'archived_at IS NOT NULL'
     *   'all'      →  '1=1'
     */
    public static function filterClause( string $view ): string {
        switch ( $view ) {
            case 'archived': return 'archived_at IS NOT NULL';
            case 'all':      return '1=1';
            case 'active':
            default:         return 'archived_at IS NULL';
        }
    }

    /**
     * Normalize the ?tt_view query-string value to one of active/archived/all.
     */
    public static function sanitizeView( $raw ): string {
        $v = is_string( $raw ) ? strtolower( trim( $raw ) ) : '';
        return in_array( $v, [ 'active', 'archived', 'all' ], true ) ? $v : 'active';
    }

    /* ═══════════════ Dependency checks ═══════════════ */

    /**
     * Count active dependents of an entity — used for warnings before
     * archiving a team ("18 active players depend on this team").
     *
     * @return array<string,int>  entity => count of active dependents
     */
    public function activeDependentsFor( string $entity, int $id ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $out = [];

        if ( $entity === 'team' ) {
            $out['players'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_players WHERE team_id = %d AND archived_at IS NULL",
                $id
            ) );
            $out['sessions'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_sessions WHERE team_id = %d AND archived_at IS NULL",
                $id
            ) );
        } elseif ( $entity === 'player' ) {
            $out['evaluations'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE player_id = %d AND archived_at IS NULL",
                $id
            ) );
            $out['goals'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$p}tt_goals WHERE player_id = %d AND archived_at IS NULL",
                $id
            ) );
        }

        return $out;
    }

    /* ═══════════════ Helpers ═══════════════ */

    private function resolveTable( string $entity ): ?string {
        if ( ! isset( self::TABLE_MAP[ $entity ] ) ) return null;
        global $wpdb;
        return $wpdb->prefix . self::TABLE_MAP[ $entity ];
    }

    /**
     * Normalize + deduplicate an array of ids into positive ints.
     *
     * @param int[] $raw
     * @return int[]
     */
    private function cleanIds( array $raw ): array {
        $out = [];
        foreach ( $raw as $v ) {
            $i = (int) $v;
            if ( $i > 0 ) $out[ $i ] = true;
        }
        return array_keys( $out );
    }
}
