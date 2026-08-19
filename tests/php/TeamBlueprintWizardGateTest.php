<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Wizards\TeamBlueprint\NewTeamBlueprintWizard;
use TT\Shared\Wizards\WizardEntryPoint;
use TT\Shared\Wizards\WizardRegistry;

/**
 * #2557 — the new-team-blueprint wizard must be reachable by anyone the
 * `team_chemistry` matrix grants `change` to, not only by holders of the
 * raw `tt_manage_team_chemistry` capability.
 *
 * The regression: a head coach holds `team_chemistry [rc, team]` in the
 * matrix but NOT the raw cap (granted to administrator / head_dev /
 * club_admin only, and absent from `LegacyCapMapper::MAPPING`, so the
 * matrix bridge can't answer it either). `FrontendTeamBlueprintsView`
 * rendered the "+ New blueprint" button off `TeamChemistryAccess::canManage()`
 * — true — while `WizardRegistry::isAvailable()` gated on the cap — false.
 * `WizardEntryPoint::buildUrl()` then returned its empty fallback and the
 * button became `href=""`: a link that reloaded the page and created
 * nothing.
 *
 * Asserted here:
 *   - the raw cap really is absent for a coach (the precondition that
 *     makes the old gate fail — if this ever flips, the test below stops
 *     proving anything and this assertion says so);
 *   - a matrix-granted coach gets `isAvailable() === true` and a
 *     non-empty entry URL;
 *   - a persona with no `change` grant is still denied.
 */
final class TeamBlueprintWizardGateTest extends WP_UnitTestCase {

    private const ENTITY = 'team_chemistry';
    private const SLUG   = 'new-team-blueprint';

    public function set_up(): void {
        parent::set_up();
        // TT WP roles are installed on plugin activation, which doesn't
        // fire in the wp-env bootstrap. Install them so PersonaResolver
        // can map tt_coach -> head_coach.
        ( new RolesService() )->installRoles();
        // The registry is populated by WizardsModule at boot; register
        // explicitly so the test doesn't depend on module boot order.
        WizardRegistry::register( new NewTeamBlueprintWizard() );
        MatrixRepository::clearCache();
    }

    public function tear_down(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( 'head_coach', self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );
        MatrixRepository::clearCache();
        parent::tear_down();
    }

    public function test_matrix_granted_coach_can_start_the_blueprint_wizard(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $uid  = self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $team = 5201;

        $this->assertContains(
            'head_coach',
            PersonaResolver::effectivePersonas( $uid ),
            'a tt_coach user must resolve to head_coach for this gate test to mean anything'
        );

        // The precondition that broke the entry point: no raw cap.
        $this->assertFalse(
            user_can( $uid, 'tt_manage_team_chemistry' ),
            'head coaches are not granted the raw manage cap — that is why the gate must ask the matrix'
        );

        // Link the WP user to a person scoped to one team, so the
        // team-scope leg of the matrix resolution can succeed.
        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Blueprint',
            'last_name'  => 'Coach',
            'role_type'  => 'coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id );

        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team,
        ] );

        // Empty module_class => the row is treated as enabled regardless
        // of module state, matching MatrixGateScopeTest's setup.
        ( new MatrixRepository() )->setRow(
            'head_coach', self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, ''
        );
        MatrixRepository::clearCache();

        $this->assertTrue(
            WizardRegistry::isAvailable( self::SLUG, $uid ),
            'a coach the matrix grants team_chemistry:change must be able to start the blueprint wizard'
        );

        // The dead-link half of the regression: an unavailable wizard
        // makes buildUrl() return its empty fallback (blueprints have no
        // flat-form path), which the view then rendered as href="".
        wp_set_current_user( $uid );
        $this->assertNotSame(
            '',
            WizardEntryPoint::buildUrl( self::SLUG, [ 'team_id' => $team ] ),
            'the entry point must resolve to a real URL, not the empty fallback'
        );
    }

    public function test_persona_without_change_authority_is_denied(): void {
        $uid = self::factory()->user->create( [ 'role' => 'tt_scout' ] );

        $this->assertFalse(
            WizardRegistry::isAvailable( self::SLUG, $uid ),
            'a read-only persona must not be able to start the blueprint wizard'
        );
    }
}
