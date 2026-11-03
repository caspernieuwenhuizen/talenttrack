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

    /** @var list<string> */
    private array $locales = [];

    /** @var list<string>|null */
    private ?array $availableLanguages = null;

    public function set_up(): void {
        parent::set_up();
        ( new RolesService() )->installRoles();
        ( new RolesService() )->ensureCapabilities();
        global $wpdb;
        $wpdb->hide_errors();

        // A KPI whose own filter names a field its fact does not have.
        KpiRegistry::register( new Kpi( self::BROKEN_KPI, 'Broken test KPI', 'goals', 'count', [ 'decided_by_eq' => 1 ] ) );

        $this->owner = $this->clubAccount( 'owner-4874@example.test' );

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
        remove_action( 'switch_locale', [ $this, 'captureLocale' ] );
        while ( is_locale_switched() ) restore_previous_locale();
        if ( $this->availableLanguages !== null ) {
            $prop = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
            $prop->setAccessible( true );
            $prop->setValue( $GLOBALS['wp_locale_switcher'], $this->availableLanguages );
            $this->availableLanguages = null;
        }
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

    public function test_an_owner_who_has_left_the_club_is_not_told(): void {
        // A colleague still in the club receives the report, so the run
        // reaches the KPI read and finds the missing field.
        $colleague = $this->clubAccount( 'colleague-4874@example.test' );
        $this->assertGreaterThan( 0, $colleague );
        $id = $this->schedule( [ 'colleague-4874@example.test' ] );

        // The owner has left: the account remains, the person in this club does not.
        global $wpdb;
        $wpdb->delete( "{$wpdb->prefix}tt_people", [ 'wp_user_id' => $this->owner ] );
        $this->flush();

        $this->runDue( $id );

        $this->assertNotNull( $this->repo->findById( $id )['last_error'] ?? null, 'fixture: the run found the missing field' );
        $this->assertSame( [], $this->noticesTo( 'owner-4874@example.test' ), 'an owner who has left is not mailed' );
        $this->assertSame( 0, $this->loggedNotices(), 'nor told in-app' );
    }

    public function test_the_message_is_rendered_in_the_owners_language(): void {
        // The test install ships no core language packs, so the switcher
        // would refuse nl_NL; a real Dutch site has it.
        $switcher = $GLOBALS['wp_locale_switcher'];
        $prop     = new \ReflectionProperty( \WP_Locale_Switcher::class, 'available_languages' );
        $prop->setAccessible( true );
        $this->availableLanguages = (array) $prop->getValue( $switcher );
        $prop->setValue( $switcher, array_values( array_unique( array_merge( $this->availableLanguages, [ 'nl_NL' ] ) ) ) );

        update_user_meta( $this->owner, 'locale', 'nl_NL' );
        $this->assertSame( 'en_US', determine_locale(), 'fixture: the run itself is English' );
        add_action( 'switch_locale', [ $this, 'captureLocale' ] );

        $this->runDue( $this->schedule() );

        $this->assertCount( 1, $this->noticesTo( 'owner-4874@example.test' ) );
        $this->assertContains( 'nl_NL', $this->locales, "the copy is written in the owner's language" );
        $this->assertFalse( is_locale_switched(), 'and the run gets its own language back' );
    }

    public function captureLocale( string $locale ): void {
        $this->locales[] = $locale;
    }

    public function test_the_message_type_waits_for_quiet_hours(): void {
        $this->assertFalse( MessageType::isOperational( MessageType::SCHEDULED_REPORT_NOT_SENT ) );
        $this->assertFalse( MessageType::bypassesQuietHours( MessageType::SCHEDULED_REPORT_NOT_SENT ), 'unlike the delivery, it carries no file and can wait' );
    }

    // ---- helpers ------------------------------------------------------------

    /** @param list<string> $recipients */
    private function schedule( array $recipients = [ 'owner-4874@example.test' ] ): int {
        $id = $this->repo->create( [
            'name'       => 'Not sent 4874',
            'kpi_key'    => self::BROKEN_KPI,
            'frequency'  => ScheduledReportsRepository::FREQUENCY_WEEKLY_MONDAY,
            'recipients' => $recipients,
        ], $this->owner );
        $this->assertGreaterThan( 0, $id );
        return $id;
    }

    /**
     * An account with a person in this club, so the schedule treats the
     * address as that account and reaches the KPI read.
     */
    private function clubAccount( string $email ): int {
        global $wpdb;
        $user_id = (int) self::factory()->user->create( [ 'role' => 'tt_head_dev', 'user_email' => $email ] );
        $wpdb->insert( "{$wpdb->prefix}tt_people", [
            'club_id' => (int) \TT\Infrastructure\Tenancy\CurrentClub::id(), 'first_name' => 'Staff', 'last_name' => 'Schedule',
            'role_type' => 'head_of_development', 'wp_user_id' => $user_id, 'status' => 'active',
        ] );
        $this->flush();
        return $user_id;
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
