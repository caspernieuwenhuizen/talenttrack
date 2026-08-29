<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #3007 (epic #2881) — the promises the endpoint has to keep now that the
 * match-analysis surface autosaves.
 *
 * An explicit Save writes once, deliberately, with the whole form in hand.
 * Autosave writes every few seconds with whatever the form happens to hold,
 * from more than one browser, at whatever moment the debounce fires. Three
 * properties that were merely true before are load-bearing now, and each is
 * a way to silently destroy a coach's write-up of a child:
 *
 *   - **absence is not deletion.** A body that says nothing about the
 *     players must leave every player item standing. Verified against the
 *     endpoint rather than by reading the writer, because it is the endpoint
 *     the browser talks to.
 *   - **the version token moves on every write**, whichever of the four
 *     tables actually changed — otherwise the conflict check below waves
 *     through a write composed against a document that has since moved.
 *   - **a second writer is refused, not merged.** Two people writing up the
 *     same match is plausible; last-write-wins across an autosave loop means
 *     one of them watches their sentences disappear without being told.
 *
 * Plus the draft/final status the share link now reads, which is what keeps
 * the guarantee that a shared link shows a finished document.
 */
final class MatchAnalysisSaveModelTest extends WP_UnitTestCase {

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

    private function makeMatch( string $date = '2026-08-15' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'Ajax U17 — away',
            'session_date'      => $date,
            'activity_type_key' => 'game',
            'opponent'          => 'Ajax U17',
            'home_away'         => 'away',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => 'Daan',
            'last_name'  => 'Peters',
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function putRaw( int $activity_id, array $body, string $base = '' ): array {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/analysis' );
        $request->set_header( 'content-type', 'application/json' );
        if ( $base !== '' ) $request->set_query_params( [ 'base_updated_at' => $base ] );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    /**
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function put( int $activity_id, array $body, string $base = '' ): array {
        [ $data ] = $this->putRaw( $activity_id, $body, $base );
        return $data;
    }

    private function analysisId( int $activity_id ): int {
        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        return $payload === null ? 0 : (int) $payload['analysis_id'];
    }

    // ---- absence is not deletion -----------------------------------------

    /**
     * The autosave loop sends the whole form, but a wizard step, an
     * integration or a future per-panel save sends a slice. None of them may
     * be able to wipe what they do not mention.
     */
    public function test_a_write_that_omits_a_key_leaves_it_standing(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();

        $this->put( $activity_id, [
            'summary'  => 'Grew into it after twenty minutes.',
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ [ 'body' => 'Third man runs came off.' ] ],
                ],
            ],
            'players'  => [
                (string) $player_id => [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT ],
            ],
        ] );

        // A second write that knows only about the summary.
        $data = $this->put( $activity_id, [ 'summary' => 'Second half was the better one.' ] );

        $this->assertTrue( $data['success'] );

        $sections = [];
        foreach ( (array) $data['data']['sections'] as $section ) {
            $sections[ (string) $section['key'] ] = $section;
        }

        $this->assertSame( 'Second half was the better one.', $data['data']['summary'] );
        $this->assertSame(
            MatchAnalysisEnums::RATING_WENT_WELL,
            (string) $sections[ MethodologyEnums::FUNCTION_AANVALLEN ]['rating'],
            'a body with no sections key must not clear a rating'
        );

        $marked = 0;
        foreach ( (array) $data['data']['players'] as $player ) {
            if ( (string) ( $player['marker'] ?? '' ) !== '' ) $marked++;
        }
        $this->assertSame( 1, $marked, 'a body with no players key must not clear a marker' );
    }

    /**
     * The status is not on the flat form, so an ordinary autosave never
     * sends it — and must therefore never reset it. Without this, publishing
     * an analysis and then fixing a typo would silently unpublish it from
     * everyone who was sent the link.
     */
    public function test_an_autosave_does_not_reset_a_published_analysis_to_draft(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [ 'summary' => 'Draft.' ] );
        $final = $this->put( $activity_id, [ 'status' => MatchAnalysisEnums::STATUS_FINAL ] );
        $this->assertSame( MatchAnalysisEnums::STATUS_FINAL, (string) $final['data']['status'] );

        $again = $this->put( $activity_id, [ 'summary' => 'Fixed a typo.' ] );

        $this->assertSame(
            MatchAnalysisEnums::STATUS_FINAL,
            (string) $again['data']['status'],
            'reopening a published document to fix a typo must not unpublish it'
        );
    }

    public function test_a_new_analysis_starts_as_a_draft(): void {
        $activity_id = $this->makeMatch();

        $data = $this->put( $activity_id, [ 'summary' => 'First words.' ] );

        $this->assertSame( MatchAnalysisEnums::STATUS_DRAFT, (string) $data['data']['status'] );
    }

    // ---- the version token ------------------------------------------------

    public function test_the_response_carries_the_version_token(): void {
        $activity_id = $this->makeMatch();

        $data = $this->put( $activity_id, [ 'summary' => 'First words.' ] );

        $this->assertArrayHasKey( 'updated_at', $data['data'] );
        $this->assertNotSame( '', (string) $data['data']['updated_at'] );
    }

    /**
     * The analysis is four tables. Before #3007 a section write only stamped
     * its own row, so the parent's `updated_at` described the last summary
     * edit — which would let a second writer's section work pass the check
     * below unnoticed.
     */
    public function test_a_section_only_write_still_moves_the_version_token(): void {
        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [ 'summary' => 'First words.' ] );

        $repo = new MatchAnalysisRepository();
        $id   = $this->analysisId( $activity_id );

        // Back-date the row so the stamp has somewhere to move to: the test
        // and the write would otherwise land in the same second, which is
        // the resolution DATETIME has.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_match_analyses',
            [ 'updated_at' => '2026-01-01 00:00:00' ],
            [ 'id' => $id ]
        );

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [],
                ],
            ],
        ] );

        $row = $repo->find( $id );
        $this->assertNotNull( $row );
        $this->assertNotSame( '2026-01-01 00:00:00', (string) $row->updated_at );
    }

    // ---- concurrency ------------------------------------------------------

    /**
     * The decision, stated: the second writer is refused rather than merged
     * or silently preferred. The coach is looking at a version the server no
     * longer holds, and the honest answer is to say so.
     */
    public function test_a_write_against_a_stale_version_is_refused(): void {
        $activity_id = $this->makeMatch();

        $first = $this->put( $activity_id, [ 'summary' => 'What the head coach wrote.' ] );
        $stale = (string) $first['data']['updated_at'];

        // Somebody else writes, without a token — an integration, the
        // wizard, or simply the other coach's first save.
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_match_analyses',
            [ 'updated_at' => '2030-01-01 00:00:00' ],
            [ 'id' => $this->analysisId( $activity_id ) ]
        );

        [ $data, $status ] = $this->putRaw(
            $activity_id,
            [ 'summary' => 'What the assistant wrote.' ],
            $stale
        );

        $this->assertSame( 409, $status );
        $this->assertFalse( $data['success'] );
        $this->assertSame( 'analysis_conflict', $data['errors'][0]['code'] );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $this->assertNotNull( $payload );
        $this->assertSame(
            'What the head coach wrote.',
            (string) $payload['summary'],
            'a refused write must not have written'
        );
    }

    public function test_a_write_carrying_the_current_version_is_accepted(): void {
        $activity_id = $this->makeMatch();

        $first   = $this->put( $activity_id, [ 'summary' => 'First words.' ] );
        $current = (string) $first['data']['updated_at'];

        [ $data, $status ] = $this->putRaw(
            $activity_id,
            [ 'summary' => 'Second words.' ],
            $current
        );

        $this->assertSame( 200, $status );
        $this->assertTrue( $data['success'] );
        $this->assertSame( 'Second words.', (string) $data['data']['summary'] );
    }

    /**
     * Opt-in by design. A client with no version to have been composed
     * against — the wizard, a script, an integration — keeps the previous
     * last-write-wins behaviour rather than being locked out of the
     * endpoint by a check it cannot satisfy.
     */
    public function test_a_write_with_no_token_is_not_checked(): void {
        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [ 'summary' => 'First words.' ] );

        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_match_analyses',
            [ 'updated_at' => '2030-01-01 00:00:00' ],
            [ 'id' => $this->analysisId( $activity_id ) ]
        );

        [ $data, $status ] = $this->putRaw( $activity_id, [ 'summary' => 'Second words.' ] );

        $this->assertSame( 200, $status );
        $this->assertTrue( $data['success'] );
    }
}
