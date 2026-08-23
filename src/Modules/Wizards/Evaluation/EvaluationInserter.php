<?php
namespace TT\Modules\Wizards\Evaluation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * EvaluationInserter (#0072 follow-up) — single-row insert helper.
 *
 * Extracted from ReviewStep so the per-row REST endpoint can call into
 * the same code path the PHP submit uses. One call writes one
 * `tt_evaluations` row + N `tt_eval_ratings` rows under one DB
 * transaction-of-convenience (sequential inserts; we accept the small
 * window where a fatal between rows leaves a partially-rated
 * evaluation, same as the v3.75.0 batch behaviour).
 */
final class EvaluationInserter {

    /**
     * @param array{
     *   activity_id?: int,
     *   eval_date?: string,
     *   coach_id?: int,
     *   player_id: int,
     *   ratings: array<int|string,int|string>,
     *   notes?: string,
     *   player_feedback?: string,
     * } $row
     * @return int|\WP_Error  evaluation id on success, WP_Error on failure
     */
    public static function insert( array $row ) {
        global $wpdb;
        $p = $wpdb->prefix;

        $player_id = (int) ( $row['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return new \WP_Error( 'no_player', __( 'No player id supplied.', 'talenttrack' ) );
        }

        $coach_id = (int) ( $row['coach_id'] ?? get_current_user_id() );
        $aid      = (int) ( $row['activity_id'] ?? 0 );
        $eval_date = (string) ( $row['eval_date'] ?? current_time( 'Y-m-d' ) );

        // v3.110.105 — `eval_type_id` is now a first-class field on
        // the payload. When the caller passes one we honour it.
        // When they don't AND there's an `activity_id`, derive the
        // type from the activity's `activity_type_key` (matching on
        // `tt_lookups[eval_type].name`). Closes the gap that left
        // mark-attendance-wizard-written evals without a type and
        // made the edit form's Type dropdown render blank.
        $eval_type_id = (int) ( $row['eval_type_id'] ?? 0 );
        if ( $eval_type_id <= 0 && $aid > 0 ) {
            $eval_type_id = self::evalTypeIdForActivity( $aid );
        }

        $insert = [
            'club_id'     => CurrentClub::id(),
            'player_id'   => $player_id,
            'coach_id'    => $coach_id,
            'eval_date'   => $eval_date,
            'notes'       => (string) ( $row['notes'] ?? '' ),
            // #1386 — optional player-facing feedback (NULL when absent).
            'player_feedback' => (string) ( $row['player_feedback'] ?? '' ),
        ];
        if ( $aid > 0 )         $insert['activity_id']  = $aid;
        if ( $eval_type_id > 0 ) $insert['eval_type_id'] = $eval_type_id;

        $ok = $wpdb->insert( "{$p}tt_evaluations", $insert );
        if ( $ok === false ) {
            return new \WP_Error( 'insert_failed', __( 'Could not write evaluation row.', 'talenttrack' ) );
        }
        $eval_id = (int) $wpdb->insert_id;
        if ( $eval_id <= 0 ) {
            return new \WP_Error( 'insert_failed', __( 'Could not write evaluation row.', 'talenttrack' ) );
        }

        \TT\Modules\DemoData\DemoMode::tagIfActive( 'evaluation', $eval_id );

        // v3.110.x — every rating row carries `club_id` (see same fix in
        // EvaluationsRestController::write_ratings).
        $club_id = CurrentClub::id();
        $ratings = is_array( $row['ratings'] ?? null ) ? (array) $row['ratings'] : [];
        // #1067 — rating column is DECIMAL(4,1) so half-step values
        // (e.g. 7.5) flow through unmodified. The cast to float +
        // round-to-0.5 guards against legacy callers passing strings
        // or non-snapped floats; the rating-input component snaps on
        // the client and the wizard validate() snaps on the server,
        // so this is defense.
        foreach ( $ratings as $cat_id => $val ) {
            $val = (float) $val;
            if ( $val <= 0 ) continue;
            $val = round( $val * 2 ) / 2;
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'club_id'       => $club_id,
                'evaluation_id' => $eval_id,
                'category_id'   => (int) $cat_id,
                'rating'        => $val,
            ] );
        }

        /**
         * #2731 — the wizard's writer never fired this, so an evaluation
         * created here was invisible to everything listening: the workflow
         * templates subscribed to it, and now the alerts engine. Same class
         * of gap as the REST activity path that #24 fixed — one writer
         * announcing itself and its sibling staying quiet.
         *
         * Documented in `docs/hooks-and-filters.md`; args match the REST
         * controller's (player first, evaluation second).
         */
        do_action( 'tt_evaluation_saved', $player_id, $eval_id );

        return $eval_id;
    }

    /**
     * #2414 — write ratings for one player on one activity, creating the
     * evaluation row only when there isn't one yet.
     *
     * The ratings grid re-saves the same activity repeatedly (that is what
     * a grid is for), so a plain insert would pile up duplicate evaluations
     * for one player on one session. This finds the existing evaluation for
     * (player, activity) and upserts each category onto it; with none, it
     * falls through to insert() so both paths share one writer and the grid
     * can never drift from the wizard.
     *
     * `$ratings` is category_id => value. A value of null / '' / <= 0 means
     * "not rated": the category is left untouched rather than written as a
     * zero, so a blank cell never destroys an existing score.
     *
     * @param array<int|string, int|float|string|null> $ratings
     * @return int|\WP_Error evaluation id
     */
    public static function upsertForActivity( int $player_id, int $activity_id, array $ratings, ?int $coach_id = null ) {
        if ( $player_id <= 0 ) {
            return new \WP_Error( 'no_player', __( 'No player id supplied.', 'talenttrack' ) );
        }
        if ( $activity_id <= 0 ) {
            return new \WP_Error( 'no_activity', __( 'No activity id supplied.', 'talenttrack' ) );
        }

        global $wpdb;
        $p       = $wpdb->prefix;
        $club_id = CurrentClub::id();

        $eval_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_evaluations
              WHERE player_id = %d AND activity_id = %d AND club_id = %d
              ORDER BY id ASC LIMIT 1",
            $player_id, $activity_id, $club_id
        ) );

        if ( $eval_id <= 0 ) {
            return self::insert( [
                'player_id'   => $player_id,
                'activity_id' => $activity_id,
                'coach_id'    => $coach_id ?? get_current_user_id(),
                'eval_date'   => self::activityDate( $activity_id ),
                'ratings'     => $ratings,
            ] );
        }

        foreach ( $ratings as $cat_id => $val ) {
            $cat_id = (int) $cat_id;
            if ( $cat_id <= 0 ) continue;
            if ( $val === null || $val === '' ) continue;

            $val = round( (float) $val * 2 ) / 2;
            if ( $val <= 0 ) continue;

            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_eval_ratings
                  WHERE evaluation_id = %d AND category_id = %d LIMIT 1",
                $eval_id, $cat_id
            ) );

            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_eval_ratings", [ 'rating' => $val ], [ 'id' => $existing ] );
            } else {
                $wpdb->insert( "{$p}tt_eval_ratings", [
                    'club_id'       => $club_id,
                    'evaluation_id' => $eval_id,
                    'category_id'   => $cat_id,
                    'rating'        => $val,
                ] );
            }
        }

        /** This event documented on {@see self::insert()}. */
        do_action( 'tt_evaluation_saved', $player_id, $eval_id );

        return $eval_id;
    }

    /** An activity-context evaluation is dated by its activity. */
    private static function activityDate( int $activity_id ): string {
        global $wpdb;
        $date = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT session_date FROM {$wpdb->prefix}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ? $date : current_time( 'Y-m-d' );
    }

    /**
     * v3.110.105 — resolve the `eval_type` lookup id that matches a
     * given activity's `activity_type_key`. The two lookup vocabularies
     * (activity_type vs eval_type) are seeded with overlapping names
     * (`training` / `game` / etc.); when they line up by name we can
     * auto-attach the right eval type to an activity-context evaluation
     * without the caller having to know about both lookup tables.
     *
     * Returns 0 when the activity doesn't exist, has no
     * `activity_type_key`, or no matching `eval_type` lookup row is
     * found. Edit-form pre-fill calls this too for legacy evals that
     * carry an `activity_id` but were written before this helper
     * existed.
     */
    public static function evalTypeIdForActivity( int $activity_id ): int {
        if ( $activity_id <= 0 ) return 0;
        global $wpdb;
        $p = $wpdb->prefix;
        $activity_type_key = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_type_key FROM {$p}tt_activities WHERE id = %d AND club_id = %d",
            $activity_id, CurrentClub::id()
        ) );
        if ( $activity_type_key === '' ) return 0;
        $eval_type_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_lookups
              WHERE lookup_type = 'eval_type'
                AND name        = %s
                AND club_id     = %d
              LIMIT 1",
            $activity_type_key, CurrentClub::id()
        ) );
        return $eval_type_id > 0 ? $eval_type_id : 0;
    }
}
