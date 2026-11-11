<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #5118 — an ordinary list never shows an item in the recycle bin, whatever
 * archive state it is asked for. `GET /goals`, `GET /evaluations` and
 * `GET /holidays` read `filter[archived]=trashed` as `active`, as
 * `GET /activities` does since #5103; the recycle-bin screen still lists
 * the binned items for whoever may open it.
 *
 * Every list is read by SearchFixtures' head coach of team A, a non-admin
 * with list scope through the auth bridge (goals and evaluations on the
 * team, the global holidays read). The wp-admin lists, which take the state
 * from `?tt_view=`, follow the same rule.
 */
final class ListsHideRecycleBinTest extends WP_UnitTestCase {

    use SearchFixtures;

    /** @var array<string,int> */
    private array $goals = [];

    /** @var array<string,int> */
    private array $evaluations = [];

    /** @var array<string,int> */
    private array $holidays = [];

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $now  = current_time( 'mysql' );

        foreach ( [ 'live', 'archived', 'trashed', 'archived_trashed' ] as $state ) {
            $wpdb->insert( "{$p}tt_goals", [
                'club_id' => $club, 'player_id' => $this->players['besten'], 'title' => 'Bin goal ' . $state,
                'status' => 'in_progress', 'created_by' => 0,
            ] );
            $this->goals[ $state ] = (int) $wpdb->insert_id;

            $wpdb->insert( "{$p}tt_evaluations", [
                'club_id' => $club, 'player_id' => $this->players['besten'], 'coach_id' => 1,
                'eval_date' => '2020-02-02', 'notes' => '',
            ] );
            $this->evaluations[ $state ] = (int) $wpdb->insert_id;

            $wpdb->insert( "{$p}tt_holidays", [
                'club_id' => $club, 'uuid' => wp_generate_uuid4(), 'name' => 'Bin holiday ' . $state,
                'start_date' => '2031-12-20', 'end_date' => '2032-01-03',
            ] );
            $this->holidays[ $state ] = (int) $wpdb->insert_id;
        }
        foreach ( [ 'tt_goals' => $this->goals, 'tt_evaluations' => $this->evaluations, 'tt_holidays' => $this->holidays ] as $table => $ids ) {
            $wpdb->update( "{$p}{$table}", [ 'archived_at' => $now ], [ 'id' => $ids['archived'] ] );
            $wpdb->update( "{$p}{$table}", [ 'trashed_at' => $now ], [ 'id' => $ids['trashed'] ] );
            $wpdb->update( "{$p}{$table}", [ 'archived_at' => $now, 'trashed_at' => $now ], [ 'id' => $ids['archived_trashed'] ] );
        }
    }

    public function tear_down(): void {
        $this->searchTearDown();
        parent::tear_down();
    }

    /** @return array<string, array{0:string}> */
    public function states(): array {
        $out = [];
        foreach ( [ '', 'active', 'archived', 'all', 'trashed' ] as $state ) {
            $out[ $state === '' ? '(none)' : $state ] = [ $state ];
        }
        return $out;
    }

    /** @dataProvider states */
    public function test_no_list_holds_a_binned_item( string $state ): void {
        foreach ( [
            'goals'       => [ $this->personas['coach'], '/goals', [ 'player_id' => (string) $this->players['besten'] ], $this->goals ],
            'evaluations' => [ $this->personas['coach'], '/evaluations', [ 'player_id' => (string) $this->players['besten'] ], $this->evaluations ],
            'holidays'    => [ $this->personas['coach'], '/holidays', [], $this->holidays ],
        ] as $what => [ $user, $route, $filter, $ids ] ) {
            if ( $state !== '' ) $filter['archived'] = $state;
            $listed = $this->listIds( $user, $route, $filter );
            $this->assertNotContains( $ids['trashed'], $listed, "{$what}, archived={$state}: an item in the recycle bin" );
            $this->assertNotContains( $ids['archived_trashed'], $listed, "{$what}, archived={$state}: an archived item in the recycle bin" );
        }
    }

    public function test_trashed_answers_the_same_rows_as_active(): void {
        foreach ( [
            'goals'       => [ $this->personas['coach'], '/goals', [ 'player_id' => (string) $this->players['besten'] ], $this->goals ],
            'evaluations' => [ $this->personas['coach'], '/evaluations', [ 'player_id' => (string) $this->players['besten'] ], $this->evaluations ],
            'holidays'    => [ $this->personas['coach'], '/holidays', [], $this->holidays ],
        ] as $what => [ $user, $route, $filter, $ids ] ) {
            $active  = $this->listIds( $user, $route, $filter + [ 'archived' => 'active' ] );
            $trashed = $this->listIds( $user, $route, $filter + [ 'archived' => 'trashed' ] );
            sort( $active );
            sort( $trashed );
            $this->assertSame( $active, $trashed, $what );
            $this->assertContains( $ids['live'], $active, "{$what}: precondition, the live item is listed" );
        }
    }

    public function test_the_recycle_bin_screen_still_lists_them(): void {
        wp_set_current_user( $this->personas['admin'] );
        $bin = ( new ArchiveRepository() )->trashedAcrossEntities();
        foreach ( [ 'goal' => $this->goals, 'evaluation' => $this->evaluations, 'holiday' => $this->holidays ] as $entity => $ids ) {
            $binned = array_map( static fn( array $row ): int => (int) $row['id'], $bin[ $entity ] ?? [] );
            $this->assertContains( $ids['trashed'], $binned, $entity );
            $this->assertContains( $ids['archived_trashed'], $binned, $entity );
        }
    }

    /** The wp-admin goals list, asked for `?tt_view=trashed`, lists what Active lists. */
    public function test_a_wp_admin_list_never_shows_a_binned_row(): void {
        wp_set_current_user( $this->personas['admin'] );
        $this->resetSearchCaches();
        $html = [];
        foreach ( [ 'trashed', 'active', 'all' ] as $state ) {
            $_GET['tt_view'] = $state;
            ob_start();
            \TT\Modules\Goals\Admin\GoalsPage::render_page();
            $html[ $state ] = (string) ob_get_clean();
        }
        unset( $_GET['tt_view'] );

        foreach ( $html as $state => $page ) {
            $this->assertStringNotContainsString( 'Bin goal trashed', $page, "tt_view={$state}" );
            $this->assertStringNotContainsString( 'Bin goal archived_trashed', $page, "tt_view={$state}" );
        }
        $this->assertStringContainsString( 'Bin goal live', $html['trashed'], 'tt_view=trashed reads as active' );
        $this->assertStringNotContainsString( 'Bin goal archived', str_replace( 'Bin goal archived_trashed', '', $html['trashed'] ), 'not as all' );
    }

    public function test_the_list_view_reads_trashed_as_active(): void {
        $this->assertSame( 'active', ArchiveRepository::listView( 'trashed' ) );
        $this->assertSame( 'active', ArchiveRepository::listView( 'nonsense' ) );
        $this->assertSame( 'archived', ArchiveRepository::listView( 'archived' ) );
        $this->assertSame( ArchiveRepository::filterClause( 'active', 'g' ), ArchiveRepository::listViewClause( 'trashed', 'g' ) );
    }

    /**
     * @param array<string,string> $filter
     * @return list<int>
     */
    private function listIds( int $user_id, string $route, array $filter ): array {
        wp_set_current_user( $user_id );
        $this->resetSearchCaches();
        $request = new \WP_REST_Request( 'GET', '/talenttrack/v1' . $route );
        $request->set_query_params( [ 'filter' => $filter, 'per_page' => '100' ] );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status(), $route . ': ' . (string) wp_json_encode( $response->get_data() ) );
        $data = (array) $response->get_data();
        $body = isset( $data['data'] ) ? (array) $data['data'] : $data;
        return array_map( static fn( $row ): int => (int) ( ( (array) $row )['id'] ?? 0 ), (array) ( $body['rows'] ?? [] ) );
    }
}
