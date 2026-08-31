<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCoverage;
use TT\Modules\DemoData\Generators\PlayerStatusGenerator;
use TT\Modules\DemoData\Generators\TeamGenerator;
use TT\Modules\Players\PlayerStatusModule;

/**
 * #3242 — the demo academy's missing traffic-light inputs, and the age
 * spread that makes one of them askable at all.
 *
 * Two of `PlayerStatusCalculator`'s four inputs were empty on every
 * generated player, so the status the demo showed was not the status the
 * product produces for a real club, and every surface built on those halves
 * rendered blank. The demo academy is the shop window; this is not a
 * testing convenience.
 *
 * The properties pinned here are the ones a careless regeneration would
 * lose: that a squad above the potential floor exists at all, that the
 * generator respects #3265's floor rather than seeding a judgement the
 * product declines to ask for, and that the deliberate gaps survive — a
 * demo where nothing is ever missing or overdue teaches the wrong thing
 * about what the product notices.
 */
final class DemoPlayerStatusTest extends WP_UnitTestCase {

    private string $p;
    private int $club;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
    }

    // ── the age spread ─────────────────────────────────────────────────

    /**
     * The bug behind the bug. `array_slice( $ladder, 0, $count )` takes the
     * **youngest** N, which is how the demo academy came to be U7/U8/U9
     * with a seven-year-old as its oldest player.
     */
    public function test_the_spread_anchors_both_ends_of_the_ladder(): void {
        $ladder = [ 'JO7', 'JO8', 'JO9', 'JO10', 'JO11', 'JO12', 'JO13', 'JO14', 'JO15', 'JO16', 'JO17', 'JO19' ];

        $three = TeamGenerator::spreadAcrossLadder( $ladder, 3 );

        $this->assertCount( 3, $three );
        $this->assertSame( 'JO7', $three[0], 'The youngest squad is still represented.' );
        $this->assertSame( 'JO19', $three[2], 'And so is the oldest — which is the half that was missing.' );
        $this->assertSame( $three, array_unique( $three ), 'No squad is created twice.' );
    }

    /** The property the rest of this issue depends on. */
    public function test_the_default_preset_includes_a_squad_above_the_potential_floor(): void {
        $ladder = [ 'JO7', 'JO8', 'JO9', 'JO10', 'JO11', 'JO12', 'JO13', 'JO14', 'JO15', 'JO16', 'JO17', 'JO19' ];

        $ages = [];
        foreach ( TeamGenerator::spreadAcrossLadder( $ladder, 3 ) as $group ) {
            $ages[] = preg_match( '/(\d+)/', $group, $m ) ? (int) $m[1] : 0;
        }

        $this->assertGreaterThanOrEqual(
            PlayerStatusModule::POTENTIAL_MIN_AGE,
            max( $ages ),
            'Without a squad at or above the floor, potential cannot be demonstrated at all.'
        );
    }

    public function test_the_spread_degrades_sensibly(): void {
        $ladder = [ 'JO7', 'JO9', 'JO13' ];

        $this->assertSame( [], TeamGenerator::spreadAcrossLadder( $ladder, 0 ) );
        $this->assertSame( [], TeamGenerator::spreadAcrossLadder( [], 3 ) );
        $this->assertSame( [ 'JO13' ], TeamGenerator::spreadAcrossLadder( $ladder, 1 ), 'One team means the oldest.' );
        $this->assertSame( $ladder, TeamGenerator::spreadAcrossLadder( $ladder, 3 ) );
        $this->assertSame( $ladder, TeamGenerator::spreadAcrossLadder( $ladder, 9 ), 'Asking for more than exist yields all of them.' );
    }

    // ── the generator ──────────────────────────────────────────────────

    /** @return array{0:int,1:int} [potential rows, behaviour rows] */
    private function generateFor( array $players ): array {
        global $wpdb;

        $before_pot = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_player_potential" );
        $before_beh = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_player_behaviour_ratings" );

        $actor = self::factory()->user->create( [ 'role' => 'administrator' ] );
        ( new PlayerStatusGenerator(
            new DemoBatchRegistry( 'test-batch-3242' ),
            $players,
            [ 'hod' => $actor ],
            8
        ) )->generate();

        return [
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_player_potential" ) - $before_pot,
            (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->p}tt_player_behaviour_ratings" ) - $before_beh,
        ];
    }

    /** @return object[] */
    private function squadAged( int $age, int $size ): array {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_teams", [
            'club_id' => $this->club, 'name' => "Squad U{$age}", 'age_group' => "JO{$age}",
        ] );
        $team_id = (int) $wpdb->insert_id;

        $out = [];
        for ( $i = 0; $i < $size; $i++ ) {
            $wpdb->insert( "{$this->p}tt_players", [
                'club_id'       => $this->club,
                'team_id'       => $team_id,
                'first_name'    => 'Demo',
                'last_name'     => "U{$age}-{$i}",
                'status'        => 'active',
                'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years -3 months" ) ),
            ] );
            $out[] = (object) [
                'id'            => (int) $wpdb->insert_id,
                'team_id'       => $team_id,
                'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years -3 months" ) ),
            ];
        }
        return $out;
    }

    /**
     * The floor is the product's, asked through `PlayerStatusModule` rather
     * than re-derived — so the demo cannot end up illustrating a rule the
     * product does not apply.
     */
    public function test_no_potential_is_seeded_below_the_age_floor(): void {
        [ $potential, $behaviour ] = $this->generateFor( $this->squadAged( 8, 14 ) );

        $this->assertSame( 0, $potential, 'A squad of eight-year-olds gets no potential at all.' );
        $this->assertGreaterThan(
            0,
            $behaviour,
            'Behaviour is unaffected at every age — how a child trains is a fair thing to record at eight.'
        );
    }

    public function test_a_squad_above_the_floor_gets_both(): void {
        [ $potential, $behaviour ] = $this->generateFor( $this->squadAged( 16, 14 ) );

        $this->assertGreaterThan( 0, $potential );
        $this->assertGreaterThan( 0, $behaviour );
    }

    /**
     * Potential is a history the club revises, not a label. Without more
     * than one entry per player the #3226 trajectory has nothing to draw.
     */
    public function test_potential_is_seeded_as_a_dated_history(): void {
        global $wpdb;
        $players = $this->squadAged( 16, 20 );
        $this->generateFor( $players );

        $ids  = implode( ',', array_map( static fn( $p ): int => (int) $p->id, $players ) );
        $with_history = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM ( SELECT player_id FROM {$this->p}tt_player_potential
               WHERE player_id IN ({$ids}) GROUP BY player_id HAVING COUNT(*) > 1 ) x"
        );

        $this->assertGreaterThan( 0, $with_history, 'At least some players carry more than one dated entry.' );

        $distinct_dates = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT DATE(set_at)) FROM {$this->p}tt_player_potential WHERE player_id IN ({$ids})"
        );
        $this->assertGreaterThan( 1, $distinct_dates, 'Entries are spread over time, not written on one afternoon.' );
    }

    /**
     * The case the trajectory exists to make visible. A demo where every
     * line only ever goes up teaches the wrong thing about what the band is
     * for, so one downward revision per squad is guaranteed rather than
     * left to the dice.
     */
    public function test_at_least_one_potential_is_revised_downward(): void {
        global $wpdb;
        $players = $this->squadAged( 16, 20 );
        $this->generateFor( $players );

        $ids  = implode( ',', array_map( static fn( $p ): int => (int) $p->id, $players ) );
        $rows = (array) $wpdb->get_results(
            "SELECT player_id, potential_band, set_at FROM {$this->p}tt_player_potential
              WHERE player_id IN ({$ids}) ORDER BY player_id, set_at"
        );

        // Best-first, so a LATER entry with a HIGHER index is a downgrade.
        $order = [ 'first_team', 'professional_elsewhere', 'semi_pro', 'top_amateur', 'recreational' ];
        $seen  = [];
        $down  = false;

        foreach ( $rows as $r ) {
            $pid   = (int) $r->player_id;
            $index = array_search( (string) $r->potential_band, $order, true );
            if ( $index === false ) continue;
            if ( isset( $seen[ $pid ] ) && $index > $seen[ $pid ] ) $down = true;
            $seen[ $pid ] = $index;
        }

        $this->assertTrue( $down, 'A demo academy where nobody is ever revised down is not an honest illustration.' );
    }

    /**
     * A demo where nothing is ever missing teaches the wrong thing about
     * what the product notices — #3225's alert would look like a feature
     * that never fires.
     */
    public function test_some_eligible_players_are_deliberately_left_without(): void {
        global $wpdb;
        $players = $this->squadAged( 16, 30 );
        $this->generateFor( $players );

        $ids     = implode( ',', array_map( static fn( $p ): int => (int) $p->id, $players ) );
        $covered = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT player_id) FROM {$this->p}tt_player_potential WHERE player_id IN ({$ids})"
        );

        $this->assertGreaterThan( 0, $covered, 'Most eligible players have one.' );
        $this->assertLessThan( count( $players ), $covered, 'And some deliberately do not.' );
    }

    // ── the manifest ───────────────────────────────────────────────────

    /**
     * Both tables must be wipeable. A generated entry that appears in no
     * cascade leaves rows the demo cleaner cannot reach, which is how a
     * "wipe and regenerate" leaves a doubled history behind.
     */
    public function test_both_tables_are_claimed_and_wipeable(): void {
        $manifest = DemoCoverage::MANIFEST;

        foreach ( [ 'tt_player_potential', 'tt_player_behaviour_ratings' ] as $table ) {
            $this->assertArrayHasKey( $table, $manifest, "{$table} is unclaimed by the demo manifest." );
            $this->assertSame( 'player_status', $manifest[ $table ]['category'] ?? null );
        }

        $cascade = DemoCoverage::CATEGORIES['player_status']['cascade'] ?? [];
        $this->assertContains( 'player_potential', $cascade );
        $this->assertContains( 'player_behaviour_rating', $cascade );
    }

    /**
     * `run_order` is a reproducibility contract: every dependent generator
     * draws from one seeded MT stream, so inserting rather than appending
     * changes every value downstream and the same (seed, preset) stops
     * reproducing.
     */
    public function test_the_new_category_appends_to_the_run_order(): void {
        $orders = [];
        foreach ( DemoCoverage::CATEGORIES as $key => $meta ) {
            if ( ( $meta['tier'] ?? '' ) !== 'dependent' ) continue;
            $orders[ $key ] = (int) ( $meta['run_order'] ?? 0 );
        }

        $this->assertArrayHasKey( 'player_status', $orders );
        $this->assertSame(
            max( $orders ),
            $orders['player_status'],
            'A new wave appends; inserting would change every generator that follows it.'
        );
    }
}
