<?php
namespace TT\Infrastructure\Logging;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ErrorLogRepository — bounded persistent buffer for Logger
 * error/warning entries (#1360).
 *
 * `Logger::log()` calls `persist()` for error + warning levels in
 * addition to its `error_log()` write, so an operator can read recent
 * failures from wp-admin (Error Log page) or `GET /system/errors`
 * without hosting-panel / SSH access to the PHP error log.
 *
 * Hard rules:
 *   - `persist()` can NEVER take down the request. Every failure mode
 *     (table missing pre-migration, DB gone, JSON encoding hiccup)
 *     degrades silently — the `error_log()` write already happened.
 *   - The table never grows unbounded: every insert prunes to the
 *     newest MAX_ROWS rows.
 *   - No Logger calls inside this class — a failing insert that logged
 *     its own failure would recurse.
 */
class ErrorLogRepository {

    public const MAX_ROWS = 500;

    /**
     * Per-request memo: once an insert fails (most likely the table
     * doesn't exist yet because migration 0155 hasn't run), stop
     * trying for the rest of the request.
     */
    private static bool $unavailable = false;

    /**
     * Append one entry and prune the tail. Never throws.
     *
     * @param array<string,mixed> $context
     */
    public static function persist( string $level, string $message, array $context = [] ): void {
        if ( self::$unavailable ) return;

        try {
            global $wpdb;
            if ( ! $wpdb instanceof \wpdb ) return;
            $table = $wpdb->prefix . 'tt_error_log';

            // Suppress wpdb's own error printing/logging for the
            // duration — a missing table must not spam the output.
            $was_suppressed = $wpdb->suppress_errors( true );
            $ok = $wpdb->insert( $table, [
                'club_id'    => CurrentClub::id(),
                'level'      => substr( $level, 0, 16 ),
                'message'    => $message,
                'context'    => empty( $context ) ? null : (string) wp_json_encode( $context ),
                'created_at' => current_time( 'mysql' ),
            ] );

            if ( $ok === false ) {
                $wpdb->suppress_errors( $was_suppressed );
                self::$unavailable = true;
                return;
            }

            // Prune: find the id of the MAX_ROWS-th newest row and
            // drop everything older. Cap is per-table (single-tenant
            // today); a future multi-tenant install would move this
            // to a per-club window.
            $cutoff = $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$table} ORDER BY id DESC LIMIT 1 OFFSET %d",
                self::MAX_ROWS - 1
            ) );
            if ( $cutoff !== null ) {
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE id < %d", (int) $cutoff ) );
            }
            $wpdb->suppress_errors( $was_suppressed );
        } catch ( \Throwable $e ) {
            // Swallow everything — logging must never fatal the request.
            self::$unavailable = true;
        }
    }

    /**
     * Newest-first page of entries for the admin viewer / REST surface.
     *
     * @param array{level?: string, date_from?: string, date_to?: string, limit?: int, offset?: int} $filters
     * @return list<object> rows: id, club_id, level, message, context, created_at.
     */
    public function list( array $filters = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_error_log';
        if ( ! $this->tableExists() ) return [];

        [ $where, $params ] = self::buildWhere( $filters );
        $limit    = max( 1, min( 200, (int) ( $filters['limit'] ?? 100 ) ) );
        $offset   = max( 0, (int) ( $filters['offset'] ?? 0 ) );
        $params[] = $limit;
        $params[] = $offset;

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, club_id, level, message, context, created_at
               FROM {$table}{$where}
              ORDER BY id DESC
              LIMIT %d OFFSET %d",
            ...$params
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @param array{level?: string, date_from?: string, date_to?: string} $filters
     */
    public function count( array $filters = [] ): int {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_error_log';
        if ( ! $this->tableExists() ) return 0;

        [ $where, $params ] = self::buildWhere( $filters );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table}{$where}",
            ...$params
        ) );
    }

    /** Read-path guard so the viewer renders an empty state pre-migration. */
    public function tableExists(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_error_log';
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
    }

    /**
     * @param array{level?: string, date_from?: string, date_to?: string} $filters
     * @return array{0:string,1:array<int,mixed>}
     */
    private static function buildWhere( array $filters ): array {
        $clauses = [ 'club_id = %d' ];
        $params  = [ CurrentClub::id() ];

        $level = (string) ( $filters['level'] ?? '' );
        if ( in_array( $level, [ Logger::LEVEL_ERROR, Logger::LEVEL_WARNING ], true ) ) {
            $clauses[] = 'level = %s';
            $params[]  = $level;
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $clauses[] = 'created_at >= %s';
            $params[]  = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $clauses[] = 'created_at <= %s';
            $params[]  = (string) $filters['date_to'] . ' 23:59:59';
        }

        return [ ' WHERE ' . implode( ' AND ', $clauses ), $params ];
    }
}
