<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityTeamScope — one answer to "may this user open this activity?"
 * (#3151).
 *
 * The capabilities that guard the match-day surfaces — `tt_edit_activities`,
 * `tt_view_activities` — are held club-wide by every coach. They answer
 * *whether* a coach runs matches, never *whose* matches. The list views knew
 * that and narrowed their queries to the viewer's teams; the detail views
 * they link to read `?activity_id=` straight out of the request and rendered
 * whatever came back, squad and coach notes included.
 *
 * Three views and two REST controllers had grown three different opinions
 * about that question, which is how they came to disagree. They now share
 * this one.
 *
 * Scope, in order:
 *   1. the dispatcher's admin flag (`tt_edit_settings`), when supplied;
 *   2. global-scope read on `activities` — the academy-wide lens Head of
 *      Development and Academy Admin already hold (`AllTeamsScope`);
 *   3. the activity's team appearing in `QueryHelpers::get_teams_for_coach()`,
 *      the single source of truth for a coach's team grants.
 *
 * This is a *scope* answer, not a capability one: callers keep their own
 * capability check and add this. A refusal here is 403 — the capability
 * model saying no — never 402, which belongs to the plan (#3104).
 */
final class ActivityTeamScope {

    /**
     * May this user reach activity data for this team?
     *
     * @param bool|null $is_admin The dispatcher's flag when the caller has
     *                            one; resolved from the capability when null,
     *                            so REST permission callbacks can omit it.
     */
    public static function coversTeam( int $user_id, int $team_id, ?bool $is_admin = null ): bool {
        if ( $user_id <= 0 || $team_id <= 0 ) return false;

        if ( $is_admin === null ) {
            $is_admin = current_user_can( 'tt_edit_settings' );
        }
        if ( $is_admin ) return true;

        if ( AllTeamsScope::canSeeAllTeamsActivities( $user_id ) ) return true;

        foreach ( QueryHelpers::get_teams_for_coach( $user_id ) as $team ) {
            if ( (int) ( $team->id ?? 0 ) === $team_id ) return true;
        }
        return false;
    }

    /**
     * The same question asked about an activity id, for callers that have
     * not loaded the row. An activity that does not exist (or belongs to
     * another club) is not covered.
     */
    public static function coversActivity( int $user_id, int $activity_id, ?bool $is_admin = null ): bool {
        $team_id = self::teamIdForActivity( $activity_id );
        if ( $team_id === null ) return false;
        return self::coversTeam( $user_id, $team_id, $is_admin );
    }

    /**
     * The activity's team, or null when there is no such activity in this
     * club. Callers that want to distinguish "not yours" from "not there"
     * — the views do, so their notices stay accurate — read this first.
     */
    public static function teamIdForActivity( int $activity_id ): ?int {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        $team_id = $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_activities
              WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return $team_id === null ? null : (int) $team_id;
    }

    /**
     * The sentence every refusing surface prints. Plain text — the caller
     * escapes and wraps it — but shared, so the ratings grid, the REST
     * layer and the three match-day views all say the one thing. Callers
     * that want the markup too use `refusalNotice()`.
     */
    public static function refusalMessage(): string {
        return __( "You do not coach this activity's team.", 'talenttrack' );
    }

    /** The refusal wrapped in the standard notice paragraph, escaped. */
    public static function refusalNotice(): string {
        return '<p class="tt-notice">' . esc_html( self::refusalMessage() ) . '</p>';
    }
}
