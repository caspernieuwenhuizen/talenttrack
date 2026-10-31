<?php
namespace TT\Modules\Workflow;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Prospects\ProspectScope;

/**
 * TaskAccess — who may open one workflow task.
 *
 * A task carries its template's form and, once completed, the answer that
 * was submitted: injury-check notes, a player's self-evaluation, a review.
 * Holding `tt_view_own_tasks` says a user has an inbox, not that every
 * task in the club is theirs to read. One task is readable by:
 *
 *   - its assignee;
 *   - a user who may complete it on someone else's behalf
 *     (`task_completion` create at global scope);
 *   - a user with a global `workflow_tasks` read;
 *   - for a task about a prospect: a user who reads prospects and sees
 *     that prospect on the onboarding pipeline, whose cards open the
 *     task behind each stage. Both halves ask what the pipeline itself
 *     asks, so the task and the card cannot disagree.
 *
 * Everyone else is answered as if the task did not exist.
 */
final class TaskAccess {

    /** @param array<string,mixed> $task a `tt_workflow_tasks` row */
    public static function canView( int $user_id, array $task ): bool {
        if ( $user_id <= 0 ) return false;
        if ( (int) ( $task['assignee_user_id'] ?? 0 ) === $user_id ) return true;
        if ( self::canCompleteOnBehalf( $user_id ) ) return true;
        if ( MatrixGate::can( $user_id, 'workflow_tasks', 'read', MatrixGate::SCOPE_GLOBAL ) ) return true;

        $prospect_id = (int) ( $task['prospect_id'] ?? 0 );
        return $prospect_id > 0
            && AuthorizationService::userCanOrMatrix( $user_id, 'tt_view_prospects' )
            && ProspectScope::canSee( $user_id, $prospect_id );
    }

    public static function canCompleteOnBehalf( int $user_id ): bool {
        return $user_id > 0
            && MatrixGate::can( $user_id, 'task_completion', 'create', MatrixGate::SCOPE_GLOBAL );
    }
}
