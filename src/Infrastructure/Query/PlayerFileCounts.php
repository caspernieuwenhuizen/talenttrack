<?php
namespace TT\Infrastructure\Query;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PlayerFileCounts — single source for the player-file tab badge counts.
 *
 * One method, one round-trip per tab type, mirroring the existing
 * SELECT shapes used by the per-tab renderers in FrontendPlayerDetailView.
 * Result keys match the tab slugs so the view can index directly.
 */
final class PlayerFileCounts {

    /**
     * @return array{goals:int, evaluations:int, activities:int, pdp:int, trials:int, notes:int, measurements:int, media:int}
     */
    public static function for( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $goals = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_goals WHERE player_id = %d AND archived_at IS NULL",
            $player_id
        ) );
        // The evaluation badge count and the evaluations-tab list query
        // (FrontendPlayerDetailView::renderEvaluationsTab) must agree
        // on the same scope, otherwise the operator sees a non-zero
        // badge with an empty tab. Pin both to `(player_id, club_id,
        // archived_at IS NULL)`.
        $evaluations = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_evaluations WHERE player_id = %d AND club_id = %d AND archived_at IS NULL",
            $player_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );
        // v3.110.3 — mirrors the tab's render query
        // (FrontendPlayerDetailView::renderActivitiesTab) so the badge and
        // the tab list always agree on scope. That intent is unchanged; what
        // changed in #2862 is that they now actually do.
        //
        // #2522 — a player on the plan who also has a recorded row was
        // counted twice. DISTINCT on the activity handles that now.
        //
        // #2862 — COUNT(DISTINCT att.activity_id), not COUNT(*). The tab
        // counts activities, and while `record_type = 'actual'` should mean
        // one row per player per activity, the badge should not be the thing
        // that discovers otherwise: a stray second recorded row would inflate
        // the number beside a list that renders one entry.
        //
        // Decided 2026-08-26: the badge counts **what the tab renders**, not
        // what the player attended. A number sitting on a tab is read as
        // "how many things are in here", and a badge saying 14 above a list
        // of 19 rows is the disagreement the pilot reported.
        //
        // So this mirrors `ActivitiesRepository::listForPlayer()`'s filter
        // set exactly — same plan states, same past-completed rule, same
        // archived guard — minus its 25-row recent-window cap, because the
        // badge is a total and the list is a window.
        //
        // #2521 is not being reverted, but it does need reading first. Its
        // point was that `plan_state` defaults to 'completed' on rows the
        // planner never created, so a plan-state filter counts still-planned
        // sessions. That was a bug when the badge claimed to count
        // *attendance*. It is not one now: those same rows are exactly what
        // the list puts on screen, so counting them is what makes the two
        // agree. The `activity_status_key` question moves to the list, which
        // is where "should a planner-less row show as completed" actually
        // belongs.
        //
        // `record_type` is deliberately absent for the same reason: the list
        // shows planned activities, which have only an `expected` row.
        // DISTINCT on the activity is what stops a player holding both rows
        // counting twice.
        $activities = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT att.activity_id)
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND a.archived_at IS NULL
                AND a.plan_state IN ( 'completed', 'planned', 'scheduled' )
                AND ( ( a.plan_state = 'completed' AND a.session_date <= CURDATE() )
                      OR a.plan_state IN ( 'planned', 'scheduled' ) )",
            $player_id
        ) );
        $pdp = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_pdp_files WHERE player_id = %d",
            $player_id
        ) );
        $trials = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_trial_cases WHERE player_id = %d AND archived_at IS NULL",
            $player_id
        ) );
        // #0085 — notes count for the new Notes tab badge. Only counts
        // visible (non-deleted) messages so the badge tracks what the
        // viewer actually sees in the tab.
        $notes_table = $p . 'tt_thread_messages';
        $notes = 0;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $notes_table ) ) === $notes_table ) {
            $notes = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$notes_table}
                  WHERE thread_type = 'player' AND thread_id = %d AND deleted_at IS NULL",
                $player_id
            ) );
        }

        // #1892 — measurements badge. Distinct tests this player has a
        // non-archived result for, club-scoped, so the badge agrees with
        // the Measurements tab's per-test rows.
        $measurements = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT definition_id) FROM {$p}tt_measurement_results
              WHERE player_id = %d AND club_id = %d AND archived_at IS NULL",
            $player_id, \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        // #2717 — media badge. Mirrors MediaRepository::listForEntity()'s
        // scope exactly (club on both tables, non-archived) so the badge
        // and the tab's tile count cannot disagree. Joined through
        // tt_media rather than counting link rows, because the link table
        // is polymorphic and carries no archived state of its own.
        $media = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*)
               FROM {$p}tt_media m
               INNER JOIN {$p}tt_media_links l ON l.media_id = m.id
              WHERE l.entity_type = %s
                AND l.entity_id = %d
                AND m.archived_at IS NULL
                AND " . QueryHelpers::clubScopeWhere( 'm' ) . "
                AND " . QueryHelpers::clubScopeWhere( 'l' ),
            \TT\Modules\Media\MediaEntityType::PLAYER,
            $player_id
        ) );

        return [
            'goals'        => $goals,
            'evaluations'  => $evaluations,
            'activities'   => $activities,
            'pdp'          => $pdp,
            'trials'       => $trials,
            'notes'        => $notes,
            'measurements' => $measurements,
            'media'        => $media,
        ];
    }
}
