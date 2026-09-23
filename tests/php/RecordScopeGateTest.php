<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #4004 — the gate that catches the seventh instance.
 *
 * `tools/check-record-scope.php` fails a PR whose by-id surface asks only a
 * club-wide capability. A gate is only worth having if it fails on the thing
 * it exists to catch AND passes on the thing it must not block, so both
 * directions are here.
 *
 * Snippets rather than the repository: what the gate says about the code as it
 * stands today is the gate's job to report, not this test's to freeze.
 */
final class RecordScopeGateTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/lib/record-scope.php';
    }

    // -----------------------------------------------------------------
    // REST — the callback and the permission callback are one unit
    // -----------------------------------------------------------------

    public function test_a_rest_route_taking_a_record_id_with_only_a_capability_is_reported(): void {
        $unit = $this->only( '<?php
            class C {
                public static function routes() {
                    register_rest_route( "talenttrack/v1", "/players/(?P<id>\\\\d+)", [
                        "methods"             => "GET",
                        "callback"            => [ self::class, "get_player" ],
                        "permission_callback" => [ self::class, "can_view" ],
                    ] );
                }
                public static function can_view() {
                    return current_user_can( "tt_view_players" );
                }
                public static function get_player( $r ) {
                    $player = ( new PlayersRepository() )->find( (int) $r["id"] );
                    return RestResponse::success( $player );
                }
            }
        ' );

        $this->assertFalse( $unit['passed'] );
        $this->assertFalse( $unit['marked'] );
        $this->assertSame( 'file.php::get_player + can_view', $unit['key'] );
    }

    public function test_the_check_may_sit_in_either_half_of_the_pair(): void {
        // The whole reason the pair is one unit: asking them separately called
        // a checked route unchecked about fifteen times in the audit.
        $in_permission = $this->only( '<?php
            class C {
                public static function routes() {
                    register_rest_route( "talenttrack/v1", "/players/(?P<id>\\\\d+)", [
                        "callback"            => [ self::class, "get_player" ],
                        "permission_callback" => [ self::class, "can_view" ],
                    ] );
                }
                public static function can_view( $r ) {
                    return AuthorizationService::canViewPlayer( get_current_user_id(), (int) $r["id"] );
                }
                public static function get_player( $r ) {
                    return RestResponse::success( ( new PlayersRepository() )->find( (int) $r["id"] ) );
                }
            }
        ' );
        $this->assertTrue( $in_permission['passed'] );

        $in_callback = $this->only( '<?php
            class C {
                public static function routes() {
                    register_rest_route( "talenttrack/v1", "/players/(?P<id>\\\\d+)", [
                        "callback"            => [ self::class, "get_player" ],
                        "permission_callback" => [ self::class, "can_view" ],
                    ] );
                }
                public static function can_view() {
                    return current_user_can( "tt_view_players" );
                }
                public static function get_player( $r ) {
                    $id = (int) $r["id"];
                    if ( ! AuthorizationService::canViewPlayer( get_current_user_id(), $id ) ) {
                        return RestResponse::notFound();
                    }
                    return RestResponse::success( ( new PlayersRepository() )->find( $id ) );
                }
            }
        ' );
        $this->assertTrue( $in_callback['passed'] );
    }

    public function test_a_route_whose_parameter_is_not_a_record_id_is_not_a_surface(): void {
        // A slug is a lookup key, not a record somebody owns.
        $this->assertSame( [], $this->units( '<?php
            class C {
                public static function routes() {
                    register_rest_route( "talenttrack/v1", "/docs/(?P<slug>[a-z-]+)", [
                        "callback"            => [ self::class, "get_doc" ],
                        "permission_callback" => "__return_true",
                    ] );
                }
                public static function get_doc( $r ) {
                    return ( new DocsRepository() )->find( $r["slug"] );
                }
            }
        ' ) );
    }

    // -----------------------------------------------------------------
    // Views and handlers
    // -----------------------------------------------------------------

    public function test_a_view_reading_an_id_and_loading_a_record_is_a_surface(): void {
        $unit = $this->only( $this->view(), 'src/Shared/Frontend/FrontendThingView.php' );

        $this->assertFalse( $unit['passed'] );
        $this->assertSame( 'view', $unit['kind'] );
    }

    public function test_the_same_method_outside_a_frontend_directory_is_not_a_view(): void {
        $this->assertSame( [], $this->units( $this->view(), 'src/Modules/Thing/Services/ThingService.php' ) );
    }

    public function test_a_check_one_call_deep_counts(): void {
        // The fix for this shape is nearly always a small private helper, and a
        // gate that could not see through one call would push people to inline
        // it — worse code, to satisfy a lint.
        $unit = $this->only( '<?php
            class V {
                public static function render() {
                    $id = absint( $_GET["id"] );
                    $thing = ( new Repo() )->find( $id );
                    if ( ! self::mayOpen( $id ) ) { $thing = null; }
                    return $thing;
                }
                private static function mayOpen( $id ) {
                    return ActivityTeamScope::coversActivity( get_current_user_id(), $id );
                }
            }
        ', 'src/Shared/Frontend/FrontendThingView.php' );

        $this->assertTrue( $unit['passed'] );
    }

    public function test_an_admin_post_handler_reading_an_id_is_a_surface(): void {
        $unit = $this->only( '<?php
            class P {
                public static function init() {
                    add_action( "admin_post_tt_delete_thing", [ __CLASS__, "handle_delete" ] );
                }
                public static function handle_delete() {
                    $id = absint( $_GET["id"] );
                    $thing = ( new Repo() )->findById( $id );
                    ( new Repo() )->delete( $id );
                }
            }
        ' );

        $this->assertFalse( $unit['passed'] );
        $this->assertSame( 'admin-post handler', $unit['kind'] );
    }

    public function test_an_exporter_collect_reading_its_entity_id_is_a_surface(): void {
        $unit = $this->only( '<?php
            class E {
                public function collect( $request ) {
                    $team_id = $request->entityId ?? 0;
                    return [ "events" => [] ];
                }
            }
        ', 'src/Modules/Export/Exporters/ThingExporter.php' );

        $this->assertFalse( $unit['passed'] );
        $this->assertSame( 'exporter', $unit['kind'] );
    }

    // -----------------------------------------------------------------
    // The marker
    // -----------------------------------------------------------------

    public function test_a_marker_with_an_approved_reason_passes(): void {
        $unit = $this->only( str_replace(
            '$id = absint',
            "/* record-scope-ok: no player dimension */\n                    \$id = absint",
            $this->view()
        ), 'src/Shared/Frontend/FrontendThingView.php' );

        $this->assertTrue( $unit['marked'] );
        $this->assertSame( '', $unit['bad_reason'] );
    }

    public function test_a_marker_with_an_invented_reason_is_reported_rather_than_accepted(): void {
        // A fourth reason is a decision about the access model, so it has to be
        // made in the gate and written down — not invented at a call site to
        // get a build green.
        $unit = $this->only( str_replace(
            '$id = absint',
            "/* record-scope-ok: trust me */\n                    \$id = absint",
            $this->view()
        ), 'src/Shared/Frontend/FrontendThingView.php' );

        $this->assertFalse( $unit['marked'] );
        $this->assertSame( 'trust me', $unit['bad_reason'] );
    }

    // -----------------------------------------------------------------
    // The known false positives the audit named
    // -----------------------------------------------------------------

    public function test_a_caller_scoped_load_passes_without_a_marker(): void {
        // `findForPlayer( $id, $player_id )` where the second argument came
        // from the session: the WHERE names the caller, so it cannot return
        // anybody else's row. That is a per-record check written as a query.
        $unit = $this->only( '<?php
            class V {
                public static function render() {
                    $user_id = get_current_user_id();
                    $id = absint( $_GET["id"] );
                    return ( new Repo() )->find( $id, $user_id );
                }
            }
        ', 'src/Shared/Frontend/FrontendMyThingView.php' );

        $this->assertTrue( $unit['passed'] );
    }

    public function test_the_recycle_bins_admin_escape_hatch_passes(): void {
        $unit = $this->only( '<?php
            class C {
                public static function routes() {
                    register_rest_route( "talenttrack/v1", "/players/(?P<id>\\\\d+)/permanent", [
                        "callback"            => [ self::class, "purge" ],
                        "permission_callback" => [ self::class, "can_purge" ],
                    ] );
                }
                public static function can_purge() {
                    return current_user_can( "tt_manage_recycle_bin" );
                }
                public static function purge( $r ) {
                    return ( new Repo() )->find( (int) $r["id"] );
                }
            }
        ' );

        $this->assertTrue( $unit['passed'] );
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /** A by-id view with no check — the base case several tests mutate. */
    private function view(): string {
        return '<?php
            class V {
                public static function render() {
                    $id = absint( $_GET["id"] );
                    $thing = ( new Repo() )->find( $id );
                    return $thing;
                }
            }
        ';
    }

    /**
     * @return array{key:string, name:string, line:int, kind:string, passed:bool, marked:bool, bad_reason:string}
     */
    private function only( string $code, string $relative = 'file.php' ): array {
        $units = $this->units( $code, $relative );
        $this->assertCount( 1, $units, 'the snippet should produce exactly one surface' );
        return $units[0];
    }

    /**
     * @return list<array{key:string, name:string, line:int, kind:string, passed:bool, marked:bool, bad_reason:string}>
     */
    private function units( string $code, string $relative = 'file.php' ): array {
        $config = require dirname( __DIR__, 2 ) . '/config/record_scope_checks.php';
        if ( strpos( $code, '<?php' ) === false ) {
            $code = "<?php\n" . $code;
        }
        return tt_rs_units( $code, $relative, $config );
    }
}
