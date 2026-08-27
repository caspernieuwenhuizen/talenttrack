<?php
namespace TT\Modules\Workflow\Resolvers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Recipients\TeamHeadCoachLookup;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * TeamHeadCoachResolver — returns the head coach's WP user ID for the
 * team given on the TaskContext. Used by the post-match evaluation
 * template to route the per-player tasks to the team's head coach.
 *
 * The resolution itself lives in
 * `Infrastructure\Recipients\TeamHeadCoachLookup` (#2719), because the
 * alerts engine needs the same answer and used to compute it with its
 * own copy of the same join. This class is the Workflow-shaped adapter:
 * it turns a `TaskContext` into the lookup's argument and the lookup's
 * answer into the assignee list the engine expects.
 *
 * Returns an empty array (and logs under WP_DEBUG) if no head coach
 * resolves — the engine will skip task creation for this context.
 */
class TeamHeadCoachResolver implements AssigneeResolver {

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        if ( ! $context->team_id ) return [];

        $team_id = (int) $context->team_id;
        $user_id = TeamHeadCoachLookup::forTeam( $team_id );

        if ( $user_id !== null && $user_id > 0 ) return [ $user_id ];

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[TalentTrack workflow] TeamHeadCoachResolver: no head coach found for team_id=%d',
                $team_id
            ) );
        }

        return [];
    }
}
