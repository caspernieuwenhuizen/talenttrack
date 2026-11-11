<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * An activity in the recycle bin appears in neither activities list: not on
 * `GET /activities` with any combination of the played filter and the archive
 * state, and not on the rendered list. An archived activity that is not in the
 * recycle bin is still listed under `archived` and `all`.
 */
final class ActivitiesListTrashTest extends WP_UnitTestCase {

    private int $team = 0;
    private int $admin = 0;
    private int $live = 0;
    private int $archived = 0;
    private int $trashed = 0;
    private int $archived_trashed = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        $this->seedActivityTypes();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Trash O15-1' ] );
        $this->team  = (int) $wpdb->insert_id;
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        // Four played matches in the past, so the played filter holds them all.
        $now                    = current_time( 'mysql' );
        $this->live             = $this->activity( -20 );
        $this->archived         = $this->activity( -15 );
        $this->trashed          = $this->activity( -10 );
        $this->archived_trashed = $this->activity( -5 );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'archived_at' => $now ], [ 'id' => $this->archived ] );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'trashed_at' => $now ], [ 'id' => $this->trashed ] );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'archived_at' => $now, 'trashed_at' => $now ], [ 'id' => $this->archived_trashed ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @return array<string, array{0:string, 1:string}> archive state, played filter */
    public function combinations(): array {
        $out = [];
        foreach ( [ '', 'active', 'archived', 'all', 'trashed' ] as $state ) {
            foreach ( [ '', '0', '1' ] as $played ) {
                $out[ 'archived=' . ( $state === '' ? '(none)' : $state ) . ', played=' . ( $played === '' ? '(none)' : $played ) ] = [ $state, $played ];
            }
        }
        return $out;
    }

    /** @dataProvider combinations */
    public function test_the_rest_list_never_holds_a_trashed_activity( string $state, string $played ): void {
        $filter = [ 'team_id' => (string) $this->team ];
        if ( $state !== '' ) $filter['archived'] = $state;
        if ( $played !== '' ) $filter['played'] = $played;
        $ids = $this->restIds( [ 'filter' => $filter, 'per_page' => 100 ] );

        $this->assertNotContains( $this->trashed, $ids, 'a trashed activity' );
        $this->assertNotContains( $this->archived_trashed, $ids, 'an archived activity in the recycle bin' );

        // The recycle bin is not a state of the list: it reads as active. The
        // played drill counts live matches only (`PlayedMatch`), so it never
        // holds an archived one, whatever the archive state.
        $shows_live     = in_array( $state, [ '', 'active', 'all', 'trashed' ], true );
        $shows_archived = in_array( $state, [ 'archived', 'all' ], true ) && $played !== '1';
        $this->assertSame( $shows_live, in_array( $this->live, $ids, true ), 'the live activity' );
        $this->assertSame( $shows_archived, in_array( $this->archived, $ids, true ), 'the archived activity' );
    }

    public function test_the_legacy_include_archived_flag_holds_no_trashed_activity(): void {
        $ids = $this->restIds( [ 'filter' => [ 'team_id' => (string) $this->team ], 'include_archived' => '1', 'per_page' => 100 ] );

        $this->assertSame( [ $this->live, $this->archived ], $ids );
    }

    public function test_the_rendered_list_holds_no_trashed_activity(): void {
        foreach ( [ 'active', 'archived', 'all' ] as $state ) {
            foreach ( [ '', '1' ] as $played ) {
                $query = [ 'tt_view' => 'activities', 'team_id' => (string) $this->team, 'archived' => $state, 'date_from' => $this->day( -60 ), 'date_to' => $this->day( 1 ) ];
                if ( $played !== '' ) $query['played'] = $played;
                $html = $this->renderList( $query );
                foreach ( [ $this->trashed, $this->archived_trashed ] as $id ) {
                    $this->assertDoesNotMatchRegularExpression( '/[?&;]id=' . $id . '\b/', $html, "archived={$state}, played={$played}" );
                }
            }
        }

        $repo = new ActivitiesRepository();
        foreach ( [ 'active', 'archived', 'all' ] as $state ) {
            $ids = array_map( static fn( $row ): int => (int) $row->id, $repo->listForManageSurface( $this->team, '', $this->admin, '', '', false, $state ) );
            $this->assertNotContains( $this->trashed, $ids, "the page's rows, {$state}" );
            $this->assertNotContains( $this->archived_trashed, $ids, "the page's rows, {$state}" );
        }
    }

    public function test_both_lists_read_one_archive_clause(): void {
        $this->assertSame( 's.archived_at IS NULL AND s.trashed_at IS NULL', ActivitiesRepository::listArchiveClause( '', 's' ) );
        $this->assertSame( 's.archived_at IS NOT NULL AND s.trashed_at IS NULL', ActivitiesRepository::listArchiveClause( 'archived', 's' ) );
        $this->assertSame( 's.trashed_at IS NULL', ActivitiesRepository::listArchiveClause( 'all', 's' ) );
        $this->assertSame( ActivitiesRepository::listArchiveClause( 'active', 's' ), ActivitiesRepository::listArchiveClause( 'trashed', 's' ), 'never the recycle bin' );
    }

    // ---- helpers ---------------------------------------------------------------------

    /**
     * @param array<string,mixed> $params
     * @return list<int>
     */
    private function restIds( array $params ): array {
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action( 'rest_api_init' );
        $req = new \WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( $params );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }
        sort( $ids );
        return $ids;
    }

    /** @param array<string,string> $query */
    private function renderList( array $query ): string {
        $_GET = $query;
        $m    = new \ReflectionMethod( FrontendActivitiesManageView::class, 'renderList' );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null, $this->admin, true );
        return (string) ob_get_clean();
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', time() + $offset * DAY_IN_SECONDS );
    }

    private function activity( int $offset ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $this->team,
            'title'               => 'Game ' . $offset,
            'session_date'        => $this->day( $offset ),
            'activity_type_key'   => 'game',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedActivityTypes(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $present = (array) $wpdb->get_col( "SELECT name FROM {$p}tt_lookups WHERE lookup_type = 'activity_type' AND club_id = 1" );
        $sort    = 0;
        foreach ( [ 'training', 'game', 'tournament', 'other', 'meeting' ] as $name ) {
            $sort++;
            if ( in_array( $name, $present, true ) ) continue;
            $wpdb->insert( "{$p}tt_lookups", [
                'club_id'     => 1,
                'lookup_type' => 'activity_type',
                'name'        => $name,
                'sort_order'  => $sort,
            ] );
        }
    }
}
