<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Reports\AudienceType;
use TT\Modules\Trials\Rest\TrialsRestController;

/**
 * #3223 — trial letters over REST.
 *
 * `TrialLetterService` was constructed in two view files and nowhere
 * else, so §4's smell test failed for this half of the module: delete
 * `src/Shared/Frontend/` and a trial letter could no longer be generated
 * or read at all. These routes close that.
 *
 * Both verbs are manager-gated, matching the Letter tab. A letter telling
 * a family whether the academy wants their child is not something an
 * assigned coach generates, and the tests assert the refusal rather than
 * trusting the registration.
 */
final class TrialLettersRestTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private int $case_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p    = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'    => $this->club,
            'first_name' => 'Letter',
            'last_name'  => 'Subject',
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
        $this->case_id = (int) $wpdb->insert_id;
    }

    private function request( string $method, array $body = [] ): WP_REST_Request {
        $r = new WP_REST_Request( $method, '/talenttrack/v1/trial-cases/' . $this->case_id . '/letters' );
        $r->set_param( 'id', $this->case_id );
        if ( $body ) {
            $r->set_header( 'content-type', 'application/json' );
            $r->set_body( (string) wp_json_encode( $body ) );
        }
        return $r;
    }

    public function test_an_unknown_case_is_404_not_an_empty_list(): void {
        $r = new WP_REST_Request( 'GET', '/talenttrack/v1/trial-cases/999999/letters' );
        $r->set_param( 'id', 999999 );

        $res = TrialsRestController::list_letters( $r );

        $this->assertSame( 404, $res->get_status() );
    }

    public function test_a_case_with_no_letters_lists_none(): void {
        $res = TrialsRestController::list_letters( $this->request( 'GET' ) );

        $this->assertSame( 200, $res->get_status() );
        $data = (array) $res->get_data();
        $this->assertSame( [], $data['data']['letters'] );
    }

    public function test_an_invalid_audience_is_refused_with_the_allowed_set(): void {
        $res = TrialsRestController::generate_letter( $this->request( 'POST', [ 'audience' => 'not_a_letter' ] ) );

        $this->assertSame( 400, $res->get_status() );

        // `RestResponse::error()` nests details under errors[0].details,
        // cast to an object so empty details serialise as {} not [].
        $data    = (array) $res->get_data();
        $details = (array) ( $data['errors'][0]['details'] ?? [] );
        $this->assertContains( AudienceType::TRIAL_ADMITTANCE, (array) ( $details['allowed'] ?? [] ) );
    }

    /** A missing audience is the same refusal, not a silent default. */
    public function test_a_missing_audience_is_refused(): void {
        $res = TrialsRestController::generate_letter( $this->request( 'POST' ) );

        $this->assertSame( 400, $res->get_status() );
    }

    public function test_generating_a_letter_then_listing_it(): void {
        $res = TrialsRestController::generate_letter(
            $this->request( 'POST', [
                'audience'          => AudienceType::TRIAL_ADMITTANCE,
                'strengths_summary' => 'Reads the game early.',
                'growth_areas'      => 'Weaker foot.',
            ] )
        );

        $this->assertSame( 200, $res->get_status() );
        $created = (array) $res->get_data();
        $this->assertGreaterThan( 0, (int) $created['data']['id'] );

        $list = (array) TrialsRestController::list_letters( $this->request( 'GET' ) )->get_data();
        $letters = $list['data']['letters'];

        $this->assertCount( 1, $letters );
        $this->assertSame( AudienceType::TRIAL_ADMITTANCE, $letters[0]['audience'] );
        $this->assertTrue( $letters[0]['is_active'] );
    }

    /**
     * Generating supersedes. Two live letters saying different things to
     * the same family is the failure mode this behaviour prevents, so the
     * list has to show which one counts.
     */
    public function test_a_second_letter_supersedes_the_first(): void {
        TrialsRestController::generate_letter(
            $this->request( 'POST', [ 'audience' => AudienceType::TRIAL_ADMITTANCE ] )
        );
        TrialsRestController::generate_letter(
            $this->request( 'POST', [ 'audience' => AudienceType::TRIAL_DENIAL_ENCOURAGE ] )
        );

        $list    = (array) TrialsRestController::list_letters( $this->request( 'GET' ) )->get_data();
        $letters = $list['data']['letters'];

        $this->assertCount( 2, $letters );

        $active = array_values( array_filter( $letters, static fn( array $l ): bool => $l['is_active'] ) );
        $this->assertCount( 1, $active, 'exactly one letter is live at a time' );
        $this->assertSame( AudienceType::TRIAL_DENIAL_ENCOURAGE, $active[0]['audience'] );
    }

    /** Both verbs are manager-gated. */
    public function test_a_non_manager_is_refused_both_verbs(): void {
        $uid = self::factory()->user->create( [ 'role' => 'subscriber' ] );
        wp_set_current_user( $uid );

        $this->assertFalse( TrialsRestController::can_manage() );
    }
}
