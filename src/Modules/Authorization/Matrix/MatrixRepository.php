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

        $rows = $wpdb->get_results( "SELECT persona, entity, activity, scope_kind, module_class FROM {$p}tt_authorization_matrix" );
        $out = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $out[ (string) $r->persona ][ (string) $r->entity ][ (string) $r->activity ][ (string) $r->scope_kind ] = (string) $r->module_class;
            }
        }
        self::$cache = $out;
        return $out;
    }
}
