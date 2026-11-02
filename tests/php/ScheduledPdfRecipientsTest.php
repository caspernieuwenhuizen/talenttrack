<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Reports\PlayerReportAccess;
use TT\Modules\Analytics\Reports\PlayerReportComposition;
use TT\Modules\Analytics\Reports\TeamMonthlyReportComposition;
use TT\Modules\Analytics\Reports\TeamReportAccess;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * #4662 — a scheduled PDF (the team monthly report, the player report)
 * reaches only recipients who may read what it is about. A role reaches its
 * members in this club, an address counts only as an account in this club,
 * and whoever is left out is named on the schedule and in the audit row.
 *
 * Every refusal is paired with someone who does receive, so a run that sent
 * nothing at all fails instead of passing.
 */
final class ScheduledPdfRecipientsTest extends WP_UnitTestCase {

    private ScheduledReportsRepository $repo;
    private int $team_a = 0;
    private int $team_b = 0;
    private int $player_a = 0;
    private int $owner = 0;
    private int $coach_a = 0;
    private int $coach_b = 0;

    /** @var list<string> the addresses a PDF was sent to */
    private array $sent = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();

        global $wpdb;
        $wpdb->hide_errors();
        $p = $wpdb->prefix;
        ( require dirname( __DIR__, 2 ) . '/database/migrations/0266_scheduled_reports_composition.php' )->up();

        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'PDF U12' ] );
        $this->team_a = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_teams", [ 'club_id' => CurrentClub::id(), 'name' => 'PDF U15' ] );
        $this->team_b = (int) $wpdb->insert_id;
        $wpdb->insert( "{$p}tt_players", [
            'club_id' => CurrentClub::id(), 'team_id' => $this->team_a, 'first_name' => 'Anna', 'last_name' => 'Pdf',
            'jersey_number' => 7, 'status' => 'active',
        ] );
        $this->player_a = (int) $wpdb->insert_id;

        $this->owner = (int) self::factory()->user->create( [ 'role' => 'tt_club_admin', 'user_email' => 'owner-4662@example.test' ] );
        $this->person( $this->owner, 'staff' );
        $this->coach_a = $this->coachOf( $this->team_a, 'coach-a-4662@example.test' );
        $this->coach_b = $this->coachOf( $this->team_b, 'coach-b-4662@example.test' );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $this->repo = new ScheduledReportsRepository();
        $this->sent = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
        $this->flush();
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
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

    public function test_a_player_report_to_the_parent_role_reaches_no_parent_who_may_not_read_it(): void {
        $this->needsPdf();
        global $wpdb;
        $own   = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'own-parent-4662@example.test' ] );
        $other = (int) self::factory()->user->create( [ 'role' => 'tt_parent', 'user_email' => 'other-parent-4662@example.test' ] );
        $this->person( $own, 'parent' );
        $this->person( $other, 'parent' );
        $wpdb->insert( "{$wpdb->prefix}tt_player_parents", [ 'player_id' => $this->player_a, 'parent_user_id' => $own, 'is_primary' => 1 ] );
        $this->flush();
        $this->assertTrue( PlayerReportAccess::canRead( $this->owner, $this->player_a ), 'Precondition: the owner reads the report.' );
        $this->assertFalse( PlayerReportAccess::canRead( $own, $this->player_a ), 'Precondition: a parent holds no reports grant.' );
        $this->assertFalse( PlayerReportAccess::canRead( $other, $this->player_a ) );

        $id = $this->runPlayer( [ 'tt_parent', 'owner-4662@example.test' ] );

        $this->assertSame( [ 'owner-4662@example.test' ], $this->sent, 'only the owner may read the player report' );
        $note = (string) $this->lastError( $id );
        $this->assertStringContainsString( 'tt_parent (2 members)', $note );
        $this->assertStringNotContainsString( 'parent-4662@example.test', $note, 'a parent\'s address reached the schedules screen' );
    }

    public function test_a_monthly_report_reaches_only_staff_who_may_read_the_team(): void {
        $this->needsPdf();
        $this->assertTrue( TeamReportAccess::canRead( $this->coach_a, $this->team_a ), 'Precondition: team A\'s coach reads its reports.' );
        $this->assertFalse( TeamReportAccess::canRead( $this->coach_b, $this->team_a ) );

        $id = $this->runMonthly( [ 'tt_coach', 'owner-4662@example.test' ] );

        sort( $this->sent );
        $this->assertSame( [ 'coach-a-4662@example.test', 'owner-4662@example.test' ], $this->sent );
        $note = (string) $this->lastError( $id );
        $this->assertStringContainsString( 'tt_coach (1 member)', $note );
        $this->assertStringNotContainsString( 'coach-b-4662@example.test', $note );
    }

    public function test_a_plain_address_without_an_account_here_gets_nothing(): void {
        $this->needsPdf();
        $outsider = (int) self::factory()->user->create( [ 'user_email' => 'other-club-4662@example.test' ] );
        $this->assertGreaterThan( 0, $outsider, 'an account with no person record in this club' );

        $id = $this->runMonthly( [ 'nobody-4662@example.test', 'other-club-4662@example.test', 'owner-4662@example.test' ] );

        $this->assertSame( [ 'owner-4662@example.test' ], $this->sent );
        $note = (string) $this->lastError( $id );
        $this->assertStringContainsString( 'nobody-4662@example.test', $note );
        $this->assertStringContainsString( 'other-club-4662@example.test', $note );
    }

    public function test_the_skipped_recipients_are_listed_in_the_audit_row(): void {
        $this->needsPdf();
        global $wpdb;
        $this->runMonthly( [ 'coach-b-4662@example.test', 'nobody-4662@example.test', 'owner-4662@example.test' ] );

        $payload = (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT payload FROM {$wpdb->prefix}tt_audit_log WHERE action = %s ORDER BY id DESC LIMIT 1",
            'scheduled_report.run'
        ) );
        $row     = json_decode( $payload, true );
        $this->assertIsArray( $row );
        $this->assertTrue( $row['ok'] );
        $this->assertSame( 1, $row['recipients_count'], 'the owner received' );
        $reasons = array_column( (array) $row['skipped'], 'reason', 'email' );
        $this->assertSame( 'no_read_access', $reasons['coach-b-4662@example.test'] ?? null );
        $this->assertSame( 'no_account', $reasons['nobody-4662@example.test'] ?? null );
    }

    public function test_a_schedule_nobody_may_receive_sends_nothing_and_says_so(): void {
        $this->needsPdf();
        $id = $this->runMonthly( [ 'coach-b-4662@example.test' ] );

        $this->assertSame( [], $this->sent );
        $this->assertStringContainsString( 'coach-b-4662@example.test', (string) $this->lastError( $id ) );
        $this->assertSame( ScheduledReportsRepository::STATUS_ACTIVE, ( (array) $this->repo->findById( $id ) )['status'], 'nobody to send to is not a reason to pause' );
    }

    public function test_the_kpi_path_reaches_only_accounts_in_this_club(): void {
        $outsider = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'outside-hod-4662@example.test' ] );
        $insider  = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'inside-hod-4662@example.test' ] );
        $this->person( $insider, 'head_of_development' );
        $this->flush();

        $role = ScheduledReportsRunner::planKpiDelivery( [ 'tt_head_dev' ], 'attendance', $this->owner );
        $reached = [];
        foreach ( $role['deliveries'] as $delivery ) {
            foreach ( $delivery['recipients'] as $recipient ) $reached[] = $recipient->userId;
        }
        $this->assertContains( $insider, $reached );
        $this->assertNotContains( $outsider, $reached, 'a member of the role from outside the club' );
        $this->assertContains( 'not_in_club', array_column( $role['skipped'], 'reason' ) );
        $this->assertNotContains( 'outside-hod-4662@example.test', array_column( $role['skipped'], 'email' ), 'never named' );

        $typed   = ScheduledReportsRunner::planKpiDelivery( [ 'outside-hod-4662@example.test' ], 'attendance', $this->owner );
        $reasons = array_column( $typed['skipped'], 'reason', 'email' );
        $this->assertSame( 'player_data_to_address', $reasons['outside-hod-4662@example.test'] ?? null, 'an account outside the club counts as an address with no account here' );
    }

    // ===== helpers =====

    private function needsPdf(): void {
        if ( ! class_exists( \Dompdf\Dompdf::class ) ) {
            $this->markTestSkipped( 'DomPDF not installed.' );
        }
    }

    /** @param list<string> $recipients */
    private function runMonthly( array $recipients ): int {
        $id = $this->repo->create( [
            'name'        => 'Monthly PDF U12',
            'report_key'  => ScheduledReportsRepository::REPORT_TEAM_MONTHLY,
            'composition' => TeamMonthlyReportComposition::normalise( [ 'team_id' => $this->team_a, 'period' => 'last_month', 'layout' => 'B' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => $recipients,
            'format'      => 'pdf',
        ], $this->owner );
        $this->assertGreaterThan( 0, $id );
        $this->flush();
        ScheduledReportsRunner::runTeamMonthly( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );
        return $id;
    }

    /** @param list<string> $recipients */
    private function runPlayer( array $recipients ): int {
        $id = $this->repo->create( [
            'name'        => 'Player PDF Anna',
            'report_key'  => ScheduledReportsRepository::REPORT_PLAYER,
            'composition' => PlayerReportComposition::normalise( [ 'player_id' => $this->player_a, 'layout' => 'A' ] ),
            'frequency'   => ScheduledReportsRepository::FREQUENCY_MONTHLY_FIRST,
            'recipients'  => $recipients,
            'format'      => 'pdf',
        ], $this->owner );
        $this->assertGreaterThan( 0, $id );
        $this->flush();
        ScheduledReportsRunner::runPlayerReport( $this->repo, (array) $this->repo->findById( $id ), gmdate( 'Y-m-01' ) . ' 06:00:00' );
        return $id;
    }

    private function lastError( int $id ): ?string {
        $row = $this->repo->findById( $id );
        $this->assertNotNull( $row );
        return $row['last_error'];
    }

    private function person( int $user_id, string $role_type ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => CurrentClub::id(), 'first_name' => ucfirst( $role_type ), 'last_name' => 'Pdf',
            'role_type' => $role_type, 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function coachOf( int $team, string $email ): int {
        global $wpdb;
        $user   = (int) self::factory()->user->create( [ 'role' => 'tt_coach', 'user_email' => $email ] );
        $person = $this->person( $user, 'head_coach' );
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
        wp_cache_flush();
    }
}
