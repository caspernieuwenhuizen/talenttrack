<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #4523 — `GET functional-roles/assignments` returns the role's translated
 * label in `role`, not the English label stored when the academy was set
 * up. An install seeded before the sentence-case labels still holds
 * "Head Coach" in `tt_functional_roles.label`. A custom role a club added
 * has no translation and keeps its own typed label. Clients key on
 * `functional_role_key`, which is unchanged.
 */
final class FunctionalRoleAssignmentLabelTest extends WP_UnitTestCase {

    private int $club = 0;
    private int $team = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server, $wpdb;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => 'Label U12' ] );
        $this->team = (int) $wpdb->insert_id;

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_seeded_role_reads_its_translated_label_and_keeps_its_key(): void {
        $role_id = $this->role( 'head_coach', 'Head Coach' );
        $this->assign( 'Kees', $role_id );

        $row = $this->rowFor( 'Kees' );
        $this->assertSame( 'head_coach', $row['functional_role_key'], 'clients key on the role key' );
        $this->assertSame( 'Head coach', $row['role'], 'the translated label, not the stored seed' );
    }

    public function test_a_custom_role_keeps_its_own_label(): void {
        $role_id = $this->role( 'video_analyst_x', 'Video analist' );
        $this->assign( 'Lotte', $role_id );

        $row = $this->rowFor( 'Lotte' );
        $this->assertSame( 'video_analyst_x', $row['functional_role_key'] );
        $this->assertSame( 'Video analist', $row['role'] );
    }

    /** The club's row for this key, created when absent; the stored label set to `$label`. */
    private function role( string $key, string $label ): int {
        global $wpdb;
        $t  = "{$wpdb->prefix}tt_functional_roles";
        $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$t} WHERE club_id = %d AND role_key = %s", $this->club, $key ) );
        if ( $id > 0 ) {
            $wpdb->update( $t, [ 'label' => $label ], [ 'id' => $id ] );
            return $id;
        }
        $wpdb->insert( $t, [ 'club_id' => $this->club, 'role_key' => $key, 'label' => $label ] );
        return (int) $wpdb->insert_id;
    }

    private function assign( string $first_name, int $role_id ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => $first_name, 'last_name' => 'Labelx',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->team,
            'person_id' => (int) $wpdb->insert_id, 'functional_role_id' => $role_id,
        ] );
    }

    /** @return array<string,mixed> */
    private function rowFor( string $first_name ): array {
        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/functional-roles/assignments' );
        $req->set_param( 'team_id', $this->team );
        $res = rest_do_request( $req );
        $this->assertSame( 200, $res->get_status() );
        $rows = (array) ( ( (array) ( ( (array) $res->get_data() )['data'] ?? [] ) )['rows'] ?? [] );
        foreach ( $rows as $row ) {
            if ( strpos( (string) ( $row['person_name'] ?? '' ), $first_name . ' ' ) === 0 ) return (array) $row;
        }
        $this->fail( 'no assignment row for ' . $first_name );
    }
}
