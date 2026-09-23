<?php
namespace TT\Modules\Teams\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Frontend\FlashMessages;

/**
 * TeamStaffPrompt (#4007) — a team nobody is running says so, at the moment
 * it is created.
 *
 * Which player question does this help answer? *What does this player need
 * next?* — for a whole squad at once. Almost every alert and notification in
 * the plugin is addressed to a team's head coach, so a team without one goes
 * quiet: nothing about those players reaches anybody. Team 76 was created in
 * April with no staff at all and nothing pointed it out.
 *
 * ## Why a prompt and not an alert
 *
 * {@see \TT\Modules\Alerts\Definitions\TeamWithoutHeadCoachAlert} already
 * covers the standing condition, but deliberately only for teams that
 * **have players** — a coachless, playerless team is a placeholder for next
 * season, and alerting on those would fill the list with rows nobody intends
 * to act on. A team one second old is exactly that shape, which is the
 * window this prompt covers. That rule stays as it is.
 *
 * ## "Has a head coach" means today
 *
 * The same definition the alert and `TeamsRestController`'s head-coach
 * columns use: a `head_coach` functional-role assignment in `tt_team_people`
 * that has not ended, held by a person who is not archived. Written once
 * here so a third definition cannot drift in.
 *
 * ## Where the prompt appears
 *
 * In the shared frontend flash queue, so it renders on whatever page the
 * creating surface lands on — the team's own page after the wizard, the list
 * after the flat form — without either of them having to know about it.
 */
final class TeamStaffPrompt {

    /**
     * Fired after a team row is created, with the new team's id. The only
     * post-insert extension point on team creation; `create_team()` had
     * none, which is why nothing could react to a staffless team.
     */
    public const CREATED_HOOK = 'tt_team_created';

    /**
     * Announce the new team, then prompt for a head coach if it has none.
     * Called by every create path (the wizard's review step and the REST
     * create), so the prompt cannot depend on which one was used.
     */
    public static function afterCreate( int $team_id, string $team_name = '' ): void {
        if ( $team_id <= 0 ) return;

        do_action( self::CREATED_HOOK, $team_id );

        if ( self::hasHeadCoach( $team_id ) ) return;

        $name = trim( $team_name ) !== '' ? trim( $team_name ) : self::nameOf( $team_id );
        if ( $name === '' ) return;

        FlashMessages::add(
            FlashMessages::TYPE_WARNING,
            sprintf(
                /* translators: %s: the team's name */
                __( '%s has no head coach yet. Assign one under Staff on the team — notifications about a player go to their head coach, so a team without one receives none of them.', 'talenttrack' ),
                $name
            )
        );
    }

    /**
     * A live head-coach assignment held by a person who is still on the
     * books. An assignment that ended in June is not this team's head coach
     * in September, which is why `end_date` is part of the question.
     */
    public static function hasHeadCoach( int $team_id ): bool {
        global $wpdb;
        if ( $team_id <= 0 ) return false;
        $p = $wpdb->prefix;

        // The role join matches on `fr.id` alone, exactly as the alert and
        // the REST head-coach columns do: `role_key` carries a global unique
        // index, so there is one `head_coach` row to find, and adding a club
        // predicate here and not there is how two resolutions drift.
        $found = $wpdb->get_var( $wpdb->prepare(
            "SELECT tp.id
               FROM {$p}tt_team_people tp
               JOIN {$p}tt_people pe ON pe.id = tp.person_id
               JOIN {$p}tt_functional_roles fr ON fr.id = tp.functional_role_id
              WHERE tp.team_id = %d
                AND fr.role_key = 'head_coach'
                AND pe.archived_at IS NULL
                AND ( tp.end_date IS NULL OR tp.end_date >= CURDATE() )
              LIMIT 1",
            $team_id
        ) );

        return $found !== null && (int) $found > 0;
    }

    private static function nameOf( int $team_id ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d",
            $team_id
        ) );
        return is_string( $name ) ? trim( $name ) : '';
    }
}
