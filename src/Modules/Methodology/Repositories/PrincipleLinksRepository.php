<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PrincipleLinksRepository — handles two cross-module link surfaces:
 *
 *   1. **Session ↔ Principle pivot** (`tt_activity_principles`) — the
 *      multi-select on the session form attaches one or more principles
 *      to a session, "what principles are we practicing today."
 *
 *   2. **Generic principle reverse-index** (`tt_methodology_principle_links`)
 *      — placeholder consumed by future modules (#0006 team planning,
 *      #0017 trial evaluations). Each row records "this entity uses
 *      this principle." Empty for now; ready to fill when consumers
 *      ship.
 *
 * The session pivot is fully wired in this PR. The reverse-index gets
 * read-side helpers ("which entities reference this principle?") so
 * the principle detail view can render a "Used in N places" line even
 * before #0006.
 */
class PrincipleLinksRepository {

    private function sessionPivot(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_activity_principles';
    }

    private function reverseTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_principle_links';
    }

    // Session ↔ Principle

    /** @return int[] principle ids attached to this session, in stored order */
    public function principlesForActivity( int $activity_id ): array {
        global $wpdb;
        $t = $this->sessionPivot();
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT principle_id FROM {$t} WHERE activity_id = %d ORDER BY sort_order ASC, id ASC",
            $activity_id
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * Replace the full list of principles linked to a session.
     *
     * @param int[] $principle_ids
     */
    public function setActivityPrinciples( int $activity_id, array $principle_ids ): void {
        global $wpdb;
        $t = $this->sessionPivot();

        $wpdb->delete( $t, [ 'activity_id' => $activity_id ] );

        $clean = array_values( array_unique( array_filter( array_map( 'intval', $principle_ids ), fn( $v ) => $v > 0 ) ) );
        if ( empty( $clean ) ) return;

        $sort = 0;
        foreach ( $clean as $pid ) {
            $wpdb->insert( $t, [
                'activity_id'   => $activity_id,
                'principle_id' => $pid,
                'sort_order'   => $sort++,
            ] );
        }
    }

    /** @return int number of sessions referencing this principle */
    public function sessionCountForPrinciple( int $principle_id ): int {
        global $wpdb;
        $t = $this->sessionPivot();
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT activity_id) FROM {$t} WHERE principle_id = %d",
            $principle_id
        ) );
    }

    // Reverse index

    /** @param string $entity_type 'team_plan' | 'trial_decision' | future */
    public function recordLink( int $principle_id, string $entity_type, int $entity_id ): void {
        global $wpdb;
        $t = $this->reverseTable();
        // Avoid duplicate rows.
        $exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE principle_id = %d AND entity_type = %s AND entity_id = %d",
            $principle_id, $entity_type, $entity_id
        ) );
        if ( $exists > 0 ) return;
        $wpdb->insert( $t, [
            'principle_id' => $principle_id,
            'entity_type'  => sanitize_key( $entity_type ),
            'entity_id'    => $entity_id,
        ] );
    }

    public function clearLinksForEntity( string $entity_type, int $entity_id ): void {
        global $wpdb;
        $t = $this->reverseTable();
        $wpdb->delete( $t, [
            'entity_type' => sanitize_key( $entity_type ),
            'entity_id'   => $entity_id,
        ] );
    }

    /**
     * @return array<string,int> entity_type => count, including
     *                            sessions (computed from the pivot)
     *                            and goals (computed from
     *                            `tt_goals.linked_principle_id`).
     */
    public function usageCountsForPrinciple( int $principle_id ): array {
        global $wpdb;
        $out = [];

        // Sessions
        $out['activity'] = $this->sessionCountForPrinciple( $principle_id );

        // Goals — direct column, not pivot
        $goals_table = $wpdb->prefix . 'tt_goals';
        $col_exists = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'linked_principle_id'",
            $goals_table
        ) );
        if ( $col_exists > 0 ) {
            $out['goal'] = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$goals_table}
                 WHERE linked_principle_id = %d AND archived_at IS NULL",
                $principle_id
            ) );
        }

        // Reverse-index entries (team plans + future)
        $t = $this->reverseTable();
        $rows = (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT entity_type, COUNT(DISTINCT entity_id) AS c
             FROM {$t} WHERE principle_id = %d
             GROUP BY entity_type",
            $principle_id
        ) );
        foreach ( $rows as $r ) {
            $out[ (string) $r->entity_type ] = (int) $r->c;
        }

        return $out;
    }
}
