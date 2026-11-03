<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\People\PeopleRepository;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;

/**
 * #4721 — a coach's teams are the teams they work with today.
 *
 * The team-scope mirror row (`tt_user_role_scopes`, `role_id = 0`) carries
 * the envelope of the person's assignments to that team, so every reader
 * that filters on the row's dates drops an assignment that ended and one that
 * has not started. Assignments are written through `PeopleRepository`, the
 * way the Staff section writes them.
 */
final class TeamScopeMirrorDatesTest extends WP_UnitTestCase {

    private const MIGRATION = '/database/migrations/0316_team_scope_mirror_dates.php';

    private int $ended   = 0;
    private int $future  = 0;
    private int $current = 0;
    private int $role    = 0;

    private string $yesterday = '';
    private string $today     = '';
    private string $tomorrow  = '';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        global $wpdb;
        $club = (int) CurrentClub::id();
        foreach ( [ 'ended', 'future', 'current' ] as $name ) {
            $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Scope ' . $name ] );
            $this->{$name} = (int) $wpdb->insert_id;
        }
        $this->role = $this->functionalRole( 'head_coach' );

        $now             = new \DateTimeImmutable( current_time( 'Y-m-d' ) );
        $this->today     = $now->format( 'Y-m-d' );
        $this->yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );
        $this->tomorrow  = $now->modify( '+1 day' )->format( 'Y-m-d' );
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        parent::tear_down();
    }

    public function test_an_ended_and_a_future_assignment_are_out_of_every_team_scope(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $this->assertTrue( $repo->assignToTeam( $this->ended, $person, $this->role, '2020-08-01', $this->yesterday ) );
        $this->assertTrue( $repo->assignToTeam( $this->future, $person, $this->role, $this->tomorrow, null ) );
        $this->assertTrue( $repo->assignToTeam( $this->current, $person, $this->role, '2020-08-01', null ) );
        $this->flush();

        $this->assertSame( [ $this->current ], $this->coachTeams( $uid ) );
        $this->assertFalse( MatrixGate::can( $uid, 'team', 'read', 'team', $this->ended ), 'ended' );
        $this->assertFalse( MatrixGate::can( $uid, 'team', 'read', 'team', $this->future ), 'future' );
        $this->assertTrue( MatrixGate::can( $uid, 'team', 'read', 'team', $this->current ), 'current' );
        $this->assertSame( [ $this->current ], MatrixGate::teamIdsFor( $uid, 'team', 'read' ) );
    }

    public function test_no_current_assignment_means_no_team_scope_at_all(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();
        $repo->assignToTeam( $this->ended, $person, $this->role, '2020-08-01', $this->yesterday );
        $repo->assignToTeam( $this->future, $person, $this->role, $this->tomorrow, null );
        $this->flush();

        $this->assertSame( [], $this->coachTeams( $uid ) );
        $this->assertFalse( MatrixGate::canAnyScope( $uid, 'team', 'read' ) );
    }

    public function test_each_write_path_moves_the_mirror_dates(): void {
        [ $uid, $person ] = $this->coach();
        $repo = new PeopleRepository();

        // A new assignment.
        $repo->assignToTeam( $this->ended, $person, $this->role, '2020-08-01', $this->yesterday );
        $this->assertSame( [ '2020-08-01', $this->yesterday ], $this->mirrorDates( $person, $this->ended ) );

        // An edited end date: the team comes back on the day it is moved to.
        $assignment = $this->assignmentId( $person, $this->ended );
        $this->assertTrue( $repo->updateAssignment( $assignment, $this->role, '2020-08-01', $this->today ) );
        $this->assertSame( [ '2020-08-01', $this->today ], $this->mirrorDates( $person, $this->ended ) );
        $this->flush();
        $this->assertSame( [ $this->ended ], $this->coachTeams( $uid ), 'an assignment ending today is current' );

        // A start moved to today: the future team comes in on that day.
        $repo->assignToTeam( $this->future, $person, $this->role, $this->tomorrow, null );
        $this->flush();
        $this->assertNotContains( $this->future, $this->coachTeams( $uid ) );
        $repo->updateAssignment( $this->assignmentId( $person, $this->future ), $this->role, $this->today, null );
        $this->flush();
        $this->assertContains( $this->future, $this->coachTeams( $uid ), 'an assignment starting today is current' );

        // An unassignment removes the mirror row.
        $this->assertTrue( $repo->unassign( $assignment ) );
        $this->assertNull( $this->mirrorDates( $person, $this->ended ) );
    }

    public function test_the_envelope_spans_every_assignment_of_the_pair(): void {
        [ , $person ] = $this->coach();
        $repo      = new PeopleRepository();
        $assistant = $this->functionalRole( 'assistant_coach' );

        $repo->assignToTeam( $this->current, $person, $this->role, '2024-08-01', '2025-06-30' );
        $repo->assignToTeam( $this->current, $person, $assistant, '2021-08-01', '2022-06-30' );
        $this->assertSame( [ '2021-08-01', '2025-06-30' ], $this->mirrorDates( $person, $this->current ) );

        // An open end on either keeps the end open.
        $repo->updateAssignment( $this->assignmentId( $person, $this->current, $assistant ), $assistant, '2021-08-01', null );
        $this->assertSame( [ '2021-08-01', null ], $this->mirrorDates( $person, $this->current ) );
    }

    public function test_a_scope_row_an_operator_dated_is_left_alone(): void {
        [ , $person ] = $this->coach();
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => (int) CurrentClub::id(), 'person_id' => $person, 'role_id' => 5,
            'scope_type' => 'team', 'scope_id' => $this->current, 'start_date' => '2019-01-01', 'end_date' => '2019-12-31',
        ] );
        $operator = (int) $wpdb->insert_id;

        ( new PeopleRepository() )->assignToTeam( $this->current, $person, $this->role, '2024-08-01', null );
        $this->migration()->up();

        $this->assertSame(
            [ 'start_date' => '2019-01-01', 'end_date' => '2019-12-31' ],
            $wpdb->get_row( $wpdb->prepare( "SELECT start_date, end_date FROM {$wpdb->prefix}tt_user_role_scopes WHERE id = %d", $operator ), ARRAY_A )
        );
        $this->assertSame( [ '2024-08-01', null ], $this->mirrorDates( $person, $this->current ) );
    }

    public function test_the_migration_dates_the_existing_mirror_rows_once(): void {
        [ $uid, $person ] = $this->coach();
        ( new PeopleRepository() )->assignToTeam( $this->ended, $person, $this->role, '2020-08-01', $this->yesterday );

        // The shape every mirror row had before: open dates.
        global $wpdb;
        $wpdb->update(
            "{$wpdb->prefix}tt_user_role_scopes",
            [ 'start_date' => null, 'end_date' => null ],
            [ 'person_id' => $person, 'scope_type' => 'team', 'scope_id' => $this->ended, 'role_id' => 0 ]
        );
        $this->flush();
        $this->assertSame( [ $this->ended ], $this->coachTeams( $uid ), 'Precondition: the open row counts the ended team.' );

        $this->migration()->up();
        // Twice, because a re-run must change nothing.
        $this->migration()->up();

        $this->assertSame( [ '2020-08-01', $this->yesterday ], $this->mirrorDates( $person, $this->ended ) );
        $this->assertSame(
            1,
            (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}tt_user_role_scopes WHERE person_id = %d AND scope_type = 'team' AND scope_id = %d",
                $person, $this->ended
            ) ),
            'one mirror row, still'
        );
        $this->flush();
        $this->assertSame( [], $this->coachTeams( $uid ) );
    }

    // ---- helpers ------------------------------------------------------------------

    /** @return array{0:int,1:int} user id, person id */
    private function coach(): array {
        global $wpdb;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) CurrentClub::id(), 'first_name' => 'Scope', 'last_name' => 'Coach' . $uid,
            'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        return [ $uid, (int) $wpdb->insert_id ];
    }

    /** @return list<int> */
    private function coachTeams( int $uid ): array {
        $ids = array_map( static fn( $t ): int => (int) $t->id, QueryHelpers::get_teams_for_coach( $uid ) );
        sort( $ids );
        return array_values( array_intersect( $ids, [ $this->ended, $this->future, $this->current ] ) );
    }

    /** @return array{0:?string,1:?string}|null */
    private function mirrorDates( int $person, int $team ): ?array {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT start_date, end_date FROM {$wpdb->prefix}tt_user_role_scopes
              WHERE person_id = %d AND scope_type = 'team' AND scope_id = %d AND role_id = 0",
            $person, $team
        ) );
        return $row === null ? null : [ $row->start_date, $row->end_date ];
    }

    private function assignmentId( int $person, int $team, int $role = 0 ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_team_people WHERE person_id = %d AND team_id = %d AND functional_role_id = %d",
            $person, $team, $role > 0 ? $role : $this->role
        ) );
    }

    private function functionalRole( string $key ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $id   = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_functional_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $key, $club
        ) );
        if ( $id > 0 ) return $id;
        $wpdb->insert( "{$wpdb->prefix}tt_functional_roles", [ 'club_id' => $club, 'role_key' => $key, 'label' => $key ] );
        return (int) $wpdb->insert_id;
    }

    private function migration(): object {
        return require dirname( __DIR__, 2 ) . self::MIGRATION;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
