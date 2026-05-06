<?php
namespace TT\Infrastructure\Privacy;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerDataMap (#0081 child 1) — central registry of every database
 * table that holds personal data attached to a player or prospect.
 *
 * The point: when GDPR Article 15 (subject access) or Article 17
 * (erasure) lands on this install, the operator needs an authoritative
 * answer to *which tables hold this person's data*. Hard-coding that
 * list inside an exporter or eraser invites drift; every new module
 * that adds a PII column has to remember to update the central list.
 *
 * Modules register their PII tables at boot via `register()`. A
 * subject-access export or erasure run walks the registry, so adding
 * a new PII column is a matter of registering it once and the export
 * / erasure paths pick it up automatically.
 *
 * Child 1 ships the registry surface and the initial set of
 * registrations (existing core PII tables + the two new prospect
 * tables). The actual erasure execution is out of scope for #0081 —
 * #0073 (GDPR module) consumes this registry. By landing the
 * registry now we avoid a "ship erasure later, then go back and
 * register every table" round-trip.
 *
 * Pattern: static-only registry (single source of truth for the
 * process). No DI; modules call `PlayerDataMap::register(...)` from
 * their boot path. Idempotent — same registration replaces, doesn't
 * duplicate.
 */
final class PlayerDataMap {

    /**
     * @var array<string, array{
     *   table: string,
     *   player_id_column: string,
     *   purpose: string,
     *   owner_module: string
     * }>
     *   Keyed by `table` so re-registration replaces (idempotent).
     */
    private static array $registrations = [];

    /**
     * Register a table that holds PII for a player or prospect.
     *
     * @param string $table            unprefixed table name (e.g. `tt_players`)
     * @param string $player_id_column column joining to player identity.
     *                                 Use `id` when the table IS the
     *                                 player record itself; otherwise
     *                                 the FK column (typically `player_id`).
     * @param string $purpose          one-line human-readable purpose,
     *                                 surfaces in subject-access exports
     *                                 to explain WHY the data is held.
     * @param string $owner_module     fully-qualified module class. Helps
     *                                 the audit log explain which
     *                                 sub-system added the registration.
     */
    public static function register(
        string $table,
        string $player_id_column,
        string $purpose,
        string $owner_module
    ): void {
        if ( $table === '' ) return;
        self::$registrations[ $table ] = [
            'table'            => $table,
            'player_id_column' => $player_id_column,
            'purpose'          => $purpose,
            'owner_module'     => $owner_module,
        ];
    }

    /**
     * @return array<int, array{table:string,player_id_column:string,purpose:string,owner_module:string}>
     */
    public static function all(): array {
        return array_values( self::$registrations );
    }

    public static function isRegistered( string $table ): bool {
        return isset( self::$registrations[ $table ] );
    }

    /**
     * Reset registry. Test-only helper; production code should never
     * call this.
     */
    public static function reset(): void {
        self::$registrations = [];
    }

    /**
     * Run a row-count query per registered table for a given player.
     * Returns the manifest used by subject-access exports and the
     * erasure dry-run UI.
     *
     * `id` columns require a special case — the table IS the player
     * record itself, so the predicate is `WHERE id = %d` rather than
     * `WHERE player_id = %d`.
     *
     * @return array<int, array{table:string,column:string,count:int,purpose:string}>
     */
    public static function rowCountsForPlayer( int $player_id ): array {
        if ( $player_id <= 0 ) return [];

        global $wpdb;
        $prefix = $wpdb->prefix;
        $out = [];

        foreach ( self::$registrations as $reg ) {
            $table  = $prefix . preg_replace( '/[^a-z0-9_]/i', '', (string) $reg['table'] );
            $column = preg_replace( '/[^a-z0-9_]/i', '', (string) $reg['player_id_column'] );
            if ( $table === $prefix || $column === '' ) continue;

            // Validate the table exists on this install (modules can be
            // disabled, leaving registrations dangling). Skip silently
            // — it's a registry, not an enforced contract.
            $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
            if ( $exists !== $table ) continue;

            // safe: $table + $column whitelisted to [a-z0-9_]
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %d",
                $player_id
            ) );

            $out[] = [
                'table'   => (string) $reg['table'],
                'column'  => (string) $reg['player_id_column'],
                'count'   => $count,
                'purpose' => (string) $reg['purpose'],
            ];
        }
        return $out;
    }
}
