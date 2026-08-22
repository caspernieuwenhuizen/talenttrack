<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Push\Dispatchers\EmailDispatcher;

/**
 * #2604 (epic #2600) — the Push module's email path routes through Comms.
 *
 * Notifications used to call `wp_mail()` directly, so they bypassed
 * opt-out, quiet hours, the rate limit and the audit log. These tests
 * pin the two properties that matter after the port:
 *
 *   1. Every notification leaves an audit row.
 *   2. The Comms result maps onto `DispatcherInterface::deliver()`'s
 *      boolean correctly — a policy skip is NOT a delivery failure, or
 *      DispatcherChain would fall through to the next channel and then
 *      record a `dispatch_dropped` for a message Comms deliberately held.
 */
final class CommsPushChokepointTest extends WP_UnitTestCase {

    private string $table;
    private int $user_id;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';

        $this->user_id = self::factory()->user->create( [ 'user_email' => 'coach@example.test' ] );

        // Register exactly what this path needs rather than relying on
        // CommsModule::boot() having run. The registries are static, and
        // sibling Comms tests clear them, so depending on boot order would
        // make this suite pass or fail on the order PHPUnit happens to pick.
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new NotificationTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // Pin the window shut so the wall clock cannot decide these tests.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /** @return object[] */
    private function rows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table}" );
    }

    /** @return array<string,mixed> */
    private function context(): array {
        return [
            'user_id' => $this->user_id,
            'title'   => 'A task is waiting for you',
            'body'    => 'Review the U13 evaluations.',
            'url'     => 'https://example.test/?tt_view=tasks',
            'event'   => 'task_assigned',
        ];
    }

    /**
     * Stand in for a configured mail provider via the adapter's documented
     * `tt_comms_email_send` hook.
     *
     * The happy path otherwise ends in `wp_mail()`, whose return value under
     * the CI mailer is WordPress's business, not this port's. What is being
     * tested here is that a *successful* send is claimed and audited, so the
     * provider is simulated and the assertion stays on our own logic.
     */
    private function withMailProvider(): void {
        add_filter( 'tt_comms_email_send', '__return_true', 10, 2 );
    }

    public function test_a_notification_is_audited(): void {
        $this->withMailProvider();

        $delivered = ( new EmailDispatcher() )->deliver( $this->context() );

        $this->assertTrue( $delivered );

        $rows = $this->rows();
        $this->assertCount( 1, $rows, 'A notification that used to bypass Comms must now leave a row.' );
        $this->assertSame( 'notification', $rows[0]->template_key );
        $this->assertSame( MessageType::NOTIFICATION, $rows[0]->message_type );
        $this->assertSame( (string) $this->user_id, (string) $rows[0]->recipient_user_id );
        $this->assertSame( 'email', $rows[0]->channel );
        $this->assertSame( CommsResult::STATUS_SENT, $rows[0]->status );
    }

    /**
     * The mapping that matters. An opted-out recipient is a deliberate
     * non-send, not a failed one: `deliver()` must return true so
     * DispatcherChain stops rather than trying the next channel and then
     * recording a drop.
     */
    public function test_an_opted_out_recipient_is_claimed_not_dropped(): void {
        ( new OptOutPolicy() )->setOptedOut( $this->user_id, MessageType::NOTIFICATION, true );

        $delivered = ( new EmailDispatcher() )->deliver( $this->context() );

        $this->assertTrue( $delivered, 'A policy skip must not read as a delivery failure.' );

        $rows = $this->rows();
        $this->assertCount( 1, $rows );
        $this->assertSame( CommsResult::STATUS_OPTED_OUT, $rows[0]->status );
    }

    public function test_quiet_hours_hold_is_claimed_not_dropped(): void {
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '23:59' );

        $delivered = ( new EmailDispatcher() )->deliver( $this->context() );

        $this->assertTrue( $delivered, 'A deferred message is held, not dropped.' );
        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $this->rows()[0]->status );
    }

    /**
     * The academy switch reaches notifications too — and, like every
     * other suppressed send, still leaves evidence.
     */
    public function test_the_academy_switch_stops_notification_email(): void {
        TemplateSwitch::setDisabled( [ 'notification' ] );

        $delivered = ( new EmailDispatcher() )->deliver( $this->context() );

        $this->assertTrue( $delivered, 'A switched-off template is a deliberate non-send.' );
        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $this->rows()[0]->status );
    }

    public function test_a_user_without_an_email_is_not_applicable(): void {
        $no_email = self::factory()->user->create();
        wp_update_user( [ 'ID' => $no_email, 'user_email' => '' ] );

        $applicable = ( new EmailDispatcher() )->applicableTo( [ 'user_id' => $no_email ] );

        $this->assertFalse( $applicable, 'The chain must still be able to fall through to another channel.' );
    }

    public function test_the_notification_body_carries_title_body_and_url(): void {
        $this->withMailProvider();
        ( new EmailDispatcher() )->deliver( $this->context() );

        $rows = $this->rows();
        $this->assertSame( 'A task is waiting for you', $rows[0]->subject );
        // The body itself is never stored — only its hash (GDPR, #0066).
        $this->assertNotEmpty( $rows[0]->payload_hash );
        $this->assertNotSame( hash( 'sha256', '' ), $rows[0]->payload_hash );
    }
}
