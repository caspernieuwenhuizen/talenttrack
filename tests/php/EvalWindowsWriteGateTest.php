<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Authorization\LegacyCapMapper;
use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * #3610 — the evaluation windows are an analytics write.
 *
 * `PUT eval-coverage/windows` and the windows form both checked the read
 * cap, so any read-only grant of analytics could move the windows every
 * coach's coverage and "window closing" alert is measured against.
 */
final class EvalWindowsWriteGateTest extends WP_UnitTestCase {

    /** @var callable|null */
    private $cap_filter = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        if ( $this->cap_filter !== null ) {
            remove_filter( 'user_has_cap', $this->cap_filter, 999 );
            $this->cap_filter = null;
        }
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_write_cap_bridges_to_analytics_change(): void {
        $this->assertSame( [ 'analytics', 'change' ], LegacyCapMapper::tupleFor( 'tt_edit_analytics' ) );
    }

    public function test_a_read_only_holder_cannot_change_the_windows(): void {
        $reader = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        // Analytics read and nothing else, after the matrix bridge has run.
        $this->cap_filter = static function ( $allcaps, $caps, $args, $user ) use ( $reader ) {
            if ( is_object( $user ) && (int) $user->ID === $reader ) {
                $allcaps['tt_view_analytics'] = true;
                unset( $allcaps['tt_edit_analytics'] );
            }
            return $allcaps;
        };
        add_filter( 'user_has_cap', $this->cap_filter, 999, 4 );
        wp_set_current_user( $reader );

        $this->assertSame( 200, $this->request( 'GET' )->get_status(), 'reading stays open' );
        $this->assertSame( 403, $this->request( 'PUT', [ 'windows' => [] ] )->get_status() );
    }

    public function test_head_of_development_and_admin_still_can(): void {
        foreach ( [ 'tt_head_dev', 'administrator' ] as $role ) {
            wp_set_current_user( self::factory()->user->create( [ 'role' => $role ] ) );
            $this->assertSame( 200, $this->request( 'PUT', [ 'windows' => [] ] )->get_status(), $role );
        }
    }

    public function test_the_migration_gives_existing_installs_the_change_right(): void {
        global $wpdb;
        $table = "{$wpdb->prefix}tt_authorization_matrix";
        $wpdb->delete( $table, [ 'entity' => 'analytics', 'activity' => 'change' ] );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0272_authorization_seed_topup_analytics_change.php';
        $migration->up();
        $migration->up();

        $personas = $wpdb->get_col( "SELECT persona FROM {$table} WHERE entity = 'analytics' AND activity = 'change' ORDER BY persona" );
        $this->assertSame( [ 'academy_admin', 'head_of_development' ], $personas );
    }

    /** @param array<string,mixed> $body */
    private function request( string $method, array $body = [] ): \WP_REST_Response {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/eval-coverage/windows' );
        if ( $body ) {
            $request->set_header( 'content-type', 'application/json' );
            $request->set_body( (string) wp_json_encode( $body ) );
        }
        return rest_do_request( $request );
    }
}
