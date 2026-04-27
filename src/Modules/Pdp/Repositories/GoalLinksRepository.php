<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * GoalLinksRepository — polymorphic goal → (principle | football_action |
 * position | value) join. Single row per (goal, type, target).
 *
 * sync() replaces all links for a goal in one shot — the form post
 * the goal save handler accepts is "here is the full set of links",
 * so the repository diffs and writes the difference.
 */
class GoalLinksRepository {

    public const ALLOWED_TYPES = [ 'principle', 'football_action', 'position', 'value' ];

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_goal_links';
    }

    /** @return list<array{type:string, id:int}> */
    public function listForGoal( int $goal_id ): array {
        if ( $goal_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT link_type, link_id FROM {$this->table} WHERE goal_id = %d ORDER BY link_type ASC, link_id ASC",
            $goal_id
        ) );
        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[] = [ 'type' => (string) $r->link_type, 'id' => (int) $r->link_id ];
        }
        return $out;
    }

    /**
     * Replace all links for a goal with the given set.
     *
     * @param list<array{type:string, id:int}> $links
     */
    public function sync( int $goal_id, array $links ): bool {
        if ( $goal_id <= 0 ) return false;

        $clean = [];
        foreach ( $links as $l ) {
            $type = (string) ( $l['type'] ?? '' );
            $id   = (int)    ( $l['id']   ?? 0 );
            if ( $id <= 0 || ! in_array( $type, self::ALLOWED_TYPES, true ) ) continue;
            $key = $type . '|' . $id;
            $clean[ $key ] = [ 'type' => $type, 'id' => $id ];
        }

        // Wipe + insert. Cheap at this scale; avoids diff bookkeeping.
        $this->wpdb->delete( $this->table, [ 'goal_id' => $goal_id ] );
        foreach ( $clean as $row ) {
            $this->wpdb->insert( $this->table, [
                'goal_id'   => $goal_id,
                'link_type' => $row['type'],
                'link_id'   => $row['id'],
            ] );
        }
        return true;
    }
}
