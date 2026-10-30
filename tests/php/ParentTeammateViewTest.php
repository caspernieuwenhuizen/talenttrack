<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Shared\Frontend\DashboardShortcode;
use TT\Shared\Frontend\FrontendMyTeamView;

/**
 * #4271 — a parent on their child's My team page gets the roster, not the
 * teammate card. The roster names were links to `?tt_view=teammate`, which a
 * parent (no own player row) was refused with "only available for users
 * linked to a player record" — wrong, and a dead end. Players keep both.
 */
final class ParentTeammateViewTest extends WP_UnitTestCase {

    /** @var int */
    private $team;

    /** @var object */
    private $child;

    /** @var object */
    private $mate;

    /** @var int */
    private $parent;

    /** @var int */
    private $childUser;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO13-1' ] );
        $this->team = (int) $wpdb->insert_id;

        $this->childUser = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->child     = $this->makePlayer( 'Daan', 'Kind', $this->childUser );
        $this->mate      = $this->makePlayer( 'Sem', 'Teamgenoot', 0 );

        $this->parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( $wpdb->prefix . 'tt_player_parents', [
            'club_id'        => 1,
            'player_id'      => (int) $this->child->id,
            'parent_user_id' => $this->parent,
            'is_primary'     => 1,
        ] );
    }

    public function tear_down(): void {
        unset( $_GET['player_id'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_sees_the_roster_without_teammate_links(): void {
        wp_set_current_user( $this->parent );

        $html = $this->renderMyTeam( $this->child );

        $this->assertStringContainsString( 'Sem', $html, 'the parent still sees who their child plays with' );
        $this->assertStringNotContainsString( 'tt_view=teammate', $html );
        $this->assertDoesNotMatchRegularExpression( '/<a\b[^>]*class="tt-mt-mate"/', $html );
        $this->assertMatchesRegularExpression( '/<span\b[^>]*class="tt-mt-mate"/', $html );
    }

    public function test_a_player_keeps_the_teammate_links(): void {
        wp_set_current_user( $this->childUser );

        $html = $this->renderMyTeam( $this->child );

        $this->assertMatchesRegularExpression( '/<a href="[^"]*tt_view=teammate[^"]*player_id=' . (int) $this->mate->id . '/', $html );
    }

    public function test_a_parent_opening_a_teammate_gets_the_players_only_notice(): void {
        wp_set_current_user( $this->parent );
        $_GET['player_id'] = (string) (int) $this->mate->id;

        [ $handled, $html ] = $this->dispatch( 'teammate', null );

        $this->assertTrue( $handled );
        $this->assertStringContainsString( 'Teammate profiles are visible to players only.', $html );
        $this->assertStringNotContainsString( 'linked to a player record', $html );
        $this->assertStringContainsString( 'tt-breadcrumbs', $html, 'the notice keeps the breadcrumb chain' );
        $this->assertStringNotContainsString( 'Teamgenoot', $html, 'nothing about the other child is shown' );
    }

    public function test_a_player_opening_a_teammate_gets_the_card(): void {
        wp_set_current_user( $this->childUser );
        $_GET['player_id'] = (string) (int) $this->mate->id;

        [ $handled, $html ] = $this->dispatch( 'teammate', $this->child );

        $this->assertTrue( $handled );
        $this->assertStringNotContainsString( 'Teammate profiles are visible to players only.', $html );
        $this->assertStringNotContainsString( 'linked to a player record', $html );
        $this->assertStringContainsString( 'Sem', $html );
    }

    private function renderMyTeam( object $player ): string {
        ob_start();
        FrontendMyTeamView::render( $player );
        return (string) ob_get_clean();
    }

    /** @return array{0:bool,1:string} */
    private function dispatch( string $view, ?object $player ): array {
        $m = new ReflectionMethod( DashboardShortcode::class, 'dispatchMeView' );
        $m->setAccessible( true );
        ob_start();
        $handled = (bool) $m->invoke( null, $view, $player );
        return [ $handled, (string) ob_get_clean() ];
    }

    private function makePlayer( string $first, string $last, int $wp_user_id ): object {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $this->team,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'active',
            'wp_user_id' => $wp_user_id,
        ] );
        return $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_players WHERE id = %d",
            (int) $wpdb->insert_id
        ) );
    }
}
