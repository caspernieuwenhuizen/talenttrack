<?php
namespace TT\Infrastructure\Activities;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;

/**
 * PlayerActivityReader — a player's view of one activity, localised.
 *
 * #3483 — renamed from `ActivitiesRepository`. That name was shared with
 * `TT\Modules\Activities\Repositories\ActivitiesRepository`, the write-path
 * repository behind wp-admin, REST, the line-up and the attendance readers —
 * and the two looked identical at a call site. #3390's sweep for the
 * planned-roster blind spot fixed the list in `FrontendMyActivitiesView` and
 * missed the detail screen behind it, which read through this class, so a
 * player could tap from a list that correctly said nothing into a detail
 * telling them they had attended a fixture two weeks away. The collision
 * hid which of the two classes the sweep had covered. It is a *reader*, and
 * the question it answers is a *player's*, which is what the name now says.
 * The module repository kept its name: it is what everybody means by "the
 * activities repository".
 *
 * #1078 — module-by-module rollout of #806's architectural sweep.
 * Pattern established in v4.17.2 / #1081 (Evaluations) and v4.20.18 /
 * #1077 (Goals). View code echoes `$row->activity_type_localised`
 * and `$row->attendance_status_localised`; bypass becomes
 * structurally impossible.
 *
 * Per-row shape (additive to whatever the join returned):
 *
 *   `activity_type_key`              raw code (back-compat — KPI joins)
 *   `activity_type_localised`        user-facing label, via
 *                                    `LabelTranslator::activityType()` which
 *                                    routes through LookupTranslator for
 *                                    operator-added rows (#1121).
 *   `attendance_status`              raw value from `tt_attendance.status`,
 *                                    `null` when the player has no
 *                                    attendance row for this activity
 *   `attendance_status_localised`    user-facing label, via
 *                                    `LabelTranslator::attendanceStatus()`,
 *                                    `null` when raw is null.
 *
 * `plan_state` (a workflow state, not lookup-backed) is intentionally
 * NOT pre-localised here — its enum is engine-internal and view
 * surfaces don't render it as a user-facing pill today.
 */
class PlayerActivityReader {

    /**
     * Single activity with the requesting player's attendance row
     * joined. Used by `FrontendMyActivitiesView::renderDetail()` so
     * the player can drill into a specific activity from the "My
     * activities" list.
     *
     * Returns null when the activity does not exist **or is not this
     * player's** — the two are deliberately indistinguishable, so the id
     * cannot be used to learn which activities exist.
     *
     * This used to return any activity in the academy by id, with the
     * player only joined for the attendance columns, and the docblock
     * called the permissiveness intentional, citing the #1149 family where
     * over-strict scoping produced false "not found"s. Those cases are all
     * the player's OWN history, and none needs "any id": an activity is
     * this player's when either
     *
     *   1. it is on the player's current team, or
     *   2. the player has any register row on it — `actual` or `expected`,
     *      as themselves or as a guest.
     *
     * Rule 2 is what keeps #1149's cases: a player who moved squad, a guest
     * appearance for another team, and a planned squad (#3800) all leave a
     * row. Plus the club, which the query did not check at all.
     *
     * #3451 — the join is scoped to the RECORDED register, which is what
     * #3390 did to the LIST this detail belongs to. The list lives on
     * `Modules\Activities\Repositories\ActivitiesRepository` and the detail
     * on this class, they share a name, and the fix reached one of them:
     * a player tapping through from a list that correctly said nothing
     * about a fixture two weeks away landed on a detail screen telling them
     * they had been present at it.
     */
    public function findForPlayer( int $activity_id, int $player_id ): ?object {
        if ( $activity_id <= 0 ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT a.*,
                    t.name AS team_name,
                    att.status   AS attendance_status,
                    att.notes    AS attendance_notes
               FROM {$p}tt_activities a
               LEFT JOIN {$p}tt_teams t ON a.team_id = t.id
               LEFT JOIN {$p}tt_attendance att
                      ON att.activity_id = a.id
                     AND att.record_type = 'actual'
                     AND ( att.player_id = %d OR att.guest_player_id = %d )
              WHERE a.id = %d
                AND a.club_id = %d
                AND (
                      a.team_id = ( SELECT pl.team_id FROM {$p}tt_players pl WHERE pl.id = %d )
                   OR EXISTS (
                        SELECT 1 FROM {$p}tt_attendance x
                         WHERE x.activity_id = a.id
                           AND x.record_type IN ( 'actual', 'expected' ) /* both-kinds-ok: either register makes it this player's activity */
                           AND ( x.player_id = %d OR x.guest_player_id = %d )
                      )
                )
              LIMIT 1",
            $player_id,
            $player_id,
            $activity_id,
            (int) \TT\Infrastructure\Tenancy\CurrentClub::id(),
            $player_id,
            $player_id,
            $player_id
        ) );

        if ( ! $row ) return null;
        self::hydrate( $row );
        return $row;
    }

    /**
     * Decorate an activity row in place with `activity_type_localised`
     * + `attendance_status_localised`. Raw fields stay for back-compat.
     */
    private static function hydrate( object $row ): void {
        $type_key   = (string) ( $row->activity_type_key ?? '' );
        $type_label = $type_key !== '' ? LabelTranslator::activityType( $type_key ) : null;
        if ( $type_label === null || $type_label === '' ) {
            // Fallback: humanise the raw key so custom types the
            // operator added without seeding a translation still
            // render legibly (Bespreking-style cases).
            $type_label = $type_key !== '' ? ucfirst( str_replace( '_', ' ', $type_key ) ) : '';
        }
        $row->activity_type_localised = $type_label;

        $att_status_raw = $row->attendance_status ?? null;
        $row->attendance_status_localised = ( $att_status_raw !== null && $att_status_raw !== '' )
            ? LabelTranslator::attendanceStatus( (string) $att_status_raw )
            : null;
    }
}
