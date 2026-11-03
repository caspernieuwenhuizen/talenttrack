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
use TT\Modules\Comms\Templates\NotificationTemplate;

/**
 * #4861: the dispatcher's clock reaches the quiet-hours decision.
 *
 * Every sender reaches Comms through `CommsDispatcher`, which builds its own
 * `CommsService`. `CommsDispatcher::useClock()` is how a test freezes the
 * time that service reads. With no clock given, the system clock decides,
 * as before.
 */
final class CommsDispatcherClockTest extends WP_UnitTestCase {

    use FrozenCommsClock;

    public function set_up(): void {
        parent::set_up();
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new NotificationTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        add_filter( 'tt_comms_email_send', '__return_true' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    public function test_a_frozen_clock_inside_quiet_hours_holds_the_message_on_both_sides_of_midnight(): void {
        foreach ( [ '23:59:30', '00:00:30' ] as $time ) {
            $this->freezeCommsClockAt( $time );
            $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $this->send(), "not held at {$time}" );
        }
    }

    public function test_a_frozen_clock_outside_quiet_hours_sends_the_message(): void {
        $this->freezeCommsClockAt( '12:00:00' );
        $this->assertSame( CommsResult::STATUS_SENT, $this->send() );
    }

    public function test_without_a_clock_the_system_clock_decides(): void {
        $this->freezeCommsClockAt( '12:00:00' );
        $this->releaseCommsClock();
        QueryHelpers::set_config( 'comms_quiet_hours_start', '21:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '07:00' );

        $minutes = (int) wp_date( 'G' ) * 60 + (int) wp_date( 'i' );
        $quiet   = $minutes >= 21 * 60 || $minutes < 7 * 60;
        $status  = $this->send();

        // The minute can turn between the two reads; only a status that
        // matches neither answer is wrong.
        $this->assertContains( $status, [ CommsResult::STATUS_QUIET_HOURS, CommsResult::STATUS_SENT ] );
        if ( $minutes !== 21 * 60 - 1 && $minutes !== 7 * 60 - 1 ) {
            $this->assertSame( $quiet ? CommsResult::STATUS_QUIET_HOURS : CommsResult::STATUS_SENT, $status );
        }
    }

    private function send(): string {
        $user    = (int) self::factory()->user->create();
        $email   = (string) get_userdata( $user )->user_email;
        $results = CommsDispatcher::dispatchSync(
            NotificationTemplate::KEY,
            [ 'title' => 'Clock', 'body' => 'Frozen.', 'url' => '', 'event' => 'test' ],
            [ Recipient::self( $user, $email ) ],
            [ 'message_type' => MessageType::NOTIFICATION, 'force_channel' => 'email', 'sender_user_id' => 0 ]
        );
        $this->assertCount( 1, $results );
        return $results[0]->status;
    }
}
