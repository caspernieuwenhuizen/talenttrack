<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Pdp\Frontend\FrontendPdpManageView;

/**
 * #4266 — the PDP list can be looked at season by season, and a file says
 * which season it belongs to.
 *
 * `GET pdp-files/coverage` took a plain `season_id` but not the
 * `filter[season_id]` the list table sends, the screen was fixed to the
 * current season, and no payload carried the season's name, so last year's
 * plans could not be told apart from this year's.
 */
final class PdpCoverageSeasonFilterTest extends WP_UnitTestCase {

    private int $past = 0;
    private int $current = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $admin = 0;

    /** @var array<string,int> player ids by first name */
    private array $players = [];

    /** @var array<string,int> file ids by "<name>@<season>" */
    private array $files = [];

    /** @var list<int> users that hold the PDP read cap in this test */
    private array $pdp_viewers = [];

    /** @var list<int> users that hold the PDP edit cap in this test */
    private array $pdp_editors = [];

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
            if ( in_array( $uid, $this->pdp_editors, true ) ) {
                $allcaps['tt_edit_pdp'] = true;
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => $club,
            'name'       => '2025/26',
            'start_date' => '2025-07-01',
            'end_date'   => '2026-06-30',
            'is_current' => 0,
        ] );
        $this->past = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_seasons", [
            'club_id'    => $club,
            'name'       => '2026/27',
            'start_date' => '2026-07-01',
            'end_date'   => '2027-06-30',
            'is_current' => 1,
        ] );
        $this->current = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Season O12-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'Season O15-1' ] );
        $this->team_b = (int) $wpdb->insert_id;

        // Anna has a plan in both seasons, Bram only last season, Cas never.
        // Daan, on the other team, had one last season.
        $this->player( $this->team_a, 'Anna', [ $this->past, $this->current ] );
        $this->player( $this->team_a, 'Bram', [ $this->past ] );
        $this->player( $this->team_a, 'Cas', [] );
        $this->player( $this->team_b, 'Daan', [ $this->past ] );

        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->pdp_viewers[] = $this->admin;
        $this->pdp_editors[] = $this->admin;
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        unset( $_GET['filter'] );
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_list_tables_filter_form_picks_the_season(): void {
        $data = $this->coverage( [ 'filter' => [ 'season_id' => $this->past, 'team_id' => $this->team_a ] ] );

        $this->assertSame( $this->past, (int) $data['season_id'] );
        $this->assertSame( '2025/26', $data['season_name'] );
        $this->assertFalse( $data['is_current_season'] );
        $this->assertSame( [ 'Anna', 'Bram' ], $this->covered( $data ) );
        foreach ( (array) $data['rows'] as $row ) {
            $this->assertSame( '2025/26', ( (array) $row )['season_name'] ?? null );
        }

        $plain = $this->coverage( [ 'season_id' => $this->past, 'filter' => [ 'team_id' => $this->team_a ] ] );
        $this->assertSame( $this->covered( $data ), $this->covered( $plain ) );

        $nested_wins = $this->coverage( [
            'season_id' => $this->current,
            'filter'    => [ 'season_id' => $this->past, 'team_id' => $this->team_a ],
        ] );
        $this->assertSame( $this->past, (int) $nested_wins['season_id'] );
    }

    public function test_no_season_means_the_current_one(): void {
        $data = $this->coverage( [ 'filter' => [ 'team_id' => $this->team_a ] ] );

        $this->assertSame( $this->current, (int) $data['season_id'] );
        $this->assertSame( '2026/27', $data['season_name'] );
        $this->assertTrue( $data['is_current_season'] );
        $this->assertSame( [ 'Anna' ], $this->covered( $data ) );
        $this->assertSame( [ 'total' => 3, 'covered' => 1 ], $this->summary( $data ) );
    }

    public function test_another_season_offers_no_create_action(): void {
        $past = $this->rowFor( $this->coverage( [ 'filter' => [ 'season_id' => $this->past, 'team_id' => $this->team_a ] ] ), 'Cas' );
        $this->assertFalse( $past['has_pdp'] );
        $this->assertSame( '', $past['actions_html'] );
        $this->assertSame( '', $past['detail_url'] );

        $now = $this->rowFor( $this->coverage( [ 'filter' => [ 'team_id' => $this->team_a ] ] ), 'Cas' );
        $this->assertStringContainsString( 'action=new', (string) $now['actions_html'] );
        $this->assertStringContainsString( 'action=new', (string) $now['detail_url'] );
    }

    public function test_a_coach_stays_on_their_own_roster_in_any_season(): void {
        $coach = $this->coachOf( $this->team_a );
        wp_set_current_user( $coach );

        foreach ( [ $this->past, $this->current ] as $season ) {
            $own = $this->coverage( [ 'filter' => [ 'season_id' => $season ] ] );
            $this->assertSame( [ 'Anna', 'Bram', 'Cas' ], $this->names( $own ), "season {$season}" );

            $other = $this->coverage( [ 'filter' => [ 'season_id' => $season, 'team_id' => $this->team_b ] ] );
            $this->assertSame( [], $this->names( $other ), "season {$season}" );
            $this->assertSame( [ 'total' => 0, 'covered' => 0 ], $this->summary( $other ), "season {$season}" );
        }

        // The global reader sees the other team's file from last season.
        wp_set_current_user( $this->admin );
        $this->assertSame( [ 'Daan' ], $this->covered( $this->coverage( [ 'filter' => [ 'season_id' => $this->past, 'team_id' => $this->team_b ] ] ) ) );
    }

    public function test_a_file_names_its_season(): void {
        $one = $this->get( '/talenttrack/v1/pdp-files/' . $this->files[ 'Bram@' . $this->past ], [] );
        $file = (array) ( $one['file'] ?? [] );
        $this->assertSame( $this->past, (int) $file['season_id'] );
        $this->assertSame( '2025/26', $file['season_name'] );

        $list = $this->get( '/talenttrack/v1/pdp-files', [ 'season_id' => $this->past, 'per_page' => 100 ] );
        $this->assertSame( '2025/26', $list['season_name'] );
        $this->assertNotEmpty( $list['rows'] );
        foreach ( (array) $list['rows'] as $row ) {
            $this->assertSame( '2025/26', ( (array) $row )['season_name'] ?? null );
        }
    }

    public function test_the_screen_follows_the_chosen_season(): void {
        $past = $this->renderList( [ 'season_id' => (string) $this->past, 'team_id' => (string) $this->team_a ] );
        $this->assertStringContainsString( '2 of 3 players have a PDP for season 2025/26.', $past );
        $this->assertStringContainsString( 'tt-pdp-season-note', $past );
        $this->assertStringNotContainsString( 'Open new PDP file', $past );

        $now = $this->renderList( [ 'team_id' => (string) $this->team_a ] );
        $this->assertStringContainsString( '1 of 3 players have a PDP for the current season (2026/27).', $now );
        $this->assertStringNotContainsString( 'tt-pdp-season-note', $now );
        $this->assertStringContainsString( 'Open new PDP file', $now );
    }

    // ---- fixtures ---------------------------------------------------------

    /** @param list<int> $seasons seasons the player has a file in */
    private function player( int $team, string $first_name, array $seasons ): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_players", [
            'club_id'    => $club,
            'team_id'    => $team,
            'first_name' => $first_name,
            'last_name'  => 'Season',
            'status'     => 'active',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $this->players[ $first_name ] = $player_id;
        foreach ( $seasons as $season ) {
            $wpdb->insert( "{$p}tt_pdp_files", [
                'club_id'   => $club,
                'player_id' => $player_id,
                'season_id' => $season,
                'status'    => 'open',
            ] );
            $this->files[ $first_name . '@' . $season ] = (int) $wpdb->insert_id;
        }
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $p     = $wpdb->prefix;
        $coach = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $this->pdp_viewers[] = $coach;

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Season',
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
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function coverage( array $query ): array {
        return $this->get( '/talenttrack/v1/pdp-files/coverage', $query + [ 'per_page' => 100 ] );
    }

    /**
     * @param array<string,mixed> $query
     * @return array<string,mixed>
     */
    private function get( string $route, array $query ): array {
        $request = new WP_REST_Request( 'GET', $route );
        $request->set_query_params( $query );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) $response->get_data();
        return (array) ( $data['data'] ?? [] );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string> first names of every row, sorted
     */
    private function names( array $data ): array {
        return $this->firstNames( (array) ( $data['rows'] ?? [] ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string> first names of the rows that have a file, sorted
     */
    private function covered( array $data ): array {
        return $this->firstNames( array_filter(
            (array) ( $data['rows'] ?? [] ),
            static fn( $row ): bool => ! empty( ( (array) $row )['has_pdp'] )
        ) );
    }

    /**
     * @param array<int|string,mixed> $rows
     * @return list<string>
     */
    private function firstNames( array $rows ): array {
        $by_id = array_flip( $this->players );
        $names = [];
        foreach ( $rows as $row ) {
            $names[] = (string) ( $by_id[ (int) ( ( (array) $row )['player_id'] ?? 0 ) ] ?? '?' );
        }
        sort( $names );
        return $names;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function rowFor( array $data, string $first_name ): array {
        foreach ( (array) ( $data['rows'] ?? [] ) as $row ) {
            $row = (array) $row;
            if ( (int) ( $row['player_id'] ?? 0 ) === $this->players[ $first_name ] ) return $row;
        }
        $this->fail( "no coverage row for {$first_name}" );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,int>
     */
    private function summary( array $data ): array {
        $summary = (array) ( $data['summary'] ?? [] );
        return [
            'total'   => (int) ( $summary['total'] ?? -1 ),
            'covered' => (int) ( $summary['covered'] ?? -1 ),
        ];
    }

    /**
     * Renders `?tt_view=pdp` with the given `filter[...]` the way the
     * dispatcher does.
     *
     * @param array<string,string> $filter
     */
    private function renderList( array $filter ): string {
        $_GET['filter'] = $filter;
        $method = new \ReflectionMethod( FrontendPdpManageView::class, 'renderList' );
        $method->setAccessible( true );
        ob_start();
        $method->invoke( null, $this->admin, true );
        return (string) ob_get_clean();
    }
}
