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
use TT\Modules\Analytics\Reports\DescribesOptions;
use TT\Modules\Analytics\Reports\LevelBlockOptions;
use TT\Modules\Analytics\Reports\MatchesBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlock;
use TT\Modules\Analytics\Reports\TeamMonthlyReportBlockOptions;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportLayout;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Blocks\KeyFiguresBlock;
use TT\Modules\ReportStudio\Blocks\LetterheadBlock;
use TT\Modules\ReportStudio\Blocks\MonthlySectionBlock;
use TT\Modules\ReportStudio\Document\StudioDocument;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockOption;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportPageFit;
use TT\Modules\ReportStudio\Domain\ReportTemplateRegistry;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;
use TT\Modules\ReportStudio\Templates\TeamMonthlyReportTemplate;
use TT\Modules\ReportStudio\Wizard\WhatStep;

/**
 * #5023 — the team monthly report as a Studio template, and starting a
 * report from a template.
 *
 * - the template holds every section of the report, in print order;
 * - each block's payload equals the monthly report's for the same team,
 *   month and reader, and each section prints with the report's renderer;
 * - the template's PDF prints the markup its screen shows, and its page count
 *   stays within the monthly report's estimate;
 * - the attendance, minutes and matches sections offer the options their
 *   option classes own;
 * - starting from a template creates an ordinary report with a copy of the
 *   template's blocks, which can change without changing the template;
 * - a coach starts the monthly template for their own team only, and the
 *   choices a template fixes cannot be changed;
 * - the wizard's first step offers the templates the builder may start.
 */
final class ReportStudioMonthlyTemplateTest extends WP_UnitTestCase {

    // Publishing commits, so every test cleans up what it wrote.
    use CommitsAfterCascade;

    private const FROM   = '2020-03-01';
    private const TO     = '2020-03-31';
    private const PERIOD = '2020-03-01/2020-03-31';

    /** Players in the fixture squad. */
    private const SQUAD = 24;

    private int $club = 1;
    private int $team = 0;
    private int $other_team = 0;
    private int $hod = 0;
    private int $coach = 0;

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        $this->club = (int) CurrentClub::id();

        $this->team       = $this->insertTeam( 'Monthly U14' );
        $this->other_team = $this->insertTeam( 'Monthly U16' );
        $this->hod        = $this->makePersona( 'tt_head_dev', 'head_of_development', $this->team );
        $this->coach      = $this->coachOf( $this->team );
        $this->seed();
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
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    public function test_the_template_holds_every_section_in_print_order(): void {
        $template = ReportTemplateRegistry::find( TeamMonthlyReportTemplate::KEY );
        $this->assertNotNull( $template );
        $this->assertSame( [ 'kind' => 'report', 'scope' => 'team', 'audience' => 'internal', 'layout' => 'rows' ], $template->choices );

        $keys = array_column( $template->definition['blocks'], 'key' );
        $want = array_map( [ TeamMonthlyReportTemplate::class, 'keyFor' ], TeamMonthlyReportBlock::ALL );
        $this->assertSame( $want, $keys, 'every section, in print order' );
        foreach ( $keys as $key ) $this->assertNotNull( BlockRegistry::find( $key ), $key );
        $this->assertSame( array_keys( MonthlySectionBlock::KEYS ), array_values( array_diff( TeamMonthlyReportBlock::ALL, [ TeamMonthlyReportBlock::LETTERHEAD, TeamMonthlyReportBlock::KPI ] ) ) );
    }

    public function test_each_block_composes_and_prints_the_monthly_reports_section(): void {
        $template = ReportTemplateRegistry::find( TeamMonthlyReportTemplate::KEY );
        $this->assertNotNull( $template );
        $report   = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->hod );
        $doc      = $this->document( $template->definition['blocks'] );

        foreach ( $doc['blocks'] as $block ) {
            $section = $this->sectionOf( $block['key'] );
            $theirs  = $report['data'][ $section ] ?? null;
            $this->assertIsArray( $theirs, $section );
            if ( $section === TeamMonthlyReportBlock::LETTERHEAD ) {
                foreach ( $theirs as $k => $v ) {
                    // Read from the clock, so a second apart.
                    if ( $k !== 'generated_at' ) $this->assertSame( $v, $block['data'][ $k ] ?? null, "letterhead.{$k}" );
                }
                continue;
            }
            if ( $section === TeamMonthlyReportBlock::KPI ) {
                // The section rebuilt from the key figures is the composer's
                // payload, whole.
                $kpi = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ TeamMonthlyReportBlock::KPI ], $this->hod );
                $this->assertSame( wp_json_encode( $kpi['data'][ TeamMonthlyReportBlock::KPI ] ), wp_json_encode( KeyFiguresBlock::section( $block['data'] ) ), 'kpi' );
                continue;
            }
            $this->assertSame( wp_json_encode( $theirs ), wp_json_encode( $block['data'] ), "{$section} composes the report's payload" );
        }

        $body = StudioDocument::body( $doc, $this->hod );
        foreach ( $report['blocks'] as $section ) {
            if ( $section === TeamMonthlyReportBlock::LETTERHEAD ) continue;
            $this->assertStringContainsString( TeamMonthlyReportDocument::sectionHtml( $section, $report['data'][ $section ] ), $body, "{$section} prints as the report prints it" );
        }
    }

    public function test_the_pdf_prints_the_screen_and_fits_the_monthly_estimate(): void {
        $template = ReportTemplateRegistry::find( TeamMonthlyReportTemplate::KEY );
        $this->assertNotNull( $template );
        $doc   = $this->document( $template->definition['blocks'] );
        $body  = StudioDocument::body( $doc, $this->hod );
        $paper = StudioDocument::html( $doc, $this->hod, 'Monthly' );

        $this->assertSame( 1, preg_match( '#<body>(.*)</body>#s', $paper, $m ) );
        $this->assertSame( $body, $m[1], 'the PDF\'s body is the screen\'s document, byte for byte' );

        $studio  = ReportPageFit::estimate( array_map( [ ReportPageFit::class, 'entryOf' ], $doc['blocks'] ) );
        $monthly = TeamMonthlyReportLayout::fit( ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->hod ), TeamMonthlyReportLayout::PACK );
        fwrite( STDERR, sprintf( "\n[#5023] pages: template %d, monthly report %d\n", $studio['pages'], $monthly['pages'] ) );
        $this->assertGreaterThanOrEqual( 2, $monthly['pages'], 'Precondition: the fixture fills more than one page of the monthly report.' );
        $this->assertGreaterThanOrEqual( 2, $studio['pages'], 'and more than one page of the template' );
        $this->assertLessThanOrEqual( $monthly['pages'], $studio['pages'], 'within the monthly report\'s estimate' );
    }

    public function test_the_template_reads_the_squad_once_not_once_per_block(): void {
        $template = ReportTemplateRegistry::find( TeamMonthlyReportTemplate::KEY );
        $this->assertNotNull( $template );
        wp_set_current_user( $this->hod );

        $monthly = $this->statements( fn() => ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [], $this->hod ) );
        $studio  = $this->statements( function () use ( $template ): void {
            foreach ( $template->definition['blocks'] as $block ) {
                $this->assertIsArray( BlockEngine::preview( $block['key'], $this->teamContext(), [], $this->hod ), $block['key'] );
            }
        } );
        $blocks = count( $template->definition['blocks'] );
        fwrite( STDERR, sprintf( "\n[#5023] statements: template %d (%d blocks), monthly report %d\n", $studio, $blocks, $monthly ) );

        // The squad reads are the monthly report's, once; each block adds only
        // its own gate (capability, subject, data), a few statements at most.
        $this->assertGreaterThan( 0, $monthly );
        $this->assertLessThanOrEqual( $monthly + 4 * $blocks, $studio );
    }

    public function test_attendance_minutes_and_matches_offer_their_option_classes(): void {
        foreach ( [ TeamMonthlyReportBlock::ATTENDANCE => LevelBlockOptions::class, TeamMonthlyReportBlock::MINUTES => LevelBlockOptions::class, TeamMonthlyReportBlock::MATCHES => MatchesBlockOptions::class ] as $section => $owner ) {
            $this->assertSame( $owner, TeamMonthlyReportBlockOptions::ownerOf( $section ) );
            $this->assertTrue( is_a( $owner, DescribesOptions::class, true ) );
            $block = BlockRegistry::find( (string) MonthlySectionBlock::keyFor( $section ) );
            $this->assertNotNull( $block );
            $keys = array_map( static fn( BlockOption $o ): string => $o->key, $block->options( ReportContext::forListing( 'team', 'internal', $this->hod ) ) );
            $this->assertSame( array_column( $owner::fields(), 'key' ), $keys, $section );
            foreach ( $keys as $key ) $this->assertSame( [], $owner::unknownKeys( [ $key => '1' ] ), "{$section}.{$key}" );
        }

        $options = [ 'level' => 'summary' ];
        $read    = BlockEngine::preview( 'monthly_attendance', $this->teamContext(), $options, $this->hod );
        $this->assertIsArray( $read, $read instanceof StudioError ? $read->message : '' );
        $report  = ( new TeamMonthlyReport() )->forTeam( $this->team, self::FROM, self::TO, [ TeamMonthlyReportBlock::ATTENDANCE ], $this->hod, [ TeamMonthlyReportBlock::ATTENDANCE => $options ] );
        $this->assertSame( wp_json_encode( $report['data'][ TeamMonthlyReportBlock::ATTENDANCE ] ), wp_json_encode( $read['data'] ) );
    }

    public function test_starting_from_the_template_makes_an_ordinary_report_with_a_copy_of_its_blocks(): void {
        wp_set_current_user( $this->hod );
        $id = $this->create( [ 'template' => TeamMonthlyReportTemplate::KEY, 'team_id' => $this->team ] );

        $repo   = new ReportsRepository();
        $report = $repo->find( $id );
        $this->assertNotNull( $report );
        $this->assertSame( 'team', $report['scope'] );
        $this->assertSame( $this->hod, (int) $report['owner_user_id'], 'owned by the builder' );
        $template = TeamMonthlyReportTemplate::template();
        $draft    = $repo->draft( $id, $this->hod );
        $this->assertNotNull( $draft );
        $this->assertSame( array_column( $template->definition['blocks'], 'key' ), array_column( $draft['definition']['blocks'], 'key' ) );

        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => array_slice( $template->definition['blocks'], 0, 2 ) ] ] )->get_status() );
        $this->assertCount( 2, (array) ( $repo->draft( $id, $this->hod )['definition']['blocks'] ?? [] ), 'the report changed' );
        $again = ReportTemplateRegistry::find( TeamMonthlyReportTemplate::KEY );
        $this->assertNotNull( $again );
        $this->assertSame( $template->definition, $again->definition, 'the template did not' );

        $second = $this->create( [ 'template' => TeamMonthlyReportTemplate::KEY, 'team_id' => $this->team ] );
        $this->assertCount( count( $template->definition['blocks'] ), (array) ( $repo->draft( $second, $this->hod )['definition']['blocks'] ?? [] ), 'a second report starts whole' );
    }

    public function test_a_coach_starts_the_monthly_template_for_their_own_team_only(): void {
        wp_set_current_user( $this->coach );
        $templates = array_column( (array) $this->send( 'GET', 'report-templates' )->get_data()['data']['templates'], 'key' );
        $this->assertContains( TeamMonthlyReportTemplate::KEY, $templates );

        $this->create( [ 'template' => TeamMonthlyReportTemplate::KEY, 'team_id' => $this->team ] );

        $res = $this->send( 'POST', 'reports', $this->choices( [ 'template' => TeamMonthlyReportTemplate::KEY, 'team_id' => $this->other_team ] ) );
        $this->assertSame( 403, $res->get_status(), 'another team' );
    }

    public function test_a_template_fixes_its_choices(): void {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', 'reports', $this->choices( [ 'template' => TeamMonthlyReportTemplate::KEY, 'team_id' => $this->team, 'layout' => 'grid' ] ) );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'layout', $this->field( $res ) );

        $res = $this->send( 'POST', 'reports', $this->choices( [ 'template' => 'no_such_template', 'team_id' => $this->team ] ) );
        $this->assertSame( 400, $res->get_status() );
        $this->assertSame( 'template', $this->field( $res ) );
    }

    /** Statements one call issues, read cold. */
    private function statements( callable $fn ): int {
        global $wpdb;
        $this->flush();
        \TT\Modules\Activities\Services\ActivityRegisterProgress::forget();
        $start = (int) $wpdb->num_queries;
        $fn();
        return (int) $wpdb->num_queries - $start;
    }

    /** The field a refusal names: `details` arrives as an object. */
    private function field( WP_REST_Response $res ): ?string {
        $details = (array) ( $res->get_data()['errors'][0]['details'] ?? [] );
        return is_string( $details['field'] ?? null ) ? $details['field'] : null;
    }

    public function test_the_wizard_offers_the_templates_the_builder_may_start(): void {
        wp_set_current_user( $this->coach );
        ob_start();
        ( new WhatStep() )->render( [] );
        $html = (string) ob_get_clean();
        $this->assertStringContainsString( 'name="start_template"', $html );
        $this->assertStringContainsString( 'value="' . TeamMonthlyReportTemplate::KEY . '"', $html );
        $this->assertStringNotContainsString( 'value="player_report_scout"', $html, 'not one the coach may not start' );
        $this->assertSame( 1, preg_match_all( '/<label class="tt-rsw-option" for="tt-rsw-template-none"/', $html ), 'an empty report is the first choice' );

        $merge = ( new WhatStep() )->validate( [ 'start_template' => TeamMonthlyReportTemplate::KEY, 'kind' => 'dashboard', 'scope' => 'me' ], [] );
        $this->assertIsArray( $merge );
        $this->assertSame( TeamMonthlyReportTemplate::KEY, $merge['template'] );
        $this->assertSame( 'report', $merge['kind'], 'the template answers what it is' );
        $this->assertSame( 'team', $merge['scope'], 'and who it is about' );
        $this->assertSame( 'rows', $merge['layout'] ?? null );
    }

    // ---- helpers ---------------------------------------------------------------------

    /**
     * The template's blocks read for the fixture team and month, as the
     * document route reads them.
     *
     * @param list<array{id:string, key:string, options:array<string,mixed>, width:string}> $blocks
     * @return array{context:array<string,mixed>, blocks:list<array<string,mixed>>, page_fit:array<string,mixed>}
     */
    private function document( array $blocks ): array {
        SharedReads::flush();
        $doc = [ 'context' => $this->teamContext() + [ 'audience' => 'internal' ], 'blocks' => [], 'page_fit' => [] ];
        foreach ( $blocks as $block ) {
            $read = BlockEngine::preview( $block['key'], $this->teamContext(), [], $this->hod );
            $this->assertIsArray( $read, $block['key'] . ( $read instanceof StudioError ? ': ' . $read->message : '' ) );
            $doc['blocks'][] = $block + [ 'data' => $read['data'] ];
        }
        return $doc;
    }

    private function sectionOf( string $key ): string {
        if ( $key === LetterheadBlock::KEY ) return TeamMonthlyReportBlock::LETTERHEAD;
        if ( $key === ( new KeyFiguresBlock() )->key() ) return TeamMonthlyReportBlock::KPI;
        $section = array_search( $key, MonthlySectionBlock::KEYS, true );
        $this->assertIsString( $section, $key );
        return $section;
    }

    /** @return array<string,mixed> */
    private function teamContext(): array {
        return [ 'scope' => 'team', 'team_id' => $this->team, 'from' => self::FROM, 'to' => self::TO ];
    }

    /**
     * @param array<string,mixed> $extra
     * @return array<string,mixed>
     */
    private function choices( array $extra ): array {
        return $extra + [ 'binding' => 'fixed', 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /** @param array<string,mixed> $extra */
    private function create( array $extra ): int {
        $res = $this->send( 'POST', 'reports', $this->choices( $extra ) );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
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
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name, 'age_group' => 'U14' ] );
        return (int) $wpdb->insert_id;
    }

    /** A persona from its WordPress role, with a team-scope grant on one team. */
    private function makePersona( string $role, string $role_type, int $team_id ): int {
        global $wpdb;
        if ( get_role( $role ) === null ) add_role( $role, $role_type, [ 'read' => true ] );
        $user_id = (int) self::factory()->user->create( [ 'role' => $role ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => ucfirst( $role_type ), 'last_name' => 'Monthly',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => (int) $wpdb->insert_id, 'role_id' => 1,
            'scope_type' => 'team', 'scope_id' => $team_id,
        ] );
        $this->flush();
        return $user_id;
    }

    private function coachOf( int $team ): int {
        global $wpdb;
        $user = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $this->club, 'first_name' => 'Head', 'last_name' => 'Monthly',
            'role_type' => 'head_coach', 'wp_user_id' => $user, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
            'team_id' => $team, 'person_id' => $person, 'role_in_team' => 'head_coach', 'is_head_coach' => 1,
        ] );
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => $this->club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
        return $user;
    }

    /** A squad over February and March 2020: trainings with registers and two matches with minutes. */
    private function seed(): void {
        global $wpdb;
        $p       = $wpdb->prefix;
        $players = [];
        // A full squad, so the monthly report's per-player sections run over
        // more than one page and the page-count check can fail.
        for ( $n = 1; $n <= self::SQUAD; $n++ ) {
            $wpdb->insert( "{$p}tt_players", [
                'club_id' => $this->club, 'team_id' => $this->team, 'first_name' => 'Player' . $n, 'last_name' => 'Monthly',
                'jersey_number' => $n, 'status' => 'active', 'date_of_birth' => '2006-04-12',
            ] );
            $players[] = (int) $wpdb->insert_id;
        }
        $statuses = [ 'Present', 'Absent', 'Late', 'Excused', 'Present' ];
        foreach ( [ '2020-02-11', '2020-03-03', '2020-03-10', '2020-03-17', '2020-03-24' ] as $i => $date ) {
            $id = $this->activity( $date, 'training' );
            foreach ( $players as $j => $pid ) $this->attend( $id, $pid, $statuses[ ( $i + $j ) % count( $statuses ) ] );
        }
        foreach ( [ [ '2020-03-07', 3, 0 ], [ '2020-03-14', 0, 2 ], [ '2020-03-21', 1, 1 ] ] as $k => [ $date, $ours, $theirs ] ) {
            $id = $this->activity( $date, 'game', [ 'opponent' => 'Rood', 'home_away' => 'home', 'home_score' => $ours, 'away_score' => $theirs ] );
            foreach ( $players as $j => $pid ) $this->attend( $id, $pid, 'Present', ( $j + $k ) % 4 === 0 ? 0 : 60 - 10 * ( ( $j + $k ) % 3 ) );
        }
    }

    /** @param array<string,mixed> $extra */
    private function activity( string $date, string $type, array $extra = [] ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_activities", $extra + [
            'club_id' => $this->club, 'team_id' => $this->team, 'title' => ucfirst( $type ) . ' ' . $date,
            'session_date' => $date, 'activity_type_key' => $type, 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function attend( int $activity_id, int $player_id, string $status, ?int $minutes = null ): void {
        global $wpdb;
        $row = [ 'club_id' => $this->club, 'activity_id' => $activity_id, 'player_id' => $player_id, 'status' => $status, 'record_type' => 'actual', 'is_guest' => 0 ];
        if ( $minutes !== null ) $row['minutes_played'] = $minutes;
        $wpdb->insert( "{$wpdb->prefix}tt_attendance", $row );
    }
}
