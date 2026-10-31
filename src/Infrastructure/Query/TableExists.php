<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TableExists — whether a TalentTrack table is present, asked of the
 * database once per table per request.
 *
 * For tables a migration may not have created on every install. A table
 * every supported install has from a migration needs no check at all.
 */
final class TableExists {

    /** @var array<string, bool> prefixed table name => present */
    private static $cache = [];

    /**
     * @param string $bare_table Table name without the WordPress prefix, e.g. `tt_comms_deferred`.
     */
    public static function has( string $bare_table ): bool {
        global $wpdb;
        $table = $wpdb->prefix . $bare_table;
        if ( ! isset( self::$cache[ $table ] ) ) {
            self::$cache[ $table ] = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        }
        return self::$cache[ $table ];
    }

    /** Forget every answer. Tests use this. */
    public static function reset(): void {
        self::$cache = [];
    }
}
