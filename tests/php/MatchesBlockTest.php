<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Domain\Vocabularies\Enums\MatchExecutionState;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\MatchExecution\Repositories\MatchExecutionRepository;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;

/**
 * #3516 (epic #3513) — the monthly report's results section.
 *
 * The section is composed rather than computed: every figure comes from
 * `TeamMatchStatsQuery`, the same reader the statistics tab uses. So what is
 * worth pinning here is the composition — which options hide what, that the
 * defaults leave an existing saved report alone, and that the two statements
 * the section must make out loud are made.
 */
final class MatchesBlockTest extends WP_UnitTestCase {

    private const TEAM_ID     = 771;
    private const HALF_LENGTH = 35;
    private const STRIKER     = 31;

    private const FROM = '2026-02-01';
    private const TO   = '2026-02-28';

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $wpdb->hide_errors();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id' => 1, 'id' => self::STRIKER, 'team_id' => self::TEAM_ID,
            'first_name' => 'Sam', 'last_name' => 'Striker', 'jersey_number' => 9, 'status' => 'active',
        ] );
    }

    private function seedFixture(
        int $activity_id,
        string $date,
        ?int $home_score = null,
        ?int $away_score = null,
        string $type = 'game'
    ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => 1,
            'id'                => $activity_id,
            'team_id'           => self::TEAM_ID,
            'title'             => 'Fixture ' . $activity_id,
            'session_date'      => $date,
            'activity_type_key' => $type,
            'opponent'          => 'Ajax',
            'home_away'         => 'home',
            'home_score'        => $home_score,
            'away_score'        => $away_score,
        ] );
    }

    private function goal( int $activity_id, int $scorer ): void {
        $prep_id = ( new MatchPrepRepository() )->ensureForActivity( $activity_id, self::HALF_LENGTH );
        $repo    = new MatchExecutionRepository();
        $exec_id = $repo->ensureForActivity( $activity_id, $prep_id );
        $repo->update( $exec_id, [ 'state' => MatchExecutionState::PENDING_REVIEW ] );
        $repo->logGoalEvent( $exec_id, wp_generate_uuid4(), $scorer, 1, 10, 'home', null, false );
    }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function block( array $options = [] ): array {
        $report = ( new TeamMonthlyReport() )->forTeam(
            self::TEAM_ID,
            self::FROM,
            self::TO,
            [ TeamMonthlyReportBlock::MATCHES ],
            0,
            $options === [] ? [] : [ TeamMonthlyReportBlock::MATCHES => $options ]
        );

        return $report['data'][ TeamMonthlyReportBlock::MATCHES ];
    }

    // ---------------------------------------------------------------
    // The vocabulary
    // ---------------------------------------------------------------

    public function test_matches_is_a_block_and_prints_after_the_headline_numbers(): void {
        $order = TeamMonthlyReportBlock::ALL;

        $this->assertTrue( TeamMonthlyReportBlock::isValid( TeamMonthlyReportBlock::MATCHES ) );
        $this->assertGreaterThan(
            array_search( TeamMonthlyReportBlock::KPI, $order, true ),
            array_search( TeamMonthlyReportBlock::MATCHES, $order, true ),
            'the month in numbers, then how it went'
        );
    }

    public function test_an_existing_saved_composition_is_unchanged(): void {
        // A saved view carries an explicit block list, and this one predates
        // the results section. It must render exactly what it always did.
        $report = ( new TeamMonthlyReport() )->forTeam(
            self::TEAM_ID,
            self::FROM,
            self::TO,
            [ TeamMonthlyReportBlock::KPI, TeamMonthlyReportBlock::ROSTER ]
        );

        $this->assertArrayNotHasKey( TeamMonthlyReportBlock::MATCHES, $report['data'] );
        $this->assertNotContains( TeamMonthlyReportBlock::MATCHES, $report['blocks'] );
    }

    // ---------------------------------------------------------------
    // Counting
    // ---------------------------------------------------------------

    public function test_the_record_counts_only_matches_with_a_recorded_score(): void {
        $this->seedFixture( 7101, '2026-02-03', 3, 1 );
        $this->seedFixture( 7102, '2026-02-10' ); // played, never typed in

        $block = $this->block();

        $this->assertSame( 1, $block['record']['played'] );
        $this->assertSame( 1, $block['record']['won'] );
        $this->assertSame( 1, $block['record']['without_a_score'] );
        $this->assertSame( 0, $block['record']['drawn'], 'a missing result is not a draw' );
    }

    public function test_a_match_without_a_score_is_still_listed(): void {
        $this->seedFixture( 7110, '2026-02-10' );

        $block = $this->block();

        $this->assertCount( 1, $block['matches'] );
        $this->assertNull( $block['matches'][0]['team_score'] );
        $this->assertNull( $block['matches'][0]['outcome'] );
        $this->assertSame( 'Ajax', $block['matches'][0]['opponent'] );
    }

    public function test_tournaments_are_excluded_and_counted(): void {
        $this->seedFixture( 7120, '2026-02-03', 2, 1 );
        $this->seedFixture( 7121, '2026-02-17', 1, 0, 'tournament' );

        $block = $this->block();

        $this->assertSame( 1, $block['record']['played'] );
        $this->assertCount( 1, $block['matches'], 'a tournament is not one of the matches listed' );
        $this->assertSame( 1, $block['tournaments_excluded'], 'and the section can say so' );
    }

    public function test_every_match_row_carries_its_own_result(): void {
        $this->seedFixture( 7130, '2026-02-03', 2, 1 );

        $row = $this->block()['matches'][0];

        foreach ( [ 'session_date', 'opponent', 'home_away', 'team_score', 'opp_score', 'outcome' ] as $key ) {
            $this->assertArrayHasKey( $key, $row );
        }
        $this->assertSame( 'W', $row['outcome'] );
    }

    // ---------------------------------------------------------------
    // Options
    // ---------------------------------------------------------------

    public function test_the_defaults_show_the_record_and_scorers_but_not_the_squads(): void {
        $this->seedFixture( 7140, '2026-02-03', 2, 1 );

        $block = $this->block();

        $this->assertTrue( $block['show_record'] );
        $this->assertTrue( $block['show_scorers'] );
        $this->assertFalse( $block['show_squads'], 'the longest part, and it overlaps the minutes section' );
        $this->assertArrayNotHasKey( 'squad', $block['matches'][0] );
    }

    public function test_switching_the_record_off_leaves_the_matches(): void {
        $this->seedFixture( 7150, '2026-02-03', 2, 1 );

        $block = $this->block( [ MatchesBlockOptions::SHOW_RECORD => false ] );

        $this->assertArrayNotHasKey( 'record', $block );
        $this->assertCount( 1, $block['matches'], 'the match list is the section, not an extra' );
    }

    public function test_switching_the_squads_on_adds_them_to_each_match(): void {
        $this->seedFixture( 7160, '2026-02-03', 2, 1 );

        $block = $this->block( [ MatchesBlockOptions::SHOW_SQUADS => true ] );

        $this->assertTrue( $block['show_squads'] );
        $this->assertArrayHasKey( 'squad', $block['matches'][0] );
    }

    public function test_a_default_is_recorded_as_absence(): void {
        // Two compositions that render the same report have to compare equal,
        // because the composition hash is what matches a saved view to a
        // preset.
        $this->assertSame( [], MatchesBlockOptions::normalise( [ MatchesBlockOptions::SHOW_RECORD => true ] ) );
        $this->assertSame( [], MatchesBlockOptions::normalise( [ MatchesBlockOptions::SHOW_SQUADS => false ] ) );
        $this->assertSame(
            [ MatchesBlockOptions::SHOW_SQUADS => true ],
            MatchesBlockOptions::normalise( [ MatchesBlockOptions::SHOW_SQUADS => '1' ] )
        );
    }

    public function test_an_unrecognised_value_leaves_the_default_standing(): void {
        // A malformed request is not a request to hide the record.
        $this->assertTrue( MatchesBlockOptions::shows( [ MatchesBlockOptions::SHOW_RECORD => 'maybe' ], MatchesBlockOptions::SHOW_RECORD ) );
        $this->assertFalse( MatchesBlockOptions::shows( [ MatchesBlockOptions::SHOW_SQUADS => 'maybe' ], MatchesBlockOptions::SHOW_SQUADS ) );
    }

    public function test_an_unknown_option_key_is_reported(): void {
        $this->assertSame(
            [ 'show_everything' ],
            MatchesBlockOptions::unknownKeys( [ 'show_record' => true, 'show_everything' => true ] )
        );
    }

    // ---------------------------------------------------------------
    // Contributions
    // ---------------------------------------------------------------

    public function test_scorers_come_from_the_goals_already_recorded(): void {
        $this->seedFixture( 7170, '2026-02-03', 2, 1 );
        $this->goal( 7170, self::STRIKER );

        $block = $this->block();

        $this->assertNotEmpty( $block['scorers'] );
        $this->assertSame( self::STRIKER, $block['scorers'][0]['player_id'] );
        $this->assertSame( 1, $block['scorers'][0]['goals'] );
    }

    public function test_scorers_are_absent_when_the_section_is_told_not_to_show_them(): void {
        $this->seedFixture( 7180, '2026-02-03', 2, 1 );
        $this->goal( 7180, self::STRIKER );

        $block = $this->block( [ MatchesBlockOptions::SHOW_SCORERS => false ] );

        $this->assertArrayNotHasKey( 'scorers', $block );
    }

    // ---------------------------------------------------------------
    // The print ladder
    // ---------------------------------------------------------------

    public function test_the_one_pager_sheds_the_squads_before_anything_else(): void {
        $report = [ 'data' => [
            'matches' => [
                'show_record'  => true,
                'show_scorers' => true,
                'show_squads'  => true,
                'matches'      => array_fill( 0, 12, [ 'squad' => [] ] ),
            ],
        ] ];

        $degraded = TeamMonthlyReportLayout::degrade(
            $report,
            [ TeamMonthlyReportLayout::DROP_MATCH_SQUADS ]
        );

        $this->assertFalse( $degraded['data']['matches']['show_squads'] );
        $this->assertTrue( $degraded['data']['matches']['squads_degraded'] );
    }
}
