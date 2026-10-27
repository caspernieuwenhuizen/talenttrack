<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;

/**
 * #4244 — the PDP create form (`?tt_view=pdp&action=new`) and
 * `POST pdp-files` decide the same way who may open a file for whom.
 *
 * The form's roster only listed every player for `tt_edit_settings`, while
 * the REST route lets any global PDP change holder through. A Head of
 * Development coaches no team, so the form said "No players available"
 * for a file the API would have created.
 */
final class PdpCreateFormRosterTest extends WP_UnitTestCase {

    private int $season = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_b = 0;

    /** @var list<int> users that hold the PDP caps in this test */
    private array $pdp_editors = [];

    /** @var list<int> users denied tt_edit_settings / manage_options */
    private array $non_admins = [];

    /** @var list<int> users denied every PDP cap */
    private array $no_pdp = [];

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->cap_filter = function ( $allcaps, $caps, $args, $user ) {
            $uid = is_object( $user ) ? (int) $user->ID : 0;
            if ( in_array( $uid, $this->pdp_editors, true ) ) {
                $allcaps['tt_view_pdp'] = true;
                $allcaps['tt_edit_pdp'] = true;
            }
            if ( in_array( $uid, $this->non_admins, true ) ) {
                $allcaps['tt_edit_settings'] = false;
                $allcaps['manage_options']   = false;
            }
            if ( in_array( $uid, $this->no_pdp, true ) ) {
                $allcaps['tt_view_pdp']      = false;
                $allcaps['tt_edit_pdp']      = false;
                $allcaps['tt_edit_settings'] = false;
                $allcaps['manage_options']   = false;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->season = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Roster O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Roster O13-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        unset( $_GET['action'], $_GET['player_id'] );
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_head_of_development_without_a_team_gets_every_player(): void {
        $hod = $this->headOfDevelopment();
        wp_set_current_user( $hod );
        $this->assertFalse( current_user_can( 'tt_edit_settings' ), 'the HoD in this test is not an admin' );

        $roster = $this->roster( $hod );
        $this->assertNotEmpty( $roster );
        $this->assertArrayHasKey( $this->player_b, $roster );
    }

    public function test_head_of_development_sees_the_form_with_the_player_preselected(): void {
        $hod = $this->headOfDevelopment();
        wp_set_current_user( $hod );

        $html = $this->renderCreateForm( $hod, $this->player_b );
        $this->assertStringNotContainsString( 'No players available', $html );
        $this->assertMatchesRegularExpression(
            '/<option[^>]*value="' . $this->player_b . '"[^>]*selected/',
            $html
        );
    }

    public function test_head_of_development_can_create_through_rest(): void {
        $hod = $this->headOfDevelopment();
        wp_set_current_user( $hod );

        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/pdp-files' );
        $request->set_body_params( [ 'player_id' => $this->player_b ] );
        $response = rest_do_request( $request );

        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        $this->assertTrue( (bool) ( $data['success'] ?? false ) );
    }

    public function test_head_coach_is_still_narrowed_to_their_own_team(): void {
        $coach = $this->coachOf( $this->team_a );
        wp_set_current_user( $coach );

        $roster = $this->roster( $coach );
        $this->assertCount( 1, $roster );
        $this->assertArrayNotHasKey( $this->player_b, $roster );
    }

    public function test_user_without_pdp_rights_still_gets_the_notice(): void {
        $user = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->no_pdp[] = $user;
        wp_set_current_user( $user );

        $this->assertSame( [], $this->roster( $user ) );
        $this->assertStringContainsString( 'No players available', $this->renderCreateForm( $user, $this->player_b ) );
    }

    // ---- fixtures ---------------------------------------------------------

    private function player( int $team, string $first_name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => (int) CurrentClub::id(),
            'team_id'    => $team,
            'first_name' => $first_name,
            'last_name'  => 'Roster',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function headOfDevelopment(): int {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->pdp_editors[] = $user;
        $this->non_admins[]  = $user;
        return $user;
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_editors[] = $coach;
        $this->non_admins[]  = $coach;

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Team',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $coach,
            'status'     => 'active',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => (int) CurrentClub::id(),
            'person_id'  => (int) $wpdb->insert_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team,
        ] );
        return $coach;
    }

    /** @return array<int, object> */
    private function roster( int $user ): array {
        $method = new ReflectionMethod( FrontendPdpManageView::class, 'eligiblePlayerObjects' );
        $method->setAccessible( true );
        return (array) $method->invoke( null, $user, user_can( $user, 'tt_edit_settings' ) );
    }

    private function renderCreateForm( int $user, int $player_id ): string {
        $_GET['action']    = 'new';
        $_GET['player_id'] = (string) $player_id;
        $method = new ReflectionMethod( FrontendPdpManageView::class, 'renderCreateForm' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $user, user_can( $user, 'tt_edit_settings' ) );
        return (string) ob_get_clean();
    }
}
