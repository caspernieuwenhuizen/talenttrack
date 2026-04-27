<?php
namespace TT\Modules\Workflow\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

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
     * } $data
     */
    public function create( array $data ): int {
        global $wpdb;
        $row = [
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
            "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id
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
        $result = $wpdb->update( $this->table(), $changes, [ 'id' => $id ] );
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
     * @return array<int,array<string,mixed>>
     */
    public function listActionableForUser( int $user_id ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
             WHERE assignee_user_id = %d
               AND status IN ('open','in_progress','overdue')
             ORDER BY due_at ASC, id ASC",
            $user_id
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }
}
