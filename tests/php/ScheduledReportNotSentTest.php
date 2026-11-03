<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Modules\Analytics\Cron\ScheduledReportsRunner;
use TT\Modules\Analytics\Domain\FactScope;
use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\ScheduledReportsRepository;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\ScheduledReportNotSentTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * #4874 — a scheduled report that was not sent, because its KPI refers to a
 * field that no longer exists (#4860), tells its owner once per breakage:
 * on the first skipped run, not on the runs after, and again once the error
 * has cleared and the schedule breaks again. The message names the report
 * and the field and links to the schedule.
 */
final class ScheduledReportNotSentTest extends WP_UnitTestCase {

    use QuietHoursClosed;

    private const BROKEN_KPI = 'test_broken_kpi_4874';

    private ScheduledReportsRepository $repo;
    private int $owner = 0;

    /** @var list<array<string,mixed>> */
    private array $mail = [];

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();

        // A KPI whose own filter names a field its fact does not have.
        KpiRegistry::register( new Kpi( self::BROKEN_KPI, 'Broken test KPI', 'goals', 'count', [ 'decided_by_eq' => 1 ] ) );

        $this->owner = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => 'owner-4874@example.test' ] );
        // A person in this club, so the schedule treats the address as the
        // owner's account and reaches the KPI read.
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) \TT\Infrastructure\Tenancy\CurrentClub::id(), 'first_name' => 'Owner', 'last_name' => 'Schedule',
            'role_type' => 'head_of_development', 'wp_user_id' => $this->owner, 'status' => 'active',
        ] );
        $this->flush();

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new ScheduledReportTemplate() );
        TemplateRegistry::register( new ScheduledReportNotSentTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        $this->closeQuietHours();

        $this->repo = new ScheduledReportsRepository();
        $this->mail = [];
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        $all = KpiRegistry::all();
        unset( $all[ self::BROKEN_KPI ] );
        KpiRegistry::clear();
        foreach ( $all as $kpi ) KpiRegistry::register( $kpi );
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
        $this->mail[] = $payload;
        return true;
    }

    public function test_the_owner_is_told_once_per_breakage(): void {
        $id = $this->schedule();

        for ( $run = 1; $run <= 3; $run++ ) $this->runDue( $id );

        $notices = $this->noticesTo( 'owner-4874@example.test' );
        $this->assertCount( 1, $notices, 'one message over three skipped runs' );
        $this->assertStringContainsString( 'Not sent 4874', (string) $notices[0]['body'], 'it names the report' );
        $this->assertStringContainsString( 'decided_by', (string) $notices[0]['body'], 'and the missing field' );
        $this->assertStringContainsString( 'tt_view=scheduled-reports', (string) $notices[0]['body'], 'and links to the schedule' );
        $this->assertNotNull( $this->repo->findById( $id )['last_error'] ?? null, 'the reason stays on the schedule' );
        $this->assertSame( [], $this->reportsTo( 'owner-4874@example.test' ), 'the wrong figure is never sent' );

        $this->assertSame( 1, $this->loggedNotices(), 'the message log has the one message' );
    }

    public function test_after_the_error_clears_a_new_breakage_tells_the_owner_again(): void {
        $id = $this->schedule();
        $this->runDue( $id );
        $this->runDue( $id );
        $this->assertCount( 1, $this->noticesTo( 'owner-4874@example.test' ) );

        // Paused and resumed: the reason is cleared, as resuming does.
        $this->repo->clearError( $id );
        $this->runDue( $id );
        $this->assertCount( 2, $this->noticesTo( 'owner-4874@example.test' ), 'the breakage after the clear is a new one' );
    }

    public function test_the_message_type_waits_for_quiet_hours(): void {
        $this->assertFalse( MessageType::isOperational( MessageType::SCHEDULED_REPORT_NOT_SENT ) );
        $this->assertFalse( MessageType::bypassesQuietHours( MessageType::SCHEDULED_REPORT_NOT_SENT ), 'unlike the delivery, it carries no file and can wait' );
    }

    // ---- helpers ------------------------------------------------------------

    private function schedule(): int {
        $id = $this->repo->create( [
            'name'       => 'Not sent 4874',
            'kpi_key'    => self::BROKEN_KPI,
            'frequency'  => ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
            'recipients' => [ 'owner-4874@example.test' ],
        ], $this->owner );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    private function runDue( int $id ): void {
        global $wpdb;
        $wpdb->update(
            $wpdb->prefix . 'tt_scheduled_reports',
            [ 'next_run_at' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ],
            [ 'id' => $id ]
        );
        $this->flush();
        ScheduledReportsRunner::run();
    }

    /** @return list<array<string,mixed>> */
    private function noticesTo( string $email ): array {
        return array_values( array_filter( $this->mail, static fn( array $m ): bool =>
            strtolower( (string) ( $m['to'] ?? '' ) ) === $email && empty( $m['attachments'] )
        ) );
    }

    /** @return list<array<string,mixed>> */
    private function reportsTo( string $email ): array {
        return array_values( array_filter( $this->mail, static fn( array $m ): bool =>
            strtolower( (string) ( $m['to'] ?? '' ) ) === $email && ! empty( $m['attachments'] )
        ) );
    }

    private function loggedNotices(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_comms_log WHERE message_type = %s",
            MessageType::SCHEDULED_REPORT_NOT_SENT
        ) );
    }

    private function flush(): void {
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
        FactScope::flush();
        wp_cache_flush();
    }
}
