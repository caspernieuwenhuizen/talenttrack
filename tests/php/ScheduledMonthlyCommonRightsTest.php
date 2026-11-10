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
 * #4991 — a scheduled report (the monthly team report, and the player
 * report) is one PDF for all its recipients, and every section carries only
 * what every one of them may read.
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
        $player       = (int) $wpdb->insert_id;
        $this->player = $player;
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
        \TT\Core\FeatureRegistry::setEnabled( 'journey_medical_visibility', false );
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

    /**
     * The report is composed as the person who set the schedule up, so an
     * owner without the attendance right takes it out however many of the
     * recipients have it, and the list says so before the run.
     */
    public function test_an_owner_without_attendance_leaves_it_out_and_the_list_says_so(): void {
        $this->needsPdf();
        $observer = $this->observer( 'observer-owner-4991@example.test' );
        $id       = $this->createMonthly( [ 'owner-4991@example.test' ], $observer );
        $schedule = (array) $this->repo->findById( $id );
        $this->assertSame( $observer, (int) $schedule['created_by'], 'Precondition: the observer owns the schedule.' );

        $preview = TeamMonthlyReportRecipients::preview( $schedule );
        $this->assertTrue( $preview['without_attendance'], 'the list says attendance is left out' );
        $this->assertNotContains( 'Attendance', $preview['sections'] );

        $this->runSchedule( $id );
        $this->assertSame( [ 'owner-4991@example.test' ], $this->sent, 'the recipient, who reads attendance, still receives it' );
        $this->assertNotContains( 'Attendance', $this->headers( (string) end( $this->pdf_html ) ), 'and the PDF agrees with the list' );
        $this->assertStringNotContainsString( self::ATTENDANCE_TILE, (string) end( $this->pdf_html ) );
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

        $out = $this->teamFor( [ 'owner-4991@example.test', 'nobody-4991@example.test' ], '2026-03-01', '2026-03-31' );

        $this->assertNotContains( 'attendance', $out['blocks'] );
        $this->assertArrayNotHasKey( 'attendance', $out['data'] );
        $this->assertArrayNotHasKey( 'attendance_pct', $out['data']['kpi'] ?? [], 'no attendance tile' );

        $this->assertSame( $report, $this->teamFor( [ 'owner-4991@example.test' ], '2026-03-01', '2026-03-31' ), 'unchanged when everyone reads it' );
    }

    /**
     * Every section, not only attendance: "what changed" carries only the
     * journey entries every recipient may see. One recipient who reads the
     * team's reports but holds no coaching-staff, medical or safeguarding
     * level, and no PDP verdict right, takes all of those out of the copy
     * everyone gets.
     */
    public function test_one_limited_recipient_takes_the_gated_changes_out_for_everyone(): void {
        $this->seedJourney();
        $observer = $this->observer( 'observer-4991@example.test' );
        $this->assertSame( [ 'public' ], \TT\Infrastructure\Journey\PlayerEventsRepository::visibilitiesForUser( $observer ), 'Precondition: the observer holds the public level only.' );
        $this->assertFalse( AuthorizationService::canReadPlayerSection( $observer, $this->player, 'pdp_verdict' ), 'Precondition: the observer holds no PDP verdict right.' );

        [ $from, $to ] = $this->lastMonth();
        $report  = $this->teamFor( [ 'owner-4991@example.test', 'observer-4991@example.test' ], $from, $to );
        $changes = array_column( (array) $report['data']['changes']['events'], 'summary' );

        $this->assertSame( [ self::ENTRY_PUBLIC ], $changes, 'only the public entry: ' . implode( ' / ', $changes ) );
        $this->assertNotContains( 'attendance', $report['blocks'] );
    }

    public function test_recipients_all_holding_full_rights_get_the_full_team_report(): void {
        $this->seedJourney();
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'hod-4991@example.test' ] );
        $this->person( $hod, 'head_of_development' );
        $this->flush();

        [ $from, $to ] = $this->lastMonth();
        $alone    = ( new TeamMonthlyReport() )->forTeam( $this->team, $from, $to, [], $this->owner );
        $together = $this->teamFor( [ 'owner-4991@example.test', 'hod-4991@example.test' ], $from, $to );

        $changes = array_column( (array) $together['data']['changes']['events'], 'summary' );
        foreach ( $this->fullEntries( [ $this->owner, $hod ] ) as $summary ) {
            $this->assertContains( $summary, $changes, 'every entry they all may read: ' . implode( ' / ', $changes ) );
        }
        $this->assertContains( 'attendance', $together['blocks'] );
        $this->assertSame( $alone, $together, 'the owner\'s own report, unchanged' );
    }

    /**
     * The scheduled player report follows the same rule: each player's
     * report in the shared PDF holds only what every recipient may read —
     * the journey entries, the PDP file and the notes thread.
     */
    public function test_one_limited_recipient_narrows_the_player_report_for_everyone(): void {
        $this->seedJourney();
        $observer = $this->observer( 'observer-4991@example.test' );
        $this->assertTrue( \TT\Modules\Analytics\Reports\PlayerReportAccess::canRead( $observer, $this->player ), 'Precondition: the observer reads the player report.' );
        $this->assertFalse( \TT\Modules\Pdp\PdpAccess::canSeeFile( $observer, $this->player ), 'Precondition: the observer may not see the PDP file.' );

        [ $from, $to ] = $this->lastMonth();
        $report = $this->playerFor( [ 'owner-4991@example.test', 'observer-4991@example.test' ], $from, $to );
        $this->assertNotNull( $report );

        $journey = array_column( (array) $report['data']['journey']['items'], 'summary' );
        $this->assertSame( [ self::ENTRY_PUBLIC ], $journey, 'only the public entry: ' . implode( ' / ', $journey ) );
        $this->assertFalse( (bool) ( $report['data']['pdp']['available'] ?? true ), 'no PDP file' );
        $this->assertSame( [], (array) $report['data']['thread_notes']['items'], 'no notes thread' );
    }

    public function test_recipients_all_holding_full_rights_get_the_full_player_report(): void {
        $this->seedJourney();
        $hod = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'hod-4991@example.test' ] );
        $this->person( $hod, 'head_of_development' );
        $this->flush();

        [ $from, $to ] = $this->lastMonth();
        $alone    = ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forPlayer( $this->player, $from, $to, self::PLAYER_BLOCKS, $this->owner );
        $together = $this->playerFor( [ 'owner-4991@example.test', 'hod-4991@example.test' ], $from, $to );
        $this->assertNotNull( $together );

        $journey = array_column( (array) $together['data']['journey']['items'], 'summary' );
        foreach ( $this->fullEntries( [ $this->owner, $hod ] ) as $summary ) {
            $this->assertContains( $summary, $journey, 'every entry they all may read: ' . implode( ' / ', $journey ) );
        }
        $this->assertSame( $alone, $together, 'the owner\'s own report, unchanged' );
    }

    /** The runner hands the player report its recipients: one PDF, narrowed, to both. */
    public function test_a_scheduled_player_report_is_composed_for_its_recipients(): void {
        $this->needsPdf();
        $this->seedJourney();
        $this->observer( 'observer-4991@example.test' );

        $id = $this->repo->create( [
            'name'        => 'Shared player report',
            'report_key'  => ScheduledReportsRepository::REPORT_PLAYER,
            'composition' => \TT\Modules\Analytics\Reports\PlayerReportComposition::normalise( [ 'player_id' => $this->player, 'period' => 'last_month', 'blocks' => self::PLAYER_BLOCKS ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => [ 'owner-4991@example.test', 'observer-4991@example.test' ],
            'format'      => 'pdf',
        ], $this->owner );
        $this->flush();
        ScheduledReportsRunner::runPlayerReport( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );

        sort( $this->sent );
        $this->assertSame( [ 'observer-4991@example.test', 'owner-4991@example.test' ], $this->sent, 'both receive the one PDF' );
        $this->assertCount( 1, $this->pdf_html );
        $this->assertStringContainsString( self::ENTRY_PUBLIC, $this->pdf_html[0], 'Precondition: the journey is printed.' );
        foreach ( [ self::ENTRY_STAFF, self::ENTRY_SAFEGUARDING, self::ENTRY_MEDICAL, self::ENTRY_VERDICT ] as $summary ) {
            $this->assertStringNotContainsString( $summary, $this->pdf_html[0] );
        }
    }

    // ===== helpers =====

    private const ENTRY_PUBLIC       = 'Moved to left back 4991';
    private const ENTRY_STAFF        = 'Staff-only move 4991';
    private const ENTRY_MEDICAL      = 'Medical move 4991';
    private const ENTRY_SAFEGUARDING = 'Safeguarding move 4991';
    private const ENTRY_VERDICT      = 'PDP verdict recorded 4991';

    private const PLAYER_BLOCKS = [ 'letterhead', 'journey', 'pdp', 'thread_notes' ];

    private int $player = 0;

    /** @return array{0:string, 1:string} last month, the window a schedule on `last_month` reports on */
    private function lastMonth(): array {
        return [ gmdate( 'Y-m-01', strtotime( 'first day of last month' ) ), gmdate( 'Y-m-t', strtotime( 'first day of last month' ) ) ];
    }

    /**
     * One journey entry at each visibility level, and a PDP verdict entry,
     * in last month. The medical level is switched on, so it is a level a
     * reader can hold.
     */
    private function seedJourney(): void {
        global $wpdb;
        \TT\Core\FeatureRegistry::setEnabled( 'journey_medical_visibility', true );
        $when = gmdate( 'Y-m-10 09:00:00', strtotime( 'first day of last month' ) );
        $rows = [
            [ 'position_changed', 'public', self::ENTRY_PUBLIC, '{}' ],
            [ 'position_changed', 'coaching_staff', self::ENTRY_STAFF, '{}' ],
            [ 'position_changed', 'medical', self::ENTRY_MEDICAL, '{}' ],
            [ 'position_changed', 'safeguarding', self::ENTRY_SAFEGUARDING, '{}' ],
            [ 'pdp_verdict_recorded', 'public', self::ENTRY_VERDICT, '{"pdp_file_id":1,"decision":"promote"}' ],
        ];
        foreach ( $rows as $i => [ $type, $visibility, $summary, $payload ] ) {
            $wpdb->insert( "{$wpdb->prefix}tt_player_events", [
                'club_id'            => CurrentClub::id(),
                'uuid'               => wp_generate_uuid4(),
                'player_id'          => $this->player,
                'event_type'         => $type,
                'event_date'         => $when,
                'summary'            => $summary,
                'payload'            => $payload,
                'visibility'         => $visibility,
                'source_module'      => 'tests',
                'source_entity_type' => 'test_event',
                'source_entity_id'   => 4991 + $i,
            ] );
            $this->assertGreaterThan( 0, (int) $wpdb->insert_id, 'fixture: the journey entry was written' );
        }
        $this->flush();
    }

    /**
     * The seeded entries every one of these readers may read, from their
     * own rights: the expectation for a copy they all receive.
     *
     * @param list<int> $readers
     * @return list<string>
     */
    private function fullEntries( array $readers ): array {
        $levels = [ 'public' => self::ENTRY_PUBLIC, 'coaching_staff' => self::ENTRY_STAFF, 'medical' => self::ENTRY_MEDICAL, 'safeguarding' => self::ENTRY_SAFEGUARDING ];
        $held   = \TT\Infrastructure\Journey\PlayerEventsRepository::visibilitiesForReaders( $readers );
        $out    = [];
        foreach ( $levels as $level => $summary ) {
            if ( in_array( $level, $held, true ) ) $out[] = $summary;
        }
        $verdict = true;
        foreach ( $readers as $reader ) {
            if ( ! AuthorizationService::canReadPlayerSection( $reader, $this->player, 'pdp_verdict' ) ) $verdict = false;
        }
        if ( $verdict ) $out[] = self::ENTRY_VERDICT;
        $this->assertGreaterThan( 2, count( $out ), 'Precondition: these readers hold more than the public level: ' . implode( ' / ', $out ) );
        return $out;
    }

    /**
     * The team report as the delivery composes it for these recipients.
     *
     * @param list<string> $recipients
     * @return array<string,mixed>
     */
    private function teamFor( array $recipients, string $from, string $to ): array {
        $this->flush();
        $resolved = ScheduledReportsRunner::resolveRecipients( $recipients );
        $readers  = TeamMonthlyReportRecipients::readers( $this->team, $resolved, $this->owner );
        return ( new TeamMonthlyReport() )->forReaders( $this->team, $from, $to, [], $readers );
    }

    /**
     * The player report as the delivery composes it for these recipients.
     *
     * @param list<string> $recipients
     * @return array<string,mixed>|null
     */
    private function playerFor( array $recipients, string $from, string $to ): ?array {
        $this->flush();
        $resolved = ScheduledReportsRunner::resolveRecipients( $recipients );
        $readers  = \TT\Modules\Analytics\Reports\PlayerReportDelivery::readers( [ $this->player ], $resolved, $this->owner );
        return ( new \TT\Modules\Analytics\Reports\PlayerReport() )->forReaders( $this->player, $from, $to, self::PLAYER_BLOCKS, $readers );
    }

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
    private function createMonthly( array $recipients, int $owner = 0 ): int {
        $id = $this->repo->create( [
            'name'        => 'Shared monthly U13',
            'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
            'composition' => TeamMonthlyReportComposition::normalise( [ 'team_id' => $this->team, 'period' => 'last_month', 'layout' => 'B' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => $recipients,
            'format'      => 'pdf',
        ], $owner > 0 ? $owner : $this->owner );
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
