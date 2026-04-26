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
 * Resolution order:
 *   1. tt_teams.head_coach_id is a tt_people.id; resolve to wp_user_id.
 *   2. If that's empty/missing, fall back to the head_coach
 *      functional-role assignment in tt_team_people for this team.
 *
 * Returns an empty array (and logs under WP_DEBUG) if neither path
 * resolves — the engine will skip task creation for this context.
 */
class TeamHeadCoachResolver implements AssigneeResolver {

    /** @return int[] */
    public function resolve( TaskContext $context ): array {
        global $wpdb;
        if ( ! $context->team_id ) return [];

        $p = $wpdb->prefix;
        $team_id = (int) $context->team_id;

        // Path 1: tt_teams.head_coach_id (a tt_people row).
        $head_coach_person_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT head_coach_id FROM {$p}tt_teams WHERE id = %d LIMIT 1",
            $team_id
        ) );
        if ( $head_coach_person_id > 0 ) {
            $user_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT wp_user_id FROM {$p}tt_people WHERE id = %d LIMIT 1",
                $head_coach_person_id
            ) );
            if ( $user_id > 0 ) return [ $user_id ];
        }

        // Path 2: a head_coach functional-role assignment on this team.
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
