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
use TT\Modules\Analytics\Reports\ReportFilters;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\SharedWithMe;
use TT\Modules\ReportStudio\Frontend\FrontendReportView;

/**
 * #4591 — the reader's view of a published Studio report: "Shared with me",
 * the persona-and-subject rule on the page and the data route, a fixed
 * subject a recipient cannot move, the subjects a reader may switch to, the
 * period pills, and the sensitive-field registry on rows about a player.
 */
final class ReportViewerTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $player_b = 0;
    private int $coach = 0;
    private int $assistant = 0;
    private int $hod = 0;

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
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Viewer U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Viewer U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->player_a = $this->player( $this->team_a, 'Anna' );
        $this->player_b = $this->player( $this->team_b, 'Bram' );
        $wpdb->update( "{$wpdb->prefix}tt_players", [ 'guardian_email' => 'family-b@example.test' ], [ 'id' => $this->player_b ] );

        $this->coach     = $this->coachOf( $this->team_a, true );
        $this->assistant = $this->coachOf( $this->team_a, false );
        $this->hod       = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );

        $this->real_blocks = BlockRegistry::all();
        BlockRegistry::register( $this->guardianBlock( $this->player_b ) );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
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

    public function test_a_head_coach_opens_a_report_shared_with_them_and_sees_their_own_team(): void {
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role', 'name' => 'Team pack' ], [ 'head_coach' ] );

        wp_set_current_user( $this->coach );
        $this->assertContains( $id, array_column( SharedWithMe::forReader( ReportReader::for( $this->coach ) ), 'id' ) );

        $launcher = $this->renderLauncher( $this->coach );
        $this->assertStringContainsString( 'Team pack', $launcher );
        $this->assertMatchesRegularExpression( '/tt_view=report(&|&amp;|&#038;)id=' . $id . '\b/', $launcher, 'the tile opens the reader\'s view' );

        $page = $this->renderView( $this->coach, $id );
        $this->assertStringContainsString( 'tt-breadcrumbs', $page );
        $this->assertStringContainsString( 'data-tt-rv', $page );

        $own = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $own->get_status() );
        $this->assertSame( $this->team_a, $own->get_data()['data']['context']['team_id'] );

        $subjects = $this->send( 'GET', "reports/{$id}/subjects" )->get_data()['data'];
        $this->assertSame( [ $this->team_a ], array_column( $subjects['teams'], 'id' ), 'only the teams the coach reads' );
        $other = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published', 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $other->get_status(), 'another team is refused on every call' );
    }

    public function test_a_reader_without_one_of_its_personas_is_refused(): void {
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ], [ 'head_coach' ] );

        wp_set_current_user( $this->assistant );
        $this->assertTrue( current_user_can( 'tt_view_reports' ), 'Precondition: the assistant reads reports.' );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a ] )->get_status() );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/subjects" )->get_status() );
        $this->assertNotContains( $id, array_column( SharedWithMe::forReader( ReportReader::for( $this->assistant ) ), 'id' ) );

        $page = $this->renderView( $this->assistant, $id );
        $this->assertStringContainsString( 'tt-breadcrumbs', $page );
        $this->assertStringContainsString( 'This report is not shared with you.', $page );
        $this->assertStringNotContainsString( 'data-tt-rv', $page );
    }

    public function test_a_recipient_cannot_move_a_fixed_subject(): void {
        global $wpdb;
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ], [ 'head_coach' ] );
        // A fixed team report reaching a role, written as stored data.
        $wpdb->update( "{$wpdb->prefix}tt_reports", [ 'binding' => 'fixed', 'team_id' => $this->team_a ], [ 'id' => $id ] );

        wp_set_current_user( $this->coach );
        $forged = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published', 'team_id' => $this->team_b ] );
        $this->assertSame( 403, $forged->get_status() );
        $this->assertSame( 'fixed_subject', $this->code( $forged ) );

        $stored = $this->send( 'GET', "reports/{$id}/data", [ 'source' => 'published', 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $stored->get_status() );
        $this->assertSame( $this->team_a, $stored->get_data()['data']['context']['team_id'] );
        $this->assertSame( [ 'teams' => [], 'players' => [] ], $this->send( 'GET', "reports/{$id}/subjects" )->get_data()['data'], 'no subject switch' );
    }

    public function test_a_period_pill_is_computed_today_and_only_where_the_period_is_open(): void {
        global $wpdb;
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ], [ 'head_coach' ] );

        wp_set_current_user( $this->coach );
        $res = $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a, 'period' => 'last_month' ] );
        $this->assertSame( 200, $res->get_status() );
        $window = ReportFilters::periodWindow( 'last_month', (string) current_time( 'Y-m-d' ) );
        $this->assertSame( [ $window['from'] ?? '', $window['to'] ?? '' ], [ $res->get_data()['data']['context']['from'], $res->get_data()['data']['context']['to'] ] );

        $this->assertSame( 400, $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a, 'period' => 'forever' ] )->get_status() );

        $wpdb->update( "{$wpdb->prefix}tt_reports", [ 'period_mode' => 'fixed', 'period_value' => '2020-03-01/2020-03-31' ], [ 'id' => $id ] );
        $fixed = $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a, 'period' => 'last_month' ] );
        $this->assertSame( 403, $fixed->get_status(), 'a recipient cannot move a set period' );
        $this->assertSame( 'fixed_period', $this->code( $fixed ) );
    }

    public function test_a_row_about_a_player_goes_through_the_sensitive_field_registry(): void {
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ], [ 'head_coach' ], [ [ 'id' => 'b1', 'key' => 'test_guardian' ] ] );

        wp_set_current_user( $this->coach );
        $this->assertFalse( AuthorizationService::isStaffForPlayer( $this->coach, $this->player_b ), 'Precondition: the coach is not staff for team B\'s player.' );
        $res = $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ] );
        $this->assertSame( 200, $res->get_status() );
        $row = $res->get_data()['data']['blocks'][0]['data']['rows'][0] ?? [];
        $this->assertSame( 'Bram', $row['name'] ?? null, 'Precondition: the row reaches the reader.' );
        $this->assertArrayNotHasKey( 'guardian_email', $row, 'a family contact the reader may not see is cut on the server' );

        $preview = $this->send( 'POST', 'report-blocks/test_guardian/preview', [ 'context' => [ 'scope' => 'team', 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31' ] ] );
        $this->assertSame( 200, $preview->get_status() );
        $preview_row = $preview->get_data()['data']['data']['rows'][0] ?? [];
        $this->assertSame( 'Bram', $preview_row['name'] ?? null, 'Precondition: the preview carries the row.' );
        $this->assertArrayNotHasKey( 'guardian_email', $preview_row, 'and on the preview route too' );

        // A breakdown by player names the player in `key`.
        $by_key = \TT\Modules\ReportStudio\Domain\BlockEngine::masked( [
            'breakdown' => 'player_id',
            'rows'      => [ [ 'key' => (string) $this->player_b, 'label' => 'Bram', 'value' => 3.0, 'guardian_email' => 'family-b@example.test' ] ],
        ], $this->coach );
        $this->assertArrayNotHasKey( 'guardian_email', $by_key['rows'][0], 'a row keyed by player is masked too' );
        $this->assertSame( 'Bram', $by_key['rows'][0]['label'] );

        wp_set_current_user( $this->hod );
        $this->assertTrue( AuthorizationService::isStaffForPlayer( $this->hod, $this->player_b ), 'Precondition: the head of development is staff for every player.' );
        $hod_row = $this->send( 'GET', "reports/{$id}/data", [ 'team_id' => $this->team_a, 'from' => '2020-03-01', 'to' => '2020-03-31', 'source' => 'published' ] )->get_data()['data']['blocks'][0]['data']['rows'][0] ?? [];
        $this->assertSame( 'family-b@example.test', $hod_row['guardian_email'] ?? null, 'and kept for a reader who may' );
    }

    public function test_the_view_renders_the_breadcrumb_on_every_path(): void {
        $id = $this->published( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ], [ 'head_coach' ] );

        $missing = $this->renderView( $this->coach, 999999 );
        $this->assertStringContainsString( 'tt-breadcrumbs', $missing );
        $this->assertStringNotContainsString( 'data-tt-rv', $missing );

        $nobody = (int) self::factory()->user->create( [ 'role' => 'subscriber' ] );
        $refused = $this->renderView( $nobody, $id );
        $this->assertStringContainsString( 'tt-breadcrumbs', $refused );
        $this->assertStringNotContainsString( 'data-tt-rv', $refused );

        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $locked = $this->renderView( $this->coach, $id );
        $this->assertStringContainsString( 'tt-breadcrumbs', $locked );
        $this->assertStringNotContainsString( 'data-tt-rv', $locked );
        $this->assertSame( 402, $this->send( 'GET', "reports/{$id}/subjects" )->get_status() );
    }

    // ---- helpers ------------------------------------------------------------------

    /**
     * A report by the head of development, published to these personas.
     *
     * @param array<string,mixed>       $choices
     * @param list<string>              $personas
     * @param list<array<string,mixed>> $blocks
     */
    private function published( array $choices, array $personas, array $blocks = [ [ 'id' => 'b1', 'key' => 'attendance_per_week' ] ] ): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', $choices );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id = (int) $res->get_data()['data']['id'];
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] )->get_status() );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => $personas ] )->get_status() );
        wp_set_current_user( 0 );
        return $id;
    }

    private function renderView( int $user, int $id ): string {
        wp_set_current_user( $user );
        $this->flush();
        $_GET['id'] = (string) $id;
        ob_start();
        FrontendReportView::render( $user, false );
        return (string) ob_get_clean();
    }

    private function renderLauncher( int $user ): string {
        wp_set_current_user( $user );
        $this->flush();
        ob_start();
        \TT\Shared\Frontend\FrontendReportsLauncherView::render( $user, false );
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $params */
    private function send( string $method, string $route, array $params = [] ): WP_REST_Response {
        $req = new WP_REST_Request( $method, '/talenttrack/v1/' . $route );
        if ( $method === 'GET' ) {
            if ( $params !== [] ) $req->set_query_params( $params );
        } else {
            $req->set_header( 'Content-Type', 'application/json' );
            $req->set_body( (string) wp_json_encode( (object) $params ) );
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
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Viewer', 'status' => 'active',
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

    /** A team block whose row is about another team's player and carries a family contact. */
    private function guardianBlock( int $player_id ): ReportBlock {
        return new class( $player_id ) implements ReportBlock {
            public function __construct( private int $player ) {}
            public function key(): string { return 'test_guardian'; }
            public function label(): string { return 'Guardian (test)'; }
            public function scopes(): array { return [ ReportContext::SCOPE_TEAM ]; }
            public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
            public function capability(): string { return 'tt_view_reports'; }
            public function options( ReportContext $context ): array { return []; }
            public function available( ReportContext $context ): bool { return true; }
            public function externalKeys(): array { return []; }
            public function render( ReportContext $context, array $options ): array {
                return [ 'breakdown' => 'player_id', 'rows' => [ [ 'player_id' => $this->player, 'name' => 'Bram', 'guardian_email' => 'family-b@example.test' ] ] ];
            }
        };
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
