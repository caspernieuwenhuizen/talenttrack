<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReport;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\Reports\ReportBrandColour;
use TT\Modules\Analytics\Reports\PlayerReportLayout;
use TT\Modules\Analytics\Reports\TeamMonthlyComposers\KpiComposer;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Blocks\TextBlock;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportDefinition;
use TT\Modules\ReportStudio\Domain\ReportPageFit;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;

/**
 * #4572 — the layout and text blocks: the letterhead and the key figures read
 * the same composers as the team monthly report and the player report, and
 * show the same figures; the staff-only key figures never reach a family; a
 * coach is refused another team's subject; an external payload carries no
 * breakdown by player; each block runs a bounded number of statements; text
 * is plain and capped; a page break is for rows only and moves the next
 * block to a new page.
 */
final class ReportStudioLayoutBlocksTest extends WP_UnitTestCase {

    private const FROM = '2020-03-01';
    private const TO   = '2020-03-31';

    private int $club = 1;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $manager = 0;
    private int $hod = 0;
    private int $pa1 = 0;
    private int $pa2 = 0;
    private int $pb1 = 0;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        $this->flush();

        global $wpdb;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team_a  = $this->insertTeam( 'Layout U12' );
        $this->team_b  = $this->insertTeam( 'Layout U15' );
        $this->manager = $this->makePersona( 'tt_team_manager', 'team_manager', $this->team_a );
        $this->hod     = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team_a );

        $this->pa1 = $this->insertPlayer( $this->team_a, 'Anna' );
        $this->pa2 = $this->insertPlayer( $this->team_a, 'Bram' );
        $this->pb1 = $this->insertPlayer( $this->team_b, 'Cas' );

        // Activity in the window and in the window before, so every delta is a number.
        $prev = $this->insertActivity( $this->team_a, '2020-02-11' );
        $a1   = $this->insertActivity( $this->team_a, '2020-03-02' );
        $a2   = $this->insertActivity( $this->team_a, '2020-03-10' );
        $this->insertAttendance( $prev, $this->pa1, 'Present' );
        $this->insertAttendance( $prev, $this->pa2, 'Absent' );
        $this->insertAttendance( $a1, $this->pa1, 'Present' );
        $this->insertAttendance( $a1, $this->pa2, 'Absent' );
        $this->insertAttendance( $a2, $this->pa1, 'Present' );
        $this->insertAttendance( $a2, $this->pa2, 'Present' );

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        $this->flush();
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_every_layout_block_is_registered(): void {
        foreach ( [ 'letterhead', 'key_figures', 'heading', 'text', 'page_break' ] as $key ) {
            $this->assertNotNull( BlockRegistry::find( $key ), $key );
        }
    }

    public function test_the_team_letterhead_equals_the_monthly_reports(): void {
        $block  = $this->preview( 'letterhead', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'letterhead' ], $this->hod );
        $theirs = $report['data']['letterhead'];

        foreach ( $theirs as $field => $value ) {
            if ( $field === 'generated_at' ) continue;
            $this->assertSame( $value, $block['data'][ $field ] ?? null, "letterhead {$field}" );
        }
        $this->assertSame( 'Layout U12', $block['data']['team_name'] );
        $this->assertSame( 2, $block['data']['squad_size'] );
        $this->assertSame( 2, $block['data']['activity_count'] );

        $primary = ReportBrandColour::primary( $this->hod );
        $this->assertSame( [ 'primary' => $primary, 'tint' => ReportBrandColour::tint( $primary ), 'number' => ReportBrandColour::numberColour( $primary ) ], $block['data']['brand'] );
    }

    public function test_the_player_letterhead_equals_the_player_reports_for_every_audience(): void {
        foreach ( [ PlayerReportAudience::INTERNAL, PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
            $block  = $this->preview( 'letterhead', [ 'scope' => 'player', 'player_id' => $this->pa1, 'from' => self::FROM, 'to' => self::TO, 'audience' => $audience ] );
            $report = ( new PlayerReport() )->forPlayer( $this->pa1, self::FROM, self::TO, [ 'letterhead' ], $this->hod, $audience );
            $this->assertIsArray( $report );
            $theirs = $report['data']['letterhead'];

            foreach ( $theirs as $field => $value ) {
                if ( $field === 'generated_at' ) continue;
                $this->assertSame( $value, $block['data'][ $field ] ?? null, "{$audience} letterhead {$field}" );
            }
            $this->assertArrayNotHasKey( 'rows', $block['data'], 'a letterhead has no breakdown' );
        }
    }

    /**
     * #4949 — the key figures read the monthly report's KPI through the
     * report's own removal, so the attendance figure follows the attendance
     * right: the head coach of the team gets it, the read-only observer, who
     * reads the team's reports but not its attendance, does not.
     */
    public function test_the_key_figures_attendance_follows_the_attendance_right(): void {
        $coach    = $this->makePersona( 'tt_coach', 'head_coach', $this->team_a );
        $observer = $this->makePersona( 'tt_readonly_observer', 'readonly_observer', $this->team_a );
        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $coach, $this->team_a ), 'Precondition: the head coach reads attendance.' );
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $observer, $this->team_a ), 'Precondition: the observer does not.' );

        $block = BlockRegistry::find( 'key_figures' );
        $this->assertNotNull( $block );
        foreach ( [ $coach => true, $observer => false ] as $uid => $sees ) {
            $context = ReportContext::fromInput( $this->team( $this->team_a ), $uid );
            $this->assertInstanceOf( ReportContext::class, $context );
            $keys = array_column( $block->render( $context, [] )['figures'], 'key' );
            if ( $sees ) {
                $this->assertContains( 'attendance_pct', $keys, 'the head coach gets the attendance figure' );
            } else {
                $this->assertNotContains( 'attendance_pct', $keys, 'the observer gets no attendance figure' );
                $this->assertContains( 'activities', $keys, 'and the other figures still' );
            }
        }
    }

    public function test_the_key_figures_equal_the_monthly_reports_tiles(): void {
        $block  = $this->preview( 'key_figures', $this->team( $this->team_a ) );
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'kpi' ], $this->hod );
        $kpi    = $report['data']['kpi'];

        $keys = array_column( $block['data']['figures'], 'key' );
        $this->assertSame( [ 'activities', 'attendance_pct', 'minutes_share_median_pct', 'evaluated', 'squad_rating', 'needs_attention' ], $keys );
        // One owner for the tiles: the block's order, labels and units are
        // the composer's, which the monthly report's document also reads.
        $tiles = KpiComposer::tiles();
        $this->assertSame( array_column( $tiles, 'key' ), $keys );
        $this->assertSame( array_column( $tiles, 'label' ), array_column( $block['data']['figures'], 'label' ) );
        $this->assertSame( array_column( $tiles, 'unit' ), array_column( $block['data']['figures'], 'unit' ) );
        $this->assertSame( array_column( $tiles, 'delta_unit' ), array_column( $block['data']['figures'], 'delta_unit' ) );
        foreach ( $block['data']['figures'] as $figure ) {
            $theirs = $kpi[ $figure['key'] ];
            $this->assertSame( $theirs['value'], $figure['value'], "{$figure['key']} value" );
            $this->assertSame( $theirs['delta'], $figure['delta'], "{$figure['key']} delta" );
        }
        $this->assertSame( $report['previous'], $block['data']['previous'] );

        // The hand count: 3 of 4 in March, 1 of 2 in February.
        $by_key = array_column( $block['data']['figures'], null, 'key' );
        $this->assertEquals( 75.0, $by_key['attendance_pct']['value'] );
        $this->assertEquals( 25.0, $by_key['attendance_pct']['delta'] );
        $this->assertSame( 2, $by_key['activities']['value'] );
        $this->assertSame( 1, $by_key['activities']['delta'] );
    }

    public function test_a_family_never_receives_the_key_figures(): void {
        $block = BlockRegistry::find( 'key_figures' );
        $this->assertNotNull( $block );
        $this->assertSame( [ PlayerReportAudience::INTERNAL ], $block->audiences() );

        $family = BlockEngine::preview( 'key_figures', [ 'scope' => 'player', 'player_id' => $this->pa1, 'audience' => PlayerReportAudience::FAMILY ], [], $this->hod );
        $this->assertInstanceOf( StudioError::class, $family );

        $listed = array_map( static fn( $b ): string => $b->key(), BlockRegistry::availableFor( ReportContext::forListing( 'player', PlayerReportAudience::FAMILY, $this->hod ) ) );
        $this->assertNotContains( 'key_figures', $listed );
        foreach ( [ 'letterhead', 'heading', 'text', 'page_break' ] as $key ) {
            $this->assertContains( $key, $listed, "{$key} is for the family too" );
        }
    }

    public function test_a_coach_out_of_scope_is_refused_each_blocks_subject(): void {
        $cases = [
            [ 'letterhead', $this->team( $this->team_b ) ],
            [ 'key_figures', $this->team( $this->team_b ) ],
            [ 'letterhead', [ 'scope' => 'player', 'player_id' => $this->pb1, 'from' => self::FROM, 'to' => self::TO ] ],
            [ 'heading', $this->team( $this->team_b ) ],
            [ 'text', $this->team( $this->team_b ) ],
            [ 'page_break', $this->team( $this->team_b ) ],
        ];
        foreach ( $cases as [ $key, $context ] ) {
            $out = BlockEngine::preview( $key, $context, [], $this->manager );
            $this->assertInstanceOf( StudioError::class, $out, "{$key} on {$context['scope']}" );
            $this->assertSame( 403, $out->status, "{$key} on {$context['scope']}" );
        }
    }

    public function test_an_external_payload_has_no_breakdown_and_no_group_average(): void {
        $options = [ 'heading' => [ 'heading' => 'Season' ], 'text' => [ 'heading' => 'About', 'text' => 'How to read this.' ] ];
        foreach ( [ 'letterhead', 'heading', 'text', 'page_break' ] as $key ) {
            foreach ( [ PlayerReportAudience::FAMILY, PlayerReportAudience::SCOUT ] as $audience ) {
                $out = BlockEngine::preview( $key, [ 'scope' => 'player', 'player_id' => $this->pa1, 'from' => self::FROM, 'to' => self::TO, 'audience' => $audience ], $options[ $key ] ?? [], $this->hod );
                $this->assertIsArray( $out, "{$key} for {$audience}" );
                $this->assertArrayNotHasKey( 'rows', $out['data'] );
                $this->assertArrayNotHasKey( 'group_average', $out['data'] );
            }
        }
    }

    public function test_each_block_runs_a_bounded_number_of_statements(): void {
        $context = $this->team( $this->team_a );
        $bounded = [ 'letterhead', 'heading', 'text', 'page_break' ];

        $before = [];
        foreach ( $bounded as $key ) $before[ $key ] = $this->statementsFor( $key, $context );
        $kpi_before    = $this->statementsFor( 'key_figures', $context );
        $report_before = $this->reportStatements();

        for ( $i = 0; $i < 6; $i++ ) $this->insertPlayer( $this->team_a, 'Extra' . $i );

        foreach ( $bounded as $key ) {
            $this->assertSame( $before[ $key ], $this->statementsFor( $key, $context ), "{$key} ran a statement per player" );
        }
        // The key figures run the monthly report's own KPI composer: the
        // status verdict behind "need attention" is read per player there
        // too. The block adds nothing per player on top of the report.
        $kpi_after    = $this->statementsFor( 'key_figures', $context );
        $report_after = $this->reportStatements();
        $this->assertSame( $report_after - $report_before, $kpi_after - $kpi_before, 'the key figures cost more per player than the report' );
    }

    public function test_text_is_plain_and_capped(): void {
        $out = $this->preview( 'text', $this->team( $this->team_a ), [ 'heading' => '<b>Our plan</b>', 'text' => "Line one\nLine <script>x</script>two" ] );
        $this->assertSame( 'Our plan', $out['data']['heading'] );
        $this->assertStringNotContainsString( '<', $out['data']['text'] );
        $this->assertStringContainsString( "\n", $out['data']['text'], 'line breaks are kept' );

        $long = BlockEngine::preview( 'text', $this->team( $this->team_a ), [ 'text' => str_repeat( 'a', TextBlock::MAX_TEXT + 1 ) ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $long );
        $this->assertSame( 400, $long->status );

        $heading = BlockEngine::preview( 'heading', $this->team( $this->team_a ), [ 'heading' => [ 'not', 'text' ] ], $this->hod );
        $this->assertInstanceOf( StudioError::class, $heading );
    }

    public function test_a_page_break_is_for_rows_only(): void {
        $definition = [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'page_break' ] ] ];
        $this->assertIsArray( ReportDefinition::normalise( $definition, 'rows' ) );
        $grid = ReportDefinition::normalise( $definition, 'grid' );
        $this->assertInstanceOf( StudioError::class, $grid );
        $this->assertSame( 400, $grid->status );

        global $wp_rest_server;
        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        wp_set_current_user( $this->hod );

        $req = new WP_REST_Request( 'GET', '/talenttrack/v1/report-blocks' );
        $req->set_query_params( [ 'scope' => 'team', 'layout' => 'grid' ] );
        $data = rest_do_request( $req )->get_data()['data'];
        $this->assertNotContains( 'page_break', array_column( $data['blocks'], 'key' ) );
        $off = array_column( $data['unavailable'], 'reason', 'key' );
        $this->assertArrayHasKey( 'page_break', $off );

        $req->set_query_params( [ 'scope' => 'team', 'layout' => 'rows' ] );
        $this->assertContains( 'page_break', array_column( rest_do_request( $req )->get_data()['data']['blocks'], 'key' ) );

        $req->set_query_params( [ 'layout' => 'columns' ] );
        $this->assertSame( 400, rest_do_request( $req )->get_status() );

        // The folded form is refused the same way.
        $req->set_query_params( [ 'filter' => [ 'layout' => 'columns' ] ] );
        $folded = rest_do_request( $req );
        $this->assertSame( 400, $folded->get_status() );
        $this->assertSame( 'bad_filter', (string) ( $folded->get_data()['errors'][0]['code'] ?? '' ) );
        $this->assertSame( 'layout', (string) ( ( (array) $folded->get_data()['errors'][0]['details'] )['parameter'] ?? '' ) );
    }

    public function test_the_letterhead_is_a_block_and_not_page_chrome(): void {
        $page = PlayerReportLayout::pageHeight( false );

        // Without a letterhead block nothing is reserved for one.
        $plain = ReportPageFit::estimate( [ [ 'id' => 'a', 'width' => 'full', 'rows' => 0 ] ] );
        $this->assertSame( (int) round( PlayerReportLayout::sectionHeight( 0 ) / $page * 100 ), $plain['fill'][0] );

        // A letterhead block takes the letterhead's height where it is placed, once.
        $headed = ReportPageFit::estimate( [
            [ 'id' => 'lh', 'width' => 'full', 'rows' => 0, 'letterhead' => true ],
            [ 'id' => 'a', 'width' => 'full', 'rows' => 0 ],
        ] );
        $this->assertSame(
            (int) round( ( PlayerReportLayout::letterheadHeight() + PlayerReportLayout::sectionHeight( 0 ) ) / $page * 100 ),
            $headed['fill'][0]
        );
    }

    public function test_a_page_break_moves_the_next_block_to_a_new_page(): void {
        $fit = ReportPageFit::estimate( [
            [ 'id' => 'a', 'width' => 'full', 'rows' => 2 ],
            [ 'id' => 'br', 'width' => 'full', 'rows' => 0, 'break' => true ],
            [ 'id' => 'b', 'width' => 'full', 'rows' => 2 ],
        ] );
        $this->assertSame( 2, $fit['pages'] );
        $this->assertSame( [ 'b' ], $fit['breaks'] );

        $without = ReportPageFit::estimate( [
            [ 'id' => 'a', 'width' => 'full', 'rows' => 2 ],
            [ 'id' => 'b', 'width' => 'full', 'rows' => 2 ],
        ] );
        $this->assertSame( 1, $without['pages'] );

        // A break between two halves keeps them apart.
        $halves = ReportPageFit::estimate( [
            [ 'id' => 'a', 'width' => 'half', 'rows' => 2 ],
            [ 'id' => 'br', 'width' => 'half', 'rows' => 0, 'break' => true ],
            [ 'id' => 'b', 'width' => 'half', 'rows' => 2 ],
        ] );
        $this->assertSame( [ 'b' ], $halves['breaks'] );
    }

    /**
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    private function preview( string $key, array $context, array $options = [] ): array {
        $out = BlockEngine::preview( $key, $context, $options, $this->hod );
        $this->assertIsArray( $out, $key . ( $out instanceof StudioError ? ': ' . $out->message : '' ) );
        return $out;
    }

    /** @return array<string,mixed> */
    private function team( int $team_id ): array {
        return [ 'scope' => 'team', 'team_id' => $team_id, 'from' => self::FROM, 'to' => self::TO ];
    }

    /** @param array<string,mixed> $context */
    private function statementsFor( string $key, array $context ): int {
        global $wpdb;
        BlockEngine::preview( $key, $context, [], $this->hod );
        $this->coldCaches();
        $start = (int) $wpdb->num_queries;
        $out   = BlockEngine::preview( $key, $context, [], $this->hod );
        $this->assertIsArray( $out, $key );
        return (int) $wpdb->num_queries - $start;
    }

    private function reportStatements(): int {
        global $wpdb;
        $this->coldCaches();
        $start = (int) $wpdb->num_queries;
        ( new TeamMonthlyReport() )->forTeam( $this->team_a, self::FROM, self::TO, [ 'kpi' ], $this->hod );
        return (int) $wpdb->num_queries - $start;
    }

    private function coldCaches(): void {
        SharedReads::flush();
        DimensionValueResolver::flush();
        wp_cache_flush();
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id, string $first ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => $this->club, 'team_id' => $team_id, 'first_name' => $first, 'last_name' => 'Layout', 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertActivity( int $team_id, string $date ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", [
            'club_id' => $this->club, 'team_id' => $team_id, 'title' => 'Training ' . $date, 'session_date' => $date,
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertAttendance( int $activity_id, int $player_id, string $status ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
            'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id,
            'status' => $status, 'is_guest' => 0, 'record_type' => 'actual',
        ] );
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) {
            add_role( $role, $role_type, [ 'read' => true ] );
        }
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Layout',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }
}
