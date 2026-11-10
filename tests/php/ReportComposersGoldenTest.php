<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
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
use TT\Tests\Php\Support\GoldenSnapshot;

/**
 * #4590 — the block composers came out of the team monthly report and the
 * player report without changing a byte of either.
 *
 * The "before" is stored data, not code (#4958): snapshots under
 * `fixtures/golden/snapshots/`, recorded from the frozen copies of the
 * readers as they stood when the composers were extracted
 * (`LegacyTeamMonthlyReport`, `LegacyPlayerReport`,
 * `LegacyTeamMatchStatsQuery`, deleted with that recording). Each payload,
 * per block and whole, and the HTML and the PDF data array each renderer
 * builds from it, is compared with its snapshot byte for byte, after
 * `GoldenSnapshot` replaces the ids the fixture made with stable tokens.
 * Only `generated_at` and the status block's `as_of` (`StatusVerdict`, the
 * one payload that carries that key) are levelled, because both come from
 * the clock.
 *
 * A comparison of two empty payloads proves nothing, so every block is also
 * checked against the same block composed for a squad and a player with no
 * records at all: the fixture has to give each composer something to do.
 *
 * A deliberate change to a figure records the snapshots again in the same
 * PR (`TT_GOLDEN_RECORD=1`, see docs/contributing.md), so the change shows
 * in review as a diff of the stored "before". `TT_GOLDEN_SHIFT=N` runs the
 * suite with every id moved on, as a second fresh database would.
 *
 * Dates are in 2020, so nothing depends on the runner's clock, apart from the
 * one fixture dated next week that pins the statistics tab's "date has
 * passed" rule.
 */
final class ReportComposersGoldenTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    /**
     * The most queries each report may issue on this fixture: the count of
     * the frozen copies (`LegacyTeamMonthlyReport`, `LegacyPlayerReport`),
     * recorded once on 10 November 2026 at the commit that deleted them
     * (#4958), so the report as it read before its composers were split
     * out. Lowering a number is fine; raising one needs a reason in review.
     */
    private const TEAM_QUERY_BASELINE   = 191;
    private const PLAYER_QUERY_BASELINE = 76;

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
        GoldenSnapshot::reset();
        $this->shiftIds();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->admin = GoldenSnapshot::id( 'user', (int) self::factory()->user->create( [ 'role' => 'tt_club_admin', 'display_name' => 'Golden Admin' ] ) );
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

        $this->golden( 'team-block-' . $block, $after, "the {$block} block composes the same bytes" );
    }

    public function test_the_whole_team_report_is_unchanged_with_options(): void {
        $options = $this->teamOptions() + [
            TeamMonthlyReportBlock::ATTENDANCE => [ 'level' => 'summary' ],
            TeamMonthlyReportBlock::MINUTES    => [ 'level' => 'summary' ],
        ];

        $changes = [];
        foreach ( [ 0 => 'unattended', $this->admin => 'admin' ] as $viewer => $who ) {
            $after = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $viewer, $options );
            // #4949 — a reader without the attendance right (here the
            // unattended viewer 0) gets the report without its attendance
            // section and attendance tile. The administrator reads attendance.
            if ( ! \TT\Infrastructure\Security\AuthorizationService::canReadTeamAttendance( $viewer, $this->team ) ) {
                $this->assertNotContains( TeamMonthlyReportBlock::ATTENDANCE, $after['blocks'] );
                $this->assertArrayNotHasKey( TeamMonthlyReportBlock::ATTENDANCE, $after['data'] );
                // No attendance figure anywhere: not the tile, not per player
                // in the roster or the attention list.
                $this->assertSame( [], self::attendanceKeys( $after['data'] ), "viewer {$viewer}" );
            }
            $this->golden( 'team-whole-' . $who, $after, "viewer {$who}" );
            $changes[ $viewer ] = array_column( $after['data']['changes']['events'], 'summary' );
        }

        // An unattended reader gets public and staff entries, never medical
        // or safeguarding ones: the branch the composer must keep.
        $this->assertContains( 'Staff-only: moved to centre back.', $changes[0] );
        $this->assertNotContains( 'Medical: ankle scan.', $changes[0] );
        $this->assertNotContains( 'Safeguarding: welfare check.', $changes[0] );
    }

    public function test_the_team_report_html_and_pdf_data_are_unchanged(): void {
        $after = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin );

        foreach ( [ TeamMonthlyReportLayout::ONE_PAGER, TeamMonthlyReportLayout::PACK, TeamMonthlyReportLayout::MATRIX ] as $layout ) {
            $prep = TeamMonthlyReportDocument::prepare( $this->level( $after ), $layout );

            $this->golden( 'team-pdf-data-' . $layout, $prep, "PDF data, layout {$layout}" );
            $this->golden(
                'team-html-' . $layout,
                TeamMonthlyReportDocument::html( $prep['report'], $layout, 'Golden U14', [], $prep['fit']['groups'] ),
                "HTML, layout {$layout}"
            );
        }
    }

    /** No composer reads more than the report did before it was split. */
    public function test_the_team_report_issues_no_more_queries(): void {
        ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin );

        $after = $this->countQueries( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->admin ) );

        $this->assertGreaterThan( 0, $after, 'Precondition: the report reads something.' );
        $this->assertLessThanOrEqual( self::TEAM_QUERY_BASELINE, $after );
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

        $audiences = [ PlayerReportAudience::INTERNAL, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ];
        $after     = [];
        foreach ( $audiences as $audience ) {
            $after[ $audience ] = ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, [ $block ], $this->admin, $audience );
        }
        $this->golden( 'player-block-' . $block, $after, "the {$block} block composes the same bytes for every audience" );
    }

    public function test_the_whole_player_report_and_its_html_are_unchanged(): void {
        $options = [ PlayerReportBlock::RATINGS => [ 'detail' => 'sub' ] ];

        foreach ( $this->players as $i => $player ) {
            foreach ( [ null, PlayerReportAudience::SCOUT, PlayerReportAudience::FAMILY ] as $audience ) {
                $name  = 'player-whole-' . ( $i + 1 ) . '-' . ( $audience ?? 'reader' );
                $after = ( new PlayerReport() )->forPlayer( $player, self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin, $audience, $options );

                $this->assertNotNull( $after );
                $this->golden( $name, $after, "player {$player}" );
                $this->golden(
                    $name,
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

        $after = $this->countQueries( fn() => ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $this->admin ) );

        $this->assertGreaterThan( 0, $after );
        $this->assertLessThanOrEqual( self::PLAYER_QUERY_BASELINE, $after );
    }

    // ---- one match record -----------------------------------------------

    public function test_the_team_statistics_are_unchanged(): void {
        $filters = [ 'from' => self::FROM, 'to' => self::TO ];
        $this->golden( 'team-statistics', ( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters ) );
    }

    /**
     * The monthly report's record and the statistics tab's record are one
     * read, and since #4572 "Played" has one meaning on both: the reports'
     * played-match rule. They agree on every figure.
     */
    public function test_the_two_match_records_agree(): void {
        $monthly = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ 'matches' ] )['data']['matches']['record'];
        $tab     = ( new TeamMatchStatsQuery() )->forTeam( $this->team, [ 'from' => self::FROM, 'to' => self::TO ] )['record'];

        foreach ( [ 'played', 'won', 'drawn', 'lost', 'goals_for', 'goals_against', 'goal_difference' ] as $key ) {
            $this->assertSame( $monthly[ $key ], $tab[ $key ], $key );
        }
        $this->assertSame( $monthly['without_score'], $tab['without_a_score'] );
        $this->assertSame( $tab['won'] + $tab['drawn'] + $tab['lost'] + $tab['without_a_score'], $tab['played'], 'every 2020 fixture was played' );

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
        foreach ( [ $this->admin => 'admin', 0 => 'unattended' ] as $viewer => $who ) {
            wp_set_current_user( $viewer );
            $after = ( new PlayerReport() )->forPlayer( $this->players[0], self::FROM, self::TO, PlayerReportBlock::ALL, $viewer, PlayerReportAudience::INTERNAL );
            $this->assertNotNull( $after );
            $this->golden( 'player-reader-' . $who, $after, "viewer {$who}" );
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

        $this->golden( 'team-statistics-to-come', ( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters ) );

        $record =( new TeamMatchStatsQuery() )->forTeam( $this->team, $filters )['record'];
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
    /**
     * #4949 — every path in the report data that ends in an attendance
     * figure.
     *
     * @param array<mixed> $data
     * @return list<string>
     */
    private static function attendanceKeys( array $data, string $path = '' ): array {
        $found = [];
        foreach ( $data as $key => $value ) {
            $here = $path === '' ? (string) $key : $path . '.' . $key;
            if ( in_array( (string) $key, [ 'attendance_pct', 'missed', 'suspended' ], true ) ) $found[] = $here;
            if ( is_array( $value ) ) $found = array_merge( $found, self::attendanceKeys( $value, $here ) );
        }
        return $found;
    }

    /**
     * Compare a payload, levelled, with its stored snapshot; with
     * `TT_GOLDEN_RECORD=1`, store it.
     *
     * @param array<mixed>|string|null $payload
     */
    private function golden( string $name, $payload, string $message = '' ): void {
        GoldenSnapshot::assertMatches( $name, is_string( $payload ) ? $payload : (array) $this->level( $payload ), $message );
    }

    /**
     * `TT_GOLDEN_SHIFT=N` moves every table the fixture writes N ids on, as
     * a second fresh database would, so a local run shows the snapshots do
     * not depend on the ids. An insert into a table that refuses a default
     * row is skipped; the run then shifts the others.
     */
    private function shiftIds(): void {
        $n = (int) getenv( 'TT_GOLDEN_SHIFT' );
        if ( $n <= 0 ) return;
        global $wpdb;
        $tables = [
            'tt_teams', 'tt_players', 'tt_activities', 'tt_attendance', 'tt_evaluations', 'tt_eval_ratings',
            'tt_eval_categories', 'tt_measurement_definitions', 'tt_measurement_sessions', 'tt_measurement_results',
            'tt_measurement_targets', 'tt_seasons', 'tt_pdp_files', 'tt_pdp_conversations', 'tt_goals',
            'tt_player_injuries', 'tt_player_events', 'tt_thread_messages', 'tt_player_behaviour_ratings',
            'tt_player_potential',
        ];
        $wpdb->hide_errors();
        foreach ( $tables as $table ) {
            for ( $i = 0; $i < $n; $i++ ) {
                $wpdb->query( "INSERT INTO {$wpdb->prefix}{$table} () VALUES ()" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                if ( $wpdb->insert_id > 0 ) $wpdb->delete( $wpdb->prefix . $table, [ 'id' => $wpdb->insert_id ] );
            }
        }
        for ( $i = 0; $i < $n; $i++ ) self::factory()->user->create();
    }

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
        return GoldenSnapshot::id( 'team', (int) $wpdb->insert_id );
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
        return GoldenSnapshot::id( 'player', (int) $wpdb->insert_id );
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
        $this->team = GoldenSnapshot::id( 'team', (int) $wpdb->insert_id );

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
            $this->players[] = GoldenSnapshot::id( 'player', (int) $wpdb->insert_id );
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
            $eval_id = GoldenSnapshot::id( 'evaluation', (int) $wpdb->insert_id );
            foreach ( $ratings as $category_id => $value ) {
                $wpdb->insert( "{$p}tt_eval_ratings", [
                    'club_id'       => $club,
                    'evaluation_id' => $eval_id,
                    'category_id'   => $category_id,
                    'rating'        => $value,
                ] );
                GoldenSnapshot::id( 'eval_rating', (int) $wpdb->insert_id );
            }
        }

        $this->seedTests();

        // Behaviour and potential for the player report, and a weak picture
        // for Daan so the squad has somebody on the agenda.
        $behaviour = new PlayerBehaviourRatingsRepository();
        GoldenSnapshot::id( 'behaviour', $behaviour->create( [ 'player_id' => $this->players[0], 'rated_at' => '2020-03-10 10:00:00', 'rated_by' => $this->admin, 'rating' => 8.0, 'context' => 'training', 'notes' => 'Leads the warm-up.' ] ) );
        GoldenSnapshot::id( 'behaviour', $behaviour->create( [ 'player_id' => $this->players[0], 'rated_at' => '2020-03-24 10:00:00', 'rated_by' => $this->admin, 'rating' => 7.0, 'context' => 'match' ] ) );
        GoldenSnapshot::id( 'behaviour', $behaviour->create( [ 'player_id' => $this->players[3], 'rated_at' => '2020-03-21 10:00:00', 'rated_by' => $this->admin, 'rating' => 1.0, 'context' => 'match' ] ) );
        $potential = new PlayerPotentialRepository();
        GoldenSnapshot::id( 'potential', $potential->create( [ 'player_id' => $this->players[0], 'set_at' => '2020-03-15 10:00:00', 'set_by' => $this->admin, 'potential_band' => 'ahead', 'notes' => 'Reads the game early.' ] ) );
        GoldenSnapshot::id( 'potential', $potential->create( [ 'player_id' => $this->players[3], 'set_at' => '2020-03-15 10:00:00', 'set_by' => $this->admin, 'potential_band' => 'below_level' ] ) );

        // A PDP file with one conversation, so the PDP block has a file.
        $wpdb->insert( "{$p}tt_seasons", [ 'club_id' => $club, 'name' => '2019/20', 'start_date' => '2019-07-01', 'end_date' => '2020-06-30', 'is_current' => 0 ] );
        $season = GoldenSnapshot::id( 'season', (int) $wpdb->insert_id );
        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => $club, 'player_id' => $this->players[0], 'season_id' => $season, 'status' => 'open' ] );
        $file = GoldenSnapshot::id( 'pdp_file', (int) $wpdb->insert_id );
        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'      => $club,
            'pdp_file_id'  => $file,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2020-03-09 10:00:00',
        ] );
        GoldenSnapshot::id( 'conversation', (int) $wpdb->insert_id );

        $wpdb->insert( "{$p}tt_goals", [
            'club_id'    => $club,
            'player_id'  => $this->players[0],
            'title'      => 'Win more headers',
            'status'     => 'in_progress',
            'created_by' => $this->admin,
            'created_at' => '2020-03-05 09:00:00',
            'updated_at' => '2020-03-05 09:00:00',
        ] );
        GoldenSnapshot::id( 'goal', (int) $wpdb->insert_id );

        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id'       => $club,
            'player_id'     => $this->players[1],
            'started_on'    => '2020-03-18',
            'actual_return' => null,
            'notes'         => 'Ankle.',
        ] );
        GoldenSnapshot::id( 'injury', (int) $wpdb->insert_id );
        $wpdb->insert( "{$p}tt_player_injuries", [
            'club_id'       => $club,
            'player_id'     => $this->players[0],
            'started_on'    => '2020-03-02',
            'actual_return' => '2020-03-09',
            'notes'         => 'Hamstring.',
        ] );
        GoldenSnapshot::id( 'injury', (int) $wpdb->insert_id );

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
            GoldenSnapshot::id( 'event', (int) $wpdb->insert_id );
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
            GoldenSnapshot::id( 'thread_message', (int) $wpdb->insert_id );
        }
    }

    private function category( string $label, ?int $parent_id, int $order ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => CurrentClub::id(),
            // A fixed key: a snapshot stores it (#4958).
            'category_key'  => 'zz_golden_' . sanitize_key( $label ),
            'label'         => $label,
            'parent_id'     => $parent_id,
            'display_order' => $order,
            'is_active'     => 1,
        ] );
        return GoldenSnapshot::id( 'category', (int) $wpdb->insert_id );
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
        $this->test_def = GoldenSnapshot::id( 'definition', (int) $wpdb->insert_id );

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id' => $club, 'category_id' => 0, 'name' => 'Golden jump', 'value_type' => 'numeric',
            'unit' => 'cm', 'direction' => 'higher', 'is_active' => 1, 'show_on_profile' => 1, 'visibility' => 'public',
        ] );
        $this->untaken_def = GoldenSnapshot::id( 'definition', (int) $wpdb->insert_id );

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
            GoldenSnapshot::id( 'session', (int) $wpdb->insert_id );
            foreach ( $this->players as $i => $player_id ) {
                $wpdb->insert( "{$p}tt_measurement_results", [
                    'club_id' => $club, 'definition_id' => $this->test_def, 'player_id' => $player_id,
                    'recorded_date' => $date, 'value_numeric' => $values[ $i ],
                ] );
                GoldenSnapshot::id( 'result', (int) $wpdb->insert_id );
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
        return GoldenSnapshot::id( 'activity', (int) $wpdb->insert_id );
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
