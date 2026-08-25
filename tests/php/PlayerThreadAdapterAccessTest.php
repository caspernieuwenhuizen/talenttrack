<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Threads\Adapters\PlayerThreadAdapter;

/**
 * #1956 — PlayerThreadAdapter no longer denies player-notes access by
 * WP role-name compare. Two cases this guards:
 *
 *   (a) Dual-role coach + parent. A coach whose own child is in the
 *       academy carries BOTH the `tt_coach` role (which grants the
 *       `tt_view/edit_player_notes` caps) AND the `tt_parent` role.
 *       The old `if ( in_array( 'tt_parent', $roles ) ) return false;`
 *       belt-and-braces line false-denied them. With the role exclude
 *       gone, the cap + `coach_owns_player` scope check correctly
 *       ALLOWS read + post on a player they coach.
 *
 *   (b) Pure player / parent. A `tt_parent` (or `tt_player`) user holds
 *       only the `read` cap — never `tt_view/edit_player_notes` — so the
 *       capability gate alone still denies read + post. No role compare
 *       needed.
 *
 * Setup mirrors MatrixGateScopeTest: a tt_people row links the coach WP
 * user → a person, and a tt_user_role_scopes team grant scopes that
 * person to the player's team so `coach_owns_player` resolves true.
 */
final class PlayerThreadAdapterAccessTest extends WP_UnitTestCase {

    private const TEAM_ID = 7301;

    public function set_up(): void {
        parent::set_up();
        // TT WP roles (tt_coach, tt_parent, …) are installed on plugin
        // activation, which doesn't fire in the wp-env test bootstrap.
        // Install them here so the role → cap mapping is present.
        ( new RolesService() )->installRoles();
    }

    /**
     * Insert a team + a player on it and return the player id (which is
     * also the thread id — the adapter anchors a thread on the player
     * record).
     */
    private function seed_player_on_team(): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_teams", [
            'id'      => self::TEAM_ID,
            'name'    => 'U17 Test',
            'club_id' => 1,
        ] );

        $ok = $wpdb->insert( "{$p}tt_players", [
            'first_name' => 'Test',
            'last_name'  => 'Player',
            'team_id'    => self::TEAM_ID,
            'club_id'    => 1,
            'status'     => 'active',
        ] );
        $this->assertNotFalse( $ok, 'player insert must succeed' );

        return (int) $wpdb->insert_id;
    }

    /**
     * A notes-capable user who ALSO holds the tt_parent role must not be
     * denied by the removed role-name exclude (the #1956 fix).
     *
     * Skipped between #1956 and #1982 on the theory that the matrix
     * user_has_cap bridge was masking the coach grant with the parent
     * persona. It was not: the bridge is registered only when
     * `tt_authorization_active` is set (AuthorizationModule::isMatrixActive),
     * and the wp-env bootstrap never calls Activator::activate(), which is
     * the only thing that seeds that flag. Native WP cap resolution decides
     * here, and it takes the union of both roles' caps.
     */
    public function test_dual_role_staff_who_is_also_parent_is_not_denied_by_role(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $player_id = $this->seed_player_on_team();

        // A coach whose own child is in the academy: both roles at once.
        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $user = new \WP_User( $uid );
        $user->add_role( 'tt_parent' );

        $this->assertTrue(
            user_can( $uid, 'tt_view_player_notes' ),
            'the tt_coach grant must survive also holding tt_parent'
        );

        // tt_people row links the WP user → a person, and the team scope
        // row is what coach_owns_player() reads through
        // get_teams_for_coach(). club_id matters: the join constrains
        // urs.club_id = t.club_id.
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Dual',
            'last_name'  => 'Role',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id );

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => self::TEAM_ID,
        ] );

        $adapter = new PlayerThreadAdapter();

        $this->assertTrue(
            $adapter->canRead( $uid, $player_id ),
            'a coach who is also a parent must still read notes on a player they coach'
        );
        $this->assertTrue(
            $adapter->canPost( $uid, $player_id ),
            'a coach who is also a parent must still post notes on a player they coach'
        );
    }

    public function test_pure_parent_is_denied_by_capability(): void {
        $player_id = $this->seed_player_on_team();

        // Pure parent — only the `read` cap, never the notes caps.
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $this->assertFalse( user_can( $uid, 'tt_view_player_notes' ) );

        $adapter = new PlayerThreadAdapter();

        $this->assertFalse(
            $adapter->canRead( $uid, $player_id ),
            'a pure parent has no notes cap and must be denied read'
        );
        $this->assertFalse(
            $adapter->canPost( $uid, $player_id ),
            'a pure parent has no notes cap and must be denied post'
        );
    }

    public function test_pure_player_is_denied_by_capability(): void {
        $player_id = $this->seed_player_on_team();

        // Pure player — only the `read` cap, never the notes caps.
        $uid = self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $this->assertFalse( user_can( $uid, 'tt_view_player_notes' ) );

        $adapter = new PlayerThreadAdapter();

        $this->assertFalse(
            $adapter->canRead( $uid, $player_id ),
            'a pure player has no notes cap and must be denied read'
        );
        $this->assertFalse(
            $adapter->canPost( $uid, $player_id ),
            'a pure player has no notes cap and must be denied post'
        );
    }
}
