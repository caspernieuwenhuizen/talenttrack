<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Frontend\EntityAnalyticsTabRenderer;
use TT\Shared\Frontend\Components\BackLabelResolver;
use TT\Shared\Frontend\Components\BackLink;

/**
 * #4859 — the explorer's back pill names where the reader came from.
 *
 * The KPI cards on the Analytics page, academy-wide and with a player or
 * team selected, link to the explorer through `BackLink::appendTo()`. The
 * label for an Analytics page with a player or team selected names that
 * record, through the same visibility check as its detail page; a record
 * the viewer may not see falls back to "Back to Analytics".
 */
final class ExploreBackPillTest extends WP_UnitTestCase {

    private int $team   = 0;
    private int $player = 0;
    private int $admin  = 0;

    /** @var array<string,mixed> */
    private array $server_was = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->server_was = $_SERVER;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Pill United U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'team_id' => $this->team, 'first_name' => 'Pieter', 'last_name' => 'Pillman', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;
        $this->admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
    }

    public function tear_down(): void {
        $_SERVER = $this->server_was;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_from_a_selected_player_the_pill_names_the_player(): void {
        wp_set_current_user( $this->admin );
        $this->assertSame( 'Back to Pieter Pillman', BackLabelResolver::labelFor( $this->analyticsUrl( 'player', $this->player ) ) );
    }

    public function test_from_a_selected_team_the_pill_names_the_team(): void {
        wp_set_current_user( $this->admin );
        $this->assertSame( 'Back to Pill United U13', BackLabelResolver::labelFor( $this->analyticsUrl( 'team', $this->team ) ) );
    }

    public function test_a_record_the_viewer_may_not_see_falls_back_to_analytics(): void {
        // A signed-in account with no grant on this player or team.
        $outsider = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $outsider );

        $this->assertSame( 'Back to Analytics', BackLabelResolver::labelFor( $this->analyticsUrl( 'player', $this->player ) ), 'no player name for a viewer who may not see the player' );
        $this->assertSame( 'Back to Analytics', BackLabelResolver::labelFor( $this->analyticsUrl( 'team', $this->team ) ), 'no team name for a viewer who may not see the team' );
    }

    public function test_the_academy_view_and_an_unnamed_type_say_back_to_analytics(): void {
        wp_set_current_user( $this->admin );
        $this->assertSame( 'Back to Analytics', BackLabelResolver::labelFor( home_url( '/?tt_view=analytics' ) ) );
        $this->assertSame( 'Back to Analytics', BackLabelResolver::labelFor( $this->analyticsUrl( 'season', 3 ) ) );
    }

    public function test_the_cards_on_a_selected_player_carry_the_page_as_back_target(): void {
        wp_set_current_user( $this->admin );
        $page = $this->analyticsUrl( 'player', $this->player );
        $_SERVER['REQUEST_URI'] = (string) wp_parse_url( $page, PHP_URL_PATH ) . '?' . (string) wp_parse_url( $page, PHP_URL_QUERY );

        ob_start();
        EntityAnalyticsTabRenderer::render( 'player', $this->player );
        $html = (string) ob_get_clean();

        preg_match_all( '/href="([^"]*tt_view=explore[^"]*)"/', $html, $m );
        if ( $m[1] === [] ) {
            $this->markTestSkipped( 'No player-scoped KPI is registered for this viewer.' );
        }
        foreach ( $m[1] as $href ) {
            $query = (string) wp_parse_url( html_entity_decode( $href ), PHP_URL_QUERY );
            parse_str( $query, $args );
            $back = (string) ( $args[ BackLink::PARAM ] ?? '' );
            $this->assertStringContainsString( 'tt_view=analytics', $back, 'the explorer link carries the Analytics page' );
            $this->assertStringContainsString( 'entity_id=' . $this->player, $back, 'with the player selected' );
            $this->assertSame( 'Back to Pieter Pillman', BackLabelResolver::labelFor( $back ) );
        }
    }

    private function analyticsUrl( string $type, int $id ): string {
        return add_query_arg( [ 'tt_view' => 'analytics', 'entity_type' => $type, 'entity_id' => $id ], home_url( '/' ) );
    }
}
