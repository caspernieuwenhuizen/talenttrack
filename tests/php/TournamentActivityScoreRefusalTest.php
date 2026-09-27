<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Tournaments\Services\TournamentScoreOwnership;

/**
 * #4055 — `PUT /activities/{id}/result` refuses a tournament-owned scoreline.
 *
 * #4021 made `tt_tournament_matches` the single score store for a tournament
 * fixture. This route was outside that lane's file boundary and still took the
 * write: nothing offers it in the UI, and the next sync from the fixture
 * overwrites whatever was written, so a caller got a `200` and lost the value
 * silently. That is the shape of a bug report later — an integration PATCHes an
 * activity score and finds it gone — so the refusal names the real rule
 * instead.
 *
 * Every refusal here is paired with the same write succeeding on an ordinary
 * match, so a fixture that could not write at all would fail rather than pass
 * on the refusal it was meant to prove.
 */
final class TournamentActivityScoreRefusalTest extends WP_UnitTestCase {

    private const TEAM = 40551;

    /** @var string */
    private $p = '';

    /** @var int */
    private $tournament = 0;

    public function set_up(): void {
        parent::set_up();

        global $wpdb;
        $this->p = $wpdb->prefix;
        $wpdb->hide_errors();

        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $wpdb->insert( "{$this->p}tt_teams", [ 'id' => self::TEAM, 'club_id' => 1, 'name' => 'JO13-1' ] );

        $wpdb->insert( "{$this->p}tt_tournaments", [
            'club_id'    => 1,
            'uuid'       => wp_generate_uuid4(),
            'name'       => 'Zomertoernooi',
            'start_date' => '2026-06-06',
            'end_date'   => '2026-06-06',
            'team_id'    => self::TEAM,
        ] );
        $this->tournament = (int) $wpdb->insert_id;
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_an_ordinary_match_still_takes_the_write(): void {
        $activity = $this->seedActivity( 'match', null );

        $response = $this->putResult( $activity, [ 'home_score' => 3, 'away_score' => 1 ] );

        $this->assertSame( 200, $response->get_status(), 'the route must still work, or the refusals prove nothing' );
        $this->assertSame( [ 3, 1 ], $this->storedScores( $activity ) );
    }

    public function test_a_tournament_fixture_activity_is_refused_and_nothing_is_written(): void {
        $activity = $this->seedActivity( 'match', 'tournament' );
        $match    = $this->seedFixture( $activity );

        $response = $this->putResult( $activity, [ 'home_score' => 3, 'away_score' => 1 ] );
        $error    = $this->firstError( $response );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( 'score_owned_by_fixture', $error['code'] ?? null );
        $this->assertSame( [ null, null ], $this->storedScores( $activity ), 'nothing is written on a refusal' );

        $details = (array) ( $error['details'] ?? [] );
        $this->assertSame( TournamentScoreOwnership::KIND_FIXTURE, $details['kind'] ?? null );
        $this->assertSame( $match, (int) ( $details['match_id'] ?? 0 ) );
        $this->assertSame(
            'PATCH /tournaments/' . $this->tournament . '/matches/' . $match,
            $details['route'] ?? null,
            'the refusal names the route that does work'
        );
    }

    /** #3857 — the day is a read-only roll-up of its fixtures. */
    public function test_a_tournament_day_activity_is_refused_too(): void {
        $activity = $this->seedActivity( 'tournament', null );

        $response = $this->putResult( $activity, [ 'home_score' => 2, 'away_score' => 2 ] );
        $error    = $this->firstError( $response );

        $this->assertSame( 400, $response->get_status(), 'a 404 would read as a missing record rather than as the rule' );
        $this->assertSame( 'score_owned_by_fixture', $error['code'] ?? null );
        $this->assertSame(
            TournamentScoreOwnership::KIND_DAY,
            (string) ( ( (array) ( $error['details'] ?? [] ) )['kind'] ?? '' )
        );
        $this->assertSame( [ null, null ], $this->storedScores( $activity ) );
    }

    /** A clear of the score is a write too, and is refused on the same grounds. */
    public function test_clearing_the_score_is_refused_as_well(): void {
        $activity = $this->seedActivity( 'match', 'tournament' );
        $this->seedFixture( $activity );
        $this->setScores( $activity, 3, 1 );

        $response = $this->putResult( $activity, [ 'home_score' => null, 'away_score' => null ] );

        $this->assertSame( 400, $response->get_status() );
        $this->assertSame( [ 3, 1 ], $this->storedScores( $activity ), 'the synced score survives' );
    }

    /** The service answers on its own, so a future front end gets the same rule. */
    public function test_the_service_leaves_an_ordinary_match_alone(): void {
        $this->assertNull( TournamentScoreOwnership::forActivity( $this->seedActivity( 'match', null ) ) );
        $this->assertNull( TournamentScoreOwnership::forActivity( 0 ) );
    }

    // ---- fixtures --------------------------------------------------

    private function seedActivity( string $type, ?string $source ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => 1,
            'team_id'             => self::TEAM,
            'title'               => 'Toernooiwedstrijd ' . $type . '/' . (string) $source,
            'session_date'        => '2026-06-06',
            'activity_type_key'   => $type,
            'activity_source_key' => $source ?? 'manual',
            'activity_status_key' => 'completed',
            'plan_state'          => 'completed',
            'opponent'            => 'Den Helder JO13',
            'tournament_id'       => $type === 'tournament' ? $this->tournament : null,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function seedFixture( int $activity_id ): int {
        global $wpdb;

        $wpdb->insert( "{$this->p}tt_tournament_matches", [
            'club_id'              => 1,
            'tournament_id'        => $this->tournament,
            'sequence'             => 1,
            'label'                => 'Wedstrijd 1',
            'opponent_name'        => 'Den Helder JO13',
            'opponent_level'       => 'equal',
            'duration_min'         => 20,
            'substitution_windows' => '[10]',
            'activity_id'          => $activity_id,
            'our_score'            => 3,
            'their_score'          => 1,
        ] );

        return (int) $wpdb->insert_id;
    }

    /**
     * The first error in the envelope, as arrays — `details` is cast to an
     * object on the way out so `{}` serialises correctly, so it is read back
     * through JSON rather than poked at as a stdClass.
     *
     * @return array<string,mixed>
     */
    private function firstError( \WP_REST_Response $response ): array {
        $data = json_decode( (string) wp_json_encode( $response->get_data() ), true );
        $out  = is_array( $data ) ? ( $data['errors'][0] ?? [] ) : [];

        return (array) $out;
    }

    /** @param array<string,mixed> $body */
    private function putResult( int $activity_id, array $body ): \WP_REST_Response {
        $request = new WP_REST_Request( 'PUT', '/talenttrack/v1/activities/' . $activity_id . '/result' );
        $request->set_header( 'Content-Type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );

        return rest_do_request( $request );
    }

    private function setScores( int $activity_id, int $home, int $away ): void {
        global $wpdb;
        $wpdb->update(
            "{$this->p}tt_activities",
            [ 'home_score' => $home, 'away_score' => $away ],
            [ 'id' => $activity_id ]
        );
    }

    /** @return array{0:?int,1:?int} */
    private function storedScores( int $activity_id ): array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT home_score, away_score FROM {$this->p}tt_activities WHERE id = %d",
            $activity_id
        ) );

        return [
            isset( $row->home_score ) && $row->home_score !== null ? (int) $row->home_score : null,
            isset( $row->away_score ) && $row->away_score !== null ? (int) $row->away_score : null,
        ];
    }
}
