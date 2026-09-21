<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Pdp\EvidencePacket;

/**
 * #3872 (epic #3871) — the player report as data.
 *
 * What is pinned: the report reads the evidence packet rather than a second
 * assembly, and the PDP packet's own shape is untouched by that; a player with
 * no PDP file gets a full report; the blocks gate themselves for the reader
 * (journey visibility, injuries on the medical rung); unknown blocks, periods
 * and windows are refused rather than quietly replaced; and the route opens
 * for staff who read reports and stays shut for a parent.
 *
 * The caller is a `tt_club_admin`, which resolves to `academy_admin` and holds
 * `reports`, `players` and `pdp_file` at global scope in the seed. A WordPress
 * `administrator` does not resolve to that persona, and the matrix bridge
 * overwrites `$allcaps`, so `add_cap()` would grant nothing. Every refusal is
 * paired with a grant on the same fixture, so a fixture that could read
 * nothing fails instead of passing over a 403.
 *
 * Dates are in 2020 so nothing depends on the runner's clock.
 */
final class PlayerReportTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $admin  = 0;
    private int $team   = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin', 'display_name' => 'Report Admin' ] );
        wp_set_current_user( $this->admin );

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Report U15', 'age_group' => 'U15' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => CurrentClub::id(),
            'team_id'       => $this->team,
            'first_name'    => 'Report',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2011-05-06',
            'wp_user_id'    => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- composition ----------------------------------------------------

    public function test_an_empty_selection_is_the_conversation_set(): void {
        $this->assertSame( PlayerReportBlock::DEFAULT_BLOCKS, PlayerReportBlock::normalise( [] ) );
    }

    public function test_the_letterhead_is_always_included_and_print_order_wins(): void {
        $this->assertSame(
            [ PlayerReportBlock::LETTERHEAD, PlayerReportBlock::ATTENDANCE, PlayerReportBlock::TESTS ],
            PlayerReportBlock::normalise( [ 'tests', 'attendance' ] )
        );
    }

    public function test_the_default_window_is_the_season_so_far(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_seasons", [
            'club_id' => CurrentClub::id(), 'name' => '2020/21',
            'start_date' => '2020-07-01', 'end_date' => '2099-06-30', 'is_current' => 1,
        ] );

        $window = PlayerReportComposition::window( PlayerReportComposition::normalise( [] ), gmdate( 'Y-m-d' ) );

        $this->assertSame( '2020-07-01', $window['from'] );
        $this->assertSame( gmdate( 'Y-m-d' ), $window['to'], 'season to date, not through the season end' );
        $this->assertSame( '', $window['period'] );
    }

    public function test_an_explicit_window_wins_over_a_period(): void {
        $composition = PlayerReportComposition::normalise( [ 'period' => 'last_month', 'from' => self::FROM, 'to' => self::TO ] );
        $this->assertSame( [ 'from' => self::FROM, 'to' => self::TO, 'period' => '' ], PlayerReportComposition::window( $composition, '2020-05-10' ) );
    }

    // ---- the composer ---------------------------------------------------

    public function test_unknown_block_keys_are_refused(): void {
        $this->expectException( \InvalidArgumentException::class );
        ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'ratings', 'ratngs' ], $this->admin );
    }

    public function test_a_malformed_window_is_refused(): void {
        $this->expectException( \InvalidArgumentException::class );
        ( new PlayerReport() )->forPlayer( $this->player, self::TO, self::FROM, [], $this->admin );
    }

    /** The informal conversation this report exists for happens with players who never had a formal talk. */
    public function test_a_player_with_no_pdp_file_gets_a_full_report(): void {
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [], $this->admin );

        $this->assertNotNull( $report );
        $this->assertSame( PlayerReportBlock::DEFAULT_BLOCKS, $report['blocks'] );
        $this->assertTrue( $report['data']['pdp']['available'], 'an academy admin reads PDP files, so the block is available' );
        $this->assertNull( $report['data']['pdp']['file'], 'available with no file is the "no file yet" state, not an error' );
        $this->assertSame( 'Report Player', $report['data']['letterhead']['name'] );
        $this->assertSame( 'Report U15', $report['data']['letterhead']['team_name'] );
        $this->assertSame( 2011, $report['data']['letterhead']['birth_year'] );
    }

    public function test_the_pdp_block_carries_the_file_and_the_last_agreed_actions(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_seasons", [ 'club_id' => CurrentClub::id(), 'name' => '2019/20', 'start_date' => '2019-07-01', 'end_date' => '2020-06-30', 'is_current' => 1 ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => CurrentClub::id(), 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );
        $file = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id' => CurrentClub::id(), 'pdp_file_id' => $file, 'sequence' => 1, 'template_key' => 'start',
            'scheduled_at' => '2019-10-01 10:00:00', 'conducted_at' => '2019-10-01 10:00:00',
            'agreed_actions' => 'Two extra finishing sessions a week.',
        ] );

        $pdp = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'pdp' ], $this->admin )['data']['pdp'] ?? [];

        $this->assertSame( $file, $pdp['file']['id'] );
        $this->assertCount( 1, $pdp['conversations'] );
        $this->assertSame( 'Two extra finishing sessions a week.', $pdp['last_agreed_actions'] );
    }

    /** The letterhead and the blank notes area need nothing from the packet, so they never build one. */
    public function test_a_letterhead_only_report_does_not_assemble_the_packet(): void {
        $seen   = [];
        $filter = static function ( $sql ) use ( &$seen ) {
            $seen[] = (string) $sql;
            return $sql;
        };
        add_filter( 'query', $filter );
        try {
            $without = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'letterhead', 'notes' ], $this->admin );
        } finally {
            remove_filter( 'query', $filter );
        }

        $this->assertNotNull( $without );
        $touches = array_filter( $seen, static fn( string $s ): bool => strpos( $s, 'tt_evaluations' ) !== false );
        $this->assertSame( [], $touches, 'no evaluation query for a report that shows no evaluations' );
    }

    public function test_a_player_in_another_club_is_not_reported(): void {
        global $wpdb;
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'club_id' => CurrentClub::id() + 1 ], [ 'id' => $this->player ] );

        $this->assertNull( ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'letterhead' ], $this->admin ) );
        $this->assertNull( EvidencePacket::forPlayer( $this->player, self::FROM, self::TO, $this->admin ) );
    }

    // ---- the packet serves the report without changing the PDP shape ---

    /** The Evidence tab, the printed file and the verdict screen read these keys, in this order. */
    public function test_the_pdp_file_packet_keeps_its_shape(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_seasons", [ 'club_id' => CurrentClub::id(), 'name' => '2019/20', 'start_date' => '2019-07-01', 'end_date' => '2020-06-30' ] );
        $season = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_pdp_files", [ 'club_id' => CurrentClub::id(), 'player_id' => $this->player, 'season_id' => $season, 'status' => 'open' ] );

        $packet = EvidencePacket::forFile( (int) $wpdb->insert_id );

        $this->assertNotNull( $packet );
        $this->assertSame( [
            'file_id', 'player_id', 'conversation_id', 'season', 'window', 'status', 'behaviour', 'potential',
            'evaluations', 'attendance', 'minutes', 'goals', 'injuries', 'notes', 'self_reflection', 'recent_journey',
        ], array_keys( $packet ) );
    }

    // ---- the blocks gate themselves for the reader ----------------------

    public function test_journey_entries_follow_the_readers_visibility(): void {
        $this->journeyEvent( 'Moved to U16.', 'public' );
        $this->journeyEvent( 'Safeguarding referral.', 'safeguarding' );

        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $outsider_view = EvidencePacket::forPlayer( $this->player, self::FROM, self::TO, $outsider );
        $this->assertNotNull( $outsider_view );
        $summaries = array_map( static fn( $e ): string => (string) $e->summary, $outsider_view['recent_journey'] );
        $this->assertSame( [ 'Moved to U16.' ], $summaries, 'a public-only reader sees the public entry and nothing else' );

        $admin_view = EvidencePacket::forPlayer( $this->player, self::FROM, self::TO, $this->admin );
        $this->assertNotNull( $admin_view );
        $this->assertContains( 'Moved to U16.', array_map( static fn( $e ): string => (string) $e->summary, $admin_view['recent_journey'] ) );
    }

    public function test_injuries_need_the_medical_rung(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id' => CurrentClub::id(), 'player_id' => $this->player,
            'started_on' => '2020-03-05', 'actual_return' => null, 'notes' => 'Hamstring.',
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );

        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $packet   = EvidencePacket::forPlayer( $this->player, self::FROM, self::TO, $outsider );
        $this->assertNotNull( $packet );
        $this->assertSame( [], $packet['injuries'] );
    }

    // ---- REST -----------------------------------------------------------

    public function test_the_route_returns_the_conversation_set_by_default(): void {
        $response = $this->get( $this->player );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data()['data'];
        $this->assertSame( PlayerReportBlock::DEFAULT_BLOCKS, $data['blocks'] );
        $this->assertSame( $this->player, $data['player_id'] );
    }

    public function test_the_route_honours_an_explicit_window_and_blocks(): void {
        $response = $this->get( $this->player, [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'attendance,tests' ] );

        $this->assertSame( 200, $response->get_status() );
        $data = $response->get_data()['data'];
        $this->assertSame( self::FROM, $data['from'] );
        $this->assertSame( [ 'letterhead', 'attendance', 'tests' ], $data['blocks'] );
    }

    public function test_the_route_refuses_an_unknown_block_period_or_window(): void {
        $this->assertSame( 400, $this->get( $this->player, [ 'blocks' => 'ratngs' ] )->get_status() );
        $this->assertSame( 400, $this->get( $this->player, [ 'period' => 'last_year' ] )->get_status() );
        $this->assertSame( 400, $this->get( $this->player, [ 'from' => self::TO, 'to' => self::FROM ] )->get_status() );
        $this->assertSame( 400, $this->get( $this->player, [ 'from' => self::FROM ] )->get_status() );

        $this->assertSame( 200, $this->get( $this->player, [ 'period' => 'last_month' ] )->get_status(), 'the same caller with a valid period is served' );
    }

    public function test_the_route_is_shut_to_a_parent_of_the_player(): void {
        global $wpdb;
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [ 'player_id' => $this->player, 'parent_user_id' => $parent ] );

        $this->assertSame( 200, $this->get( $this->player )->get_status(), 'precondition: the report exists and staff can read it' );

        wp_set_current_user( $parent );
        $this->assertSame( 403, $this->get( $this->player )->get_status(), 'v1 is coach-facing: a parent holds no reports grant' );
    }

    public function test_an_unknown_and_an_out_of_scope_player_are_refused_alike(): void {
        $this->assertSame( 403, $this->get( 999999 )->get_status() );

        wp_set_current_user( (int) self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $this->assertSame( 403, $this->get( $this->player )->get_status() );
    }

    // ---- fixtures -------------------------------------------------------

    /** @param array<string,string> $params */
    private function get( int $player_id, array $params = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $player_id . '/report' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request );
    }

    private function journeyEvent( string $summary, string $visibility ): void {
        // The table's natural key includes the source entity id, so each
        // event needs its own or the second insert is refused as a duplicate.
        static $source_id = 0;
        $source_id++;

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id'            => CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $this->player,
            'event_type'         => 'age_group_change',
            'event_date'         => '2020-03-10 09:00:00',
            'summary'            => $summary,
            'visibility'         => $visibility,
            'source_module'      => 'tests',
            'source_entity_type' => 'test_event',
            'source_entity_id'   => $source_id,
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'the fixture wrote' );
    }
}
