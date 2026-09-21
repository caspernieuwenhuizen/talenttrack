<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_UnitTestCase;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * #3817 (slice 3 of #3603) — the body contract on the player record:
 * players, teams, evaluations and goals, create and update.
 *
 * These eight routes read a fixed set of fields and ignored everything
 * else, so a misspelled field name answered 200 over a value nothing had
 * stored — in a child's development history rather than in a log. Each now
 * declares its `args` and runs `BaseController::checkBody()`.
 *
 * Three properties are tested per route, because they are the three ways a
 * slice like this goes wrong:
 *
 *   - **strict**: a key outside the declaration is `400 unknown_field`,
 *     naming it and listing what the route does take;
 *   - **named**: a create missing what it needs answers `missing_fields`
 *     with the field spellings in `details.fields`, not an English
 *     sentence a client has to parse;
 *   - **still partial**: declaring the fields must not turn an omitted one
 *     into a cleared one (CLAUDE.md §6). `PUT /teams/{id}` failed that
 *     before this change — a body carrying only a name erased the age
 *     group and the notes.
 *
 * Core refuses an *absent* required key before the callback runs, which is
 * why none of the eight declares one: a required field would answer an
 * unauthenticated POST with a 400 naming the fields instead of the 401 it
 * owes. Each create names what it needs itself, behind its capability gate.
 *
 * The caller is a WordPress `administrator`, which `LegacyCapMapper` lets
 * through every `tt_*` cap unconditionally and `userHasPermission()` lets
 * through every scope check — so these tests measure the body contract and
 * not the authorization layer. Every refusal assertion below is paired with
 * a request that succeeds, so a fixture that silently could not write would
 * fail rather than pass vacuously.
 */
final class PlayerRecordRouteArgsTest extends WP_UnitTestCase {

    /** @var int */
    private $admin;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->ensureCapabilities();

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );

        do_action( 'rest_api_init' );
    }

    public function tear_down(): void {
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    // ---- helpers ----------------------------------------------------------

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $path, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . $path );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( (object) $body ) );

        $response = rest_get_server()->dispatch( $request );
        $data     = json_decode( (string) wp_json_encode( $response->get_data() ), true );

        return [ is_array( $data ) ? $data : [], (int) $response->get_status() ];
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function firstError( array $data ): array {
        return (array) ( $data['errors'][0] ?? [] );
    }

    /** @param array<string,mixed> $data */
    private function assertUnknownField( array $data, int $status, string $field, string $allowed ): void {
        $this->assertSame( 400, $status );

        $error = $this->firstError( $data );
        $this->assertSame( 'unknown_field', $error['code'] ?? null );
        $this->assertSame( [ $field ], $error['details']['fields'] ?? null );
        $this->assertContains( $allowed, (array) ( $error['details']['allowed'] ?? [] ) );
    }

    /**
     * @param array<string,mixed> $data
     * @return list<string>
     */
    private function missingFields( array $data ): array {
        $error  = $this->firstError( $data );
        $this->assertSame( 'missing_fields', $error['code'] ?? null );
        $fields = array_map( 'strval', (array) ( $error['details']['fields'] ?? [] ) );
        sort( $fields );
        return $fields;
    }

    private function makeTeam( string $name = 'JO17-1', string $age_group = 'JO17', string $notes = 'Zaterdag.' ): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_teams', [
            'club_id'   => CurrentClub::id(),
            'name'      => $name,
            'age_group' => $age_group,
            'notes'     => $notes,
        ] );

        return (int) $wpdb->insert_id;
    }

    private function makePlayer(): int {
        global $wpdb;

        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => CurrentClub::id(),
            'first_name'    => 'Sem',
            'last_name'     => 'de Boer',
            'status'        => 'active',
            'guardian_name' => 'Ingrid de Boer',
        ] );

        return (int) $wpdb->insert_id;
    }

    /** @return array<string,mixed> */
    private function row( string $table, int $id ): array {
        global $wpdb;

        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}{$table} WHERE id = %d",
            $id
        ), ARRAY_A );

        return is_array( $row ) ? $row : [];
    }

    // ---- POST /players -----------------------------------------------------

    public function test_creating_a_player_refuses_a_field_it_does_not_take(): void {
        [ $data, $status ] = $this->send( 'POST', 'players', [
            'first_name'     => 'Sem',
            'last_name'      => 'de Boer',
            'guardian_mail'  => 'ingrid@example.test',
        ] );

        $this->assertUnknownField( $data, $status, 'guardian_mail', 'guardian_email' );
    }

    public function test_creating_a_player_with_an_empty_body_names_what_is_missing(): void {
        [ $data, $status ] = $this->send( 'POST', 'players', [] );

        $this->assertSame( 400, $status );
        $this->assertSame( [ 'first_name', 'last_name' ], $this->missingFields( $data ) );
    }

    public function test_a_declared_player_body_still_creates(): void {
        $team_id = $this->makeTeam();

        [ $data, $status ] = $this->send( 'POST', 'players', [
            'first_name'     => 'Sem',
            'last_name'      => 'de Boer',
            'date_of_birth'  => '2010-04-02',
            'team_id'        => $team_id,
            'jersey_number'  => 7,
            'guardian_name'  => 'Ingrid de Boer',
            'guardian_email' => 'ingrid@example.test',
            'media_consent'  => 1,
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /players/{id} -------------------------------------------------

    public function test_updating_a_player_refuses_a_field_it_does_not_take(): void {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'PUT', 'players/' . $player_id, [
            'achternaam' => 'de Boer',
        ] );

        $this->assertUnknownField( $data, $status, 'achternaam', 'last_name' );
    }

    public function test_updating_a_player_leaves_an_omitted_field_alone(): void {
        $player_id = $this->makePlayer();

        [ , $status ] = $this->send( 'PUT', 'players/' . $player_id, [
            'guardian_phone' => '0612345678',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_players', $player_id );
        $this->assertSame( '0612345678', (string) ( $row['guardian_phone'] ?? '' ), 'the phone number was written' );
        $this->assertSame( 'Sem', (string) ( $row['first_name'] ?? '' ), 'the first name survived' );
        $this->assertSame( 'Ingrid de Boer', (string) ( $row['guardian_name'] ?? '' ), 'the guardian name survived' );
    }

    // ---- POST /teams -------------------------------------------------------

    public function test_creating_a_team_refuses_a_field_it_does_not_take(): void {
        [ $data, $status ] = $this->send( 'POST', 'teams', [
            'name'      => 'JO15-2',
            'leeftijd'  => 'JO15',
        ] );

        $this->assertUnknownField( $data, $status, 'leeftijd', 'age_group' );
    }

    public function test_creating_a_team_with_an_empty_body_names_what_is_missing(): void {
        [ $data, $status ] = $this->send( 'POST', 'teams', [] );

        $this->assertSame( 400, $status );
        $this->assertSame( [ 'name' ], $this->missingFields( $data ) );
    }

    public function test_a_declared_team_body_still_creates(): void {
        [ $data, $status ] = $this->send( 'POST', 'teams', [
            'name'      => 'JO15-2',
            'age_group' => 'JO15',
            'notes'     => 'Woensdagavond.',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /teams/{id} ---------------------------------------------------

    public function test_updating_a_team_refuses_a_field_it_does_not_take(): void {
        $team_id = $this->makeTeam();

        [ $data, $status ] = $this->send( 'PUT', 'teams/' . $team_id, [
            'naam' => 'JO17-2',
        ] );

        $this->assertUnknownField( $data, $status, 'naam', 'name' );
    }

    /**
     * The one this slice fixed rather than only guarded. `update_team()`
     * wrote `extract()` whole, and `extract()` defaults every missing key to
     * empty, so a body carrying only a name blanked the age group and the
     * notes.
     */
    public function test_updating_a_team_leaves_an_omitted_field_alone(): void {
        $team_id = $this->makeTeam( 'JO17-1', 'JO17', 'Zaterdagochtend.' );

        [ , $status ] = $this->send( 'PUT', 'teams/' . $team_id, [
            'name' => 'JO17-1 (selectie)',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_teams', $team_id );
        $this->assertSame( 'JO17-1 (selectie)', (string) ( $row['name'] ?? '' ), 'the name was written' );
        $this->assertSame( 'JO17', (string) ( $row['age_group'] ?? '' ), 'the age group survived' );
        $this->assertSame( 'Zaterdagochtend.', (string) ( $row['notes'] ?? '' ), 'the notes survived' );
    }

    public function test_updating_a_team_still_refuses_a_name_that_was_sent_blank(): void {
        $team_id = $this->makeTeam();

        [ $data, $status ] = $this->send( 'PUT', 'teams/' . $team_id, [
            'name' => '',
        ] );

        $this->assertSame( 400, $status );
        $this->assertSame( [ 'name' ], $this->missingFields( $data ) );
    }

    // ---- POST /evaluations -------------------------------------------------

    public function test_creating_an_evaluation_refuses_a_field_it_does_not_take(): void {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id' => $player_id,
            'eval_date' => '2026-02-01',
            'note'      => 'Typo for notes.',
        ] );

        $this->assertUnknownField( $data, $status, 'note', 'notes' );
    }

    /**
     * `eval_date` defaults to today when the key is absent, so the empty
     * body is missing only the player.
     */
    public function test_creating_an_evaluation_with_an_empty_body_names_the_player(): void {
        [ $data, $status ] = $this->send( 'POST', 'evaluations', [] );

        $this->assertSame( 400, $status );
        $this->assertSame( [ 'player_id' ], $this->missingFields( $data ) );
    }

    public function test_a_declared_evaluation_body_still_creates(): void {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'       => $player_id,
            'eval_date'       => '2026-02-01',
            'notes'           => 'Staff-only.',
            'player_feedback' => 'Goed meegedaan.',
            'opponent'        => 'Ajax',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /evaluations/{id} ---------------------------------------------

    public function test_updating_an_evaluation_refuses_a_field_it_does_not_take(): void {
        $eval_id = $this->makeEvaluation();

        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'feedback' => 'Typo for player_feedback.',
        ] );

        $this->assertUnknownField( $data, $status, 'feedback', 'player_feedback' );
    }

    /**
     * The evaluation edit surface autosaves (CLAUDE.md §6, model A), so this
     * is a coach's write-up rather than a theoretical column.
     */
    public function test_updating_an_evaluation_leaves_an_omitted_field_alone(): void {
        $eval_id = $this->makeEvaluation();

        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'notes' => 'Herzien na de wedstrijd.',
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_evaluations', $eval_id );
        $this->assertSame( 'Herzien na de wedstrijd.', (string) ( $row['notes'] ?? '' ), 'the notes were written' );
        $this->assertSame( 'Zag het spel goed.', (string) ( $row['player_feedback'] ?? '' ), 'the player feedback survived' );
        $this->assertSame( 'Ajax', (string) ( $row['opponent'] ?? '' ), 'the opponent survived' );
    }

    // ---- POST /goals -------------------------------------------------------

    public function test_creating_a_goal_refuses_a_field_it_does_not_take(): void {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'goals', [
            'player_id' => $player_id,
            'title'     => 'Twee keer per week links trappen',
            'titel'     => 'typo',
        ] );

        $this->assertUnknownField( $data, $status, 'titel', 'title' );
    }

    public function test_creating_a_goal_with_an_empty_body_names_what_is_missing(): void {
        [ $data, $status ] = $this->send( 'POST', 'goals', [] );

        $this->assertSame( 400, $status );
        $this->assertSame( [ 'player_id', 'title' ], $this->missingFields( $data ) );
    }

    public function test_a_declared_goal_body_still_creates(): void {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'goals', [
            'player_id'   => $player_id,
            'title'       => 'Twee keer per week links trappen',
            'description' => 'Na elke training tien herhalingen.',
            'priority'    => 'high',
            'due_date'    => '2026-06-01',
        ] );

        $this->assertSame( 200, $status );
        $this->assertGreaterThan( 0, (int) ( $data['data']['id'] ?? 0 ) );
    }

    // ---- PUT /goals/{id} ---------------------------------------------------

    public function test_updating_a_goal_refuses_a_field_it_does_not_take(): void {
        $goal_id = $this->makeGoal();

        [ $data, $status ] = $this->send( 'PUT', 'goals/' . $goal_id, [
            'omschrijving' => 'typo for description',
        ] );

        $this->assertUnknownField( $data, $status, 'omschrijving', 'description' );
    }

    /** The goal edit surface autosaves too. */
    public function test_updating_a_goal_leaves_an_omitted_field_alone(): void {
        $goal_id = $this->makeGoal();

        [ , $status ] = $this->send( 'PUT', 'goals/' . $goal_id, [
            'progress_pct' => 40,
        ] );
        $this->assertSame( 200, $status );

        $row = $this->row( 'tt_goals', $goal_id );
        $this->assertSame( 40, (int) ( $row['progress_pct'] ?? 0 ), 'the progress was written' );
        $this->assertSame( 'Twee keer per week links trappen', (string) ( $row['title'] ?? '' ), 'the title survived' );
        $this->assertSame( 'Na elke training tien herhalingen.', (string) ( $row['description'] ?? '' ), 'the description survived' );
    }

    // ---- fixtures that write through the routes ---------------------------

    /**
     * Created through the endpoint rather than with a raw insert, so a
     * fixture that could not write fails here instead of making the
     * assertions above pass over an unchanged row.
     */
    private function makeEvaluation(): int {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'       => $player_id,
            'eval_date'       => '2026-02-01',
            'notes'           => 'Eerste versie.',
            'player_feedback' => 'Zag het spel goed.',
            'opponent'        => 'Ajax',
        ] );

        $this->assertSame( 200, $status, 'the evaluation fixture was written' );
        $id = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id );

        return $id;
    }

    private function makeGoal(): int {
        $player_id = $this->makePlayer();

        [ $data, $status ] = $this->send( 'POST', 'goals', [
            'player_id'   => $player_id,
            'title'       => 'Twee keer per week links trappen',
            'description' => 'Na elke training tien herhalingen.',
        ] );

        $this->assertSame( 200, $status, 'the goal fixture was written' );
        $id = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $id );

        return $id;
    }
}
