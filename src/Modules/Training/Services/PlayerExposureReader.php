<?php
namespace TT\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerExposureReader (#2500) — the read side of training exposure.
 *
 * Separate from `PlayerExposureAggregator` because they answer to
 * different pressures: the aggregator runs nightly and may be slow, this
 * runs on the most trafficked view in the plugin and may not. Keeping
 * them apart also means a surface can never accidentally trigger a
 * rebuild by reading.
 *
 * ## The LEFT JOIN is the feature
 *
 * Every query here starts from `tt_principles`, not from the exposure
 * table, and joins exposure onto it. A principle a player has never
 * trained therefore comes back with zero minutes rather than not coming
 * back at all.
 *
 * That is deliberate and it is the point of the whole wave: the useful
 * finding on a player's training tab is usually the row that is empty.
 * Starting from the exposure table would produce a screen that looks
 * complete while hiding exactly what a coach opened it to find.
 */
final class PlayerExposureReader {

    /**
     * Every principle, with this player's minutes against it.
     *
     * Ordered by minutes ascending so the never-trained principles sort
     * to the top — the reading order that matches why someone opened the
     * tab.
     *
     * @return list<array<string,mixed>>
     */
    public function forPlayer( int $player_id, ?int $season_id = null ): array {
        if ( $player_id <= 0 ) return [];

        global $wpdb;

        $club = CurrentClub::id();

        $season_clause = '';
        $params        = [ $player_id, $club ];

        if ( $season_id !== null ) {
            $season_clause = ' AND e.season_id = %d';
            $params[]      = $season_id;
        }

        $params[] = $club;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT
                p.id                                 AS principle_id,
                p.code                               AS code,
                p.title_json                         AS title_json,
                p.team_function_key                  AS team_function_key,
                COALESCE( SUM( e.minutes_total ), 0 )  AS minutes_total,
                COALESCE( SUM( e.sessions_count ), 0 ) AS sessions_count,
                MAX( e.last_trained_on )             AS last_trained_on
              FROM {$wpdb->prefix}tt_principles p
         LEFT JOIN {$wpdb->prefix}tt_player_principle_exposure e
                ON e.principle_id = p.id
               AND e.player_id = %d
               AND e.club_id = %d
               {$season_clause}
             WHERE p.archived_at IS NULL
               AND ( p.club_id = %d OR p.club_id IS NULL )
          GROUP BY p.id, p.code, p.title_json, p.team_function_key
          ORDER BY minutes_total ASC, p.code ASC",
            $params
        ), ARRAY_A );

        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as &$row ) {
            $row['title'] = $this->title( (string) ( $row['title_json'] ?? '' ), (string) $row['code'] );
            unset( $row['title_json'] );
        }
        unset( $row );

        return $rows;
    }

    /**
     * The headline figures.
     *
     * `principles_total` counts the methodology, not the player's rows —
     * "8 of 34 touched" is the sentence worth reading, and it needs the
     * denominator to come from the club's principles rather than from
     * what happens to have minutes.
     *
     * @return array{minutes:int, principles_trained:int, principles_total:int, sessions:int, last_trained_on:?string}
     */
    public function summaryFor( int $player_id ): array {
        $rows = $this->forPlayer( $player_id );

        $minutes = 0;
        $trained = 0;
        $last    = null;

        foreach ( $rows as $row ) {
            $row_minutes = (int) $row['minutes_total'];
            $minutes    += $row_minutes;
            if ( $row_minutes > 0 ) $trained++;

            $row_last = $row['last_trained_on'] ?? null;
            if ( $row_last !== null && ( $last === null || $row_last > $last ) ) {
                $last = (string) $row_last;
            }
        }

        return [
            'minutes'            => $minutes,
            'principles_trained' => $trained,
            'principles_total'   => count( $rows ),
            // Distinct trainings, not the sum of per-principle counts: one
            // training that touched three principles is one training, and
            // summing the column would report it as three.
            'sessions'           => $this->distinctSessions( $player_id ),
            'last_trained_on'    => $last,
        ];
    }

    /**
     * How many completed trainings this player actually attended.
     *
     * Counted from the runs rather than from the exposure rows, because
     * `sessions_count` is per principle and adding those up
     * double-counts every training that trained more than one thing.
     */
    private function distinctSessions( int $player_id ): int {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT( DISTINCT r.id )
               FROM {$wpdb->prefix}tt_training_plan_runs r
               JOIN {$wpdb->prefix}tt_attendance att
                 ON att.activity_id = r.activity_id AND att.club_id = r.club_id
                AND att.record_type = 'actual'
                AND att.status IN ( 'present', 'late' )
                AND COALESCE( att.guest_player_id, att.player_id ) = %d
              WHERE r.club_id = %d AND r.status = 'completed'",
            $player_id,
            CurrentClub::id()
        ) );
    }

    /**
     * Principle × team, for the head-of-development coverage matrix.
     *
     * Aggregated by the *run's* team rather than the player's current
     * one, so a guest's minutes land under the team that actually trained
     * them (D8) — otherwise a played-up striker would make their own age
     * group look better trained than it is.
     *
     * ## Read from the snapshot, like the aggregator
     *
     * The obvious version joins the run block to the *live* plan block to
     * reach its exercise, and it is wrong for the same reason it was
     * wrong in `PlayerExposureAggregator`: editing a plan would silently
     * remove every run made from it out of the coverage matrix, and a
     * head of development would see a team's training gaps that were
     * never real. History comes from the snapshot, always.
     *
     * This is a report on a page nobody opens in a loop, so walking a
     * season's runs in PHP costs nothing worth the inconsistency of
     * having two different definitions of what a run trained.
     *
     * @return list<array<string,mixed>>
     */
    public function coverageByTeam( ?int $season_id = null ): array {
        global $wpdb;

        $club = CurrentClub::id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT r.id, r.team_id, r.run_date, r.blocks_snapshot_json, t.name AS team_name
               FROM {$wpdb->prefix}tt_training_plan_runs r
               JOIN {$wpdb->prefix}tt_teams t ON t.id = r.team_id AND t.club_id = r.club_id
              WHERE r.club_id = %d AND r.status = 'completed'
           ORDER BY r.run_date ASC",
            $club
        ) );

        $season_window = $season_id !== null ? $this->seasonWindow( $season_id, $club ) : null;

        $out = [];
        foreach ( (array) $rows as $run ) {
            if ( $season_window !== null ) {
                $date = (string) $run->run_date;
                if ( $date < $season_window['start'] || $date > $season_window['end'] ) continue;
            }

            $skipped = $this->skippedOrders( (int) $run->id, $club );

            $snapshot = json_decode( (string) $run->blocks_snapshot_json, true );
            if ( ! is_array( $snapshot ) ) continue;

            $seen = [];
            foreach ( (array) ( $snapshot['blocks'] ?? [] ) as $block ) {
                $exercise_id = (int) ( $block['exercise_id'] ?? 0 );
                if ( $exercise_id <= 0 ) continue;
                if ( isset( $skipped[ (int) ( $block['order_index'] ?? 0 ) ] ) ) continue;

                foreach ( $this->principlesOfExercise( $exercise_id, $club ) as $principle_id ) {
                    $seen[ $principle_id ] = true;
                }
            }

            foreach ( array_keys( $seen ) as $principle_id ) {
                $key = (int) $run->team_id . ':' . $principle_id;

                if ( ! isset( $out[ $key ] ) ) {
                    $out[ $key ] = [
                        'team_id'         => (int) $run->team_id,
                        'team_name'       => (string) $run->team_name,
                        'principle_id'    => (int) $principle_id,
                        'sessions_count'  => 0,
                        'last_trained_on' => '',
                    ];
                }

                $out[ $key ]['sessions_count']++;
                if ( (string) $run->run_date > $out[ $key ]['last_trained_on'] ) {
                    $out[ $key ]['last_trained_on'] = (string) $run->run_date;
                }
            }
        }

        return array_values( $out );
    }

    /** @return array{start:string, end:string}|null */
    private function seasonWindow( int $season_id, int $club ): ?array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT start_date, end_date FROM {$wpdb->prefix}tt_seasons WHERE id = %d AND club_id = %d",
            $season_id,
            $club
        ) );

        return $row ? [ 'start' => (string) $row->start_date, 'end' => (string) $row->end_date ] : null;
    }

    /** @return array<int,true> order_index => true */
    private function skippedOrders( int $run_id, int $club ): array {
        global $wpdb;

        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT order_index FROM {$wpdb->prefix}tt_training_plan_run_blocks
              WHERE run_id = %d AND club_id = %d AND was_skipped = 1",
            $run_id,
            $club
        ) );

        $out = [];
        foreach ( (array) $rows as $order ) $out[ (int) $order ] = true;

        return $out;
    }

    /** @var array<int, list<int>> */
    private array $principle_cache = [];

    /** @return list<int> */
    private function principlesOfExercise( int $exercise_id, int $club ): array {
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

    /** Every principle the club's methodology defines. */
    public function principles(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, code, title_json, team_function_key
               FROM {$wpdb->prefix}tt_principles
              WHERE archived_at IS NULL AND ( club_id = %d OR club_id IS NULL )
           ORDER BY code ASC",
            CurrentClub::id()
        ), ARRAY_A );

        if ( ! is_array( $rows ) ) return [];

        foreach ( $rows as &$row ) {
            $row['title'] = $this->title( (string) ( $row['title_json'] ?? '' ), (string) $row['code'] );
            unset( $row['title_json'] );
        }
        unset( $row );

        return $rows;
    }

    /**
     * A principle's title in the reader's locale, falling back to its
     * code so a row is never nameless.
     */
    private function title( string $json, string $code ): string {
        $decoded = json_decode( $json, true );
        if ( ! is_array( $decoded ) || $decoded === [] ) return $code;

        $locale = function_exists( 'determine_locale' ) ? determine_locale() : 'nl_NL';
        $title  = $decoded[ $locale ] ?? $decoded['nl_NL'] ?? $decoded['en_US'] ?? reset( $decoded );

        return is_string( $title ) && $title !== '' ? $title : $code;
    }
}
