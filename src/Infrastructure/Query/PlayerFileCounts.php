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
        // v3.110.3 — restrict to completed activities. Mirrors the
        // tab's render query (FrontendPlayerDetailView::renderActivitiesTab)
        // so the badge and the tab list always agree on scope.
        //
        // #2521 — "completed" is the coach-set `activity_status_key`, not
        // `plan_state` (which defaults to 'completed' on every row the
        // planner did not create, so a still-planned session counted).
        // #2522 — `record_type = 'actual'`: a player who is on the plan
        // AND has a recorded row was counted twice.
        $completed  = ActivityLifecycle::completedClause( 'a' );
        // #2862 — COUNT(DISTINCT att.activity_id), not COUNT(*). The tab
        // counts activities, and while `record_type = 'actual'` should mean
        // one row per player per activity, the badge should not be the thing
        // that discovers otherwise: a stray second recorded row would inflate
        // the number beside a list that renders one entry.
        //
        // The badge deliberately keeps the `activity_status_key` lifecycle
        // definition from #2521. Switching it to the `plan_state` filter the
        // tab's list uses would make the two agree — and would reintroduce
        // exactly what #2521 fixed, because `plan_state` defaults to
        // 'completed' on every row the planner did not create, so
        // still-planned sessions would count again.
        //
        // So the badge answers "activities attended" and the list also shows
        // what is coming up. They agree on every completed activity and
        // diverge only on upcoming ones. Whether the badge should instead
        // count everything the tab renders is a product question, raised on
        // the issue rather than settled quietly here.
        $activities = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT att.activity_id)
               FROM {$p}tt_attendance att
               JOIN {$p}tt_activities a ON a.id = att.activity_id
              WHERE att.player_id = %d
                AND att.is_guest = 0
                AND att.record_type = 'actual'
                AND a.archived_at IS NULL
                AND {$completed}",
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
