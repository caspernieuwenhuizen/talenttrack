<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;

/**
 * #4185 — a "Match" type filter matches rows stored under the legacy
 * `match` key as well as the canonical `game`.
 *
 * The report drills asked the activities list for `match`, an exact filter
 * found nothing because matches are stored as `game`, and the list came up
 * empty beside a KPI that said eight. Tournament-created fixtures still
 * write `match`, so the fix is not "stop sending match" alone: whichever
 * of the two keys is asked for, both are matched. A tournament stays its
 * own type.
 */
final class ActivityTypeLegacyMatchFilterTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    private int $game       = 0;
    private int $legacy     = 0;
    private int $tournament = 0;
    private int $training   = 0;

    private int $admin = 0;

    public function set_up(): void {
        parent::set_up();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();
        $this->seedActivityTypes();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'Legacy O14-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->game       = $this->activity( 'game', 'O14 v Hedel' );
        $this->legacy     = $this->activity( 'match', 'O14 toernooiwedstrijd' );
        $this->tournament = $this->activity( 'tournament', 'O14 toernooi' );
        $this->training   = $this->activity( 'training', 'O14 training' );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['activity_type_key'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_game_and_match_expand_to_both_keys(): void {
        $this->assertSame( [ 'game', 'match' ], ActivityTypeKey::filterKeys( 'game' ) );
        $this->assertSame( [ 'game', 'match' ], ActivityTypeKey::filterKeys( 'match' ) );
    }

    public function test_other_keys_match_only_themselves(): void {
        $this->assertSame( [ 'tournament' ], ActivityTypeKey::filterKeys( 'tournament' ) );
        $this->assertSame( [ 'training' ], ActivityTypeKey::filterKeys( 'training' ) );
        $this->assertSame( [ 'meeting' ], ActivityTypeKey::filterKeys( 'meeting' ) );
    }

    public function test_the_rendered_list_includes_legacy_match_rows(): void {
        foreach ( [ 'game', 'match' ] as $asked ) {
            $ids = $this->manageIds( $asked );

            $this->assertContains( $this->game, $ids, "Filter '{$asked}' must list the game row." );
            $this->assertContains( $this->legacy, $ids, "Filter '{$asked}' must list the legacy match row." );
            $this->assertNotContains( $this->tournament, $ids, 'A tournament is its own type.' );
            $this->assertNotContains( $this->training, $ids );
        }
    }

    public function test_rest_game_filter_includes_legacy_match_rows(): void {
        wp_set_current_user( $this->admin );
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/activities' );
        $req->set_query_params( [
            'filter'   => [ 'team_id' => $this->team, 'activity_type_key' => 'game' ],
            'per_page' => 100,
        ] );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );

        $ids = [];
        foreach ( (array) ( $res->get_data()['data']['rows'] ?? [] ) as $row ) {
            $ids[] = (int) ( (array) $row )['id'];
        }

        $this->assertContains( $this->game, $ids );
        $this->assertContains( $this->legacy, $ids );
        $this->assertNotContains( $this->tournament, $ids );
        $this->assertNotContains( $this->training, $ids );
    }

    public function test_an_inbound_match_key_reads_as_game(): void {
        $method = new \ReflectionMethod( FrontendActivitiesManageView::class, 'requestTypeFilter' );
        $method->setAccessible( true );

        $_GET['activity_type_key'] = 'match';
        $this->assertSame( 'game', $method->invoke( null ) );

        $_GET['activity_type_key'] = 'tournament';
        $this->assertSame( 'tournament', $method->invoke( null ) );

        unset( $_GET['activity_type_key'] );
        $this->assertSame( '', $method->invoke( null ) );
    }

    /** @return list<int> */
    private function manageIds( string $type ): array {
        $rows = ( new ActivitiesRepository() )->listForManageSurface( $this->team, $type, $this->admin );
        return array_values( array_map( static fn( $row ): int => (int) $row->id, $rows ) );
    }

    private function seedActivityTypes(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $present = (array) $wpdb->get_col( $wpdb->prepare(
            "SELECT name FROM {$p}tt_lookups WHERE lookup_type = 'activity_type' AND club_id = %d",
            $this->club
        ) );
        $sort = 0;
        foreach ( [ 'training', 'game', 'tournament', 'other', 'meeting' ] as $name ) {
            $sort++;
            if ( in_array( $name, $present, true ) ) continue;
            $wpdb->insert( "{$p}tt_lookups", [
                'club_id'     => $this->club,
                'lookup_type' => 'activity_type',
                'name'        => $name,
                'sort_order'  => $sort,
            ] );
        }
    }

    private function activity( string $type, string $title ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $this->team,
            'title'               => $title,
            'session_date'        => gmdate( 'Y-m-d', strtotime( '+3 days' ) ),
            'activity_type_key'   => $type,
            'activity_status_key' => 'planned',
        ] );
        return (int) $wpdb->insert_id;
    }
}
