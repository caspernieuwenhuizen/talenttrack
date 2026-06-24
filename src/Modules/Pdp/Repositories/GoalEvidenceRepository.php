<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * GoalEvidenceRepository (#1717) — links evaluations to a learning goal as
 * evidence ("Bewijslast"), each carrying the evaluation's date + overall
 * (average-rating) score for the POP card chips.
 *
 * Backed by the dedicated `tt_goal_evidence` table — kept separate from the
 * methodology `tt_goal_links` (whose sync replaces the whole link set), so
 * evidence and methodology links are independent.
 */
final class GoalEvidenceRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_goal_evidence';
    }

    /**
     * Replace a goal's evaluation-evidence set.
     *
     * @param int[] $eval_ids
     */
    public function syncForGoal( int $goal_id, array $eval_ids ): void {
        global $wpdb;
        if ( $goal_id <= 0 ) return;
        $clean = array_values( array_unique( array_filter(
            array_map( 'intval', $eval_ids ),
            static fn( $i ) => $i > 0
        ) ) );

        $wpdb->delete( $this->table(), [ 'goal_id' => $goal_id ] );
        $by = get_current_user_id();
        foreach ( $clean as $eid ) {
            $wpdb->insert( $this->table(), [
                'club_id'       => CurrentClub::id(),
                'goal_id'       => $goal_id,
                'evaluation_id' => $eid,
                'created_by'    => $by ?: null,
            ] );
        }
    }

    /** @return int[] linked evaluation ids for one goal (for pre-checking the form). */
    public function evalIdsForGoal( int $goal_id ): array {
        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT evaluation_id FROM {$this->table()} WHERE goal_id = %d AND club_id = %d ORDER BY id ASC",
            $goal_id, CurrentClub::id()
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Batch-load scored evidence for a set of goals.
     *
     * @param int[] $goal_ids
     * @return array<int, list<object>>  goal_id => [ {evaluation_id, eval_date, avg_rating}, … ] (newest first)
     */
    public function listForGoals( array $goal_ids ): array {
        global $wpdb;
        $p   = $wpdb->prefix;
        $ids = array_values( array_filter( array_map( 'intval', $goal_ids ), static fn( $i ) => $i > 0 ) );
        if ( ! $ids ) return [];

        $ph   = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ge.goal_id, e.id AS evaluation_id, e.eval_date,
                    (SELECT AVG(r.rating) FROM {$p}tt_eval_ratings r
                      WHERE r.evaluation_id = e.id AND r.club_id = e.club_id) AS avg_rating
               FROM {$p}tt_goal_evidence ge
               JOIN {$p}tt_evaluations e ON e.id = ge.evaluation_id AND e.archived_at IS NULL
              WHERE ge.goal_id IN ({$ph}) AND ge.club_id = %d
              ORDER BY e.eval_date DESC, e.id DESC",
            ...array_merge( $ids, [ CurrentClub::id() ] )
        ) );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->goal_id ][] = $r;
        }
        return $out;
    }
}
