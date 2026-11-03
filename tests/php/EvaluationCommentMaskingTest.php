<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RecordRedactor;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterRegistry;
use TT\Shared\Frontend\FrontendEvaluationsView;

/**
 * #4693 — the coach's written words about a minor follow the
 * sensitive-field registry. The staff-only notes go to staff for the player
 * and whoever may write the player's evaluations; the player feedback and
 * the category notes go to those and the family. The read-only observer,
 * who reads evaluations academy-wide, keeps the ratings, dates, type and
 * evaluator, and gets none of the words.
 *
 * Every refusal is paired with a reader who keeps the text, and the
 * observer's read of the evaluation is asserted first.
 */
final class EvaluationCommentMaskingTest extends WP_UnitTestCase {

    private const NOTES    = 'Staff note 4693: worried about focus at home';
    private const FEEDBACK = 'Feedback 4693: keep your head up after a mistake';
    private const CATEGORY = 'Category note 4693: scanning before receiving';

    private int $club     = 0;
    private int $team     = 0;
    private int $player   = 0;
    private int $eval     = 0;
    private int $observer = 0;
    private int $hod      = 0;
    private int $parent   = 0;

    public function set_up(): void {
        parent::set_up();
        global $wpdb, $wp_rest_server;
        $p          = $wpdb->prefix;
        $this->club = (int) CurrentClub::id();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => $this->club, 'name' => 'JO16-1' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Lars', 'last_name' => 'Bos', 'status' => 'active',
        ] );
        $this->player = (int) $wpdb->insert_id;

        $this->observer = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer' ] );
        $this->hod      = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->parent   = (int) self::factory()->user->create( [ 'role' => 'tt_parent' ] );
        $wpdb->insert( "{$p}tt_player_parents", [
            'club_id' => $this->club, 'player_id' => $this->player, 'parent_user_id' => $this->parent,
        ] );

        $wpdb->insert( "{$p}tt_evaluations", [
            'club_id'         => $this->club,
            'player_id'       => $this->player,
            'coach_id'        => $this->hod,
            'eval_date'       => gmdate( 'Y-m-d', strtotime( '-3 days' ) ),
            'notes'           => self::NOTES,
            'player_feedback' => self::FEEDBACK,
        ] );
        $this->eval = (int) $wpdb->insert_id;

        $wpdb->insert( "{$p}tt_eval_categories", [
            'club_id' => $this->club, 'category_key' => 'zz_mask_4693', 'label' => 'Scanning', 'parent_id' => null, 'display_order' => 901, 'is_active' => 1,
        ] );
        $category = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_eval_ratings", [
            'club_id' => $this->club, 'evaluation_id' => $this->eval, 'category_id' => $category, 'rating' => 7.0,
        ] );
        $wpdb->insert( "{$p}tt_eval_category_notes", [
            'club_id' => $this->club, 'evaluation_id' => $this->eval, 'category_id' => $category, 'note' => self::CATEGORY,
        ] );
        AuthorizationService::flushCache();

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );

        $this->assertTrue( AuthorizationService::canViewPlayer( $this->observer, $this->player ), 'the observer reads the player' );
        $this->assertTrue(
            AuthorizationService::canReadPlayerSection( $this->observer, $this->player, 'evaluations' ),
            'and the evaluations; what is hidden below is the words, not the record'
        );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        unset( $_GET['id'], $_GET['action'] );
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        AuthorizationService::flushCache();
        parent::tear_down();
    }

    public function test_the_registry_names_the_comment_fields(): void {
        $fields = RecordRedactor::fields( 'evaluations' );
        $this->assertSame( 'evaluation_notes', $fields['notes'] ?? null );
        $this->assertSame( 'evaluation_notes', $fields['notes_excerpt'] ?? null );
        $this->assertSame( 'evaluation_comment', $fields['player_feedback'] ?? null );
        $this->assertSame( 'evaluation_comment', $fields['category_notes'] ?? null );
        foreach ( [ 'eval_date', 'avg_rating', 'coach_name', 'eval_type_id' ] as $kept ) {
            $this->assertArrayNotHasKey( $kept, $fields, "{$kept} stays visible" );
        }
    }

    public function test_the_evaluation_record_keeps_the_words_for_the_readers_they_are_for(): void {
        $observer = $this->record( $this->observer );
        $this->assertSame( $this->eval, (int) ( $observer['id'] ?? 0 ), 'the observer gets the evaluation' );
        $this->assertArrayHasKey( 'eval_date', $observer );
        foreach ( [ 'notes', 'player_feedback', 'category_notes' ] as $field ) {
            $this->assertArrayNotHasKey( $field, $observer, $field );
        }

        $hod = $this->record( $this->hod );
        $this->assertSame( self::NOTES, $hod['notes'] ?? null, 'HoD keeps the staff notes' );
        $this->assertSame( self::FEEDBACK, $hod['player_feedback'] ?? null );

        $parent = $this->record( $this->parent );
        $this->assertArrayNotHasKey( 'notes', $parent, 'staff-only notes never reach the family' );
        $this->assertSame( self::FEEDBACK, $parent['player_feedback'] ?? null, 'the family keeps the feedback' );
        $this->assertStringContainsString( self::CATEGORY, (string) wp_json_encode( $parent['category_notes'] ?? [] ) );
    }

    /** The contract: no evaluation route hands the observer a comment. */
    public function test_no_evaluation_route_hands_the_observer_a_comment(): void {
        $paths = [
            '/talenttrack/v1/evaluations',
            '/talenttrack/v1/evaluations/' . $this->eval,
            '/talenttrack/v1/players/' . $this->player . '/evaluations',
            '/talenttrack/v1/players/' . $this->player . '/evaluations/' . $this->eval . '/detail',
        ];
        foreach ( $paths as $path ) {
            $request = new WP_REST_Request( 'GET', $path );
            if ( strpos( $path, '/players/' ) !== false ) $request->set_param( 'scope', 'all' );

            wp_set_current_user( $this->observer );
            AuthorizationService::flushCache();
            $response = $this->serve( $request );
            $this->assertSame( 200, $response->get_status(), "the observer reaches {$path}" );
            $body = (string) wp_json_encode( $response->get_data() );
            foreach ( [ 'Staff note 4693', 'Feedback 4693', 'Category note 4693' ] as $text ) {
                $this->assertStringNotContainsString( $text, $body, "{$path} hands the observer a comment" );
            }

            wp_set_current_user( $this->hod );
            AuthorizationService::flushCache();
            $body = (string) wp_json_encode( $this->serve( $request )->get_data() );
            $this->assertMatchesRegularExpression( '/4693/', $body, "HoD still gets the words on {$path}" );
        }
    }

    /**
     * The list's search follows the same notes rule: a word that appears
     * only in the staff notes finds nothing for the observer, while the
     * same list still answers a search on the player's name; HoD's search
     * on the word still finds the evaluation.
     */
    public function test_the_list_search_follows_the_notes_rule(): void {
        foreach ( [ 'player file' => [ 'player_id' => $this->player ], 'whole list' => [] ] as $label => $params ) {
            $this->assertSame( 0, $this->searchCount( $this->observer, 'worried', $params ), "{$label}: the observer finds nothing in the notes" );
            $this->assertSame( 1, $this->searchCount( $this->hod, 'worried', $params ), "{$label}: HoD still finds the evaluation" );
        }
        $this->assertSame( 1, $this->searchCount( $this->observer, 'Lars', [ 'player_id' => $this->player ] ), 'the observer still searches player names' );

        $this->assertStringNotContainsString( 'Bos', $this->adminSearch( $this->observer, 'worried' ), 'wp-admin: no match for the observer' );
        $this->assertStringContainsString( 'Bos', $this->adminSearch( $this->hod, 'worried' ), 'wp-admin: HoD still finds it' );
    }

    /**
     * A team-scoped coach on the player's team reads the notes and searches
     * them: the record keeps `notes`, and the coach-scoped list's search on
     * the notes word still finds the evaluation.
     */
    public function test_a_team_coach_keeps_the_notes_and_their_search(): void {
        $coach = $this->makeHeadCoach();
        $this->assertFalse( AuthorizationService::isStaffAcademyWide( $coach ), 'the fixture is team-scoped' );

        $record = $this->record( $coach );
        $this->assertSame( self::NOTES, $record['notes'] ?? null, 'the team coach keeps the notes' );
        $this->assertSame( 1, $this->searchCount( $coach, 'worried', [] ), 'and the coach-scoped search on them' );
        $this->assertTrue( RecordRedactor::readsOnTeams( 'evaluations', 'notes', $coach, [ $this->team ] ) );
        $this->assertFalse( RecordRedactor::readsOnTeams( 'evaluations', 'notes', $this->observer, [ $this->team ] ), 'not the observer' );
    }

    /** The rate card's chart carries the category notes for staff only. */
    public function test_the_rate_card_leaves_the_category_notes_out_for_the_observer(): void {
        $this->assertStringContainsString( 'scanning before receiving', $this->renderRateCard( $this->hod ), 'HoD sees the note behind the point' );
        $observer = $this->renderRateCard( $this->observer );
        $this->assertStringContainsString( 'Scanning', $observer, 'the observer gets the chart' );
        $this->assertStringNotContainsString( 'scanning before receiving', $observer );
    }

    /** The wp-admin edit form is for a reader who may write the evaluation. */
    public function test_the_admin_edit_form_is_not_a_way_round_the_mask(): void {
        $hod = $this->renderAdmin( $this->hod, 'edit' );
        $this->assertStringContainsString( 'name="notes"', $hod, 'HoD gets the form' );
        $this->assertStringContainsString( 'worried about focus', $hod );

        $observer = $this->renderAdmin( $this->observer, 'edit' );
        $this->assertStringNotContainsString( 'name="notes"', $observer, 'no form for a reader who may not write' );
        $this->assertStringNotContainsString( 'worried about focus', $observer );
        $this->assertStringContainsString( 'Hidden', $observer, 'the masked record view instead' );
    }

    /**
     * The player detail route drops the groups that exist only because of a
     * note, and `has_detail` is answered without the notes, for a reader the
     * category notes are hidden from.
     */
    public function test_the_player_detail_route_leaves_no_note_only_groups(): void {
        foreach ( [ 'HoD' => [ $this->hod, 1, true ], 'observer' => [ $this->observer, 0, false ] ] as $label => [ $uid, $groups, $has_detail ] ) {
            wp_set_current_user( $uid );
            AuthorizationService::flushCache();
            $detail = (array) $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/evaluations/' . $this->eval . '/detail' ) )->get_data();
            $this->assertCount( $groups, (array) ( $detail['groups'] ?? [] ), "{$label}: the note-only group" );

            $request = new WP_REST_Request( 'GET', '/talenttrack/v1/players/' . $this->player . '/evaluations' );
            $request->set_param( 'scope', 'all' );
            $list = (array) json_decode( (string) wp_json_encode( $this->serve( $request )->get_data() ), true );
            $this->assertSame( $has_detail, $list['items'][0]['has_detail'] ?? null, "{$label}: has_detail" );
        }
    }

    public function test_the_detail_view_shows_hidden_where_the_notes_would_be(): void {
        $hod = $this->renderDetail( $this->hod );
        $this->assertStringContainsString( 'worried about focus', $hod, 'HoD reads the notes' );
        $this->assertStringContainsString( 'scanning before receiving', $hod );

        $html = $this->renderDetail( $this->observer );
        $this->assertStringContainsString( 'Scanning', $html, 'the observer sees the ratings' );
        $this->assertStringContainsString( 'tt-masked-value', $html, '"Hidden" where the notes would be' );
        $this->assertStringNotContainsString( 'worried about focus', $html );
        $this->assertStringNotContainsString( 'scanning before receiving', $html );
    }

    public function test_the_player_report_reads_the_observer_without_the_notes(): void {
        $from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $to   = gmdate( 'Y-m-d' );

        AuthorizationService::flushCache();
        $hod = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer( $this->player, $from, $to, [ 'ratings' ], $this->hod );
        $this->assertNotNull( $hod );
        $this->assertStringContainsString( 'worried about focus', (string) wp_json_encode( $hod ), 'the staff report is unchanged' );

        AuthorizationService::flushCache();
        $observer = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer( $this->player, $from, $to, [ 'ratings' ], $this->observer );
        $this->assertNotNull( $observer );
        $this->assertSame( 'internal', $observer['audience'] ?? null, 'the observer reads the internal report' );
        $body = (string) wp_json_encode( $observer );
        $this->assertStringNotContainsString( 'worried about focus', $body );
        $this->assertStringContainsString( '"notes_hidden":true', $body, 'the report knows notes were there' );
        $this->assertStringContainsString( '"rating"', $body, 'the ratings stay' );
    }

    public function test_the_player_report_shows_hidden_in_the_notes_cell_for_the_observer(): void {
        // The report's evaluations table: the Notes column stays, labelled
        // for the stacked phone layout, and its cell says "Hidden" rather
        // than printing the words or a dash that reads as "nothing written".
        $from = gmdate( 'Y-m-d', strtotime( '-30 days' ) );
        $to   = gmdate( 'Y-m-d' );

        AuthorizationService::flushCache();
        wp_set_current_user( $this->observer );
        $report = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer( $this->player, $from, $to, [ 'ratings' ], $this->observer );
        $this->assertNotNull( $report );

        ob_start();
        \TT\Modules\Analytics\Frontend\PlayerReportPage::renderBlocks( $report, [ 'from' => $from, 'to' => $to, 'period' => '' ] );
        $html = (string) ob_get_clean();

        $this->assertSame(
            1,
            preg_match( '#<td role="cell" data-label="Notes"><span class="tt-masked-value"#', $html ),
            'the Notes cell carries the masked value'
        );
        $this->assertStringNotContainsString( 'worried about focus', $html );
    }

    public function test_the_evaluations_file_leaves_the_words_out_for_the_observer(): void {
        $this->assertStringContainsString( 'worried about focus', $this->csvBody( $this->hod ) );
        $observer = $this->csvBody( $this->observer );
        $this->assertStringContainsString( 'Lars', $observer, 'the observer gets the rows' );
        $this->assertStringNotContainsString( 'worried about focus', $observer );
        $this->assertStringNotContainsString( 'scanning before receiving', $observer );
    }

    // ---- fixtures ------------------------------------------------------

    private function serve( WP_REST_Request $request ): WP_REST_Response {
        $server   = rest_get_server();
        $response = rest_ensure_response( $server->dispatch( $request ) );
        $response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
        $this->assertInstanceOf( WP_REST_Response::class, $response );
        return $response;
    }

    /** @return array<string, mixed> */
    private function record( int $user_id ): array {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $response = $this->serve( new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations/' . $this->eval ) );
        $this->assertSame( 200, $response->get_status(), 'user ' . $user_id . ' reads the evaluation' );
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return (array) ( $data['data'] ?? [] );
    }

    /** @param array<string, int> $params */
    private function searchCount( int $user_id, string $search, array $params ): int {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $request = new WP_REST_Request( 'GET', '/talenttrack/v1/evaluations' );
        $request->set_param( 'search', $search );
        foreach ( $params as $key => $value ) $request->set_param( $key, $value );
        $response = $this->serve( $request );
        $this->assertSame( 200, $response->get_status() );
        $data = (array) json_decode( (string) wp_json_encode( $response->get_data() ), true );
        return count( (array) ( $data['data']['rows'] ?? [] ) );
    }

    private function renderRateCard( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        ob_start();
        \TT\Modules\Stats\Admin\PlayerRateCardView::render( $this->player, [], admin_url( 'admin.php?page=tt-rate-cards' ) );
        return (string) ob_get_clean();
    }

    private function renderAdmin( int $user_id, string $action ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['action'] = $action;
        $_GET['id']     = (string) $this->eval;
        ob_start();
        \TT\Modules\Evaluations\Admin\EvaluationsPage::render_page();
        $html = (string) ob_get_clean();
        unset( $_GET['action'], $_GET['id'] );
        return $html;
    }

    private function adminSearch( int $user_id, string $search ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['s'] = $search;
        ob_start();
        \TT\Modules\Evaluations\Admin\EvaluationsPage::render_page();
        $html = (string) ob_get_clean();
        unset( $_GET['s'] );
        return $html;
    }

    /** A head coach of the player's team, built the way production builds one. */
    private function makeHeadCoach(): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id );

        $wpdb->insert( "{$p}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Hoofd', 'last_name' => 'Trainer', 'role_type' => 'head_coach', 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_team_people", [
            'club_id' => $this->club, 'team_id' => $this->team, 'person_id' => $person_id, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person_id, 'role_id' => $role_id, 'scope_type' => 'team', 'scope_id' => $this->team,
        ] );
        AuthorizationService::flushCache();
        return $uid;
    }

    private function renderDetail( int $user_id ): string {
        wp_set_current_user( $user_id );
        AuthorizationService::flushCache();
        $_GET['id'] = (string) $this->eval;
        ob_start();
        FrontendEvaluationsView::render( $user_id, user_can( $user_id, 'administrator' ) );
        return (string) ob_get_clean();
    }

    private function csvBody( int $user_id ): string {
        AuthorizationService::flushCache();
        $exporter = ExporterRegistry::get( 'player_evaluations' );
        $this->assertNotNull( $exporter );
        $result = $exporter->collect( new ExportRequest( 'player_evaluations', 'csv', $this->club, $user_id, null, [ 'team_id' => $this->team ] ) );
        return (string) wp_json_encode( $result['rows'] ?? [] );
    }
}
