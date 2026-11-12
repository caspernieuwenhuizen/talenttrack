<?php
namespace TT\Tests\Php;

use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;
use WP_UnitTestCase;
use TT\Core\FeatureRegistry;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Infrastructure\Visibility\ReaderSet;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAudience;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportPausedTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Modules\Export\ExportGate;
use TT\Modules\License\FeatureMap;
use TT\Modules\License\LicenseGate;
use TT\Modules\License\LicenseMode;
use TT\Modules\ReportStudio\Domain\BlockEngine;
use TT\Modules\ReportStudio\Domain\BlockRegistry;
use TT\Modules\ReportStudio\Domain\ComposesPerReader;
use TT\Modules\ReportStudio\Domain\ReportBlock;
use TT\Modules\ReportStudio\Domain\ReportContext;
use TT\Modules\ReportStudio\Domain\ReportData;
use TT\Modules\ReportStudio\Domain\ReportSchedules;
use TT\Modules\ReportStudio\Domain\SharedReads;
use TT\Modules\ReportStudio\Domain\StudioError;
use TT\Modules\ReportStudio\Repositories\ReportsRepository;

/**
 * #4963 — a published Report Studio report on a schedule.
 *
 * - One PDF for the owner and every recipient: a block one of them may not
 *   read is left out for all, a group is narrowed to the players every one
 *   of them may see, a row about a player one of them may not see is left
 *   out, and a block whose figures depend on the reader is left out when
 *   they differ. A recipient whose rights cannot be checked has none.
 * - A run sends the published version, never a later draft. An archived
 *   report pauses its schedule and tells the owner once.
 * - The REST routes: accounts and roles only, the publishing right for
 *   anyone but yourself, the start rules' refusals, a PATCH that changes only
 *   what it is sent and is audited with before and after, and a refusal per
 *   route.
 */
final class ReportStudioScheduleTest extends WP_UnitTestCase {

    use CommitsAfterCascade;
    use QuietHoursClosed;

    private const PERIOD = '2026-08-01/2026-08-31';

    private int $team_a = 0;
    private int $team_b = 0;
    private int $child = 0;
    private int $other_child = 0;
    private int $hod = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;

    /** @var array<string,ReportBlock> */
    private array $real_blocks = [];

    /** @var list<string> addresses a PDF went to */
    private array $sent = [];

    /** @var list<string> addresses a message without a file went to */
    private array $told = [];

    /** @var list<string> */
    private array $pdf_html = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        global $wpdb, $wp_rest_server;
        $wpdb->hide_errors();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        ( require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php' )->up();

        $club = (int) CurrentClub::id();
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Schedule U13' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $club, 'name' => 'Schedule U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $this->child       = $this->player( $this->team_a, 'Child' );
        $this->other_child = $this->player( $this->team_b, 'Other' );

        $this->hod     = $this->persona( 'tt_head_dev', 'head_of_development', 0, 'hod-4963@example.test' );
        $this->coach_a = $this->persona( 'tt_coach', 'head_coach', $this->team_a, 'coach-a-4963@example.test' );
        $this->coach_b = $this->persona( 'tt_coach', 'head_coach', $this->team_b, 'coach-b-4963@example.test' );

        $this->real_blocks = BlockRegistry::all();

        FeatureRegistry::setEnabled( 'report_studio', true );
        LicenseMode::overrideForTests( true );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_PRO );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        TemplateRegistry::register( new ScheduledReportPausedTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        $this->closeQuietHours();
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
        add_filter( 'tt_pdf_render_html', [ $this, 'capturePdf' ] );

        $wp_rest_server = new WP_REST_Server();
        do_action( 'rest_api_init' );
        $this->flush();
    }

    public function tear_down(): void {
        global $wp_rest_server;
        $wp_rest_server = null;
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        remove_filter( 'tt_pdf_render_html', [ $this, 'capturePdf' ] );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        BlockRegistry::clear();
        foreach ( $this->real_blocks as $block ) BlockRegistry::register( $block );
        LicenseMode::overrideForTests( null );
        LicenseGate::overrideTierForTests( null );
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        $this->flush();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $payload
     */
    public function captureSend( $accepted, array $payload ): bool {
        if ( (array) ( $payload['attachments'] ?? [] ) !== [] ) {
            $this->sent[] = strtolower( (string) $payload['to'] );
        } else {
            $this->told[] = strtolower( (string) $payload['to'] );
        }
        return true;
    }

    public function capturePdf( string $html ): string {
        $this->pdf_html[] = $html;
        return $html;
    }

    // ---- lowest common rights -------------------------------------------------------

    public function test_a_block_one_reader_may_not_read_is_left_out_for_everyone(): void {
        BlockRegistry::register( $this->block( 'test_admin_only', 'manage_options' ) );
        $admin  = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $admin2 = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        $context = $this->teamContext( $this->team_a );

        $left = BlockEngine::forReaders( 'test_admin_only', $context, [], ReaderSet::of( [ $admin, $this->hod ] ) );
        $this->assertInstanceOf( StudioError::class, $left );
        $this->assertSame( 'not_for_every_reader', is_array( $left ) ? '' : $left->code, 'one reader without the right takes it out' );

        $kept = BlockEngine::forReaders( 'test_admin_only', $context, [], ReaderSet::of( [ $admin, $admin2 ] ) );
        $this->assertIsArray( $kept, 'and it is there when every reader holds it' );

        $nobody = BlockEngine::forReaders( 'test_admin_only', $context, [], ReaderSet::of( [ $admin, 0 ] ) );
        $this->assertInstanceOf( StudioError::class, $nobody, 'a reader whose rights cannot be checked has none' );
    }

    public function test_a_row_about_a_player_one_reader_may_not_see_is_left_out(): void {
        BlockRegistry::register( $this->block( 'test_rows', 'tt_view_reports', [ 'breakdown' => 'player_id', 'rows' => [
            [ 'player_id' => $this->child, 'name' => 'Child', 'value' => 1 ],
            [ 'player_id' => $this->other_child, 'name' => 'Other', 'value' => 2 ],
        ] ] ) );
        $context = $this->teamContext( $this->team_a );

        $hod  = BlockEngine::forReaders( 'test_rows', $context, [], ReaderSet::of( [ $this->hod ] ) );
        $both = BlockEngine::forReaders( 'test_rows', $context, [], ReaderSet::of( [ $this->hod, $this->coach_a ] ) );
        $this->assertIsArray( $hod );
        $this->assertIsArray( $both );
        $this->assertSame( [ $this->child, $this->other_child ], array_column( $hod['data']['rows'], 'player_id' ) );
        $this->assertSame( [ $this->child ], array_column( $both['data']['rows'], 'player_id' ), 'only the rows the coach may see too' );
    }

    public function test_a_block_whose_figures_differ_per_reader_is_left_out(): void {
        BlockRegistry::register( $this->perReaderBlock() );
        $context = $this->teamContext( $this->team_a );

        $differ = BlockEngine::forReaders( 'test_per_reader', $context, [], ReaderSet::of( [ $this->hod, $this->coach_a ] ) );
        $this->assertInstanceOf( StudioError::class, $differ );
        $this->assertSame( 'differs_per_reader', is_array( $differ ) ? '' : $differ->code );

        $same = BlockEngine::forReaders( 'test_per_reader', $context, [], ReaderSet::of( [ $this->hod ] ) );
        $this->assertIsArray( $same, 'one reader: nothing to differ' );
    }

    public function test_a_group_is_narrowed_to_the_players_every_reader_sees_and_left_out_blocks_are_named(): void {
        BlockRegistry::register( $this->block( 'test_admin_only', 'manage_options' ) );
        $id = $this->published( [ 'kind' => 'report', 'scope' => 'group', 'binding' => 'on_open', 'cohort' => [ 'team_ids' => [ $this->team_a, $this->team_b ] ], 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [
            [ 'id' => 'b1', 'key' => 'attendance_per_week' ],
            [ 'id' => 'b2', 'key' => 'test_admin_only' ],
        ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $hod = ReportData::composeForReaders( $report, [ 'from' => '2026-08-01', 'to' => '2026-08-31' ], ReaderSet::of( [ $this->hod ] ) );
        $this->assertIsArray( $hod, $hod instanceof \TT\Modules\ReportStudio\Domain\StudioError ? $hod->message : '' );
        $this->assertEqualsCanonicalizing( [ $this->child, $this->other_child ], $hod['context']['player_ids'] );

        $both = ReportData::composeForReaders( $report, [ 'from' => '2026-08-01', 'to' => '2026-08-31' ], ReaderSet::of( [ $this->hod, $this->coach_a ] ) );
        $this->assertIsArray( $both );
        $this->assertSame( [ $this->child ], $both['context']['player_ids'], 'the players every reader may see' );
        $this->assertSame( [ 'b1' ], array_column( $both['blocks'], 'id' ) );
        $this->assertSame( [ 'b2' ], array_column( $both['left_out'], 'id' ), 'the block left out is named, with the reason' );
        $this->assertNotSame( '', (string) $both['left_out'][0]['reason'] );
    }

    // ---- the run ------------------------------------------------------------------

    public function test_a_run_sends_the_published_version_not_a_later_draft(): void {
        $this->needsPdf();
        $this->assertTrue( ExportGate::mayExport( $this->hod ), 'Precondition: the head of development may receive a file.' );
        $id = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Published heading 4963' ] ] ] );
        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Draft heading 4963' ] ] ] ] ] )->get_status() );

        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod ] ] );
        $this->runOnce( $schedule );

        $this->assertSame( [ 'hod-4963@example.test' ], $this->sent );
        $this->assertCount( 1, $this->pdf_html );
        $this->assertStringContainsString( 'Published heading 4963', $this->pdf_html[0] );
        $this->assertStringNotContainsString( 'Draft heading 4963', $this->pdf_html[0], 'the draft is not sent' );
    }

    public function test_an_archived_report_pauses_its_schedule_and_tells_the_owner_once(): void {
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Going' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod ] ] );
        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'DELETE', "reports/{$id}" )->get_status() );

        $this->runOnce( $schedule );
        $this->runOnce( $schedule );

        $row = ( new ScheduledReportsRepository() )->findById( $schedule );
        $this->assertNotNull( $row );
        $this->assertSame( ScheduledReportsRepository::STATUS_PAUSED, $row['status'] );
        $this->assertStringContainsString( 'archived', (string) $row['last_error'] );
        $this->assertSame( [], $this->sent, 'nothing is sent' );
        $this->assertSame( [ 'hod-4963@example.test' ], $this->told, 'the owner is told once' );
    }

    public function test_a_recipient_who_loses_read_access_drops_out(): void {
        // A report about a fixed team is published to no role: the team's
        // coach receives it because they read what it is about.
        $id     = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );
        $entries = [ 'user:' . $this->coach_a, 'user:' . $this->coach_b ];

        $this->assertSame( [ $this->hod, $this->coach_a ], ReportSchedules::readers( $entries, $this->hod, $report ), 'the coach of another team receives nothing and narrows nothing' );

        global $wpdb;
        $person = (int) QueryHelpers::person_id_for_user( $this->coach_a );
        $wpdb->delete( "{$wpdb->prefix}tt_team_people", [ 'person_id' => $person ] );
        $wpdb->delete( "{$wpdb->prefix}tt_user_role_scopes", [ 'person_id' => $person ] );
        $this->flush();
        $this->assertSame( [ $this->hod ], array_values( array_diff( ReportSchedules::readers( $entries, $this->hod, $report ), [ 0 ] ) ), 'at the next run they are out' );
    }

    // ---- the routes ---------------------------------------------------------------

    public function test_recipients_are_accounts_and_roles_only(): void {
        $id = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'X' ] ] ] );
        wp_set_current_user( $this->hod );

        $email = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'someone@example.test' ] ] );
        $this->assertSame( 400, $email->get_status() );
        $this->assertSame( 'invalid_field', $this->code( $email ) );
        $this->assertSame( 'recipients', (string) ( (array) ( $email->get_data()['errors'][0]['details'] ?? [] ) )['field'] );

        $family = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'tt_parent' ] ] );
        $this->assertSame( 400, $family->get_status(), 'a family role is not a schedule recipient' );

        $role = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'tt_coach', 'user:' . $this->coach_a ] ] );
        $this->assertSame( 201, $role->get_status(), (string) wp_json_encode( $role->get_data() ) );
        $this->assertSame( [ 'tt_coach', 'user:' . $this->coach_a ], $role->get_data()['data']['recipients'] );
        $this->assertArrayHasKey( 'preview', $role->get_data()['data'] );
    }

    public function test_a_coach_schedules_for_themselves_and_needs_the_publishing_right_for_others(): void {
        wp_set_current_user( $this->coach_a );
        $id = $this->publishAs( $this->coach_a, $this->teamChoices() + [ 'sharing' => 'me' ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Mine' ] ] ] );

        $others = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->coach_a, 'user:' . $this->hod ] ] );
        $this->assertSame( 403, $others->get_status() );
        $this->assertSame( 'forbidden', $this->code( $others ) );

        $self = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->coach_a ] ] );
        $this->assertSame( 201, $self->get_status(), (string) wp_json_encode( $self->get_data() ) );

        wp_set_current_user( $this->coach_b );
        $this->assertSame( 404, $this->send( 'GET', 'scheduled-reports/' . (int) $self->get_data()['data']['id'] )->get_status(), 'a coach of another team does not open it: it answers as one that does not exist' );
        $this->assertSame( 403, $this->send( 'GET', "reports/{$id}/schedules" )->get_status() );
    }

    public function test_a_report_that_cannot_be_scheduled_is_refused_with_the_reason(): void {
        $grid = $this->published( [ 'kind' => 'report', 'scope' => 'team', 'binding' => 'fixed', 'team_id' => $this->team_a, 'layout' => 'grid', 'sharing' => 'me', 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'G' ] ] ] );
        $open = $this->published( [ 'kind' => 'report', 'scope' => 'team', 'binding' => 'on_open', 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'O' ] ] ], [ 'head_coach' ] );
        wp_set_current_user( $this->hod );

        foreach ( [ $grid => 'rows', $open => 'same player or team' ] as $id => $words ) {
            $res = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->hod ] ] );
            $this->assertSame( 400, $res->get_status() );
            $this->assertSame( 'not_schedulable', $this->code( $res ) );
            $this->assertStringContainsString( $words, (string) $res->get_data()['errors'][0]['message'] );
        }
        $this->assertSame( 'A scheduled mail is laid out in rows.', ReportSchedules::refusal( (array) ( new ReportsRepository() )->find( $grid ) ), 'the start rules\' own words' );
    }

    public function test_a_patch_changes_only_the_fields_sent_and_is_audited(): void {
        global $wpdb;
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'P' ] ] ] );
        $schedule = $this->schedule( $id, [ 'name' => 'Monthly pack', 'recipients' => [ 'user:' . $this->hod ], 'frequency' => 'monthly_first' ] );
        wp_set_current_user( $this->hod );

        $res = $this->send( 'PATCH', "scheduled-reports/{$schedule}", [ 'frequency' => 'weekly_monday' ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $row = ( new ScheduledReportsRepository() )->findById( $schedule );
        $this->assertNotNull( $row );
        $this->assertSame( 'weekly_monday', $row['frequency'] );
        $this->assertSame( 'Monthly pack', $row['name'], 'an omitted field keeps its value' );
        $this->assertSame( [ 'user:' . $this->hod ], $row['recipients'] );

        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'scheduled_report.updated' AND entity_id = %d ORDER BY id DESC LIMIT 1",
            $schedule
        ) );
        $audit = json_decode( $payload, true );
        $this->assertIsArray( $audit, 'the edit is in the audit log' );
        $this->assertSame( [ 'frequency' => 'monthly_first' ], $audit['before'] );
        $this->assertSame( [ 'frequency' => 'weekly_monday' ], $audit['after'] );

        $bad = $this->send( 'PATCH', "scheduled-reports/{$schedule}", [ 'frequency' => 'hourly' ] );
        $this->assertSame( 400, $bad->get_status() );
        $this->assertSame( 400, $this->send( 'PATCH', "scheduled-reports/{$schedule}", [ 'when' => 'now' ] )->get_status(), 'an unknown field' );
    }

    public function test_every_route_refuses_what_it_should(): void {
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'R' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod ] ] );

        wp_set_current_user( $this->coach_b );
        foreach ( [ [ 'GET', "reports/{$id}/schedules" ], [ 'POST', "reports/{$id}/schedules" ], [ 'GET', "reports/{$id}/schedules/preview" ],
            [ 'GET', "scheduled-reports/{$schedule}" ], [ 'PATCH', "scheduled-reports/{$schedule}" ], [ 'DELETE', "scheduled-reports/{$schedule}" ] ] as [ $method, $route ] ) {
            $res = $this->send( $method, $route, $method === 'POST' ? [ 'recipients' => [ 'user:' . $this->coach_b ] ] : ( $method === 'PATCH' ? [ 'name' => 'Mine now' ] : [] ) );
            // A report answers 403 as the Studio's report routes do; a
            // schedule id answers as one that does not exist.
            $expected = strpos( $route, 'scheduled-reports/' ) === 0 ? 404 : 403;
            $this->assertSame( $expected, $res->get_status(), "{$method} {$route} by a coach of another team" );
        }
        $row = ( new ScheduledReportsRepository() )->findById( $schedule );
        $this->assertSame( ScheduledReportsRepository::STATUS_ACTIVE, $row['status'] ?? '', 'the refused DELETE changed nothing' );
        $this->assertNotSame( 'Mine now', $row['name'] ?? '', 'and so did the refused PATCH' );

        wp_set_current_user( $this->hod );
        $this->assertSame( 404, $this->send( 'GET', 'scheduled-reports/999999' )->get_status() );
        $this->assertSame( 404, $this->send( 'GET', 'reports/999999/schedules' )->get_status() );

        $preview = $this->send( 'GET', "reports/{$id}/schedules/preview", [ 'recipients' => [ 'user:' . $this->hod ] ] );
        $this->assertSame( 200, $preview->get_status(), (string) wp_json_encode( $preview->get_data() ) );
        $this->assertSame( [ 'b1' ], array_column( $preview->get_data()['data']['blocks'], 'id' ) );

        $this->assertSame( 200, $this->send( 'DELETE', "scheduled-reports/{$schedule}" )->get_status() );
        $this->assertSame( 404, $this->send( 'GET', "scheduled-reports/{$schedule}" )->get_status(), 'an archived schedule is gone' );

        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $this->assertSame( 402, $this->send( 'GET', "reports/{$id}/schedules" )->get_status(), 'without the plan feature' );
    }

    public function test_the_form_has_save_and_cancel_back_to_the_report_and_shows_what_they_get(): void {
        $id     = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Form' ] ] ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $html = $this->renderScreen( $this->hod, $report, 'new' );
        $this->assertStringContainsString( 'tt-form-actions', $html, 'Save and Cancel side by side' );
        $this->assertMatchesRegularExpression( '/href="[^"]*tt_view=report-studio[^"]*id=' . $id . '[^"]*"[^>]*>Cancel/', $html, 'Cancel goes back to the report' );
        $this->assertStringContainsString( 'data-tt-rsch-preview', $html );
        $heading = BlockRegistry::find( 'heading' );
        $this->assertNotNull( $heading );
        $this->assertStringContainsString( '<li>' . esc_html( $heading->label() ) . '</li>', $html, 'the blocks they get, before Save' );
        $this->assertStringContainsString( 'name="recipients[]" value="tt_coach"', $html, 'a publisher picks roles' );
        $this->assertStringNotContainsString( 'value="tt_parent"', $html, 'never a family role' );

        $coach_report = $this->publishAs( $this->coach_a, $this->teamChoices() + [ 'sharing' => 'me' ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Mine' ] ] ] );
        $coach_html   = $this->renderScreen( $this->coach_a, (array) ( new ReportsRepository() )->find( $coach_report ), 'new' );
        $this->assertStringContainsString( 'value="user:' . $this->coach_a . '"', $coach_html, 'a coach schedules for themselves' );
        $this->assertStringNotContainsString( 'value="tt_coach"', $coach_html, 'and is offered no role' );
    }

    // ---- the review of #5102 ------------------------------------------------------------

    public function test_a_report_kept_for_its_owner_goes_to_nobody_else(): void {
        $id = $this->published( $this->teamChoices() + [ 'sharing' => 'me' ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Mine' ] ] ] );
        wp_set_current_user( $this->hod );

        $others = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->hod, 'tt_coach' ] ] );
        $this->assertSame( 400, $others->get_status() );
        $this->assertSame( 'kept_for_owner', (string) ( (array) ( $others->get_data()['errors'][0]['details'] ?? [] ) )['reason'] );
        $this->assertSame( 201, $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->hod ] ] )->get_status(), 'its owner may' );

        $html = $this->renderScreen( $this->hod, (array) ( new ReportsRepository() )->find( $id ), 'new' );
        $this->assertStringNotContainsString( 'value="tt_coach"', $html, 'the form offers its owner only, even to a publisher' );
    }

    public function test_a_caller_who_may_only_schedule_for_themselves_learns_nothing_about_account_ids(): void {
        $id = $this->publishAs( $this->coach_a, $this->teamChoices() + [ 'sharing' => 'me' ], [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Mine' ] ] ] );
        $real = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:' . $this->hod ] ] );
        $fake = $this->send( 'POST', "reports/{$id}/schedules", [ 'recipients' => [ 'user:999999' ] ] );
        $this->assertSame( 403, $real->get_status() );
        $this->assertSame( 403, $fake->get_status(), 'a real account and a made-up one answer alike' );
    }

    public function test_creating_a_schedule_needs_the_plan_on_every_path(): void {
        $id     = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Plan' ] ] ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );
        LicenseGate::overrideTierForTests( FeatureMap::TIER_STANDARD );
        $this->assertFalse( ReportSchedules::planAllows() );
        $refused = ReportSchedules::create( $report, [ 'recipients' => [ 'user:' . $this->hod ] ], \TT\Modules\ReportStudio\Domain\ReportReader::for( $this->hod ) );
        $this->assertInstanceOf( StudioError::class, $refused, 'the domain refuses it, so the form save does too' );
        $this->assertSame( 402, $refused->status );
    }

    public function test_a_departed_colleague_does_not_empty_the_pdf_for_the_others(): void {
        $id     = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );
        $gone = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] ); // no person record here: not in the club

        $readers = ReportSchedules::readers( [ 'user:' . $this->hod, 'user:' . $gone ], $this->hod, $report );
        $this->assertSame( [ $this->hod ], $readers, 'no reader without rights is added for a named account that gets no mail' );

        $resolved = ScheduledReportsRunner::resolveRecipients( [ 'user:' . $gone ] );
        $this->assertSame( ScheduledReportsRunner::ACCOUNT_UNREACHABLE, $resolved['skipped'][0]['reason'] ?? '' );
    }

    public function test_losing_the_right_to_publish_pauses_a_schedule_to_others(): void {
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $this->coach_a ] ] );

        $hod  = $this->hod;
        $deny = static function ( array $allcaps, array $caps, array $args, $user ) use ( $hod ): array {
            if ( $user instanceof \WP_User && (int) $user->ID === $hod ) unset( $allcaps['tt_publish_reports'] );
            return $allcaps;
        };
        add_filter( 'user_has_cap', $deny, PHP_INT_MAX, 4 );
        try {
            $this->assertFalse( user_can( $this->hod, 'tt_publish_reports' ), 'Precondition: the owner no longer publishes.' );
            $this->runOnce( $schedule );
        } finally {
            remove_filter( 'user_has_cap', $deny, PHP_INT_MAX );
        }

        $row = ( new ScheduledReportsRepository() )->findById( $schedule );
        $this->assertNotNull( $row );
        $this->assertSame( ScheduledReportsRepository::STATUS_PAUSED, $row['status'] );
        $this->assertStringContainsString( 'send reports to others', (string) $row['last_error'] );
        $this->assertSame( [], $this->sent );
    }

    public function test_a_preview_reads_no_injuries_and_a_send_records_one_read_per_recipient(): void {
        $this->needsPdf();
        global $wpdb;
        FeatureRegistry::setEnabled( 'journey_medical_visibility', true );
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id' => CurrentClub::id(), 'player_id' => $this->child, 'started_on' => '2026-08-04', 'actual_return' => '2026-08-11', 'notes' => 'Hamstring strain.',
        ] );
        $hod2 = $this->persona( 'tt_head_dev', 'head_of_development', 0, 'hod2-4963@example.test' );
        $this->assertTrue( ReaderSet::of( [ $this->hod, $hod2 ] )->seesMedical(), 'Precondition: both read injuries.' );

        $id       = $this->published( [ 'kind' => 'report', 'scope' => 'player', 'binding' => 'fixed', 'player_id' => $this->child, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ], [ [ 'id' => 'b1', 'key' => 'player_injuries' ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $hod2 ] ] );
        $before   = $this->injuryRows();

        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'GET', "scheduled-reports/{$schedule}" )->get_status() );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/schedules/preview", [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $hod2 ] ] )->get_status() );
        $this->assertSame( $before, $this->injuryRows(), 'a preview shows block names, not injuries: no read' );

        wp_set_current_user( 0 );
        $this->runOnce( $schedule );
        sort( $this->sent );
        $this->assertSame( [ 'hod-4963@example.test', 'hod2-4963@example.test' ], $this->sent, 'Precondition: both received it.' );
        $this->assertStringContainsString( 'Hamstring', $this->pdf_html[0] ?? '', 'Precondition: the PDF shows the injury.' );

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT user_id, payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' AND entity_type = 'player' AND entity_id = %d ORDER BY id DESC LIMIT 2",
            $this->child
        ), ARRAY_A );
        $this->assertSame( $before + 2, $this->injuryRows(), 'one read per recipient' );
        $this->assertEqualsCanonicalizing( [ $this->hod, $hod2 ], array_map( 'intval', array_column( (array) $rows, 'user_id' ) ), 'each written as its recipient' );
        foreach ( (array) $rows as $row ) {
            $payload = json_decode( (string) $row['payload'], true );
            $this->assertSame( 'scheduled_report', $payload['surface'] ?? '' );
            $this->assertSame( $schedule, (int) ( $payload['schedule_id'] ?? 0 ) );
        }
    }

    public function test_a_team_report_with_what_changed_records_its_reads_per_recipient_too(): void {
        global $wpdb;
        FeatureRegistry::setEnabled( 'journey_medical_visibility', true );
        $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
            'club_id' => CurrentClub::id(), 'uuid' => wp_generate_uuid4(), 'player_id' => $this->child, 'event_type' => 'injury_started',
            'event_date' => '2026-08-04 09:00:00', 'summary' => 'Injured: hamstring 4963.', 'visibility' => 'medical',
            'source_module' => 'tests', 'source_entity_type' => 'test_event', 'source_entity_id' => 4963,
        ] );
        $hod2 = $this->persona( 'tt_head_dev', 'head_of_development', 0, 'hod2-4963@example.test' );
        $this->assertTrue( ReaderSet::of( [ $this->hod, $hod2 ] )->seesMedical(), 'Precondition: both read medical entries.' );

        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'monthly_changes' ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $hod2 ] ] );
        $injuries  = $this->eventRows( 'player.injuries_viewed' );
        $sensitive = $this->eventRows( 'player.sensitive_timeline_viewed' );
        $all       = [ $this->eventRows( 'player.injuries_viewed', true ), $this->eventRows( 'player.sensitive_timeline_viewed', true ) ];

        wp_set_current_user( $this->hod );
        $this->assertSame( 200, $this->send( 'GET', "scheduled-reports/{$schedule}" )->get_status() );
        $this->assertSame( 200, $this->send( 'GET', "reports/{$id}/schedules/preview", [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $hod2 ] ] )->get_status() );
        $this->assertSame( $all, [ $this->eventRows( 'player.injuries_viewed', true ), $this->eventRows( 'player.sensitive_timeline_viewed', true ) ], 'the preview reads nothing, on any surface' );

        // The block as the run composes it holds the entry, so the reads it
        // records are of what was sent.
        $report = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );
        $doc = ReportSchedules::compose( $report, [ 'from' => '2026-08-01', 'to' => '2026-08-31' ], [ $this->hod, $hod2 ] );
        $this->assertIsArray( $doc );
        $reads = \TT\Modules\ReportStudio\Domain\StudioScheduleDelivery::sensitiveReads( $doc, $this->hod );
        $this->assertSame( [ $this->child => 1 ], $reads['player.injuries_viewed'] ?? [], 'the injury entry, per player' );
        $this->assertSame( [ $this->child => 1 ], $reads['player.sensitive_timeline_viewed'] ?? [], 'and as a medical entry' );

        wp_set_current_user( 0 );
        $this->runOnce( $schedule );
        if ( class_exists( \Dompdf\Dompdf::class ) ) {
            $this->assertCount( 2, $this->sent, 'Precondition: both received it.' );
            $this->assertSame( $injuries + 2, $this->eventRows( 'player.injuries_viewed' ), 'one injury read per recipient' );
            $this->assertSame( $sensitive + 2, $this->eventRows( 'player.sensitive_timeline_viewed' ), 'one sensitive read per recipient' );
        }
    }

    public function test_a_preview_for_40_readers_costs_a_bounded_number_of_statements(): void {
        for ( $i = 0; $i < 38; $i++ ) $this->persona( 'tt_coach', 'head_coach', $this->team_a, "coach{$i}-4963@example.test" );
        $three = $this->published( $this->teamChoices(), $this->headings( 3 ) );
        $six   = $this->published( $this->teamChoices(), $this->headings( 6 ) );
        $count = function ( int $report_id, array $recipients ): array {
            $report = ( new ReportsRepository() )->find( $report_id );
            $this->assertNotNull( $report );
            $this->flush();
            $n       = 0;
            $counter = static function ( $sql ) use ( &$n ) { $n++; return $sql; };
            add_filter( 'query', $counter );
            $preview = ReportSchedules::preview( $report, $recipients, $this->hod, [ 'report_id' => $report_id, 'period' => '' ] );
            remove_filter( 'query', $counter );
            return [ $n, $preview ];
        };

        [ $q3, $p3 ] = $count( $three, [ 'tt_coach' ] );
        [ $q6, $p6 ] = $count( $six, [ 'tt_coach' ] );
        $this->assertSame( 39, $p6['receivers'], 'Precondition: 39 coaches of the team receive it; 40 readers with the owner.' );
        $this->assertSame( '', $p6['message'], 'Precondition: within the reader cap.' );
        $this->assertCount( 6, $p6['blocks'] );
        $this->assertLessThanOrEqual( 6, $q6 - $q3, 'three more blocks for 40 readers add almost nothing: the subject check is asked once per reader' );

        [ $q1 ] = $count( $six, [ 'user:' . $this->coach_a ] );
        // Measured: 38 statements for one receiver, 234 for 39, so about 5
        // per extra reader, all in resolving the recipients; blocks add none.
        $per_reader = ( $q6 - $q1 ) / 38;
        $this->assertLessThanOrEqual( 6, $per_reader, sprintf( 'each extra reader costs a bounded number of statements (%.1f), not one per block', $per_reader ) );
    }

    // ---- #5135: who is left out ---------------------------------------------------

    public function test_the_preview_names_who_is_left_out_and_why(): void {
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'tt_coach' ] ] );
        $report   = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $preview = ReportSchedules::preview( $report, [ 'tt_coach' ], $this->hod, [ 'report_id' => $id, 'period' => '' ] );
        $this->assertSame( 1, $preview['receivers'], 'the coach of the team' );
        $this->assertSame( 1, $preview['left_out_recipients'] );
        $this->assertCount( 1, $preview['left_out_people'] );
        $group = $preview['left_out_people'][0];
        $this->assertSame( 'no_read_access', $group['reason'] );
        $this->assertSame( 1, $group['count'] );
        $this->assertStringContainsString( 'may not read this report', $group['message'] );
        $this->assertSame( [ "Sched Persona{$this->coach_b}" ], $group['names'], 'the coach of another team, by the name on their person record' );
        $this->assertNotContains( (string) get_userdata( $this->coach_b )->display_name, $group['names'], 'never by the account display name' );

        $html = $this->renderScreen( $this->hod, $report, (string) $schedule );
        $this->assertStringContainsString( 'tt-rsch-skipped', $html, 'shown under the recipient count' );
        $this->assertStringContainsString( esc_html( $group['message'] ), $html );
        $this->assertStringContainsString( "Sched Persona{$this->coach_b}", $html, 'one name is listed inline' );
    }

    public function test_with_nobody_left_out_nothing_extra_shows(): void {
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'user:' . $this->hod, 'user:' . $this->coach_a ] ] );
        $report   = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $preview = ReportSchedules::preview( $report, [ 'user:' . $this->hod, 'user:' . $this->coach_a ], $this->hod, [ 'report_id' => $id, 'period' => '' ] );
        $this->assertSame( [], $preview['left_out_people'] );
        $this->assertStringNotContainsString( 'tt-rsch-skipped', $this->renderScreen( $this->hod, $report, (string) $schedule ) );
    }

    public function test_more_than_five_names_open_in_a_list(): void {
        for ( $i = 0; $i < 6; $i++ ) $this->persona( 'tt_coach', 'head_coach', $this->team_b, "other{$i}-5135@example.test" );
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $schedule = $this->schedule( $id, [ 'recipients' => [ 'tt_coach' ] ] );
        $html     = $this->renderScreen( $this->hod, (array) ( new ReportsRepository() )->find( $id ), (string) $schedule );
        $this->assertMatchesRegularExpression( '/<details class="tt-rsch-skipped-list"><summary>Show the 7 names<\/summary>/', $html, 'seven coaches of another team, in a list that opens' );
    }

    public function test_the_preview_and_the_run_leave_out_the_same_people(): void {
        $this->needsPdf();
        global $wpdb;
        $id       = $this->published( $this->teamChoices(), [ [ 'id' => 'b1', 'key' => 'heading', 'options' => [ 'heading' => 'Team' ] ] ] );
        $entries  = [ 'tt_coach', 'user:' . $this->hod ];
        $schedule = $this->schedule( $id, [ 'recipients' => $entries ] );
        $report   = ( new ReportsRepository() )->find( $id );
        $this->assertNotNull( $report );

        $resolved = ScheduledReportsRunner::resolveRecipients( $entries );
        $preview  = ReportSchedules::resolve( $resolved, $this->hod, $report )['skipped'];
        $this->runOnce( $schedule );

        $payload = json_decode( (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = 'scheduled_report.run' AND entity_id = %d ORDER BY id DESC LIMIT 1",
            $schedule
        ) ), true );
        $this->assertIsArray( $payload, 'the run was logged' );
        $key = static fn( array $s ): string => $s['reason'] . '|' . (int) $s['user_id'];
        $ran = array_map( $key, (array) $payload['skipped'] );
        $saw = array_map( $key, $preview );
        sort( $ran );
        sort( $saw );
        $this->assertNotSame( [], $saw, 'Precondition: someone is left out.' );
        $this->assertContains( 'no_read_access|' . $this->coach_b, $saw );
        $this->assertTrue( (bool) ( $payload['ok'] ?? false ), 'Precondition: the run sent.' );
        sort( $this->sent );
        $this->assertSame( [ 'coach-a-4963@example.test', 'hod-4963@example.test' ], $this->sent, 'and to exactly the people the preview counts' );
        $this->assertSame( $saw, $ran, 'the preview shows exactly who the run leaves out' );
    }

    // ---- helpers ------------------------------------------------------------------

    /** @return list<array<string,mixed>> */
    private function headings( int $n ): array {
        $out = [];
        for ( $i = 1; $i <= $n; $i++ ) $out[] = [ 'id' => 'b' . $i, 'key' => 'heading', 'options' => [ 'heading' => 'H' . $i ] ];
        return $out;
    }

    /** Reads of the child's entries: by a scheduled send, or on any surface. */
    private function eventRows( string $action, bool $any_surface = false ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = %s AND entity_type = 'player' AND entity_id = %d AND payload LIKE %s",
            $action,
            $this->child,
            $any_surface ? '%' : '%"surface":"scheduled_report"%'
        ) );
    }

    private function injuryRows(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_audit_log WHERE action = 'player.injuries_viewed' AND entity_type = 'player' AND entity_id = %d",
            $this->child
        ) );
    }

    /** @param array<string,mixed> $report */
    private function renderScreen( int $user, array $report, string $schedule ): string {
        wp_set_current_user( $user );
        $this->flush();
        $_GET['schedule'] = $schedule;
        ob_start();
        /** @phpstan-ignore-next-line argument.type */
        \TT\Modules\ReportStudio\Frontend\ReportScheduleScreen::render( $report, (string) $report['name'] );
        $html = (string) ob_get_clean();
        unset( $_GET['schedule'] );
        return $html;
    }

    /** @return array<string,mixed> */
    private function teamChoices(): array {
        return [ 'kind' => 'report', 'scope' => 'team', 'binding' => 'fixed', 'team_id' => $this->team_a, 'period_mode' => 'fixed', 'period_value' => self::PERIOD ];
    }

    /** @return array<string,mixed> */
    private function teamContext( int $team ): array {
        return [ 'scope' => 'team', 'team_id' => $team, 'player_id' => null, 'player_ids' => [], 'from' => '2026-08-01', 'to' => '2026-08-31', 'audience' => 'internal' ];
    }

    /**
     * A report by the head of development, published for these personas.
     *
     * @param array<string,mixed>       $choices
     * @param list<array<string,mixed>> $blocks
     * @param list<string>              $personas
     */
    private function published( array $choices, array $blocks, array $personas = [] ): int {
        return $this->publishAs( $this->hod, $choices + [ 'sharing' => $personas === [] ? 'schedule' : 'role' ], $blocks, $personas );
    }

    /**
     * @param array<string,mixed>       $choices
     * @param list<array<string,mixed>> $blocks
     * @param list<string>              $personas
     */
    private function publishAs( int $user, array $choices, array $blocks, array $personas = [] ): int {
        wp_set_current_user( $user );
        $res = $this->send( 'POST', 'reports', $choices );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $id  = (int) $res->get_data()['data']['id'];
        $res = $this->send( 'PATCH', "reports/{$id}", [ 'definition' => [ 'blocks' => $blocks ] ] );
        $this->assertSame( 200, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        $res = $this->send( 'POST', "reports/{$id}/versions", [ 'note' => 'First', 'persona_keys' => $personas ] );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return $id;
    }

    /** @param array<string,mixed> $body */
    private function schedule( int $report_id, array $body ): int {
        wp_set_current_user( $this->hod );
        $res = $this->send( 'POST', "reports/{$report_id}/schedules", $body );
        $this->assertSame( 201, $res->get_status(), (string) wp_json_encode( $res->get_data() ) );
        return (int) $res->get_data()['data']['id'];
    }

    private function runOnce( int $schedule ): void {
        $this->flush();
        $repo = new ScheduledReportsRepository();
        ScheduledReportsRunner::runStudio( $repo, (array) $repo->findById( $schedule ), gmdate( 'Y-m-01' ) . ' 06:00:00' );
    }

    /** @param array<string,mixed> $data */
    private function block( string $key, string $capability, array $data = [ 'value' => 1 ] ): ReportBlock {
        return new class( $key, $capability, $data ) implements ReportBlock {
            /** @param array<string,mixed> $data */
            public function __construct( private string $k, private string $cap, private array $data ) {}
            public function key(): string { return $this->k; }
            public function label(): string { return 'Test block ' . $this->k; }
            public function scopes(): array { return ReportContext::SCOPES; }
            public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
            public function capability(): string { return $this->cap; }
            public function options( ReportContext $context ): array { return []; }
            public function available( ReportContext $context ): bool { return true; }
            public function externalKeys(): array { return []; }
            public function render( ReportContext $context, array $options ): array { return $this->data; }
        };
    }

    private function perReaderBlock(): ReportBlock {
        return new class implements ReportBlock, ComposesPerReader {
            public function key(): string { return 'test_per_reader'; }
            public function label(): string { return 'Per reader'; }
            public function scopes(): array { return ReportContext::SCOPES; }
            public function audiences(): array { return [ PlayerReportAudience::INTERNAL ]; }
            public function capability(): string { return 'tt_view_reports'; }
            public function options( ReportContext $context ): array { return []; }
            public function available( ReportContext $context ): bool { return true; }
            public function externalKeys(): array { return []; }
            public function render( ReportContext $context, array $options ): array { return [ 'value' => $context->userId ]; }
        };
    }

    private function needsPdf(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) $this->markTestSkipped( 'DomPDF not installed.' );
    }

    private function player( int $team, string $last ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $team, 'first_name' => 'Sched', 'last_name' => $last, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function persona( string $role, string $role_type, int $team, string $email ): int {
        global $wpdb;
        $club = (int) CurrentClub::id();
        $uid  = (int) self::factory()->user->create( [ 'role' => $role, 'user_email' => $email ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => $club, 'first_name' => 'Sched', 'last_name' => 'Persona' . $uid, 'email' => $email,
            'role_type' => $role_type, 'wp_user_id' => $uid, 'status' => 'active',
        ] );
        $person = (int) $wpdb->insert_id;
        if ( $team > 0 ) {
            $wpdb->insert( "{$wpdb->prefix}tt_team_people", [
                'club_id' => $club, 'team_id' => $team, 'person_id' => $person,
                'role_in_team' => $role_type, 'is_head_coach' => $role_type === 'head_coach' ? 1 : 0,
            ] );
            $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
                'club_id' => $club, 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
            ] );
        }
        $this->flush();
        return $uid;
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

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        SharedReads::flush();
        wp_cache_flush();
    }
}
