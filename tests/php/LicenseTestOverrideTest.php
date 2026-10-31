<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Shared\Frontend\FrontendTeamsManageView;

/**
 * #4336 — a test can switch commercial mode and the tier per case.
 *
 * `TT_COMMERCIAL_MODE` is a constant, so a run was all commercial or all
 * not, and the free-tier team cap could not be put under test. The two
 * overrides are read only while the PHPUnit bootstrap's `TT_PHPUNIT` is
 * defined; the resolver tests below prove they are inert without it.
 */
final class LicenseTestOverrideTest extends WP_UnitTestCase {

    private string $p = '';

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p = $wpdb->prefix;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->query( "DELETE FROM {$this->p}tt_teams" );
    }

    public function tear_down(): void {
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    private function team( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => (int) CurrentClub::id(), 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function freeCommercialInstall(): void {
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_FREE );
    }

    private function renderFor( int $user_id, bool $is_admin ): string {
        wp_set_current_user( $user_id );
        ob_start();
        FrontendTeamsManageView::render( $user_id, $is_admin );
        $html = (string) ob_get_clean();
        wp_set_current_user( 0 );
        return $html;
    }

    public function test_the_bootstrap_defines_the_test_constant(): void {
        $this->assertTrue( LicenseMode::testsRunning() );
    }

    public function test_commercial_mode_and_the_tier_switch_per_case_and_reset(): void {
        $this->freeCommercialInstall();
        $this->assertTrue( LicenseMode::isCommercial() );
        $this->assertSame( FeatureMap::TIER_FREE, LicenseGate::tier() );

        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        $this->assertSame(
            defined( LicenseMode::CONST_NAME ) && (bool) constant( LicenseMode::CONST_NAME ),
            LicenseMode::isCommercial(),
            'a reset hands the answer back to the constant'
        );
    }

    public function test_over_the_team_cap_the_admin_gets_the_nudge_and_the_head_coach_does_not(): void {
        $first = $this->team( 'Cap U13' );
        $this->team( 'Cap U15' );
        $this->freeCommercialInstall();

        $this->assertTrue( LicenseGate::capsExceeded( 'teams' ), 'fixture sanity: two teams on a one-team plan' );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertStringContainsString( 'tt-upgrade-panel--cap', $this->renderFor( $admin, true ) );

        $coach = $this->makeHeadCoach( $first );
        $this->assertStringNotContainsString(
            'tt-upgrade-panel--cap',
            $this->renderFor( $coach, false ),
            'a head coach cannot add a team, so the cap is not their reason'
        );
    }

    public function test_below_the_cap_nobody_gets_the_nudge(): void {
        $this->team( 'Cap U13' );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );

        $this->assertFalse( LicenseGate::capsExceeded( 'teams' ), 'caps apply on the free tier only' );

        $admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->assertStringNotContainsString( 'tt-upgrade-panel--cap', $this->renderFor( $admin, true ) );
    }

    public function test_without_the_test_constant_the_commercial_override_is_inert(): void {
        $this->assertNull( LicenseMode::resolveTestOverride( true, false ) );
        $this->assertNull( LicenseMode::resolveTestOverride( false, false ) );
        $this->assertTrue( LicenseMode::resolveTestOverride( true, true ) );
        $this->assertNull( LicenseMode::resolveTestOverride( null, true ), 'no override set reads the constant' );
    }

    public function test_without_the_test_constant_the_tier_override_is_inert(): void {
        foreach ( FeatureMap::tiers() as $tier ) {
            $this->assertNull( LicenseGate::resolveTestTier( $tier, false ) );
            $this->assertSame( $tier, LicenseGate::resolveTestTier( $tier, true ) );
        }
        $this->assertNull( LicenseGate::resolveTestTier( 'enterprise', true ), 'only the three tiers are accepted' );
        $this->assertNull( LicenseGate::resolveTestTier( null, true ) );
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$this->p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$this->p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        return $uid;
    }
}
