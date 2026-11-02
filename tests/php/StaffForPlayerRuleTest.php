<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Shared\Frontend\FrontendPlayerDetailView;

/**
 * #4689 — "may read every player" is not "works with this player".
 *
 * `isStaffForPlayer()` used to say yes to anyone holding `players.view`
 * globally, so the read-only observer passed every check that keeps a
 * family's details with the people around a child: the guardians card, the
 * record's guardian fields, the guardian columns of a squad list. The rule is
 * now a staff role on the player's team, or a role that may change players
 * academy-wide.
 *
 * Every refusal is paired with the same surface answering a persona that
 * should get through, and the observer's own read of the player is asserted
 * first: without it, a refusal would prove nothing about the split.
 */
final class StaffForPlayerRuleTest extends WP_UnitTestCase {

    private int $club    = 0;
    private int $team_a  = 0;
    private int $team_b  = 0;
    private int $player  = 0;

    /** @var array<string, int> */
    private array $users = [];

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-1' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO13-2' ] );
        $this->team_b = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_players", [
            'club_id'        => $this->club,
            'team_id'        => $this->team_a,
            'first_name'     => 'Sem',
            'last_name'      => 'Visser',
            'status'         => 'active',
            'guardian_name'  => 'Ger Visser',
            'guardian_email' => 'ger.visser@example.org',
            'guardian_phone' => '0612345678',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->users['admin']     = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $this->users['hod']       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->users['head_a']    = $this->makeTeamStaff( $this->team_a, 'head_coach' );
        $this->users['assist_a']  = $this->makeTeamStaff( $this->team_a, 'assistant_coach' );
        $this->users['coach_b']   = $this->makeTeamStaff( $this->team_b, 'head_coach' );
        $this->users['observer']  = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );

        $this->users['parent'] = (int) self::factory()->user->create( [
            'role'         => 'tt_parent',
            'display_name' => 'Marieke Ouder',
            'user_email'   => 'marieke@example.org',
        ] );
        ( new PlayerParentsRepository() )->link( $this->player, $this->users['parent'], true );

        $this->users['player'] = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$p}tt_players", [ 'wp_user_id' => $this->users['player'] ], [ 'id' => $this->player ] );

        AuthorizationService::flushCache();

        $this->assertContains(
            'readonly_observer',
            PersonaResolver::personasFor( $this->users['observer'] ),
            'the observer fixture must resolve to its persona'
        );
        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->users['observer'], $this->player ),
            'the observer reads the player; every refusal below is about working with them, not reading them'
        );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['tab'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_staff_rule_per_persona(): void {
        $expected = [
            'admin'    => true,
            'hod'      => true,
            'head_a'   => true,
            'assist_a' => true,
            'coach_b'  => false,
            'observer' => false,
            'parent'   => false,
            'player'   => false,
        ];

        foreach ( $expected as $key => $staff ) {
            $uid = $this->users[ $key ];
            $this->assertSame( $staff, AuthorizationService::isStaffForPlayer( $uid, $this->player ), $key );
            $this->assertSame(
                $staff,
                AuthorizationService::staffForPlayers( $uid, [ $this->player => $this->team_a ] )[ $this->player ],
                $key . ': the roster form gives the same answer'
            );
        }
    }

    public function test_the_record_carries_guardian_contact_for_staff_only(): void {
        $hod = $this->getPlayer( $this->users['hod'] );
        $this->assertSame( 'ger.visser@example.org', $hod['guardian_email'] ?? null, 'HoD keeps the guardian fields' );

        $coach = $this->getPlayer( $this->users['head_a'] );
        $this->assertSame( 'ger.visser@example.org', $coach['guardian_email'] ?? null, 'the team head coach keeps them' );

        $observer = $this->getPlayer( $this->users['observer'] );
        $this->assertSame( $this->player, (int) ( $observer['id'] ?? 0 ), 'the observer still gets the record' );
        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $observer, $field );
        }
    }

    public function test_the_guardians_card_is_for_staff_only(): void {
        $this->assertStringContainsString( 'Marieke Ouder', $this->renderProfile( $this->users['hod'] ), 'HoD sees the card' );
        $this->assertStringContainsString( 'Marieke Ouder', $this->renderProfile( $this->users['head_a'] ), 'the team head coach sees it' );

        $html = $this->renderProfile( $this->users['observer'] );
        $this->assertStringContainsString( 'Visser', $html, 'the profile renders for the observer' );
        $this->assertStringNotContainsString( 'Marieke Ouder', $html, 'no co-guardian names' );
        $this->assertStringNotContainsString( 'marieke@example.org', $html, 'no co-guardian e-mail' );
    }

    public function test_the_squad_list_carries_guardian_contact_for_staff_only(): void {
        $this->assertSame( 'ger.visser@example.org', $this->exportedGuardianEmail( $this->users['admin'] ) );
        $this->assertSame( 'ger.visser@example.org', $this->exportedGuardianEmail( $this->users['hod'] ) );
        $this->assertSame( 'ger.visser@example.org', $this->exportedGuardianEmail( $this->users['head_a'] ) );
        $this->assertSame( '', $this->exportedGuardianEmail( $this->users['observer'] ) );
    }

    public function test_the_federation_envelope_carries_guardian_contact_for_staff_only(): void {
        $this->assertSame( 'ger.visser@example.org', $this->federationGuardian( $this->users['hod'] )['email'] );
        $this->assertSame( 'ger.visser@example.org', $this->federationGuardian( $this->users['head_a'] )['email'] );

        $observer = $this->federationGuardian( $this->users['observer'] );
        $this->assertSame( [ 'name' => '', 'email' => '', 'phone' => '' ], $observer );
    }

    // ---- fixtures ------------------------------------------------------

    /** @return array<string, mixed> */
    private function getPlayer( int $user_id ): array {
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player ) );
        $this->assertSame( 200, $response->get_status() );

        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $data = is_array( $data ) ? $data : [];
        return isset( $data['data'] ) && is_array( $data['data'] ) ? $data['data'] : $data;
    }

    private function renderProfile( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['tab'] = 'profile';
        ob_start();
        FrontendPlayerDetailView::render( $this->player, $user_id, false );
        return (string) ob_get_clean();
    }

    private function exportedGuardianEmail( int $user_id ): string {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'players_list' );
        $this->assertNotNull( $exporter );
        $result = $exporter->collect( new ExportRequest(
            'players_list', 'csv', $this->club, $user_id, null, [ 'team_id' => $this->team_a, 'status' => 'all' ]
        ) );

        foreach ( (array) $result['rows'] as $row ) {
            if ( (int) $row[0] === $this->player ) return (string) $row[9];
        }
        $this->fail( 'the player is not in the squad list' );
    }

    /** @return array<string, string> */
    private function federationGuardian( int $user_id ): array {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'federation_json' );
        $this->assertNotNull( $exporter );
        $result = $exporter->collect( new ExportRequest(
            'federation_json', 'json', $this->club, $user_id, null, [ 'team_id' => $this->team_a, 'status' => 'all' ]
        ) );

        foreach ( (array) $result['teams'] as $team ) {
            foreach ( (array) $team['players'] as $row ) {
                if ( (int) $row['id'] === $this->player ) return (array) $row['guardian'];
            }
        }
        $this->fail( 'the player is not in the federation envelope' );
    }

    /** A staff member on one team, built the way production builds one (see GuardianContactsAuditTest). */
    private function makeTeamStaff( int $team_id, string $role_key ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            $role_key,
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, "the {$role_key} auth role must be seeded" );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Staf',
            'last_name'  => $role_key,
            'role_type'  => $role_key,
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'       => $this->club,
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => $role_key,
            'is_head_coach' => $role_key === 'head_coach' ? 1 : 0,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }
}
