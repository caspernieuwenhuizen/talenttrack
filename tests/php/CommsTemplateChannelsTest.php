<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateCatalog;
use TT\Modules\Comms\Template\TemplateChannels;
use TT\Modules\Comms\Template\TemplateGuide;
use TT\Modules\Comms\Template\TemplateInterface;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;

/**
 * #3112 — the Messages settings screen: channels as a control of their
 * own, and the copy that makes the screen readable.
 *
 * Enforcement is tested through `CommsService` rather than by reading a
 * boolean back, for the reason #2603's tests are: the property that
 * matters is that no caller can route around it.
 */
final class CommsTemplateChannelsTest extends WP_UnitTestCase {

    private ChannelSpyAdapter $push;
    private ChannelSpyAdapter $email;

    public function set_up(): void {
        parent::set_up();
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();

        TemplateRegistry::register( new MultiChannelSpyTemplate() );

        // Registration order is preference order, so push is tried first.
        $this->push  = new ChannelSpyAdapter( 'spy_push' );
        $this->email = new ChannelSpyAdapter( 'spy_email' );
        ChannelAdapterRegistry::register( $this->push );
        ChannelAdapterRegistry::register( $this->email );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( TemplateChannels::CONFIG_KEY, '' );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    private function request(): CommsRequest {
        return new CommsRequest(
            'multi_spy',
            'multi_spy',
            1,
            0,
            [ Recipient::parent( 0, 42, 'parent@example.test' ) ],
            [],
            null,
            true
        );
    }

    // -- enforcement -------------------------------------------------------

    public function test_nothing_blocked_uses_the_templates_first_channel(): void {
        $results = ( new CommsService() )->send( $this->request() );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 1, $this->push->sendCalls );
        $this->assertSame( 0, $this->email->sendCalls );
    }

    /**
     * The point of the control: an academy that has ruled out a channel
     * falls through to the next one it can reach the person on, rather
     * than the message failing.
     */
    public function test_a_blocked_channel_falls_through_to_the_next(): void {
        TemplateChannels::setBlocked( [ 'multi_spy' => [ 'spy_push' ] ] );

        $results = ( new CommsService() )->send( $this->request() );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 0, $this->push->sendCalls, 'A blocked channel must not be used.' );
        $this->assertSame( 1, $this->email->sendCalls );
    }

    /**
     * Blocking every channel would leave the send recording
     * `no_channel_available` — a fault, not a decision. The stored value
     * drops such an entry, so the message keeps travelling and the
     * academy is told to use the message's own switch instead.
     */
    public function test_blocking_every_channel_is_not_a_way_to_switch_a_message_off(): void {
        TemplateChannels::setBlocked( [ 'multi_spy' => [ 'spy_push', 'spy_email' ] ] );

        $this->assertSame( [], TemplateChannels::blocked(), 'The entry is dropped on write.' );

        $results = ( new CommsService() )->send( $this->request() );
        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
    }

    /**
     * Defence in depth: a value written straight into config — a forged
     * POST that skipped normalisation, a hand-edited row — must not
     * strand the message either.
     */
    public function test_a_hand_written_block_of_every_channel_is_ignored_on_read(): void {
        QueryHelpers::set_config(
            TemplateChannels::CONFIG_KEY,
            '{"multi_spy":["spy_push","spy_email"]}'
        );

        $this->assertSame(
            [ 'spy_push', 'spy_email' ],
            TemplateChannels::allowedFor( 'multi_spy', [ 'spy_push', 'spy_email' ] )
        );
    }

    public function test_the_switch_and_the_channels_are_separate_decisions(): void {
        TemplateChannels::setBlocked( [ 'multi_spy' => [ 'spy_push' ] ] );
        TemplateSwitch::setDisabled( [ 'multi_spy' ] );

        $results = ( new CommsService() )->send( $this->request() );

        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $results[0]->status );
        $this->assertSame( 0, $this->email->sendCalls, 'A switched-off message sends on no channel.' );
    }

    // -- stored-value hygiene ---------------------------------------------

    public function test_unknown_templates_and_channels_are_dropped(): void {
        $stored = TemplateChannels::normaliseStored(
            '{"multi_spy":["spy_push","not_a_channel"],"no_such_template":["spy_push"]}'
        );

        $this->assertSame( [ 'multi_spy' => [ 'spy_push' ] ], json_decode( $stored, true ) );
    }

    public function test_a_malformed_stored_value_blocks_nothing(): void {
        QueryHelpers::set_config( TemplateChannels::CONFIG_KEY, 'not json at all' );

        $this->assertSame( [], TemplateChannels::blocked() );
        $this->assertFalse( TemplateChannels::isBlocked( 'multi_spy', 'spy_push' ) );
    }

    /**
     * An empty stored value means "nothing ruled out", the same
     * direction `TemplateSwitch` stores in, so a channel added to a
     * template in a later release is allowed on upgrade.
     */
    public function test_a_channel_added_later_is_allowed_on_an_existing_install(): void {
        TemplateChannels::setBlocked( [ 'multi_spy' => [ 'spy_push' ] ] );

        $this->assertSame(
            [ 'spy_email', 'a_channel_added_next_release' ],
            TemplateChannels::allowedFor( 'multi_spy', [ 'spy_push', 'spy_email', 'a_channel_added_next_release' ] )
        );
    }

    // -- the guide ---------------------------------------------------------

    /**
     * Every switchable template the product ships needs a description,
     * or the screen goes back to being a list of keys for that row. The
     * next template added fails here rather than shipping unexplained.
     */
    public function test_every_switchable_shipped_template_is_described(): void {
        $missing = [];
        foreach ( TemplateCatalog::shippedSwitchableKeys() as $key ) {
            if ( TemplateGuide::forKey( $key ) === null ) $missing[] = $key;
        }

        $this->assertSame(
            [],
            $missing,
            'Add a TemplateGuide entry (what / who / when / family) for each of these.'
        );
    }

    public function test_every_guide_entry_names_a_real_family(): void {
        $families = array_keys( TemplateGuide::families() );

        foreach ( TemplateCatalog::shippedSwitchableKeys() as $key ) {
            $entry = TemplateGuide::forKey( $key );
            $this->assertNotNull( $entry );
            $this->assertContains( $entry['family'], $families, $key . ' names an unknown family.' );
        }
    }

    /**
     * A template with no guide entry still has to appear on the screen —
     * losing a row silently is worse than describing it thinly.
     */
    public function test_grouping_keeps_a_template_with_no_guide_entry(): void {
        $grouped = TemplateGuide::grouped( [ 'multi_spy' => new MultiChannelSpyTemplate() ] );

        $keys = [];
        foreach ( $grouped as $group ) {
            $keys = array_merge( $keys, array_keys( $group ) );
        }

        $this->assertSame( [ 'multi_spy' ], $keys );
    }

    public function test_channel_labels_fall_back_to_the_key(): void {
        $this->assertSame( 'Email', TemplateGuide::channelLabel( 'email' ) );
        $this->assertSame( 'spy_push', TemplateGuide::channelLabel( 'spy_push' ) );
    }
}

/** @phpstan-ignore-next-line test double */
final class MultiChannelSpyTemplate implements TemplateInterface {
    public function key(): string { return 'multi_spy'; }
    public function label(): string { return 'Multi-channel spy'; }
    public function supportedChannels(): array { return [ 'spy_push', 'spy_email' ]; }
    public function isEditable(): bool { return false; }
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [ 'Subject', 'Body' ];
    }
}

/** @phpstan-ignore-next-line test double */
final class ChannelSpyAdapter implements ChannelAdapterInterface {
    public int $sendCalls = 0;
    private string $key;

    public function __construct( string $key ) { $this->key = $key; }

    public function key(): string { return $this->key; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        $this->sendCalls++;
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, $this->key, $recipient );
    }
}
