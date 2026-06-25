<?php
namespace TT\Modules\DataBrowser;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * DataBrowserRepository — read-only, club-scoped raw row reads.
 *
 * Never writes. Every query is scoped to the active club when the table
 * carries a `club_id` column, so a second tenant on the install can never
 * read another tenant's rows (CLAUDE.md §4). The table name is always a
 * key validated against {@see SchemaIntrospector} before it reaches SQL —
 * never raw user input — and column names come from the live schema, so
 * identifier interpolation is safe. Values bind through $wpdb->prepare.
 */
class DataBrowserRepository {

    public const PER_PAGE     = 25;
    public const MAX_PER_PAGE = 100;

    /**
     * One page of raw rows.
     *
     * @return array<int,object>
     */
    public static function rows( string $key, int $page, int $per_page, string $search = '', ?int $pk = null ): array {
        if ( ! SchemaIntrospector::exists( $key ) ) return [];

        global $wpdb;
        $table    = SchemaIntrospector::fullName( $key );
        $per_page = max( 1, min( self::MAX_PER_PAGE, $per_page ) );
        $offset   = max( 0, ( max( 1, $page ) - 1 ) * $per_page );

        [ $where, $params ] = self::buildWhere( $key, $search, $pk );
        $order = self::orderColumn( $key );

        $params[] = $per_page;
        $params[] = $offset;

        $sql = "SELECT * FROM `{$table}` WHERE {$where} ORDER BY `{$order}` DESC LIMIT %d OFFSET %d";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        return is_array( $rows ) ? $rows : [];
    }

    /** Total rows matching the same scope/filters (for pagination). */
    public static function count( string $key, string $search = '', ?int $pk = null ): int {
        if ( ! SchemaIntrospector::exists( $key ) ) return 0;

        global $wpdb;
        $table = SchemaIntrospector::fullName( $key );
        [ $where, $params ] = self::buildWhere( $key, $search, $pk );

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE {$where}";
        return (int) ( $params
            ? $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) )
            : $wpdb->get_var( $sql ) );
    }

    /**
     * Build the shared WHERE clause + bound params: club scope (when the
     * table has club_id), optional primary-key filter, optional search
     * across string columns.
     *
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function buildWhere( string $key, string $search, ?int $pk ): array {
        global $wpdb;
        $where  = '1=1';
        $params = [];

        if ( SchemaIntrospector::hasColumn( $key, 'club_id' ) ) {
            $where   .= ' AND club_id = %d';
            $params[] = CurrentClub::id();
        }

        if ( $pk !== null && SchemaIntrospector::hasColumn( $key, 'id' ) ) {
            $where   .= ' AND id = %d';
            $params[] = $pk;
        }

        $search = trim( $search );
        if ( $search !== '' ) {
            $string_cols = array_values( array_filter(
                SchemaIntrospector::columns( $key ),
                static fn( $c ) => $c->is_string
            ) );
            if ( $string_cols ) {
                $like = '%' . $wpdb->esc_like( $search ) . '%';
                $ors  = [];
                foreach ( $string_cols as $c ) {
                    $ors[]    = "`{$c->name}` LIKE %s";
                    $params[] = $like;
                }
                $where .= ' AND ( ' . implode( ' OR ', $ors ) . ' )';
            }
        }

        return [ $where, $params ];
    }

    /** Prefer `id` for ordering; fall back to the first column. */
    private static function orderColumn( string $key ): string {
        if ( SchemaIntrospector::hasColumn( $key, 'id' ) ) return 'id';
        $cols = SchemaIntrospector::columnNames( $key );
        return $cols[0] ?? 'id';
    }
}
