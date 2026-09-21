<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\PlayerReportBlock;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Reports\ReportConfig;
use TT\Modules\Reports\ScoutDelivery;

/**
 * #3876 (epic #3871) — who a player report is written for.
 *
 * What is pinned: the scout audience is a payload cut, not a render choice —
 * blocks outside the allowlist are absent from the data and each evaluation
 * loses the coach's notes; asking for a staff block as a scout is not a way in;
 * the audience is resolved from the reader, so a scout reading through REST
 * gets the scout composition whatever the query string says; staff keep the
 * internal report; and the emailed scout document is that same composition.
 *
 * The staff reader is a `tt_club_admin` (resolves to `academy_admin`). The
 * scout is a `tt_scout` linked to the player through the assignment meta, the
 * way the head of development assigns one.
 *
 * Dates are in 2020 so nothing depends on the runner's clock.
 */
final class PlayerReportAudienceTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $admin  = 0;
    private int $scout  = 0;
    private int $player = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin' ] );
        $this->scout = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );
        wp_set_current_user( $this->admin );

        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Audience U15', 'age_group' => 'U15' ] );
        $team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'       => CurrentClub::id(),
            'team_id'       => $team,
            'first_name'    => 'Audience',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => '2011-05-06',
            'wp_user_id'    => null,
        ] );
        $this->player = (int) $wpdb->insert_id;

        update_user_meta( $this->scout, 'tt_scout_player_ids', (string) wp_json_encode( [ $this->player ] ) );

        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id'   => CurrentClub::id(),
            'player_id' => $this->player,
            'coach_id'  => $this->admin,
            'eval_date' => '2020-03-10',
            'notes'     => 'Needs to scan before receiving.',
        ] );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_staff_read_the_internal_report_and_a_scout_the_scout_one(): void {
        $this->assertSame( PlayerReportAudience::INTERNAL, PlayerReportAudience::forReader( $this->admin ) );
        $this->assertSame( PlayerReportAudience::SCOUT, PlayerReportAudience::forReader( $this->scout ) );
        $this->assertSame( PlayerReportAudience::SCOUT, PlayerReportAudience::forReader( 0 ), 'a reader the rules cannot place gets less' );
    }

    public function test_the_scout_payload_carries_only_the_allowlist(): void {
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [], $this->admin, PlayerReportAudience::SCOUT );

        $this->assertNotNull( $report );
        $this->assertSame( PlayerReportAudience::SCOUT, $report['audience'] );
        $this->assertSame( PlayerReportAudience::SCOUT_BLOCKS, $report['blocks'] );
        $this->assertSame( [], array_diff( array_keys( $report['data'] ), PlayerReportAudience::SCOUT_BLOCKS ), 'nothing outside the list is in the data' );
    }

    public function test_a_staff_block_asked_for_as_a_scout_is_not_a_way_in(): void {
        $report = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'goals', 'pdp', 'thread_notes', 'injuries' ], $this->admin, PlayerReportAudience::SCOUT );

        $this->assertNotNull( $report );
        $this->assertSame( [ PlayerReportBlock::LETTERHEAD ], $report['blocks'] );
        $this->assertSame( [ PlayerReportBlock::LETTERHEAD ], array_keys( $report['data'] ) );
    }

    public function test_the_scout_reads_scores_without_the_coachs_notes(): void {
        $internal = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'ratings' ], $this->admin );
        $this->assertSame( 'Needs to scan before receiving.', $internal['data']['ratings']['evaluations'][0]['notes'] ?? null, 'precondition: staff see the note' );

        $scout = ( new PlayerReport() )->forPlayer( $this->player, self::FROM, self::TO, [ 'ratings' ], $this->admin, PlayerReportAudience::SCOUT );
        $this->assertCount( 1, $scout['data']['ratings']['evaluations'] );
        $this->assertArrayNotHasKey( 'notes', $scout['data']['ratings']['evaluations'][0] );
    }

    public function test_a_scout_reading_through_rest_gets_the_scout_composition(): void {
        $staff = $this->get( [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'ratings,goals' ] );
        $this->assertSame( 200, $staff->get_status() );
        $this->assertSame( PlayerReportAudience::INTERNAL, $staff->get_data()['data']['audience'] );
        $this->assertContains( PlayerReportBlock::GOALS, $staff->get_data()['data']['blocks'] );

        wp_set_current_user( $this->scout );
        $scout = $this->get( [ 'from' => self::FROM, 'to' => self::TO, 'blocks' => 'ratings,goals', 'audience' => 'internal' ] );
        $this->assertSame( 200, $scout->get_status(), 'a scout reads the players linked to them' );
        $report = $scout->get_data()['data'];
        $this->assertSame( PlayerReportAudience::SCOUT, $report['audience'], 'the audience is the reader\'s, not the request\'s' );
        $this->assertSame( [ PlayerReportBlock::LETTERHEAD, PlayerReportBlock::RATINGS ], $report['blocks'] );
        $this->assertArrayNotHasKey( 'notes', $report['data']['ratings']['evaluations'][0] );
    }

    public function test_the_emailed_scout_document_is_the_scout_composition(): void {
        $config = new ReportConfig(
            AudienceType::SCOUT,
            [ 'date_from' => self::FROM, 'date_to' => self::TO, 'eval_type_id' => 0 ],
            [ 'profile', 'ratings', 'coach_notes' ],
            $this->player,
            $this->admin
        );

        $html = ScoutDelivery::scoutDocument( $this->player, $config );

        $this->assertStringContainsString( 'Audience Player', $html );
        $this->assertStringNotContainsString( 'Needs to scan before receiving.', $html, 'coach notes map to nothing a scout may receive' );
    }

    /** @param array<string,string> $params */
    private function get( array $params ): \WP_REST_Response {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/report' );
        foreach ( $params as $key => $value ) {
            $request->set_param( $key, $value );
        }
        return rest_get_server()->dispatch( $request );
    }
}
