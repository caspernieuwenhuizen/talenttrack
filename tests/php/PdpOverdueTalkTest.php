<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Services\PdpCycleState;
use TT\Modules\Pdp\Services\PdpFamilyReader;

/**
 * #3692 — a development talk whose planned date has passed without it
 * being held reads "Overdue", not "Planned".
 *
 * On My PDP every unheld talk used to carry the "Planned" chip whatever
 * its date, so a player and their parent saw a date weeks in the past
 * under the word "Planned" and could not tell whether the talk had been
 * held, moved or forgotten. The rule is domain logic — `isOverdue()` —
 * so the screen and `GET players/{id}/pdp` answer the same thing.
 */
final class PdpOverdueTalkTest extends WP_UnitTestCase {

    /** Fixed "now" so the assertions do not depend on the day CI runs. */
    private const NOW = 1790000000; // 2026-09-21 06:13:20 UTC

    private string $p   = '';
    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $season = 0;
    private int $file   = 0;

    /* ---- the domain rule ------------------------------------------------ */

    public function test_a_talk_planned_yesterday_and_not_held_is_overdue(): void {
        $this->assertTrue( PdpCycleState::isOverdue( $this->conv( $this->offsetDay( -1 ) . ' 18:00:00' ), self::NOW ) );
    }

    public function test_a_talk_planned_later_today_is_not_yet_overdue(): void {
        $this->assertFalse(
            PdpCycleState::isOverdue( $this->conv( $this->offsetDay( 0 ) . ' 23:30:00' ), self::NOW ),
            'the state flips at midnight, not at the hour the talk was pencilled in for'
        );
    }

    public function test_a_talk_planned_next_week_is_not_overdue(): void {
        $this->assertFalse( PdpCycleState::isOverdue( $this->conv( $this->offsetDay( 7 ) . ' 18:00:00' ), self::NOW ) );
    }

    public function test_a_talk_planned_yesterday_but_conducted_is_not_overdue(): void {
        $conv = $this->conv( $this->offsetDay( -1 ) . ' 18:00:00', [ 'conducted_at' => $this->offsetDay( -1 ) . ' 18:40:00' ] );
        $this->assertFalse( PdpCycleState::isOverdue( $conv, self::NOW ) );
    }

    public function test_a_signed_off_talk_is_never_overdue(): void {
        $conv = $this->conv( $this->offsetDay( -30 ) . ' 18:00:00', [ 'coach_signoff_at' => $this->offsetDay( -29 ) . ' 09:00:00' ] );
        $this->assertFalse( PdpCycleState::isOverdue( $conv, self::NOW ) );
    }

    public function test_a_talk_without_a_planned_date_is_not_overdue(): void {
        $this->assertFalse(
            PdpCycleState::isOverdue( $this->conv( null ), self::NOW ),
            'an unscheduled talk cannot be late for a date it has not got'
        );
    }

    /* ---- the marker state ----------------------------------------------- */

    public function test_the_marker_state_reports_overdue_before_next(): void {
        $row = [ 'id' => 7, 'scheduled_at' => $this->offsetDay( -3 ) . ' 18:00:00' ];

        $this->assertSame(
            'overdue',
            PdpFamilyReader::stateOf( $row, 7, self::NOW ),
            'the next talk in the cycle still reads overdue once its date has passed'
        );
    }

    public function test_a_past_talk_that_is_not_the_next_one_is_also_overdue(): void {
        $row = [ 'id' => 9, 'scheduled_at' => $this->offsetDay( -3 ) . ' 18:00:00' ];

        $this->assertSame( 'overdue', PdpFamilyReader::stateOf( $row, 7, self::NOW ) );
    }

    public function test_a_future_talk_still_reads_next_or_later(): void {
        $next  = [ 'id' => 7, 'scheduled_at' => $this->offsetDay( 10 ) . ' 18:00:00' ];
        $later = [ 'id' => 8, 'scheduled_at' => $this->offsetDay( 40 ) . ' 18:00:00' ];

        $this->assertSame( 'next', PdpFamilyReader::stateOf( $next, 7, self::NOW ) );
        $this->assertSame( 'future', PdpFamilyReader::stateOf( $later, 7, self::NOW ) );
    }

    public function test_a_held_talk_still_reads_done(): void {
        $row = [ 'id' => 7, 'scheduled_at' => $this->offsetDay( -3 ) . ' 18:00:00', 'conducted_at' => $this->offsetDay( -3 ) . ' 18:45:00' ];

        $this->assertSame( 'done', PdpFamilyReader::stateOf( $row, 7, self::NOW ) );
    }

    /* ---- the family route ------------------------------------------------ */

    public function test_the_family_route_reports_an_overdue_talk(): void {
        $this->bootRest();
        $this->seed();

        $conv_id = $this->insertConversation( 1, gmdate( 'Y-m-d', time() - 10 * DAY_IN_SECONDS ) . ' 18:00:00' );
        wp_set_current_user( $this->linkedPlayerAccount() );

        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/pdp' );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertIsArray( $data );
        $conversations = $data['data']['conversations'];
        $this->assertSame( $conv_id, $conversations[0]['id'] );
        $this->assertSame( 'overdue', $conversations[0]['state'] );
        $this->assertSame(
            $conv_id,
            $data['data']['next_conversation_id'],
            'it is still the next talk in the cycle — only its chip has changed'
        );
    }

    public function test_the_family_route_still_reports_a_future_talk_as_next(): void {
        $this->bootRest();
        $this->seed();

        $this->insertConversation( 1, gmdate( 'Y-m-d', time() + 20 * DAY_IN_SECONDS ) . ' 18:00:00' );
        wp_set_current_user( $this->linkedPlayerAccount() );

        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/pdp' );
        $response = rest_do_request( $request );
        $data     = $response->get_data();

        $this->assertSame( 200, $response->get_status() );
        $this->assertIsArray( $data );
        $this->assertSame( 'next', $data['data']['conversations'][0]['state'] );
    }

    /* ---- fixtures -------------------------------------------------------- */

    /**
     * A conversation row as the repository hands it over.
     *
     * @param array<string,mixed> $extra
     */
    private function conv( ?string $scheduled_at, array $extra = [] ): object {
        return (object) array_merge( [
            'id'               => 1,
            'sequence'         => 1,
            'scheduled_at'     => $scheduled_at,
            'conducted_at'     => null,
            'coach_signoff_at' => null,
        ], $extra );
    }

    /** A date $days away from the fixed "now", as YYYY-MM-DD. */
    private function offsetDay( int $days ): string {
        return gmdate( 'Y-m-d', self::NOW + $days * DAY_IN_SECONDS );
    }

    private function bootRest(): void {
        global $wp_rest_server;
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    private function seed(): void {
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'O15-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $this->team,
            'first_name' => 'Sem',
            'last_name'  => 'de Vries',
            'status'     => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_seasons", [
            'club_id'    => $this->club,
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_pdp_files", [
            'club_id'   => $this->club,
            'player_id' => $this->player,
            'season_id' => $this->season,
            'status'    => 'open',
        ] );
        $this->file = (int) $wpdb->insert_id;
    }

    private function insertConversation( int $sequence, string $scheduled_at ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_pdp_conversations", [
            'club_id'      => $this->club,
            'pdp_file_id'  => $this->file,
            'sequence'     => $sequence,
            'template_key' => 'start',
            'scheduled_at' => $scheduled_at,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function linkedPlayerAccount(): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$this->p}tt_players", [ 'wp_user_id' => $user ], [ 'id' => $this->player ] );
        return $user;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }
}
