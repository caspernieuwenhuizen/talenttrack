<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Comms\Channel\ChannelAdapterInterface;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\CommsService;
use TT\Modules\Comms\Domain\CommsRequest;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\CommsStatusLabels;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Template\TemplateInterface;
use TT\Modules\Comms\Template\TemplateRegistry;

/**
 * #3383 — `tt_comms_log` carries two facts, not one.
 *
 * The defect these pin: the same parent, with the same missing contact
 * details, logged `failed / no_address` at 10:00 and `quiet_hours` at
 * 22:00. Both statuses were true; only one of them told the sender what to
 * fix, and which one they got depended on the clock.
 *
 * So the assertions are about the pair. The status of every path is
 * unchanged — that is asserted, because changing it would rewrite what
 * historical rows mean — and `reachable` answers the question the status
 * was never able to.
 */
final class CommsReachabilityTest extends WP_UnitTestCase {

    private string $table;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_comms_log';

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        $wpdb->query( "DELETE FROM {$this->table}" );
    }

    public function tear_down(): void {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /* ---- the same recipient, at both hours ------------------------------ */

    public function test_an_unreachable_recipient_is_recorded_as_such_inside_quiet_hours(): void {
        $this->registerSpies();
        $this->setQuietHours( '00:00', '23:59' );

        $results = ( new CommsService() )->send( $this->request( [ $this->unreachable() ] ) );

        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $results[0]->status, 'the status is unchanged' );
        $this->assertFalse( $results[0]->reachable );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $row->status );
        $this->assertSame( 0, (int) $row->reachable, 'deferred AND unreachable — both facts on one row' );
    }

    public function test_the_same_recipient_is_recorded_as_such_outside_quiet_hours(): void {
        $this->registerSpies();

        // `urgent` rather than a quiet-hours window that happens to be shut:
        // the suite runs at whatever hour CI reaches it, and this assertion
        // is about the recipient, not the clock.
        $results = ( new CommsService() )->send( $this->request( [ $this->unreachable() ] ) );

        $this->assertSame( CommsResult::STATUS_FAILED, $results[0]->status );
        $this->assertSame( 'no_channel_available', $results[0]->errorCode );

        $row = $this->onlyRow();
        $this->assertSame( 0, (int) $row->reachable, 'the hour cannot change whether a family has a phone number' );
    }

    public function test_a_reachable_recipient_held_until_morning_is_not_flagged(): void {
        $this->registerSpies();
        $this->setQuietHours( '00:00', '23:59' );

        ( new CommsService() )->send( $this->quietHoursRequest( [ $this->reachable() ] ) );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_QUIET_HOURS, $row->status );
        $this->assertSame( 1, (int) $row->reachable );
    }

    public function test_a_delivered_send_records_the_recipient_as_reachable(): void {
        $this->registerSpies();

        ( new CommsService() )->send( $this->request( [ $this->reachable() ] ) );

        $row = $this->onlyRow();
        $this->assertSame( CommsResult::STATUS_SENT, $row->status );
        $this->assertSame( 1, (int) $row->reachable, 'the adapter reached them, so the fact is settled' );
    }

    /* ---- preflight and delivery cannot disagree ------------------------- */

    /**
     * The warning before the click and the row after it come from one
     * helper. Asserted for both answers: a test that only checked the
     * unreachable case would pass against a helper hard-wired to false.
     */
    public function test_preflight_and_delivery_agree_about_the_same_recipient(): void {
        foreach ( [ 'unreachable', 'reachable' ] as $which ) {
            global $wpdb;
            $wpdb->query( "DELETE FROM {$this->table}" );

            $this->registerSpies();
            $this->setQuietHours( '00:00', '23:59' );
            $recipient = $which === 'reachable' ? $this->reachable() : $this->unreachable();

            $preflight = ( new CommsService() )->preflight( $this->quietHoursRequest( [ $recipient ] ) );
            ( new CommsService() )->send( $this->quietHoursRequest( [ $recipient ] ) );

            $logged = $this->onlyRow()->reachable;
            $this->assertNotNull( $logged, 'a per-recipient path always establishes the fact' );
            $this->assertNotNull( $preflight[0]->reachable );
            $this->assertSame(
                $preflight[0]->reachable,
                (bool) $logged,
                "preflight and the logged row must agree for the {$which} recipient"
            );
        }
    }

    /* ---- what "not established" means ----------------------------------- */

    /**
     * The whole-send guards stop before any recipient is examined, so they
     * have nothing to claim. NULL is that absence — and it is what every
     * row written before this shipped carries, which is why it must not
     * read as "reachable" anywhere.
     */
    public function test_a_send_that_never_reached_the_recipient_leaves_the_fact_unestablished(): void {
        // Nothing registered — the template key cannot resolve, and the
        // guard writes one row per recipient without consulting anything.
        ( new CommsService() )->send( $this->request( [ $this->reachable() ] ) );

        $row = $this->onlyRow();
        $this->assertSame( 'unknown_template', $row->error_code );
        $this->assertNull( $row->reachable, 'a guard that looked at nobody must not claim anything' );
    }

    public function test_the_migration_leaves_rows_written_before_it_alone(): void {
        $this->insertLegacyRow();
        $this->assertNull( $this->onlyRow()->reachable );

        $migration = require dirname( __DIR__, 2 ) . '/database/migrations/0264_comms_log_reachable.php';
        $migration->up();

        $this->assertNull(
            $this->onlyRow()->reachable,
            'a row from last month cannot be re-asked, so it is not answered for'
        );
    }

    /* ---- what the reader is told ---------------------------------------- */

    public function test_the_log_view_renders_the_three_answers_differently(): void {
        $unestablished = CommsStatusLabels::reachabilityNote( 'quiet_hours', null );
        $unreachable   = CommsStatusLabels::reachabilityNote( 'quiet_hours', 0 );
        $contactable   = CommsStatusLabels::reachabilityNote( 'quiet_hours', 1 );

        $this->assertNotSame( '', $unestablished );
        $this->assertNotSame( $unestablished, $contactable, 'NULL must not read as reachable' );
        $this->assertNotSame( $unreachable, $contactable );

        $this->assertSame( 'problem', CommsStatusLabels::reachabilityTone( 0 ) );
        $this->assertSame( 'muted', CommsStatusLabels::reachabilityTone( null ) );

        // A message that arrived has answered the question by arriving.
        $this->assertSame( '', CommsStatusLabels::reachabilityNote( 'sent', 1 ) );
    }

    /* ---- fixtures -------------------------------------------------------- */

    private function registerSpies(): void {
        TemplateRegistry::register( new ReachSpyTemplate() );
        ChannelAdapterRegistry::register( new ReachSpyAdapter() );
    }

    /** @param Recipient[] $recipients */
    private function request( array $recipients ): CommsRequest {
        // urgent: quiet hours cannot decide the outcome of these.
        return new CommsRequest( 'reach_template', 'reach_template', 1, 0, $recipients, [], null, true );
    }

    /** @param Recipient[] $recipients */
    private function quietHoursRequest( array $recipients ): CommsRequest {
        return new CommsRequest( 'reach_template', 'reach_template', 1, 0, $recipients, [], null, false );
    }

    private function setQuietHours( string $start, string $end ): void {
        \TT\Infrastructure\Query\QueryHelpers::set_config( 'comms_quiet_hours_start', $start );
        \TT\Infrastructure\Query\QueryHelpers::set_config( 'comms_quiet_hours_end', $end );
    }

    /** A guardian the academy holds nothing for: no account, no address, no phone. */
    private function unreachable(): Recipient {
        return Recipient::parent( 0, 42, '', '' );
    }

    private function reachable(): Recipient {
        return Recipient::parent( 0, 42, 'parent@example.test' );
    }

    private function onlyRow(): object {
        global $wpdb;
        $rows = $wpdb->get_results( "SELECT * FROM {$this->table}" );
        $this->assertCount( 1, $rows );
        return $rows[0];
    }

    /** A row as it was written before migration 0264 — no reachability at all. */
    private function insertLegacyRow(): void {
        global $wpdb;
        $wpdb->insert( $this->table, [
            'club_id'      => 1,
            'uuid'         => wp_generate_uuid4(),
            'template_key' => 'reach_template',
            'message_type' => 'reach_template',
            'channel'      => 'spy',
            'payload_hash' => hash( 'sha256', 'body' ),
            'status'       => CommsResult::STATUS_QUIET_HOURS,
        ] );
    }
}

/** Minimal template so the service has something to resolve. */
final class ReachSpyTemplate implements TemplateInterface {
    public function key(): string { return 'reach_template'; }
    public function label(): string { return 'Reachability template'; }
    public function supportedChannels(): array { return [ 'reach_spy' ]; }
    public function isEditable(): bool { return false; }
    public function render( string $channelKey, CommsRequest $request, Recipient $recipient, string $locale ): array {
        return [ 'Subject', 'Body' ];
    }
}

/** Reaches anyone with an email address, like the real email adapter. */
final class ReachSpyAdapter implements ChannelAdapterInterface {
    public function key(): string { return 'reach_spy'; }
    public function canReach( Recipient $recipient ): bool { return $recipient->emailAddress !== ''; }
    public function send( CommsRequest $request, Recipient $recipient, string $uuid, string $renderedSubject, string $renderedBody ): CommsResult {
        return new CommsResult( $uuid, CommsResult::STATUS_SENT, 'reach_spy', $recipient );
    }
}
