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
     * Link a coach WP user to a person scoped to TEAM_ID, so
     * `coach_owns_player` resolves the coach → the player's team.
     */
    private function scope_coach_to_team( int $user_id ): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Coach',
            'last_name'  => 'Parent',
            'role_type'  => 'coach',
            'wp_user_id' => $user_id,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id );

        // Mirrors MatrixGateScopeTest: no explicit club_id — the column's
        // migration default (1) matches CurrentClub::id() in the test env.
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => self::TEAM_ID,
        ] );
    }

    public function test_dual_role_coach_parent_can_read_and_post(): void {
        $player_id = $this->seed_player_on_team();

        // A coach who is ALSO a parent of an academy child. tt_coach
        // grants the player-notes caps; tt_parent is the second role
        // that the old role-name exclude false-denied on.
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $user = get_user_by( 'id', $uid );
        $this->assertInstanceOf( \WP_User::class, $user );
        $user->add_role( 'tt_parent' );

        // Grant the coach's player-notes caps directly. In production the
        // `tt_coach` role carries them (RolesService), but the wp-env
        // harness can leave the role without its seeded caps — `add_role`
        // is a no-op once the role is process-cached, so installRoles() in
        // set_up() doesn't re-seed it. The unit under test here is the
        // ADAPTER's role-name handling, not role installation, so we pin
        // the caps the coach hat confers and keep the test deterministic.
        $user->add_cap( 'tt_view_player_notes' );
        $user->add_cap( 'tt_edit_player_notes' );

        // Sanity: the user really carries the parent role (the condition
        // that used to trip the exclude) AND the notes caps (coach hat).
        $this->assertContains( 'tt_parent', (array) $user->roles );
        $this->assertTrue( user_can( $uid, 'tt_view_player_notes' ) );
        $this->assertTrue( user_can( $uid, 'tt_edit_player_notes' ) );

        $this->scope_coach_to_team( $uid );

        $adapter = new PlayerThreadAdapter();

        $this->assertTrue(
            $adapter->canRead( $uid, $player_id ),
            'a coach who is also a tt_parent must read notes on a player they coach'
        );
        $this->assertTrue(
            $adapter->canPost( $uid, $player_id ),
            'a coach who is also a tt_parent must post notes on a player they coach'
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
