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
use TT\Modules\Analytics\Reports\TeamMatchStatsQuery;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Export\Exporters\PlayerReportPdfDocument;

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
 * `generated_at` is levelled, because the two runs may fall either side of a
 * second.
 *
 * A deliberate change to a figure updates the frozen copy in the same PR, so
 * the change is visible in review as a change to the "before".
 *
 * Dates are in 2020, so nothing depends on the runner's clock.
 */
final class ReportComposersGoldenTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $admin = 0;
    private int $team  = 0;

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
        $this->assertSame(
            $this->json( ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ $block ], $this->admin ) ),
            $this->json( ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ $block ], $this->admin ) ),
            "the {$block} block composes the same bytes"
        );
    }

    public function test_the_whole_team_report_is_unchanged_with_options(): void {
        $options = [
            TeamMonthlyReportBlock::ATTENDANCE => [ 'level' => 'summary' ],
            TeamMonthlyReportBlock::MINUTES    => [ 'level' => 'summary' ],
            TeamMonthlyReportBlock::MATCHES    => [ 'level' => 'details', 'record' => true, 'scorers' => true, 'squads' => true ],
        ];

        foreach ( [ 0, $this->admin ] as $viewer ) {
            $before = ( new LegacyTeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $viewer, $options );
            $after  = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $viewer, $options );
            $this->assertSame( $this->json( $before ), $this->json( $after ), "viewer {$viewer}" );
        }
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

    // ---- helpers --------------------------------------------------------

    /** @param array<string,mixed>|null $payload */
    private function json( ?array $payload ): string {
        return (string) wp_json_encode( $this->level( $payload ) );
    }

    /**
     * The payload with every `generated_at` set to one value.
     *
     * @param mixed $payload
     * @return mixed
     */
    private function level( $payload ) {
        if ( ! is_array( $payload ) ) return $payload;
        foreach ( $payload as $key => $value ) {
            if ( $key === 'generated_at' ) {
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

        foreach ( [ [ 0, '2020-03-12', 7.5 ], [ 1, '2020-03-19', 6.0 ], [ 0, '2020-02-20', 6.5 ] ] as [ $who, $date, $rating ] ) {
            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id'   => $club,
                'player_id' => $this->players[ $who ],
                'coach_id'  => $this->admin,
                'eval_date' => $date,
                'rating'    => $rating,
                'notes'     => 'Golden evaluation.',
            ] );
        }

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

        $wpdb->insert( "{$p}tt_player_events", [
            'club_id'            => $club,
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $this->players[0],
            'event_type'         => 'position_changed',
            'event_date'         => '2020-03-20 09:00:00',
            'summary'            => 'Moved to centre back.',
            'visibility'         => 'public',
            'source_module'      => 'tests',
            'source_entity_type' => 'test_event',
            'source_entity_id'   => 1,
        ] );

        $wpdb->insert( "{$p}tt_thread_messages", [
            'club_id'        => $club,
            'uuid'           => wp_generate_uuid4(),
            'thread_type'    => 'player',
            'thread_id'      => $this->players[0],
            'author_user_id' => $this->admin,
            'body'           => 'Golden note.',
            'visibility'     => 'public',
            'created_at'     => '2020-03-15 09:00:00',
        ] );
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
