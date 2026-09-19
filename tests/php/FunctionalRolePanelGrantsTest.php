<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Activities\Services\ActivityGridLink;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Shared\Tiles\TileRegistry;

/**
 * #3643 — the dashboard agreed with the API about what a team manager
 * may read.
 *
 * #3567 gave the Manager functional role its data grants: `team`,
 * `players`, `people`, `activities`, `attendance`, `player_status`, all
 * at the scope of the team the role is held on. REST honoured them. The
 * dashboard did not, because a tile is gated on a *panel* entity —
 * `team_roster_panel`, `coach_player_list_panel`, `activities_panel` —
 * and no functional role held one. So `GET players?filter[team_id]=52`
 * returned sixteen players to the same account that got "You do not have
 * access to this surface" on `?tt_view=players&team_id=52`.
 *
 * Two kinds of assertion here, and the first is the one that keeps this
 * from happening a third time:
 *
 *  - a config invariant: for every functional role, reading a data
 *    entity implies reading the panel entity that stands in front of its
 *    screen. It reads the shipped config rather than restating it, so a
 *    role that gains `activities [r]` tomorrow and no panel fails here
 *    and not in a simulation run six weeks later.
 *  - the resolved answer for a real assignment, through the same gates
 *    the nav and the dispatcher ask.
 *
 * The negative assertions carry as much weight as the positive ones: a
 * Staff account with no functional role must still see none of the three
 * screens, and none of these roles may write on them.
 */
final class FunctionalRolePanelGrantsTest extends WP_UnitTestCase {

    /** data entity => the tile-visibility entity in front of its screen. */
    private const PANEL_FOR = [
        'team'       => 'team_roster_panel',
        'players'    => 'coach_player_list_panel',
        'activities' => 'activities_panel',
    ];

    private int $team    = 0;
    private int $other   = 0;
    private int $manager = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        // The tile registry is populated during boot; a test process has
        // not necessarily been through it.
        \TT\Shared\CoreSurfaceRegistration::register();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O11-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => 1, 'name' => 'Hedel O12-1' ] );
        $this->other = (int) $wpdb->insert_id;

        $this->manager = $this->makeStaffUser();
        $this->assign( $this->manager, $this->team, 'manager' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    // ── the invariant ──────────────────────────────────────────────────

    /**
     * The drift guard. Reading the data without being offered the screen
     * is the bug; this says so in one place, for every role at once.
     */
    public function test_every_role_that_reads_a_data_entity_is_offered_its_screen(): void {
        $grants = $this->grants();
        $this->assertNotEmpty( $grants, 'functional_role_grants.php returned no grants' );

        foreach ( $grants as $role_key => $entities ) {
            foreach ( self::PANEL_FOR as $data => $panel ) {
                if ( ! $this->reads( $entities, $data ) ) continue;

                $this->assertTrue(
                    $this->reads( $entities, $panel ),
                    "The {$role_key} functional role reads {$data} but is not offered the {$panel} screen"
                );
            }
        }
    }

    /**
     * A panel entity decides whether a surface is offered, never what it
     * may show. Granting one `change` would be a category error.
     */
    public function test_no_role_holds_more_than_read_on_a_panel_entity(): void {
        foreach ( $this->grants() as $role_key => $entities ) {
            foreach ( self::PANEL_FOR as $panel ) {
                if ( ! isset( $entities[ $panel ] ) ) continue;
                $this->assertSame(
                    'r',
                    (string) ( $entities[ $panel ][0] ?? '' ),
                    "{$role_key} holds more than read on the visibility entity {$panel}"
                );
            }
        }
    }

    public function test_the_physio_is_offered_the_squad_list(): void {
        $entities = $this->grants()['physio'] ?? [];

        $this->assertTrue(
            $this->reads( $entities, 'coach_player_list_panel' ),
            'a physio reads injuries and measurements per player, so the squad list is how they find the player'
        );
    }

    // ── the resolved answer ────────────────────────────────────────────

    public function test_a_manager_is_offered_the_three_screens_on_their_own_team(): void {
        foreach ( self::PANEL_FOR as $panel ) {
            $this->assertTrue(
                MatrixGate::can( $this->manager, $panel, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team ),
                "Manager should be offered {$panel} on their own team"
            );
            $this->assertFalse(
                MatrixGate::can( $this->manager, $panel, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->other ),
                "Manager must not be offered {$panel} on a team they hold no role on"
            );
        }
    }

    /**
     * `canAccessViewSlug()` is what the nav and `DashboardShortcode` both
     * ask, so this is the assertion that actually opens the screens.
     */
    public function test_the_dashboard_lets_a_manager_open_teams_players_and_activities(): void {
        foreach ( [ 'teams', 'players', 'activities' ] as $slug ) {
            $this->assertTrue(
                TileRegistry::canAccessViewSlug( $slug, $this->manager ),
                "?tt_view={$slug} still refuses the team manager"
            );
        }
    }

    public function test_a_kit_manager_is_offered_the_same_three_screens(): void {
        $kit = $this->makeStaffUser();
        $this->assign( $kit, $this->team, 'kit_manager' );

        foreach ( [ 'teams', 'players', 'activities' ] as $slug ) {
            $this->assertTrue( TileRegistry::canAccessViewSlug( $slug, $kit ), "?tt_view={$slug} refuses the kit manager" );
        }
    }

    /**
     * The upgrade story (#3257): an account with no functional role is
     * untouched by this file, so it stays where it was.
     */
    public function test_a_staff_account_with_no_functional_role_is_offered_none_of_them(): void {
        $plain = $this->makeStaffUser();

        foreach ( self::PANEL_FOR as $panel ) {
            $this->assertFalse(
                MatrixGate::canAnyScope( $plain, $panel, MatrixGate::READ ),
                "an unassigned Staff account was offered {$panel}"
            );
        }
    }

    /**
     * Opening a screen is not the same as writing on it. Asserted on the
     * config, because that is the layer this change edits: neither
     * logistics role gains a letter past `r` on any of the three.
     */
    public function test_neither_logistics_role_gains_a_write_on_the_three_entities(): void {
        $grants = $this->grants();

        foreach ( [ 'manager', 'kit_manager' ] as $role_key ) {
            $entities = $grants[ $role_key ] ?? [];
            $this->assertNotEmpty( $entities, "the {$role_key} functional role granted nothing" );

            foreach ( array_merge( array_keys( self::PANEL_FOR ), array_values( self::PANEL_FOR ) ) as $entity ) {
                if ( ! isset( $entities[ $entity ] ) ) continue;
                $this->assertSame(
                    'r',
                    (string) ( $entities[ $entity ][0] ?? '' ),
                    "{$role_key} holds more than read on {$entity} — create/edit/archive stay with the coaches"
                );
            }
        }
    }

    /**
     * And the resolved answer, on the two entities where the `staff`
     * persona has no say. (`players [rc, team]` sits on that persona
     * from #3232 and is out of this issue's scope, so the screen's write
     * affordances are decided there rather than here.)
     */
    public function test_a_manager_does_not_write_teams_or_activities(): void {
        foreach ( [ 'team', 'activities' ] as $data ) {
            foreach ( [ MatrixGate::CHANGE, MatrixGate::CREATE_DELETE ] as $activity ) {
                $this->assertFalse(
                    MatrixGate::can( $this->manager, $data, $activity, MatrixGate::SCOPE_TEAM, $this->team ),
                    "Manager should not hold {$data}:{$activity}"
                );
            }
        }
    }

    // ── the register link ──────────────────────────────────────────────

    /**
     * The other half of #3643: the grid a manager may open had no way in
     * from the activity, because the affordance asked
     * `tt_edit_activities` while the grid and its endpoint asked
     * `canRecordAttendance()`.
     */
    public function test_the_register_link_follows_the_attendance_question(): void {
        // Yesterday, so #3656's column rule holds: an upcoming activity
        // with nothing recorded on it is not a column and offers no link
        // to anybody, which would hide what this test is about.
        $date     = $this->yesterday();
        $activity = $this->activity( $this->team, $date );
        ActivityGridLink::primeAnchor( $activity, $this->team, $date );

        if ( ! ActivityGridLink::attendanceEnabled() ) {
            $this->markTestSkipped( 'the attendance grid feature is off on this install' );
        }

        $this->assertTrue(
            ActivityGridLink::canUseAttendance( $activity, $this->manager ),
            'a team manager may take the register, so the link has to be there'
        );

        $kit = $this->makeStaffUser();
        $this->assign( $kit, $this->team, 'kit_manager' );
        $this->assertFalse(
            ActivityGridLink::canUseAttendance( $activity, $kit ),
            'a kit manager reads the schedule and does not take the register'
        );
    }

    /**
     * The precondition the helper has always carried: the grid's rows
     * are a team roster, so a club-wide activity offers nothing.
     */
    public function test_a_club_wide_activity_offers_no_register_link(): void {
        $date     = $this->yesterday();
        $activity = $this->activity( $this->team, $date );
        ActivityGridLink::primeAnchor( $activity, 0, $date );

        $this->assertFalse( ActivityGridLink::canUseAttendance( $activity, $this->manager ) );
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** @return array<string, array<string, array{0:string,1:string}>> */
    private function grants(): array {
        $config = require dirname( __DIR__, 2 ) . '/config/functional_role_grants.php';

        return is_array( $config ) && is_array( $config['grants'] ?? null ) ? $config['grants'] : [];
    }

    /** @param array<string, array{0:string,1:string}> $entities */
    private function reads( array $entities, string $entity ): bool {
        return strpos( (string) ( $entities[ $entity ][0] ?? '' ), 'r' ) !== false;
    }

    private function makeStaffUser(): int {
        global $wpdb;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Manager',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        return $uid;
    }

    private function personIdFor( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
            $user_id
        ) );
    }

    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";
        $id    = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s AND club_id = %d",
            $role_key, 1
        ) );
        if ( $id > 0 ) return $id;

        $wpdb->insert( $table, [
            'club_id'     => 1,
            'role_key'    => $role_key,
            'label'       => ucfirst( str_replace( '_', ' ', $role_key ) ),
            'description' => 'Created by FunctionalRolePanelGrantsTest.',
            'is_system'   => 1,
            'sort_order'  => 90,
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * The functional-role assignment plus the team-scope row that
     * `PeopleRepository::assignToTeam()` writes beside it.
     */
    private function assign( int $user_id, int $team_id, string $role_key ): void {
        global $wpdb;
        $person = $this->personIdFor( $user_id );

        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $team_id,
            'person_id'          => $person,
            'functional_role_id' => $this->functionalRoleId( $role_key ),
            'role_in_team'       => $role_key,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => $person,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }

    private function yesterday(): string {
        return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -1 day' ) ?: time() );
    }

    private function activity( int $team_id, string $session_date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id'             => 1,
            'team_id'             => $team_id,
            'title'               => 'Training',
            'session_date'        => $session_date,
            'activity_type_key'   => 'training',
            'activity_status_key' => 'planned',
            'plan_state'          => 'scheduled',
        ] );
        return (int) $wpdb->insert_id;
    }
}
