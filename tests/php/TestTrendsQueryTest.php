<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Measurements\Reports\TestTrendsQuery;

/**
 * #2537 — the Test trends read model.
 *
 * The contract that matters is direction awareness. On a `lower` test (a
 * sprint time) a NEGATIVE change is an improvement, and the verdict and the
 * ranking have to follow the test rather than the sign of the number. A
 * `neutral` test gets no verdict at all. Getting this backwards is the
 * fastest way to lose a coach's trust in the report, so it is pinned here.
 */
final class TestTrendsQueryTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $team_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $this->club, 'name' => 'U14 trends', 'age_group' => 'U14',
        ] );
        $this->team_id = (int) $wpdb->insert_id;
    }

    public function test_lower_is_better_treats_a_negative_change_as_progress(): void {
        $def = $this->definition( 'Sprint 10 m', 'numeric', 'lower', 's' );

        $faster = $this->player( 'Milan', 'Faster' );
        $slower = $this->player( 'Daan', 'Slower' );
        $same   = $this->player( 'Jesse', 'Same' );

        // Sprint times, in seconds.
        $this->results( $def, $faster, [ '2026-01-05' => 2.05, '2026-03-05' => 1.94 ] ); // −5.4%
        $this->results( $def, $slower, [ '2026-01-05' => 2.05, '2026-03-05' => 2.15 ] ); // +4.9%
        $this->results( $def, $same,   [ '2026-01-05' => 2.05, '2026-03-05' => 2.04 ] ); // −0.5%

        $data = ( new TestTrendsQuery() )->forDefinition( $def );
        $this->assertTrue( $data['has_direction'] );

        $by_name = [];
        foreach ( $data['players'] as $p ) $by_name[ $p['name'] ] = $p;

        $this->assertSame( 'improved', $by_name['Milan Faster']['verdict'], 'a faster sprint is an improvement' );
        $this->assertLessThan( 0, $by_name['Milan Faster']['delta'], 'and its delta is negative' );

        $this->assertSame( 'declined', $by_name['Daan Slower']['verdict'], 'a slower sprint is a decline' );
        $this->assertSame( 'flat', $by_name['Jesse Same']['verdict'], 'a change inside the noise is neither' );
    }

    public function test_higher_is_better_is_the_mirror_image(): void {
        $def    = $this->definition( 'Vertical jump', 'numeric', 'higher', 'cm' );
        $higher = $this->player( 'Up', 'Player' );
        $lower  = $this->player( 'Down', 'Player' );

        $this->results( $def, $higher, [ '2026-01-05' => 28.0, '2026-03-05' => 33.0 ] );
        $this->results( $def, $lower,  [ '2026-01-05' => 33.0, '2026-03-05' => 28.0 ] );

        $by_name = [];
        foreach ( ( new TestTrendsQuery() )->forDefinition( $def )['players'] as $p ) $by_name[ $p['name'] ] = $p;

        $this->assertSame( 'improved', $by_name['Up Player']['verdict'] );
        $this->assertSame( 'declined', $by_name['Down Player']['verdict'] );
    }

    /**
     * #2628 — the display state the reports and the REST payload share. It
     * follows the verdict, never the sign: a faster sprint is 'up' even
     * though its delta is negative.
     */
    public function test_trend_state_follows_the_verdict_not_the_sign(): void {
        $def    = $this->definition( 'Sprint 10 m', 'numeric', 'lower', 's' );
        $faster = $this->player( 'Milan', 'Faster' );
        $slower = $this->player( 'Daan', 'Slower' );
        $same   = $this->player( 'Jesse', 'Same' );

        $this->results( $def, $faster, [ '2026-01-05' => 2.05, '2026-03-05' => 1.94 ] );
        $this->results( $def, $slower, [ '2026-01-05' => 2.05, '2026-03-05' => 2.15 ] );
        $this->results( $def, $same,   [ '2026-01-05' => 2.05, '2026-03-05' => 2.04 ] );

        $by_name = [];
        foreach ( ( new TestTrendsQuery() )->forDefinition( $def )['players'] as $p ) $by_name[ $p['name'] ] = $p;

        $this->assertSame( 'up', $by_name['Milan Faster']['trend'], 'a faster sprint points up' );
        $this->assertLessThan( 0, $by_name['Milan Faster']['delta'], 'while its number is negative' );
        $this->assertSame( 'down', $by_name['Daan Slower']['trend'] );
        $this->assertSame( 'flat', $by_name['Jesse Same']['trend'] );
    }

    /**
     * #2628 — a neutral test still gets a state, so the report can say which
     * way the value moved. It is deliberately not one of the verdict states:
     * a taller player is not a better one.
     */
    public function test_neutral_test_reports_direction_of_travel_without_a_verdict(): void {
        $def     = $this->definition( 'Height', 'numeric', 'neutral', 'cm' );
        $growing = $this->player( 'Growing', 'Player' );
        $shrunk  = $this->player( 'Shrunk', 'Player' );

        $this->results( $def, $growing, [ '2026-01-05' => 162.0, '2026-03-05' => 168.0 ] );
        $this->results( $def, $shrunk,  [ '2026-01-05' => 168.0, '2026-03-05' => 162.0 ] );

        $by_name = [];
        foreach ( ( new TestTrendsQuery() )->forDefinition( $def )['players'] as $p ) $by_name[ $p['name'] ] = $p;

        $this->assertSame( 'rose', $by_name['Growing Player']['trend'] );
        $this->assertSame( 'fell', $by_name['Shrunk Player']['trend'] );
        $this->assertNull( $by_name['Growing Player']['verdict'], 'still no judgement' );
        $this->assertNull( $by_name['Shrunk Player']['verdict'] );
    }

    /** No previous reading is no state — the report shows an em dash. */
    public function test_a_single_reading_has_no_trend_state(): void {
        $def    = $this->definition( 'Vertical jump', 'numeric', 'higher', 'cm' );
        $player = $this->player( 'Only', 'Once' );
        $this->results( $def, $player, [ '2026-01-05' => 28.0 ] );

        $data = ( new TestTrendsQuery() )->forDefinition( $def );

        $this->assertNull( $data['players'][0]['trend'] );
        $this->assertNull( $data['players'][0]['delta'], 'and no fabricated zero' );
    }

    /** A test with no better or worse must never hand back a judgement. */
    public function test_neutral_test_has_no_verdict(): void {
        $def    = $this->definition( 'Height', 'numeric', 'neutral', 'cm' );
        $player = $this->player( 'Growing', 'Player' );
        $this->results( $def, $player, [ '2026-01-05' => 162.0, '2026-03-05' => 168.0 ] );

        $data = ( new TestTrendsQuery() )->forDefinition( $def );

        $this->assertFalse( $data['has_direction'], 'a neutral test has no direction' );
        $this->assertNull( $data['players'][0]['verdict'], 'and therefore no verdict' );
        $this->assertSame( 6.0, round( (float) $data['players'][0]['delta'], 1 ), 'the change is still reported as a fact' );

        $ranks = ( new TestTrendsQuery() )->rankings( $data['players'] );
        $this->assertSame( [], $ranks['improved'], 'a neutral test can rank nobody as improved' );
        $this->assertSame( [], $ranks['declined'] );
    }

    /**
     * The mockup's bug, pinned: a small improvement on a lower-is-better test
     * must not be listed under "fallen back" just because the number is
     * negative.
     */
    public function test_rankings_never_put_an_improvement_in_the_declined_column(): void {
        $def = $this->definition( 'Sprint 10 m', 'numeric', 'lower', 's' );
        $a   = $this->player( 'Big', 'Gain' );
        $b   = $this->player( 'Small', 'Gain' );

        $this->results( $def, $a, [ '2026-01-05' => 2.10, '2026-03-05' => 1.95 ] ); // −7.1%
        $this->results( $def, $b, [ '2026-01-05' => 2.05, '2026-03-05' => 2.02 ] ); // −1.5% → flat

        $data  = ( new TestTrendsQuery() )->forDefinition( $def );
        $ranks = ( new TestTrendsQuery() )->rankings( $data['players'] );

        $declined_names = array_column( $ranks['declined'], 'name' );
        $this->assertNotContains( 'Small Gain', $declined_names );
        $this->assertSame( [ 'Big Gain' ], array_column( $ranks['improved'], 'name' ) );
    }

    /** A missed round narrows the window; it must not read as a zero. */
    public function test_a_gap_does_not_become_a_zero(): void {
        $def    = $this->definition( 'Sprint 10 m', 'numeric', 'lower', 's' );
        $player = $this->player( 'Gap', 'Player' );
        $other  = $this->player( 'Full', 'Player' );

        $this->results( $def, $player, [ '2026-01-05' => 2.10, '2026-05-05' => 2.00 ] );
        $this->results( $def, $other,  [ '2026-01-05' => 2.00, '2026-03-05' => 1.98, '2026-05-05' => 1.96 ] );

        $data = ( new TestTrendsQuery() )->forDefinition( $def );
        $this->assertCount( 3, $data['dates'], 'the axis is the union of every measuring moment' );

        $by_name = [];
        foreach ( $data['players'] as $p ) $by_name[ $p['name'] ] = $p;

        $this->assertArrayNotHasKey( '2026-03-05', $by_name['Gap Player']['values'], 'the missed round stays absent' );
        $this->assertSame( 2, (int) $by_name['Gap Player']['count'] );
        // The average on the missed date is the other player's value alone.
        $this->assertSame( 1.98, round( $data['average']['2026-03-05'], 2 ) );
    }

    public function test_team_scope_of_an_empty_list_returns_nothing(): void {
        $def    = $this->definition( 'Sprint 10 m', 'numeric', 'lower', 's' );
        $player = $this->player( 'Scoped', 'Out' );
        $this->results( $def, $player, [ '2026-01-05' => 2.10, '2026-03-05' => 2.00 ] );

        $data = ( new TestTrendsQuery() )->forDefinition( $def, [], [] );
        $this->assertSame( [], $data['players'], 'a reader who coaches no team sees nothing' );
    }

    /* ---- fixtures ------------------------------------------------------- */

    private function definition( string $name, string $type, string $direction, string $unit ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_measurement_definitions", [
            'club_id'     => $this->club,
            'category_id' => 0,
            'name'        => $name,
            'value_type'  => $type,
            'unit'        => $unit,
            'direction'   => $direction,
            'is_active'   => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function player( string $first, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team_id,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string, float> $by_date */
    private function results( int $definition_id, int $player_id, array $by_date ): void {
        global $wpdb;
        foreach ( $by_date as $date => $value ) {
            $wpdb->insert( "{$this->p}tt_measurement_results", [
                'club_id'       => $this->club,
                'definition_id' => $definition_id,
                'player_id'     => $player_id,
                'recorded_date' => $date,
                'value_numeric' => $value,
            ] );
        }
    }
}
