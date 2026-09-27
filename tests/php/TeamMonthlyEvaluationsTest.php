<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Teams\TeamKpisRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\TeamMonthlyEvaluations;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;

/**
 * #4134 (epic #4094) — the team monthly report's Evaluations section.
 *
 * Pinned: the derivations on a fixture computed by hand (category averages
 * and their change, coverage, movers, spread, the grid's cells and arrows);
 * the tone follows the configured scale; and, against the database, the
 * section's squad average is the "Squad rating" tile's for the same types,
 * and unticking a type takes its evaluations out of every figure.
 */
final class TeamMonthlyEvaluationsTest extends WP_UnitTestCase {

    private const SCALE = [ 'min' => 5, 'max' => 9, 'step' => 1 ];

    /**
     * Three players, two categories. In the window Ann rated 8 and 6 on one
     * evaluation, Bob 6 and 6 twice; Cas not at all. The month before, Ann
     * 7 / 6 and Bob 7 / 7. Their last evaluations before the window had
     * overall averages of 6,0 (Ann) and 7,0 (Bob).
     *
     * @return array<string,mixed>
     */
    private function fixture( string $level = 'summary' ): array {
        $eval = static fn( int $id, int $player, string $date, int $type, int $coach, array $mains ): array => [
            'id' => $id, 'player_id' => $player, 'date' => $date, 'type_id' => $type, 'coach_id' => $coach,
            'mains' => $mains, 'subs' => [], 'ratings' => array_values( $mains ),
        ];
        return [
            'level'    => $level,
            'sub'      => false,
            'scale'    => self::SCALE,
            'squad'    => [
                [ 'player_id' => 1, 'name' => 'Ann', 'jersey_number' => 1 ],
                [ 'player_id' => 2, 'name' => 'Bob', 'jersey_number' => 2 ],
                [ 'player_id' => 3, 'name' => 'Cas', 'jersey_number' => 3 ],
            ],
            'mains'    => [ [ 'id' => 10, 'label' => 'Technical' ], [ 'id' => 20, 'label' => 'Tactical' ], [ 'id' => 30, 'label' => 'Unused' ] ],
            'subs'     => [],
            'window'   => [
                $eval( 101, 1, '2026-08-10', 1, 50, [ 10 => 8.0, 20 => 6.0 ] ),
                $eval( 102, 2, '2026-08-12', 2, 51, [ 10 => 6.0, 20 => 6.0 ] ),
                $eval( 103, 2, '2026-08-24', 1, 50, [ 10 => 6.0, 20 => 6.0 ] ),
            ],
            'previous' => [
                $eval( 91, 1, '2026-07-10', 1, 50, [ 10 => 7.0, 20 => 6.0 ] ),
                $eval( 92, 2, '2026-07-12', 1, 50, [ 10 => 7.0, 20 => 7.0 ] ),
            ],
            'before'   => [ 1 => [ 6.0, 6.0 ], 2 => [ 7.0, 7.0 ] ],
            'squad_avg'          => 6.333333,
            'squad_avg_previous' => 6.75,
            'type_labels'        => [ 1 => 'Training', 2 => 'Match' ],
            'types'              => [],
        ];
    }

    public function test_the_category_averages_their_change_and_spread(): void {
        $e = TeamMonthlyEvaluations::derive( $this->fixture() );

        $this->assertCount( 2, $e['categories'], 'a category nobody was rated on prints no line' );
        [ $tech, $tact ] = $e['categories'];

        // Technical: 8, 6, 6 -> 6,7; last month 7, 7 -> 7,0.
        $this->assertSame( 6.7, $tech['avg'] );
        $this->assertSame( -0.3, $tech['delta'] );
        // Ann 8, Bob 6 -> the band runs 6 to 8, a half to all of the 5–9 scale.
        $this->assertSame( 6.0, $tech['min'] );
        $this->assertSame( 8.0, $tech['max'] );
        $this->assertSame( 25.0, $tech['band_from_pct'] );
        $this->assertSame( 75.0, $tech['band_to_pct'] );
        $this->assertFalse( $tech['wide'] );

        // Tactical: 6 throughout -> 6,0; last month 6, 7 -> 6,5.
        $this->assertSame( 6.0, $tact['avg'] );
        $this->assertSame( -0.5, $tact['delta'] );
    }

    public function test_coverage_names_who_was_not_evaluated(): void {
        $e = TeamMonthlyEvaluations::derive( $this->fixture() );

        $this->assertSame( 2, $e['evaluated'] );
        $this->assertSame( 3, $e['squad'] );
        $this->assertSame( [ [ 'player_id' => 3, 'name' => 'Cas' ] ], $e['missing'] );
        $this->assertSame( 3, $e['evaluations'] );
        $this->assertSame( 2, $e['coaches'] );
        $this->assertSame( [ [ 'type_id' => 1, 'label' => 'Training', 'count' => 2 ], [ 'type_id' => 2, 'label' => 'Match', 'count' => 1 ] ], $e['by_type'] );
    }

    public function test_movers_are_measured_against_their_own_previous_evaluation(): void {
        $e = TeamMonthlyEvaluations::derive( $this->fixture() );

        // Ann: 6,0 -> 7,0. Bob: 7,0 -> 6,0.
        $this->assertSame( [ [ 'player_id' => 1, 'name' => 'Ann', 'from' => 6.0, 'to' => 7.0, 'delta' => 1.0 ] ], $e['rising'] );
        $this->assertSame( [ [ 'player_id' => 2, 'name' => 'Bob', 'from' => 7.0, 'to' => 6.0, 'delta' => -1.0 ] ], $e['falling'] );
        $this->assertSame( 1, $e['rising_count'] );
        $this->assertSame( 1, $e['falling_count'] );
    }

    public function test_the_squad_average_is_the_one_it_was_given(): void {
        $e = TeamMonthlyEvaluations::derive( $this->fixture() );

        $this->assertSame( 6.3, $e['squad_avg'] );
        $this->assertSame( -0.5, $e['squad_avg_delta'], '6,3 against 6,8' );
    }

    public function test_the_summary_has_no_grid_and_details_does(): void {
        $this->assertArrayNotHasKey( 'grid', TeamMonthlyEvaluations::derive( $this->fixture() ) );

        $grid = TeamMonthlyEvaluations::derive( $this->fixture( 'details' ) )['grid']['rows'];
        $this->assertSame( [ 'Ann', 'Bob', 'Cas' ], array_column( $grid, 'name' ), 'shirt order' );

        // Ann's technical 8 against 7 the month before; tactical 6 against 6.
        $this->assertSame( 8.0, $grid[0]['cells'][0]['value'] );
        $this->assertSame( 'up', $grid[0]['cells'][0]['trend'] );
        $this->assertSame( 'flat', $grid[0]['cells'][1]['trend'] );
        $this->assertSame( 4, $grid[0]['cells'][0]['tone'] );
        $this->assertSame( 7.0, $grid[0]['avg'] );
        $this->assertSame( 1, $grid[0]['count'] );
        $this->assertSame( '2026-08-10', $grid[0]['last'] );

        // Bob's technical 6 against 7: down.
        $this->assertSame( 'down', $grid[1]['cells'][0]['trend'] );
        $this->assertSame( 2, $grid[1]['count'] );

        // Cas was not evaluated.
        $this->assertFalse( $grid[2]['evaluated'] );
        $this->assertNull( $grid[2]['cells'][0]['value'] );
    }

    /** The colour ramp spans the configured scale, whatever it is. */
    public function test_the_tone_follows_the_configured_scale(): void {
        $this->assertSame( 1, TeamMonthlyEvaluations::tone( 5.0, [ 'min' => 5.0, 'max' => 9.0, 'step' => 1.0 ] ) );
        $this->assertSame( 5, TeamMonthlyEvaluations::tone( 9.0, [ 'min' => 5.0, 'max' => 9.0, 'step' => 1.0 ] ) );
        $this->assertSame( 3, TeamMonthlyEvaluations::tone( 7.0, [ 'min' => 5.0, 'max' => 9.0, 'step' => 1.0 ] ) );
        $this->assertSame( 1, TeamMonthlyEvaluations::tone( 1.0, [ 'min' => 1.0, 'max' => 10.0, 'step' => 0.5 ] ) );
        $this->assertSame( 2, TeamMonthlyEvaluations::tone( 3.0, [ 'min' => 1.0, 'max' => 10.0, 'step' => 0.5 ] ) );
        $this->assertSame( 5, TeamMonthlyEvaluations::tone( 10.0, [ 'min' => 1.0, 'max' => 10.0, 'step' => 0.5 ] ) );
    }

    /** "Wide" when the players span the whole scale. */
    public function test_a_spread_over_the_whole_scale_is_flagged_wide(): void {
        $in                              = $this->fixture();
        $in['window'][0]['mains'][10]    = 9.0;
        $in['window'][1]['mains'][10]    = 5.0;
        $in['window'][2]['mains'][10]    = 5.0;
        $this->assertTrue( TeamMonthlyEvaluations::derive( $in )['categories'][0]['wide'] );
    }

    /** The one-pager and the landscape matrix print the summary; the pack prints the grid. */
    public function test_details_need_the_pack(): void {
        $this->assertTrue( TeamMonthlyReportLayout::supportsDetails( TeamMonthlyReportLayout::PACK, 'evaluations' ) );
        $this->assertFalse( TeamMonthlyReportLayout::supportsDetails( TeamMonthlyReportLayout::ONE_PAGER, 'evaluations' ) );
        $this->assertFalse( TeamMonthlyReportLayout::supportsDetails( TeamMonthlyReportLayout::MATRIX, 'evaluations' ) );

        $data = TeamMonthlyReportLayout::forLayout( [ 'evaluations' => TeamMonthlyEvaluations::derive( $this->fixture( 'details' ) ) ], TeamMonthlyReportLayout::ONE_PAGER );
        $this->assertSame( 'summary', $data['evaluations']['level'] );
        $this->assertArrayNotHasKey( 'grid', $data['evaluations'] );
    }

    /** The section renders its four summary parts, and the grid on the pack. */
    public function test_the_section_renders_on_paper(): void {
        $report = [
            'from'   => '2026-08-01',
            'to'     => '2026-08-31',
            'blocks' => [ 'letterhead', 'status', 'evaluations' ],
            'data'   => [
                'letterhead'  => [ 'squad_size' => 3, 'activity_count' => 8 ],
                'status'      => [ 'counts' => [ 'green' => 3 ] ],
                'evaluations' => TeamMonthlyEvaluations::derive( $this->fixture( 'details' ) ),
            ],
        ];
        $prepared = TeamMonthlyReportDocument::prepare( $report, 'B' );
        $html     = TeamMonthlyReportDocument::html( $prepared['report'], 'B', 'Eval U13', [], $prepared['fit']['groups'] );

        $this->assertStringContainsString( 'class="tstat ekpi"', $html );
        $this->assertStringContainsString( 'Missing: Cas', $html );
        $this->assertStringContainsString( 'class="ecat"', $html );
        $this->assertStringContainsString( 'Biggest risers', $html );
        $this->assertStringContainsString( 'class="egrid"', $html );
        $this->assertStringContainsString( 'not evaluated', $html );
        $this->assertLessThan( strpos( $html, '<!--tt-block:evaluations-->' ), strpos( $html, '<!--tt-block:status-->' ), 'right after the squad status' );

        $one = TeamMonthlyReportDocument::prepare( $report, 'A' );
        $this->assertStringNotContainsString( 'class="egrid"', TeamMonthlyReportDocument::html( $one['report'], 'A', 'Eval U13', [], $one['fit']['groups'] ), 'the one-pager prints the summary' );
    }

    /**
     * Against the database: the section's squad average is the tile's, for
     * every type and for one; unticking a type takes its evaluations out of
     * every figure; and the meta line says which types are counted.
     */
    public function test_the_squad_average_equals_the_tile_for_the_same_types(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Eval U13', 'age_group' => 'U13' ] );
        $team = (int) $wpdb->insert_id;
        $players = [];
        foreach ( [ 'Ann', 'Bob' ] as $i => $name ) {
            $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'team_id' => $team, 'first_name' => $name, 'last_name' => 'Test', 'status' => 'active', 'jersey_number' => $i + 1 ] );
            $players[] = (int) $wpdb->insert_id;
        }
        $types = [];
        foreach ( [ 'Zz training', 'Zz match' ] as $name ) {
            $wpdb->insert( "{$p}tt_lookups", [ 'club_id' => $club, 'lookup_type' => 'eval_type', 'name' => $name, 'sort_order' => 90 ] );
            $types[] = (int) $wpdb->insert_id;
        }
        $wpdb->insert( "{$p}tt_eval_categories", [ 'club_id' => $club, 'category_key' => 'zz_monthly_' . wp_rand( 1000, 9999 ), 'label' => 'Zz category', 'parent_id' => null, 'display_order' => 950, 'is_active' => 1 ] );
        $category = (int) $wpdb->insert_id;

        $rate = static function ( int $player, int $type, string $date, float $rating ) use ( $wpdb, $p, $club, $category ): void {
            $wpdb->insert( "{$p}tt_evaluations", [ 'club_id' => $club, 'player_id' => $player, 'coach_id' => 1, 'eval_type_id' => $type, 'eval_date' => $date, 'notes' => '' ] );
            $wpdb->insert( "{$p}tt_eval_ratings", [ 'club_id' => $club, 'evaluation_id' => (int) $wpdb->insert_id, 'category_id' => $category, 'rating' => $rating ] );
        };
        $rate( $players[0], $types[0], '2026-08-05', 8.0 );
        $rate( $players[1], $types[0], '2026-08-06', 6.0 );
        $rate( $players[1], $types[1], '2026-08-20', 5.0 );

        $kpis = new TeamKpisRepository();
        $all  = ( new TeamMonthlyReport() )->forTeam( $team, '2026-08-01', '2026-08-31', [ 'kpi', 'evaluations' ] );
        $this->assertSame( $all['data']['kpi']['squad_rating']['value'], $all['data']['evaluations']['squad_avg'], 'the section and the tile agree' );
        $this->assertSame( round( (float) $kpis->avgSquadRatingBetween( $team, '2026-08-01', '2026-08-31' ), 1 ), $all['data']['evaluations']['squad_avg'] );
        $this->assertSame( 3, $all['data']['evaluations']['evaluations'] );

        $training = ( new TeamMonthlyReport() )->forTeam( $team, '2026-08-01', '2026-08-31', [ 'evaluations' ], 0, [ 'evaluations' => [ 'types' => [ $types[0] ] ] ] );
        $e        = $training['data']['evaluations'];
        $this->assertSame( round( (float) $kpis->avgSquadRatingBetween( $team, '2026-08-01', '2026-08-31', [ $types[0] ] ), 1 ), $e['squad_avg'] );
        $this->assertSame( 7.0, $e['squad_avg'], 'the match evaluation is out' );
        $this->assertSame( 2, $e['evaluations'] );
        $this->assertSame( [ 'Zz training' ], $e['types_counted'] );
        $this->assertStringContainsString( 'Zz training', TeamMonthlyReport::sectionMeta( 'evaluations', $e ) );
    }
}
