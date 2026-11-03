<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Shared\Frontend\Components\MaskedValue;

/**
 * #4690 — one chokepoint for the sensitive fields of a player-linked record.
 *
 * `config/sensitive_fields.php` names each field and the right that unlocks
 * it; `RecordRedactor` drops what a reader may not read, and runs below the
 * REST serializers so a controller that forgets to ask still cannot leak.
 *
 * Every refusal is paired with a reader who keeps the field, so a redactor
 * that dropped everything would fail here as surely as one that dropped
 * nothing.
 */
final class RecordRedactorTest extends WP_UnitTestCase {

    private const NAME  = 'Ger Visser';
    private const EMAIL = 'ger.visser.4690@example.org';
    private const PHONE = '0619283746';

    private const RECORD_ROUTE = '/talenttrack/v1/players/(?P<id>\d+)';
    private const LIST_ROUTE   = '/talenttrack/v1/players';
    private const DOB          = '2012-04-01';

    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $observer = 0;
    private int $hod      = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO14-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id'        => $this->club,
            'team_id'        => $this->team,
            'first_name'     => 'Sem',
            'last_name'      => 'Visser',
            'status'         => 'active',
            'guardian_name'  => self::NAME,
            'guardian_email' => self::EMAIL,
            'guardian_phone' => self::PHONE,
            'date_of_birth'  => self::DOB,
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->assertTrue(
            AuthorizationService::canViewPlayer( $this->observer, $this->player ),
            'the observer reads the player; what is hidden below is fields, not the record'
        );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_registry_names_the_guardian_fields(): void {
        $fields = RecordRedactor::fields( 'players' );
        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'parent_name', 'parent_link_html', 'custom_fields' ] as $field ) {
            $this->assertSame( 'staff_for_player', $fields[ $field ] ?? null, $field );
        }
        $this->assertSame( 'staff_or_family', $fields['date_of_birth'] ?? null );
        $this->assertArrayNotHasKey( 'birth_year', $fields, 'the birth year stays visible' );
        $this->assertSame( [], RecordRedactor::fields( 'no_such_entity' ) );
    }

    public function test_redact_drops_registered_fields_for_a_reader_without_the_right(): void {
        $row = RecordRedactor::redact( 'players', $this->row(), $this->observer, $this->player );

        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields', 'date_of_birth' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $row, $field );
        }
        $this->assertSame( 'Sem', $row['first_name'], 'an unregistered field is left alone' );
        $this->assertSame( 2012, $row['birth_year'], 'the birth year is left alone' );
        $this->assertSame( 'kept', $row['something_else'], 'an unknown key is left alone' );
    }

    /**
     * `staff_or_family`: the player and a linked guardian keep the date of
     * birth, and still do not get the guardian contact, which is staff only.
     */
    public function test_the_player_and_a_linked_guardian_keep_the_date_of_birth(): void {
        global $wpdb;
        $player_user = (int) self::factory()->user->create( [ 'role' => 'tt_player' ] );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'wp_user_id' => $player_user ], [ 'id' => $this->player ] );
        $parent = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $parent,
        ] );
        AuthorizationService::flushCache();

        foreach ( [ 'player' => $player_user, 'parent' => $parent ] as $label => $uid ) {
            $row = RecordRedactor::redact( 'players', $this->row(), $uid, $this->player );
            $this->assertSame( '2012-04-01', $row['date_of_birth'] ?? null, $label );
            $this->assertArrayNotHasKey( 'guardian_email', $row, $label );
        }

        $other = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        AuthorizationService::flushCache();
        $this->assertTrue( RecordRedactor::isMasked( 'players', 'date_of_birth', $other, $this->player ), 'an unlinked guardian' );
    }

    public function test_redact_keeps_registered_fields_for_a_reader_with_the_right(): void {
        $this->assertSame( $this->row(), RecordRedactor::redact( 'players', $this->row(), $this->hod, $this->player ) );
    }

    public function test_an_unregistered_entity_is_returned_as_it_came(): void {
        $this->assertSame( $this->row(), RecordRedactor::redact( 'no_such_entity', $this->row(), $this->observer, $this->player ) );
    }

    public function test_is_masked_answers_per_reader_and_only_for_registered_fields(): void {
        $this->assertTrue( RecordRedactor::isMasked( 'players', 'guardian_email', $this->observer, $this->player ) );
        $this->assertFalse( RecordRedactor::isMasked( 'players', 'guardian_email', $this->hod, $this->player ) );
        $this->assertFalse( RecordRedactor::isMasked( 'players', 'first_name', $this->observer, $this->player ) );
        $this->assertTrue( RecordRedactor::isMasked( 'players', 'guardian_email', 0, $this->player ), 'no reader, no field' );
    }

    /**
     * Below the serializer: a response from a registered route is redacted
     * even when the controller put the fields in, wherever the row sits.
     */
    public function test_the_response_filter_strips_what_a_controller_leaked(): void {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player );

        wp_set_current_user( $this->observer );
        $data = (array) RecordRedactor::filterResponse( $this->response( $this->row(), self::RECORD_ROUTE ), rest_get_server(), $request )->get_data();
        $this->assertArrayNotHasKey( 'guardian_email', (array) $data['data'] );
        $this->assertSame( 'Sem', ( (array) $data['data'] )['first_name'] );

        wp_set_current_user( $this->hod );
        $data = (array) RecordRedactor::filterResponse( $this->response( $this->row(), self::RECORD_ROUTE ), rest_get_server(), $request )->get_data();
        $this->assertSame( self::EMAIL, ( (array) $data['data'] )['guardian_email'] );
    }

    public function test_a_route_outside_the_registry_is_not_touched(): void {
        wp_set_current_user( $this->observer );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/evaluations' );
        $response = $this->response( $this->row(), '/talenttrack/v1/players/(?P<id>\d+)/evaluations' );
        $data     = (array) RecordRedactor::filterResponse( $response, rest_get_server(), $request )->get_data();
        $this->assertSame( self::EMAIL, ( (array) $data['data'] )['guardian_email'] );
    }

    /**
     * The server matches routes case-insensitively, so `/Players/12` reaches
     * the record route. The filter keys on the pattern the server matched,
     * which a path in another case cannot step around. A controller that
     * leaked is simulated by putting the fields back after the callback.
     */
    public function test_a_case_variant_path_is_still_redacted(): void {
        $record_path = '/talenttrack/v1/players/' . $this->player;
        $leak        = static function ( $response, $handler, $request ) use ( $record_path ) {
            if ( $response instanceof WP_REST_Response
                && $request instanceof WP_REST_Request
                && strtolower( $request->get_route() ) === $record_path ) {
                $data = (array) $response->get_data();
                $data['data'] = array_merge( (array) ( $data['data'] ?? [] ), [ 'guardian_email' => self::EMAIL ] );
                $response->set_data( $data );
            }
            return $response;
        };
        add_filter( 'rest_request_after_callbacks', $leak, 10, 3 );

        try {
            wp_set_current_user( $this->observer );
            $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/Players/' . $this->player ) );
            $this->assertSame( 200, $response->get_status(), 'the case-variant path reaches the record route' );
            $this->assertSame( self::RECORD_ROUTE, $response->get_matched_route() );
            $this->assertStringNotContainsString( self::EMAIL, (string) wp_json_encode( $response->get_data() ) );

            wp_set_current_user( $this->hod );
            $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/Players/' . $this->player ) );
            $this->assertStringContainsString( self::EMAIL, (string) wp_json_encode( $response->get_data() ) );
        } finally {
            remove_filter( 'rest_request_after_callbacks', $leak, 10 );
        }
    }

    /**
     * Rows also arrive as `stdClass` and `JsonSerializable` values. Both are
     * redacted, and the JSON keeps its shape: an object stays an object, so
     * an empty `custom_fields` is still `{}` and not `[]`.
     */
    public function test_object_rows_are_redacted_and_keep_their_shape(): void {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players' );
        $object  = (object) array_merge( $this->row(), [ 'custom_fields' => new \stdClass() ] );
        $json    = new class( $this->row() ) implements \JsonSerializable {
            /** @var array<string, mixed> */
            private array $row;
            /** @param array<string, mixed> $row */
            public function __construct( array $row ) { $this->row = $row; }
            /** @return array<string, mixed> */
            public function jsonSerialize(): array { return $this->row; }
        };
        $payload = [ 'rows' => [ $object, $json, new \stdClass() ], 'total' => 3 ];

        wp_set_current_user( $this->observer );
        $body = (string) wp_json_encode( RecordRedactor::filterResponse( $this->response( $payload, self::LIST_ROUTE ), rest_get_server(), $request )->get_data() );
        $this->assertStringNotContainsString( self::EMAIL, $body, 'neither the stdClass nor the JsonSerializable row keeps the e-mail' );
        $decoded = (array) json_decode( $body, true );
        $this->assertSame( 'Sem', $decoded['data']['rows'][0]['first_name'] );
        $this->assertSame( 'Sem', $decoded['data']['rows'][1]['first_name'] );
        $this->assertStringContainsString( ',{}]', $body, 'an empty object stays an object' );

        wp_set_current_user( $this->hod );
        $body = (string) wp_json_encode( RecordRedactor::filterResponse( $this->response( $payload, self::LIST_ROUTE ), rest_get_server(), $request )->get_data() );
        $this->assertStringContainsString( '"custom_fields":{}', $body, 'an empty custom_fields stays {} for a reader who keeps it' );
        $this->assertSame( 2, substr_count( $body, self::EMAIL ), 'HoD keeps both' );
    }

    /**
     * The registry names where a route's player rows sit, and the filter
     * reads only there: a nested record of another kind with an `id` of its
     * own is never asked the staff rule as if that id were a player's.
     */
    public function test_only_the_route_row_path_is_read_as_player_rows(): void {
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player );
        $nested  = [ 'id' => 987654, 'guardian_email' => 'nested@example.org', 'date_of_birth' => '2000-01-01' ];
        $row     = array_merge( $this->row(), [ 'related' => $nested ] );

        wp_set_current_user( $this->observer );
        $data = (array) RecordRedactor::filterResponse( $this->response( $row, self::RECORD_ROUTE ), rest_get_server(), $request )->get_data();
        $out  = (array) $data['data'];
        $this->assertArrayNotHasKey( 'guardian_email', $out, 'the record at the row path is redacted' );
        $this->assertSame( $nested, $out['related'], 'a node outside the row path is left as it came' );

        // A list route reads `data.rows.*`; the envelope itself is no row.
        $list = $this->response( [ 'id' => $this->player, 'guardian_email' => self::EMAIL, 'rows' => [ $this->row() ] ], self::LIST_ROUTE );
        $data = (array) RecordRedactor::filterResponse( $list, rest_get_server(), new WP_REST_Request( 'GET', '/talenttrack/v1/players' ) )->get_data();
        $this->assertSame( self::EMAIL, $data['data']['guardian_email'], 'only the rows are read' );
        $this->assertArrayNotHasKey( 'guardian_email', $data['data']['rows'][0] );
    }

    /**
     * The contract: every route keyed on a player, requested as the
     * observer, carries no registered field of the player and none of the
     * fixture's guardian values. A new route that leaks fails here.
     */
    public function test_no_player_route_hands_the_observer_a_registered_field(): void {
        wp_set_current_user( $this->observer );
        $fields  = array_keys( RecordRedactor::fields( 'players' ) );
        $checked = 0;
        $reached = false;

        foreach ( rest_get_server()->get_routes() as $pattern => $handlers ) {
            if ( strpos( $pattern, '/talenttrack/v1/players' ) !== 0 ) continue;
            $path = str_replace( [ '(?P<id>\d+)', '(?P<player_id>\d+)' ], (string) $this->player, $pattern );
            if ( strpos( $path, '(?P<' ) !== false ) continue;
            if ( ! $this->answersGet( (array) $handlers ) ) continue;

            AuthorizationService::flushCache();
            $response = $this->serve( new WP_REST_Request( 'GET', $path ) );
            $body     = (string) wp_json_encode( $response->get_data() );
            $checked++;

            foreach ( [ self::NAME, self::EMAIL, self::PHONE ] as $value ) {
                $this->assertStringNotContainsString( $value, $body, "{$path} hands the observer guardian contact" );
            }
            $this->assertStringNotContainsString( self::DOB, $body, "{$path} hands the observer the date of birth" );
            if ( in_array( $response->get_matched_route(), [ '/talenttrack/v1/players', self::RECORD_ROUTE ], true ) ) {
                foreach ( $fields as $field ) {
                    $this->assertStringNotContainsString( '"' . $field . '":', $body, "{$path} carries {$field}" );
                }
            }
            if ( $path === '/talenttrack/v1/players/' . $this->player ) {
                $reached = $response->get_status() === 200;
                $this->assertStringContainsString( '"birth_year":2012', $body, 'the birth year stays on the record' );
            }
        }

        $this->assertGreaterThan( 10, $checked, 'the contract must walk the player routes' );
        $this->assertTrue( $reached, 'the observer must reach the record, or the contract proves nothing' );

        wp_set_current_user( $this->hod );
        AuthorizationService::flushCache();
        $body = (string) wp_json_encode( $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player ) )->get_data() );
        $this->assertStringContainsString( self::EMAIL, $body, 'HoD still gets the guardian on the record' );
        $this->assertStringContainsString( self::DOB, $body, 'HoD still gets the date of birth' );
    }

    /** A list costs the same statements whatever its size: no query per player. */
    public function test_redacting_a_list_adds_no_statement_per_player(): void {
        $with_team = function ( int $n ): array {
            $rows = [];
            for ( $i = 1; $i <= $n; $i++ ) {
                $rows[] = [ 'id' => 900000 + $i, 'team_id' => $this->team, 'first_name' => 'P' . $i, 'guardian_email' => 'g' . $i . '@example.org' ];
            }
            return $rows;
        };
        $without_team = function ( int $n ): array {
            $rows = [];
            for ( $i = 1; $i <= $n; $i++ ) {
                $rows[] = [ 'id' => 800000 + $i, 'first_name' => 'P' . $i, 'guardian_email' => 'g' . $i . '@example.org' ];
            }
            return $rows;
        };

        foreach ( [ 'rows carry their team' => $with_team, 'teams read for the list' => $without_team ] as $label => $make ) {
            $this->queriesToRedact( $make( 5 ) ); // warm the per-request caches below the redactor
            $small = $this->queriesToRedact( $make( 20 ) );
            $large = $this->queriesToRedact( $make( 200 ) );
            $this->assertSame( $small, $large, "{$label}: statements for 20 rows vs 200 rows" );
        }

        AuthorizationService::flushCache();
        $out = RecordRedactor::redactRows( 'players', $with_team( 200 ), $this->observer );
        $this->assertCount( 200, $out );
        foreach ( $out as $row ) {
            $this->assertArrayNotHasKey( 'guardian_email', $row );
        }
    }

    public function test_the_masked_value_says_hidden_and_why(): void {
        $html = MaskedValue::html();
        $this->assertStringContainsString( 'class="tt-masked-value"', $html );
        $this->assertStringContainsString( 'Hidden', $html );
        $this->assertStringContainsString( 'Hidden for your role', $html );
        $this->assertStringContainsString( 'tt-screen-reader-text', $html );
    }

    // ---- fixtures ------------------------------------------------------

    /**
     * A request the way `WP_REST_Server::serve_request()` answers it:
     * `rest_do_request()` dispatches but skips `rest_post_dispatch`, which
     * is where the response filter runs.
     */
    private function serve( WP_REST_Request $request ): WP_REST_Response {
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @param mixed $data */
    private function response( $data, string $route ): WP_REST_Response {
        $response = new WP_REST_Response( [ 'success' => true, 'data' => $data, 'errors' => [] ] );
        $response->set_matched_route( $route );
        return $response;
    }

    /** @return array<string, mixed> */
    private function row(): array {
        return [
            'id'             => $this->player,
            'team_id'        => $this->team,
            'first_name'     => 'Sem',
            'date_of_birth'  => self::DOB,
            'birth_year'     => 2012,
            'guardian_name'  => self::NAME,
            'guardian_email' => self::EMAIL,
            'guardian_phone' => self::PHONE,
            'custom_fields'  => [ 'second_contact' => 'x' ],
            'something_else' => 'kept',
        ];
    }

    /** @param list<array<string, mixed>> $rows */
    private function queriesToRedact( array $rows ): int {
        global $wpdb;
        AuthorizationService::flushCache();
        $before = (int) $wpdb->num_queries;
        RecordRedactor::redactRows( 'players', $rows, $this->observer );
        return (int) $wpdb->num_queries - $before;
    }

    /** @param array<int|string, mixed> $handlers */
    private function answersGet( array $handlers ): bool {
        foreach ( $handlers as $handler ) {
            $methods = is_array( $handler ) ? (array) ( $handler['methods'] ?? [] ) : [];
            if ( ! empty( $methods['GET'] ) ) return true;
        }
        return false;
    }
}
