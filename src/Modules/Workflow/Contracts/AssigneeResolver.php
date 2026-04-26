<?php
namespace TT\Modules\Workflow\Contracts;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\TaskContext;

/**
 * AssigneeResolver — resolves a TaskContext to the WP user IDs that
 * should receive a task. Resolvers are pluggable so templates declare
 * intent ("the head coach of this team") without knowing how that
 * resolves to an integer ID.
 *
 * The minors-assignment policy plugs in here: PlayerOrParentResolver
 * reads tt_config.tt_workflow_minors_assignment_policy + the player's
 * date_of_birth and routes accordingly without templates being aware.
 *
 * Sprint 1 ships four implementations:
 *   - RoleBasedResolver         — all users with a given WP role
 *   - TeamHeadCoachResolver     — head coach for a team
 *   - PlayerOrParentResolver    — minors-policy aware
 *   - LambdaResolver            — closure-based escape hatch for tests
 *                                 and one-off resolution
 *
 * TrialCaseStaffResolver lands in Phase 2 with the trial-input migration.
 */
interface AssigneeResolver {

    /**
     * Resolve to a list of WP user IDs. Empty array means "no assignee
     * found" — the engine logs and skips task creation rather than
     * creating an orphan.
     *
     * @return int[]
     */
    public function resolve( TaskContext $context ): array;
}
