<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;

/**
 * Tier 2 (#1388) — full user → persona → team-scope → MatrixGate::can
 * round-trip. Tier 1's AuthorizationServiceTest stops at the repository
 * grant round-trip; this exercises the whole decision chain end-to-end,
 * including the runtime scope check (`userHasScope`) that the repo-level
 * test can't reach.
 *
 * Setup:
 *   - A `tt_scout` user resolves (via PersonaResolver) to the `scout`
 *     persona — a clean, deterministic WP-role → persona mapping with no
 *     administrator short-circuit muddying the result.
 *   - A `tt_people` row links the WP user (so `userHasScope` can resolve
 *     user → person), and a `tt_user_role_scopes` row scopes that person
 *     to one team.
 *   - A matrix grant: scout may `view` `players` at `team` scope.
 *
 * Assertions:
 *   - can(view, team, grantedTeam)  === true  (granted + in scope)
 *   - can(view, team, otherTeam)    === false (granted but NOT in scope)
 *   - can(change, team, grantedTeam)=== false (activity not granted)
 */
final class MatrixGateScopeTest extends WP_UnitTestCase {

    private const PERSONA = 'scout';

    public function set_up(): void {
        parent::set_up();
        // The TT WP roles (tt_scout, …) are installed on plugin activation,
        // which doesn't fire in the wp-env test bootstrap. Install them here
        // so PersonaResolver can map tt_scout → scout deterministically.
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        // Remove the grants this test wrote so the shared matrix table
        // doesn't leak rows into sibling tests.
        $repo = new MatrixRepository();
        $repo->removeRow( self::PERSONA, 'players', 'view', MatrixGate::SCOPE_TEAM );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_full_user_persona_team_scope_round_trip(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );

        // Guard: confirm the WP role really maps to the scout persona. If
        // the role isn't registered on this install, fall back-document
        // below instead of producing a misleading green.
        $personas = PersonaResolver::effectivePersonas( $uid );
        $this->assertContains(
            self::PERSONA,
            $personas,
            'a tt_scout user must resolve to the scout persona for this round-trip to mean anything'
        );

        $granted_team = 4101;
        $other_team   = 4102;

        // 1. tt_people row links the WP user → a person (userHasScope reads
        //    tt_people.wp_user_id to find the person, then its role-scopes).
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Scope',
            'last_name'  => 'Scout',
            'role_type'  => 'scout',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id );

        // 2. Assign the person the team scope (scope_type='team', scope_id=team).
        //    role_id is NOT NULL on the table but irrelevant to userHasScope's
        //    lookup, which keys on person_id + scope_type + scope_id + dates.
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $granted_team,
        ] );

        // 3. Grant: scout may view players at team scope. Empty module_class
        //    => ModuleRegistry::isEnabled('') returns true (unknown module),
        //    so the row is treated as enabled.
        ( new MatrixRepository() )->setRow( self::PERSONA, 'players', 'view', MatrixGate::SCOPE_TEAM, '' );
        MatrixRepository::clearCache();

        // Granted activity, scoped to the team the user actually holds.
        $this->assertTrue(
            MatrixGate::can( $uid, 'players', 'view', MatrixGate::SCOPE_TEAM, $granted_team ),
            'scout with a view grant AND the team scope can view players on that team'
        );

        // Granted activity, but a team the user has NO scope on.
        $this->assertFalse(
            MatrixGate::can( $uid, 'players', 'view', MatrixGate::SCOPE_TEAM, $other_team ),
            'the grant must not leak to a team the user holds no scope on'
        );

        // An activity that was never granted, even on the in-scope team.
        $this->assertFalse(
            MatrixGate::can( $uid, 'players', 'change', MatrixGate::SCOPE_TEAM, $granted_team ),
            'an ungranted activity (change) is denied even where the user is in scope'
        );
    }
}
