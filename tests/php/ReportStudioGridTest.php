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
use TT\Modules\PersonaDashboard\Domain\GridLayout;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\ReportStudio\Document\StudioGrid;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportAccess;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportData;
use TT\Modules\ReportStudio\Domain\ReportGrid;
use TT\Modules\ReportStudio\Domain\ReportReader;
use TT\Modules\ReportStudio\Domain\ReportSnapshots;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Domain\StudioHome;
use TT\Modules\ReportStudio\Frontend\StudioHomeView;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Shared\Util\GridReadingOrder;

/**
 * #4962 — the Report Studio grid and dashboards as a persona's home.
 *
 * The grid's rules are the server's: a place outside the grid or on another
 * block is refused on save, a rows report refuses a place, a dashboard holds
 * at most twelve blocks, and the start rules keep the grid away from a
 * family, a scout and a scheduled mail. A grid prints nothing.
 *
 * A home is for a persona a report is published to, with a subject each
 * reader resolves; two readers of one persona read their own teams; the
 * choice is one club's `tt_config` row; and a twelve-block dashboard reads
 * in a number of statements that does not grow with the squad.
 */
final class ReportStudioGridTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private int $team_a = 0;
    private int $team_b = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;
    private int $hod = 0;
    private int $admin = 0;

    /** @var array<string,ReportBlock> */
    private array $real_blocks = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $keys = [];
        foreach ( ReportAccess::PUBLISHABLE_PERSONAS as $persona ) $keys[] = StudioHome::configKey( $persona );
        $this->markConfigKeys( ...$keys );
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Grid U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Grid U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        for ( $i = 0; $i < 3; $i++ ) $this->player( $this->team_a, 'Anna' . $i );
        for ( $i = 0; $i < 15; $i++ ) $this->player( $this->team_b, 'Bram' . $i );

        $this->coach_a = $this->coachOf( $this->team_a );
        $this->coach_b = $this->coachOf( $this->team_b );
        $this->hod     = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev' ] );
        $this->admin   = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );

        $this->real_blocks = BlockRegistry::all();
        BlockRegistry::register( $this->teamEchoBlock() );
        $this->flush();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        BlockRegistry::clear();
        foreach ( $this->real_blocks as $block ) BlockRegistry::register( $block );
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    // ---- the grid on save ---------------------------------------------------

    public function test_a_grid_place_outside_the_grid_or_on_another_block_is_refused(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'grid' ] );

        $refused = [
            'overlap'        => [ $this->at( 'b1', 0, 0, 6, 2 ), $this->at( 'b2', 4, 1, 4, 2 ) ],
            'past column 12' => [ $this->at( 'b1', 8, 0, 6, 2 ) ],
            'left of 0'      => [ $this->at( 'b1', -1, 0, 4, 2 ) ],
            'too tall'       => [ $this->at( 'b1', 0, 0, 4, ReportGrid::MAX_HEIGHT + 1 ) ],
            'below the grid' => [ $this->at( 'b1', 0, ReportGrid::ROWS - 1, 4, 2 ) ],
            'half a column'  => [ [ 'id' => 'b1', 'key' => 'heading', 'grid' => [ 'x' => 0.5, 'y' => 0, 'w' => 4, 'h' => 2 ] ] ],
            'no width'       => [ [ 'id' => 'b1', 'key' => 'heading', 'grid' => [ 'x' => 0, 'y' => 0, 'w' => 0, 'h' => 2 ] ] ],
            'an extra key'   => [ [ 'id' => 'b1', 'key' => 'heading', 'grid' => [ 'x' => 0, 'y' => 0, 'w' => 4, 'h' => 2, 'z' => 1 ] ] ],
        ];
        foreach ( $refused as $why => $blocks ) {
            $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
            $this->assertSame( 400, $res->get_status(), $why );
            $this->assertSame( 'invalid_field', $this->code( $res ), $why );
        }
        $this->assertSame( [], $this->draftBlocks( $id ), 'nothing refused was saved' );

        // Touching edges is not an overlap.
        $ok = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ $this->at( 'b1', 0, 0, 6, 2 ), $this->at( 'b2', 6, 0, 6, 2 ), $this->at( 'b3', 0, 2, 12, 1 ) ] ] ] );
        $this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
        $this->assertSame( [ 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 2 ], $this->draftBlocks( $id )[1]['grid'] );
    }

    public function test_a_grid_block_sent_without_a_place_gets_the_first_free_one(): void {
        $id = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'grid' ] );
        $this->patch( $id, [ $this->at( 'b1', 0, 0, 6, 2 ), [ 'id' => 'b2', 'key' => 'heading' ] ] );
        $this->assertSame( [ 'x' => 6, 'y' => 0, 'w' => ReportGrid::DEFAULT_WIDTH, 'h' => ReportGrid::DEFAULT_HEIGHT ], $this->draftBlocks( $id )[1]['grid'] );
    }

    public function test_a_rows_report_refuses_a_place_in_a_grid(): void {
        $id  = $this->create( [ 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'rows' ] );
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ $this->at( 'b1', 0, 0, 6, 2 ) ] ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'invalid_field', $this->code( $res ) );
        $this->patch( $id, [ [ 'id' => 'b1', 'key' => 'heading' ] ] );
    }

    public function test_a_dashboard_holds_at_most_twelve_blocks(): void {
        $id     = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'grid', 'period_mode' => 'on_open' ] );
        $blocks = [];
        for ( $i = 0; $i < 13; $i++ ) $blocks[] = $this->at( 'b' . ( $i + 1 ), ( $i % 3 ) * 4, intdiv( $i, 3 ) * 2, 4, 2 );
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 400, $res->get_status() );
        $this->patch( $id, array_slice( $blocks, 0, 12 ) );
    }

    // ---- who gets a grid ------------------------------------------------------

    public function test_the_start_rules_keep_the_grid_for_staff_on_screen(): void {
        $player = $this->player( $this->team_a, 'Cees' );
        $cases  = [
            'family audience'     => [ [ 'scope' => 'player', 'player_id' => $player, 'audience' => PlayerReportAudience::FAMILY, 'layout' => 'grid' ], 'layout' ],
            'scout audience'      => [ [ 'scope' => 'player', 'player_id' => $player, 'audience' => PlayerReportAudience::SCOUT, 'layout' => 'grid' ], 'layout' ],
            'scheduled mail'      => [ [ 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'grid', 'period_mode' => 'rolling', 'period_value' => 'last_month', 'sharing' => 'schedule' ], 'sharing' ],
            'shared with family'  => [ [ 'scope' => 'player', 'player_id' => $player, 'layout' => 'grid', 'sharing' => 'family' ], 'sharing' ],
        ];
        foreach ( $cases as $why => [ $body, $field ] ) {
            $res = $this->send( 'POST', 'reports', $body );
            $this->assertSame( 400, $res->get_status(), $why );
            $this->assertSame( 'invalid_field', $this->code( $res ), $why );
            $this->assertSame( $field, $res->get_data()['errors'][0]['details']['field'] ?? '', $why );
        }
        $this->create( [ 'scope' => 'player', 'player_id' => $player, 'layout' => 'grid' ] );
    }

    public function test_a_grid_is_never_printed_or_frozen(): void {
        $id = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'on_open', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $this->patch( $id, [ $this->at( 'b1', 6, 0, 6, 2, 'test_team_echo' ), $this->at( 'b2', 0, 0, 6, 2, 'test_team_echo' ) ] );
        $this->publish( $id, [ 'head_coach' ] );

        $doc = $this->send( 'GET', "reports/{$id}/document", [], $this->period() + [ 'team_id' => $this->team_a ] );
        $this->assertSame( 200, $doc->get_status(), (string) wp_json_encode( $doc->get_data() ) );
        $data = $doc->get_data()['data'];
        $this->assertSame( 'grid', $data['layout'] );
        $this->assertNull( $data['page_fit'], 'a grid has no pages' );
        $this->assertFalse( $data['context']['can_snapshot'] );
        $this->assertStringContainsString( '--tt-sg-x:7;--tt-sg-y:1;--tt-sg-w:6;--tt-sg-h:2', $data['html'] );
        $this->assertLessThan( strpos( $data['html'], 'tt-sd-block:b1' ), strpos( $data['html'], 'tt-sd-block:b2' ), 'the markup is in reading order: the left block first' );

        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );
        $frozen = ReportSnapshots::take( $report, [ 'player_id' => null, 'team_id' => $this->team_a, 'from' => '', 'to' => '' ], ReportReader::for( $this->hod ) );
        $this->assertInstanceOf( StudioError::class, $frozen );
        $this->assertSame( 'layout', $frozen->details['field'] ?? '' );
        $this->assertFalse( ReportGrid::printable( 'grid' ) );
        $this->assertTrue( ReportGrid::printable( 'rows' ) );
    }

    // ---- reading order ----------------------------------------------------------

    public function test_a_phone_reads_a_grid_top_row_first_left_to_right(): void {
        $places = [ [ 'id' => 'c', 'x' => 0, 'y' => 2 ], [ 'id' => 'b', 'x' => 6, 'y' => 0 ], [ 'id' => 'a', 'x' => 0, 'y' => 0 ], [ 'id' => 'd', 'x' => 6, 'y' => 2 ] ];
        $sorted = GridReadingOrder::sort( $places, static fn( array $p ): int => $p['x'], static fn( array $p ): int => $p['y'] );
        $this->assertSame( [ 'a', 'b', 'c', 'd' ], array_column( $sorted, 'id' ) );

        // The persona dashboard reads the same rule.
        $layout = new GridLayout( [ new WidgetSlot( 'c', '', 'M', 0, 2 ), new WidgetSlot( 'b', '', 'M', 6, 0 ), new WidgetSlot( 'a', '', 'M', 0, 0 ) ] );
        $this->assertSame( [ 'a', 'b', 'c' ], array_map( static fn( WidgetSlot $s ): string => $s->widget_id, $layout->desktopOrder() ) );

        $html = StudioGrid::body( [
            'context' => [ 'scope' => 'team', 'audience' => 'internal', 'from' => '2020-03-01', 'to' => '2020-03-31' ],
            'blocks'  => [
                [ 'id' => 'b2', 'key' => 'heading', 'data' => [ 'heading' => 'Second' ], 'grid' => [ 'x' => 6, 'y' => 0, 'w' => 6, 'h' => 1 ] ],
                [ 'id' => 'b3', 'key' => 'heading', 'data' => [ 'heading' => 'Third' ], 'grid' => [ 'x' => 0, 'y' => 1, 'w' => 12, 'h' => 1 ] ],
                [ 'id' => 'b1', 'key' => 'heading', 'data' => [ 'heading' => 'First' ], 'grid' => [ 'x' => 0, 'y' => 0, 'w' => 6, 'h' => 1 ] ],
            ],
        ], $this->hod );
        $first  = (int) strpos( $html, 'First' );
        $second = (int) strpos( $html, 'Second' );
        $third  = (int) strpos( $html, 'Third' );
        $this->assertTrue( $first < $second && $second < $third, 'the grid prints in reading order' );
    }

    // ---- a persona's home ----------------------------------------------------------

    public function test_a_studio_home_is_only_for_a_persona_a_report_is_published_to(): void {
        $dashboard = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'viewer', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $report    = $this->create( [ 'scope' => 'team', 'binding' => 'on_open', 'sharing' => 'role' ] );
        $unpublished = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'viewer', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $this->publish( $dashboard, [ 'head_coach' ] );
        $this->publish( $report, [ 'head_coach' ] );

        // A dashboard about one fixed team is kept for its owner (a fixed
        // subject is never published to a role); the admin owns this one, so
        // the refusal under test is the home rule, not the read check.
        wp_set_current_user( $this->admin );
        $fixed = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'team_id' => $this->team_a, 'layout' => 'grid', 'period_mode' => 'on_open' ] );
        $this->publish( $fixed, [] );
        foreach ( [ 'player', 'parent', 'scout', 'readonly_observer' ] as $persona ) {
            $res = $this->send( 'PUT', "studio-homes/{$persona}", [ 'report_id' => $dashboard ] );
            $this->assertSame( 400, $res->get_status(), $persona );
            $this->assertSame( 'persona', $res->get_data()['errors'][0]['details']['field'] ?? '', $persona );
        }
        $refused = [
            'a fixed subject'       => [ 'head_coach', $fixed ],
            'a report'              => [ 'head_coach', $report ],
            'not published'         => [ 'head_coach', $unpublished ],
            'not published to them' => [ 'assistant_coach', $dashboard ],
        ];
        foreach ( $refused as $why => [ $persona, $id ] ) {
            $res = $this->send( 'PUT', "studio-homes/{$persona}", [ 'report_id' => $id ] );
            $this->assertSame( 400, $res->get_status(), $why );
            $this->assertSame( 'invalid_field', $this->code( $res ), $why );
        }
        $this->assertSame( 0, StudioHome::storedId( 'head_coach' ), 'nothing refused was stored' );

        $ok = $this->send( 'PUT', 'studio-homes/head_coach', [ 'report_id' => $dashboard ] );
        $this->assertSame( 200, $ok->get_status(), (string) wp_json_encode( $ok->get_data() ) );
        $homes = array_column( $this->send( 'GET', 'studio-homes' )->get_data()['data']['homes'], 'report_id', 'persona' );
        $this->assertSame( $dashboard, $homes['head_coach'] );
        $this->assertNull( $homes['assistant_coach'] );
        $this->assertArrayNotHasKey( 'parent', $homes, 'a family never gets a Studio home' );

        $this->assertSame( 200, $this->send( 'DELETE', 'studio-homes/head_coach' )->get_status() );
        $this->assertNull( StudioHome::reportFor( 'head_coach' ) );

        // Choosing a role's home is the persona dashboard editor's right.
        wp_set_current_user( $this->coach_a );
        $this->assertSame( 403, $this->send( 'PUT', 'studio-homes/head_coach', [ 'report_id' => $dashboard ] )->get_status() );
    }

    public function test_each_reader_of_a_studio_home_reads_their_own_team(): void {
        $id = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'viewer', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $this->patch( $id, [ $this->at( 'b1', 0, 0, 6, 2, 'test_team_echo' ) ] );
        $this->publish( $id, [ 'head_coach' ] );
        $this->assertIsArray( StudioHome::set( 'head_coach', $id ) );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        foreach ( [ $this->coach_a => $this->team_a, $this->coach_b => $this->team_b ] as $coach => $team ) {
            wp_set_current_user( $coach );
            $this->flush();
            $this->assertSame( $id, StudioHomeView::resolve( $coach, 'head_coach' )['id'] ?? null, 'the landing hosts the Studio home' );

            $read = ReportData::compose( $report, [ 'source' => '', 'player_id' => null, 'team_id' => null, 'from' => '2020-03-01', 'to' => '2020-03-31' ], ReportReader::for( $coach ) );
            $this->assertIsArray( $read );
            $this->assertSame( $team, $read['context']['team_id'] );
            $this->assertSame( $team, $read['blocks'][0]['data']['team_id'], 'the block reads the reader\'s own team' );

            // Asking for the other team still reads their own.
            $other = $team === $this->team_a ? $this->team_b : $this->team_a;
            $asked = ReportData::compose( $report, [ 'source' => '', 'player_id' => null, 'team_id' => $other, 'from' => '2020-03-01', 'to' => '2020-03-31' ], ReportReader::for( $coach ) );
            $this->assertIsArray( $asked );
            $this->assertSame( $team, $asked['context']['team_id'] );
        }

        // A persona without the home sees its own template.
        $this->assertNull( StudioHomeView::resolve( $this->coach_a, 'assistant_coach' ) );
    }

    public function test_the_home_choice_is_one_clubs_tt_config_row(): void {
        $id = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'viewer', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $this->publish( $id, [ 'head_coach' ] );
        $this->assertIsArray( StudioHome::set( 'head_coach', $id ) );

        global $wpdb;
        $club = (int) CurrentClub::id();
        $row  = $wpdb->get_var( $wpdb->prepare(
            "SELECT config_value FROM {$wpdb->prefix}tt_config WHERE club_id = %d AND config_key = %s",
            $club, 'persona_dashboard.head_coach.studio_report'
        ) );
        $this->assertSame( (string) $id, $row );
        $this->assertSame( '', (string) get_option( 'persona_dashboard.head_coach.studio_report', '' ), 'never in wp_options' );

        $other = static fn(): int => 2;
        add_filter( 'tt_current_club_id', $other );
        try {
            $this->assertSame( 0, StudioHome::storedId( 'head_coach' ), 'another club has no home from this one' );
            $this->assertNull( StudioHome::reportFor( 'head_coach' ) );
        } finally {
            remove_filter( 'tt_current_club_id', $other );
        }
        $this->assertSame( $id, StudioHome::storedId( 'head_coach' ) );
    }

    // ---- the landing's cost ----------------------------------------------------------

    /**
     * The landing reads a twelve-block dashboard in one request. What that
     * request costs is bounded by the blocks, not by the squad: a coach of 3
     * players and a coach of 15 read it in the same number of statements.
     */
    public function test_a_twelve_block_dashboard_reads_in_statements_that_do_not_grow_with_the_squad(): void {
        $keys = [ 'letterhead', 'key_figures', 'attendance_per_week', 'attendance_per_player', 'attendance_flags', 'minutes_share',
            'playing_time', 'match_results', 'team_statistics', 'goals_assists', 'open_goals', 'pdp_conversations' ];
        $id     = $this->create( [ 'kind' => 'dashboard', 'scope' => 'team', 'binding' => 'viewer', 'layout' => 'grid', 'sharing' => 'role', 'period_mode' => 'on_open' ] );
        $blocks = [];
        foreach ( $keys as $i => $key ) $blocks[] = $this->at( 'b' . ( $i + 1 ), ( $i % 3 ) * 4, intdiv( $i, 3 ) * 2, 4, 2, $key );
        $this->patch( $id, $blocks );
        $this->publish( $id, [ 'head_coach' ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $count = function ( int $coach ) use ( $report ): int {
            global $wpdb;
            wp_set_current_user( $coach );
            $in = [ 'source' => '', 'player_id' => null, 'team_id' => null, 'from' => '2020-03-01', 'to' => '2020-03-31' ];
            // Once to warm what a request reads once whatever it does, then measured.
            ReportData::compose( $report, $in, ReportReader::for( $coach ) );
            $this->flush();
            $before = (int) $wpdb->num_queries;
            $read   = ReportData::compose( $report, $in, ReportReader::for( $coach ) );
            $after  = (int) $wpdb->num_queries;
            $this->assertIsArray( $read );
            $this->assertCount( 12, $read['blocks'] );
            return $after - $before;
        };

        $small = $count( $this->coach_a );
        $large = $count( $this->coach_b );
        // Recorded in the PR (#4962): the statements one landing read costs.
        fwrite( STDERR, sprintf( "\n[#4962] 12-block dashboard: %d statements for a squad of 3, %d for a squad of 15\n", $small, $large ) );

        $this->assertSame( $small, $large, 'the statement count does not grow with the squad' );
        $this->assertLessThanOrEqual( 12 * 25, $large, 'bounded by the blocks' );
    }

    // ---- helpers -----------------------------------------------------------------------

    /** @return array<string,mixed> */
    private function at( string $id, int $x, int $y, int $w, int $h, string $key = 'heading' ): array {
        return [ 'id' => $id, 'key' => $key, 'grid' => [ 'x' => $x, 'y' => $y, 'w' => $w, 'h' => $h ] ];
    }

    /** @return array<string,string> */
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

    /** @param list<string> $personas */
    private function publish( int $id, array $personas ): void {
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => $personas ] );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
    }

    /** @return list<array<string,mixed>> */
    private function draftBlocks( int $id ): array {
        $draft = $this->send( 'GET', "reports/{$id}" )->get_data()['data']['draft'] ?? null;
        return is_array( $draft ) ? array_values( $draft['definition']['blocks'] ?? [] ) : [];
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
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => $first, 'last_name' => 'Grid', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** The head coach of one team. */
    private function coachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => 'Head', 'last_name' => 'Coach',
            'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
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

    /** A block that says which team it was read for. */
    private function teamEchoBlock(): ReportBlock {
        return new class implements ReportBlock {
    public function key(): string { return 'test_team_echo'; }
    public function label(): string { return 'Team echo (test)'; }
    public function scopes(): array { return [ ReportContext::SCOPE_TEAM ]; }
    public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
    public function capability(): string { return 'tt_view_reports'; }
    public function options( ReportContext $context ): array { return []; }
    public function available( ReportContext $context ): bool { return true; }
    public function externalKeys(): array { return []; }
    public function render( ReportContext $context, array $options ): array {
        return [ 'team_id' => $context->teamId ];
    }
        };
    }
}
