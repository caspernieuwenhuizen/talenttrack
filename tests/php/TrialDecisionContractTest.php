<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Domain\Vocabularies\Lookups\TrialCaseDecision;

/**
 * #3654 — `POST trial-cases/{id}/decision` says which field it wants, and
 * the case read gives the motivation back.
 *
 * The route declared no args, read the motivation from `notes`, and answered
 * a body that used any other name with "Justification must be at least 30
 * characters." A head of development who sent a 400-character justification
 * under `justification` was told their text was too short, with nothing to
 * read that would have said otherwise. Afterwards, `GET trial-cases/{id}`
 * returned `decision` but neither the motivation, nor who recorded it, nor
 * when — so there was no way to check what had been stored.
 */
final class TrialDecisionContractTest extends WP_UnitTestCase {

    private const ROUTE = '/talenttrack/v1/trial-cases/(?P<id>\d+)/decision';

    /** At least this long, per `TrialsRestController::DECISION_NOTES_MIN`. */
    private const MOTIVATION = 'Sterk in de duels en coachbaar; past binnen de speelwijze van de lichting.';

    private int $case = 0;
    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();
        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        global $wpdb;
        $p    = $wpdb->prefix;
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$p}tt_players", [ 'club_id' => $club, 'first_name' => 'Proef', 'last_name' => 'Speler', 'status' => 'trial' ] );
        $player = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_trial_tracks", [ 'club_id' => $club, 'slug' => 'std-' . uniqid(), 'name' => 'Standard' ] );
        $track = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_trial_cases", [
            'club_id'    => $club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        $this->case = (int) $wpdb->insert_id;

        // A trial manager may record the decision and read the synthesis.
        $this->user = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_the_route_declares_its_fields(): void {
        $args = [];
        foreach ( rest_get_server()->get_routes()[ self::ROUTE ] ?? [] as $handler ) {
            $args += (array) ( $handler['args'] ?? [] );
        }

        foreach ( [ 'decision', 'notes', 'strengths_summary', 'growth_areas' ] as $field ) {
            $this->assertArrayHasKey( $field, $args, "{$field} is not declared" );
            $this->assertNotSame( '', (string) ( $args[ $field ]['description'] ?? '' ), "{$field} has no description" );
        }

        $this->assertTrue( (bool) ( $args['decision']['required'] ?? false ) );
        $this->assertTrue( (bool) ( $args['notes']['required'] ?? false ) );
        $this->assertEqualsCanonicalizing(
            [ TrialCaseDecision::ADMIT, TrialCaseDecision::DENY_FINAL, TrialCaseDecision::DENY_ENCOURAGEMENT ],
            (array) ( $args['decision']['enum'] ?? [] ),
            'the three outcomes this route records are not advertised'
        );
    }

    /**
     * Absent altogether. `notes` is declared required, so core refuses it
     * before the callback runs and `CoreParamErrors` (#3689) answers in the
     * house envelope. Either way the refusal names the field instead of
     * describing a length.
     */
    public function test_a_missing_motivation_is_answered_as_a_missing_field(): void {
        [ $data, $status ] = $this->decide( [ 'decision' => TrialCaseDecision::ADMIT ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'notes', (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        $this->assertStringContainsString( 'notes', $this->messageOf( $data ) );
        $this->assertSame( '', $this->column( 'decision' ), 'nothing was recorded' );
    }

    /**
     * Sent but blank. Core lets an empty string past its required check, so
     * this is the one `checkBody()` catches, in the shared envelope.
     */
    public function test_a_blank_motivation_is_a_missing_field_not_a_short_one(): void {
        [ $data, $status ] = $this->decide( [ 'decision' => TrialCaseDecision::ADMIT, 'notes' => '' ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'notes', (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        $this->assertSame( '', $this->column( 'decision' ) );
    }

    public function test_a_motivation_sent_under_another_name_is_not_called_short(): void {
        // The report that opened #3654: a long justification, refused for its
        // length. It is the field name that is wrong, and the answer says so.
        [ $data, $status ] = $this->decide( [
            'decision'      => TrialCaseDecision::ADMIT,
            'justification' => self::MOTIVATION,
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'missing_fields', $data['errors'][0]['code'] ?? null );
        $message = $this->messageOf( $data );
        $this->assertStringContainsString( 'notes', $message, 'the refusal must name the field it wants' );
        $this->assertStringNotContainsString(
            '30',
            $message,
            'a misnamed motivation must not be reported as a short one'
        );
        $this->assertSame( '', $this->column( 'decision' ) );
    }

    public function test_a_key_the_route_does_not_take_is_named(): void {
        [ $data, $status ] = $this->decide( [
            'decision'      => TrialCaseDecision::ADMIT,
            'notes'         => self::MOTIVATION,
            'justification' => 'iets anders',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $data['errors'][0]['code'] ?? null );
        $this->assertSame( [ 'justification' ], (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        $this->assertEqualsCanonicalizing(
            [ 'decision', 'notes', 'strengths_summary', 'growth_areas' ],
            (array) ( $data['errors'][0]['details']['allowed'] ?? [] )
        );
    }

    public function test_a_short_motivation_says_how_short(): void {
        [ $data, $status ] = $this->decide( [
            'decision' => TrialCaseDecision::ADMIT,
            'notes'    => 'Prima speler.',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'bad_request', $data['errors'][0]['code'] ?? null );
        $details = (array) ( $data['errors'][0]['details'] ?? [] );
        $this->assertSame( 'notes', $details['field'] ?? null );
        $this->assertSame( 30, (int) ( $details['min_length'] ?? 0 ) );
        $this->assertSame( 13, (int) ( $details['length'] ?? 0 ) );
    }

    /**
     * The floor is a character count. Twenty-nine characters carrying two
     * accents weigh thirty-one bytes, so a byte count let them through a
     * floor the message describes in characters.
     */
    public function test_the_floor_counts_characters_not_bytes(): void {
        $accented = 'Zéér sterk in de duels, prima';
        $this->assertSame( 29, mb_strlen( $accented ) );
        $this->assertGreaterThanOrEqual( 30, strlen( $accented ), 'a byte count would have let it through' );

        [ $data, $status ] = $this->decide( [ 'decision' => TrialCaseDecision::ADMIT, 'notes' => $accented ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 29, (int) ( $data['errors'][0]['details']['length'] ?? 0 ) );
        $this->assertSame( '', $this->column( 'decision' ) );
    }

    /**
     * The route keeps its narrow surface: the rolling-membership decisions
     * belong to the workflow chain that spawns the next task, and recording
     * one over HTTP would move the case without moving the chain. Declaring
     * the enum moves the refusal one layer earlier than the controller's own
     * `in_array()`, and names the field it was about.
     */
    public function test_a_decision_outside_the_enum_is_refused(): void {
        [ $data, $status ] = $this->decide( [
            'decision' => TrialCaseDecision::CONTINUE_IN_TRIAL_GROUP,
            'notes'    => self::MOTIVATION,
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'invalid_field', $data['errors'][0]['code'] ?? null );
        $this->assertContains( 'decision', (array) ( $data['errors'][0]['details']['fields'] ?? [] ) );
        $this->assertSame( '', $this->column( 'decision' ) );
    }

    public function test_the_case_read_returns_the_motivation_and_its_stamps(): void {
        [ $data, $status ] = $this->decide( [
            'decision' => TrialCaseDecision::ADMIT,
            'notes'    => self::MOTIVATION,
        ] );
        $this->assertSame( 200, $status );
        $this->assertTrue( (bool) ( $data['data']['recorded'] ?? false ) );

        $case = $this->readCase();
        $this->assertSame( TrialCaseDecision::ADMIT, $case['decision'] ?? null );
        $this->assertSame( self::MOTIVATION, $case['decision_notes'] ?? null );
        $this->assertNotEmpty( $case['decision_made_at'] ?? null );
        $this->assertSame( $this->user, (int) ( $case['decision_made_by'] ?? 0 ) );
    }

    /**
     * "Not decided yet" is null, not an empty string and not a zero user id.
     * A consumer has to be able to tell an undecided case from one decided
     * by a user who has since been deleted.
     */
    public function test_an_undecided_case_reads_null_rather_than_blank(): void {
        $case = $this->readCase();

        foreach ( [ 'decision', 'decision_notes', 'decision_made_at', 'decision_made_by' ] as $field ) {
            $this->assertArrayHasKey( $field, $case, "{$field} is not in the payload at all" );
            $this->assertNull( $case[ $field ], "{$field} is not null on an undecided case" );
        }
    }

    public function test_the_list_leaves_the_motivation_out(): void {
        $this->decide( [ 'decision' => TrialCaseDecision::ADMIT, 'notes' => self::MOTIVATION ] );

        $request  = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases' );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $rows     = is_array( $data ) ? (array) ( $data['data']['cases'] ?? [] ) : [];

        $mine = [];
        foreach ( $rows as $row ) {
            if ( (int) ( $row['id'] ?? 0 ) === $this->case ) $mine = (array) $row;
        }

        $this->assertNotSame( [], $mine, 'the decided case is not in the list' );
        // The stamps ride along; a list that shows a decision and cannot say
        // when it was taken is half an answer.
        $this->assertNotEmpty( $mine['decision_made_at'] ?? null );
        $this->assertSame( $this->user, (int) ( $mine['decision_made_by'] ?? 0 ) );
        // The motivation does not: `GET /trial-cases` is gated on the
        // capability alone, `GET /trial-cases/{id}` per case.
        $this->assertArrayNotHasKey( 'decision_notes', $mine );
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function decide( array $body ): array {
        $request = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $this->case . '/decision' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );
        $response = rest_do_request( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /** @return array<string,mixed> */
    private function readCase(): array {
        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $this->case ) );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return is_array( $data ) ? (array) ( $data['data']['case'] ?? [] ) : [];
    }

    /**
     * The human-readable refusal. A route that declares `required` args has
     * core refuse the request before the callback, and `CoreParamErrors`
     * (#3689) re-wraps that in the house envelope — so both layers answer in
     * the same shape and this reads one field.
     *
     * @param array<string,mixed> $data
     */
    private function messageOf( array $data ): string {
        $errors = (array) ( $data['errors'] ?? [] );
        if ( $errors !== [] ) return (string) ( ( (array) $errors[0] )['message'] ?? '' );
        return (string) ( $data['message'] ?? '' );
    }

    /** One column of the case row, straight from the table. */
    private function column( string $name ): string {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tt_trial_cases WHERE id = %d", $this->case ),
            ARRAY_A
        );
        return (string) ( is_array( $row ) ? ( $row[ $name ] ?? '' ) : '' );
    }
}
