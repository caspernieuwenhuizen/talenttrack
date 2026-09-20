<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3810 — coverage by team, and "who has not had their talk".
 *
 * Seven weeks into a season one talk of sixty-four had been held, and the
 * head of development could not see which teams the other sixty-three were
 * in: `pdp-files/coverage` returns one flat ratio, and the screen shows one
 * team at a time. Finding out meant texting four coaches.
 *
 * Two things are asserted here and they are both about arithmetic rather
 * than access. The breakdown must agree with the headline — a total that
 * disagrees with its own parts is worse than no total. And both must be
 * computed over the whole filtered scope, so turning the page does not
 * change what the numbers say.
 */
final class PdpCoverageByTeamTest extends WP_UnitTestCase {

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

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Aa O11-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Bb O13-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        // Team A — three players, all three with a plan, one talked to.
        $this->player( $this->team_a, 'Anna', true,  true );
        $this->player( $this->team_a, 'Bram', true,  false );
        $this->player( $this->team_a, 'Cas',  true,  false );
        // Team B — two players, one with a plan and that one talked to.
        $this->player( $this->team_b, 'Daan', true,  true );
        $this->player( $this->team_b, 'Eva',  false, false );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_breakdown_agrees_with_the_headline(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        // The install this runs on has its own players, so the assertion
        // that matters is that the parts sum to the whole — not a number
        // this fixture chose.
        $summary = $this->coverage()['summary'];

        $players = 0;
        $covered = 0;
        foreach ( $summary['by_team'] as $team ) {
            $players += (int) $team['players'];
            $covered += (int) $team['covered'];
        }

        $this->assertSame( (int) $summary['total'], $players );
        $this->assertSame( (int) $summary['covered'], $covered );
        $this->assertGreaterThanOrEqual( 5, $players );
    }

    public function test_one_team_at_a_time_agrees_too(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        $summary = $this->coverage( [ 'filter' => [ 'team_id' => $this->team_a ] ] )['summary'];

        $this->assertSame( 3, (int) $summary['total'] );
        $this->assertSame( 3, (int) $summary['covered'] );
        $this->assertCount( 1, $summary['by_team'] );
        $this->assertSame( $this->team_a, (int) $summary['by_team'][0]['team_id'] );
    }

    public function test_each_team_carries_its_own_four_numbers(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        $by_team = $this->byTeamKeyedById();

        $this->assertSame( 3, (int) $by_team[ $this->team_a ]['players'] );
        $this->assertSame( 3, (int) $by_team[ $this->team_a ]['covered'] );
        $this->assertSame( 1, (int) $by_team[ $this->team_a ]['conducted'] );

        $this->assertSame( 2, (int) $by_team[ $this->team_b ]['players'] );
        $this->assertSame( 1, (int) $by_team[ $this->team_b ]['covered'] );
        $this->assertSame( 1, (int) $by_team[ $this->team_b ]['conducted'] );
    }

    public function test_the_team_that_needs_chasing_comes_first(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        $by_team = $this->coverage()['summary']['by_team'];

        $position = [];
        foreach ( $by_team as $index => $team ) {
            $position[ (int) $team['team_id'] ] = (int) $index;
        }

        // A has talked to 1 of 3, B to 1 of 2. A is further behind, so it
        // comes first — relative to B, whatever else this install holds.
        $this->assertArrayHasKey( $this->team_a, $position );
        $this->assertArrayHasKey( $this->team_b, $position );
        $this->assertLessThan( $position[ $this->team_b ], $position[ $this->team_a ] );
    }

    public function test_the_totals_do_not_change_when_you_turn_the_page(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        $first  = $this->coverage( [ 'per_page' => 10, 'page' => 1 ] );
        $second = $this->coverage( [ 'per_page' => 10, 'page' => 2 ] );

        $this->assertSame( $first['summary'], $second['summary'] );
        $this->assertSame( (int) $first['total'], (int) $second['total'] );
    }

    public function test_conducted_zero_lists_only_players_with_no_talk(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        // Team A: Bram and Cas have a file and no talk.
        $team_a = $this->coverage( [ 'conducted' => 0, 'filter' => [ 'team_id' => $this->team_a ] ] );
        $this->assertSame( 2, (int) $team_a['total'] );

        // Team B: Eva, who has no file at all. That is the worst case and
        // must not be filtered out by a question about talks.
        $team_b = $this->coverage( [ 'conducted' => 0, 'filter' => [ 'team_id' => $this->team_b ] ] );
        $this->assertSame( 1, (int) $team_b['total'] );

        // The summary describes the scope, not the narrowed list — a ratio
        // over "everyone who has not had their talk" would always be zero.
        $this->assertSame( 3, (int) $team_a['summary']['total'] );
        $this->assertSame( 3, (int) $team_a['summary']['covered'] );
    }

    public function test_a_caller_that_does_not_send_the_parameter_is_unaffected(): void {
        wp_set_current_user( $this->headOfDevelopment() );

        $scope = [ 'filter' => [ 'team_id' => $this->team_a ] ];

        $this->assertSame( 3, (int) $this->coverage( $scope )['total'] );
        $this->assertSame( 3, (int) $this->coverage( $scope + [ 'conducted' => '' ] )['total'] );
        $this->assertSame( 3, (int) $this->coverage( $scope + [ 'conducted' => 'all' ] )['total'] );
    }

    public function test_a_coach_only_sees_their_own_team_in_the_breakdown(): void {
        wp_set_current_user( $this->coachOf( $this->team_a ) );

        $by_team = $this->coverage()['summary']['by_team'];

        $this->assertCount( 1, $by_team );
        $this->assertSame( $this->team_a, (int) $by_team[0]['team_id'] );
    }

    // ---- fixtures ---------------------------------------------------------

    private function player( int $team, string $first_name, bool $with_pdp, bool $talked ): void {
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
        $file_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_pdp_conversations", [
            'club_id'      => $club,
            'pdp_file_id'  => $file_id,
            'sequence'     => 1,
            'template_key' => 'start',
            'scheduled_at' => '2026-10-01 10:00:00',
            'conducted_at' => $talked ? '2026-10-01 10:30:00' : null,
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

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    private function coverage( array $params = [] ): array {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/pdp-files/coverage' );
        $request->set_query_params( array_merge( [
            'season_id' => $this->season,
            'per_page'  => 100,
        ], $params ) );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        return (array) ( $data['data'] ?? [] );
    }

    /** @return array<int, array<string,mixed>> */
    private function byTeamKeyedById(): array {
        $out = [];
        foreach ( $this->coverage()['summary']['by_team'] as $team ) {
            $out[ (int) $team['team_id'] ] = $team;
        }
        return $out;
    }
}
