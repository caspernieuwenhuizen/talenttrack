<?php
namespace TT\Tests\Php;

use ReflectionMethod;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AuthorizationModule;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\Components\EmptyStateCard;
use TT\Shared\Frontend\FrontendActivitiesManageView;
use TT\Shared\Frontend\FrontendPlayerDetailView;
use TT\Shared\Frontend\FrontendTeamsManageView;

/**
 * #4503 — the empty-state card decides its button with the matrix-aware
 * check, so a right held only through the authorization matrix shows the
 * same "create first" button an empty list offers everyone else. Every case
 * runs with the `user_has_cap` bridge unhooked, as on a matrix-inactive
 * install, which is where a raw `current_user_can()` answered differently.
 */
final class EmptyStateCardMatrixTest extends WP_UnitTestCase {

    private const BRIDGE = [ AuthorizationModule::class, 'filterUserHasCap' ];

    /** @var int|false */
    private $bridge_priority = false;

    public function set_up(): void {
        parent::set_up();
        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $this->bridge_priority = has_filter( 'user_has_cap', self::BRIDGE );
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
    }

    public function tear_down(): void {
        remove_filter( 'user_has_cap', self::BRIDGE, 10 );
        if ( $this->bridge_priority !== false ) {
            add_filter( 'user_has_cap', self::BRIDGE, (int) $this->bridge_priority, 4 );
        }
        $_GET = [];
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // The card itself

    public function test_the_card_shows_its_button_on_a_matrix_only_grant(): void {
        $html = $this->card( $this->matrixOnlyHod( 'tt_edit_activities' ), 'tt_edit_activities' );
        $this->assertStringContainsString( 'tt-empty-state__cta', $html );
    }

    public function test_the_card_drops_its_button_without_the_cap_or_the_grant(): void {
        $nobody = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $this->assertFalse( AuthorizationService::userCanOrMatrix( $nobody, 'tt_edit_activities' ), 'fixture sanity' );

        $html = $this->card( $nobody, 'tt_edit_activities' );
        $this->assertStringContainsString( 'Nothing here yet', $html, 'the framing still renders' );
        $this->assertStringNotContainsString( 'tt-empty-state__cta', $html );
    }

    // A plain view: the activities list renders the card directly

    public function test_the_activities_empty_state_button_follows_the_matrix(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_activities" );
        $uid = $this->matrixOnlyHod( 'tt_edit_activities' );

        wp_set_current_user( $uid );
        $_GET = [ 'tt_view' => 'activities' ];
        ob_start();
        FrontendActivitiesManageView::render( $uid, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No activities to show', $html, 'fixture sanity: the card is rendered' );
        $this->assertStringContainsString( 'Plan your first activity', $html );
    }

    // A FrontendListTable list: the teams list pre-renders the card

    public function test_the_teams_empty_state_button_follows_the_matrix(): void {
        global $wpdb;
        $wpdb->query( "DELETE FROM {$wpdb->prefix}tt_teams" );
        $uid = $this->matrixOnlyHod( AuthorizationService::CREATE_TEAM_CAP );

        wp_set_current_user( $uid );
        ob_start();
        FrontendTeamsManageView::render( $uid, false );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No teams yet', $html, 'fixture sanity: the card is rendered' );
        $this->assertStringContainsString( 'Create your first team', $html );
    }

    // A player-profile tab

    public function test_the_player_goals_tab_button_follows_the_matrix(): void {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Empty U14' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $club, 'first_name' => 'Empty', 'last_name' => 'Speler', 'team_id' => (int) $wpdb->insert_id, 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        $uid    = $this->matrixOnlyHod( 'tt_edit_goals' );

        wp_set_current_user( $uid );
        $tab = new ReflectionMethod( FrontendPlayerDetailView::class, 'renderGoalsTab' );
        $tab->setAccessible( true );
        ob_start();
        $tab->invoke( null, $player, [ 'is_family' => false, 'is_self' => false, 'player_id' => $player ] );
        $html = (string) ob_get_clean();

        $this->assertStringContainsString( 'No goals yet for this player', $html, 'fixture sanity: the card is rendered' );
        $this->assertStringContainsString( 'Add first goal', $html );
    }

    // ===== helpers =====

    /** A head of development with the raw cap withheld: the matrix alone grants it. */
    private function matrixOnlyHod( string $cap ): int {
        $uid = self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        ( new \WP_User( $uid ) )->add_cap( $cap, false );
        AuthorizationService::flushCache();
        $this->assertFalse( user_can( $uid, $cap ), 'fixture sanity: the raw cap is withheld' );
        $this->assertTrue( AuthorizationService::userCanOrMatrix( $uid, $cap ), 'fixture sanity: the matrix grants ' . $cap );
        return $uid;
    }

    private function card( int $user_id, string $cap ): string {
        wp_set_current_user( $user_id );
        ob_start();
        EmptyStateCard::render( [
            'headline'  => 'Nothing here yet',
            'cta_label' => 'Add the first one',
            'cta_url'   => 'https://example.test/new',
            'cta_cap'   => $cap,
        ] );
        return (string) ob_get_clean();
    }
}
