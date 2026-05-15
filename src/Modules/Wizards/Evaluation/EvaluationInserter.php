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

        // v3.110.x — every rating row carries `club_id` (see same fix in
        // EvaluationsRestController::write_ratings).
        $club_id = CurrentClub::id();
        $ratings = is_array( $row['ratings'] ?? null ) ? (array) $row['ratings'] : [];
        foreach ( $ratings as $cat_id => $val ) {
            $val = (int) $val;
            if ( $val <= 0 ) continue;
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'club_id'       => $club_id,
                'evaluation_id' => $eval_id,
                'category_id'   => (int) $cat_id,
                'rating'        => $val,
            ] );
        }

        return $eval_id;
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
