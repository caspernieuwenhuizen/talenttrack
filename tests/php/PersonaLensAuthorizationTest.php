<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #1982 — the persona switcher is a presentation lens, not a capability
 * gate.
 *
 * Before this test, `MatrixGate` resolved personas through
 * `PersonaResolver::effectivePersonas()`, which collapses a multi-persona
 * user down to the single persona stored in `tt_active_persona` user
 * meta. The switcher writes that meta permanently
 * (`ActivePersonaController::set`), so a coach whose own child is in the
 * academy — who flipped to "Parent" once, to look at their child's page —
 * lost every staff capability on every surface, across sessions and
 * devices, with nothing on screen naming the cause.
 *
 * The gate now resolves against `personasFor()` (the full union). To act
 * as a lesser role deliberately there is Impersonation (#0071) and the
 * matrix Preview page, both of which are visible while they are on.
 *
 * Both assertions run against the same fixture so the first one proves
 * the second means something: if the seed row or the team scope were
 * missing, the pre-condition would fail loudly rather than leaving a
 * green test that asserts nothing.
 */
final class PersonaLensAuthorizationTest extends WP_UnitTestCase {

    private const TEAM_ID = 7401;

    public function set_up(): void {
        parent::set_up();
        // TT WP roles are installed on plugin activation, which doesn't
        // fire in the wp-env bootstrap.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    /**
     * A dual-role coach + parent, linked to a person with a live team
     * scope — which is what the seed's `player_notes [rc, team]` grant on
     * head_coach requires.
     */
    private function seed_dual_role_coach(): int {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $user = new \WP_User( $uid );
        $user->add_role( 'tt_parent' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Lens',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => 1,
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => self::TEAM_ID,
        ] );

        return $uid;
    }

    public function test_active_parent_persona_does_not_remove_the_coach_grant(): void {
        $uid = $this->seed_dual_role_coach();

        $this->assertContains(
            'head_coach',
            PersonaResolver::personasFor( $uid ),
            'a tt_coach user with no assistant rows resolves to head_coach'
        );
        $this->assertContains(
            'parent',
            PersonaResolver::personasFor( $uid ),
            'the tt_parent role must also resolve, or this test proves nothing'
        );

        // Pre-condition: with no lens chosen, the union grants the read.
        $this->assertTrue(
            MatrixGate::canAnyScope( $uid, 'player_notes', MatrixGate::READ ),
            'the seeded head_coach player_notes [read, team] grant must apply before any lens is set'
        );

        // The switcher writes this on every flip, with no expiry.
        update_user_meta( $uid, 'tt_active_persona', 'parent' );

        // The lens itself must still work — it drives the persona chip.
        $this->assertSame(
            [ 'parent' ],
            PersonaResolver::effectivePersonas( $uid ),
            'effectivePersonas stays the presentation lens and still narrows'
        );

        // ...but it must not take a capability away. This is the assertion
        // that fails on the pre-#1982 gate.
        $this->assertTrue(
            MatrixGate::canAnyScope( $uid, 'player_notes', MatrixGate::READ ),
            'viewing the dashboard as a parent must not revoke the coach persona grant'
        );
    }

    /**
     * The lens must not *add* anything either — a parent who somehow held
     * an active persona they no longer qualify for gains nothing, because
     * the gate reads the roles, not the meta.
     */
    public function test_active_persona_meta_cannot_grant_what_the_roles_do_not(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_parent' ] );

        // A stale value from a role that was since removed. personasFor()
        // never returns it, so nothing downstream may honour it.
        update_user_meta( $uid, 'tt_active_persona', 'head_coach' );

        $this->assertNotContains(
            'head_coach',
            PersonaResolver::personasFor( $uid ),
            'a pure parent does not hold the head_coach persona'
        );
        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, 'player_notes', MatrixGate::READ ),
            'a stale active-persona value must not grant a capability the roles withhold'
        );
    }
}
