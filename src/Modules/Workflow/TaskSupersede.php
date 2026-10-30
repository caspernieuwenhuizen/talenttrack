<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Repositories\TasksRepository;

/**
 * TaskSupersede (#4287) — retires a template's still-actionable tasks for
 * one player when the date they were due on no longer holds, so a moved
 * date leaves one reminder instead of two. Superseded tasks are skipped,
 * not deleted, so the inbox history keeps them.
 */
final class TaskSupersede {

    private TasksRepository $tasks;

    public function __construct( ?TasksRepository $tasks = null ) {
        $this->tasks = $tasks ?? new TasksRepository();
    }

    /**
     * Skip the active tasks of `$template_key` for `$player_id`. A task due
     * on `$keep_due_at` stays, so a caller can supersede and then dispatch
     * the current date without touching the task that already matches it.
     * Other players' tasks are never touched. Returns how many were skipped.
     */
    public function forPlayer( string $template_key, int $player_id, ?string $keep_due_at = null ): int {
        if ( $player_id <= 0 ) return 0;

        $keep = $keep_due_at === null ? null : TaskEngine::normalizeDueAt( $keep_due_at );

        $skipped = 0;
        foreach ( $this->tasks->listActiveForPlayer( $template_key, $player_id ) as $task ) {
            if ( $keep !== null && (string) ( $task['due_at'] ?? '' ) === $keep ) continue;
            if ( $this->tasks->skip( (int) $task['id'] ) ) {
                $skipped++;
            }
        }
        return $skipped;
    }
}
