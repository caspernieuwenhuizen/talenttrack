<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3819 (slice 5 of #3603) — `PUT /measurements/results/{id}` is partial.
 *
 * `MeasurementResultsRepository::update()` has always been partial: it
 * checks `array_key_exists` per column, so a patch touches what it names
 * and nothing else. The controller undid that. It built the payload as
 *
 *     'recorded_date' => sanitize_text_field( (string) ( $r['recorded_date'] ?? '' ) ),
 *     'value_numeric' => $r['value_numeric'] ?? '',
 *     'value_text'    => sanitize_text_field( (string) ( $r['value_text'] ?? '' ) ),
 *
 * so all three keys were always present and an absent one arrived as `''`.
 * A body carrying only `value_numeric` therefore wrote `recorded_date = ''`
 * and cleared `value_text`. Moving a result's date also moves it between
 * seasons, which is how a measurement leaves the window it was counted in
 * without anybody touching it.
 *
 * The tests below pin the three properties the route owes: an omitted field
 * is left alone, a field sent blank is still cleared on purpose, and a body
 * that names nothing writable is refused rather than answered 200 over an
 * untouched row.
 */
final class MeasurementResultPartialUpdateTest extends WP_UnitTestCase {

    private int $admin = 0;
    private int $player = 0;
    private int $definition = 0;
    private int $result = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();

        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $club, 'first_name' => 'Meet', 'last_name' => 'Speler', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_measurement_definitions", [
            'club_id'     => $club,
            'uuid'        => wp_generate_uuid4(),
            'category_id' => 0,
            'name'       => 'Sprint 10 m',
            'value_type' => 'numeric',
            'unit'       => 's',
            'frequency'  => 'adhoc',
            'direction'  => 'lower',
            'is_active'  => 1,
        ] );
        $this->definition = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_measurement_results", [
            'club_id'       => $club,
            'uuid'          => wp_generate_uuid4(),
            'player_id'     => $this->player,
            'definition_id' => $this->definition,
            'recorded_date' => '2026-03-01',
            'value_numeric' => 2.05,
            'value_text'    => 'Harde ondergrond.',
        ] );
        $this->result = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- helpers -------------------------------------------------- */

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function put( array $body ): array {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/measurements/results/' . $this->result );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );

        $response = rest_get_server()->dispatch( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /** The stored row, as an associative array. @return array<string,mixed> */
    private function row(): array {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT recorded_date, value_numeric, value_text FROM {$wpdb->prefix}tt_measurement_results WHERE id = %d",
                $this->result
            ),
            ARRAY_A
        );
        return is_array( $row ) ? $row : [];
    }

    /* ---- the contract --------------------------------------------- */

    public function test_a_body_with_only_a_number_leaves_the_date_and_the_note_alone(): void {
        [ , $status ] = $this->put( [ 'value_numeric' => 1.98 ] );
        $this->assertSame( 200, $status, 'the update is accepted' );

        $row = $this->row();
        $this->assertSame( '1.98', (string) (float) $row['value_numeric'], 'the number is the one that was sent' );
        $this->assertSame( '2026-03-01', (string) $row['recorded_date'], 'the date the body did not mention is untouched' );
        $this->assertSame( 'Harde ondergrond.', (string) $row['value_text'], 'the note the body did not mention is untouched' );
    }

    public function test_a_body_with_only_a_date_leaves_the_number_alone(): void {
        [ , $status ] = $this->put( [ 'recorded_date' => '2026-03-08' ] );
        $this->assertSame( 200, $status );

        $row = $this->row();
        $this->assertSame( '2026-03-08', (string) $row['recorded_date'] );
        $this->assertSame( '2.05', (string) (float) $row['value_numeric'], 'the number the body did not mention is untouched' );
    }

    public function test_a_field_sent_blank_is_still_cleared(): void {
        [ , $status ] = $this->put( [ 'value_text' => '' ] );
        $this->assertSame( 200, $status );

        $row = $this->row();
        $this->assertNull( $row['value_text'], 'a note sent empty is cleared, which is what empty means' );
        $this->assertSame( '2026-03-01', (string) $row['recorded_date'], 'and nothing else moves with it' );
    }

    public function test_a_body_that_names_nothing_writable_is_refused(): void {
        [ , $status ] = $this->put( [] );
        $this->assertSame( 400, $status, 'an empty body is refused rather than answered 200 over an untouched row' );
        $this->assertSame( '2026-03-01', (string) $this->row()['recorded_date'] );
    }

    public function test_an_undeclared_key_is_refused_and_nothing_is_written(): void {
        [ $data, $status ] = $this->put( [ 'value_numeric' => 9.99, 'recorded_on' => '2026-04-01' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'recorded_on' ], $data['errors'][0]['details']['fields'] ?? null );
        $this->assertSame( '2.05', (string) (float) $this->row()['value_numeric'], 'the refused body wrote nothing' );
    }
}
