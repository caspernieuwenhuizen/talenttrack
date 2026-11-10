<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\TeamMonthlyReport;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportDocument;
use TT\Modules\Analytics\Reports\TeamMonthlyReportRecipients;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;
use TT\Modules\Export\ExportGate;

/**
 * #4991 — a scheduled monthly team report is one PDF for all its
 * recipients, and carries only the sections every one of them may read.
 * The recipients are resolved at each run, so a right lost after saving
 * shrinks the next PDF, and a recipient whose rights cannot be checked
 * counts as having none.
 */
final class ScheduledMonthlyCommonRightsTest extends WP_UnitTestCase {

    use QuietHoursClosed;

    /** The headline tile's label cell, as the document draws it. */
    private const ATTENDANCE_TILE = '<div class="kl">Attendance</div>';

    private ScheduledReportsRepository $repo;
    private int $team = 0;
    private int $owner = 0;

    /** @var list<string> addresses a PDF was sent to */
    private array $sent = [];

    /** @var list<string> the HTML of every PDF rendered */
    private array $pdf_html = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $p = $wpdb->prefix;
        ( require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php' )->up();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'Shared U13' ] );
        $this->team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'first_name' => 'Anna', 'last_name' => 'Shared',
            'jersey_number' => 7, 'status' => 'active',
        ] );
        $player = (int) $wpdb->insert_id;
        // One training in the window the schedule reports on (last month),
        // with a register, so the report has sections to draw.
        $wpdb->insert( "{$p}tt_activities", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team, 'title' => 'Training',
            'session_date' => gmdate( 'Y-m-15', strtotime( 'first day of last month' ) ),
            'activity_type_key' => 'training', 'activity_status_key' => 'completed', 'plan_state' => 'completed',
        ] );
        $wpdb->insert( "{$p}tt_attendance", [
            'club_id' => CurrentClub::id(), 'activity_id' => (int) $wpdb->insert_id, 'player_id' => $player,
            'status' => 'Present', 'is_guest' => 0, 'record_type' => 'actual',
        ] );

        $this->owner = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin', 'user_email' => 'owner-4991@example.test' ] );
        $this->person( $this->owner, 'staff' );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        $this->closeQuietHours();

        $this->repo     = new ScheduledReportsRepository();
        $this->sent     = [];
        $this->pdf_html = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
        add_filter( 'tt_pdf_render_html', [ $this, 'capturePdf' ] );
        $this->flush();

        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $this->owner, $this->team ), 'Precondition: the owner reads attendance.' );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        remove_filter( 'tt_pdf_render_html', [ $this, 'capturePdf' ] );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
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
        }
        return true;
    }

    public function capturePdf( string $html ): string {
        $this->pdf_html[] = $html;
        return $html;
    }

    public function test_one_recipient_without_attendance_takes_it_out_for_everyone(): void {
        $this->needsPdf();
        $observer = $this->observer( 'observer-4991@example.test' );

        $this->runMonthly( [ 'owner-4991@example.test', 'observer-4991@example.test' ] );

        sort( $this->sent );
        $this->assertSame( [ 'observer-4991@example.test', 'owner-4991@example.test' ], $this->sent, 'both receive the one PDF' );
        $this->assertCount( 1, $this->pdf_html, 'one PDF for all recipients' );
        $this->assertNotContains( 'Attendance', $this->headers( $this->pdf_html[0] ), 'no attendance section' );
        $this->assertStringNotContainsString( self::ATTENDANCE_TILE, $this->pdf_html[0], 'no attendance tile' );
        $this->assertGreaterThan( 0, $observer );
    }

    public function test_every_recipient_holding_attendance_keeps_it(): void {
        $this->needsPdf();
        $this->runMonthly( [ 'owner-4991@example.test' ] );

        $this->assertSame( [ 'owner-4991@example.test' ], $this->sent );
        $this->assertContains( 'Attendance', $this->headers( $this->pdf_html[0] ), implode( ' / ', $this->headers( $this->pdf_html[0] ) ) );
        $this->assertStringContainsString( self::ATTENDANCE_TILE, $this->pdf_html[0], 'the tile, so its absence elsewhere means something' );
    }

    public function test_a_recipient_losing_the_right_shrinks_the_next_run(): void {
        $this->needsPdf();
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'hod-4991@example.test' ] );
        $person = $this->person( $hod, 'head_of_development' );
        $this->flush();
        $this->assertTrue( AuthorizationService::canReadTeamAttendance( $hod, $this->team ), 'Precondition: the head of development reads attendance.' );

        $id = $this->createMonthly( [ 'owner-4991@example.test', 'hod-4991@example.test' ] );
        $this->runSchedule( $id );
        $this->assertContains( 'Attendance', $this->headers( (string) end( $this->pdf_html ) ), 'first run: everyone reads it' );

        // After the schedule was saved, the head of development becomes a
        // read-only observer: still a reader of the team's reports, no longer
        // of its attendance.
        global $wpdb;
        ( new \WP_User( $hod ) )->set_role( 'tt_readonly_observer' );
        $wpdb->update( "{$wpdb->prefix}tt_people", [ 'role_type' => 'readonly_observer' ], [ 'id' => $person ] );
        $this->scope( $person, $this->team );
        $this->grantFiles( 'readonly_observer' );
        $this->flush();
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $hod, $this->team ), 'Precondition: the right is gone.' );
        $this->assertTrue( ExportGate::mayExport( $hod ), 'Precondition: still receives files.' );
        $this->assertTrue( TeamReportAccess::canRead( $hod, $this->team ), 'Precondition: still a recipient.' );

        $this->sent = [];
        $this->runSchedule( $id );
        $this->assertContains( 'hod-4991@example.test', $this->sent );
        $this->assertNotContains( 'Attendance', $this->headers( (string) end( $this->pdf_html ) ), 'the next run leaves it out' );
    }

    public function test_an_address_without_an_account_counts_as_no_rights(): void {
        $this->needsPdf();
        $this->runMonthly( [ 'owner-4991@example.test', 'nobody-4991@example.test' ] );

        $this->assertSame( [ 'owner-4991@example.test' ], $this->sent, 'the address receives nothing' );
        $this->assertNotContains( 'Attendance', $this->headers( (string) end( $this->pdf_html ) ), 'and its rights are unknown, so attendance goes' );
    }

    public function test_a_role_with_no_members_counts_as_no_rights(): void {
        $this->needsPdf();
        add_role( 'tt_empty_4991', 'Empty 4991', [ 'read' => true ] );
        try {
            $this->runMonthly( [ 'owner-4991@example.test', 'tt_empty_4991' ] );
        } finally {
            remove_role( 'tt_empty_4991' );
        }

        $this->assertSame( [ 'owner-4991@example.test' ], $this->sent );
        $this->assertNotContains( 'Attendance', $this->headers( (string) end( $this->pdf_html ) ) );
    }

    /**
     * The email, the PDF attached to it and the report on screen agree: for
     * the same recipients, the PDF is the screen as the least-privileged of
     * them reads it, section for section.
     */
    public function test_the_email_the_pdf_and_the_screen_agree(): void {
        $this->needsPdf();
        $observer = $this->observer( 'observer-4991@example.test' );
        $id       = $this->createMonthly( [ 'owner-4991@example.test', 'observer-4991@example.test' ] );
        $schedule = (array) $this->repo->findById( $id );
        $this->runSchedule( $id );

        $this->assertCount( 2, $this->sent, 'the email went to both, with the PDF attached' );
        $pdf = (string) end( $this->pdf_html );

        $composition = TeamMonthlyReportComposition::normalise( (array) $schedule['composition'] );
        $window      = TeamMonthlyReportComposition::window( $composition, gmdate( 'Y-m-01' ) );
        $screen      = ( new TeamMonthlyReport() )->forTeam( $this->team, $window['from'], $window['to'], $composition['blocks'], $observer, $composition['options'] );
        $prepared    = TeamMonthlyReportDocument::prepare( $screen, $composition['layout'] );
        $screen_html = TeamMonthlyReportDocument::html( $prepared['report'], $composition['layout'], 'Shared U13', [], $prepared['fit']['groups'] );

        $this->assertNotSame( [], $this->headers( $pdf ), 'Precondition: the PDF has sections to compare.' );
        $this->assertSame( $this->headers( $screen_html ), $this->headers( $pdf ), 'the same sections, in the same order' );
        $this->assertNotContains( 'Attendance', $this->headers( $pdf ) );

        // And the schedules list says the same before the run.
        $preview = TeamMonthlyReportRecipients::preview( $schedule );
        $this->assertTrue( $preview['without_attendance'] );
        $this->assertNotContains( 'Attendance', $preview['sections'] );
    }

    public function test_the_list_preview_follows_the_recipients(): void {
        $with    = (array) $this->repo->findById( $this->createMonthly( [ 'owner-4991@example.test' ] ) );
        $without = (array) $this->repo->findById( $this->createMonthly( [ 'owner-4991@example.test', 'nobody-4991@example.test' ] ) );

        $this->assertFalse( TeamMonthlyReportRecipients::preview( $with )['without_attendance'] );
        $this->assertContains( 'Attendance', TeamMonthlyReportRecipients::preview( $with )['sections'] );
        $this->assertTrue( TeamMonthlyReportRecipients::preview( $without )['without_attendance'] );
        $this->assertNotContains( 'Attendance', TeamMonthlyReportRecipients::preview( $without )['sections'] );
    }

    public function test_the_attendance_tile_and_columns_go_with_the_section(): void {
        $report = ( new TeamMonthlyReport() )->forTeam( $this->team, '2026-03-01', '2026-03-31', [], $this->owner );
        $this->assertContains( 'attendance', $report['blocks'], 'Precondition: the owner\'s report has attendance.' );

        $none = ScheduledReportsRunner::resolveRecipients( [ 'owner-4991@example.test', 'nobody-4991@example.test' ] );
        $out  = TeamMonthlyReportRecipients::forRecipients( $report, $this->team, $none );

        $this->assertNotContains( 'attendance', $out['blocks'] );
        $this->assertArrayNotHasKey( 'attendance', $out['data'] );
        $this->assertArrayNotHasKey( 'attendance_pct', $out['data']['kpi'] ?? [], 'no attendance tile' );

        $all = ScheduledReportsRunner::resolveRecipients( [ 'owner-4991@example.test' ] );
        $this->assertSame( $report, TeamMonthlyReportRecipients::forRecipients( $report, $this->team, $all ), 'unchanged when everyone reads it' );
    }

    // ===== helpers =====

    /** @return list<string> the section headers of a rendered report, in order */
    private function headers( string $html ): array {
        preg_match_all( '#<h2[^>]*>(.*?)</h2>#s', $html, $m );
        return array_map( static fn( string $h ): string => trim( html_entity_decode( wp_strip_all_tags( $h ) ) ), $m[1] );
    }

    private function needsPdf(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
    }

    private function observer( string $email ): int {
        $user   = (int) self::factory()->user->create( [ 'role' => 'tt_readonly_observer', 'user_email' => $email ] );
        $person = $this->person( $user, 'readonly_observer' );
        $this->scope( $person, $this->team );
        $this->grantFiles( 'readonly_observer' );
        $this->flush();
        $this->assertTrue( TeamReportAccess::canRead( $user, $this->team ), 'Precondition: the observer reads the team\'s reports.' );
        $this->assertTrue( ExportGate::mayExport( $user ), 'Precondition: the observer may receive a file.' );
        $this->assertFalse( AuthorizationService::canReadTeamAttendance( $user, $this->team ), 'Precondition: the observer does not read attendance.' );
        return $user;
    }

    /** @param list<string> $recipients */
    private function createMonthly( array $recipients ): int {
        $id = $this->repo->create( [
            'name'        => 'Shared monthly U13',
            'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
            'composition' => TeamMonthlyReportComposition::normalise( [ 'team_id' => $this->team, 'period' => 'last_month', 'layout' => 'B' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => $recipients,
            'format'      => 'pdf',
        ], $this->owner );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function runSchedule( int $id ): void {
        $this->flush();
        ScheduledReportsRunner::runTeamMonthly( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );
    }

    /** @param list<string> $recipients */
    private function runMonthly( array $recipients ): void {
        $this->runSchedule( $this->createMonthly( $recipients ) );
        $this->assertNotSame( [], $this->pdf_html, 'a PDF was rendered' );
    }

    private function person( int $user_id, string $role_type ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => ucfirst( $role_type ), 'last_name' => 'Shared',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /**
     * An academy can let a persona receive files (`exports` read). The
     * observer does not by default; here it does, so it is a recipient
     * without the attendance right rather than one left out altogether.
     */
    private function grantFiles( string $persona ): void {
        global $wpdb;
        $t      = "{$wpdb->prefix}tt_authorization_matrix";
        $module = (string) $wpdb->get_var( $wpdb->prepare( "SELECT module_class FROM {$t} WHERE entity = %s LIMIT 1", ExportGate::ENTITY ) );
        $wpdb->insert( $t, [
            'persona' => $persona, 'entity' => ExportGate::ENTITY, 'activity' => 'read',
            'scope_kind' => 'global', 'module_class' => $module, 'is_default' => 0,
        ] );
    }

    private function scope( int $person, int $team ): void {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_user_role_scopes", [
            'club_id' => CurrentClub::id(), 'person_id' => $person, 'role_id' => 1, 'scope_type' => 'team', 'scope_id' => $team,
        ] );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        wp_cache_flush();
    }
}
