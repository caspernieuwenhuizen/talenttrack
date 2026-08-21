<?php
namespace TT\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerExposureAggregator (#2500) — how many minutes each player has
 * actually spent training each principle.
 *
 * This is the number the whole epic exists to produce. Everything before
 * it — the library, the generator, the builder, the run record — is
 * plumbing that makes this answerable.
 *
 * ## Why this is not one SQL statement
 *
 * The obvious implementation joins runs → run blocks → plan blocks →
 * exercise principles → attendance, and it is wrong.
 * `tt_training_plan_run_blocks` stores only what happened (actual
 * duration, skipped, notes); which exercise a block used and how long it
 * was meant to run live in the run's `blocks_snapshot_json`, taken at
 * attach time.
 *
 * Reaching the exercise through the *live* plan block instead would mean
 * that editing a plan silently changes — or erases — the training history
 * of every session already run from it. #2499 demonstrated exactly that
 * failure in `listBlocks()`: a plan whose blocks were replaced left a
 * completed session reading "27 minutes actual against 0 planned". The
 * same join here would drop the run from the aggregate entirely, and the
 * player's minutes would quietly fall.
 *
 * So the snapshot is read per run, in PHP, and joined to attendance and
 * principles in bulk. A season's runs number in the hundreds and this
 * runs nightly, so the loop costs nothing worth optimising. If it ever
 * does, the fix is to denormalise `exercise_id` and
 * `planned_duration_minutes` onto the run block — not to start trusting
 * the live plan.
 *
 * ## Idempotent by construction
 *
 * Every row is written with `ON DUPLICATE KEY UPDATE` against
 * `(club_id, player_id, principle_id, season_id)`, and the totals are
 * recomputed from source rather than incremented. Running it twice
 * changes nothing, which is the wave's load-bearing acceptance criterion.
 *
 * `season_id` is NOT NULL DEFAULT 0 so that key actually constrains:
 * MySQL does not treat two NULLs as equal in a UNIQUE index, so a
 * nullable season would let every night insert another duplicate and a
 * player's minutes would climb without anyone training.
 *
 * ## What counts
 *
 *   - **Completed runs only.** A run still in progress has not happened.
 *   - **`record_type = 'actual'` attendance.** An `expected` row is a
 *     plan, not a fact; counting it credits minutes to a player who never
 *     turned up.
 *   - **Present and late.** A player who arrived late trained; excused,
 *     absent and injured did not.
 *   - **Guests, on their own file (D8).** Recorded either as `player_id`
 *     with `is_guest`, or as `guest_player_id` with `player_id` NULL —
 *     `COALESCE` resolves both, matching `PlayerAttendanceCalculator`.
 *   - **Actual block duration, falling back to the snapshot's planned
 *     figure.** A block that ran 27 minutes contributed 27.
 *   - **Skipped blocks contribute nothing**, which is the whole point of
 *     recording a skip.
 */
final class PlayerExposureAggregator {

    /** Statuses that mean the player was on the pitch. */
    private const PRESENT_LIKE = [ AttendanceStatus::PRESENT, AttendanceStatus::LATE ];

    /**
     * Rebuild every player's exposure. The nightly path.
     *
     * @return int rows written
     */
    public function rebuildAll(): int {
        return $this->rebuild( null );
    }

    /**
     * Rebuild the players a completing run touched, so the player file is
     * right immediately rather than tomorrow (D17).
     *
     * **The narrowing is on WHO to recompute, never on WHAT to count.**
     * Aggregating only the finished run's own minutes and writing those
     * would overwrite the player's season total with one evening — a
     * coach finishing a session would erase the history, and the number
     * would look plausible enough that nobody would notice for weeks.
     */
    public function rebuildForRun( int $run_id ): int {
        $players = $this->playersOnRun( $run_id );
        if ( $players === [] ) return 0;

        return $this->rebuild( $players );
    }

    /**
     * @param list<int>|null $only_players recompute these players in full, or null for everyone
     */
    private function rebuild( ?array $only_players ): int {
        global $wpdb;

        $club  = CurrentClub::id();
        $keep  = $only_players === null ? null : array_flip( array_map( 'intval', $only_players ) );
        $totals = [];

        foreach ( $this->completedRuns( $club ) as $run ) {
            $blocks = $this->blocksOf( $run );
            if ( $blocks === [] ) continue;

            $players = $this->presentPlayers( (int) $run->activity_id, $club );
            if ( $keep !== null ) {
                $players = array_values( array_filter(
                    $players,
                    static fn( int $id ): bool => isset( $keep[ $id ] )
                ) );
            }
            if ( $players === [] ) continue;

            $season = $this->seasonFor( (string) $run->run_date, $club );

            foreach ( $blocks as $block ) {
                $minutes = (int) $block['minutes'];
                if ( $minutes <= 0 ) continue;

                foreach ( $this->principlesOf( (int) $block['exercise_id'], $club ) as $principle_id ) {
                    foreach ( $players as $player_id ) {
                        $key = $player_id . ':' . $principle_id . ':' . $season;

                        if ( ! isset( $totals[ $key ] ) ) {
                            $totals[ $key ] = [
                                'player_id'    => $player_id,
                                'principle_id' => $principle_id,
                                'season_id'    => $season,
                                'minutes'      => 0,
                                'runs'         => [],
                                'last'         => '',
                            ];
                        }

                        $totals[ $key ]['minutes']              += $minutes;
                        $totals[ $key ]['runs'][ (int) $run->id ] = true;
                        if ( (string) $run->run_date > $totals[ $key ]['last'] ) {
                            $totals[ $key ]['last'] = (string) $run->run_date;
                        }
                    }
                }
            }
        }

        if ( $totals === [] ) return 0;

        $table  = $wpdb->prefix . 'tt_player_principle_exposure';
        $now    = current_time( 'mysql' );
        $writes = 0;

        foreach ( $totals as $row ) {
            $ok = $wpdb->query( $wpdb->prepare(
                "INSERT INTO {$table}
                    (club_id, player_id, principle_id, season_id,
                     minutes_total, sessions_count, last_trained_on, computed_at)
                 VALUES (%d, %d, %d, %d, %d, %d, %s, %s)
                 ON DUPLICATE KEY UPDATE
                    minutes_total   = VALUES(minutes_total),
                    sessions_count  = VALUES(sessions_count),
                    last_trained_on = VALUES(last_trained_on),
                    computed_at     = VALUES(computed_at)",
                $club,
                $row['player_id'],
                $row['principle_id'],
                $row['season_id'],
                $row['minutes'],
                count( $row['runs'] ),
                $row['last'] !== '' ? $row['last'] : null,
                $now
            ) );
            if ( $ok !== false ) $writes++;
        }

        return $writes;
    }

    /** @return list<object> */
    private function completedRuns( int $club ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, activity_id, run_date, blocks_snapshot_json
               FROM {$wpdb->prefix}tt_training_plan_runs
              WHERE club_id = %d AND status = 'completed'
           ORDER BY id ASC",
            $club
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * One run's blocks, as exercise + minutes actually trained.
     *
     * The snapshot supplies the exercise and the planned length; the run
     * block table supplies what happened. Matched on `order_index`, which
     * is the one key both sides agree on and which cannot be invalidated
     * by editing the plan afterwards.
     *
     * @return list<array{exercise_id:int, minutes:int}>
     */
    private function blocksOf( object $run ): array {
        $snapshot = json_decode( (string) $run->blocks_snapshot_json, true );
        if ( ! is_array( $snapshot ) ) return [];

        $actuals = $this->actualsFor( (int) $run->id );

        $out = [];
        foreach ( (array) ( $snapshot['blocks'] ?? [] ) as $block ) {
            $exercise_id = (int) ( $block['exercise_id'] ?? 0 );
            if ( $exercise_id <= 0 ) continue;  // a team talk trains no principle

            $order  = (int) ( $block['order_index'] ?? 0 );
            $actual = $actuals[ $order ] ?? null;

            if ( $actual !== null && $actual['skipped'] ) continue;

            $minutes = $actual !== null && $actual['minutes'] !== null
                ? (int) $actual['minutes']
                : (int) ( $block['duration_minutes'] ?? 0 );

            $out[] = [ 'exercise_id' => $exercise_id, 'minutes' => $minutes ];
        }

        return $out;
    }

    /** @return array<int, array{minutes:int|null, skipped:bool}> keyed by order_index */
    private function actualsFor( int $run_id ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT order_index, actual_duration_minutes, was_skipped
               FROM {$wpdb->prefix}tt_training_plan_run_blocks
              WHERE run_id = %d AND club_id = %d",
            $run_id,
            CurrentClub::id()
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->order_index ] = [
                'minutes' => $row->actual_duration_minutes === null ? null : (int) $row->actual_duration_minutes,
                'skipped' => (bool) $row->was_skipped,
            ];
        }

        return $out;
    }

    /**
     * Everyone who was actually on the pitch for this activity.
     *
     * @return list<int>
     */
    private function presentPlayers( int $activity_id, int $club ): array {
        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( self::PRESENT_LIKE ), '%s' ) );

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT COALESCE( guest_player_id, player_id )
               FROM {$wpdb->prefix}tt_attendance
              WHERE activity_id = %d
                AND club_id = %d
                AND record_type = 'actual'
                AND status IN ({$placeholders})",
            array_merge( [ $activity_id, $club ], self::PRESENT_LIKE )
        ) );

        return is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
    }

    /**
     * @return list<int>
     */
    private function playersOnRun( int $run_id ): array {
        global $wpdb;

        $activity_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT activity_id FROM {$wpdb->prefix}tt_training_plan_runs WHERE id = %d AND club_id = %d",
            $run_id,
            CurrentClub::id()
        ) );
        if ( $activity_id <= 0 ) return [];

        return $this->presentPlayers( $activity_id, CurrentClub::id() );
    }

    /**
     * Exercise → principles, memoised for the length of one rebuild.
     * A nightly pass over a season's runs asks about the same handful of
     * drills repeatedly.
     *
     * @var array<int, list<int>>
     */
    private array $principle_cache = [];

    /** @return list<int> */
    private function principlesOf( int $exercise_id, int $club ): array {
        if ( isset( $this->principle_cache[ $exercise_id ] ) ) {
            return $this->principle_cache[ $exercise_id ];
        }

        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT principle_id FROM {$wpdb->prefix}tt_exercise_principles
              WHERE exercise_id = %d AND club_id = %d",
            $exercise_id,
            $club
        ) );

        return $this->principle_cache[ $exercise_id ] =
            is_array( $ids ) ? array_values( array_filter( array_map( 'intval', $ids ) ) ) : [];
    }

    /**
     * The season a date falls in, or 0 for none.
     *
     * 0 rather than null so the UNIQUE key constrains — see the class
     * docblock.
     *
     * @var array<string, int>
     */
    private array $season_cache = [];

    private function seasonFor( string $date, int $club ): int {
        if ( $date === '' ) return 0;
        if ( isset( $this->season_cache[ $date ] ) ) return $this->season_cache[ $date ];

        global $wpdb;

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_seasons
              WHERE club_id = %d AND %s BETWEEN start_date AND end_date
           ORDER BY id ASC LIMIT 1",
            $club,
            $date
        ) );

        return $this->season_cache[ $date ] = $id;
    }
}
