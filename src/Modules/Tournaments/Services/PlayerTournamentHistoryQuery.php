<?php
namespace TT\Modules\Tournaments\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\TournamentOpponentLevel;
use TT\Infrastructure\Query\LookupPill;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * PlayerTournamentHistoryQuery (#3561, epic #3558) — one player's
 * tournament record, keyed by the player rather than by the tournament.
 *
 * Which player question does this answer? *Where have they come from?* and
 * *where are they now?* — for the days the academy plays four short
 * fixtures instead of one long one. A tournament day is where a coach
 * rotates hardest, and until now the only surface that reported the
 * resulting minutes was the tournament's own planner: you could see how one
 * squad's day had been divided, and nowhere could you see how one child's
 * season of them had.
 *
 * ## Minutes come from the fixture's register, then from the plan
 *
 * #4053 — the figures a coach confirmed on the completion step are what a
 * child's record carries; the rotation plan answers only where the register
 * holds nothing, which is every fixture completed before v4.135.0. A
 * confirmed `0` is a real 0 and never falls back.
 *
 * The rule is not implemented here. `TournamentMinutesResolver` holds it,
 * and the minutes grid, the minutes reports and
 * `TournamentsRestController::computeTotals()` come through the same class —
 * two surfaces disagreeing about one child's minutes is the bug that class
 * exists to close, and a second copy of the fallback would recreate it.
 *
 * ## What "no result" means
 *
 * `our_score` and `their_score` are **null** when nothing was recorded,
 * never `0`. A goalless draw and a fixture nobody typed in are different
 * facts, and a child's record must not report the second as the first.
 */
final class PlayerTournamentHistoryQuery {

    /** The opponent levels a "played up" total counts. */
    private const HARDER = [
        TournamentOpponentLevel::STRONGER,
        TournamentOpponentLevel::MUCH_STRONGER,
    ];

    /**
     * Everything the player file's Tournaments tab renders, already
     * decided. The tab composes; it does not compute (CLAUDE.md §4).
     *
     * @return array{
     *     player_id: int,
     *     tournaments: list<array<string,mixed>>,
     *     totals: array<string,mixed>,
     *     upcoming: array<string,mixed>|null
     * }
     *
     * `starts` and `full_matches` count every fixture the player is down
     * for; `minutes` counts the completed ones only. That split is the
     * parity constraint: the coach's minutes ticker counts a planned start
     * as a start, and the player file showing a different number from the
     * screen the coach was looking at is the drift this endpoint exists to
     * prevent.
     */
    public function forPlayer( int $player_id ): array {
        $empty = [
            'player_id'   => $player_id,
            'tournaments' => [],
            'totals'      => self::emptyTotals(),
            'upcoming'    => null,
        ];
        if ( $player_id <= 0 ) return $empty;

        $tournaments = $this->tournaments( $player_id );
        if ( $tournaments === [] ) return $empty;

        $fixtures = $this->fixtures( array_keys( $tournaments ) );

        // #4053 — every figure below comes from the one resolver, register
        // first. Narrowed to this player, so a child's file reads their own
        // row and nobody else's.
        $match_ids = [];
        foreach ( $fixtures as $list ) {
            foreach ( $list as $fixture ) $match_ids[] = (int) $fixture['match_id'];
        }
        $resolved = TournamentMinutesResolver::forMatches( $match_ids, $player_id );

        $out      = [];
        $totals   = self::emptyTotals();
        $upcoming = null;

        foreach ( $tournaments as $tid => $tournament ) {
            $rows              = [];
            $played            = 0;
            $scheduled         = 0;
            $starts            = 0;
            $upcoming_starts   = 0;
            $upcoming_fixtures = 0;
            $full_matches      = 0;
            $harder_minutes    = 0;

            foreach ( $fixtures[ $tid ] ?? [] as $fixture ) {
                // A fixture the player has neither an assignment nor a register
                // row for is not on their record. A bench-only one is: being in
                // the squad and not playing is a fact about their day.
                $out_row = $resolved[ (int) $fixture['match_id'] ][ $player_id ] ?? null;
                if ( $out_row === null ) continue;

                $rows[] = [
                    'match_id'       => (int) $fixture['match_id'],
                    'sequence'       => (int) $fixture['sequence'],
                    'label'          => (string) $fixture['label'],
                    'opponent_name'  => (string) $fixture['opponent_name'],
                    'opponent_level' => LookupPill::describe( 'tournament_opponent_level', (string) $fixture['opponent_level'] ),
                    'our_score'      => $fixture['our_score'],
                    'their_score'    => $fixture['their_score'],
                    'completed'      => (bool) $fixture['completed'],
                    'duration_min'   => (int) $fixture['shape']['duration'],
                    'minutes'        => (int) $out_row['minutes'],
                    // #4053 — which of the two answered, said rather than
                    // implied. `plan` on a completed fixture means its register
                    // was never written (it predates v4.135.0); on an
                    // uncompleted one it means these minutes are still a plan,
                    // and the tab labels them as one (#3713).
                    'minutes_source' => (string) $out_row['source'],
                    'role'           => (string) $out_row['role'],
                    'positions'      => $out_row['positions'],
                ];

                // Starts and full matches count every fixture the player is
                // down for, played or not, which is what the coach's minutes
                // ticker counts — the two are the same rotation plan read
                // from two ends and they have to agree (the epic's parity
                // constraint). Minutes do not: `played` is the completed
                // fixtures and `scheduled` is the rest, so a day still to
                // come never reads as a day already had. `upcoming` below
                // reports its own starts, so a reader can tell the two apart.
                if ( $out_row['started'] ) $starts++;
                if ( $out_row['full'] )    $full_matches++;

                if ( $fixture['completed'] ) {
                    $played += $out_row['minutes'];
                    if ( in_array( (string) $fixture['opponent_level'], self::HARDER, true ) ) {
                        $harder_minutes += $out_row['minutes'];
                    }
                } else {
                    $scheduled += $out_row['minutes'];
                    $upcoming_fixtures++;
                    if ( $out_row['started'] ) $upcoming_starts++;
                }
            }

            if ( $rows === [] ) continue;

            $target = $tournament['target_minutes'];

            $out[] = [
                'tournament_id'     => $tid,
                'uuid'              => (string) $tournament['uuid'],
                'name'              => (string) $tournament['name'],
                'start_date'        => $tournament['start_date'],
                'end_date'          => $tournament['end_date'],
                'team_id'           => (int) $tournament['team_id'],
                'team_name'         => (string) $tournament['team_name'],
                'target_minutes'    => $target,
                'played_minutes'    => $played,
                'scheduled_minutes' => $scheduled,
                'starts'            => $starts,
                'full_matches'      => $full_matches,
                'fixture_count'     => count( $rows ),
                'completed_count'   => count( $rows ) - $upcoming_fixtures,
                'fixtures'          => $rows,
            ];

            $totals['tournaments']++;
            $totals['minutes']             += $played;
            $totals['starts']              += $starts;
            $totals['full_matches']        += $full_matches;
            $totals['fixtures']            += count( $rows );
            $totals['minutes_vs_stronger'] += $harder_minutes;

            // A target is only "met or missed" once there is something to
            // measure. A tournament that has not been played yet is neither,
            // and counting it as missed would make every new squad list look
            // like a failure the day it is picked.
            if ( $target !== null && $upcoming_fixtures < count( $rows ) ) {
                $totals['targets_set']++;
                if ( $played >= $target ) $totals['targets_met']++;
            }

            // The next tournament that still has fixtures this player is
            // assigned to — the **earliest-starting** one, not necessarily a
            // future one. A tournament whose fixtures were never marked
            // complete still has the player down for them, and saying so on
            // their file is more useful than hiding it behind a date check.
            // `$tournaments` is newest first, so the last write wins and is
            // the earliest.
            if ( $upcoming_fixtures > 0 ) {
                $upcoming = [
                    'tournament_id'     => $tid,
                    'name'              => (string) $tournament['name'],
                    'start_date'        => $tournament['start_date'],
                    'fixture_count'     => $upcoming_fixtures,
                    'scheduled_minutes' => $scheduled,
                    'starts'            => $upcoming_starts,
                ];
            }
        }

        return [
            'player_id'   => $player_id,
            'tournaments' => $out,
            'totals'      => $totals,
            'upcoming'    => $upcoming,
        ];
    }

    /** @return array<string,int> */
    private static function emptyTotals(): array {
        return [
            'tournaments'         => 0,
            'fixtures'            => 0,
            'minutes'             => 0,
            'starts'              => 0,
            'full_matches'        => 0,
            'minutes_vs_stronger' => 0,
            'targets_met'         => 0,
            'targets_set'         => 0,
        ];
    }

    /**
     * The tournaments this player was in the squad for, newest first.
     *
     * Keyed on `player_id`, never on an account: a player released and
     * taken back keeps their record, and a player with no login has one
     * at all.
     *
     * Archived and trashed tournaments are excluded — a day the academy
     * has taken out of its own records is not part of a child's history.
     *
     * @return array<int, array<string,mixed>>
     */
    private function tournaments( int $player_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT t.id, t.uuid, t.name, t.start_date, t.end_date, t.team_id,
                    tm.name AS team_name,
                    s.target_minutes
               FROM {$p}tt_tournament_squad s
               JOIN {$p}tt_tournaments t ON t.id = s.tournament_id AND t.club_id = s.club_id
          LEFT JOIN {$p}tt_teams tm ON tm.id = t.team_id AND tm.club_id = t.club_id
              WHERE s.player_id = %d
                AND s.club_id = %d
                AND t.archived_at IS NULL
                AND t.trashed_at IS NULL
           ORDER BY t.start_date DESC, t.id DESC",
            $player_id,
            (int) CurrentClub::id()
        ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['id'] ] = [
                'uuid'           => (string) $row['uuid'],
                'name'           => (string) $row['name'],
                'start_date'     => $row['start_date'],
                'end_date'       => $row['end_date'],
                'team_id'        => (int) $row['team_id'],
                'team_name'      => (string) ( $row['team_name'] ?? '' ),
                'target_minutes' => $row['target_minutes'] !== null ? (int) $row['target_minutes'] : null,
            ];
        }

        return $out;
    }

    /**
     * Every fixture of those tournaments, already divided into periods.
     *
     * @param list<int> $tournament_ids
     * @return array<int, list<array<string,mixed>>> tournament id => fixtures
     */
    private function fixtures( array $tournament_ids ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $tournament_ids === [] ) return [];

        $placeholders = implode( ',', array_fill( 0, count( $tournament_ids ), '%d' ) );
        $params       = array_map( 'intval', $tournament_ids );
        $params[]     = (int) CurrentClub::id();

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders are generated, values are bound below.
        $sql = "SELECT id, tournament_id, sequence, label, opponent_name, opponent_level,
                       duration_min, substitution_windows, completed_at, our_score, their_score
                  FROM {$p}tt_tournament_matches
                 WHERE tournament_id IN ($placeholders)
                   AND club_id = %d
              ORDER BY tournament_id ASC, sequence ASC, id ASC";

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$params ), ARRAY_A );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[ (int) $row['tournament_id'] ][] = [
                'match_id'       => (int) $row['id'],
                'sequence'       => (int) $row['sequence'],
                'label'          => (string) ( $row['label'] ?? '' ),
                'opponent_name'  => (string) ( $row['opponent_name'] ?? '' ),
                'opponent_level' => (string) ( $row['opponent_level'] ?? '' ),
                // #3532 — null, not 0. "Nobody typed it in" is not 0-0.
                'our_score'      => isset( $row['our_score'] ) ? (int) $row['our_score'] : null,
                'their_score'    => isset( $row['their_score'] ) ? (int) $row['their_score'] : null,
                'completed'      => ! empty( $row['completed_at'] ),
                'shape'          => TournamentMinutesCalculator::fixtureShape(
                    (int) $row['duration_min'],
                    (string) $row['substitution_windows']
                ),
            ];
        }

        return $out;
    }
}
