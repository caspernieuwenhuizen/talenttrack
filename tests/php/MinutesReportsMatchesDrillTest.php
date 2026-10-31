<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Analytics\Reports\MinutesQuery;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\FrontendActivitiesManageView;
use TT\Shared\Frontend\FrontendStandardReportsView;

/**
 * #4507 — "Matches played" on the minutes reports opens the activities list
 * of exactly the matches it counted: the report's window, every match type
 * (a tournament day included), past matches expanded, nothing else.
 *
 * Each test renders the report, follows the tile's own `href` into the
 * rendered activities list, and compares what the list shows with the
 * number on the tile. A fixture for every row that must stay out: cancelled,
 * future, today but not yet played, a training, outside the window, archived.
 */
final class MinutesReportsMatchesDrillTest extends WP_UnitTestCase {

    /** @var int */
    private $team = 0;

    /** @var int */
    private $admin = 0;

    /** @var string */
    private $from = '';

    /** @var string */
    private $to = '';

    /** @var list<int> */
    private $played = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        $this->seedActivityTypes();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O14-1' ] );
        $this->team  = (int) $wpdb->insert_id;
        $this->admin = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        $this->from = $this->day( -60 );
        $this->to   = $this->day( 30 );

        $this->played = [
            $this->activity( 'game', -20, 'planned' ),         // past, so played
            $this->activity( 'match', -10, 'completed' ),      // legacy match key
            $this->activity( 'tournament', -5, 'completed' ),  // a tournament day
        ];
        $this->activity( 'game', -3, 'cancelled' );
        $this->activity( 'game', 5, 'planned' );
        $this->activity( 'game', 0, 'planned' );                // today, not yet played
        $this->activity( 'training', -2, 'completed' );
        $this->activity( 'game', -90, 'completed' );            // outside the window
        $archived = $this->activity( 'game', -15, 'completed' );
        $wpdb->update( "{$wpdb->prefix}tt_activities", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $archived ] );
    }

    public function tear_down(): void {
        $_GET = [];
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_minutes_share_matches_played_opens_exactly_those_matches(): void {
        $html = $this->renderReport( 'renderMinutesShare' );
        $tile = $this->playedTile( $html );

        $this->assertSame( (string) count( $this->played ), $tile['value'], 'the tile counts the three played matches' );
        $this->assertSame( $this->from, $tile['query']['date_from'] ?? '', 'the drill carries the report window' );
        $this->assertSame( $this->to, $tile['query']['date_to'] ?? '' );

        $listed = $this->listedIds( $tile['query'] );
        sort( $listed );
        $expected = $this->played;
        sort( $expected );
        $this->assertSame( $expected, $listed, 'the list shows exactly the counted matches, tournament day included' );
        $this->assertSame( (int) $tile['value'], count( $listed ), 'the tile number equals the destination count' );
    }

    public function test_minutes_share_keeps_its_window_on_the_distribution_link(): void {
        $html = $this->renderReport( 'renderMinutesShare' );
        $this->assertSame( 1, preg_match( '/href="([^"]*team-minutes-distribution[^"]*)"/', $html, $m ), 'the distribution report stays reachable' );
        parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $q );
        $this->assertSame( $this->from, $q['from'] ?? '' );
        $this->assertSame( $this->to, $q['to'] ?? '' );
    }

    public function test_the_distribution_matches_tile_opens_the_played_count(): void {
        $html = $this->renderReport( 'renderTeamMinutesDistribution' );
        $tile = $this->playedTile( $html );

        $played = ( new MinutesQuery() )->matchCountsForTeam( $this->team, $this->from, $this->to )['played'];
        $this->assertSame( count( $this->played ), $played );
        $this->assertSame( $this->from, $tile['query']['date_from'] ?? '' );
        $this->assertSame( $this->to, $tile['query']['date_to'] ?? '' );
        $this->assertCount( $played, $this->listedIds( $tile['query'] ), 'the list shows the played count the report states' );
    }

    public function test_a_date_drill_lands_with_past_activities_expanded(): void {
        $html = $this->renderList( [ 'tt_view' => 'activities', 'team_id' => (string) $this->team, 'date_from' => $this->from, 'date_to' => $this->to ] );
        $this->assertStringNotContainsString( 'data-past-state="collapsed"', $html );

        $collapsed = $this->renderList( [ 'tt_view' => 'activities', 'team_id' => (string) $this->team, 'date_from' => $this->from, 'date_to' => $this->to, 'include_past' => '0' ] );
        $this->assertStringContainsString( 'data-past-state="collapsed"', $collapsed, 'the toggle can still hide them' );
    }

    public function test_the_drill_line_names_the_count_and_window(): void {
        $html = $this->renderList( [ 'tt_view' => 'activities', 'team_id' => (string) $this->team, 'played' => '1', 'date_from' => $this->from, 'date_to' => $this->to ] );
        $this->assertStringContainsString( '3 played matches', $html );
    }

    public function test_the_rest_list_takes_the_same_filter(): void {
        $result = ( new ActivitiesRepository() )->searchForRest( [
            'per_page' => 100,
            'filter'   => [ 'team_id' => $this->team, 'played' => 1, 'date_from' => $this->from, 'date_to' => $this->to ],
        ] );
        $ids = array_map( static function ( $r ): int { return (int) $r->id; }, (array) $result['rows'] );
        sort( $ids );
        $expected = $this->played;
        sort( $expected );
        $this->assertSame( $expected, $ids );
    }

    // Fixtures

    private function renderReport( string $method ): string {
        $_GET = [ 'tt_view' => 'standard-report', 'team_id' => (string) $this->team, 'from' => $this->from, 'to' => $this->to ];
        $m    = new \ReflectionMethod( FrontendStandardReportsView::class, $method );
        $m->setAccessible( true );
        ob_start();
        $m->invoke( null );
        return (string) ob_get_clean();
    }

    /**
     * The KPI tile that drills into the played-matches list.
     *
     * @return array{value:string,query:array<string,string>}
     */
    private function playedTile( string $html ): array {
        $found = preg_match( '/<a class="[^"]*tt-kpi--link" href="([^"]*played=1[^"]*)"[^>]*>.*?<span class="tt-kpi__val">([^<]*)<\/span>/s', $html, $m );
        $this->assertSame( 1, $found, 'a Matches tile links to the played-matches list' );
        parse_str( (string) wp_parse_url( html_entity_decode( $m[1] ), PHP_URL_QUERY ), $query );
        return [ 'value' => trim( $m[2] ), 'query' => array_map( 'strval', $query ) ];
    }

    /**
     * @param array<string,string> $query
     * @return list<int> Activity ids with a card on the rendered list.
     */
    private function listedIds( array $query ): array {
        $html = $this->renderList( $query );
        $this->assertStringNotContainsString( 'data-past-state="collapsed"', $html, 'nothing is folded away' );
        preg_match_all( '/tt_view=activities(?:&|&#038;|&amp;)id=(\d+)/', $html, $m );
        return array_values( array_unique( array_map( 'intval', $m[1] ) ) );
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

    private function activity( string $type, int $offset, string $status ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $this->team,
            'title'               => ucfirst( $type ) . ' ' . $offset,
            'session_date'        => $this->day( $offset ),
            'activity_type_key'   => $type,
            'activity_status_key' => $status,
            'plan_state'          => $status === 'cancelled' ? 'cancelled' : ( $status === 'completed' ? 'completed' : 'scheduled' ),
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
