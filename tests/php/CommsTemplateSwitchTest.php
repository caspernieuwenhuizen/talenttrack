<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\OptOut\OptOutPolicy;
use TT\Modules\Comms\Template\TemplateInterface;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;

/**
 * #2603 (epic #2600) — per-template kill switch + opt-out.
 *
 * The switch is enforced inside `CommsService` rather than at the call
 * site, so these tests go through the orchestrator: that is the property
 * that matters (no caller can route around it), not that a boolean reads
 * back correctly.
 *
 * Two things the switch must NOT do: suppress the audit row (an academy
 * still needs to see that a message would have gone out), and silently
 * mute a safeguarding message (operational types are exempt from opt-out
 * by contract).
 */
final class CommsTemplateSwitchTest extends WP_UnitTestCase {

    private string $table;
    private SwitchSpyAdapter $adapter;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new SwitchSpyTemplate() );
        $this->adapter = new SwitchSpyAdapter();
        ChannelAdapterRegistry::register( $this->adapter );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );

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

    private function request(): CommsRequest {
        // urgent: quiet hours must not decide the outcome of these tests.
        return new CommsRequest(
            'switch_spy',
            'switch_spy',
            1,
            0,
            [ Recipient::parent( 0, 42, 'parent@example.test' ) ],
            [],
            null,
            true
        );
    }

    // -- default state -----------------------------------------------------

    public function test_templates_are_enabled_by_default(): void {
        $this->assertTrue( TemplateSwitch::isEnabled( 'switch_spy' ) );
        $this->assertSame( [], TemplateSwitch::disabledKeys() );
    }

    /**
     * The stored value is the DISABLED set, so a template registered by a
     * later release must be on without touching stored config.
     */
    public function test_an_unknown_template_is_enabled_when_others_are_disabled(): void {
        TemplateSwitch::setDisabled( [ 'switch_spy' ] );
        $this->assertFalse( TemplateSwitch::isEnabled( 'switch_spy' ) );
        $this->assertTrue( TemplateSwitch::isEnabled( 'some_future_template' ) );
    }

    // -- enforcement -------------------------------------------------------

    public function test_disabled_template_is_not_dispatched(): void {
        TemplateSwitch::setDisabled( [ 'switch_spy' ] );

        $results = ( new CommsService() )->send( $this->request() );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $results[0]->status );
        $this->assertSame( 0, $this->adapter->sendCalls, 'A disabled template must not reach an adapter.' );
    }

    public function test_disabled_template_still_writes_an_audit_row(): void {
        TemplateSwitch::setDisabled( [ 'switch_spy' ] );

        ( new CommsService() )->send( $this->request() );

        $rows = $this->rows();
        $this->assertCount( 1, $rows, 'The switch suppresses the message, never the evidence.' );
        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $rows[0]->status );
        $this->assertSame( 'switch_spy', $rows[0]->template_key );
    }

    /**
     * Regression guard for migration 0220.
     *
     * `status` was VARCHAR(16) and `template_disabled` is 17 characters, so
     * strict-mode MySQL rejected the row outright — switching a template off
     * wrote no audit row at all, reintroducing by column width exactly the
     * silence #2602 removed. `$wpdb->insert()` signals that by returning
     * false rather than throwing, which is why nothing noticed.
     *
     * Asserts the column can hold every status the vocabulary defines, so
     * the next one added fails here rather than in production.
     */
    public function test_status_column_fits_every_defined_status(): void {
        global $wpdb;

        $column = $wpdb->get_row( "SHOW COLUMNS FROM {$this->table} LIKE 'status'" );
        $this->assertNotNull( $column, 'tt_comms_log.status must exist.' );
        $this->assertSame( 1, preg_match( '/varchar\((\d+)\)/i', (string) $column->Type, $m ) );
        $width = (int) $m[1];

        $statuses = [
            CommsResult::STATUS_QUEUED,
            CommsResult::STATUS_SENT,
            CommsResult::STATUS_DELIVERED,
            CommsResult::STATUS_BOUNCED,
            CommsResult::STATUS_FAILED,
            CommsResult::STATUS_OPTED_OUT,
            CommsResult::STATUS_QUIET_HOURS,
            CommsResult::STATUS_RATE_LIMITED,
            CommsResult::STATUS_NO_RECIPIENTS,
            CommsResult::STATUS_TEMPLATE_DISABLED,
            CommsResult::STATUS_EXCEPTION,
        ];

        foreach ( $statuses as $status ) {
            $this->assertLessThanOrEqual(
                $width,
                strlen( $status ),
                sprintf( 'Status "%s" (%d chars) does not fit tt_comms_log.status VARCHAR(%d).', $status, strlen( $status ), $width )
            );
        }
    }

    public function test_enabled_template_still_sends(): void {
        $results = ( new CommsService() )->send( $this->request() );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( 1, $this->adapter->sendCalls );
    }

    public function test_preflight_reports_a_disabled_template_before_sending(): void {
        TemplateSwitch::setDisabled( [ 'switch_spy' ] );

        $results = ( new CommsService() )->preflight( $this->request() );

        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $results[0]->status );
        $this->assertCount( 0, $this->rows(), 'Preflight must not write audit rows.' );
    }

    // -- stored-value hygiene ---------------------------------------------

    public function test_unregistered_keys_are_dropped_on_normalise(): void {
        $stored = TemplateSwitch::normaliseStored( '["switch_spy","no_such_template"]' );
        $this->assertSame( [ 'switch_spy' ], json_decode( $stored, true ) );
    }

    public function test_a_malformed_stored_value_disables_nothing(): void {
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, 'not json at all' );
        $this->assertTrue(
            TemplateSwitch::isEnabled( 'switch_spy' ),
            'A malformed config value must fail open, not mute every message.'
        );
    }

    // -- opt-out -----------------------------------------------------------

    public function test_opt_out_suppresses_a_normal_message_type(): void {
        $user_id = self::factory()->user->create();
        $policy  = new OptOutPolicy();

        $policy->setOptedOut( $user_id, MessageType::GOAL_NUDGE, true );

        $this->assertTrue( $policy->isOptedOut( $user_id, MessageType::GOAL_NUDGE ) );
    }

    /**
     * The settings form renders operational rows as disabled checkboxes;
     * this pins the server-side half, so a forged POST cannot mute a
     * safeguarding message.
     */
    public function test_operational_types_cannot_be_opted_out_of(): void {
        $user_id = self::factory()->user->create();
        $policy  = new OptOutPolicy();

        $policy->setOptedOut( $user_id, MessageType::SAFEGUARDING_BROADCAST, true );

        $this->assertFalse(
            $policy->isOptedOut( $user_id, MessageType::SAFEGUARDING_BROADCAST ),
            'A safeguarding broadcast must remain deliverable however the preference was submitted.'
        );
    }

    public function test_opting_back_in_clears_the_preference(): void {
        $user_id = self::factory()->user->create();
        $policy  = new OptOutPolicy();

        $policy->setOptedOut( $user_id, MessageType::GOAL_NUDGE, true );
        $policy->setOptedOut( $user_id, MessageType::GOAL_NUDGE, false );

        $this->assertFalse( $policy->isOptedOut( $user_id, MessageType::GOAL_NUDGE ) );
        $this->assertSame( '', (string) get_user_meta( $user_id, 'tt_comms_optout_' . MessageType::GOAL_NUDGE, true ) );
    }
}

/** @phpstan-ignore-next-line test double */
final class SwitchSpyTemplate implements TemplateInterface {
    public function key(): string { return 'switch_spy'; }
    public function label(): string { return 'Switch spy'; }
    public function supportedChannels(): array { return [ 'switch_spy_channel' ]; }
    public function isEditable(): bool { return false; }
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [ 'Subject', 'Body' ];
    }
}

/** @phpstan-ignore-next-line test double */
final class SwitchSpyAdapter implements ChannelAdapterInterface {
    public int $sendCalls = 0;

    public function key(): string { return 'switch_spy_channel'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        $this->sendCalls++;
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'switch_spy_channel', $recipient );
    }
}
