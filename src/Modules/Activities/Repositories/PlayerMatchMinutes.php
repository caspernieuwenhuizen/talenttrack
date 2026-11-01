<?php
namespace TT\Modules\Activities\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerMatchMinutes (#4526) — the one answer to "how many minutes did this
 * player play in that match", read from the recorded register.
 *
 * Nothing in the schema stops a player having two `record_type = 'actual'`
 * rows for one match: there is no unique key on (activity, player, kind).
 * The readers disagreed on what two rows meant. The minutes grid took the
 * MAX, the Minutes audit and the minutes reports the SUM, so two rows of 70
 * read 70 on the grid and 140 in the audit beside it.
 *
 * **The newest recorded row wins** (highest `id`), with
 * `COALESCE(minutes_override, minutes_played)`. A duplicate is a second
 * write of the same fact, and the latest write is what the coach last
 * typed: SUM counts the match twice, and MAX ignores a correction downwards.
 * Guest rows are never a player's minutes.
 *
 * Every reader of a player's match minutes comes through here:
 * `MinutesGridQuery`, `MinutesAuditQuery`, `MinutesQuery` and the
 * `TournamentMinutesResolver` register read. A reader that has to aggregate
 * in its own SQL (a JOIN across activities) applies {@see newestRowClause()}
 * so it keeps the same one row.
 *
 * `AttendanceWriter::recordActual()` stops new duplicates by updating the
 * existing recorded row. Old ones are counted by {@see duplicatePairs()},
 * not repaired: whether a clean-up with a unique key is worth it is a
 * separate decision.
 */
final class PlayerMatchMinutes {

    /**
     * The minutes per (activity, player) on the newest recorded row: a
     * figure, or null where that row carries none (a register taken without
     * minutes — the tournament fallback, #4053, keys on that null).
     *
     * @param array<int> $activity_ids
     * @param int|null   $player_id Narrow the read to one player.
     * @return array<int, array<int, int|null>> activity id => player id => minutes
     */
    public static function forActivities( array $activity_ids, ?int $player_id = null ): array {
        $ids = [];
        foreach ( $activity_ids as $aid ) {
            $aid = (int) $aid;
            if ( $aid > 0 ) $ids[ $aid ] = $aid;
        }
        if ( $ids === [] ) return [];
        $ids = array_values( $ids );

        global $wpdb;
        $p      = $wpdb->prefix;
        $in     = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $params = array_merge( $ids, [ (int) CurrentClub::id() ] );
        $filter = '';
        if ( $player_id !== null && $player_id > 0 ) {
            $filter   = 'AND a.player_id = %d';
            $params[] = $player_id;
        }
        $newest = self::newestRowClause( 'a' );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT a.activity_id, a.player_id, COALESCE( a.minutes_override, a.minutes_played ) AS minutes
               FROM {$p}tt_attendance a
              WHERE a.activity_id IN ($in)
                AND a.club_id = %d
                AND a.record_type = 'actual'
                AND a.is_guest = 0
                AND a.player_id > 0
                {$filter}
                {$newest}",
            $params
        ) );

        $out = [];
        foreach ( (array) $rows as $r ) {
            $out[ (int) $r->activity_id ][ (int) $r->player_id ] = $r->minutes !== null ? (int) $r->minutes : null;
        }
        return $out;
    }

    /**
     * The newest-row rule as a SQL condition on an attendance alias that is
     * already narrowed to recorded, non-guest rows: `AND <alias>.id` is the
     * highest id among that player's recorded rows for the match. Built like
     * `ActivityLifecycle::notCancelledClause()`: the alias is the caller's
     * own literal, never input.
     */
    public static function newestRowClause( string $alias ): string {
        global $wpdb;
        $p = $wpdb->prefix;
        return "AND {$alias}.id = (
                    SELECT MAX( n.id ) FROM {$p}tt_attendance n
                     WHERE n.activity_id = {$alias}.activity_id
                       AND n.player_id   = {$alias}.player_id
                       AND n.club_id     = {$alias}.club_id
                       AND n.record_type = 'actual'
                       AND n.is_guest    = 0
                )";
    }

    /**
     * How many (activity, player) pairs among these activities carry more
     * than one recorded row: the legacy duplicates the newest-row rule reads
     * past. Read-only; nothing is repaired.
     *
     * @param array<int> $activity_ids
     */
    public static function duplicatePairs( array $activity_ids ): int {
        $ids = [];
        foreach ( $activity_ids as $aid ) {
            $aid = (int) $aid;
            if ( $aid > 0 ) $ids[ $aid ] = $aid;
        }
        if ( $ids === [] ) return 0;
        $ids = array_values( $ids );

        global $wpdb;
        $p  = $wpdb->prefix;
        $in = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM (
                SELECT activity_id, player_id
                  FROM {$p}tt_attendance
                 WHERE activity_id IN ($in)
                   AND club_id = %d
                   AND record_type = 'actual'
                   AND is_guest = 0
                   AND player_id > 0
              GROUP BY activity_id, player_id
                HAVING COUNT(*) > 1
             ) d",
            array_merge( $ids, [ (int) CurrentClub::id() ] )
        ) );
    }
}
