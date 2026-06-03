<?php
namespace TT\Infrastructure\Activities;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\LabelTranslator;

/**
 * ActivitiesRepository — read-only repository for activity records.
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
class ActivitiesRepository {

    /**
     * Single activity with the requesting player's attendance row
     * joined. Used by `FrontendMyActivitiesView::renderDetail()` so
     * the player can drill into a specific activity from the "My
     * activities" list.
     *
     * Returns null when the activity row doesn't exist. The caller
     * is responsible for any further authorization beyond the
     * implicit "the player has an attendance row" join — historic
     * scope-strictness on this surface has been intentionally
     * permissive (#1149 family of bugs).
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
                     AND ( att.player_id = %d OR att.guest_player_id = %d )
              WHERE a.id = %d
              LIMIT 1",
            $player_id,
            $player_id,
            $activity_id
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
