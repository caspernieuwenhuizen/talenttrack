<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Analytics\Reports\LegacyPlayerReport;
use TT\Modules\Analytics\Reports\LegacyTeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\LegacyTeamMonthlyReport;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\TeamMatchRecord;
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Analytics\Reports\TestsBlockOptions;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;
use TT\Modules\Measurements\Units\UnitRegistry;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * #4590 — the block composers came out of the team monthly report and the
 * player report without changing a byte of either.
 *
 * The "before" is the code as it stood when the composers were extracted,
 * frozen under `fixtures/golden/`: `LegacyTeamMonthlyReport`,
 * `LegacyPlayerReport` and `LegacyTeamMatchStatsQuery`. Both versions compose
 * the same fixture in the same process, and their payloads are compared as
 * JSON, byte for byte, per block and whole; the HTML and the PDF data array
 * each renderer builds from them are compared the same way. Only
 * `generated_at` and the status block's `as_of` (`StatusVerdict`, the one
 * payload that carries that key) are levelled, because both come from the
 * clock and the two runs may fall either side of a second.
 *
 * A comparison of two empty payloads proves nothing, so every block is also
 * checked against the same block composed for a squad and a player with no
 * records at all: the fixture has to give each composer something to do.
 *
 * A deliberate change to a figure updates the frozen copy in the same PR, so
 * the change is visible in review as a change to the "before". The frozen
 * copies are deleted when #4572 merges; the per-block comparisons then move
 * to stored JSON snapshots.
 *
 * Dates are in 2020, so nothing depends on the runner's clock, apart from the
 * one fixture dated next week that pins the statistics tab's "date has
 * passed" rule.
 */
final class ReportComposersGoldenTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $admin = 0;
    private int $team  = 0;

    /** A test the squad took in the window. */
    private int $test_def = 0;

    /** A test the composition names but the squad did not take. */
    private int $untaken_def = 0;

    /** @var list<int> */
    private array $players = [];

    public function set_up(): void {
        parent::set_up();
        require_once __DIR__ . '/fixtures/golden/LegacyTeamMonthlyReport.php';
        require_once __DIR__ . '/fixtures/golden/LegacyPlayerReport.php';
        require_once __DIR__ . '/fixtures/golden/LegacyTeamMatchStatsQuery.php';

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin', 'display_name' => 'Golden Admin' ] );
        wp_set_current_user( $this->admin );
        ActivityRegisterProgress::forget();

        $this->seed();
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- team monthly report --------------------------------------------

    /** @return array<string,array{string}> */
    public function teamBlocks(): array {
        $out = [];
        foreach ( TeamMonthlyReportBlock::ALL as $block ) $out[ $block ] = [ $block ];
        return $out;
    }

    /** @dataProvider teamBlocks */
    public function test_each_team_block_is_unchanged( string $block ): void {
        $options = $this->teamOptions();
        $after   = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ $block ], $this->admin, $options );

        if ( $block !== TeamMonthlyReportBlock::NOTES ) {
            $empty = ( new TeamMonthlyReport() )->forTeam( $this->emptyTeam(), self::FROM, self::TO, [ $block ], $this->admin, $options );
            $this->assertNotSame(
                $this->json( $this->blockData( $empty, $block ) ),
                $this->json( $this->blockData( $after, $block ) ),
                "Precondition: the fixture gives the {$block} composer something to compose."
            );
        }

        $this->assertSame(
            $this->json( ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ $block ], $this->admin, $options ) ),
            $this->json( $after ),
            "the {$block} block composes the same bytes"
        );
    }

    public function test_the_whole_team_report_is_unchanged_with_options(): void {
        $options = $this->teamOptions() + [
            TeamMonthlyReportBlock::ATTENDANCE => [ 'level' => 'summary' ],
            TeamMonthlyReportBlock::MINUTES    => [ 'level' => 'summary' ],
        ];

        $changes = [];
        foreach ( [ 0, $this->admin ] as $viewer ) {
            $before = ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $viewer, $options );
            $after  = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $viewer, $options );
            $this->assertSame( $this->json( $before ), $this->json( $after ), "viewer {$viewer}" );
            $changes[ $viewer ] = array_column( $after['data']['changes']['events'], 'summary' );
        }

        // An unattended reader gets public and staff entries, never medical
        // or safeguarding ones: the branch the composer must keep.
        $this->assertContains( 'Staff-only: moved to centre back.', $changes[0] );
        $this->assertNotContains( 'Medical: ankle scan.', $changes[0] );
        $this->assertNotContains( 'Safeguarding: welfare check.', $changes[0] );
    }

    public function test_the_team_report_html_and_pdf_data_are_unchanged(): void {
        $before = ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin );
        $after  = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin );

        foreach ( [ TeamMonthlyReportLayout::ONE_PAGER, TeamMonthlyReportLayout::PACK, TeamMonthlyReportLayout::MATRIX ] as $layout ) {
            $prep_before = TeamMonthlyReportDocument::prepare( $this->level( $before ), $layout );
            $prep_after  = TeamMonthlyReportDocument::prepare( $this->level( $after ), $layout );

            $this->assertSame( $this->json( $prep_before ), $this->json( $prep_after ), "PDF data, layout {$layout}" );
            $this->assertSame(
                TeamMonthlyReportDocument::html( $prep_before['report'], $layout, 'Golden U14', [], $prep_before['fit']['groups'] ),
                TeamMonthlyReportDocument::html( $prep_after['report'], $layout, 'Golden U14', [], $prep_after['fit']['groups'] ),
                "HTML, layout {$layout}"
            );
        }
    }

    /** No composer reads more than the report did before it was split. */
    public function test_the_team_report_issues_no_more_queries(): void {
        ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin );

        $before = $this->countQueries( fn() => ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin ) );
        $after  = $this->countQueries( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin ) );

        $this->assertGreaterThan( 0, $after, 'Precondition: the report reads something.' );
        $this->assertLessThanOrEqual( $before, $after );
    }

    // ---- player report --------------------------------------------------

    /** @return array<string,array{string}> */
    public function playerBlocks(): array {
        $out = [];
        foreach ( PlayerReportBlock::ALL as $block ) $out[ $block ] = [ $block ];
        return $out;
    }

    /** @dataProvider playerBlocks */
    public function test_each_player_block_is_unchanged( string $block ): void {
        if ( $block !== PlayerReportBlock::NOTES ) {
            $empty = ( new PlayerReport() )->forPlayer( $this->emptyPlayer(), self::FROM, self::TO, [ $block ], $this->admin, PlayerReportAudience::INTERNAL );
            $after = ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, [ $block ], $this->admin, PlayerReportAudience::INTERNAL );
            $this->assertNotSame(
                $this->json( $this->blockData( $empty, $block ) ),
                $this->json( $this->blockData( $after, $block ) ),
                "Precondition: the fixture gives the {$block} composer something to compose."
            );
        }

        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
            $this->assertSame(
                $this->json( ( new LegacyPlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, [ $block ], $this->admin, $audience ) ),
                $this->json( ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, [ $block ], $this->admin, $audience ) ),
                "the {$block} block composes the same bytes for {$audience}"
            );
        }
    }

    public function test_the_whole_player_report_and_its_html_are_unchanged(): void {
        $options = [ PlayerReportBlock::RATINGS => [ 'detail' => 'sub' ] ];

        foreach ( $this->players as $player ) {
            foreach ( [ null, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
                $before = ( new LegacyPlayerReport() )->forPlayer( $player, self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin, $audience, $options );
                $after  = ( new PlayerReport() )->forPlayer( $player, self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin, $audience, $options );

                $this->assertNotNull( $after );
                $this->assertSame( $this->json( $before ), $this->json( $after ), "player {$player}" );
                $this->assertSame(
                    PlayerReportPdfDocument::html( $this->level( (array) $before ), [], $audience === PlayerReportAudience::FAMILY ),
                    PlayerReportPdfDocument::html( $this->level( (array) $after ), [], $audience === PlayerReportAudience::FAMILY ),
                    "player {$player} HTML"
                );
            }
        }
    }

    public function test_a_player_outside_the_club_is_still_null(): void {
        $this->assertNull( ( new PlayerReport() )->forPlayer( 99999999, self::FROM, self::TO, [], $this->admin ) );
    }

    public function test_the_player_report_issues_no_more_queries(): void {
        ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin );

        $before = $this->countQueries( fn() => ( new LegacyPlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin ) );
        $after  = $this->countQueries( fn() => ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin ) );

        $this->assertGreaterThan( 0, $after );
        $this->assertLessThanOrEqual( $before, $after );
    }

    // ---- one match record -----------------------------------------------

    public function test_the_team_statistics_are_unchanged(): void {
        $filters = [ 'from' => self::FROM, 'to' => self::TO ];
        $this->assertSame(
            $this->json( ( new LegacyTeamMatchStatsQuery() )->forTeam( $this->team, $filters ) ),
            $this->json( ( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters ) )
        );
    }

    /**
     * The monthly report's record and the statistics tab's record are one
     * read. They word "played" differently — the report counts every
     * fixture, the tab only the scored ones — and agree on everything else.
     */
    public function test_the_two_match_records_agree(): void {
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'matches' ] )['data']['matches']['record'];
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team, [ 'from' => self::FROM, 'to' => self::TO ] )['record'];

        foreach ( [ 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference' ] as $key ) {
            $this->assertSame( $monthly[ $key ], $tab[ $key ], $key );
        }
        $this->assertSame( $monthly['without_score'], $tab['without_a_score'] );
        $this->assertSame( $monthly['played'], $tab['played'] + $tab['without_a_score'] );

        // Precondition: the fixture has wins, a loss, a draw and a gap.
        $this->assertSame( [ 2, 1, 1, 1 ], [ $tab['won'], $tab['lost'], $tab['drawn'], $tab['without_a_score'] ] );
        $this->assertSame( 1, $tab['clean_sheets'] );
        $this->assertSame( 1, $tab['tournaments_excluded'] );
    }

    /**
     * The journey and the thread notes are read through the reader's
     * visibility. A staff reader and a reader with no clearance get
     * different payloads, and each is unchanged.
     */
    public function test_the_player_report_is_unchanged_for_each_reader(): void {
        $seen = [];
        foreach ( [ $this->admin, 0 ] as $viewer ) {
            wp_set_current_user( $viewer );
            $before = ( new LegacyPlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $viewer, PlayerReportAudience::INTERNAL );
            $after  = ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $viewer, PlayerReportAudience::INTERNAL );
            $this->assertNotNull( $after );
            $this->assertSame( $this->json( $before ), $this->json( $after ), "viewer {$viewer}" );
            $seen[ $viewer ] = [
                'journey' => array_column( $after['data']['journey']['items'], 'summary' ),
                'notes'   => array_column( $after['data']['thread_notes']['items'], 'body' ),
            ];
        }
        wp_set_current_user( $this->admin );

        $this->assertContains( 'Staff-only: moved to centre back.', $seen[ $this->admin ]['journey'] );
        $this->assertNotContains( 'Staff-only: moved to centre back.', $seen[0]['journey'] );
        $this->assertContains( 'Moved to centre back.', $seen[0]['journey'], 'Precondition: the public entry reaches every reader.' );
        $this->assertContains( 'Golden staff-only note.', $seen[ $this->admin ]['notes'] );
        $this->assertNotContains( 'Golden staff-only note.', $seen[0]['notes'] );
    }

    /**
     * The tab counts fixtures whose date has passed. A fixture next week is
     * not a match missing its result, so it stays out of the record and the
     * form, while the monthly report's read still returns it.
     */
    public function test_the_tab_leaves_out_fixtures_still_to_come(): void {
        $next_week = gmdate( 'Y-m-d', time() + 7 * DAY_IN_SECONDS );
        $future    = $this->activity( $next_week, 'Volgende week', 'game', 'planned', [ 'opponent' => 'Paars', 'home_away' => 'home' ] );
        $filters   = [ 'from' => '2020-01-01', 'to' => gmdate( 'Y-m-d', time() + 30 * DAY_IN_SECONDS ) ];

        $this->assertSame(
            $this->json( ( new LegacyTeamMatchStatsQuery() )->forTeam( $this->team, $filters ) ),
            $this->json( ( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters ) )
        );

        $record = ( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters )['record'];
        $this->assertSame( 1, $record['without_a_score'], 'Only the 2020 match without a score; next week is not missing one.' );

        $all    = array_column( TeamMatchRecord::fixtures( $this->team, $filters['from'], $filters['to'] ), 'activity_id' );
        $played = array_column( TeamMatchRecord::fixtures( $this->team, $filters['from'], $filters['to'], true ), 'activity_id' );
        $this->assertContains( $future, $all );
        $this->assertNotContains( $future, $played );
        $this->assertCount( count( $all ) - 1, $played );
    }

    // ---- helpers --------------------------------------------------------

    /**
     * The payload as JSON. An encoding failure fails the test rather than
     * comparing two empty strings.
     *
     * @param array<string,mixed>|null $payload
     */
    private function json( ?array $payload ): string {
        $json = wp_json_encode( $this->level( $payload ) );
        $this->assertIsString( $json, 'The payload must encode.' );
        $this->assertNotSame( '', $json );
        return $json;
    }

    /**
     * One block's data, so the letterhead every report carries does not make
     * two different subjects look different.
     *
     * @param array<string,mixed>|null $report
     * @return array<string,mixed>|null
     */
    private function blockData( ?array $report, string $block ): ?array {
        $this->assertNotNull( $report );
        $this->assertArrayHasKey( $block, $report['data'] );
        return [ $block => $report['data'][ $block ] ];
    }

    /**
     * Options that make the tests block read its rounds in full, one test the
     * squad took and one it did not.
     *
     * @return array<string,array<string,mixed>>
     */
    private function teamOptions(): array {
        return [
            TeamMonthlyReportBlock::TESTS   => [ 'definitions' => [ $this->test_def, $this->untaken_def ], 'show' => TestsBlockOptions::SHOW_VALUES_TREND ],
            TeamMonthlyReportBlock::MATCHES => [ 'level' => 'details', 'record' => true, 'scorers' => true, 'squads' => true ],
        ];
    }

    /** A team in the same club with nobody on it and nothing recorded. */
    private function emptyTeam(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Empty U14', 'age_group' => 'U14' ] );
        return (int) $wpdb->insert_id;
    }

    /** A player on an empty team with nothing recorded. */
    private function emptyPlayer(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => CurrentClub::id(),
            'team_id'    => $this->emptyTeam(),
            'first_name' => 'Leeg',
            'last_name'  => 'Speler',
            'status'     => 'active',
            'wp_user_id' => null,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The payload with every `generated_at`, and the status block's `as_of`,
     * set to one value. Both are read from the clock, so two compositions
     * either side of a second would differ by it.
     *
     * @param mixed $payload
     * @return mixed
     */
    private function level( $payload ) {
        if ( ! is_array( $payload ) ) return $payload;
        foreach ( $payload as $key => $value ) {
            if ( $key === 'generated_at' || $key === 'as_of' ) {
                $payload[ $key ] = '2020-04-01 00:00:00';
                continue;
            }
            $payload[ $key ] = $this->level( $value );
        }
        return $payload;
    }

    private function countQueries( callable $fn ): int {
        ActivityRegisterProgress::forget();
        wp_cache_flush();

        $n      = 0;
        $filter = static function ( $sql ) use ( &$n ) {
            $n++;
            return $sql;
        };
        add_filter( 'query', $filter );
        try {
            $fn();
        } finally {
            remove_filter( 'query', $filter );
        }
        return $n;
    }

    /**
     * One squad over February and March 2020: trainings with registers, one
     * closed without a register, one never closed, one cancelled; a home win,
     * an away loss, a goalless draw, a match without a score and one without
     * an opponent; a tournament; minutes, evaluations, a goal, an injury, a
     * journey event and a thread note.
     */
    private function seed(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = CurrentClub::id();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Golden U14', 'age_group' => 'U14' ] );
        $this->team = (int) $wpdb->insert_id;

        $squad = [
            [ 'Anna', 'Akker', 7, '["CB"]' ],
            [ 'Bram', 'Bos', 3, '["CB","RB"]' ],
            [ 'Cas', 'Dijk', 10, '["ST"]' ],
            [ 'Daan', 'Eik', null, '' ],
        ];
        foreach ( $squad as [ $first, $last, $jersey, $positions ] ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id'             => $club,
                'team_id'             => $this->team,
                'first_name'          => $first,
                'last_name'           => $last,
                'jersey_number'       => $jersey,
                'preferred_positions' => $positions,
                'status'              => 'active',
                'date_of_birth'       => '2006-04-12',
                'wp_user_id'          => null,
            ] );
            $this->players[] = (int) $wpdb->insert_id;
        }

        $feb = $this->activity( '2020-02-11', 'Training februari', 'training' );
        foreach ( $this->players as $pid ) $this->attend( $feb, $pid, 'Present' );

        $statuses = [
            [ 'Present', 'Present', 'Absent', 'Late' ],
            [ 'Present', 'Excused', 'Present', 'Present' ],
            [ 'Absent', 'Present', 'Present', 'Absent' ],
        ];
        foreach ( [ '2020-03-03', '2020-03-10', '2020-03-17' ] as $i => $date ) {
            $id = $this->activity( $date, 'Training ' . $date, 'training' );
            foreach ( $this->players as $j => $pid ) $this->attend( $id, $pid, $statuses[ $i ][ $j ] );
        }
        $this->activity( '2020-03-24', 'Training zonder lijst', 'training' );
        $this->activity( '2020-03-26', 'Nooit afgesloten', 'training', 'planned' );
        $this->activity( '2020-03-27', 'Afgelast', 'training', 'cancelled' );

        $fixtures = [
            [ '2020-03-07', 'Thuis tegen Rood', 'Rood', 'home', 3, 0 ],
            [ '2020-03-14', 'Uit bij Blauw', 'Blauw', 'away', 0, 2 ],
            [ '2020-03-21', 'Thuis tegen Groen', 'Groen', 'home', 1, 1 ],
            [ '2020-03-28', 'Uit bij Geel', 'Geel', 'away', null, null ],
            [ '2020-03-29', 'Oefenwedstrijd', '', 'home', 2, 1 ],
        ];
        foreach ( $fixtures as $k => [ $date, $title, $opponent, $venue, $ours, $theirs ] ) {
            $id = $this->activity( $date, $title, 'game', 'completed', [
                'opponent'   => $opponent,
                'home_away'  => $venue,
                'home_score' => $ours,
                'away_score' => $theirs,
            ] );
            foreach ( $this->players as $j => $pid ) {
                $this->attend( $id, $pid, 'Present', $ours === null ? null : ( $j === 3 ? 0 : 60 - 10 * ( ( $j + $k ) % 3 ) ) );
            }
        }
        $this->activity( '2020-03-15', 'Toernooi', 'tournament' );

        // A main category with a subcategory under it, so the ratings block's
        // main and sub arithmetic both run.
        $main = $this->category( 'Golden technical', null, 10 );
        $sub  = $this->category( 'Golden passing', $main, 11 );
        $evaluations = [
            [ 0, '2020-03-12', 7.5, [ $main => 7.0, $sub => 8.0 ] ],
            [ 1, '2020-03-19', 6.0, [ $main => 6.0 ] ],
            [ 0, '2020-02-20', 6.5, [ $main => 6.5, $sub => 6.0 ] ],
            [ 0, '2020-03-25', 8.0, [ $sub => 9.0 ] ],
            [ 3, '2020-03-20', 2.0, [ $main => 1.0, $sub => 1.5 ] ],
        ];
        foreach ( $evaluations as [ $who, $date, $rating, $ratings ] ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id'   => $club,
                'player_id' => $this->players[ $who ],
                'coach_id'  => $this->admin,
                'eval_date' => $date,
                'rating'    => $rating,
                'notes'     => 'Golden evaluation.',
            ] );
            $eval_id = (int) $wpdb->insert_id;
            foreach ( $ratings as $category_id => $value ) {
                $wpdb->insert( "{$p}tt_eval_ratings", [
                    'club_id'       => $club,
                    'evaluation_id' => $eval_id,
                    'category_id'   => $category_id,
                    'rating'        => $value,
                ] );
            }
        }

        $this->seedTests();

        // Behaviour and potential for the player report, and a weak picture
        // for Daan so the squad has somebody on the agenda.
        $behaviour = new PlayerBehaviourRatingsRepository();
        $behaviour->create( [ 'player_id' => $this->players[0], 'rated_at' => '2020-03-10 10:00:00', 'rated_by' => $this->admin, 'rating' => 8.0, 'context' => 'training', 'notes' => 'Leads the warm-up.' ] );
        $behaviour->create( [ 'player_id' => $this->players[0], 'rated_at' => '2020-03-24 10:00:00', 'rated_by' => $this->admin, 'rating' => 7.0, 'context' => 'match' ] );
        $behaviour->create( [ 'player_id' => $this->players[3], 'rated_at' => '2020-03-21 10:00:00', 'rated_by' => $this->admin, 'rating' => 1.0, 'context' => 'match' ] );
        $potential = new PlayerPotentialRepository();
        $potential->create( [ 'player_id' => $this->players[0], 'set_at' => '2020-03-15 10:00:00', 'set_by' => $this->admin, 'potential_band' => 'ahead', 'notes' => 'Reads the game early.' ] );
        $potential->create( [ 'player_id' => $this->players[3], 'set_at' => '2020-03-15 10:00:00', 'set_by' => $this->admin, 'potential_band' => 'below_level' ] );

        // A PDP file with one conversation, so the PDP block has a file.
        $wpdb->insert( "{$p}tt_seasons", [ 'club_id' => $club, 'name' => '2019/20', 'start_date' => '2019-07-01', 'end_date' => '2020-06-30', 'is_current' => 0 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => $club, 'player_id' => $this->players[0], 'season_id' => $season, 'status' => 'open' ] );
        $file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'      => $club,
            'pdp_file_id'  => $file,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2020-03-09 10:00:00',
        ] );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id'    => $club,
            'player_id'  => $this->players[0],
            'title'      => 'Win more headers',
            'status'     => 'in_progress',
            'created_by' => $this->admin,
            'created_at' => '2020-03-05 09:00:00',
            'updated_at' => '2020-03-05 09:00:00',
        ] );

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id'       => $club,
            'player_id'     => $this->players[1],
            'started_on'    => '2020-03-18',
            'actual_return' => null,
            'notes'         => 'Ankle.',
        ] );
        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id'       => $club,
            'player_id'     => $this->players[0],
            'started_on'    => '2020-03-02',
            'actual_return' => '2020-03-09',
            'notes'         => 'Hamstring.',
        ] );

        // One journey entry per visibility rung, so every reader's set is
        // a different set.
        $events = [
            [ 0, 'position_changed', '2020-03-20 09:00:00', 'Moved to centre back.', 'public' ],
            [ 0, 'position_changed', '2020-03-21 09:00:00', 'Staff-only: moved to centre back.', 'coaching_staff' ],
            [ 1, 'injury_started', '2020-03-18 09:00:00', 'Medical: ankle scan.', 'medical' ],
            [ 2, 'team_changed', '2020-03-22 09:00:00', 'Safeguarding: welfare check.', 'safeguarding' ],
        ];
        foreach ( $events as $i => [ $who, $type, $when, $summary, $visibility ] ) {
            $wpdb->insert( "{$p}tt_player_events", [
                'club_id'            => $club,
                'uuid'               => wp_generate_uuid4(),
                'player_id'          => $this->players[ $who ],
                'event_type'         => $type,
                'event_date'         => $when,
                'summary'            => $summary,
                'visibility'         => $visibility,
                'source_module'      => 'tests',
                'source_entity_type' => 'test_event',
                'source_entity_id'   => $i + 1,
            ] );
        }

        foreach ( [ [ 'Golden note.', 'public', '2020-03-15 09:00:00' ], [ 'Golden staff-only note.', 'private_to_coach', '2020-03-16 09:00:00' ] ] as [ $body, $visibility, $when ] ) {
            $wpdb->insert( "{$p}tt_thread_messages", [
                'club_id'        => $club,
                'uuid'           => wp_generate_uuid4(),
                'thread_type'    => 'player',
                'thread_id'      => $this->players[0],
                'author_user_id' => $this->admin,
                'body'           => $body,
                'visibility'     => $visibility,
                'created_at'     => $when,
            ] );
        }
    }

    private function category( string $label, ?int $parent_id, int $order ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => CurrentClub::id(),
            'category_key'  => 'zz_golden_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => $parent_id,
            'display_order' => $order,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * A timed test with an age-group target, taken by the squad in February
     * and March, and a second test nobody took. The team's tests block and
     * the player's tests block both read them.
     */
    private function seedTests(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $club    = CurrentClub::id();
        $minutes = ( new UnitRegistry() )->bySymbol( 'min' );
        $this->assertNotNull( $minutes, 'The unit registry should carry minutes.' );

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $club, 'category_id' => 0, 'name' => 'Golden laps', 'value_type' => 'numeric',
            'unit' => 'min', 'dimension' => $minutes->dimension, 'entry_unit_id' => (int) $minutes->id,
            'numeric_format' => 'duration', 'direction' => 'lower', 'is_active' => 1,
            'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $this->test_def = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $club, 'category_id' => 0, 'name' => 'Golden jump', 'value_type' => 'numeric',
            'unit' => 'cm', 'direction' => 'higher', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $this->untaken_def = (int) $wpdb->insert_id;

        // Green up to 12:30, amber up to 13:30, in canonical seconds.
        $wpdb->insert( "{$p}tt_measurement_targets", [
            'club_id' => $club, 'definition_id' => $this->test_def, 'age_group' => 'U14',
            'green_max' => 750.0, 'amber_max' => 810.0,
        ] );

        $rounds = [
            '2020-02-12' => [ 720.0, 900.0, 780.0, 840.0 ],
            '2020-03-11' => [ 700.0, 940.0, 760.0, 800.0 ],
        ];
        foreach ( $rounds as $date => $values ) {
            $wpdb->insert( "{$p}tt_measurement_sessions", [
                'club_id' => $club, 'uuid' => wp_generate_uuid4(), 'definition_id' => $this->test_def,
                'team_id' => $this->team, 'planned_date' => $date, 'status' => 'completed',
            ] );
            foreach ( $this->players as $i => $player_id ) {
                $wpdb->insert( "{$p}tt_measurement_results", [
                    'club_id' => $club, 'definition_id' => $this->test_def, 'player_id' => $player_id,
                    'recorded_date' => $date, 'value_numeric' => $values[ $i ],
                ] );
            }
        }
    }

    /** @param array<string,mixed> $extra */
    private function activity( string $date, string $title, string $type, string $status = 'completed', array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", $extra + [
            'club_id'             => CurrentClub::id(),
            'team_id'             => $this->team,
            'title'               => $title,
            'session_date'        => $date,
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $status === 'cancelled' ? 'cancelled' : 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( int $activity_id, int $player_id, string $status, ?int $minutes = null ): void {
        global $wpdb;
        $row = [
            'club_id'     => CurrentClub::id(),
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => $status,
            'record_type' => 'actual',
            'is_guest'    => 0,
        ];
        if ( $minutes !== null ) $row['minutes_played'] = $minutes;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", $row );
    }
}
