<?php
namespace TT\Modules\Workflow\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Workflow\TaskStatus;

/**
 * TasksRepository — data access for tt_workflow_tasks.
 *
 * Sprint 1 ships the read + write surface that the engine and
 * (eventually) the inbox view need. Sprint 2 adds inbox-specific
 * filtering helpers. Sprint 5 dashboard adds aggregates.
 */
class TasksRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_workflow_tasks';
    }

    /**
     * Insert a task row. Returns the new task ID, or 0 on failure.
     *
     * @param array{
     *   template_key:string,
     *   assignee_user_id:int,
     *   due_at:string,
     *   player_id?:?int,
     *   team_id?:?int,
     *   activity_id?:?int,
     *   evaluation_id?:?int,
     *   goal_id?:?int,
     *   trial_case_id?:?int,
     *   parent_task_id?:?int,
     *   status?:string,
     *   spawned_by_step?:?string,
     * } $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
            'club_id'          => CurrentClub::id(),
            'template_key'     => (string) $data['template_key'],
            'assignee_user_id' => (int) $data['assignee_user_id'],
            'due_at'           => (string) $data['due_at'],
            'status'           => (string) ( $data['status'] ?? TaskStatus::OPEN ),
        ];
        foreach ( [ 'player_id', 'team_id', 'activity_id', 'evaluation_id', 'goal_id', 'trial_case_id', 'parent_task_id' ] as $col ) {
            if ( isset( $data[ $col ] ) && $data[ $col ] !== null ) {
                $row[ $col ] = (int) $data[ $col ];
            }
        }
        if ( ! empty( $data['spawned_by_step'] ) ) {
            $row['spawned_by_step'] = (string) $data['spawned_by_step'];
        }
        $ok = $wpdb->insert( $this->table(), $row );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Fetch a task by ID, or null when not found.
     *
     * @return array<string,mixed>|null
     */
    public function find( int $id ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d LIMIT 1",
            $id, CurrentClub::id()
        ), ARRAY_A );
        return is_array( $row ) ? $row : null;
    }

    /**
     * Update a task row by ID. Returns true on success (any row affected),
     * false on failure.
     *
     * @param array<string,mixed> $changes
     */
    public function update( int $id, array $changes ): bool {
        global $wpdb;
        if ( empty( $changes ) ) return true;
        $result = $wpdb->update( $this->table(), $changes, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return $result !== false;
    }

    /**
     * Mark a task completed and stamp completed_at + response_json. Returns
     * true on success.
     *
     * @param array<string,mixed> $response
     */
    public function complete( int $id, array $response ): bool {
        return $this->update( $id, [
            'status'       => TaskStatus::COMPLETED,
            'completed_at' => current_time( 'mysql' ),
            'response_json' => wp_json_encode( $response ),
        ] );
    }

    /**
     * Open + in-progress tasks for a user, ordered by due_at ascending.
     * Powers the inbox in Sprint 2.
     *
     * #0022 Phase 2 — accepts an optional filters array:
     *   template_key      → exact match
     *   status            → 'open'|'in_progress'|'overdue' subset
     *   due_within_days   → only tasks due within N days
     *   include_snoozed   → bool; default false (snoozed rows hidden)
     *
     * @param array<string,mixed> $filters
     * @return array<int,array<string,mixed>>
     */
    public function listActionableForUser( int $user_id, array $filters = [] ): array {
        global $wpdb;
        $where = [ 'assignee_user_id = %d', 'club_id = %d' ];
        $params = [ $user_id, CurrentClub::id() ];

        $statuses = is_array( $filters['status'] ?? null ) ? $filters['status'] : null;
        if ( $statuses ) {
            $clean = [];
            foreach ( $statuses as $s ) {
                $s = (string) $s;
                if ( in_array( $s, [ TaskStatus::OPEN, TaskStatus::IN_PROGRESS, TaskStatus::OVERDUE ], true ) ) {
                    $clean[] = $s;
                }
            }
            if ( $clean ) {
                $placeholders = implode( ',', array_fill( 0, count( $clean ), '%s' ) );
                $where[] = "status IN ($placeholders)";
                $params = array_merge( $params, $clean );
            }
        } else {
            $where[] = "status IN (%s, %s, %s)";
            $params[] = TaskStatus::OPEN;
            $params[] = TaskStatus::IN_PROGRESS;
            $params[] = TaskStatus::OVERDUE;
        }

        if ( ! empty( $filters['template_key'] ) ) {
            $where[] = 'template_key = %s';
            $params[] = (string) $filters['template_key'];
        }

        if ( isset( $filters['due_within_days'] ) ) {
            $days = (int) $filters['due_within_days'];
            if ( $days > 0 ) {
                $where[] = 'due_at <= %s';
                $params[] = date( 'Y-m-d H:i:s', current_time( 'timestamp' ) + ( $days * 86400 ) );
            }
        }

        if ( empty( $filters['include_snoozed'] ) ) {
            // Either snooze unset, or snooze in the past (already woken up).
            $where[] = '(snoozed_until IS NULL OR snoozed_until <= %s)';
            $params[] = current_time( 'mysql' );
        }

        $sql = "SELECT * FROM {$this->table()}
                WHERE " . implode( ' AND ', $where ) . "
                ORDER BY due_at ASC, id ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * #0022 Phase 2 — Snooze a task. `until` is a MySQL datetime string;
     * passing null clears the snooze.
     */
    public function snooze( int $id, ?string $until ): bool {
        return $this->update( $id, [ 'snoozed_until' => $until ] );
    }

    /**
     * #0022 Phase 2 — Skip a task without filling in a response. Used
     * by inbox bulk actions when a coach decides a task no longer
     * applies (e.g. player left the team).
     */
    public function skip( int $id ): bool {
        return $this->update( $id, [
            'status'       => TaskStatus::SKIPPED,
            'completed_at' => current_time( 'mysql' ),
        ] );
    }

    /** @return list<string> distinct template keys held by a user's tasks */
    public function templateKeysForUser( int $user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT template_key FROM {$this->table()}
              WHERE assignee_user_id = %d
                AND club_id = %d
                AND status IN ('open','in_progress','overdue')
              ORDER BY template_key ASC",
            $user_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? array_map( 'strval', $rows ) : [];
    }
}
