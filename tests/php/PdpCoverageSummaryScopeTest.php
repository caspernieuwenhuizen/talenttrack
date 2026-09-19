<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;
use TT\Modules\Pdp\PdpAccess;

/**
 * #3685 — the "M of N players have a PDP" line on `?tt_view=pdp` and the
 * `summary` block of `GET pdp-files/coverage` count the same players.
 *
 * The line narrowed everyone without `tt_edit_settings` to the teams they
 * coach, while the REST route (which fills the table underneath) lets a
 * global PDP reader see every player. A Head of Development coaches no team,
 * so the line read "0 of 0" above a full roster. Both now take their scope
 * from PdpAccess::coverageScopePlayerIds().
 */
final class PdpCoverageSummaryScopeTest extends WP_UnitTestCase {

    private int $season = 0;
    private int $team_a = 0;
    private int $team_b = 0;

    /** @var list<int> users that hold the PDP read cap in this test */
    private array $pdp_viewers = [];

    /** @var list<int> users denied tt_edit_settings / manage_options */
    private array $non_admins = [];

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
            if ( in_array( $uid, $this->pdp_viewers, true ) ) {
                $allcaps['tt_view_pdp'] = true;
            }
            // The bug needs a global PDP reader who is not a settings admin.
            if ( in_array( $uid, $this->non_admins, true ) ) {
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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Coverage O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Coverage O13-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        // Team A: three players, two with a PDP. Team B: one player with a PDP.
        $this->player( $this->team_a, 'Anna', true );
        $this->player( $this->team_a, 'Bram', true );
        $this->player( $this->team_a, 'Cas', false );
        $this->player( $this->team_b, 'Daan', true );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        unset( $_GET['filter'], $_GET['only_missing'] );
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_head_of_development_line_counts_the_whole_team(): void {
        $hod = $this->headOfDevelopment();
        wp_set_current_user( $hod );
        $this->assertFalse( current_user_can( 'tt_edit_settings' ), 'the HoD in this test is not an admin' );

        $this->assertNull( PdpAccess::coverageScopePlayerIds( $hod ) );

        $summary = $this->restSummary( $this->team_a );
        $this->assertSame( [ 'total' => 3, 'covered' => 2 ], $summary );
        $this->assertSame( [ 2, 3 ], $this->renderedSummary( $hod, $this->team_a ) );
    }

    public function test_head_coach_is_still_narrowed_to_their_own_roster(): void {
        $coach = $this->coachOf( $this->team_a );
        wp_set_current_user( $coach );

        $scope = PdpAccess::coverageScopePlayerIds( $coach );
        $this->assertIsArray( $scope );
        $this->assertCount( 3, $scope );

        $this->assertSame( [ 'total' => 3, 'covered' => 2 ], $this->restSummary( $this->team_a ) );
        $this->assertSame( [ 2, 3 ], $this->renderedSummary( $coach, $this->team_a ) );

        // Another coach's team: nothing in scope, in the line and in REST.
        $this->assertSame( [ 'total' => 0, 'covered' => 0 ], $this->restSummary( $this->team_b ) );
        $this->assertSame( [ 0, 0 ], $this->renderedSummary( $coach, $this->team_b ) );
    }

    public function test_line_and_rest_summary_agree_for_every_persona(): void {
        $admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->pdp_viewers[] = $admin;

        foreach ( [ $admin, $this->headOfDevelopment(), $this->coachOf( $this->team_a ) ] as $user ) {
            wp_set_current_user( $user );
            foreach ( [ $this->team_a, $this->team_b ] as $team ) {
                $rest = $this->restSummary( $team );
                $this->assertSame(
                    [ (int) $rest['covered'], (int) $rest['total'] ],
                    $this->renderedSummary( $user, $team ),
                    "user {$user}, team {$team}"
                );
            }
        }
    }

    // ---- fixtures ---------------------------------------------------------

    private function player( int $team, string $first_name, bool $with_pdp ): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $team,
            'first_name' => $first_name,
            'last_name'  => 'Coverage',
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;
        if ( ! $with_pdp ) return;
        $wpdb->insert( "{$p}tt_pdp_files", [
            'club_id'   => $club,
            'player_id' => $player_id,
            'season_id' => $this->season,
            'status'    => 'open',
        ] );
    }

    private function headOfDevelopment(): int {
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->pdp_viewers[] = $user;
        $this->non_admins[]  = $user;
        return $user;
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_viewers[] = $coach;

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

    /** @return array<string,int> */
    private function restSummary( int $team ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-files/coverage' );
        $request->set_query_params( [
            'season_id' => $this->season,
            'filter'    => [ 'team_id' => $team ],
            'per_page'  => 100,
        ] );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data    = (array) $response->get_data();
        $payload = (array) ( $data['data'] ?? [] );
        $summary = (array) ( $payload['summary'] ?? [] );
        return [
            'total'   => (int) ( $summary['total'] ?? -1 ),
            'covered' => (int) ( $summary['covered'] ?? -1 ),
        ];
    }

    /**
     * Renders the coverage list the way `?tt_view=pdp&filter[team_id]=N`
     * does and reads the numbers off the summary line.
     *
     * @return array{0:int,1:int} [covered, total]
     */
    private function renderedSummary( int $user, int $team ): array {
        // The season the REST calls are pinned to, shaped like a
        // SeasonsRepository row.
        $current = (object) [ 'id' => $this->season, 'name' => '2026/27' ];

        $_GET['filter'] = [ 'team_id' => (string) $team ];
        $method = new \ReflectionMethod( FrontendPdpManageView::class, 'renderCoverageList' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $user, user_can( $user, 'tt_edit_settings' ), $current );
        $html = (string) ob_get_clean();

        $matched = preg_match( '/tt-pdp-coverage-summary">(\d+) of (\d+) players/', $html, $m );
        $this->assertSame( 1, $matched, 'the coverage summary line is rendered' );
        return [ (int) $m[1], (int) $m[2] ];
    }
}
