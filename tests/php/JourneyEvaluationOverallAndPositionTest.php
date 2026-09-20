<?php
namespace TT\Tests\Php;

use TT\Infrastructure\Evaluations\EvalRatingsRepository;
use TT\Infrastructure\Journey\JourneyBackfillService;
use TT\Infrastructure\Query\LabelTranslator;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;

/**
 * #3767 — the journey is what a family reads, so it has to be true.
 *
 * Two write-path defects made it say things that were not: every
 * `evaluation_completed` event carried `overall: 0`, because the emitter
 * read the legacy `tt_evaluations.rating` column, which is null on every
 * evaluation written the normal way, and cast that null to a float. And
 * clearing a player's preferred positions emitted "Position: Centre
 * forward → []", the raw empty JSON array the field stores.
 *
 * What is pinned here: the score on the timeline is the weighted overall
 * the evaluation screen shows, an unscored evaluation claims no score at
 * all, and no raw JSON reaches a summary.
 */
final class JourneyEvaluationOverallAndPositionTest extends WP_UnitTestCase {

    private int $player_id = 0;
    private int $main_cat  = 0;
    private int $sub_cat   = 0;

    public function set_up(): void {
        parent::set_up();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        MatrixRepository::clearCache();

        $this->player_id = $this->player();
        $this->main_cat  = $this->category( 'Technical ' . wp_rand(), null );
        $this->sub_cat   = $this->category( 'First touch ' . wp_rand(), $this->main_cat );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /* ---- the overall ---------------------------------------------------- */

    public function test_a_scored_evaluation_carries_the_weighted_overall(): void {
        $eval_id = $this->evaluation( '2026-03-12' );
        $this->rate( $eval_id, 6.5 );

        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );

        $expected = ( new EvalRatingsRepository() )->overallRating( $eval_id )['value'];
        $this->assertNotNull( $expected, 'the evaluation has ratings, so it has an overall' );
        $this->assertGreaterThan( 0, $expected );

        $payload = $this->evaluationPayload( $eval_id );
        $this->assertArrayHasKey( 'overall', $payload );
        $this->assertSame( (float) $expected, (float) $payload['overall'], 'the timeline reports the score the evaluation screen shows' );
    }

    public function test_an_unscored_evaluation_claims_no_score_rather_than_zero(): void {
        $eval_id = $this->evaluation( '2026-03-13' );

        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );

        $payload = $this->evaluationPayload( $eval_id );
        $this->assertArrayNotHasKey( 'overall', $payload, 'no ratings and no legacy score means no claim about a score' );
        $this->assertSame( 1, $this->payloadValid( $eval_id ), 'an absent optional field is still a valid payload' );
    }

    /** The legacy single-score column still counts when nothing else does. */
    public function test_a_legacy_rating_is_used_when_there_are_no_category_ratings(): void {
        global $wpdb;
        $eval_id = $this->evaluation( '2026-03-14' );
        $wpdb->update( "{$wpdb->prefix}tt_evaluations", [ 'rating' => 7.5 ], [ 'id' => $eval_id ] );

        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );

        $this->assertSame( 7.5, (float) $this->evaluationPayload( $eval_id )['overall'] );
    }

    /**
     * The emit is insert-only, so without a refresh the first score would
     * stand for good — including the zero the installs in the field carry.
     */
    public function test_re_scoring_an_evaluation_updates_the_entry_instead_of_duplicating_it(): void {
        $eval_id = $this->evaluation( '2026-03-15' );
        $this->rate( $eval_id, 6.0 );
        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );

        $this->rate( $eval_id, 8.0 );
        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );

        $this->assertCount( 1, $this->events( 'evaluation_completed' ), 'still one entry per evaluation' );
        $this->assertSame( 8.0, (float) $this->evaluationPayload( $eval_id )['overall'] );
    }

    /** The rebuild and the live hook have to agree, or a rebuild is a regression. */
    public function test_the_rebuild_writes_the_same_overall_as_the_live_hook(): void {
        $eval_id = $this->evaluation( '2026-03-16' );
        $this->rate( $eval_id, 6.5 );

        JourneyBackfillService::rebuildAll();

        $expected = ( new EvalRatingsRepository() )->overallRating( $eval_id )['value'];
        $this->assertSame( (float) $expected, (float) $this->evaluationPayload( $eval_id )['overall'] );
    }

    /* ---- the position --------------------------------------------------- */

    public function test_clearing_the_positions_writes_no_entry(): void {
        do_action(
            'tt_player_save_diff',
            $this->player_id,
            [ 'preferred_positions' => '["ST"]' ],
            [ 'preferred_positions' => '[]' ]
        );

        $this->assertCount( 0, $this->events( 'position_changed' ), 'a cleared field is not a position change worth reading' );
    }

    public function test_setting_a_position_from_empty_reads_as_none(): void {
        do_action(
            'tt_player_save_diff',
            $this->player_id,
            [ 'preferred_positions' => '[]' ],
            [ 'preferred_positions' => '["ST"]' ]
        );

        $events = $this->events( 'position_changed' );
        $this->assertCount( 1, $events );

        $summary = (string) $events[0]->summary;
        $this->assertStringNotContainsString( '[]', $summary, 'raw JSON never reaches a summary' );
        $this->assertStringContainsString( LabelTranslator::positionLabel( 'ST' ), $summary );
        $this->assertStringContainsString( __( 'none', 'talenttrack' ), $summary );

        $payload = (array) json_decode( (string) $events[0]->payload, true );
        $this->assertSame( '', $payload['from'] );
        $this->assertSame( LabelTranslator::positionLabel( 'ST' ), $payload['to'] );
    }

    /** The rows already written with the literal in them get scrubbed. */
    public function test_the_rebuild_scrubs_a_raw_empty_array_out_of_an_existing_entry(): void {
        $this->legacyPositionEvent();

        $repaired = JourneyBackfillService::rebuildAll();
        $this->assertSame( 1, $repaired['position_repaired'] );

        $events  = $this->events( 'position_changed' );
        $summary = (string) $events[0]->summary;
        $payload = (array) json_decode( (string) $events[0]->payload, true );
        $this->assertStringNotContainsString( '[]', $summary );
        $this->assertSame( '', $payload['to'] );

        $again = JourneyBackfillService::rebuildAll();
        $this->assertSame( 0, $again['position_repaired'], 'the repair is idempotent' );
    }

    /* ---- what the family actually reads --------------------------------- */

    public function test_the_timeline_route_reports_no_zero_scores_and_no_raw_json(): void {
        $eval_id = $this->evaluation( '2026-03-17' );
        $this->rate( $eval_id, 6.5 );
        do_action( 'tt_evaluation_saved', $this->player_id, $eval_id );
        do_action(
            'tt_player_save_diff',
            $this->player_id,
            [ 'preferred_positions' => '[]' ],
            [ 'preferred_positions' => '["ST"]' ]
        );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
        $request  = new WP_REST_Request( 'GET', "/talenttrack/v1/players/{$this->player_id}/timeline" );
        $response = rest_do_request( $request );
        $this->assertSame( 200, $response->get_status() );

        $body   = (array) $response->get_data();
        $data   = (array) ( $body['data'] ?? [] );
        $events = (array) ( $data['events'] ?? [] );
        $this->assertNotEmpty( $events );

        foreach ( $events as $event ) {
            $row = (array) $event;
            $this->assertStringNotContainsString( '[]', (string) ( $row['summary'] ?? '' ) );
            if ( ( $row['event_type'] ?? '' ) !== 'evaluation_completed' ) continue;
            $payload = (array) ( $row['payload'] ?? [] );
            if ( ! array_key_exists( 'overall', $payload ) ) continue;
            $this->assertGreaterThan( 0, (float) $payload['overall'], 'no evaluation is reported as a zero' );
        }
    }

    /* ---- helpers -------------------------------------------------------- */

    private function player(): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Journey',
            'last_name'     => 'Reader',
            'date_of_birth' => '2011-01-01',
            'status'        => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function evaluation( string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $this->player_id,
            'coach_id'  => 1,
            'eval_date' => $date,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function category( string $label, ?int $parent ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", [
            'club_id'       => (int) CurrentClub::id(),
            'category_key'  => 'zz_' . sanitize_key( $label ) . '_' . wp_rand( 10000, 99999 ),
            'label'         => $label,
            'parent_id'     => $parent,
            'display_order' => 900,
            'is_active'     => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function rate( int $eval_id, float $rating ): void {
        ( new EvalRatingsRepository() )->upsert( $eval_id, $this->sub_cat, $rating );
    }

    private function legacyPositionEvent(): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id'            => (int) CurrentClub::id(),
            'uuid'               => wp_generate_uuid4(),
            'player_id'          => $this->player_id,
            'event_type'         => 'position_changed',
            'event_date'         => '2026-03-01 10:00:00',
            'summary'            => 'Position: Centre forward → []',
            'payload'            => (string) wp_json_encode( [ 'from' => 'Centre forward', 'to' => '[]' ] ),
            'payload_valid'      => 1,
            'visibility'         => 'public',
            'source_module'      => 'Players',
            'source_entity_type' => 'position_change',
            'source_entity_id'   => 9901,
        ] );
    }

    /** @return array<int,object> */
    private function events( string $type ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT summary, payload, payload_valid, source_entity_id
               FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s
              ORDER BY id ASC",
            $this->player_id,
            $type
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** @return array<string,mixed> */
    private function evaluationPayload( int $eval_id ): array {
        foreach ( $this->events( 'evaluation_completed' ) as $row ) {
            if ( (int) $row->source_entity_id !== $eval_id ) continue;
            $decoded = json_decode( (string) $row->payload, true );
            return is_array( $decoded ) ? $decoded : [];
        }
        $this->fail( "no journey entry was written for evaluation {$eval_id}" );
    }

    private function payloadValid( int $eval_id ): int {
        foreach ( $this->events( 'evaluation_completed' ) as $row ) {
            if ( (int) $row->source_entity_id === $eval_id ) return (int) $row->payload_valid;
        }
        return 0;
    }
}
