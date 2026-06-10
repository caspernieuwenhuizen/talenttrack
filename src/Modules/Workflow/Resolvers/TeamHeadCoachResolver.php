<?php
namespace TT\Modules\Workflow\Resolvers;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\TaskContext;

/**
 * TeamHeadCoachResolver — returns the head coach's WP user ID for the
 * team given on the TaskContext. Used by the post-match evaluation
 * template to route the per-player tasks to the team's head coach.
 *
 * Resolution: the head_coach functional-role assignment in
 * tt_team_people for this team. Pre-#1315 also consulted a legacy
 * `tt_teams.head_coach_id` column, but that column held a WP user ID
 * everywhere else in the codebase while this resolver mis-treated it
 * as a `tt_people.id`. The legacy path was silently broken since
 * v3.110.200 dropped the dropdown; the column itself was retired in
 * #1315 — `tt_team_people` is now the single source of truth.
 *
 * Returns an empty array (and logs under WP_DEBUG) if no head coach
 * resolves — the engine will skip task creation for this context.
 */
class TeamHeadCoachResolver implements AssigneeResolver {

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        global $wpdb;
        if ( ! $context->team_id ) return [];

        $p = $wpdb->prefix;
        $team_id = (int) $context->team_id;

        // The head_coach functional-role assignment on this team.
        $user_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT pe.wp_user_id
             FROM {$p}tt_team_people tp
             INNER JOIN {$p}tt_functional_roles fr ON tp.functional_role_id = fr.id
             INNER JOIN {$p}tt_people pe ON tp.person_id = pe.id
             WHERE tp.team_id = %d
               AND fr.role_key = %s
             ORDER BY tp.id ASC
             LIMIT 1",
            $team_id,
            'head_coach'
        ) );
        if ( $user_id > 0 ) return [ $user_id ];
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[TalentTrack workflow] TeamHeadCoachResolver: no head coach found for team_id=%d',
                $team_id
            ) );
        }
        return [];
    }
}
