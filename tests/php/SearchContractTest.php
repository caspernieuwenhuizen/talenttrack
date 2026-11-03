<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\DemoData\DemoMode;

/**
 * #4671 — the response shapes of `GET /search`, demo scope on every kind,
 * and the settings the head of development is offered.
 */
final class SearchContractTest extends WP_UnitTestCase {

    use SearchFixtures;

    public function set_up(): void {
        parent::set_up();
        $this->searchSetUp();
    }

    public function tear_down(): void {
        DemoMode::clearOverride();
        $this->searchTearDown();
        parent::tear_down();
    }

    public function test_without_page_the_header_box_shape_is_unchanged(): void {
        $res  = $this->searchRequest( $this->personas['admin'], [ 'q' => 'searchside' ] );
        $data = (array) $res->get_data();
        $this->assertSame( 200, $res->get_status() );
        $this->assertSame( [ 'results' ], array_keys( $data ), 'one key, as before' );
        $this->assertLessThanOrEqual( 10, count( $data['results'] ) );
        $this->assertNotEmpty( $data['results'] );
        foreach ( $data['results'] as $row ) {
            $this->assertSame( [ 'type', 'id', 'label', 'sublabel', 'url' ], array_keys( $row ) );
            $this->assertContains( $row['type'], [ 'view', 'player', 'team', 'activity' ] );
        }
    }

    public function test_with_page_the_paged_shape(): void {
        $res = $this->searchRequest( $this->personas['admin'], [ 'q' => 'jose', 'page' => '1' ] );
        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data()['data'];
        $this->assertSame( [ 'items', 'counts', 'understood', 'total' ], array_keys( $data ) );
        $this->assertSame( array_sum( $data['counts'] ), $data['total'] );
        $player = null;
        foreach ( $data['items'] as $item ) {
            if ( $item['type'] === 'player' && (int) $item['id'] === $this->players['jose'] ) $player = $item;
        }
        $this->assertNotNull( $player );
        foreach ( [ 'type', 'id', 'label', 'sublabel', 'url', 'player_id', 'team_id', 'actions' ] as $key ) {
            $this->assertArrayHasKey( $key, $player );
        }
        $this->assertLessThanOrEqual( 2, count( $player['actions'] ) );
        foreach ( $player['actions'] as $action ) {
            $this->assertSame( [ 'key', 'label', 'url' ], array_keys( $action ) );
        }
        $this->assertStringNotContainsString( 'head_coach', (string) wp_json_encode( $data ), 'no role names in the payload' );
    }

    public function test_the_actions_follow_what_the_viewer_may_do(): void {
        $coach = $this->actionKeys( $this->personas['coach'], $this->players['jose'] );
        $this->assertSame( [ 'new_evaluation', 'goals' ], $coach, 'the team coach evaluates and sets goals' );
        $hod = $this->actionKeys( $this->personas['hod'], $this->players['jose'] );
        $this->assertSame( 'profile', $hod[0] ?? '', 'an academy-wide reader opens the profile' );
        $parent = $this->actionKeys( $this->personas['parent'], $this->players['jose'] );
        $this->assertNotContains( 'new_evaluation', $parent );
    }

    public function test_paging_walks_the_ranked_list(): void {
        for ( $i = 0; $i < 6; $i++ ) $this->player( $this->team_a, 'Pagetest', 'Number' . $i, 20 + $i );
        $one = $this->paged( $this->personas['admin'], 'pagetest', [ 'per_page' => '4', 'types' => 'player' ] );
        $two = $this->paged( $this->personas['admin'], 'pagetest', [ 'per_page' => '4', 'page' => '2', 'types' => 'player' ] );
        $this->assertSame( 6, $one['total'] );
        $this->assertCount( 4, $one['items'] );
        $this->assertCount( 2, $two['items'] );
        $this->assertSame( [], array_intersect( array_column( $one['items'], 'id' ), array_column( $two['items'], 'id' ) ) );
    }

    public function test_demo_records_stay_in_demo_mode_and_real_ones_out_of_it(): void {
        global $wpdb;
        $demo_player = $this->player( $this->team_a, 'Demofolk', 'Tagged', 31 );
        $real_player = $this->player( $this->team_a, 'Demofolk', 'Real', 32 );
        $demo_team   = $this->team( 'Demofolk United', 'O14' );
        $demo_act    = $this->activity( $this->team_a, 'Demofolk drill', '2020-01-16' );
        foreach ( [ [ 'player', $demo_player ], [ 'team', $demo_team ], [ 'activity', $demo_act ] ] as [ $type, $id ] ) {
            $wpdb->insert( "{$wpdb->prefix}tt_demo_tags", [ 'club_id' => 1, 'batch_id' => 'search-test', 'entity_type' => $type, 'entity_id' => $id ] );
        }
        $admin = $this->personas['admin'];

        DemoMode::overrideForRequest( DemoMode::OFF );
        $off = $this->paged( $admin, 'demofolk' );
        $this->assertSame( [ $real_player ], $this->idsOf( $off, 'player' ), 'outside demo mode no demo player' );
        $this->assertSame( [], $this->idsOf( $off, 'team' ) );
        $this->assertSame( [], $this->idsOf( $off, 'activity' ) );

        DemoMode::overrideForRequest( DemoMode::ON );
        $on = $this->paged( $admin, 'demofolk' );
        $this->assertSame( [ $demo_player ], $this->idsOf( $on, 'player' ), 'inside demo mode no real player' );
        $this->assertSame( [ $demo_team ], $this->idsOf( $on, 'team' ) );
        $this->assertSame( [ $demo_act ], $this->idsOf( $on, 'activity' ) );
    }

    /**
     * Owner decision, 3 November 2026: the head of development does not get
     * the Configuration tile, so search never lists a setting reached only
     * through it.
     */
    public function test_the_head_of_development_gets_no_setting_reached_only_through_configuration(): void {
        $entries = \TT\Shared\Configuration\SettingsRegistry::navigableEntriesFor( $this->personas['hod'] );
        foreach ( $entries as $entry ) {
            $this->assertStringNotContainsString( 'tt_view=configuration', (string) $entry['url'], "{$entry['key']} is reached through Configuration" );
        }
        $data = $this->paged( $this->personas['hod'], 'a', [ 'types' => 'setting', 'per_page' => '50' ] );
        foreach ( $data['items'] as $item ) {
            $this->assertStringNotContainsString( 'tt_view=configuration', (string) $item['url'] );
        }

        $admin_urls = array_column( \TT\Shared\Configuration\SettingsRegistry::navigableEntriesFor( $this->personas['admin'] ), 'url' );
        $this->assertNotEmpty( array_filter( $admin_urls, static fn( string $u ): bool => strpos( $u, 'tt_view=configuration' ) !== false ), 'Precondition: an admin is offered Configuration entries.' );
    }

    /** @return list<string> */
    private function actionKeys( int $user_id, int $player_id ): array {
        foreach ( $this->paged( $user_id, 'jose', [ 'types' => 'player' ] )['items'] as $item ) {
            if ( (int) $item['id'] === $player_id ) return array_column( $item['actions'], 'key' );
        }
        return [];
    }
}
