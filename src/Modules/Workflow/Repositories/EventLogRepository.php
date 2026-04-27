<?php
namespace TT\Modules\Workflow\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * EventLogRepository — data access for tt_workflow_event_log (#0022
 * Phase 3).
 *
 * Every event-typed trigger firing writes a row here before dispatch.
 * Status transitions:
 *
 *   pending   → processed   (dispatch ran, tasks_created persisted)
 *   pending   → failed      (dispatch threw, error_message persisted)
 *   failed    → pending     (admin retry button: clears error, increments retries)
 *
 * The log is bounded — TasksRepository's overdue sweep will trim
 * processed rows older than 90 days in a follow-up. For Phase 3 we
 * keep everything so the admin can see the full history.
 */
class EventLogRepository {

    public const STATUS_PENDING   = 'pending';
    public const STATUS_PROCESSED = 'processed';
    public const STATUS_FAILED    = 'failed';

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_workflow_event_log';
    }

    /**
     * Insert a pending row. Returns the new id.
     *
     * @param array<int,mixed> $args
     */
    public function recordFiring( string $event_hook, string $template_key, array $args ): int {
        global $wpdb;
        $payload = self::serializeArgs( $args );
        $ok = $wpdb->insert( $this->table(), [
            'event_hook'   => $event_hook,
            'template_key' => $template_key,
            'args_json'    => $payload,
            'status'       => self::STATUS_PENDING,
            'retries'      => 0,
        ] );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Mark a log row as processed and stamp the resulting task ids.
     *
     * @param int[] $task_ids
     */
    public function markProcessed( int $log_id, array $task_ids ): bool {
        global $wpdb;
        $ok = $wpdb->update( $this->table(), [
            'status'        => self::STATUS_PROCESSED,
            'processed_at'  => current_time( 'mysql' ),
            'tasks_created' => wp_json_encode( array_values( array_map( 'intval', $task_ids ) ) ),
            'error_message' => null,
        ], [ 'id' => $log_id ] );
        return $ok !== false;
    }

    public function markFailed( int $log_id, string $error_message ): bool {
        global $wpdb;
        $ok = $wpdb->update( $this->table(), [
            'status'        => self::STATUS_FAILED,
            'error_message' => $error_message,
        ], [ 'id' => $log_id ] );
        return $ok !== false;
    }

    public function find( int $log_id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1",
            $log_id
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listRecent( array $filters = [], int $limit = 100 ): array {
        global $wpdb;
        $where = [ '1=1' ];
        $params = [];
        if ( ! empty( $filters['status'] ) ) {
            $where[] = 'status = %s';
            $params[] = (string) $filters['status'];
        }
        if ( ! empty( $filters['template_key'] ) ) {
            $where[] = 'template_key = %s';
            $params[] = (string) $filters['template_key'];
        }
        $sql = "SELECT * FROM {$this->table()}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY id DESC LIMIT %d";
        $params[] = max( 1, min( 500, $limit ) );
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /** Increment retries and return the new count. */
    public function incrementRetries( int $log_id ): int {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET retries = retries + 1 WHERE id = %d",
            $log_id
        ) );
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT retries FROM {$this->table()} WHERE id = %d", $log_id
        ) );
    }

    public function counts(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT status, COUNT(*) AS n FROM {$this->table()} GROUP BY status",
            ARRAY_A
        );
        $out = [
            self::STATUS_PENDING   => 0,
            self::STATUS_PROCESSED => 0,
            self::STATUS_FAILED    => 0,
        ];
        foreach ( (array) $rows as $r ) {
            $out[ (string) $r['status'] ] = (int) $r['n'];
        }
        return $out;
    }

    /**
     * @param array<int,mixed> $args
     */
    public static function serializeArgs( array $args ): string {
        // Strip closures + objects we can't serialise; keep ints/strings/arrays.
        $clean = [];
        foreach ( $args as $i => $a ) {
            if ( is_scalar( $a ) || is_array( $a ) || $a === null ) {
                $clean[ $i ] = $a;
            } elseif ( is_object( $a ) && method_exists( $a, '__toString' ) ) {
                $clean[ $i ] = (string) $a;
            } else {
                $clean[ $i ] = '<unserializable:' . ( is_object( $a ) ? get_class( $a ) : gettype( $a ) ) . '>';
            }
        }
        $json = wp_json_encode( $clean );
        return is_string( $json ) ? $json : '[]';
    }

    /** @return array<int,mixed> */
    public static function decodeArgs( string $payload ): array {
        if ( $payload === '' ) return [];
        $decoded = json_decode( $payload, true );
        return is_array( $decoded ) ? $decoded : [];
    }
}
