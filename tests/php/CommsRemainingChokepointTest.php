<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\DirectMessageTemplate;
use TT\Modules\Comms\Templates\ScheduledReportTemplate;

/**
 * #2604 — the last of the direct `wp_mail()` senders.
 *
 * Two properties worth pinning, because both were assumptions the port
 * made rather than behaviour that already existed:
 *
 *   1. A caller-composed message (the in-product composer) survives the
 *      template layer verbatim — Comms must not reword what a coach
 *      typed — and still leaves an audit row.
 *   2. A file the caller has already written reaches the transport, and
 *      a path that has gone missing degrades to a send without the
 *      attachment rather than to no send at all.
 */
final class CommsRemainingChokepointTest extends WP_UnitTestCase {

    private string $table;
    private int $user_id;

    /** @var array<string,mixed>|null last payload the transport filter saw */
    private ?array $captured = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';

        $this->user_id = self::factory()->user->create( [ 'user_email' => 'parent@example.test' ] );

        // Register exactly what these paths need rather than relying on
        // CommsModule::boot(); the registries are static and sibling Comms
        // tests clear them.
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new DirectMessageTemplate() );
        TemplateRegistry::register( new ScheduledReportTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // Pin the window shut so the wall clock cannot decide these tests.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $wpdb->query( "DELETE FROM {$this->table}" );

        $this->captured = null;
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /**
     * Stands in for the mail transport so the tests can read what the
     * adapter handed over without sending anything.
     *
     * @param mixed                $accepted
     * @param array<string,mixed>  $args
     */
    public function captureSend( $accepted, $args ): bool {
        $this->captured = $args;
        return true;
    }

    private function recipient(): Recipient {
        return Recipient::self( $this->user_id, 'parent@example.test' );
    }

    /** @return object[] */
    private function rows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->table}" );
    }

    public function test_composer_copy_reaches_the_transport_unchanged(): void {
        $results = CommsDispatcher::dispatchSync(
            DirectMessageTemplate::KEY,
            [
                'subject' => 'About Saturday',
                'body'    => "Hi,\n\nLucas played well. Let's talk Saturday.",
            ],
            [ $this->recipient() ],
            [ 'message_type' => MessageType::DIRECT_MESSAGE ]
        );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );

        $this->assertIsArray( $this->captured );
        $this->assertSame( 'About Saturday', $this->captured['subject'] );
        $this->assertSame( "Hi,\n\nLucas played well. Let's talk Saturday.", $this->captured['body'] );

        $rows = $this->rows();
        $this->assertCount( 1, $rows, 'A hand-written email must leave an audit row like any other.' );
        $this->assertSame( MessageType::DIRECT_MESSAGE, $rows[0]->message_type );
    }

    public function test_an_attachment_the_caller_wrote_reaches_the_transport(): void {
        $path = wp_tempnam( 'tt-report-test.csv' );
        file_put_contents( $path, "player,minutes\nLucas,64\n" );

        CommsDispatcher::dispatchSync(
            ScheduledReportTemplate::KEY,
            [ 'schedule_name' => 'Weekly minutes', 'kpi_label' => 'Minutes played' ],
            [ $this->recipient() ],
            [
                'message_type' => MessageType::SCHEDULED_REPORT,
                'attachments'  => [ $path ],
            ]
        );

        $this->assertIsArray( $this->captured );
        $this->assertSame( [ $path ], $this->captured['attachments'] );

        @unlink( $path );
    }

    public function test_a_missing_attachment_still_sends_the_message(): void {
        $results = CommsDispatcher::dispatchSync(
            ScheduledReportTemplate::KEY,
            [ 'schedule_name' => 'Weekly minutes', 'kpi_label' => 'Minutes played' ],
            [ $this->recipient() ],
            [
                'message_type' => MessageType::SCHEDULED_REPORT,
                'attachments'  => [ '/no/such/file-tt-2604.csv' ],
            ]
        );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertIsArray( $this->captured );
        $this->assertSame( [], $this->captured['attachments'] );
    }

    public function test_the_desktop_link_is_operational(): void {
        // The user asked for it seconds ago and is waiting: an opt-out or a
        // quiet-hours window holding it would turn a working button into a
        // silently broken one.
        $this->assertTrue( MessageType::isOperational( MessageType::DESKTOP_LINK ) );
        $this->assertTrue( MessageType::bypassesQuietHours( MessageType::DESKTOP_LINK ) );
    }

    public function test_a_hand_written_email_is_not_operational(): void {
        // Someone who asked the academy not to email them means it when a
        // coach types the message too.
        $this->assertFalse( MessageType::isOperational( MessageType::DIRECT_MESSAGE ) );
    }
}
