<?php
namespace TT\Tests\Php;

use WPDieException;
use WP_UnitTestCase;
use TT\Infrastructure\Players\ParentAccountService;
use TT\Infrastructure\Players\PlayerParentVisibilityRepository;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Stats\PrintRouter;

/**
 * #3594 — the printable player report answers to the product's read gate.
 *
 * `?tt_print=<id>` knew admins, the player's coaches and the player, so a
 * parent following the print icon on their own child's overview got an
 * error page, and every refusal went out as HTTP 500 because `wp_die()`
 * defaults to it.
 */
final class PrintRouterAccessTest extends WP_UnitTestCase {

    private int $child = 0;
    private int $other_child = 0;
    private int $parent = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $club, 'name' => 'O11-1' ] );
        $team = (int) $wpdb->insert_id;
        foreach ( [ 'child', 'other_child' ] as $slot ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $club, 'team_id' => $team, 'first_name' => 'Kind', 'last_name' => $slot, 'status' => 'active',
            ] );
            $this->{$slot} = (int) $wpdb->insert_id;
        }

        $this->parent = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        ( new ParentAccountService() )->linkToPlayer( $this->child, $this->parent );
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        unset( $_GET['tt_print'] );
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_parent_can_print_their_own_child(): void {
        $this->assertTrue( PrintRouter::canPrint( $this->parent, $this->child ) );
    }

    public function test_a_parent_cannot_print_someone_elses_child(): void {
        $this->assertFalse( PrintRouter::canPrint( $this->parent, $this->other_child ) );
    }

    public function test_a_parent_the_child_hid_evaluations_from_cannot_print(): void {
        ( new PlayerParentVisibilityRepository() )->setVisibility( $this->child, 'evaluations', false );
        $this->assertFalse( PrintRouter::canPrint( $this->parent, $this->child ) );
    }

    public function test_an_administrator_still_can(): void {
        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertTrue( PrintRouter::canPrint( $admin, $this->child ) );
    }

    public function test_a_coach_prints_their_own_team_and_not_another(): void {
        global $wpdb;
        $p    = $wpdb->prefix;
        $team = (int) $wpdb->get_var( $wpdb->prepare( "SELECT team_id FROM {$p}tt_players WHERE id = %d", $this->child ) );
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => 'O12-1' ] );
        $elsewhere = (int) $wpdb->insert_id;

        $mine   = $this->coachOf( $team );
        $theirs = $this->coachOf( $elsewhere );

        $this->assertTrue( PrintRouter::canPrint( $mine, $this->child ) );
        $this->assertFalse( PrintRouter::canPrint( $theirs, $this->child ) );
    }

    public function test_a_refusal_is_403_not_500(): void {
        wp_set_current_user( $this->parent );
        $_GET['tt_print'] = (string) $this->other_child;

        try {
            PrintRouter::maybeRenderFrontend();
            $this->fail( 'the report was rendered for a player the parent is not linked to' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 403, $e->getCode() );
        }
    }

    public function test_a_logged_out_visitor_gets_401(): void {
        wp_set_current_user( 0 );
        $_GET['tt_print'] = (string) $this->child;

        try {
            PrintRouter::maybeRenderFrontend();
            $this->fail( 'the report was rendered for a logged-out visitor' );
        } catch ( WPDieException $e ) {
            $this->assertSame( 401, $e->getCode() );
        }
    }

    private function coachOf( int $team_id ): int {
        global $wpdb;
        $user = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Team', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id' => (int) $wpdb->insert_id, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        MatrixRepository::clearCache();
        return $user;
    }
}
