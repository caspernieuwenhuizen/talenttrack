<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Evaluations\EvalCategoryNotesRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\PersonaResolver;
use TT\Shared\Frontend\FrontendEvaluationsView;

/**
 * #3949 — a short note per evaluation category.
 *
 * The notes follow the evaluation's own contract: a write is partial by
 * category (an omitted note is left alone, a blank clears it), a note can
 * sit on a category that has no rating, the length is capped server-side,
 * and whoever may read the evaluation reads its notes, on the API and on
 * the detail view alike. Every refusal sits beside a grant.
 */
final class EvalCategoryNotesTest extends WP_UnitTestCase {

    private int $admin   = 0;
    private int $teamA   = 0;
    private int $teamB   = 0;
    private int $playerA = 0;
    private int $playerB = 0;
    private int $catMain = 0;
    private int $catSub  = 0;
    private int $catLone = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Notes A', 'age_group' => 'U14' ] );
        $this->teamA = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Notes B', 'age_group' => 'U15' ] );
        $this->teamB = (int) $wpdb->insert_id;
        $this->playerA = $this->makePlayer( 'Alpha', $this->teamA );
        $this->playerB = $this->makePlayer( 'Bravo', $this->teamB );

        $this->catMain = $this->makeCategory( 'notes_main', 'Technical notes', null );
        $this->catSub  = $this->makeCategory( 'notes_sub', 'Short passing notes', $this->catMain );
        $this->catLone = $this->makeCategory( 'notes_lone', 'Heading notes', $this->catMain );

        $this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->admin );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        wp_set_current_user( 0 );
        $_GET = [];
        parent::tear_down();
    }

    // Writes

    public function test_create_stores_notes_including_one_on_an_unrated_category(): void {
        [ $data, $status ] = $this->send( 'POST', 'evaluations', [
            'player_id'      => $this->playerA,
            'eval_date'      => '2024-09-01',
            'ratings'        => [ (string) $this->catSub => 7 ],
            'category_notes' => [
                (string) $this->catSub  => 'Accurate, but slow under pressure.',
                (string) $this->catLone => 'Not rated today; won every header though.',
            ],
        ] );
        $this->assertSame( 200, $status, 'the fixture must write, or nothing below means anything' );
        $eval_id = (int) ( $data['data']['id'] ?? 0 );
        $this->assertGreaterThan( 0, $eval_id );

        $notes = ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id );
        $this->assertSame( 'Accurate, but slow under pressure.', $notes[ $this->catSub ] ?? null );
        $this->assertSame( 'Not rated today; won every header though.', $notes[ $this->catLone ] ?? null, 'a note needs no rating beside it' );
    }

    public function test_an_update_leaves_the_notes_it_omits_alone(): void {
        $eval_id = $this->seedEvaluation( $this->playerA );
        ( new EvalCategoryNotesRepository() )->write( $eval_id, [
            $this->catMain => 'Main note as first written.',
            $this->catSub  => 'Sub note as first written.',
        ] );
        $this->assertCount( 2, ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ), 'the fixture must write both notes' );

        // A body with no notes at all.
        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [ 'notes' => 'Staff note, rewritten.' ] );
        $this->assertSame( 200, $status );

        // A body naming one category's note only.
        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'category_notes' => [ (string) $this->catSub => 'Sub note, rewritten.' ],
        ] );
        $this->assertSame( 200, $status );

        $notes = ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id );
        $this->assertSame( 'Main note as first written.', $notes[ $this->catMain ] ?? null, 'an omitted note must be left alone' );
        $this->assertSame( 'Sub note, rewritten.', $notes[ $this->catSub ] ?? null );
    }

    public function test_a_blank_note_clears_it(): void {
        $eval_id = $this->seedEvaluation( $this->playerA );
        ( new EvalCategoryNotesRepository() )->write( $eval_id, [ $this->catSub => 'To be cleared.' ] );
        $this->assertArrayHasKey( $this->catSub, ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ) );

        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'category_notes' => [ (string) $this->catSub => '' ],
        ] );
        $this->assertSame( 200, $status );
        $this->assertArrayNotHasKey( $this->catSub, ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ) );
    }

    public function test_a_note_over_the_limit_is_refused_and_one_at_the_limit_is_stored(): void {
        $eval_id = $this->seedEvaluation( $this->playerA );

        [ $data, $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'category_notes' => [ (string) $this->catSub => str_repeat( 'a', EvalCategoryNotesRepository::MAX_LENGTH + 1 ) ],
        ] );
        $this->assertSame( 400, $status );
        $this->assertSame( 'note_too_long', (string) ( $data['errors'][0]['code'] ?? '' ) );
        $this->assertSame( [], ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ), 'refused before anything is written' );

        [ , $status ] = $this->send( 'PUT', 'evaluations/' . $eval_id, [
            'category_notes' => [ (string) $this->catSub => str_repeat( 'a', EvalCategoryNotesRepository::MAX_LENGTH ) ],
        ] );
        $this->assertSame( 200, $status );
        $this->assertSame( EvalCategoryNotesRepository::MAX_LENGTH, mb_strlen( ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id )[ $this->catSub ] ?? '' ) );
    }

    // Reads: the same visibility as the rating

    public function test_a_reader_of_the_evaluation_gets_its_notes_and_another_team_gets_not_found(): void {
        $evalA = $this->seedEvaluation( $this->playerA );
        $evalB = $this->seedEvaluation( $this->playerB );
        ( new EvalCategoryNotesRepository() )->write( $evalA, [ $this->catSub => 'Visible to the squad coach.' ] );
        ( new EvalCategoryNotesRepository() )->write( $evalB, [ $this->catSub => 'Another team.' ] );

        $coach = $this->makeHeadCoach( $this->teamA );
        wp_set_current_user( $coach );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations/' . $evalA ) );
        $this->assertSame( 200, (int) $response->get_status(), 'the head coach reads their own squad' );
        $body  = (array) $response->get_data();
        $notes = (array) ( $body['data']['category_notes'] ?? [] );
        $this->assertSame( 'Visible to the squad coach.', $notes[ $this->catSub ] ?? ( $notes[ (string) $this->catSub ] ?? null ) );

        $response = rest_do_request( new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations/' . $evalB ) );
        $this->assertSame( 404, (int) $response->get_status(), 'another team is answered as a missing evaluation' );
    }

    public function test_the_detail_view_follows_the_same_per_player_check(): void {
        $evalA = $this->seedEvaluation( $this->playerA );
        $evalB = $this->seedEvaluation( $this->playerB );
        ( new EvalCategoryNotesRepository() )->write( $evalA, [ $this->catSub => 'Shown on the detail.' ] );
        ( new EvalCategoryNotesRepository() )->write( $evalB, [ $this->catSub => 'Must not show.' ] );

        $coach = $this->makeHeadCoach( $this->teamA );

        $html = $this->renderDetail( $coach, $evalA );
        $this->assertStringContainsString( 'Shown on the detail.', $html, 'the grant: the squad coach sees the evaluation and its note' );

        $html = $this->renderDetail( $coach, $evalB );
        $this->assertStringNotContainsString( 'Must not show.', $html );
        $this->assertStringContainsString( 'That evaluation no longer exists, or you do not have access.', $html, 'answered as not found' );
    }

    // Purge

    public function test_notes_go_with_their_evaluation_on_purge(): void {
        $eval_id = $this->seedEvaluation( $this->playerA );
        ( new EvalCategoryNotesRepository() )->write( $eval_id, [ $this->catSub => 'Goes with it.' ] );
        $this->assertCount( 1, ( new EvalCategoryNotesRepository() )->forEvaluation( $eval_id ), 'the fixture must write the note' );

        $deleted = ( new ArchiveRepository() )->deletePermanently( 'evaluation', [ $eval_id ] );
        $this->assertSame( 1, $deleted );

        global $wpdb;
        $left = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_eval_category_notes WHERE evaluation_id = %d",
            $eval_id
        ) );
        $this->assertSame( 0, $left );
    }

    // Fixtures

    /**
     * @param array<string,mixed> $body
     * @return array{0:array<string,mixed>,1:int}
     */
    private function send( string $method, string $route, array $body ): array {
        $request = new WP_REST_Request( $method, '/talenttrack/v1/' . ltrim( $route, '/' ) );
        $request->set_header( 'content-type', 'application/json' );
        $request->set_body( (string) wp_json_encode( $body ) );
        $response = rest_get_server()->dispatch( $request );
        return [ (array) $response->get_data(), (int) $response->get_status() ];
    }

    private function seedEvaluation( int $player_id ): int {
        global $wpdb;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_evaluations", [
            'club_id'   => (int) CurrentClub::id(),
            'player_id' => $player_id,
            'coach_id'  => $this->admin,
            'eval_date' => '2024-09-01',
        ] );
        $this->assertNotFalse( $ok, 'evaluation insert must succeed' );
        $id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_eval_ratings", [
            'club_id'       => (int) CurrentClub::id(),
            'evaluation_id' => $id,
            'category_id'   => $this->catSub,
            'rating'        => 7,
        ] );
        return $id;
    }

    private function makeCategory( string $key, string $label, ?int $parent_id ): int {
        global $wpdb;
        $row = [
            'club_id'       => (int) CurrentClub::id(),
            'category_key'  => $key,
            'label'         => $label,
            'is_active'     => 1,
            'display_order' => 99,
        ];
        if ( $parent_id !== null ) $row['parent_id'] = $parent_id;
        $ok = $wpdb->insert( "{$wpdb->prefix}tt_eval_categories", $row );
        $this->assertNotFalse( $ok, 'category insert must succeed: ' . $wpdb->last_error );
        return (int) $wpdb->insert_id;
    }

    private function makePlayer( string $last, int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Notes',
            'last_name'     => $last,
            'team_id'       => $team_id,
            'status'        => 'active',
            'date_of_birth' => '2011-04-02',
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write the player' );
        return $id;
    }

    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => (int) CurrentClub::id(),
            'first_name' => 'Hoofd',
            'last_name'  => 'Trainer',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $person_id, 'the fixture must write the person' );

        $wpdb->insert( "{$p}tt_team_people", [
            'team_id'       => $team_id,
            'person_id'     => $person_id,
            'role_in_team'  => 'head_coach',
            'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        $this->assertContains( 'head_coach', PersonaResolver::personasFor( $uid ) );
        AuthorizationService::flushCache();
        $this->assertTrue( AuthorizationService::canReadPlayerSection( $uid, $this->playerA, 'evaluations' ), 'the head coach must read their squad, or the refusals are vacuous' );
        return $uid;
    }

    private function renderDetail( int $user_id, int $eval_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET = [ 'tt_view' => 'evaluations', 'id' => (string) $eval_id ];
        ob_start();
        FrontendEvaluationsView::render( $user_id, false );
        $html = (string) ob_get_clean();
        $_GET = [];
        return $html;
    }
}
