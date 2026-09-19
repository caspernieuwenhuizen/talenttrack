<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Letters\TrialLetterService;
use TT\Modules\Trials\Rest\TrialsRestController;

/**
 * #3683 — recording that a trial letter reached the family.
 *
 * Generating a letter has never sent it, and nothing recorded the human
 * step that followed, so the head of development reopening a case saw
 * "Active" whether the letter had gone out that afternoon or was still
 * sitting unprinted. These routes write that down; TalentTrack still
 * sends nothing itself.
 *
 * The ownership test matters as much as the happy path: a letter id is a
 * guessable integer, and stamping a delivery onto another family's
 * letter has to be a 404, not a silent write.
 */
final class TrialLetterDeliveryRestTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;
    private int $other_case_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $this->case_id       = $this->makeCase( 'Delivery', 'Subject' );
        $this->other_case_id = $this->makeCase( 'Other', 'Family' );
    }

    private function makeCase( string $first, string $last ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => $first,
            'last_name'  => $last,
            'status'     => 'trial',
        ] );
        $player = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id' => $this->club,
            'name'    => 'Standard',
        ] );
        $track = (int) $wpdb->insert_id;

        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $player,
            'track_id'   => $track,
            'start_date' => '2026-09-01',
            'end_date'   => '2026-10-01',
            'status'     => 'decided',
            'decision'   => 'admit',
            'uuid'       => wp_generate_uuid4(),
        ] );

        return (int) $wpdb->insert_id;
    }

    /** Generate a letter on a case and hand back its id. */
    private function generate( int $case_id ): int {
        $r = new WP_REST_Request( 'POST', '/talenttrack/v1/trial-cases/' . $case_id . '/letters' );
        $r->set_param( 'id', $case_id );
        $r->set_header( 'content-type', 'application/json' );
        $r->set_body( (string) wp_json_encode( [ 'audience' => AudienceType::TRIAL_ADMITTANCE ] ) );

        $data = (array) TrialsRestController::generate_letter( $r )->get_data();
        return (int) ( $data['data']['id'] ?? 0 );
    }

    private function deliveryRequest( string $method, int $case_id, int $letter_id, array $body = [] ): WP_REST_Request {
        $r = new WP_REST_Request(
            $method,
            '/talenttrack/v1/trial-cases/' . $case_id . '/letters/' . $letter_id . '/delivery'
        );
        $r->set_param( 'id', $case_id );
        $r->set_param( 'letter_id', $letter_id );
        if ( $body ) {
            $r->set_header( 'content-type', 'application/json' );
            $r->set_body( (string) wp_json_encode( $body ) );
        }
        return $r;
    }

    /** @return array<int,array<string,mixed>> */
    private function listLetters( int $case_id ): array {
        $r = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/' . $case_id . '/letters' );
        $r->set_param( 'id', $case_id );
        $data = (array) TrialsRestController::list_letters( $r )->get_data();
        return (array) ( $data['data']['letters'] ?? [] );
    }

    public function test_a_fresh_letter_is_not_recorded_as_delivered(): void {
        $this->generate( $this->case_id );

        $letters = $this->listLetters( $this->case_id );

        $this->assertCount( 1, $letters );
        $this->assertFalse( $letters[0]['delivered'] );
        $this->assertNull( $letters[0]['delivered_at'] );
        $this->assertNull( $letters[0]['delivered_by'] );
        $this->assertNull( $letters[0]['delivery_method'] );
    }

    public function test_recording_a_delivery_then_reading_it_back(): void {
        $uid = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $uid );

        $letter_id = $this->generate( $this->case_id );

        $res = TrialsRestController::record_letter_delivery(
            $this->deliveryRequest( 'PUT', $this->case_id, $letter_id, [ 'method' => 'emailed' ] )
        );

        $this->assertSame( 200, $res->get_status() );

        $letters = $this->listLetters( $this->case_id );
        $this->assertTrue( $letters[0]['delivered'] );
        $this->assertSame( 'emailed', $letters[0]['delivery_method'] );
        $this->assertSame( $uid, $letters[0]['delivered_by'] );
        $this->assertNotEmpty( $letters[0]['delivered_at'] );
    }

    public function test_clearing_a_delivery_puts_it_back_to_not_recorded(): void {
        $letter_id = $this->generate( $this->case_id );

        TrialsRestController::record_letter_delivery(
            $this->deliveryRequest( 'PUT', $this->case_id, $letter_id, [ 'method' => 'printed' ] )
        );

        $res = TrialsRestController::clear_letter_delivery(
            $this->deliveryRequest( 'DELETE', $this->case_id, $letter_id )
        );

        $this->assertSame( 200, $res->get_status() );

        $letters = $this->listLetters( $this->case_id );
        $this->assertFalse( $letters[0]['delivered'] );
        $this->assertNull( $letters[0]['delivery_method'] );
        $this->assertNull( $letters[0]['delivered_by'] );
    }

    public function test_an_unknown_method_is_refused_with_the_allowed_set(): void {
        $letter_id = $this->generate( $this->case_id );

        $res = TrialsRestController::record_letter_delivery(
            $this->deliveryRequest( 'PUT', $this->case_id, $letter_id, [ 'method' => 'fax' ] )
        );

        $this->assertSame( 400, $res->get_status() );

        $data    = (array) $res->get_data();
        $this->assertSame( 'bad_delivery_method', (string) ( $data['errors'][0]['code'] ?? '' ) );
        $details = (array) ( $data['errors'][0]['details'] ?? [] );
        $this->assertContains( 'handed_over', (array) ( $details['allowed'] ?? [] ) );
    }

    /**
     * A letter belonging to another family is a 404, not a write. The id
     * is a small integer and the route is manager-gated club-wide, so
     * ownership is the only thing standing between two cases.
     */
    public function test_a_letter_from_another_case_is_not_found(): void {
        $foreign = $this->generate( $this->other_case_id );

        $res = TrialsRestController::record_letter_delivery(
            $this->deliveryRequest( 'PUT', $this->case_id, $foreign, [ 'method' => 'printed' ] )
        );

        $this->assertSame( 404, $res->get_status() );

        $letters = $this->listLetters( $this->other_case_id );
        $this->assertFalse( $letters[0]['delivered'], 'the other case keeps its blank record' );
    }

    public function test_an_unknown_case_is_not_found(): void {
        $res = TrialsRestController::record_letter_delivery(
            $this->deliveryRequest( 'PUT', 999999, 1, [ 'method' => 'printed' ] )
        );

        $this->assertSame( 404, $res->get_status() );
    }

    /** The route declares its methods, so a caller can read them off it. */
    public function test_the_service_names_the_methods_it_accepts(): void {
        $this->assertTrue( TrialLetterService::isDeliveryMethod( 'printed' ) );
        $this->assertTrue( TrialLetterService::isDeliveryMethod( 'emailed' ) );
        $this->assertTrue( TrialLetterService::isDeliveryMethod( 'handed_over' ) );
        $this->assertFalse( TrialLetterService::isDeliveryMethod( 'fax' ) );
    }

    /** Both delivery verbs sit behind the same manager gate as the letter. */
    public function test_a_non_manager_is_refused(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $uid );

        $this->assertFalse( TrialsRestController::can_manage() );
    }
}
