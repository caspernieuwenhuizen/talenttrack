<?php
namespace TT\Modules\Authorization\Matrix;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * MatrixRepository — read API over `tt_authorization_matrix` (#0033 Sprint 1).
 *
 * Static, lazy-loaded, request-scoped cache. The matrix is small (a few
 * hundred rows for 8 personas × ~30 entities × 3 activities) so we read
 * it once per request and answer all subsequent lookups in-memory.
 *
 * Sprint 1 ships read + reseed only. Sprint 3 adds write methods (update,
 * resetRow) when the admin matrix UI lands.
 */
class MatrixRepository {

    /**
     * Cache shape: persona => entity => activity => scope_kind => module_class
     *
     * @var array<string, array<string, array<string, array<string, string>>>>|null
     */
    private static $cache = null;

    /**
     * Parallel cache, persona => entity => activity => scope_kind => row_id.
     * Populated alongside `$cache` so the admin comparison page can surface
     * "source row #N" in the per-cap drill-down without a second query.
     *
     * @var array<string, array<string, array<string, array<string, int>>>>|null
     */
    private static $idCache = null;

    /**
     * True if persona is allowed (activity, entity, scope_kind).
     */
    public function lookup( string $persona, string $entity, string $activity, string $scope_kind ): bool {
        $cache = self::loadCache();
        return isset( $cache[ $persona ][ $entity ][ $activity ][ $scope_kind ] );
    }

    /**
     * True if any of the supplied personas is allowed.
     *
     * @param string[] $personas
     */
    public function lookupAny( array $personas, string $entity, string $activity, string $scope_kind ): bool {
        foreach ( $personas as $p ) {
            if ( $this->lookup( $p, $entity, $activity, $scope_kind ) ) return true;
        }
        return false;
    }

    /**
     * Module class that owns this matrix row, or null if no row exists.
     * Used by MatrixGate's Sprint-5 module-disabled short-circuit.
     */
    public function moduleFor( string $persona, string $entity, string $activity, string $scope_kind ): ?string {
        $cache = self::loadCache();
        return $cache[ $persona ][ $entity ][ $activity ][ $scope_kind ] ?? null;
    }

    /**
     * #0080 Wave B3 — DB id of the matrix row that grants this tuple,
     * or null if no row exists. Used by the comparison page's per-cap
     * drill-down ("source row #N").
     */
    public function rowIdFor( string $persona, string $entity, string $activity, string $scope_kind ): ?int {
        self::loadCache();
        return self::$idCache[ $persona ][ $entity ][ $activity ][ $scope_kind ] ?? null;
    }

    /**
     * Returns the set of entities a persona can perform `$activity` on
     * within `$scope_kind`. Useful for tile rendering ("which entities
     * does this persona see at all?").
     *
     * @return string[]
     */
    public function entitiesFor( string $persona, string $activity, string $scope_kind ): array {
        $cache = self::loadCache();
        $entities = [];
        if ( ! isset( $cache[ $persona ] ) ) return $entities;
        foreach ( $cache[ $persona ] as $entity => $by_activity ) {
            if ( isset( $by_activity[ $activity ][ $scope_kind ] ) ) {
                $entities[] = $entity;
            }
        }
        return $entities;
    }

    /**
     * Truncate the matrix and re-insert from /config/authorization_seed.php.
     *
     * Destructive: any admin-edited rows are lost. Only Sprint 3's reset
     * button should call this; never call from normal request paths.
     */
    public function reseed(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->query( "TRUNCATE TABLE {$p}tt_authorization_matrix" );

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) {
            self::clearCache();
            return;
        }

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) {
            self::clearCache();
            return;
        }

        foreach ( $rows as $row ) {
            $wpdb->insert( "{$p}tt_authorization_matrix", [
                'persona'      => (string) $row['persona'],
                'entity'       => (string) $row['entity'],
                'activity'     => (string) $row['activity'],
                'scope_kind'   => (string) $row['scope_kind'],
                'module_class' => (string) $row['module_class'],
                'is_default'   => 1,
            ] );
        }

        self::clearCache();
    }

    /**
     * Drop the per-request cache. Tests use this between scenarios; the
     * Sprint 3 admin save handler will call it after row updates.
     */
    public static function clearCache(): void {
        self::$cache = null;
        self::$idCache = null;
    }

    /**
     * Sprint 3: insert OR update a matrix row. Sets `is_default = 0`
     * to mark it admin-edited.
     *
     * `module_class` is preserved if a row already exists; for new
     * rows the caller must provide it (resolved from the seed file).
     */
    public function setRow( string $persona, string $entity, string $activity, string $scope_kind, string $module_class ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $existing = $wpdb->get_row( $wpdb->prepare(
            "SELECT id, module_class FROM {$p}tt_authorization_matrix
             WHERE persona = %s AND entity = %s AND activity = %s AND scope_kind = %s",
            $persona, $entity, $activity, $scope_kind
        ) );
        if ( $existing ) {
            $wpdb->update(
                "{$p}tt_authorization_matrix",
                [ 'is_default' => 0 ],
                [ 'id' => (int) $existing->id ]
            );
        } else {
            $wpdb->insert( "{$p}tt_authorization_matrix", [
                'persona'      => $persona,
                'entity'       => $entity,
                'activity'     => $activity,
                'scope_kind'   => $scope_kind,
                'module_class' => $module_class,
                'is_default'   => 0,
            ] );
        }
        self::clearCache();
    }

    /**
     * Sprint 3: remove a matrix row (revoke the persona's permission
     * for this tuple). No-op if the row doesn't exist.
     */
    public function removeRow( string $persona, string $entity, string $activity, string $scope_kind ): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->delete( "{$p}tt_authorization_matrix", [
            'persona'    => $persona,
            'entity'     => $entity,
            'activity'   => $activity,
            'scope_kind' => $scope_kind,
        ] );
        self::clearCache();
    }

    /**
     * @return list<string> distinct persona keys present in the matrix.
     */
    public function personas(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_col( "SELECT DISTINCT persona FROM {$p}tt_authorization_matrix ORDER BY persona ASC" );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return list<array{entity:string, module_class:string}> distinct (entity, module) pairs.
     */
    public function entities(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( "SELECT DISTINCT entity, module_class FROM {$p}tt_authorization_matrix ORDER BY module_class ASC, entity ASC" );
        if ( ! is_array( $rows ) ) return [];
        $out = [];
        foreach ( $rows as $r ) {
            $out[] = [
                'entity'       => (string) $r->entity,
                'module_class' => (string) $r->module_class,
            ];
        }
        return $out;
    }

    /**
     * @return array<string, array<string, array{scope_kind:string, is_default:int}>>
     *         persona => entity => activity => details.
     */
    public function asGrid(): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $rows = $wpdb->get_results( "SELECT persona, entity, activity, scope_kind, is_default FROM {$p}tt_authorization_matrix" );
        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $out[ (string) $r->persona ][ (string) $r->entity ][ (string) $r->activity ] = [
                    'scope_kind' => (string) $r->scope_kind,
                    'is_default' => (int) $r->is_default,
                ];
            }
        }
        return $out;
    }

    /**
     * Lazy load + cache the full matrix once per request.
     *
     * @return array<string, array<string, array<string, array<string, string>>>>
     */
    private static function loadCache(): array {
        if ( self::$cache !== null ) return self::$cache;

        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( "SELECT id, persona, entity, activity, scope_kind, module_class FROM {$p}tt_authorization_matrix" );
        $modules = [];
        $ids = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $persona    = (string) $r->persona;
                $entity     = (string) $r->entity;
                $activity   = (string) $r->activity;
                $scope_kind = (string) $r->scope_kind;
                $modules[ $persona ][ $entity ][ $activity ][ $scope_kind ] = (string) $r->module_class;
                $ids[ $persona ][ $entity ][ $activity ][ $scope_kind ]     = (int) $r->id;
            }
        }
        self::$cache   = $modules;
        self::$idCache = $ids;
        return $modules;
    }
}
