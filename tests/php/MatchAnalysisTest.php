<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Domain\Vocabularies\Lookups\JourneyEventType;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\MatchAnalysis\MatchAnalysisEnums;
use TT\Modules\MatchAnalysis\MatchAnalysisShareToken;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisComposer;
use TT\Modules\MatchAnalysis\Services\MatchAnalysisShareLink;
use TT\Modules\Methodology\MethodologyEnums;

/**
 * #2704 — the match analysis.
 *
 * What is worth proving here is the set of promises the surface makes and
 * the ways it could quietly break a child's record:
 *
 *   - it opens for a match that was never prepped or run (the common case
 *     at youth level), and refuses activity types it cannot describe;
 *   - a section a coach cleared leaves no row, so later aggregation is not
 *     reading blanks as data;
 *   - a player item lands on that player's timeline, is rewritten in place
 *     rather than duplicated, and is removed when the coach withdraws it;
 *   - the share link only opens with a valid token, and reissuing it shuts
 *     the previous URL immediately.
 */
final class MatchAnalysisTest extends WP_UnitTestCase {

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

    private function makeMatch( string $date = '2026-08-15', string $type = 'game' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_activities', [
            'club_id'           => CurrentClub::id(),
            'team_id'           => 7,
            'title'             => 'Ajax U17 — away',
            'session_date'      => $date,
            'activity_type_key' => $type,
            'opponent'          => 'Ajax U17',
            'home_away'         => 'away',
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer( string $first = 'Daan', string $last = 'Peters' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => CurrentClub::id(),
            'team_id'    => 7,
            'first_name' => $first,
            'last_name'  => $last,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function markPlayed( int $activity_id, int $player_id, int $minutes = 60 ): void {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_attendance', [
            'club_id'        => CurrentClub::id(),
            'activity_id'    => $activity_id,
            'player_id'      => $player_id,
            'status'         => 'present',
            'record_type'    => 'actual',
            'minutes_played' => $minutes,
            'is_guest'       => 0,
        ] );
    }

    /**
     * @param array<string,mixed> $body
     */
    private function put( int $activity_id, array $body ): array {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/analysis' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        $response = rest_get_server()->dispatch( $request );

        return (array) $response->get_data();
    }

    // ---- shape ------------------------------------------------------------

    public function test_a_match_with_no_prep_and_no_execution_still_opens(): void {
        $activity_id = $this->makeMatch();

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );

        $this->assertNotNull( $payload );
        $this->assertFalse( $payload['has_prep'] );
        $this->assertFalse( $payload['has_exec'] );
        // The overall read plus the two chains of three.
        $this->assertCount( 7, $payload['sections'] );
        $this->assertSame( 0, $payload['analysis_id'], 'reading a match must not create the record' );
    }

    public function test_a_training_is_refused(): void {
        $activity_id = $this->makeMatch( '2026-08-15', 'training' );

        $this->assertNull( ( new MatchAnalysisComposer() )->forActivity( $activity_id, false ) );

        $data = $this->put( $activity_id, [ 'summary' => 'nope' ] );
        $this->assertFalse( $data['success'] );
        $this->assertSame( 'not_a_match', $data['errors'][0]['code'] );
    }

    /**
     * A tournament day is several games and one analysis row cannot say
     * which — so the surface refuses it rather than attaching an ambiguous
     * record to it (#2686 is the same failure in the match-prep gate).
     */
    public function test_a_tournament_is_refused(): void {
        $activity_id = $this->makeMatch( '2026-08-15', 'tournament' );

        $this->assertNull( ( new MatchAnalysisComposer() )->forActivity( $activity_id, false ) );
    }

    public function test_a_match_in_the_future_is_not_reviewable_yet(): void {
        $future = gmdate( 'Y-m-d', strtotime( '+14 days' ) );
        $activity = MatchAnalysisComposer::activity( $this->makeMatch( $future ) );

        $this->assertFalse( MatchAnalysisComposer::isReviewable( $activity ) );
    }

    // ---- sections ---------------------------------------------------------

    public function test_saving_sections_persists_rating_and_bullets(): void {
        $activity_id = $this->makeMatch();

        $data = $this->put( $activity_id, [
            'summary'  => 'Grew into it after twenty minutes.',
            'sections' => [
                MethodologyEnums::FUNCTION_AANVALLEN => [
                    'rating' => MatchAnalysisEnums::RATING_WENT_WELL,
                    'notes'  => [ 'Patient build-up', '', 'Switch to the far winger worked' ],
                ],
            ],
        ] );

        $this->assertTrue( $data['success'] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );
        $this->assertNotNull( $analysis );
        $this->assertSame( 'Grew into it after twenty minutes.', $analysis->summary );

        $sections = $repo->listSections( (int) $analysis->id );
        $this->assertSame(
            MatchAnalysisEnums::RATING_WENT_WELL,
            $sections[ MethodologyEnums::FUNCTION_AANVALLEN ]['rating']
        );
        $this->assertSame(
            [
                [ 'valence' => '', 'body' => 'Patient build-up' ],
                [ 'valence' => '', 'body' => 'Switch to the far winger worked' ],
            ],
            $sections[ MethodologyEnums::FUNCTION_AANVALLEN ]['items'],
            'blank bullet inputs must not become empty notes'
        );
    }

    /**
     * Clearing a section is not the same as rating it "nothing much" — it
     * must leave no row, or every later aggregation counts blanks.
     */
    public function test_clearing_a_section_removes_its_row(): void {
        $activity_id = $this->makeMatch();

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [
                    'rating' => MatchAnalysisEnums::RATING_NEEDS_WORK,
                    'notes'  => [ 'Line dropped too early' ],
                ],
            ],
        ] );

        $this->put( $activity_id, [
            'sections' => [
                MethodologyEnums::FUNCTION_VERDEDIGEN => [ 'rating' => '', 'notes' => [ '', '' ] ],
            ],
        ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );

        $this->assertSame( [], $repo->listSections( (int) $analysis->id ) );
    }

    public function test_an_unknown_section_key_is_rejected(): void {
        $activity_id = $this->makeMatch();

        $request = new WP_REST_Request(
            'PUT',
            '/talenttrack/v1/activities/' . $activity_id . '/analysis/sections/wing_play'
        );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'rating' => MatchAnalysisEnums::RATING_MIXED ] ) );

        $response = rest_get_server()->dispatch( $request );
        $data     = (array) $response->get_data();

        $this->assertFalse( $data['success'] );
        $this->assertSame( 'unknown_section', $data['errors'][0]['code'] );
    }

    // ---- players ----------------------------------------------------------

    public function test_the_roster_lists_who_played_with_their_minutes(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id, 55 );

        $payload = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );

        $this->assertCount( 1, $payload['players'] );
        $this->assertSame( $player_id, $payload['players'][0]['player_id'] );
        $this->assertSame( 55, $payload['players'][0]['minutes'] );
        $this->assertSame( '', $payload['players'][0]['marker'], 'not mentioned is the resting state' );
    }

    public function test_an_untouched_player_row_persists_nothing(): void {
        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [ $player_id => [ 'marker' => '', 'note' => '', 'team_function' => '' ] ],
        ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );

        $this->assertSame( [], $repo->listPlayerItems( (int) $analysis->id ) );
    }

    public function test_a_player_item_lands_on_that_players_timeline(): void {
        global $wpdb;

        $activity_id = $this->makeMatch( '2026-08-15' );
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [
                $player_id => [
                    'marker'        => MatchAnalysisEnums::MARKER_STOOD_OUT,
                    'note'          => 'Took the ball on the half-turn twice.',
                    'team_function' => MethodologyEnums::FUNCTION_AANVALLEN,
                ],
            ],
        ] );

        $events = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            JourneyEventType::MATCH_OBSERVED
        ) );

        $this->assertCount( 1, $events );
        $this->assertStringContainsString( 'half-turn', (string) $events[0]->summary );
        $this->assertStringStartsWith(
            '2026-08-15',
            (string) $events[0]->event_date,
            'the entry is dated to the match, not to when it was typed'
        );
        $this->assertSame( 'coaching_staff', (string) $events[0]->visibility );
    }

    public function test_rewriting_an_item_updates_the_entry_rather_than_adding_one(): void {
        global $wpdb;

        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [ $player_id => [ 'marker' => MatchAnalysisEnums::MARKER_BELOW_PAR, 'note' => 'First version.' ] ],
        ] );
        $this->put( $activity_id, [
            'players' => [ $player_id => [ 'marker' => MatchAnalysisEnums::MARKER_BELOW_PAR, 'note' => 'Second version.' ] ],
        ] );

        $summaries = $wpdb->get_col( $wpdb->prepare(
            "SELECT summary FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            JourneyEventType::MATCH_OBSERVED
        ) );

        $this->assertCount( 1, $summaries );
        $this->assertStringContainsString( 'Second version.', (string) $summaries[0] );
    }

    public function test_clearing_an_item_removes_its_timeline_entry(): void {
        global $wpdb;

        $activity_id = $this->makeMatch();
        $player_id   = $this->makePlayer();
        $this->markPlayed( $activity_id, $player_id );

        $this->put( $activity_id, [
            'players' => [ $player_id => [ 'marker' => MatchAnalysisEnums::MARKER_STOOD_OUT, 'note' => 'Good game.' ] ],
        ] );
        $this->put( $activity_id, [
            'players' => [ $player_id => [ 'marker' => '', 'note' => '' ] ],
        ] );

        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_player_events
              WHERE player_id = %d AND event_type = %s",
            $player_id,
            JourneyEventType::MATCH_OBSERVED
        ) );

        $this->assertSame( 0, $count, 'a withdrawn note must not keep standing on the child\'s file' );
    }

    // ---- authorization ----------------------------------------------------

    public function test_a_user_without_edit_rights_cannot_write(): void {
        $activity_id = $this->makeMatch();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'subscriber' ] ) );

        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/analysis' );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( [ 'summary' => 'should not land' ] ) );

        $response = rest_get_server()->dispatch( $request );

        $this->assertSame( 403, $response->get_status() );
        $this->assertNull( ( new MatchAnalysisRepository() )->findByActivity( $activity_id ) );
    }

    // ---- share link -------------------------------------------------------

    public function test_a_valid_token_resolves_and_a_tampered_one_does_not(): void {
        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [ 'summary' => 'Shared read.' ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );
        $seed     = $repo->ensureShareTokenSeed( (int) $analysis->id );
        $token    = MatchAnalysisShareToken::tokenFor( (int) $analysis->id, (string) $analysis->uuid, $seed );

        $this->assertNotNull( MatchAnalysisShareLink::resolve( (string) $analysis->uuid, $token ) );
        $this->assertNull( MatchAnalysisShareLink::resolve( (string) $analysis->uuid, $token . 'x' ) );
        $this->assertNull( MatchAnalysisShareLink::resolve( wp_generate_uuid4(), $token ) );
    }

    public function test_an_analysis_nobody_shared_has_no_working_link(): void {
        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [ 'summary' => 'Never shared.' ] );

        $analysis = ( new MatchAnalysisRepository() )->findByActivity( $activity_id );

        // Guessing the uuid is not enough: with no seed there is no token
        // that verifies, and resolving must not mint one on the way in.
        $this->assertNull( MatchAnalysisShareLink::resolve(
            (string) $analysis->uuid,
            MatchAnalysisShareToken::tokenFor( (int) $analysis->id, (string) $analysis->uuid, '' )
        ) );
    }

    public function test_reissuing_the_link_shuts_the_previous_one(): void {
        $activity_id = $this->makeMatch();
        $this->put( $activity_id, [ 'summary' => 'Shared read.' ] );

        $repo     = new MatchAnalysisRepository();
        $analysis = $repo->findByActivity( $activity_id );
        $old_seed = $repo->ensureShareTokenSeed( (int) $analysis->id );
        $old      = MatchAnalysisShareToken::tokenFor( (int) $analysis->id, (string) $analysis->uuid, $old_seed );

        $this->assertNotNull( MatchAnalysisShareLink::resolve( (string) $analysis->uuid, $old ) );

        $request  = new WP_REST_Request( 'POST', '/talenttrack/v1/activities/' . $activity_id . '/analysis/share/rotate' );
        $response = rest_get_server()->dispatch( $request );
        $this->assertSame( 200, $response->get_status() );

        $this->assertNull(
            MatchAnalysisShareLink::resolve( (string) $analysis->uuid, $old ),
            'the URL handed out before the reissue must stop working immediately'
        );
    }

    // ---- prefill ----------------------------------------------------------

    public function test_the_plan_shows_up_next_to_the_matching_section(): void {
        global $wpdb;

        $activity_id = $this->makeMatch();

        $wpdb->insert( $wpdb->prefix . 'tt_match_prep', [
            'uuid'                  => wp_generate_uuid4(),
            'club_id'               => CurrentClub::id(),
            'activity_id'           => $activity_id,
            'half_length_minutes'   => 35,
            'goals_attack'          => 'Press on their goal kick',
            'goals_attack_setpiece' => 'Second ball on our corners',
            'goals_defend_setpiece' => 'No short corners against',
        ] );

        $payload  = ( new MatchAnalysisComposer() )->forActivity( $activity_id, false );
        $sections = $payload['sections'];

        $this->assertSame(
            'Press on their goal kick',
            $sections[ MethodologyEnums::FUNCTION_AANVALLEN ]['planned']
        );
        // Since the split, each side of the plan lands beside its own
        // phase instead of both being crammed into one line.
        $this->assertSame(
            'Second ball on our corners',
            $sections[ MatchAnalysisEnums::SECTION_SET_PIECES_ATTACK ]['planned']
        );
        $this->assertSame(
            'No short corners against',
            $sections[ MatchAnalysisEnums::SECTION_SET_PIECES_DEFEND ]['planned']
        );
        $this->assertSame(
            '',
            $sections[ MethodologyEnums::FUNCTION_OMSCHAKELEN_AANVALLEN ]['planned'],
            'match prep never asked about the transitions, so there is no plan line to show'
        );
    }
}
