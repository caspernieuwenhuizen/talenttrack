<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\MinutesQuery;

/**
 * #4599 — `MinutesQuery::forTeam()` reads the preps, line-ups, executions,
 * substitutions and match lengths of the whole window up front, so a season
 * costs the same number of statements as a month.
 *
 * The golden test pins the result on a window with a league match run
 * through match execution (prep, line-ups, a substitution), a cup match
 * recorded on paper, a tournament fixture recorded through its rotation
 * plan and a match that was planned but never played. The figures are the
 * ones `forTeam()` returned before the reads were batched.
 */
final class MinutesForTeamBatchedTest extends WP_UnitTestCase {

    private const FROM = '2026-02-01';
    private const TO   = '2026-06-30';

    private string $p = '';
    private int $club = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $wpdb->hide_errors();
    }

    public function test_the_result_is_unchanged(): void {
        $team = $this->insertTeam( 'Golden', 'U13' );
        [ $a, $b, $c ] = [ $this->insertPlayer( $team, 'Anna', 1 ), $this->insertPlayer( $team, 'Bram', 2 ), $this->insertPlayer( $team, 'Cas', 3 ) ];

        // League: run through match execution, B on for A at 20 in the second half.
        $league = $this->insertMatch( $team, '2026-03-01', 'League' );
        $prep   = $this->insertPrep( $league, 30 );
        foreach ( [ 1, 2 ] as $half ) {
            $this->insertLineup( $prep, $half, 1, $a );
            $this->insertLineup( $prep, $half, 2, $c );
        }
        $exec = $this->insertExecution( $league, $prep );
        $this->insertSubstitution( $exec, 2, 20, $a, $b );
        $this->insertMinutes( $league, $a, 50 );
        $this->insertMinutes( $league, $b, 10 );
        $this->insertMinutes( $league, $c, 60 );

        // Cup: on paper, no prep, a stored match length.
        $cup = $this->insertMatch( $team, '2026-03-08', 'Cup', 80 );
        $this->insertMinutes( $cup, $a, 80 );
        $this->insertMinutes( $cup, $b, 40 );

        // A tournament fixture completed before minutes were asked for: the
        // plan answers for A, B is on the register with nothing.
        $fixture = $this->insertTournamentFixture( $team, '2026-04-04', [ $a ] );
        $this->insertMinutes( $fixture, $a, null );
        $this->insertMinutes( $fixture, $b, null );

        // Planned with a line-up, never played: counts nowhere.
        $planned = $this->insertMatch( $team, '2026-05-02', 'League', 0, 'planned' );
        $planned_prep = $this->insertPrep( $planned, 30 );
        $this->insertLineup( $planned_prep, 1, 1, $c );

        $rows = ( new MinutesQuery() )->forTeam( $team, self::FROM, self::TO );
        $byName = [];
        foreach ( $rows as $row ) {
            unset( $row['player_id'] );
            $byName[ $row['first_name'] ] = $row;
        }

        $this->assertSame( [ 'Anna', 'Cas', 'Bram' ], array_keys( $byName ), 'the order: minutes high to low' );
        $this->assertSame( self::golden(), $byName );
    }

    /** The same number of statements for 5 matches as for 20. */
    public function test_the_statement_count_does_not_grow_with_the_matches(): void {
        global $wpdb;
        $small = $this->season( 'Five', 5 );
        $large = $this->season( 'Twenty', 20 );

        // Warm whatever is cached per request (config, layouts) first.
        ( new MinutesQuery() )->forTeam( $small, self::FROM, self::TO );

        $before = $wpdb->num_queries;
        $five   = ( new MinutesQuery() )->forTeam( $small, self::FROM, self::TO );
        $count_five = $wpdb->num_queries - $before;

        $before = $wpdb->num_queries;
        $twenty = ( new MinutesQuery() )->forTeam( $large, self::FROM, self::TO );
        $count_twenty = $wpdb->num_queries - $before;

        $this->assertSame( 5, $five[0]['matches'], 'fixture: five matches counted' );
        $this->assertSame( 20, $twenty[0]['matches'], 'fixture: twenty matches counted' );
        $this->assertSame( $count_five, $count_twenty, "{$count_five} statements for 5 matches, {$count_twenty} for 20" );
    }

    /**
     * The figures `forTeam()` returned before #4599, captured on the same
     * fixture.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function golden(): array {
        $row = static fn( string $first, int $jersey, int $total, int $matches, int $starts, int $in, int $off, array $by_type ): array => [
            'first_name'        => $first,
            'last_name'         => 'Golden',
            'jersey_number'     => $jersey,
            'total_minutes'     => $total,
            'matches'           => $matches,
            'starts'            => $starts,
            'subs_in'           => $in,
            'subs_off'          => $off,
            'by_type'           => $by_type,
            // 60 (prep 2 × 30) + 80 (the cup's own length) + 70 (the
            // tournament fixture: 2 × 35, nothing more specific on it).
            'available_minutes' => 210,
        ];
        return [
            'Anna' => $row( 'Anna', 1, 154, 3, 1, 0, 1, [ 'League' => 50, 'Cup' => 80, 'unknown' => 24 ] ),
            'Cas'  => $row( 'Cas', 3, 60, 1, 1, 0, 0, [ 'League' => 60 ] ),
            'Bram' => $row( 'Bram', 2, 50, 2, 0, 1, 0, [ 'League' => 10, 'Cup' => 40 ] ),
        ];
    }

    private function season( string $name, int $matches ): int {
        $team    = $this->insertTeam( $name, 'U13' );
        $players = [ $this->insertPlayer( $team, $name . 'A', 1 ), $this->insertPlayer( $team, $name . 'B', 2 ) ];
        for ( $i = 1; $i <= $matches; $i++ ) {
            $match = $this->insertMatch( $team, sprintf( '2026-03-%02d', $i ), 'League' );
            $prep  = $this->insertPrep( $match, 30 );
            $this->insertLineup( $prep, 1, 1, $players[0] );
            $this->insertLineup( $prep, 2, 1, $players[0] );
            $exec = $this->insertExecution( $match, $prep );
            $this->insertSubstitution( $exec, 2, 10, $players[0], $players[1] );
            $this->insertMinutes( $match, $players[0], 40 );
            $this->insertMinutes( $match, $players[1], 20 );
        }
        return $team;
    }

    private function insertTeam( string $name, string $age_group ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => $age_group ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first, int $shirt ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Golden',
            'jersey_number' => $shirt, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMatch( int $team_id, string $date, string $subtype, int $length = 0, string $status = 'completed' ): int {
        global $wpdb;
        $row = [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => $subtype . ' ' . $date, 'session_date' => $date,
            'activity_type_key' => 'match', 'game_subtype_key' => $subtype,
            'activity_status_key' => $status, 'plan_state' => $status,
            'start_time' => '10:00:00', 'end_time' => '11:45:00',
        ];
        if ( $length > 0 ) $row['match_length_minutes'] = $length;
        $wpdb->insert( "{$this->p}tt_activities", $row );
        return (int) $wpdb->insert_id;
    }

    /** @param list<int> $planned_players on the pitch for both periods in the plan */
    private function insertTournamentFixture( int $team_id, string $date, array $planned_players ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id' => $this->club, 'uuid' => wp_generate_uuid4(), 'name' => 'Golden cup',
            'start_date' => $date, 'end_date' => $date, 'team_id' => $team_id,
        ] );
        $tournament = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Golden cup 1', 'session_date' => $date,
            'activity_type_key' => 'match', 'activity_source_key' => 'tournament',
            'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $activity = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id' => $this->club, 'tournament_id' => $tournament, 'sequence' => 1, 'label' => 'Wedstrijd 1',
            'opponent_name' => 'Ajax', 'opponent_level' => 'equal', 'duration_min' => 24,
            'substitution_windows' => '[12]', 'completed_at' => $date . ' 12:00:00', 'activity_id' => $activity,
        ] );
        $match = (int) $wpdb->insert_id;
        foreach ( $planned_players as $pid ) {
            foreach ( [ 0, 1 ] as $period ) {
                $wpdb->insert( "{$this->p}tt_tournament_assignments", [
                    'club_id' => $this->club, 'match_id' => $match, 'period_index' => $period,
                    'player_id' => $pid, 'position_code' => 'CM',
                ] );
            }
        }
        return $activity;
    }

    private function insertPrep( int $activity_id, int $half_length ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep", [
            'uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'activity_id' => $activity_id,
            'half_length_minutes' => $half_length,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertLineup( int $prep_id, int $half, int $slot, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_prep_lineup", [
            'club_id' => $this->club, 'match_prep_id' => $prep_id, 'half' => $half,
            'slot_number' => $slot, 'player_id' => $player_id,
        ] );
    }

    private function insertExecution( int $activity_id, int $prep_id ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_execution", [
            'uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'activity_id' => $activity_id,
            'match_prep_id' => $prep_id, 'state' => 'finalized',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertSubstitution( int $execution_id, int $half, int $minute, int $off, int $on ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_match_execution_substitutions", [
            'event_uuid' => wp_generate_uuid4(), 'club_id' => $this->club, 'execution_id' => $execution_id,
            'half' => $half, 'minute_in_half' => $minute, 'player_off_id' => $off, 'player_on_id' => $on,
        ] );
    }

    private function insertMinutes( int $activity_id, int $player_id, ?int $minutes ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => 'present', 'is_guest' => 0, 'record_type' => 'actual', 'minutes_played' => $minutes,
        ] );
    }
}
