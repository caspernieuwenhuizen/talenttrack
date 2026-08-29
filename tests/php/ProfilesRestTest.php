<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Core\ModuleRegistry;
use TT\Infrastructure\Security\RolesService;
use TT\Shared\Modules\ProfileService;

/**
 * #3036 — REST smoke suite for the install-profile routes.
 *
 * The two things worth pinning are the capability gate and that
 * `GET /profiles/{slug}` really is a read. It is the preview screen's
 * only data source, and a preview that writes is the failure the whole
 * "nothing happens without a human seeing the diff" decision exists to
 * prevent — so it is asserted against a byte-comparable snapshot of live
 * state rather than by inspection.
 */
final class ProfilesRestTest extends WP_UnitTestCase {

    private const BASE = '/talenttrack/v1/profiles';

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb; $wpdb->hide_errors();
        global $wp_rest_server; $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->clearCaches();
    }

    public function tear_down(): void {
        global $wp_rest_server; $wp_rest_server = null;
        $this->clearCaches();
        parent::tear_down();
    }

    private function clearCaches(): void {
        foreach ( [ ModuleRegistry::class, FeatureRegistry::class ] as $class ) {
            $ref = new \ReflectionClass( $class );
            foreach ( [ 'stateCache', 'devStateCache' ] as $prop ) {
                $p = $ref->getProperty( $prop );
                $p->setAccessible( true );
                $p->setValue( null, null );
            }
        }
        ProfileService::resetConfigCache();
    }

    private function asAdmin(): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    // ------------------------------------------------------------------
    // The gate
    // ------------------------------------------------------------------

    /** @return array<string, array{0:string,1:string}> */
    public function provideRoutes(): array {
        return [
            'GET  list'   => [ 'GET',  self::BASE ],
            'GET  one'    => [ 'GET',  self::BASE . '/basics' ],
            'POST apply'  => [ 'POST', self::BASE . '/basics/apply' ],
        ];
    }

    /** @dataProvider provideRoutes */
    public function test_unauthenticated_request_is_denied( string $method, string $route ): void {
        wp_set_current_user( 0 );
        $status = rest_do_request( new WP_REST_Request( $method, $route ) )->get_status();
        $this->assertContains( $status, [ 401, 403 ], "$method $route denies with 401/403 (got $status)" );
    }

    /** @dataProvider provideRoutes */
    public function test_a_user_without_the_capability_is_denied( string $method, string $route ): void {
        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );
        $status = rest_do_request( new WP_REST_Request( $method, $route ) )->get_status();
        $this->assertSame( 403, $status, "$method $route is 403 without tt_manage_modules (got $status)" );
    }

    // ------------------------------------------------------------------
    // GET /profiles
    // ------------------------------------------------------------------

    public function test_list_names_both_shipped_profiles_and_a_neutral_current(): void {
        $this->asAdmin();

        $res = rest_do_request( new WP_REST_Request( 'GET', self::BASE ) );
        $this->assertSame( 200, $res->get_status() );

        $body = (array) $res->get_data();
        $this->assertArrayHasKey( 'profiles', $body );
        $this->assertArrayHasKey( 'current', $body );
        $this->assertArrayHasKey( 'divergence', $body );

        $slugs = array_column( (array) $body['profiles'], 'slug' );
        $this->assertContains( 'basics', $slugs );
        $this->assertContains( 'full', $slugs );

        // An install that was never put on a profile is a neutral state,
        // not an error, and carries no divergence to report.
        $this->assertNull( $body['current'] );
        $this->assertNull( $body['divergence'] );

        foreach ( (array) $body['profiles'] as $profile ) {
            $this->assertNotSame( '', (string) $profile['label'] );
            $this->assertNotSame( '', (string) $profile['description'] );
        }
    }

    // ------------------------------------------------------------------
    // GET /profiles/{slug}
    // ------------------------------------------------------------------

    public function test_show_returns_the_diff_and_writes_nothing(): void {
        $this->asAdmin();

        $before = $this->liveState();

        $res = rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/basics' ) );
        $this->assertSame( 200, $res->get_status() );

        $body = (array) $res->get_data();
        $this->assertSame( 'basics', $body['slug'] );
        $this->assertNotEmpty( $body['changes'] );

        foreach ( (array) $body['changes'] as $row ) {
            $this->assertArrayHasKey( 'id', $row );
            $this->assertArrayHasKey( 'skipped_reason', $row );
            $this->assertContains( $row['kind'], [ 'module', 'feature' ] );
            $this->assertNotSame( $row['from'], $row['to'] );
        }

        $this->clearCaches();
        $this->assertSame( $before, $this->liveState(), 'GET /profiles/{slug} changed live state.' );
    }

    public function test_an_unknown_slug_is_404_not_400(): void {
        $this->asAdmin();

        foreach ( [ 'GET ' . self::BASE . '/nope', 'POST ' . self::BASE . '/nope/apply' ] as $spec ) {
            [ $method, $route ] = explode( ' ', $spec, 2 );
            $status = rest_do_request( new WP_REST_Request( trim( $method ), $route ) )->get_status();
            $this->assertSame( 404, $status, "$spec is 404 (got $status)" );
        }
    }

    // ------------------------------------------------------------------
    // POST /profiles/{slug}/apply
    // ------------------------------------------------------------------

    public function test_apply_writes_and_reports_zero_divergence(): void {
        $this->asAdmin();

        $res = rest_do_request( new WP_REST_Request( 'POST', self::BASE . '/basics/apply' ) );
        $this->assertSame( 200, $res->get_status() );

        $body = (array) $res->get_data();
        $this->assertSame( 'basics', $body['profile'] );
        $this->assertNotEmpty( $body['applied'] );
        $this->assertSame( 0, $body['divergence'] );

        $this->clearCaches();
        $this->assertFalse( ModuleRegistry::isEnabled( 'TT\\Modules\\Training\\TrainingModule' ) );
    }

    public function test_apply_honours_exclude_and_names_the_reason(): void {
        $this->asAdmin();

        $preview = (array) rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/basics' ) )->get_data();
        $rows    = array_values( array_filter(
            (array) $preview['changes'],
            static fn( array $r ): bool => $r['skipped_reason'] === null
        ) );
        $this->assertNotEmpty( $rows );
        $held = (string) $rows[0]['id'];

        $request = new WP_REST_Request( 'POST', self::BASE . '/basics/apply' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [ 'exclude' => [ $held ] ] ) );

        $body = (array) rest_do_request( $request )->get_data();

        $skipped = [];
        foreach ( (array) $body['skipped'] as $row ) {
            $skipped[ (string) $row['id'] ] = (string) $row['reason'];
        }
        $this->assertArrayHasKey( $held, $skipped );
        $this->assertSame( 'excluded', $skipped[ $held ] );
        $this->assertSame( 1, $body['divergence'] );
    }

    public function test_apply_with_everything_excluded_is_a_200_no_op(): void {
        $this->asAdmin();

        $preview = (array) rest_do_request( new WP_REST_Request( 'GET', self::BASE . '/basics' ) )->get_data();
        $ids     = array_column( (array) $preview['changes'], 'id' );

        $before = $this->liveState();

        $request = new WP_REST_Request( 'POST', self::BASE . '/basics/apply' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( wp_json_encode( [ 'exclude' => $ids ] ) );

        $res = rest_do_request( $request );
        $this->assertSame( 200, $res->get_status(), 'Excluding everything is a no-op, not an error.' );

        $body = (array) $res->get_data();
        $this->assertSame( [], $body['applied'] );

        $this->clearCaches();
        $this->assertSame( $before, $this->liveState() );
    }

    /** @return array<string,bool> */
    private function liveState(): array {
        $out = [];
        foreach ( ModuleRegistry::allWithState() as $row ) {
            $out[ 'module:' . $row['class'] ] = $row['enabled'];
        }
        foreach ( [ 'analytics_explorer', 'comms_sms_channel', 'report_minutes_audit' ] as $key ) {
            $out[ 'feature:' . $key ] = FeatureRegistry::configuredState( $key );
        }
        ksort( $out );
        return $out;
    }
}
