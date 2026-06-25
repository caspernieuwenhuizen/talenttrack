<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * SchemaIntrospector — live, read-only view of the TalentTrack schema.
 *
 * There is no machine-readable schema registry in this repo; the schema
 * is spread across 175+ migrations. The reliable source of truth for
 * "which tables and columns exist right now" is the live database, so
 * the Data Browser reads INFORMATION_SCHEMA rather than parsing
 * migration files. Everything here is read-only and per-request cached.
 *
 * A "table key" is the table name without the WordPress prefix, e.g.
 * `tt_players`. Keys are the identifiers used in URLs and the REST API.
 * Only `tt_*` tables are introspected — never wp_ core tables.
 */
class SchemaIntrospector {

    /** @var array<string,object>|null key => { name, key, approx_rows } */
    private static $tables = null;

    /** @var array<string,array<int,object>> key => column rows */
    private static $columns = [];

    /**
     * All browsable TalentTrack tables, keyed by table key (`tt_*`).
     *
     * @return array<string,object> key => { key, full, approx_rows }
     */
    public static function tables(): array {
        if ( self::$tables !== null ) return self::$tables;

        global $wpdb;
        self::$tables = [];

        $like = $wpdb->esc_like( $wpdb->prefix . 'tt_' ) . '%';
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT TABLE_NAME AS name, TABLE_ROWS AS approx_rows '
            . 'FROM INFORMATION_SCHEMA.TABLES '
            . 'WHERE TABLE_SCHEMA = %s AND TABLE_NAME LIKE %s '
            . 'ORDER BY TABLE_NAME ASC',
            DB_NAME,
            $like
        ) );

        if ( ! is_array( $rows ) ) return self::$tables;

        $prefix_len = strlen( $wpdb->prefix );
        foreach ( $rows as $r ) {
            $full = (string) $r->name;
            $key  = substr( $full, $prefix_len );
            if ( strpos( $key, 'tt_' ) !== 0 ) continue;
            self::$tables[ $key ] = (object) [
                'key'        => $key,
                'full'       => $full,
                'approx_rows'=> (int) $r->approx_rows,
            ];
        }
        return self::$tables;
    }

    /** Whether a table key is a real, browsable `tt_*` table. */
    public static function exists( string $key ): bool {
        return isset( self::tables()[ $key ] );
    }

    /** Full prefixed table name for a validated key, or '' if unknown. */
    public static function fullName( string $key ): string {
        $tables = self::tables();
        return isset( $tables[ $key ] ) ? (string) $tables[ $key ]->full : '';
    }

    /**
     * Columns for a table, in declaration order.
     *
     * @return array<int,object> { name, type, nullable (bool), key, is_string (bool) }
     */
    public static function columns( string $key ): array {
        if ( isset( self::$columns[ $key ] ) ) return self::$columns[ $key ];
        if ( ! self::exists( $key ) ) return self::$columns[ $key ] = [];

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            'SELECT COLUMN_NAME AS name, COLUMN_TYPE AS type, '
            . 'IS_NULLABLE AS nullable, COLUMN_KEY AS col_key, DATA_TYPE AS data_type '
            . 'FROM INFORMATION_SCHEMA.COLUMNS '
            . 'WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s '
            . 'ORDER BY ORDINAL_POSITION ASC',
            DB_NAME,
            self::fullName( $key )
        ) );

        $out = [];
        if ( is_array( $rows ) ) {
            $string_types = [ 'char', 'varchar', 'text', 'tinytext', 'mediumtext', 'longtext' ];
            foreach ( $rows as $r ) {
                $out[] = (object) [
                    'name'      => (string) $r->name,
                    'type'      => (string) $r->type,
                    'nullable'  => strtoupper( (string) $r->nullable ) === 'YES',
                    'key'       => (string) $r->col_key,
                    'is_string' => in_array( strtolower( (string) $r->data_type ), $string_types, true ),
                ];
            }
        }
        return self::$columns[ $key ] = $out;
    }

    /** Column names for a table (cheap helper for membership checks). */
    public static function columnNames( string $key ): array {
        return array_map( static fn( $c ) => $c->name, self::columns( $key ) );
    }

    /** Whether a table carries a given column (e.g. `club_id`). */
    public static function hasColumn( string $key, string $column ): bool {
        return in_array( $column, self::columnNames( $key ), true );
    }
}
