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
        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields' ] as $field ) {
            $this->assertSame( 'staff_for_player', $fields[ $field ] ?? null, $field );
        }
        $this->assertSame( [], RecordRedactor::fields( 'no_such_entity' ) );
    }

    public function test_redact_drops_registered_fields_for_a_reader_without_the_right(): void {
        $row = RecordRedactor::redact( 'players', $this->row(), $this->observer, $this->player );

        foreach ( [ 'guardian_name', 'guardian_email', 'guardian_phone', 'custom_fields' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $row, $field );
        }
        $this->assertSame( 'Sem', $row['first_name'], 'an unregistered field is left alone' );
        $this->assertSame( '2012-04-01', $row['date_of_birth'], 'an unregistered field is left alone' );
        $this->assertSame( 'kept', $row['something_else'], 'an unknown key is left alone' );
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
        $leaked = new WP_REST_Response( [ 'success' => true, 'data' => $this->row(), 'errors' => [] ] );
        $data   = (array) RecordRedactor::filterResponse( $leaked, rest_get_server(), $request )->get_data();
        $this->assertArrayNotHasKey( 'guardian_email', (array) $data['data'] );
        $this->assertSame( 'Sem', ( (array) $data['data'] )['first_name'] );

        wp_set_current_user( $this->hod );
        $kept = new WP_REST_Response( [ 'success' => true, 'data' => $this->row(), 'errors' => [] ] );
        $data = (array) RecordRedactor::filterResponse( $kept, rest_get_server(), $request )->get_data();
        $this->assertSame( self::EMAIL, ( (array) $data['data'] )['guardian_email'] );
    }

    public function test_a_route_outside_the_registry_is_not_touched(): void {
        wp_set_current_user( $this->observer );
        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/evaluations' );
        $response = new WP_REST_Response( [ 'data' => $this->row() ] );
        $data     = (array) RecordRedactor::filterResponse( $response, rest_get_server(), $request )->get_data();
        $this->assertSame( self::EMAIL, ( (array) $data['data'] )['guardian_email'] );
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
            $response = rest_do_request( new WP_REST_Request( 'GET', $path ) );
            $body     = (string) wp_json_encode( $response->get_data() );
            $checked++;

            foreach ( [ self::NAME, self::EMAIL, self::PHONE ] as $value ) {
                $this->assertStringNotContainsString( $value, $body, "{$path} hands the observer guardian contact" );
            }
            if ( preg_match( '#^/talenttrack/v1/players(?:/\d+)?$#', $path ) === 1 ) {
                foreach ( $fields as $field ) {
                    $this->assertStringNotContainsString( '"' . $field . '":', $body, "{$path} carries {$field}" );
                }
            }
            if ( $path === '/talenttrack/v1/players/' . $this->player ) {
                $reached = $response->get_status() === 200;
            }
        }

        $this->assertGreaterThan( 10, $checked, 'the contract must walk the player routes' );
        $this->assertTrue( $reached, 'the observer must reach the record, or the contract proves nothing' );

        wp_set_current_user( $this->hod );
        AuthorizationService::flushCache();
        $body = (string) wp_json_encode( rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player ) )->get_data() );
        $this->assertStringContainsString( self::EMAIL, $body, 'HoD still gets the guardian on the record' );
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

    /** @return array<string, mixed> */
    private function row(): array {
        return [
            'id'             => $this->player,
            'team_id'        => $this->team,
            'first_name'     => 'Sem',
            'date_of_birth'  => '2012-04-01',
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
