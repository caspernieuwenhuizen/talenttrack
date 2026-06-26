<?php
namespace TT\Modules\Pdp\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

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

    /**
     * #1853 — a goal can also link to a PDP conversation (the "combine":
     * goals discussed in a development talk). Kept OUT of ALLOWED_TYPES so
     * the methodology-link sync() below can't clobber it; managed via the
     * dedicated conversation methods.
     */
    public const TYPE_PDP_CONVERSATION = 'pdp_conversation';

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
            "SELECT link_type, link_id FROM {$this->table} WHERE goal_id = %d AND club_id = %d ORDER BY link_type ASC, link_id ASC",
            $goal_id, CurrentClub::id()
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

        // Wipe + insert, scoped to the methodology link types so a goal's
        // PDP-conversation links (#1853) survive a methodology re-sync.
        $ph = implode( ',', array_fill( 0, count( self::ALLOWED_TYPES ), '%s' ) );
        $this->wpdb->query( $this->wpdb->prepare(
            "DELETE FROM {$this->table} WHERE goal_id = %d AND club_id = %d AND link_type IN ($ph)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $goal_id, CurrentClub::id(), ...self::ALLOWED_TYPES
        ) );
        foreach ( $clean as $row ) {
            $this->wpdb->insert( $this->table, [
                'club_id'   => CurrentClub::id(),
                'goal_id'   => $goal_id,
                'link_type' => $row['type'],
                'link_id'   => $row['id'],
            ] );
        }
        return true;
    }

    // ── #1853 — goal ↔ PDP conversation links ─────────────────────────

    /** @return list<int> goal IDs linked to a conversation. */
    public function goalsForConversation( int $conv_id ): array {
        if ( $conv_id <= 0 ) return [];
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT goal_id FROM {$this->table} WHERE link_type = %s AND link_id = %d AND club_id = %d",
            self::TYPE_PDP_CONVERSATION, $conv_id, CurrentClub::id()
        ) );
        return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
    }

    /** @return list<int> conversation IDs linked to a goal. */
    public function conversationsForGoal( int $goal_id ): array {
        if ( $goal_id <= 0 ) return [];
        $rows = $this->wpdb->get_col( $this->wpdb->prepare(
            "SELECT link_id FROM {$this->table} WHERE link_type = %s AND goal_id = %d AND club_id = %d",
            self::TYPE_PDP_CONVERSATION, $goal_id, CurrentClub::id()
        ) );
        return array_values( array_unique( array_map( 'intval', (array) $rows ) ) );
    }

    /**
     * Replace the set of goals linked to a conversation (the coach's
     * "goals discussed in this talk" multi-select). Only touches this
     * conversation's `pdp_conversation` rows. Returns true.
     *
     * @param int[] $goal_ids
     */
    public function setGoalsForConversation( int $conv_id, array $goal_ids ): bool {
        if ( $conv_id <= 0 ) return false;

        $this->wpdb->delete( $this->table, [
            'link_type' => self::TYPE_PDP_CONVERSATION,
            'link_id'   => $conv_id,
            'club_id'   => CurrentClub::id(),
        ] );

        $seen = [];
        foreach ( $goal_ids as $gid ) {
            $gid = (int) $gid;
            if ( $gid <= 0 || isset( $seen[ $gid ] ) ) continue;
            $seen[ $gid ] = true;
            $this->wpdb->insert( $this->table, [
                'club_id'   => CurrentClub::id(),
                'goal_id'   => $gid,
                'link_type' => self::TYPE_PDP_CONVERSATION,
                'link_id'   => $conv_id,
            ] );
        }
        return true;
    }
}
