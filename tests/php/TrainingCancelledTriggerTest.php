<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Repositories\ActivitiesRepository;
use TT\Modules\Activities\Services\ActivityLifecycle;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Send\TrainingCancelledSend;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrainingCancelledTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;

/**
 * #3081 — the training-cancelled message could never send.
 *
 * The template shipped naming `tt_activity_cancelled` as its trigger and
 * nothing raised that hook. Behind it sat the reason the fix is not a
 * one-liner: cancellation reaches the row down two independent write
 * paths — the detail view's Cancel button (`setStatus()`) and an edit
 * that sets the status to cancelled (`update()`, reached from both REST
 * and wp-admin). An event fired from only one of them is the failure
 * where half the families are told and half are not, and it looks
 * correct in testing because the button is the path a tester uses.
 *
 * So the properties worth pinning are about the transition, not about
 * the copy: every path fires, each fires once, and re-saving a row that
 * already reads cancelled fires nothing.
 */
final class TrainingCancelledTriggerTest extends WP_UnitTestCase {

    private string $p;
    private int $club;
    private string $log_table;

    /** @var int[] activity ids seen on `tt_activity_cancelled` */
    private array $fired = [];

    /** @var array<string,mixed>|null last payload the transport filter saw */
    private ?array $captured = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p         = $wpdb->prefix;
        $this->club      = (int) CurrentClub::id();
        $this->log_table = $wpdb->prefix . 'tt_comms_log';

        $this->fired    = [];
        $this->captured = null;

        add_action( 'tt_activity_cancelled', [ $this, 'recordFired' ], 10, 1 );
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );

        // Only what these paths need: the sibling Comms tests clear the
        // static registries, so relying on CommsModule::boot() would make
        // this suite order-dependent.
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new TrainingCancelledTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        $wpdb->query( "DELETE FROM {$this->log_table}" );
    }

    public function tear_down(): void {
        remove_action( 'tt_activity_cancelled', [ $this, 'recordFired' ], 10 );
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    public function recordFired( int $activity_id ): void {
        $this->fired[] = $activity_id;
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureSend( $accepted, $args ): bool {
        $this->captured = $args;
        return true;
    }

    // --- the two write paths -------------------------------------------

    public function test_the_cancel_button_fires_the_event_once(): void {
        $activity = $this->insertActivity( 'planned', 'scheduled' );

        ( new ActivitiesRepository() )->setStatus( $activity, 'cancelled' );

        $this->assertSame( [ $activity ], $this->fired );
    }

    public function test_editing_the_activity_to_cancelled_fires_the_event_once(): void {
        // The REST edit form and the wp-admin activities page both land
        // here. This is the path that fired nothing at all before.
        $activity = $this->insertActivity( 'planned', 'scheduled' );

        ( new ActivitiesRepository() )->update( $activity, [
            'activity_status_key' => 'cancelled',
            'plan_state'          => 'cancelled',
        ] );

        $this->assertSame( [ $activity ], $this->fired );
    }

    public function test_resaving_an_already_cancelled_activity_fires_nothing(): void {
        // A parent told yesterday must not be told again because an
        // operator re-opened the form and pressed Save.
        $activity = $this->insertActivity( 'cancelled', 'cancelled' );

        $repo = new ActivitiesRepository();
        $repo->update( $activity, [ 'activity_status_key' => 'cancelled', 'title' => 'Renamed' ] );
        $repo->setStatus( $activity, 'cancelled' );

        $this->assertSame( [], $this->fired );
    }

    public function test_a_non_cancelling_edit_fires_nothing(): void {
        $activity = $this->insertActivity( 'planned', 'scheduled' );

        $repo = new ActivitiesRepository();
        $repo->update( $activity, [ 'title' => 'Moved indoors' ] );
        $repo->update( $activity, [ 'activity_status_key' => 'completed' ] );
        $repo->setStatus( $activity, 'planned' );

        $this->assertSame( [], $this->fired );
    }

    // --- the lifecycle columns agreeing --------------------------------

    public function test_a_terminal_status_implies_the_matching_plan_state(): void {
        // The rule the wp-admin save path was missing: it posted
        // `activity_status_key` with no `plan_state`, so a cancellation
        // left the session sitting in the planner's upcoming bucket.
        $this->assertSame( 'cancelled', ActivityLifecycle::planStateForTerminalStatus( 'cancelled' ) );
        $this->assertSame( 'completed', ActivityLifecycle::planStateForTerminalStatus( 'completed' ) );
        $this->assertNull( ActivityLifecycle::planStateForTerminalStatus( 'planned' ) );
    }

    public function test_the_cancel_button_moves_both_lifecycle_columns(): void {
        $activity = $this->insertActivity( 'planned', 'scheduled' );

        $repo = new ActivitiesRepository();
        $repo->setStatus( $activity, 'cancelled' );

        $this->assertSame( 'cancelled', $repo->statusKey( $activity ) );
        $this->assertSame( 'cancelled', $repo->planState( $activity ) );
    }

    // --- who gets told --------------------------------------------------

    public function test_the_send_reaches_the_parents_of_a_youth_player(): void {
        $activity = $this->insertActivity( 'planned', 'scheduled' );
        $player   = $this->insertPlayer( 9 );
        $parent   = self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );
        $this->insertExpectedAttendance( $activity, $player );

        $results = TrainingCancelledSend::handle( $activity );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( $parent, $results[0]->recipient->userId );
        $this->assertSame( Recipient::KIND_PARENT, $results[0]->recipient->kind );

        $this->assertIsArray( $this->captured );
        $this->assertSame( 'mum@example.test', $this->captured['to'] );

        $rows = $this->logRows();
        $this->assertCount( 1, $rows );
        $this->assertSame( MessageType::TRAINING_CANCELLED, $rows[0]->message_type );
    }

    public function test_a_parent_with_two_children_on_the_roster_is_told_once(): void {
        // The copy is about the activity, not about a named child, so the
        // second copy would only read as a bug.
        $activity = $this->insertActivity( 'planned', 'scheduled' );
        $parent   = self::factory()->user->create( [ 'user_email' => 'twins@example.test' ] );
        $repo     = new PlayerParentsRepository();

        foreach ( [ $this->insertPlayer( 9 ), $this->insertPlayer( 9 ) ] as $player ) {
            $repo->link( $player, $parent, true );
            $this->insertExpectedAttendance( $activity, $player );
        }

        $results = TrainingCancelledSend::handle( $activity );

        $this->assertCount( 1, $results );
        $this->assertSame( $parent, $results[0]->recipient->userId );
    }

    public function test_an_activity_nobody_is_planned_for_still_leaves_a_row(): void {
        $activity = $this->insertActivity( 'planned', 'scheduled' );

        $results = TrainingCancelledSend::handle( $activity );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );
        $this->assertCount( 1, $this->logRows(), 'a send that reached nobody is the failure that must not be silent' );
    }

    // --- quiet hours ----------------------------------------------------

    public function test_a_cancellation_is_never_held_until_morning(): void {
        $this->assertTrue(
            MessageType::bypassesQuietHours( MessageType::TRAINING_CANCELLED ),
            'a training called off tonight is useless news tomorrow'
        );

        // Shut the window over the whole day so the wall clock cannot
        // decide this, and send without the `urgent` flag so it is the
        // message type doing the bypassing, not the caller.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '00:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '23:59' );

        $user    = self::factory()->user->create( [ 'user_email' => 'late@example.test' ] );
        $results = CommsDispatcher::dispatchSync(
            TrainingCancelledTemplate::KEY,
            [ 'activity_title' => 'Tuesday training', 'date' => '2026-01-06', 'team_name' => 'U10-1' ],
            [ Recipient::self( $user, 'late@example.test' ) ],
            [ 'message_type' => MessageType::TRAINING_CANCELLED, 'sender_user_id' => 0, 'urgent' => false ]
        );

        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
    }

    // --- fixtures -------------------------------------------------------

    private function insertActivity( string $status, string $plan_state ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_teams", [ 'club_id' => $this->club, 'name' => 'U10-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( "{$this->p}tt_activities", [
            'club_id'             => $this->club,
            'team_id'             => $team,
            'title'               => 'Tuesday training',
            'session_date'        => '2026-01-06',
            'activity_type_key'   => 'training',
            'activity_status_key' => $status,
            'plan_state'          => $plan_state,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $age ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => 'Roster',
            'last_name'     => 'Player',
            'status'        => 'active',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years" ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertExpectedAttendance( int $activity_id, int $player_id ): void {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_attendance", [
            'club_id'     => $this->club,
            'activity_id' => $activity_id,
            'player_id'   => $player_id,
            'status'      => 'present',
            'record_type' => 'expected',
            'is_guest'    => 0,
        ] );
    }

    /** @return object[] */
    private function logRows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->log_table}" );
    }
}
