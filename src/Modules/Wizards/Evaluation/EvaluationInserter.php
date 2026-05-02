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

        $insert = [
            'club_id'     => CurrentClub::id(),
            'player_id'   => $player_id,
            'coach_id'    => $coach_id,
            'eval_date'   => $eval_date,
            'notes'       => (string) ( $row['notes'] ?? '' ),
        ];
        if ( $aid > 0 ) $insert['activity_id'] = $aid;

        $ok = $wpdb->insert( "{$p}tt_evaluations", $insert );
        if ( $ok === false ) {
            return new \WP_Error( 'insert_failed', __( 'Could not write evaluation row.', 'talenttrack' ) );
        }
        $eval_id = (int) $wpdb->insert_id;
        if ( $eval_id <= 0 ) {
            return new \WP_Error( 'insert_failed', __( 'Could not write evaluation row.', 'talenttrack' ) );
        }

        $ratings = is_array( $row['ratings'] ?? null ) ? (array) $row['ratings'] : [];
        foreach ( $ratings as $cat_id => $val ) {
            $val = (int) $val;
            if ( $val <= 0 ) continue;
            $wpdb->insert( "{$p}tt_eval_ratings", [
                'evaluation_id' => $eval_id,
                'category_id'   => (int) $cat_id,
                'rating'        => $val,
            ] );
        }

        return $eval_id;
    }
}
