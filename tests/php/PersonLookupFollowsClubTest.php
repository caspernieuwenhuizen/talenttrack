<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\ClubIteration;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Authorization\PersonaResolver;

/**
 * #4967 — person lookups follow the current club. One account holds a person
 * row in each of two clubs, each with a team scope on its own club's team.
 * Inside one request, moving between the clubs with `ClubIteration::
 * withClub()`, each club is answered from its own rows, and so is the club
 * the request returns to.
 *
 * The staff persona holds `team` read at team scope only, so the role scope
 * on the person row decides the team grant on its own.
 */
final class PersonLookupFollowsClubTest extends WP_UnitTestCase {

    private int $club_a = 0;
    private int $club_b = 0;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $user = 0;
    private int $person_a = 0;
    private int $person_b = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $wpdb->hide_errors();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        $this->club_a = (int) CurrentClub::id();
        $this->club_b = $this->club_a + 1000;

        $this->team_a = $this->team( $this->club_a, 'Club A U13' );
        $this->team_b = $this->team( $this->club_b, 'Club B U13' );

        $this->user     = (int) self::factory()->user->create( [ 'role' => 'tt_staff' ] );
        $this->person_a = $this->person( $this->club_a );
        $this->person_b = $this->person( $this->club_b );
        $this->scope( $this->club_a, $this->person_a, $this->team_a );
        $this->scope( $this->club_b, $this->person_b, $this->team_b );
        $this->flush();
    }

    public function tear_down(): void {
        $this->flush();
        parent::tear_down();
    }

    public function test_each_club_is_answered_from_its_own_person_row_within_one_request(): void {
        $this->assertSame( [ 'staff' ], PersonaResolver::personasFor( $this->user ), 'Precondition: the account holds the staff persona.' );

        // Club A first, so its answers are the ones a stale memo would keep.
        $this->assertSame( $this->person_a, AuthorizationService::getPersonIdByUserId( $this->user ) );
        $this->assertTrue( $this->readsTeam( $this->team_a ) );
        $this->assertFalse( $this->readsTeam( $this->team_b ) );

        $in_b = ClubIteration::withClub( $this->club_b, fn(): array => [
            'person' => AuthorizationService::getPersonIdByUserId( $this->user ),
            'own'    => $this->readsTeam( $this->team_b ),
            'other'  => $this->readsTeam( $this->team_a ),
        ] );
        $this->assertSame( $this->person_b, $in_b['person'], 'club B reads its own person row' );
        $this->assertTrue( $in_b['own'], 'club B answers from its own role scope' );
        $this->assertFalse( $in_b['other'], 'club A\'s role scope grants nothing in club B' );

        // Back in club A: its own rows again, not the ones club B left behind.
        $this->assertSame( $this->person_a, AuthorizationService::getPersonIdByUserId( $this->user ) );
        $this->assertTrue( $this->readsTeam( $this->team_a ) );
        $this->assertFalse( $this->readsTeam( $this->team_b ) );
    }

    public function test_a_role_scope_row_of_another_club_is_not_read(): void {
        global $wpdb;
        // Club A's person, carrying a scope row filed under club B.
        $wpdb->delete( "{$wpdb->prefix}tt_user_role_scopes", [ 'person_id' => $this->person_a ] );
        $this->scope( $this->club_b, $this->person_a, $this->team_a );
        $this->flush();

        $this->assertSame( $this->person_a, AuthorizationService::getPersonIdByUserId( $this->user ) );
        $this->assertFalse( $this->readsTeam( $this->team_a ), 'a scope row of another club grants nothing here' );
        $this->assertSame( [], MatrixGate::teamIdsFor( $this->user, 'team', MatrixGate::READ ) );
    }

    private function readsTeam( int $team_id ): bool {
        return MatrixGate::can( $this->user, 'team', MatrixGate::READ, MatrixGate::SCOPE_TEAM, $team_id );
    }

    private function team( int $club, string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function person( int $club ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Club', 'last_name' => 'Person' . $club,
            'role_type' => 'staff', 'wp_user_id' => $this->user, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function scope( int $club, int $person, int $team ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        MatrixGate::clearCache();
        FunctionalRoleGrants::clearCache();
        PersonaResolver::clearCache();
        AuthorizationService::flushCache();
        wp_cache_flush();
    }
}
