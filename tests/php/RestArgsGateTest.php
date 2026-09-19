<?php
namespace TT\Tests\Php;

use PHPUnit\Framework\TestCase;

/**
 * #3689 — the ratchet that stops a new write route shipping without `args`.
 *
 * Snippets rather than the repository: what the gate says about the code as
 * it stands is `tools/check-rest-args.php`'s job to report against the
 * baseline, not this test's to freeze. Both directions are here, because a
 * gate is only worth having if it fails on what it exists to catch and
 * passes on what it must not block.
 */
final class RestArgsGateTest extends TestCase {

    public static function setUpBeforeClass(): void {
        require_once dirname( __DIR__, 2 ) . '/tools/lib/rest-args.php';
    }

    public function test_a_post_route_without_args_is_reported(): void {
        $this->assertSame(
            [ 'src/X.php | /players | POST' ],
            $this->violations( "register_rest_route( self::NS, '/players', [ 'methods' => 'POST', 'callback' => [ __CLASS__, 'create' ], 'permission_callback' => '__return_true' ] );" )
        );
    }

    public function test_declared_args_pass_including_empty_and_a_call(): void {
        $this->assertSame( [], $this->violations( "
            register_rest_route( self::NS, '/a', [ 'methods' => 'POST', 'callback' => 'f', 'args' => [ 'name' => [ 'type' => 'string' ] ] ] );
            register_rest_route( self::NS, '/b', [ 'methods' => 'POST', 'callback' => 'f', 'args' => [] ] );
            register_rest_route( self::NS, '/c', [ 'methods' => 'PUT', 'callback' => 'f', 'args' => self::putArgs() ] );
        " ) );
    }

    public function test_reads_and_deletes_are_not_writes(): void {
        $this->assertSame( [], $this->violations( "
            register_rest_route( self::NS, '/a', [ 'methods' => 'GET', 'callback' => 'f' ] );
            register_rest_route( self::NS, '/b', [ 'methods' => \\WP_REST_Server::READABLE, 'callback' => 'f' ] );
            register_rest_route( self::NS, '/c', [ 'methods' => 'DELETE', 'callback' => 'f' ] );
            register_rest_route( self::NS, '/d', [ 'callback' => 'f' ] );
        " ) );
    }

    public function test_the_list_form_is_judged_per_endpoint(): void {
        $this->assertSame(
            [ 'src/X.php | /players/(?P<id>\\d+) | PATCH' ],
            $this->violations( "
                register_rest_route( self::NS, '/players/(?P<id>\\d+)', [
                    [ 'methods' => 'GET', 'callback' => 'f' ],
                    [ 'methods' => 'PUT', 'callback' => 'f', 'args' => [] ],
                    [ 'methods' => 'PATCH', 'callback' => function ( \$r ) { return [ 'a' => 1, 'b' => 2 ]; } ],
                    'schema' => [ __CLASS__, 'schema' ],
                ] );
            " )
        );
    }

    public function test_server_constants_count_as_writes(): void {
        $this->assertSame(
            [
                'src/X.php | /a | POST',
                'src/X.php | /b | POST,PUT,PATCH',
                'src/X.php | /c | GET,POST,PUT,PATCH,DELETE',
            ],
            $this->violations( "
                register_rest_route( self::NS, '/a', [ 'methods' => WP_REST_Server::CREATABLE, 'callback' => 'f' ] );
                register_rest_route( self::NS, '/b', [ 'methods' => \\WP_REST_Server::EDITABLE, 'callback' => 'f' ] );
                register_rest_route( self::NS, '/c', array( 'methods' => WP_REST_Server::ALLMETHODS, 'callback' => 'f' ) );
            " )
        );
    }

    public function test_a_route_is_identified_by_path_not_line(): void {
        $once  = $this->violations( "register_rest_route( self::NS, self::BASE . '/x', [ 'methods' => 'POST, PUT', 'callback' => 'f' ] );" );
        $moved = $this->violations( "\n\n\n// an edit above\nregister_rest_route(\n    self::NS,\n    self::BASE . '/x',\n    [ 'methods' => 'POST, PUT', 'callback' => 'f' ]\n);" );

        $this->assertSame( [ 'src/X.php | /teams/x | POST,PUT' ], $once );
        $this->assertSame( $once, $moved );
    }

    public function test_what_it_cannot_read_is_reported_by_its_expression(): void {
        $this->assertSame(
            [
                "src/X.php | \$base . '/' . \$action | POST",
                'src/X.php | /b | ?',
                'src/X.php | /c | ?',
            ],
            $this->violations( "
                register_rest_route( self::NS, \$base . '/' . \$action, [ 'methods' => 'POST', 'callback' => 'f', 'args' => [] ] );
                register_rest_route( self::NS, '/b', \$endpoint );
                register_rest_route( self::NS, '/c', [ 'methods' => \$verbs, 'callback' => 'f' ] );
            " )
        );
    }

    public function test_the_word_in_a_comment_a_string_or_a_method_is_not_a_route(): void {
        $this->assertSame( [], $this->violations( "
            // register_rest_route( self::NS, '/a', [ 'methods' => 'POST' ] );
            if ( function_exists( 'register_rest_route' ) ) {}
            \$this->register_rest_route( '/b', [ 'methods' => 'POST' ] );
        " ) );
    }

    public function test_the_baseline_line_round_trips(): void {
        $identity = 'src/X.php | /players/(?P<id>\\d+) | POST';
        $line     = tt_rest_args_baseline_line( $identity );
        $this->assertSame( [ $identity ], eval( 'return [' . $line . '];' ) );
    }

    /** @return list<string> */
    private function violations( string $body ): array {
        $code = "<?php\nclass X {\n    const BASE = '/teams';\n    public static function register() {\n" . $body . "\n    }\n}\n";
        return tt_rest_args_violations( $code, 'src/X.php' );
    }
}
