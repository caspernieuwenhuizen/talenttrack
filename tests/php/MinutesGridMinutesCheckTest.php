<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Reports\MinutesGridQuery;
use TT\Modules\Analytics\Frontend\MinutesCompletenessLabels;
use TT\Modules\Analytics\Reports\MinutesAuditQuery;

/**
 * #4483 — the minutes grid's Minutes row gives the Minutes-audit's answer.
 *
 * The owner's match: eleven a side, seventy minutes, 795 player-minutes
 * entered where the match holds 770 — a substitution counted twice, which
 * nothing on the grid used to say. Every case below asks the grid's row and
 * the audit's row for the same match and expects the same total, target,
 * status and reason, so the two screens cannot drift apart.
 */
final class MinutesGridMinutesCheckTest extends WP_UnitTestCase {

    private const FROM = '2026-10-01';
    private const TO   = '2026-10-31';

    private string $p = '';
    private int $club = 0;
    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->team = $this->insertTeam();
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_owners_match_reads_795_of_770_and_25_over(): void {
        [ $match ] = $this->ownersMatch();

        $grid = $this->gridCheck( $match );

        $this->assertSame( 795, $grid['total_minutes'] );
        $this->assertSame( 770, $grid['available_minutes'], 'eleven a side over seventy minutes' );
        $this->assertSame( 14, $grid['squad_count'] );
        $this->assertSame( 'partial', $grid['status'] );
        $this->assertSame( 'minutes_over', $grid['status_reason'] );
        $this->assertSame(
            sprintf( MinutesCompletenessLabels::reasonTemplates()['minutes_over'], number_format_i18n( 25 ) ),
            MinutesCompletenessLabels::reasonText( $grid['status_reason'], $grid['total_minutes'], (int) $grid['available_minutes'] )
        );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_a_player_set_to_zero_makes_it_770_with_a_player_missing(): void {
        [ $match, $morris ] = $this->ownersMatch();

        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_attendance",
            [ 'minutes_played' => 0 ],
            [ 'activity_id' => $match, 'player_id' => $morris ]
        );

        $grid = $this->gridCheck( $match );

        $this->assertSame( 770, $grid['total_minutes'] );
        $this->assertSame( 'partial', $grid['status'] );
        $this->assertSame( 'players_missing', $grid['status_reason'] );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_a_player_taken_out_of_the_squad_makes_it_complete(): void {
        [ $match, $morris ] = $this->ownersMatch();

        global $wpdb;
        $wpdb->delete( "{$this->p}tt_attendance", [ 'activity_id' => $match, 'player_id' => $morris ] );

        $grid = $this->gridCheck( $match );

        $this->assertSame( 770, $grid['total_minutes'] );
        $this->assertSame( 13, $grid['squad_count'] );
        $this->assertSame( 'complete', $grid['status'] );
        $this->assertSame( '', $grid['status_reason'] );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_a_player_selected_but_never_registered_counts_as_missing(): void {
        $match   = $this->insertMatch( '2026-10-10', 70 );
        $players = $this->insertPlayers( 12 );
        foreach ( array_slice( $players, 0, 11 ) as $pid ) {
            $this->insertMinutes( $match, $pid, 70 );
        }
        // The plan has him, the register does not: no box on the grid, but
        // the audit still waits for his minutes.
        $this->insertPlannedRow( $match, $players[11] );

        $grid = $this->gridCheck( $match );

        $this->assertSame( 12, $grid['squad_count'] );
        $this->assertSame( 'players_missing', $grid['status_reason'] );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_minutes_of_a_player_off_the_roster_count_but_are_kept_apart(): void {
        $match   = $this->insertMatch( '2026-10-12', 70 );
        $players = $this->insertPlayers( 11 );
        foreach ( $players as $pid ) {
            $this->insertMinutes( $match, $pid, 70 );
        }
        // Moved to another team since; his register row stays on this match.
        global $wpdb;
        $wpdb->update( "{$this->p}tt_players", [ 'team_id' => $this->insertTeam() ], [ 'id' => $players[10] ] );

        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $roster = array_map( static fn( array $pl ): int => (int) $pl['player_id'], $matrix['players'] );
        $check  = MinutesGridQuery::minutesCheck( $this->column( $matrix, $match ), $matrix['cells'], $roster );

        $this->assertNotContains( $players[10], $roster );
        $this->assertSame( 770, $check['total_minutes'] );
        $this->assertSame( 70, $check['offgrid_minutes'] );
        $this->assertSame( 1, $check['offgrid_recorded'] );
        $this->assertSame( 'complete', $check['status'] );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_a_tournament_column_has_no_minutes_target(): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'           => $this->club,
            'team_id'           => $this->team,
            'title'             => 'Cup day',
            'session_date'      => '2026-10-17',
            'activity_type_key' => 'tournament',
            'plan_state'        => 'completed',
        ] );
        $day = (int) $wpdb->insert_id;
        $this->insertPlayers( 1 );

        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $column = $this->column( $matrix, $day );
        $check  = MinutesGridQuery::minutesCheck( $column, $matrix['cells'] );

        $this->assertNull( $column['available_minutes'] );
        $this->assertNull( $column['squad_count'] );
        $this->assertNull( $check['available_minutes'] );
        $this->assertSame( 'rollup', $check['status'], 'judged as the audit judges a tournament day' );
        $this->assertSame( '', $check['status_reason'] );
    }

    public function test_the_rest_payload_carries_the_yardstick(): void {
        [ $match ] = $this->ownersMatch();

        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $column = $this->column( $matrix, $match );

        $this->assertSame( 770, $column['available_minutes'] );
        $this->assertSame( 14, $column['squad_count'] );

        $one = ( new MinutesGridQuery() )->forActivity( $match );
        $this->assertNotNull( $one );
        $this->assertSame( 770, $one['activity']['available_minutes'], 'GET /activities/{id}/minutes carries the same column' );
    }

    /**
     * Hidde's first case on #4487: twelve selected, eleven with minutes, and
     * a player registered absent with a box at 0. Typing 30 for him puts him
     * on the audit's squad, so the row still says a player is missing. A
     * squad count frozen at render time said 25 over instead.
     */
    public function test_minutes_typed_for_an_absent_player_put_him_on_the_squad(): void {
        $match   = $this->insertMatch( '2026-10-20', 70 );
        $players = $this->insertPlayers( 13 );
        foreach ( array_slice( $players, 0, 11 ) as $pid ) {
            $this->insertMinutes( $match, $pid, 70 );
        }
        $this->insertMinutes( $match, $players[11], 0 );
        $absent = $players[12];
        $this->insertMinutes( $match, $absent, 0, 'absent' );

        $before = $this->gridState( $match );
        $this->assertFalse( $before['cells'][ $absent ][ $match ]['selected'], 'registered absent: not on the squad without minutes' );
        $this->assertSame( 12, $before['check']['squad_count'] );
        $this->assertSame( 'players_missing', $before['check']['status_reason'] );

        $live = $this->liveRecount( $before, $match, [ $absent => 30 ] );

        global $wpdb;
        $wpdb->update( "{$this->p}tt_attendance", [ 'minutes_played' => 30 ], [ 'activity_id' => $match, 'player_id' => $absent ] );
        $after = $this->gridCheck( $match );

        $this->assertSame( 13, $after['squad_count'], 'with minutes he is on the squad' );
        $this->assertSame( [ 'partial', 'players_missing' ], $live, 'the live row while typing' );
        $this->assertSame( [ $after['status'], $after['status_reason'] ], $live, 'is the row after Save' );
        $this->assertSameAsTheAudit( $match );
    }

    /**
     * Hidde's second case: the absent player is rendered with 40 minutes, so
     * he is on the squad. Clearing his box takes him off it again: the match
     * is complete, not short of a player.
     */
    public function test_clearing_an_absent_players_minutes_takes_him_off_the_squad(): void {
        $match   = $this->insertMatch( '2026-10-22', 70 );
        $players = $this->insertPlayers( 12 );
        foreach ( array_slice( $players, 0, 11 ) as $pid ) {
            $this->insertMinutes( $match, $pid, 70 );
        }
        $absent = $players[11];
        $this->insertMinutes( $match, $absent, 40, 'absent' );

        $before = $this->gridState( $match );
        $this->assertSame( 12, $before['check']['squad_count'] );
        $this->assertSame( 'minutes_over', $before['check']['status_reason'] );

        $live = $this->liveRecount( $before, $match, [ $absent => 0 ] );

        global $wpdb;
        $wpdb->update( "{$this->p}tt_attendance", [ 'minutes_played' => null ], [ 'activity_id' => $match, 'player_id' => $absent ] );
        $after = $this->gridCheck( $match );

        $this->assertSame( [ 'complete', '' ], $live, 'the live row while typing' );
        $this->assertSame( [ $after['status'], $after['status_reason'] ], $live, 'is the row after Save' );
        $this->assertSameAsTheAudit( $match );
    }

    public function test_the_rest_payload_carries_the_verdict(): void {
        [ $match ] = $this->ownersMatch();

        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $column = $this->column( $matrix, $match );

        $this->assertIsArray( $column['minutes_check'] );
        $this->assertSame( 795, $column['minutes_check']['total_minutes'] );
        $this->assertSame( 14, $column['minutes_check']['recorded_count'] );
        $this->assertSame( 'partial', $column['minutes_check']['status'] );
        $this->assertSame( 'minutes_over', $column['minutes_check']['status_reason'] );
        $this->assertSame( 0, $column['minutes_check']['offgrid_squad'] );

        $one = ( new MinutesGridQuery() )->forActivity( $match );
        $this->assertNotNull( $one );
        $this->assertSame( 'minutes_over', $one['activity']['minutes_check']['status_reason'], 'GET /activities/{id}/minutes carries it too' );
    }

    // ---- the comparison -------------------------------------------------

    /**
     * The script's rule (`frontend-minutes-grid.js`, `recomputeMinutesCheck`)
     * run on the rendered state with some boxes retyped: squad = off-grid
     * squad + boxes that are selected or hold minutes; recorded and total =
     * off-grid figures + boxes with minutes.
     *
     * @param array<string, mixed> $state  From `gridState()`.
     * @param array<int, int>      $typed  player id => minutes typed into his box
     * @return array{0:string, 1:string}
     */
    private function liveRecount( array $state, int $match, array $typed ): array {
        $check    = $state['check'];
        $squad    = (int) $check['offgrid_squad'];
        $recorded = (int) $check['offgrid_recorded'];
        $total    = (int) $check['offgrid_minutes'];
        foreach ( $state['roster'] as $pid ) {
            if ( ! isset( $state['cells'][ $pid ][ $match ] ) ) continue;
            $cell    = $state['cells'][ $pid ][ $match ];
            $minutes = array_key_exists( $pid, $typed ) ? $typed[ $pid ] : (int) $cell['minutes'];
            if ( $minutes > 0 ) {
                $recorded++;
                $total += $minutes;
            }
            if ( $minutes > 0 || ! empty( $cell['selected'] ) ) $squad++;
        }
        return MinutesAuditQuery::completeness( $recorded, $squad, $total, $check['available_minutes'] );
    }

    /** @return array{check:array<string, mixed>, cells:array<int, array<int, array<string, mixed>>>, roster:list<int>} */
    private function gridState( int $match ): array {
        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $roster = array_map( static fn( array $pl ): int => (int) $pl['player_id'], $matrix['players'] );
        return [
            'check'  => MinutesGridQuery::minutesCheck( $this->column( $matrix, $match ), $matrix['cells'], $roster ),
            'cells'  => $matrix['cells'],
            'roster' => $roster,
        ];
    }

    /** The grid's Minutes row and the audit's row agree on the same match. */
    private function assertSameAsTheAudit( int $match ): void {
        $grid  = $this->gridCheck( $match );
        $audit = null;
        foreach ( ( new MinutesAuditQuery() )->matrix( $this->team, self::FROM, self::TO )['games'] as $g ) {
            if ( (int) $g['activity_id'] === $match ) $audit = $g;
        }
        $this->assertNotNull( $audit, 'the audit lists the match' );

        $this->assertSame( (int) $audit['total_minutes'], $grid['total_minutes'], 'the footer number is the audit\'s number' );
        $this->assertSame( (int) $audit['available_minutes'], $grid['available_minutes'] );
        $this->assertSame( (int) $audit['squad_count'], $grid['squad_count'] );
        $this->assertSame( (string) $audit['status'], $grid['status'] );
        $this->assertSame( (string) $audit['status_reason'], $grid['status_reason'] );
        $this->assertSame(
            MinutesCompletenessLabels::reasonText( (string) $audit['status_reason'], (int) $audit['total_minutes'], (int) $audit['available_minutes'] ),
            MinutesCompletenessLabels::reasonText( $grid['status_reason'], $grid['total_minutes'], (int) $grid['available_minutes'] )
        );
    }

    /** @return array<string, mixed> */
    private function gridCheck( int $match ): array {
        $matrix = ( new MinutesGridQuery() )->matrix( $this->team, self::FROM, self::TO );
        $roster = array_map( static fn( array $pl ): int => (int) $pl['player_id'], $matrix['players'] );
        return MinutesGridQuery::minutesCheck( $this->column( $matrix, $match ), $matrix['cells'], $roster );
    }

    /**
     * @param array<string, mixed> $matrix
     * @return array<string, mixed>
     */
    private function column( array $matrix, int $activity_id ): array {
        foreach ( $matrix['activities'] as $a ) {
            if ( (int) $a['activity_id'] === $activity_id ) return $a;
        }
        $this->fail( 'the grid has a column for the match' );
    }

    // ---- fixtures ---------------------------------------------------------

    /**
     * Hedel O14-1 against Baronie O14-1: 8 × 70 + 25 + 60 + 60 + 50 + 15 + 25.
     *
     * @return array{0:int, 1:int} [ match id, the player on the last 25 ]
     */
    private function ownersMatch(): array {
        $match   = $this->insertMatch( '2026-10-31', 70 );
        $players = $this->insertPlayers( 14 );
        $minutes = [ 70, 70, 70, 70, 70, 70, 70, 70, 25, 60, 60, 50, 15, 25 ];
        foreach ( $players as $i => $pid ) {
            $this->insertMinutes( $match, $pid, $minutes[ $i ] );
        }
        return [ $match, $players[13] ];
    }

    private function insertTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Hedel O14-1' ] );
        return (int) $wpdb->insert_id;
    }

    /** @return list<int> */
    private function insertPlayers( int $count ): array {
        global $wpdb;
        $ids = [];
        for ( $n = 1; $n <= $count; $n++ ) {
            $wpdb->insert( "{$this->p}tt_players", [
                'club_id'       => $this->club,
                'team_id'       => $this->team,
                'first_name'    => 'Player',
                'last_name'     => 'No ' . $n,
                'jersey_number' => $n,
                'status'        => 'active',
            ] );
            $ids[] = (int) $wpdb->insert_id;
        }
        return $ids;
    }

    private function insertMatch( string $date, int $length ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'              => $this->club,
            'team_id'              => $this->team,
            'title'                => 'Baronie O14-1',
            'session_date'         => $date,
            'activity_type_key'    => 'match',
            'game_subtype_key'     => 'League',
            'activity_status_key'  => 'completed',
            'plan_state'           => 'completed',
            'match_length_minutes' => $length,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertMinutes( int $activity_id, int $player_id, int $minutes, string $status = 'present' ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'        => $this->club,
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => $status,
            'is_guest'       => 0,
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
        ] );
    }

    private function insertPlannedRow( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'present',
            'is_guest'    => 0,
            'record_type' => 'expected',
        ] );
    }
}
