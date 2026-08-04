<?php
namespace TT\Modules\MatchExecution\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrackedEventsRepository — the tt_match_execution_tracked_events table.
 *
 * Tracked events are per-player development actions (e.g. "runs in behind",
 * "duels won") logged live by the coach on prep-flagged players. They are
 * distinct from goals: they do NOT affect the score. Append-only, with
 * `reversed_at` as the soft-delete for undo, and `event_uuid` as the
 * offline-queue idempotency key — mirroring the sibling goal-event /
 * substitution tables. Kept separate from MatchExecutionRepository, which
 * is scoped to the three live-match state tables.
 */
class TrackedEventsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_match_execution_tracked_events';
    }

    /**
     * Log one tracked action. Idempotent via INSERT IGNORE on the unique
     * event_uuid, so an offline-queue replay never double-counts.
     */
    public function logTrackedEvent(
        int $execution_id,
        string $event_uuid,
        int $player_id,
        int $half,
        int $minute,
        string $action_label,
        ?string $action_key = null
    ): bool {
        if ( $execution_id <= 0 || $event_uuid === '' || $player_id <= 0 ) return false;
        $ok = $this->wpdb->query( $this->wpdb->prepare(
            "INSERT IGNORE INTO {$this->table}
               (event_uuid, club_id, execution_id, player_id, half, minute_in_half, action_key, action_label)
             VALUES (%s, %d, %d, %d, %d, %d, %s, %s)",
            $event_uuid,
            CurrentClub::id(),
            $execution_id,
            $player_id,
            $half,
            max( 0, $minute ),
            $action_key !== null ? $action_key : '',
            $action_label
        ) );
        return $ok !== false;
    }

    /** Soft-delete (undo) a tracked action by its client event_uuid. */
    public function reverseTrackedEvent( string $event_uuid ): bool {
        if ( $event_uuid === '' ) return false;
        return false !== $this->wpdb->update(
            $this->table,
            [ 'reversed_at' => current_time( 'mysql', true ) ],
            [ 'event_uuid' => $event_uuid, 'club_id' => CurrentClub::id() ]
        );
    }

    /** Does a tracked event with this event_uuid exist (reversed or not)? */
    public function trackedEventExists( string $event_uuid ): bool {
        if ( $event_uuid === '' ) return false;
        return (int) $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE event_uuid = %s AND club_id = %d",
            $event_uuid, CurrentClub::id()
        ) ) > 0;
    }

    /** @return object[] non-reversed tracked events, chronological. */
    public function listTrackedEvents( int $execution_id ): array {
        if ( $execution_id <= 0 ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE execution_id = %d AND club_id = %d AND reversed_at IS NULL
              ORDER BY half ASC, minute_in_half ASC, id ASC",
            $execution_id, CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Non-reversed tap count per player for an execution.
     *
     * @return array<int, int> player_id => count
     */
    public function countsByPlayer( int $execution_id ): array {
        $out = [];
        if ( $execution_id <= 0 ) return $out;
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT player_id, COUNT(*) AS n FROM {$this->table}
              WHERE execution_id = %d AND club_id = %d AND reversed_at IS NULL
              GROUP BY player_id",
            $execution_id, CurrentClub::id()
        ) );
        foreach ( (array) $rows as $r ) {
            $pid = (int) $r->player_id;
            if ( $pid > 0 ) $out[ $pid ] = (int) $r->n;
        }
        return $out;
    }
}
