<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #3843 — what `PUT /activities/{id}/analysis` does with the sections it
 * is given.
 *
 * The route used to answer `200 success:true, errors:[]` over a document it
 * had stored nothing of. Two separate ways:
 *
 *   - `sections` sent as a JSON list keyed its entries by array index, so
 *     `saveSection()` was called with the section key `"0"`, refused it,
 *     and had its refusal dropped by the caller;
 *   - a rating that is not one of the three enum values was nulled rather
 *     than refused, so a numeric `6.5` disappeared without a word.
 *
 * A coach types up six phases on a phone between the final whistle and the
 * car park. Losing that behind a success message is the worst answer the
 * endpoint can give, so both are refusals now, and the list shape — the one
 * an API client reaches for first — simply works.
 */
final class MatchAnalysisSectionsContractTest extends WP_UnitTestCase {

    /** @var int */
    private $coach;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->coach = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->coach );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- fixtures ---------------------------------------------------------

    private function makeMatch(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'Feyenoord U17 — home',
            'session_date'      => '2026-09-05',
            'activity_type_key' => 'game',
            'opponent'          => 'Feyenoord U17',
            'home_away'         => 'home',
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function put( int $activity_id, array $body ): array {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/analysis' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function putSection( int $activity_id, string $section_key, array $body ): array {
        $request = new WP_REST_Request(
            'PUT',
            '/talenttrack/v1/activities/' . $activity_id . '/analysis/sections/' . $section_key
        );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /**
     * One section as the GET answers it.
     *
     * @return array<string,mixed>
     */
    private function readSection( int $activity_id, string $key ): array {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );
        $sections = (array) $payload['sections'];

        return (array) ( $sections[ $key ] ?? [] );
    }

    /** @param array<string,mixed> $data */
    private function firstError( array $data ): array {
        $decoded = json_decode( (string) wp_json_encode( $data ), true );

        return is_array( $decoded ) ? (array) ( $decoded['errors'][0] ?? [] ) : [];
    }

    // ---- the list shape ----------------------------------------------------

    public function test_sections_sent_as_a_list_store_their_rating_and_notes(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->put( $activity_id, [
            'summary'  => 'Analysetest HQ',
            'sections' => [
                [
                    'key'    => MethodologyEnums::FUNCTION_AANVALLEN,
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ [ 'body' => 'Goede organisatie.' ] ],
                ],
                [
                    'key'    => MethodologyEnums::FUNCTION_VERDEDIGEN,
                    'rating' => MatchAnalysisEnums::RATING_NEEDS_WORK,
                    'notes'  => [ [ 'body' => 'Te laat druk zetten.' ] ],
                ],
            ],
        ] );

        $this->assertSame( 200, $status );

        $attack = $this->readSection( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN );
        $this->assertSame( MatchAnalysisEnums::RATING_WENT_WELL, (string) ( $attack['rating'] ?? '' ) );
        $this->assertSame( 'Goede organisatie.', (string) ( $attack['note_items'][0]['body'] ?? '' ) );

        $defend = $this->readSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN );
        $this->assertSame( MatchAnalysisEnums::RATING_NEEDS_WORK, (string) ( $defend['rating'] ?? '' ) );
        $this->assertSame( 'Te laat druk zetten.', (string) ( $defend['note_items'][0]['body'] ?? '' ) );
    }

    /** `section_key` is the other spelling an API client reaches for. */
    public function test_a_list_entry_may_name_its_section_as_section_key(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->put( $activity_id, [
            'sections' => [
                [
                    'section_key' => MatchAnalysisEnums::SECTION_SET_PIECES_ATTACK,
                    'rating'      => MatchAnalysisEnums::RATING_MIXED,
                    'notes'       => [ 'Korte corner twee keer raak.' ],
                ],
            ],
        ] );

        $this->assertSame( 200, $status );

        $section = $this->readSection( $activity_id, MatchAnalysisEnums::SECTION_SET_PIECES_ATTACK );
        $this->assertSame( MatchAnalysisEnums::RATING_MIXED, (string) ( $section['rating'] ?? '' ) );
        $this->assertSame( 'Korte corner twee keer raak.', (string) ( $section['note_items'][0]['body'] ?? '' ) );
    }

    /** The shape the form and the wizard post is untouched. */
    public function test_sections_sent_as_a_map_keep_working(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_MIXED,
                    'notes'  => [ [ 'body' => 'Eerste bal te vaak kwijt.', 'valence' => MatchAnalysisEnums::VALENCE_MINUS ] ],
                ],
            ],
        ] );

        $this->assertSame( 200, $status );

        $section = $this->readSection( $activity_id, MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN );
        $this->assertSame( MatchAnalysisEnums::RATING_MIXED, (string) ( $section['rating'] ?? '' ) );
        $this->assertSame( 'Eerste bal te vaak kwijt.', (string) ( $section['note_items'][0]['body'] ?? '' ) );
        $this->assertSame( MatchAnalysisEnums::VALENCE_MINUS, (string) ( $section['note_items'][0]['valence'] ?? '' ) );
    }

    // ---- refusals ----------------------------------------------------------

    public function test_a_numeric_rating_is_refused_and_nothing_is_written(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->put( $activity_id, [
            'summary'  => 'Analysetest HQ',
            'sections' => [
                [
                    'key'    => MethodologyEnums::FUNCTION_AANVALLEN,
                    'rating' => 6.5,
                    'notes'  => [ [ 'body' => 'Goede organisatie.' ] ],
                ],
            ],
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame(
            [ 'sections[' . MethodologyEnums::FUNCTION_AANVALLEN . '].rating' ],
            $error['details']['fields'] ?? null
        );
        $this->assertSame(
            [ MatchAnalysisEnums::RATING_WENT_WELL, MatchAnalysisEnums::RATING_MIXED, MatchAnalysisEnums::RATING_NEEDS_WORK ],
            $error['details']['allowed']['rating'] ?? null
        );
        $this->assertContains(
            MethodologyEnums::FUNCTION_AANVALLEN,
            (array) ( $error['details']['allowed']['sections'] ?? [] )
        );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );
        $this->assertSame( 0, (int) $payload['analysis_id'], 'a refused write creates no analysis' );
        $this->assertSame( '', (string) $payload['summary'] );
    }

    public function test_a_list_entry_that_names_no_section_is_refused(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->put( $activity_id, [
            'sections' => [
                [ 'rating' => MatchAnalysisEnums::RATING_WENT_WELL, 'notes' => [ 'Prima.' ] ],
            ],
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame( [ 'sections[0]' ], $error['details']['fields'] ?? null );
    }

    public function test_an_unknown_section_key_is_refused_by_name(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->put( $activity_id, [
            'sections' => [ 'middenveld' => [ 'rating' => MatchAnalysisEnums::RATING_MIXED ] ],
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame( [ 'sections[middenveld]' ], $error['details']['fields'] ?? null );
    }

    /**
     * One bad section refuses the whole request. Storing the five the
     * server understood and dropping the sixth is the failure mode this
     * issue is about, one section smaller.
     */
    public function test_one_bad_section_refuses_the_whole_write(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [ 'rating' => MatchAnalysisEnums::RATING_WENT_WELL ],
                'middenveld'                         => [ 'rating' => MatchAnalysisEnums::RATING_MIXED ],
            ],
        ] );

        $this->assertSame( 400, $status );

        $attack = $this->readSection( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN );
        $this->assertSame( '', (string) ( $attack['rating'] ?? '' ) );
    }

    /** An empty rating is the resting state, not a bad value. */
    public function test_an_empty_rating_clears_it_rather_than_being_refused(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ 'Goed.' ],
                ],
            ],
        ] );

        [ , $status ] = $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [ 'rating' => '', 'notes' => [ 'Goed.' ] ],
            ],
        ] );

        $this->assertSame( 200, $status );
        $this->assertSame( '', (string) ( $this->readSection( $activity_id, MethodologyEnums::FUNCTION_AANVALLEN )['rating'] ?? '' ) );
    }

    // ---- the body contract -------------------------------------------------

    public function test_a_top_level_key_the_route_does_not_take_is_refused(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->put( $activity_id, [
            'summary'   => 'Analysetest HQ',
            'sumary'    => 'typo',
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'unknown_field', $error['code'] ?? null );
        $this->assertSame( [ 'sumary' ], $error['details']['fields'] ?? null );
        $this->assertContains( 'sections', (array) ( $error['details']['allowed'] ?? [] ) );
    }

    public function test_the_declared_body_still_writes(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->put( $activity_id, [
            'summary' => 'Analysetest HQ',
            'status'  => MatchAnalysisEnums::STATUS_FINAL,
        ] );

        $this->assertSame( 200, $status );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertIsArray( $payload );
        $this->assertSame( 'Analysetest HQ', (string) $payload['summary'] );
        $this->assertSame( MatchAnalysisEnums::STATUS_FINAL, (string) $payload['status'] );
    }

    // ---- the per-section route --------------------------------------------

    public function test_the_section_route_refuses_a_rating_that_is_not_one(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->putSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN, [
            'rating' => 'uitstekend',
            'notes'  => [ 'Compact.' ],
        ] );

        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'invalid_field', $error['code'] ?? null );
        $this->assertSame( [ 'rating' ], $error['details']['fields'] ?? null );
        $this->assertSame(
            [ MatchAnalysisEnums::RATING_WENT_WELL, MatchAnalysisEnums::RATING_MIXED, MatchAnalysisEnums::RATING_NEEDS_WORK ],
            $error['details']['allowed']['rating'] ?? null
        );

        $this->assertSame( '', (string) ( $this->readSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN )['rating'] ?? '' ) );
    }

    public function test_the_section_route_refuses_a_key_it_does_not_take(): void {
        $activity_id = $this->makeMatch();

        [ $data, $status ] = $this->putSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN, [
            'rating'  => MatchAnalysisEnums::RATING_MIXED,
            'comment' => 'Compact.',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( 'unknown_field', $this->firstError( $data )['code'] ?? null );
    }

    public function test_the_section_route_still_writes_a_good_body(): void {
        $activity_id = $this->makeMatch();

        [ , $status ] = $this->putSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN, [
            'rating' => MatchAnalysisEnums::RATING_MIXED,
            'notes'  => [ [ 'body' => 'Compact tot rust.' ] ],
        ] );

        $this->assertSame( 200, $status );

        $section = $this->readSection( $activity_id, MethodologyEnums::FUNCTION_VERDEDIGEN );
        $this->assertSame( MatchAnalysisEnums::RATING_MIXED, (string) ( $section['rating'] ?? '' ) );
        $this->assertSame( 'Compact tot rust.', (string) ( $section['note_items'][0]['body'] ?? '' ) );
    }
}
