<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Send\TrialPlayerWelcomeSend;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;

/**
 * #2605 Gate D — the `trial_player_welcome` trigger.
 *
 * Two things are pinned here, and the second is the reason the copy was
 * rewritten before the trigger was wired.
 *
 * **It fires from the repository, not from a screen.** `tt_trial_started`
 * is announced by `TrialCasesRepository::create()` (#3130), which is what
 * makes a trial opened through the REST controller, the trials-manage
 * form, the wizard or the demo generator behave identically. A test that
 * drove one screen would not notice the others regressing.
 *
 * **It never sends an empty label to a family.** The template used to
 * promise `{first_session_location}` and `{what_to_bring}`, neither of
 * which has a column behind it, so the first message an academy ever sent
 * a family read "Where:" and "What to bring:" with nothing after them.
 * `test_the_message_never_promises_what_a_trial_case_cannot_know` is there
 * so that cannot come back.
 */
final class TrialPlayerWelcomeTriggerTest extends WP_UnitTestCase {

    use QuietHoursClosed;

    private string $p;
    private int $club;
    private string $log_table;

    /** @var array<string,mixed>|null */
    private ?array $captured = null;

    public function set_up(): void {
        parent::set_up();
        global $wpdb;
        $this->p         = $wpdb->prefix;
        $this->club      = (int) CurrentClub::id();
        $this->log_table = $wpdb->prefix . 'tt_comms_log';

        $this->captured = null;
        add_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10, 2 );

        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new TrialPlayerWelcomeTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // This template does not bypass quiet hours, and a test that passes
        // only outside office hours is not one (#4837).
        $this->closeQuietHours();

        $wpdb->query( "DELETE FROM {$this->log_table}" );
    }

    public function tear_down(): void {
        remove_filter( 'tt_comms_email_send', [ $this, 'captureSend' ], 10 );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        parent::tear_down();
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureSend( $accepted, $args ): bool {
        $this->captured = $args;
        return true;
    }

    public function test_opening_a_case_welcomes_the_family(): void {
        $player = $this->insertPlayer( 13 );
        $parent = self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );

        $case = $this->insertCase( $player, '2026-09-14' );

        $results = TrialPlayerWelcomeSend::send( $case );

        $this->assertCount( 1, $results );
        $this->assertSame( CommsResult::STATUS_SENT, $results[0]->status );
        $this->assertSame( Recipient::KIND_PARENT, $results[0]->recipient->kind );
        $this->assertSame( 'mum@example.test', $this->captured['to'] );

        $rows = $this->logRows();
        $this->assertCount( 1, $rows );
        $this->assertSame( MessageType::TRIAL_PLAYER_WELCOME, $rows[0]->message_type );
    }

    /** The player's name and the start date are what the message is for. */
    public function test_the_message_carries_the_name_and_the_start_date(): void {
        $player = $this->insertPlayer( 13, 'Sem', 'de Vries' );
        $parent = self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );

        TrialPlayerWelcomeSend::send( $this->insertCase( $player, '2026-09-14' ) );

        $body = (string) $this->captured['body'];
        $this->assertStringContainsString( 'Sem de Vries', $body );
        $this->assertStringContainsString(
            date_i18n( (string) get_option( 'date_format' ), strtotime( '2026-09-14' ) ),
            $body
        );
    }

    /**
     * The regression this issue exists to prevent: no label may survive
     * with nothing after it, and no token may go out unreplaced.
     */
    public function test_the_message_never_promises_what_a_trial_case_cannot_know(): void {
        $player = $this->insertPlayer( 13 );
        $parent = self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );

        TrialPlayerWelcomeSend::send( $this->insertCase( $player, '2026-09-14' ) );

        $body    = (string) $this->captured['body'];
        $subject = (string) $this->captured['subject'];

        foreach ( [ 'first_session_location', 'what_to_bring', 'team_name', 'first_session_date' ] as $dead ) {
            $this->assertStringNotContainsString( $dead, $body );
        }
        // Nothing unreplaced, in either half of the message.
        $this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $body );
        $this->assertDoesNotMatchRegularExpression( '/\{[a-z_]+\}/', $subject );
    }

    /**
     * The trigger belongs to the repository, so every caller inherits it.
     * This drives `create()` rather than a screen, which is the whole point
     * of #3130 having moved the hook there.
     */
    public function test_the_repository_announces_the_trial_for_every_caller(): void {
        $player = $this->insertPlayer( 13 );

        $fired = [];
        $spy   = static function ( int $case_id, int $player_id ) use ( &$fired ): void {
            $fired[] = $player_id;
        };
        add_action( 'tt_trial_started', $spy, 10, 2 );

        ( new TrialCasesRepository() )->create( [
            'player_id'  => $player,
            'track_id'   => $this->insertTrack(),
            'start_date' => '2026-09-14',
            'end_date'   => '2026-10-14',
        ] );

        remove_action( 'tt_trial_started', $spy, 10 );
        $this->assertSame( [ $player ], $fired );
    }

    public function test_a_case_with_no_reachable_family_still_leaves_a_row(): void {
        $case = $this->insertCase( $this->insertPlayer( 13 ), '2026-09-14' );

        $results = TrialPlayerWelcomeSend::send( $case );

        $this->assertSame( CommsResult::STATUS_NO_RECIPIENTS, $results[0]->status );
        $this->assertCount( 1, $this->logRows() );
    }

    public function test_an_unknown_case_sends_nothing(): void {
        $this->assertSame( [], TrialPlayerWelcomeSend::send( 999999 ) );
        $this->assertSame( [], $this->logRows() );
    }

    /** Gate B's switch has to hold for a newly wired template too. */
    public function test_disabling_the_template_stops_it(): void {
        $player = $this->insertPlayer( 13 );
        $parent = self::factory()->user->create( [ 'user_email' => 'mum@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player, $parent, true );

        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, TrialPlayerWelcomeTemplate::KEY );

        $results = TrialPlayerWelcomeSend::send( $this->insertCase( $player, '2026-09-14' ) );

        $this->assertNotEmpty( $results );
        $this->assertSame( CommsResult::STATUS_TEMPLATE_DISABLED, $results[0]->status );
        $this->assertNull( $this->captured, 'a disabled template must not reach the channel' );
    }

    /**
     * #4837 — the window this file pins holds no message at any minute of
     * the day, the date boundaries and the old 03:00 minute included, so no
     * case here depends on when the suite runs.
     */
    public function test_the_pinned_quiet_hours_hold_nothing_at_any_time_of_day(): void {
        $request = new \TT\Modules\Comms\Domain\CommsRequest( TrialPlayerWelcomeTemplate::KEY, MessageType::TRIAL_PLAYER_WELCOME, $this->club, 0, [] );
        $this->assertFalse( MessageType::bypassesQuietHours( MessageType::TRIAL_PLAYER_WELCOME ), 'Precondition: the welcome is subject to quiet hours.' );
        $tz      = wp_timezone();
        foreach ( [ '23:59', '00:00', '00:01', '03:00', '03:00:30', '12:00' ] as $time ) {
            $instant = ( new \DateTimeImmutable( '2026-09-14 ' . $time, $tz ) )->getTimestamp();
            $policy  = new \TT\Modules\Comms\QuietHours\QuietHoursPolicy( static fn (): int => $instant );
            $this->assertFalse( $policy->shouldDefer( $request ), "held at {$time}" );
        }

        // The other side, on the frozen clock: a window that is open at
        // 03:00 does hold the message.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '04:00' );
        $at_three = ( new \DateTimeImmutable( '2026-09-14 03:00:30', $tz ) )->getTimestamp();
        $this->assertTrue( ( new \TT\Modules\Comms\QuietHours\QuietHoursPolicy( static fn (): int => $at_three ) )->shouldDefer( $request ), 'Precondition: an open window holds the welcome at 03:00.' );
    }

    // --- fixtures -------------------------------------------------------

    private function insertPlayer( int $age, string $first = 'Trial', string $last = 'Player' ): int {
        global $wpdb;
        // Half a year past the birthday, so the age never turns over
        // between the fixture and the send, whatever the date (#4837).
        $wpdb->insert( "{$this->p}tt_players", [
            'club_id'       => $this->club,
            'first_name'    => $first,
            'last_name'     => $last,
            'status'        => 'trial',
            'date_of_birth' => gmdate( 'Y-m-d', strtotime( "-{$age} years -6 months" ) ),
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertTrack(): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_tracks", [
            'club_id' => $this->club,
            'name'    => 'Standard trial',
        ] );
        return (int) $wpdb->insert_id;
    }

    private function insertCase( int $player_id, string $start ): int {
        global $wpdb;
        $wpdb->insert( "{$this->p}tt_trial_cases", [
            'club_id'    => $this->club,
            'player_id'  => $player_id,
            'track_id'   => $this->insertTrack(),
            'start_date' => $start,
            'end_date'   => gmdate( 'Y-m-d', strtotime( $start . ' +30 days' ) ),
            'status'     => 'open',
            'uuid'       => wp_generate_uuid4(),
        ] );
        return (int) $wpdb->insert_id;
    }

    /** @return object[] */
    private function logRows(): array {
        global $wpdb;
        return $wpdb->get_results( "SELECT * FROM {$this->log_table}" );
    }
}
