<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Visibility\RecordVisibility;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #3257 — a physio needs injuries, a kit manager does not.
 * #3433 — and a kit manager does not need measurements either.
 *
 * Both are the `staff` persona, from the one `tt_staff` WordPress role,
 * so the matrix — which keys on `(persona, entity, activity,
 * scope_kind)` — cannot separate them. #3232's `player_injuries
 * [rc, team]` grant therefore reached every Staff account, including one
 * issued to move shirts. The functional role held on a squad is what
 * separates them, and `FunctionalRoleGrants` is where that is read.
 *
 * #3433 is the same shape one entity over. `measurements [rc, team]` was
 * left on the persona by #3257 on #3232's reasoning that height and
 * weight are what every staff member records — which was true about the
 * job and wrong about the seat, in exactly the way the injury grant was.
 * It now follows the functional role too: Physio, Head coach and
 * Assistant coach read it, Kit manager does not.
 *
 * The negative assertions carry the weight here. The failure mode of an
 * authorization change is WIDENING access to medical data about minors,
 * so every "can" below is paired with a "cannot", and the cases that
 * must come back false outnumber the ones that must come back true.
 */
final class FunctionalRoleAccessTest extends WP_UnitTestCase {

    private const PERSONA = 'staff';
    private const ENTITY  = 'player_injuries';

    /** #3433 — the second entity to follow the functional role. */
    private const MEASUREMENTS = 'measurements';

    /** @var int */
    private $team_with_role = 7301;

    /** @var int */
    private $team_without_role = 7302;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
    }

    public function tear_down(): void {
        $this->removeLegacyStaffInjuryRow();
        $this->removeLegacyStaffMeasurementRow();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    // ── fixtures ───────────────────────────────────────────────────────

    /** A `tt_staff` WordPress user with a linked `tt_people` row. */
    private function makeStaffUser(): int {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        $this->assertContains(
            self::PERSONA,
            PersonaResolver::personasFor( $uid ),
            'tt_staff must resolve to the staff persona or none of this means anything'
        );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Functional',
            'last_name'  => 'Role',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $this->assertGreaterThan( 0, (int) $wpdb->insert_id );

        return $uid;
    }

    private function personIdFor( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d LIMIT 1",
            $user_id
        ) );
    }

    /** Id of a functional role, created on demand so the test never depends on seed order. */
    private function functionalRoleId( string $role_key ): int {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_functional_roles";

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$table} WHERE role_key = %s AND club_id = %d",
            $role_key, 1
        ) );
        if ( $id > 0 ) return $id;

        $wpdb->insert( $table, [
            'club_id'     => 1,
            'role_key'    => $role_key,
            'label'       => ucfirst( str_replace( '_', ' ', $role_key ) ),
            'description' => 'Created by FunctionalRoleAccessTest.',
            'is_system'   => 1,
            'sort_order'  => 90,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function assignFunctionalRole( int $user_id, int $team_id, string $role_key ): void {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id'            => 1,
            'team_id'            => $team_id,
            'person_id'          => $this->personIdFor( $user_id ),
            'functional_role_id' => $this->functionalRoleId( $role_key ),
            'role_in_team'       => $role_key,
        ] );
        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
    }

    /**
     * The `tt_user_role_scopes` row the persona's team-scope check reads.
     * Separate from the functional-role assignment on purpose: the point
     * of several assertions below is that holding the team scope is no
     * longer enough on its own.
     */
    private function scopeToTeam( int $user_id, int $team_id ): void {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'person_id'  => $this->personIdFor( $user_id ),
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );
        AuthorizationService::flushCache();
    }

    /**
     * Reproduce an upgraded install: `tt_authorization_matrix` still
     * carries the `staff` persona's `player_injuries` rows that #3232
     * seeded, because nothing migrates them away.
     */
    private function addLegacyStaffInjuryRow(): void {
        $repo = new MatrixRepository();
        $repo->setRow( self::PERSONA, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, '' );
        $repo->setRow( self::PERSONA, self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, '' );
        MatrixRepository::clearCache();
    }

    private function removeLegacyStaffInjuryRow(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( self::PERSONA, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $repo->removeRow( self::PERSONA, self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );
    }

    /**
     * #3433's equivalent: an upgraded install still carries #3232's
     * `measurements [rc, team]` rows for the `staff` persona, because
     * nothing migrates them away either.
     */
    private function addLegacyStaffMeasurementRow(): void {
        $repo = new MatrixRepository();
        $repo->setRow( self::PERSONA, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM, '' );
        $repo->setRow( self::PERSONA, self::MEASUREMENTS, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, '' );
        MatrixRepository::clearCache();
    }

    private function removeLegacyStaffMeasurementRow(): void {
        $repo = new MatrixRepository();
        $repo->removeRow( self::PERSONA, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        $repo->removeRow( self::PERSONA, self::MEASUREMENTS, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM );
    }

    private function makePlayerOnTeam( int $team_id ): int {
        global $wpdb;

        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => 1,
            'first_name' => 'Injured',
            'last_name'  => 'Player',
            'team_id'    => $team_id,
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    // ── the seed no longer carries the grant ───────────────────────────

    /**
     * The acceptance criterion stated as data: `config/authorization_seed.php`
     * does not grant `player_injuries` to the `staff` persona any more. This
     * is the successor to `StaffPersonaGrantsTest`'s #3232 assertion.
     */
    public function test_the_staff_persona_is_no_longer_seeded_player_injuries(): void {
        $seed = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';

        foreach ( $seed as $row ) {
            if ( ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            $this->assertNotSame(
                self::ENTITY,
                (string) ( $row['entity'] ?? '' ),
                'The injury grant belongs to the Physio functional role, not to every Staff account.'
            );
        }
    }

    /** And it is on the Physio functional role instead, read + change, no delete. */
    public function test_the_physio_functional_role_carries_the_injury_grant(): void {
        $this->assertContains(
            self::ENTITY,
            FunctionalRoleGrants::entitiesFor( 'physio' )
        );
    }

    /**
     * #3433 — the same criterion for measurements. Stated as data so a
     * future edit putting the row back on the persona has to argue with
     * this rather than sail past a deleted test.
     */
    public function test_the_staff_persona_is_no_longer_seeded_measurements(): void {
        $seed = require dirname( __DIR__, 2 ) . '/config/authorization_seed.php';

        foreach ( $seed as $row ) {
            if ( ( $row['persona'] ?? '' ) !== self::PERSONA ) continue;
            $this->assertNotSame(
                self::MEASUREMENTS,
                (string) ( $row['entity'] ?? '' ),
                'The measurement grant belongs to the functional roles, not to every Staff account.'
            );
        }
    }

    /**
     * Exactly three functional roles read measurements, and the closed
     * list is the assertion — a "contains" check would not catch a fourth
     * role being handed a minor's growth data.
     */
    public function test_three_functional_roles_read_measurements_and_no_others(): void {
        $reads = [];
        foreach ( [ 'physio', 'head_coach', 'assistant_coach', 'kit_manager', 'manager', 'head_of_development', 'club_admin', 'other' ] as $role_key ) {
            if ( in_array( self::MEASUREMENTS, FunctionalRoleGrants::entitiesFor( $role_key ), true ) ) {
                $reads[] = $role_key;
            }
        }
        sort( $reads );

        $this->assertSame( [ 'assistant_coach', 'head_coach', 'physio' ], $reads );
    }

    /**
     * The kit manager set is written positively. "Everything except
     * injuries" is a definition by subtraction, and the next sensitive
     * entity added to the persona would land on this seat silently.
     *
     * #3643 added the three `*_panel` entries. They are visibility
     * entities, not data: each one decides whether the screen in front
     * of a data entity already on this list is offered, which is the
     * drift that left a kit manager reading the schedule over REST and
     * refused on the Activities screen. They carry read and nothing
     * else, and the exhaustive assertion stays exhaustive.
     */
    public function test_the_kit_manager_set_is_a_positive_list_with_nothing_medical(): void {
        $entities = FunctionalRoleGrants::entitiesFor( 'kit_manager' );
        sort( $entities );

        // #3686 added `holidays`: the academy calendar, read only, which
        // is what explains a gap in the schedule a kit manager packs for.
        $this->assertSame(
            [ 'activities', 'activities_panel', 'coach_player_list_panel', 'holidays', 'people', 'players', 'team', 'team_roster_panel' ],
            $entities
        );

        foreach ( [ 'player_injuries', 'measurements', 'safeguarding_notes', 'evaluations', 'player_potential' ] as $forbidden ) {
            $this->assertNotContains( $forbidden, $entities );
        }
    }

    // ── physio: the positive direction, and its boundary ───────────────

    public function test_a_physio_reads_the_injuries_of_the_team_they_are_the_physio_for(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );

        $this->assertTrue(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'a physio on the team must reach that team\'s injury records'
        );
        $this->assertTrue(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'recording the injury is the job'
        );
    }

    /**
     * The load-bearing negative. The user holds the team scope on
     * `team_without_role` AND the install still carries #3232's staff
     * matrix row — the widest state an upgraded install can be in — and
     * the answer is still no, because they are not the physio there.
     */
    public function test_a_physio_reaches_no_injuries_on_a_team_they_hold_no_functional_role_on(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );
        $this->scopeToTeam( $uid, $this->team_without_role );
        $this->addLegacyStaffInjuryRow();

        $this->assertFalse(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_without_role ),
            'holding a team scope is not holding the physio role on that team'
        );
    }

    /** Deleting a minor's medical record was never a touchline decision and still is not. */
    public function test_a_physio_cannot_delete_an_injury_record(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );

        $this->assertFalse(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::CREATE_DELETE, MatrixGate::SCOPE_TEAM, $this->team_with_role )
        );
    }

    /** A functional role is held on a squad. It never answers a global question. */
    public function test_a_physio_holds_nothing_at_global_scope(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );

        $this->assertFalse(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_GLOBAL )
        );
    }

    /** An assignment that has ended grants nothing, and does not fall back to the old wider row. */
    public function test_an_expired_physio_assignment_grants_nothing_and_does_not_fall_back(): void {
        global $wpdb;

        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->addLegacyStaffInjuryRow();

        $wpdb->update(
            "{$wpdb->prefix}tt_team_people",
            [ 'end_date' => '2000-01-01' ],
            [ 'person_id' => $this->personIdFor( $uid ) ]
        );
        FunctionalRoleGrants::clearCache();

        $this->assertFalse(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'both halves must fail narrow: no grant, and no return to the persona-wide row'
        );
    }

    // ── kit manager: no medical data anywhere ──────────────────────────

    /**
     * The case the issue is named for. The kit manager is attached to the
     * squad, holds the team scope, and the install still carries #3232's
     * staff row. No medical entity, no activity, no team.
     */
    public function test_a_kit_manager_reaches_no_medical_data_on_any_team(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'kit_manager' );
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->scopeToTeam( $uid, $this->team_without_role );
        $this->addLegacyStaffInjuryRow();

        foreach ( [ $this->team_with_role, $this->team_without_role ] as $team_id ) {
            foreach ( [ MatrixGate::READ, MatrixGate::CHANGE, MatrixGate::CREATE_DELETE ] as $activity ) {
                $this->assertFalse(
                    MatrixGate::can( $uid, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $team_id ),
                    sprintf( 'kit manager reached player_injuries:%s on team %d', $activity, $team_id )
                );
            }
        }

        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, self::ENTITY, MatrixGate::READ ),
            'the tile gate asks the "any scope" question; a kit manager must not be offered the Injuries surface'
        );
    }

    /** What the kit manager does get: the squad, the people, the calendar. */
    public function test_a_kit_manager_reads_the_squad_they_are_attached_to(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'kit_manager' );

        foreach ( [ 'team', 'players', 'people', 'activities' ] as $entity ) {
            $this->assertTrue(
                MatrixGate::can( $uid, $entity, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
                "a kit manager needs to read {$entity} for their own squad"
            );
        }
    }

    // ── measurements: the three roles admitted, and everyone else ──────

    /**
     * #3433's positive direction, for all three roles the decision names.
     * Each reads the measurements of the squad they hold the role on.
     */
    public function test_the_three_named_roles_read_the_measurements_of_their_own_squad(): void {
        foreach ( [ 'physio', 'head_coach', 'assistant_coach' ] as $role_key ) {
            $uid = $this->makeStaffUser();
            $this->assignFunctionalRole( $uid, $this->team_with_role, $role_key );

            $this->assertTrue(
                MatrixGate::can( $uid, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
                sprintf( '%s must read the measurements of the squad they hold the role on', $role_key )
            );
            $this->assertTrue(
                MatrixGate::canAnyScope( $uid, self::MEASUREMENTS, MatrixGate::READ ),
                sprintf( 'the tile gate must offer %s the measurement surfaces', $role_key )
            );
        }
    }

    /**
     * The load-bearing negative, measurement edition. The user holds the
     * team scope on `team_without_role` AND the install still carries
     * #3232's staff matrix row — the widest state an upgraded install can
     * be in — and the answer is still no, because they hold no functional
     * role there.
     */
    public function test_a_physio_reaches_no_measurements_on_a_team_they_hold_no_functional_role_on(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'physio' );
        $this->scopeToTeam( $uid, $this->team_without_role );
        $this->addLegacyStaffMeasurementRow();

        $this->assertFalse(
            MatrixGate::can( $uid, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_without_role ),
            'holding a team scope is not holding a measuring role on that team'
        );
    }

    /**
     * The case #3433 is named for. The kit manager is attached to the
     * squad, holds the team scope on two teams, and the install still
     * carries #3232's staff row. No measurement, no activity, no team.
     */
    public function test_a_kit_manager_reaches_no_measurements_on_any_team(): void {
        $uid = $this->makeStaffUser();
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'kit_manager' );
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->scopeToTeam( $uid, $this->team_without_role );
        $this->addLegacyStaffMeasurementRow();

        foreach ( [ $this->team_with_role, $this->team_without_role ] as $team_id ) {
            foreach ( [ MatrixGate::READ, MatrixGate::CHANGE, MatrixGate::CREATE_DELETE ] as $activity ) {
                $this->assertFalse(
                    MatrixGate::can( $uid, self::MEASUREMENTS, $activity, MatrixGate::SCOPE_TEAM, $team_id ),
                    sprintf( 'kit manager reached measurements:%s on team %d', $activity, $team_id )
                );
            }
        }

        $this->assertFalse(
            MatrixGate::canAnyScope( $uid, self::MEASUREMENTS, MatrixGate::READ ),
            'a kit manager must not even be offered the test-results surface'
        );
    }

    /**
     * The decision names READ and nothing else. No functional role
     * carries the write half, so a superseded Staff account reads and
     * does not record — recording stays a `head_coach` / `coach` /
     * `team_manager` persona grant, and those personas are not
     * superseded. Asserted rather than left implied, because granting
     * `change` here would be the widening this layer exists to prevent.
     */
    public function test_no_functional_role_carries_the_measurement_write_half(): void {
        foreach ( [ 'physio', 'head_coach', 'assistant_coach' ] as $role_key ) {
            $uid = $this->makeStaffUser();
            $this->assignFunctionalRole( $uid, $this->team_with_role, $role_key );
            $this->addLegacyStaffMeasurementRow();

            foreach ( [ MatrixGate::CHANGE, MatrixGate::CREATE_DELETE ] as $activity ) {
                $this->assertFalse(
                    MatrixGate::can( $uid, self::MEASUREMENTS, $activity, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
                    sprintf( '%s reached measurements:%s through a functional role', $role_key, $activity )
                );
            }
        }
    }

    /**
     * `team_manager` is a persona of its own, from the `tt_team_manager`
     * WordPress role, and holds `measurements [r, team]` on its own row.
     * Supersession names the `staff` persona and nothing else, so this
     * account is untouched — including when it also holds a functional
     * role, which a team manager routinely does.
     */
    public function test_a_team_manager_is_unaffected_by_the_supersession(): void {
        global $wpdb;

        $uid = self::factory()->user->create( [ 'role' => 'tt_team_manager' ] );
        $this->assertContains( 'team_manager', PersonaResolver::personasFor( $uid ) );

        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id'    => 1,
            'first_name' => 'Team',
            'last_name'  => 'Manager',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->assignFunctionalRole( $uid, $this->team_with_role, 'manager' );

        $this->assertTrue(
            FunctionalRoleGrants::holdsAnyFunctionalRole( $uid ),
            'the fixture must be the case supersession would engage on, if it named this persona'
        );
        $this->assertFalse(
            FunctionalRoleGrants::supersedes( $uid, 'team_manager', self::MEASUREMENTS ),
            'only the staff persona is superseded'
        );
        $this->assertTrue(
            MatrixGate::can( $uid, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'a team manager reads their squad\'s measurements on their own persona row'
        );
    }

    /**
     * #3392 is a different axis and stays one. Being admitted to
     * measurements by a functional role decides WHETHER this person
     * reaches the surface; `tt_measurement_definitions.visibility`
     * decides WHICH tests they see once there. Assigning the role must
     * therefore not move the visibility ladder at all — and in
     * particular must not hand out the medical level.
     */
    public function test_admission_by_functional_role_does_not_widen_the_per_test_visibility(): void {
        $uid    = $this->makeStaffUser();
        $before = RecordVisibility::forMeasurements( $uid );

        $this->assignFunctionalRole( $uid, $this->team_with_role, 'assistant_coach' );
        $after = RecordVisibility::forMeasurements( $uid );

        $this->assertSame(
            $before,
            $after,
            'the per-test visibility ladder is #3392\'s axis; admission must not move it'
        );
        $this->assertNotContains(
            RecordVisibility::LEVEL_MEDICAL,
            $after,
            'a test marked medical-only stays medical-only for somebody admitted by #3433'
        );
    }

    // ── upgrade behaviour ──────────────────────────────────────────────

    /**
     * The sentence an operator needs. An existing `tt_staff` account with
     * no functional role assigned is untouched: it keeps exactly the
     * access #3232 gave it. Narrowing is what happens when an academy
     * assigns a functional role — an act, not a default.
     */
    public function test_a_legacy_staff_account_with_no_functional_role_is_unchanged(): void {
        $uid = $this->makeStaffUser();
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->addLegacyStaffInjuryRow();

        $this->assertFalse(
            FunctionalRoleGrants::holdsAnyFunctionalRole( $uid ),
            'the fixture must be an account nobody has given a functional role'
        );
        $this->assertTrue(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'silently narrowing an existing physio takes access away mid-season with no signal'
        );
        $this->assertTrue(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->team_with_role )
        );
    }

    /**
     * #3433's half of the same sentence, and the reason the change needs
     * no migration. A Staff account nobody has given a functional role
     * keeps the `measurements [rc, team]` it has today — read AND change,
     * so the person entering heights on a Tuesday evening does not find
     * the form gone mid-season because a config file moved.
     */
    public function test_a_legacy_staff_account_keeps_its_measurement_grant(): void {
        $uid = $this->makeStaffUser();
        $this->scopeToTeam( $uid, $this->team_with_role );
        $this->addLegacyStaffMeasurementRow();

        $this->assertFalse( FunctionalRoleGrants::holdsAnyFunctionalRole( $uid ) );
        $this->assertTrue(
            MatrixGate::can( $uid, self::MEASUREMENTS, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_with_role )
        );
        $this->assertTrue(
            MatrixGate::can( $uid, self::MEASUREMENTS, MatrixGate::CHANGE, MatrixGate::SCOPE_TEAM, $this->team_with_role ),
            'narrowing silently takes the entry form away from somebody using it'
        );
    }

    /**
     * And the moment somebody assigns them one, the narrower shape
     * applies — including on a team the old row used to cover.
     */
    public function test_assigning_a_functional_role_is_what_narrows_the_account(): void {
        $uid = $this->makeStaffUser();
        $this->scopeToTeam( $uid, $this->team_without_role );
        $this->addLegacyStaffInjuryRow();

        $this->assertTrue(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_without_role )
        );

        $this->assignFunctionalRole( $uid, $this->team_with_role, 'kit_manager' );

        $this->assertFalse(
            MatrixGate::can( $uid, self::ENTITY, MatrixGate::READ, MatrixGate::SCOPE_TEAM, $this->team_without_role ),
            'once the academy has said what this person does, the legacy row stops speaking for them'
        );
    }

    // ── one answer, one place ──────────────────────────────────────────

    /**
     * The chokepoint assertion. `AuthorizationService::canRecordInjury()`
     * is the call site every injury REST route and view goes through; it
     * must land on the same answer as the gate, for the physio AND for
     * the kit manager. Two answers in two places is how `tt_view_players`
     * and its parent persona drifted apart in #3391.
     */
    public function test_the_injury_call_site_and_the_gate_agree(): void {
        $physio = $this->makeStaffUser();
        $this->assignFunctionalRole( $physio, $this->team_with_role, 'physio' );

        $kit = $this->makeStaffUser();
        $this->assignFunctionalRole( $kit, $this->team_with_role, 'kit_manager' );
        $this->scopeToTeam( $kit, $this->team_with_role );
        $this->addLegacyStaffInjuryRow();

        $player_id = $this->makePlayerOnTeam( $this->team_with_role );
        $this->assertGreaterThan( 0, $player_id );

        foreach ( [ MatrixGate::READ, MatrixGate::CHANGE ] as $activity ) {
            $gate_says = MatrixGate::can( $physio, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $this->team_with_role );
            $site_says = AuthorizationService::canRecordInjury( $physio, $player_id, $activity );
            $this->assertTrue( $gate_says, "the gate must allow the physio to {$activity}" );
            $this->assertSame( $gate_says, $site_says, "canRecordInjury disagreed with MatrixGate on {$activity} for the physio" );

            $gate_says = MatrixGate::can( $kit, self::ENTITY, $activity, MatrixGate::SCOPE_TEAM, $this->team_with_role );
            $site_says = AuthorizationService::canRecordInjury( $kit, $player_id, $activity );
            $this->assertFalse( $gate_says, "the gate must refuse the kit manager on {$activity}" );
            $this->assertSame( $gate_says, $site_says, "canRecordInjury disagreed with MatrixGate on {$activity} for the kit manager" );
        }
    }
}
