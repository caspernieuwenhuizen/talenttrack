<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportPageFit;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Frontend\FrontendReportStudioView;

/**
 * #4571 — what the Report Studio canvas reads and writes: the family preview
 * cut on the server, the subject switch kept inside the builder's scope, the
 * publish confirm's count, the block width and limits, the revision in the
 * query, the page estimate, the library's switched-off blocks, and the view's
 * breadcrumb on every path.
 *
 * The callers are a head coach and an assistant coach of team A, a head of
 * development and a scout. Every refusal is paired with the grant it is the
 * edge of.
 */
final class ReportStudioCanvasTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $coach = 0;
    private int $assistant = 0;
    private int $hod = 0;
    private int $scout = 0;

    /** @var array<string,ReportBlock> */
    private array $real_blocks = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Canvas U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Canvas U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->player_a = $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bramvanderzee' );

        $this->coach     = $this->coachOf( $this->team_a, true );
        $this->assistant = $this->coachOf( $this->team_a, false );
        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->scout     = (int) self::factory()->user->create( [ 'role' => 'tt_scout' ] );

        $this->real_blocks = BlockRegistry::all();
        BlockRegistry::register( $this->staffOnlyBlock() );
        BlockRegistry::register( $this->squadBlock( 'Bramvanderzee' ) );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->coach );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        BlockRegistry::clear();
        foreach ( $this->real_blocks as $block ) BlockRegistry::register( $block );
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        unset( $_GET['id'] );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    // ---- the audience preview ----------------------------------------------

    public function test_a_family_preview_is_cut_on_the_server(): void {
        $id = $this->create( [ 'scope' => 'player', 'player_id' => $this->player_a ] );
        $this->patch( $id, [ [ 'id' => 'b1', 'key' => 'test_staff_only' ], [ 'id' => 'b2', 'key' => 'test_squad' ], [ 'id' => 'b3', 'key' => 'attendance_per_week' ] ] );

        $staff = $this->send( 'GET', "reports/{$id}/data", [], $this->period() );
        $this->assertSame( 200, $staff->get_status() );
        $staff_json = (string) wp_json_encode( $staff->get_data() );
        $this->assertStringContainsString( 'Staff secret', $staff_json, 'Precondition: staff read the staff-only block.' );
        $this->assertStringContainsString( 'Bramvanderzee', $staff_json, 'Precondition: staff read the squad breakdown.' );

        $family = $this->send( 'GET', "reports/{$id}/data", [], $this->period() + [ 'audience' => 'family' ] );
        $this->assertSame( 200, $family->get_status() );
        $data = $family->get_data()['data'];
        $this->assertSame( 'family', $data['context']['audience'] );
        $by_id = array_column( $data['blocks'], null, 'id' );
        $this->assertArrayHasKey( 'error', $by_id['b1'], 'the staff-only block is refused, not dimmed' );
        $this->assertArrayNotHasKey( 'data', $by_id['b1'] );
        $this->assertArrayNotHasKey( 'rows', $by_id['b2']['data'], 'no breakdown by player reaches a family' );
        $this->assertArrayHasKey( 'data', $by_id['b3'], 'a block a family may see still reads' );

        $json = (string) wp_json_encode( $family->get_data() );
        $this->assertStringNotContainsString( 'Staff secret', $json );
        $this->assertStringNotContainsString( 'Bramvanderzee', $json, 'another child\'s name is not in the response at all' );

        $bad = $this->send( 'GET', "reports/{$id}/data", [], [ 'audience' => 'press' ] );
        $this->assertSame( 400, $bad->get_status() );
        $this->assertSame( 'bad_filter', $this->code( $bad ) );
    }

    public function test_a_recipient_reads_the_reports_own_audience_only(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => [ 'head_coach' ] ] )->get_status() );

        wp_set_current_user( $this->coach );
        $own = $this->send( 'GET', "reports/{$id}/data", [], $this->period() + [ 'team_id' => $this->team_a, 'audience' => 'internal' ] );
        $this->assertSame( 200, $own->get_status(), 'the report\'s own audience' );

        $other = $this->send( 'GET', "reports/{$id}/data", [], $this->period() + [ 'team_id' => $this->team_a, 'audience' => 'scout' ] );
        $this->assertSame( 403, $other->get_status() );
        $this->assertSame( 'fixed_audience', $this->code( $other ) );
    }

    // ---- the subject switch --------------------------------------------------

    public function test_a_coach_is_offered_and_reads_only_their_own_subjects(): void {
        $options = $this->send( 'GET', 'reports/subject-options' );
        $this->assertSame( 200, $options->get_status() );
        $data = $options->get_data()['data'];
        $this->assertSame( [ $this->team_a ], array_column( $data['teams'], 'id' ) );
        $this->assertSame( [ $this->player_a ], array_column( $data['players'], 'id' ) );

        $id = $this->create( [ 'scope' => 'player', 'player_id' => $this->player_a ] );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/data", [], $this->period() + [ 'player_id' => $this->player_a ] )->get_status() );
        $other = $this->send( 'GET', "reports/{$id}/data", [], $this->period() + [ 'player_id' => $this->player_b ] );
        $this->assertSame( 403, $other->get_status(), 'another team\'s player is refused on the switch' );
        $this->assertSame( 'forbidden_player', $this->code( $other ) );

        wp_set_current_user( $this->hod );
        $all = $this->send( 'GET', 'reports/subject-options' )->get_data()['data'];
        $this->assertEqualsCanonicalizing( [ $this->team_a, $this->team_b ], array_values( array_intersect( array_column( $all['teams'], 'id' ), [ $this->team_a, $this->team_b ] ) ) );
        $this->assertContains( $this->player_b, array_column( $all['players'], 'id' ), 'the head of development sees everyone' );
    }

    // ---- the publish confirm's count -----------------------------------------

    public function test_the_reach_counts_who_holds_the_persona_and_reads_the_subject(): void {
        wp_set_current_user( $this->hod );
        $own_team   = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $other_team = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_b ] );

        $head = $this->send( 'GET', "reports/{$own_team}/reach", [], [ 'persona_keys' => 'head_coach' ] );
        $this->assertSame( 200, $head->get_status() );
        $this->assertSame( 1, $head->get_data()['data']['count'], 'the head coach of team A' );
        $this->assertSame( 'head_coach', $head->get_data()['data']['personas'][0]['key'] );
        $this->assertNotSame( '', $head->get_data()['data']['personas'][0]['label'] );

        $both = $this->send( 'GET', "reports/{$own_team}/reach", [], [ 'persona_keys' => 'head_coach,assistant_coach' ] );
        $this->assertSame( 2, $both->get_data()['data']['count'] );

        $this->assertSame( 0, $this->send( 'GET', "reports/{$other_team}/reach", [], [ 'persona_keys' => 'head_coach' ] )->get_data()['data']['count'], 'nobody who holds the persona reads team B' );
        $this->assertSame( 0, $this->send( 'GET', "reports/{$own_team}/reach" )->get_data()['data']['count'], 'no persona reaches nobody' );

        $bad = $this->send( 'GET', "reports/{$own_team}/reach", [], [ 'persona_keys' => 'parent' ] );
        $this->assertSame( 400, $bad->get_status(), 'a family is never published to' );

        wp_set_current_user( $this->coach );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$own_team}/reach", [], [ 'persona_keys' => 'head_coach' ] )->get_status(), 'only an editor counts' );
    }

    // ---- the draft -------------------------------------------------------------

    public function test_a_block_is_full_or_half_and_a_report_holds_at_most_40(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );

        $half = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'attendance_per_week', 'width' => 'half' ], [ 'id' => 'b2', 'key' => 'attendance_per_week' ] ] ] ] );
        $this->assertSame( 200, $half->get_status() );
        $blocks = $half->get_data()['data']['draft']['definition']['blocks'];
        $this->assertSame( [ 'half', 'full' ], array_column( $blocks, 'width' ), 'full is the default' );

        $third = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'key' => 'attendance_per_week', 'width' => 'third' ] ] ] ] );
        $this->assertSame( 400, $third->get_status() );

        $forty = array_fill( 0, 40, [ 'key' => 'attendance_per_week' ] );
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $forty ] ] )->get_status() );
        $forty[] = [ 'key' => 'attendance_per_week' ];
        $this->assertSame( 400, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $forty ] ] )->get_status(), 'a 41st block' );
    }

    public function test_the_revision_may_travel_in_the_query_and_a_stale_one_is_409(): void {
        $id  = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Kept' ] );
        $def = [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'attendance_per_week' ] ] ];

        $first = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => $def ], [ 'revision' => 0 ] );
        $this->assertSame( 200, $first->get_status() );
        $this->assertSame( 'Kept', $first->get_data()['data']['name'], 'the autosave without a name keeps it' );
        $this->assertSame( 1, $first->get_data()['data']['draft']['revision'] );

        $stale = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [] ] ], [ 'revision' => 0 ] );
        $this->assertSame( 409, $stale->get_status(), 'a second tab on the old revision' );
        $this->assertSame( 'stale_revision', $this->code( $stale ) );

        $kept = $this->send( 'GET', "reports/{$id}" )->get_data()['data']['draft'];
        $this->assertSame( 'attendance_per_week', $kept['definition']['blocks'][0]['key'], 'the newer draft is not lost' );
    }

    // ---- the page estimate -------------------------------------------------------

    public function test_the_data_route_returns_the_page_estimate(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        $this->patch( $id, [ [ 'id' => 'b1', 'key' => 'attendance_per_week' ] ] );

        $fit = $this->send( 'GET', "reports/{$id}/data", [], $this->period() )->get_data()['data']['page_fit'];
        $this->assertSame( 1, $fit['pages'] );
        $this->assertSame( [], $fit['breaks'] );
        $this->assertCount( 1, $fit['fill'] );
    }

    public function test_two_halves_share_a_row_and_a_long_report_breaks_onto_a_new_page(): void {
        $full  = ReportPageFit::estimate( [ [ 'id' => 'a', 'width' => 'full', 'rows' => 10 ], [ 'id' => 'b', 'width' => 'full', 'rows' => 10 ] ] );
        $halves = ReportPageFit::estimate( [ [ 'id' => 'a', 'width' => 'half', 'rows' => 10 ], [ 'id' => 'b', 'width' => 'half', 'rows' => 10 ] ] );
        $this->assertLessThan( $full['fill'][0], $halves['fill'][0], 'side by side is one row' );

        $long = [];
        for ( $i = 1; $i <= 8; $i++ ) $long[] = [ 'id' => 'b' . $i, 'width' => 'full', 'rows' => 10 ];
        $fit = ReportPageFit::estimate( $long );
        $this->assertGreaterThan( 1, $fit['pages'] );
        $this->assertCount( $fit['pages'] - 1, $fit['breaks'] );
        $this->assertNotContains( 'b1', $fit['breaks'], 'the first block opens the first page' );
    }

    // ---- the library -----------------------------------------------------------------

    public function test_the_library_lists_a_block_that_does_not_fit_with_its_reason(): void {
        $res = $this->send( 'GET', 'report-blocks', [], [ 'scope' => 'player' ] );
        $this->assertSame( 200, $res->get_status() );
        $data = $res->get_data()['data'];

        $off = array_column( $data['unavailable'], null, 'key' );
        $this->assertArrayHasKey( 'playing_time', $off, 'a team-only block on a player report' );
        $this->assertNotSame( '', $off['playing_time']['reason'] );
        $this->assertNotContains( 'playing_time', array_column( $data['blocks'], 'key' ) );

        foreach ( $data['blocks'] as $block ) {
            $this->assertNotSame( '', $block['source'], $block['key'] );
            $this->assertNotSame( '', $block['family'], $block['key'] );
        }
    }

    // ---- the view ----------------------------------------------------------------------

    public function test_the_view_renders_the_breadcrumb_on_every_path(): void {
        $own = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'name' => 'Coach pack' ] );

        $canvas = $this->render( $this->coach, $own );
        $this->assertStringContainsString( 'tt-breadcrumbs', $canvas );
        $this->assertStringContainsString( 'data-tt-rs-canvas', $canvas );
        $this->assertStringContainsString( 'Coach pack', $canvas );

        $list = $this->render( $this->coach, 0 );
        $this->assertStringContainsString( 'tt-breadcrumbs', $list );
        $this->assertStringContainsString( 'Coach pack', $list, 'the builder\'s own reports are listed' );

        $missing = $this->render( $this->coach, 999999 );
        $this->assertStringContainsString( 'tt-breadcrumbs', $missing );
        $this->assertStringNotContainsString( 'data-tt-rs-canvas', $missing );

        $not_theirs = $this->render( $this->hod, $own );
        $this->assertStringContainsString( 'tt-breadcrumbs', $not_theirs, 'a report kept for its owner' );
        $this->assertStringNotContainsString( 'data-tt-rs-canvas', $not_theirs );

        $scout = $this->render( $this->scout, $own );
        $this->assertStringContainsString( 'tt-breadcrumbs', $scout );
        $this->assertStringNotContainsString( 'data-tt-rs-canvas', $scout );

        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $locked = $this->render( $this->coach, $own );
        $this->assertStringContainsString( 'tt-breadcrumbs', $locked );
        $this->assertStringNotContainsString( 'data-tt-rs-canvas', $locked, 'the plan locks the canvas' );
    }

    public function test_the_new_routes_answer_402_without_the_plan_feature(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a ] );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        foreach ( [ "reports/{$id}/reach", 'reports/subject-options' ] as $route ) {
            $res = $this->send( 'GET', $route );
            $this->assertSame( 402, $res->get_status(), $route );
        }
    }

    // ---- helpers ------------------------------------------------------------------------

    /** @return array{from:string, to:string} */
    private function period(): array {
        return [ 'from' => '2020-03-01', 'to' => '2020-03-31' ];
    }

    /** @param array<string,mixed> $body */
    private function create( array $body ): int {
        $res = $this->send( 'POST', 'reports', $body );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
    }

    /** @param list<array<string,mixed>> $blocks */
    private function patch( int $id, array $blocks ): void {
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
    }

    private function render( int $user, int $id ): string {
        wp_set_current_user( $user );
        $this->flush();
        if ( $id > 0 ) $_GET['id'] = (string) $id; else unset( $_GET['id'] );
        ob_start();
        FrontendReportStudioView::render( $user, false );
        return (string) ob_get_clean();
    }

    /**
     * @param array<string,mixed> $body
     * @param array<string,mixed> $query
     */
    private function send( string $method, string $route, array $body = [], array $query = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $query !== [] ) $req->set_query_params( $query );
        if ( $method !== 'GET' ) {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $body ) );
        }
        $this->flush();
        return rest_do_request( $req );
    }

    private function code( WP_REST_Response $res ): string {
        return (string) ( $res->get_data()['errors'][0]['code'] ?? '' );
    }

    private function player( int $team, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Canvas', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function coachOf( int $team, bool $head ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => $head ? 'Head' : 'Assistant', 'last_name' => 'Coach',
            'role_type' => $head ? 'head_coach' : 'assistant_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => $head ? 'head_coach' : 'assistant_coach', 'is_head_coach' => $head ? 1 : 0,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    /** A block for staff only, about one player: a family preview must not carry it. */
    private function staffOnlyBlock(): ReportBlock {
        return new class implements ReportBlock {
    public function key(): string { return 'test_staff_only'; }
    public function label(): string { return 'Staff only (test)'; }
    public function scopes(): array { return [ ReportContext::SCOPE_PLAYER ]; }
    public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
    public function capability(): string { return 'tt_view_reports'; }
    public function options( ReportContext $context ): array { return []; }
    public function available( ReportContext $context ): bool { return true; }
    public function externalKeys(): array { return []; }
    public function render( ReportContext $context, array $options ): array {
        return [ 'rows' => [ [ 'name' => 'Staff secret' ] ] ];
    }
        };
    }

    /** A block a family may see, whose staff payload breaks down by player. */
    private function squadBlock( string $other_name ): ReportBlock {
        return new class( $other_name ) implements ReportBlock {
    public function __construct( private string $other ) {}
    public function key(): string { return 'test_squad'; }
    public function label(): string { return 'Squad (test)'; }
    public function scopes(): array { return [ ReportContext::SCOPE_PLAYER ]; }
    public function audiences(): array { return [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY ]; }
    public function capability(): string { return 'tt_view_reports'; }
    public function options( ReportContext $context ): array { return []; }
    public function available( ReportContext $context ): bool { return true; }
    public function externalKeys(): array { return [ 'breakdown', 'rows' ]; }
    public function render( ReportContext $context, array $options ): array {
        return [ 'breakdown' => 'player_id', 'rows' => [ [ 'player_id' => 1, 'name' => $this->other ] ] ];
    }
        };
    }
}
