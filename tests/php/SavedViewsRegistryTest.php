<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Filters\SavedViewsRegistry;
use TT\Infrastructure\Security\RolesService;

/**
 * #2448 / #2449 — the registry is the single source of truth for which
 * capability gates saved views on a surface.
 *
 * Both the renderer and the REST permission callbacks read it, so these tests
 * are what stop the two gates drifting. The any-of support (#2449) exists
 * because several lists gate their own REST endpoint on "view-cap OR
 * edit-cap": a single-capability gate would refuse saved views to a user who
 * holds only the edit cap and can plainly see the list.
 */
final class SavedViewsRegistryTest extends WP_UnitTestCase {

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
    }

    public function tear_down(): void {
        SavedViewsRegistry::resetForTests();
        parent::tear_down();
    }

    public function test_unknown_surface_has_no_capability_and_is_refused(): void {
        // Fail-closed. An unregistered key must never fall through to a
        // permissive default.
        $this->assertSame( [], SavedViewsRegistry::capabilitiesFor( 'nope' ) );
        $this->assertNull( SavedViewsRegistry::capabilityFor( 'nope' ) );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $this->assertFalse( SavedViewsRegistry::currentUserCan( 'nope' ) );
        $this->assertFalse( SavedViewsRegistry::currentUserCan( '' ) );
    }

    public function test_every_shipped_report_surface_is_registered(): void {
        foreach ( [ 'attendance_team', 'attendance_player', 'attendance_leaderboard', 'minutes_team', 'minutes_audit' ] as $key ) {
            $this->assertSame( 'tt_view_analytics', SavedViewsRegistry::capabilityFor( $key ), $key );
        }
    }

    public function test_list_surfaces_carry_their_own_capability_not_analytics(): void {
        // The point of the registry: a players-list view is gated on the
        // players capability, not on the reports one #2385 hardcoded.
        $this->assertSame( 'tt_view_players', SavedViewsRegistry::capabilityFor( 'players-list' ) );
        $this->assertSame( 'tt_view_people', SavedViewsRegistry::capabilityFor( 'people-list' ) );
        $this->assertSame( 'tt_view_settings', SavedViewsRegistry::capabilityFor( 'audit-log' ) );
        $this->assertSame( 'tt_view_activities', SavedViewsRegistry::capabilityFor( 'activities-list' ) );
    }

    public function test_all_six_standard_report_slugs_are_registered(): void {
        // These six all render through renderPeriodFilterBar(); if one is
        // added there without a registry entry its strip silently vanishes.
        foreach ( [
            'player-minutes-played', 'team-minutes-distribution',
            'team-squad-evaluation-summary', 'season-summary',
            'season-trial-funnel', 'scout-report-card',
        ] as $slug ) {
            $this->assertSame(
                'tt_view_analytics',
                SavedViewsRegistry::capabilityFor( 'report-' . $slug ),
                $slug
            );
        }
    }

    public function test_registered_keys_survive_sanitize_key(): void {
        // The REST layer runs view_key through sanitize_key(), which strips
        // anything outside [a-z0-9_-]. A key that does not round-trip would
        // be silently unresolvable at the gate.
        foreach ( array_keys( SavedViewsRegistry::all() ) as $key ) {
            $this->assertSame( $key, sanitize_key( $key ), "view_key '{$key}' does not survive sanitize_key()" );
        }
    }

    public function test_any_of_capabilities_grant_access(): void {
        SavedViewsRegistry::register( 'either_surface', [ 'tt_capability_nobody_has', 'tt_view_players' ] );

        $user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $user );

        // Holding the second of the two is enough.
        $this->assertTrue( SavedViewsRegistry::currentUserCan( 'either_surface' ) );
        $this->assertSame(
            [ 'tt_capability_nobody_has', 'tt_view_players' ],
            SavedViewsRegistry::capabilitiesFor( 'either_surface' )
        );
    }

    public function test_holding_none_of_the_capabilities_is_refused(): void {
        SavedViewsRegistry::register( 'locked_surface', [ 'tt_capability_nobody_has', 'tt_another_fake_cap' ] );
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $this->assertFalse( SavedViewsRegistry::currentUserCan( 'locked_surface' ) );
    }

    public function test_teams_and_goals_accept_the_edit_capability_alone(): void {
        // Regression for the any-of case: these two lists are readable by a
        // user holding only the edit cap, so saved views must be too.
        $this->assertContains( 'tt_edit_teams', SavedViewsRegistry::capabilitiesFor( 'teams-list' ) );
        $this->assertContains( 'tt_edit_goals', SavedViewsRegistry::capabilitiesFor( 'goals-list' ) );
    }

    public function test_runtime_registration_overrides_the_shipped_map(): void {
        SavedViewsRegistry::register( 'players-list', 'tt_view_settings' );
        $this->assertSame( 'tt_view_settings', SavedViewsRegistry::capabilityFor( 'players-list' ) );
    }

    public function test_registration_ignores_empty_input(): void {
        SavedViewsRegistry::register( '', 'tt_view_players' );
        SavedViewsRegistry::register( 'blank_cap', '' );
        SavedViewsRegistry::register( 'empty_list', [] );

        $this->assertNull( SavedViewsRegistry::capabilityFor( 'blank_cap' ) );
        $this->assertNull( SavedViewsRegistry::capabilityFor( 'empty_list' ) );
    }

    public function test_filter_can_add_a_surface(): void {
        add_filter( 'tt_saved_views_registry', static function ( array $map ): array {
            $map['from_filter'] = 'tt_view_players';
            return $map;
        } );

        $this->assertSame( 'tt_view_players', SavedViewsRegistry::capabilityFor( 'from_filter' ) );
    }
}
