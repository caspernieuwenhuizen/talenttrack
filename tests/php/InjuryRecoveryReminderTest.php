<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Config\ConfigService;
use TT\Infrastructure\Journey\InjuryRepository;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Security\RolesService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Activities\Services\AttendanceDateRule;
use TT\Modules\Authorization\FunctionalRoleGrants;
use TT\Modules\Authorization\Matrix\MatrixRepository;
use TT\Modules\Authorization\MatrixGate;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\NotificationTemplate;
use TT\Modules\Journey\JourneyModule;
use TT\Modules\Journey\Workflow\InjuryRecoveryDueTemplate;
use TT\Modules\Journey\Workflow\InjuryRecoveryTasks;
use TT\Modules\Workflow\TaskStatus;

/**
 * #4246 / #4269 — one recovery reminder per player, on the workflow
 * chokepoint (#4287): due on the record's check date, to the team's physio
 * and head coach (the head coach alone without a physio), skipped when the
 * date moves or the record is closed, and created by the daily sweep for a
 * record whose date arrives without an edit.
 */
final class InjuryRecoveryReminderTest extends WP_UnitTestCase {

    // #4399 — the delete cases run a real cascade, which commits.
    use CommitsAfterCascade;

    private int $club   = 0;
    private int $team   = 0;
    private int $player = 0;
    private int $coach  = 0;
    private string $today = '';

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->club  = (int) CurrentClub::id();
        $this->today = AttendanceDateRule::today();

        $roles = new RolesService();
        $roles->installRoles();
        $roles->ensureCapabilities();
        JourneyModule::ensureCapabilities();
        JourneyModule::registerWorkflowTemplates();
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();

        global $wpdb;
        $wpdb->hide_errors();
        $this->team   = $this->insertTeam( 'JO10-1' );
        $this->player = $this->insertPlayer( $this->team );
        $this->coach  = $this->makeHeadCoach( $this->team );

        wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
    }

    public function tear_down(): void {
        remove_all_filters( 'tt_comms_email_send' );
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        wp_set_current_user( 0 );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        parent::tear_down();
    }

    public function test_a_return_due_today_reaches_the_physio_and_the_head_coach(): void {
        $physio = $this->makePhysio( $this->team );

        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->today,
        ] );

        $tasks = $this->active();
        $assignees = array_map( static fn ( array $t ): int => (int) $t['assignee_user_id'], $tasks );
        sort( $assignees );
        $expected = [ $this->coach, $physio ];
        sort( $expected );

        $this->assertSame( $expected, $assignees );
        foreach ( $tasks as $task ) {
            $this->assertSame( $this->today . ' 00:00:00', (string) $task['due_at'] );
        }
    }

    public function test_without_a_physio_the_head_coach_alone_is_asked(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );

        $tasks = $this->active();
        $this->assertCount( 1, $tasks );
        $this->assertSame( $this->coach, (int) $tasks[0]['assignee_user_id'] );
    }

    public function test_a_date_still_ahead_creates_nothing_yet(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -2 ),
            'expected_return' => $this->day( 5 ),
        ] );

        $this->assertSame( [], $this->all() );
    }

    public function test_moving_the_date_supersedes_the_old_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $first = $this->active();
        $this->assertCount( 1, $first );

        // Extended into the future: the old reminder goes, none yet for the new date.
        $repo->update( $id, [ 'expected_return' => $this->day( 10 ) ] );
        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $first[0]['id'] ) );

        // Moved to a day that has arrived: a new reminder on that day.
        $repo->update( $id, [ 'expected_return' => $this->day( -1 ) ] );
        $now = $this->active();
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -1 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /** #4358 — a date moved away and back gets its reminder again. */
    public function test_a_date_moved_away_and_back_reminds_again(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $repo->update( $id, [ 'expected_return' => $this->day( -1 ) ] );
        $repo->update( $id, [ 'expected_return' => $this->day( -2 ) ] );

        $now = $this->active();
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -2 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /** #4358 — an answered reminder stays answered through edits and the sweep. */
    public function test_a_completed_reminder_is_not_recreated(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];
        ( new \TT\Modules\Workflow\Repositories\TasksRepository() )->complete( (int) $task['id'], [] );

        $repo->update( $id, [ 'notes' => 'Seen by the physio.' ] );
        ( new ConfigService() )->set( InjuryRecoveryTasks::LAST_SWEEP_KEY, '' );
        InjuryRecoveryTasks::sweep();

        $this->assertSame( [], $this->active() );
        $this->assertCount( 1, $this->all() );
    }

    public function test_an_edit_that_keeps_the_date_creates_no_second_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $repo->update( $id, [ 'notes' => 'Seen by the physio.' ] );
        $repo->update( $id, [ 'notes' => 'Seen again.' ] );

        $this->assertCount( 1, $this->all() );
    }

    public function test_closing_the_record_skips_the_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];

        $repo->update( $id, [ 'actual_return' => $this->today ] );

        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $task['id'] ) );
    }

    /** #4357 — archiving fires the edit event with the update() payload shape. */
    public function test_archiving_fires_the_injury_updated_event(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'  => $this->player,
            'started_on' => $this->day( -3 ),
        ] );
        $seen = [];
        $spy  = static function ( $payload ) use ( &$seen ): void { $seen[] = $payload; };
        add_action( 'tt_journey_injury_updated', $spy, 5 );

        $this->assertTrue( $repo->archive( $id, 0 ) );
        remove_action( 'tt_journey_injury_updated', $spy, 5 );

        $this->assertCount( 1, $seen );
        $this->assertSame( $this->player, $seen[0]['player_id'] );
        $this->assertSame( $this->team, $seen[0]['team_id'] );
        $this->assertSame( $id, $seen[0]['extras']['injury_id'] );
        $this->assertSame( [ 'archived_at' ], $seen[0]['extras']['fields'] );
    }

    /** #4357 — the archived record's reminder is skipped in the same request. */
    public function test_archiving_the_record_skips_the_task(): void {
        $repo = new InjuryRepository();
        $id   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];

        $repo->archive( $id, 0 );

        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $task['id'] ) );
        $this->assertSame( [], ( new InjuryRepository() )->needsCheckSet( [ $this->player ], $this->today ) );
    }

    /** #4357 — a second open record keeps its own reminder. */
    public function test_archiving_one_record_keeps_the_other_records_reminder(): void {
        $repo  = new InjuryRepository();
        $first = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -3 ),
        ] );
        $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );
        $this->assertSame( $this->day( -3 ) . ' 00:00:00', (string) $this->active()[0]['due_at'] );

        $repo->archive( $first, 0 );

        $now = $this->active();
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -1 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /** #4399 — a permanent delete skips the reminder in the same request. */
    public function test_deleting_the_record_skips_the_task(): void {
        $id = ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];

        $deleted = ( new ArchiveRepository() )->deletePermanently( 'injury', [ $id ] );
        $active  = $this->active();
        $status  = $this->status( (int) $task['id'] );

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $deleted );
        $this->assertSame( [], $active );
        $this->assertSame( TaskStatus::SKIPPED, $status );
    }

    /** #4399 — a second open record keeps its own reminder through a delete. */
    public function test_deleting_one_record_keeps_the_other_records_reminder(): void {
        $repo  = new InjuryRepository();
        $first = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -3 ),
        ] );
        $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );

        ( new ArchiveRepository() )->deletePermanently( 'injury', [ $first ] );
        $now = $this->active();

        $this->cleanUpAndCommit();

        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -1 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /**
     * #4399 — deleting the player for good takes their reminder with them:
     * the player cascade's sweep reaches `tt_workflow_tasks.player_id`, so
     * the archive action naming no players for entity `player` leaves
     * nothing behind.
     */
    public function test_deleting_the_player_leaves_no_reminder(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $this->assertCount( 1, $this->active(), 'precondition: the reminder is open' );

        $deleted   = ( new ArchiveRepository() )->deletePermanently( 'player', [ $this->player ] );
        $remaining = $this->all();

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $deleted );
        $this->assertSame( [], $remaining, 'no injury_recovery_due row remains for the deleted player' );
    }

    /** #4399 — moving an archived record into the bin skips a reminder still open. */
    public function test_trashing_the_record_skips_the_task(): void {
        global $wpdb;
        $id = ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];
        // Archived without any event, so only the trash can move the reminder.
        $wpdb->update( "{$wpdb->prefix}tt_player_injuries", [ 'archived_at' => current_time( 'mysql' ) ], [ 'id' => $id ] );
        $this->assertCount( 1, $this->active(), 'precondition: the reminder is still open' );

        $this->assertSame( 1, ( new ArchiveRepository() )->trash( 'injury', [ $id ], get_current_user_id() ) );

        $this->assertSame( [], $this->active() );
        $this->assertSame( TaskStatus::SKIPPED, $this->status( (int) $task['id'] ) );
    }

    /**
     * #4399 — out of the bin the record is archived again, so still no
     * reminder; restored from the archive it asks for its check at once.
     */
    public function test_restoring_the_record_brings_the_task_back(): void {
        $archive = new ArchiveRepository();
        $id      = ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );

        $archive->archive( 'injury', [ $id ], 0 );
        $this->assertSame( [], $this->active(), 'archiving through the archive layer skips it' );
        $archive->trash( 'injury', [ $id ], get_current_user_id() );
        $archive->restoreFromTrash( 'injury', [ $id ], get_current_user_id() );
        $this->assertSame( [], $this->active(), 'back in the archive, not yet active' );

        $this->assertSame( 1, $archive->restore( 'injury', [ $id ] ) );

        $now = $this->active();
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -2 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /** #4399 — the bin's changes to one record leave the other record's reminder alone. */
    public function test_the_bin_keeps_the_other_records_reminder(): void {
        $repo    = new InjuryRepository();
        $archive = new ArchiveRepository();
        $first   = $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -3 ),
        ] );
        $repo->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );

        $archive->archive( 'injury', [ $first ], 0 );
        $archive->trash( 'injury', [ $first ], get_current_user_id() );
        $after_trash = $this->active();
        $archive->restoreFromTrash( 'injury', [ $first ], get_current_user_id() );
        $archive->restore( 'injury', [ $first ] );
        $after_restore = $this->active();

        $this->assertCount( 1, $after_trash );
        $this->assertSame( $this->day( -1 ) . ' 00:00:00', (string) $after_trash[0]['due_at'] );
        $this->assertCount( 1, $after_restore );
        $this->assertSame( $this->day( -3 ) . ' 00:00:00', (string) $after_restore[0]['due_at'], 'the earlier record is due again' );
    }

    /**
     * #4436 — archiving the player skips the reminder in the same request,
     * and the next sweep does not bring it back. The player archive runs a
     * transaction that commits, so the state is read before the cleanup.
     */
    public function test_archiving_the_player_skips_the_task_and_the_sweep_leaves_it(): void {
        $this->markConfigKeys( InjuryRecoveryTasks::LAST_SWEEP_KEY );
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );
        $task = $this->active()[0];

        $archived = ( new ArchiveRepository() )->archive( 'player', [ $this->player ], get_current_user_id() );
        $active   = $this->active();
        $status   = $this->status( (int) $task['id'] );

        ( new ConfigService() )->set( InjuryRecoveryTasks::LAST_SWEEP_KEY, '' );
        InjuryRecoveryTasks::sweep();
        $after_sweep = $this->active();
        $swept       = ( new InjuryRepository() )->playersWithCheckDue( $this->today );

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $archived );
        $this->assertSame( [], $active );
        $this->assertSame( TaskStatus::SKIPPED, $status );
        $this->assertSame( [], $after_sweep, 'the sweep does not recreate it' );
        $this->assertNotContains( $this->player, $swept, 'the sweep skips an archived player' );
    }

    /** #4436 — restoring the player with a record still due brings the reminder back at once. */
    public function test_restoring_the_player_brings_the_task_back(): void {
        $archive = new ArchiveRepository();
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );

        $archive->archive( 'player', [ $this->player ], get_current_user_id() );
        $archived = $this->active();
        $restored = $archive->restore( 'player', [ $this->player ] );
        $now      = $this->active();

        $this->cleanUpAndCommit();

        $this->assertSame( [], $archived );
        $this->assertSame( 1, $restored );
        $this->assertCount( 1, $now );
        $this->assertSame( $this->day( -2 ) . ' 00:00:00', (string) $now[0]['due_at'] );
    }

    /**
     * #4436 — the bin behaves like the archive: trashed, no reminder; out of
     * the bin the player is archived again, so still none; restored from the
     * archive, the reminder is back.
     */
    public function test_the_bin_follows_the_player(): void {
        $archive = new ArchiveRepository();
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );

        $archive->archive( 'player', [ $this->player ], get_current_user_id() );
        $trashed       = $archive->trash( 'player', [ $this->player ], get_current_user_id() );
        $after_trash   = $this->active();
        $out_of_bin    = $archive->restoreFromTrash( 'player', [ $this->player ], get_current_user_id() );
        $after_bin     = $this->active();
        $archive->restore( 'player', [ $this->player ] );
        $after_restore = $this->active();

        $this->cleanUpAndCommit();

        $this->assertSame( 1, $trashed );
        $this->assertSame( [], $after_trash );
        $this->assertSame( 1, $out_of_bin );
        $this->assertSame( [], $after_bin, 'back in the archive, not yet active' );
        $this->assertCount( 1, $after_restore );
    }

    /** #4436 — archiving one player leaves another player's reminder alone. */
    public function test_archiving_another_player_keeps_this_players_reminder(): void {
        $other = $this->insertPlayer( $this->team );
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -20 ),
            'expected_return' => $this->day( -2 ),
        ] );

        ( new ArchiveRepository() )->archive( 'player', [ $other ], get_current_user_id() );
        $now   = $this->active();
        $swept = ( new InjuryRepository() )->playersWithCheckDue( $this->today );

        $this->cleanUpAndCommit();

        $this->assertCount( 1, $now );
        $this->assertContains( $this->player, $swept );
    }

    public function test_the_daily_sweep_reminds_about_an_incomplete_record_once(): void {
        global $wpdb;
        // Written without the repository, so no event fires: only the sweep can find it.
        $wpdb->insert( "{$wpdb->prefix}tt_player_injuries", [
            'club_id'    => $this->club,
            'player_id'  => $this->player,
            'started_on' => $this->day( -20 ),
            'notes'      => 'ankle',
        ] );
        $this->assertSame( [], $this->all() );

        $this->assertGreaterThanOrEqual( 1, InjuryRecoveryTasks::sweep() );
        $tasks = $this->active();
        $this->assertCount( 1, $tasks );
        $this->assertSame( $this->day( -6 ) . ' 00:00:00', (string) $tasks[0]['due_at'], 'due the day it became incomplete' );

        $this->assertSame( 0, InjuryRecoveryTasks::sweep(), 'once a day' );

        ( new ConfigService() )->set( InjuryRecoveryTasks::LAST_SWEEP_KEY, '' );
        InjuryRecoveryTasks::sweep();
        $this->assertCount( 1, $this->all(), 'a second run on another day creates nothing new' );
    }

    public function test_the_task_carries_no_injury_detail(): void {
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
            'notes'           => 'ankle',
        ] );
        $template = new InjuryRecoveryDueTemplate();
        $json     = (string) wp_json_encode( [ $this->all(), $template->name(), $template->description() ] );
        foreach ( [ 'ankle', 'expected_return', 'body_part', 'severity' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    /**
     * The recipient filter is the security control: a head coach on the
     * player's team who may not read this player's injuries is refused the
     * task and the e-mail, while the physio who may is still asked.
     */
    public function test_a_head_coach_without_injury_read_gets_no_task_and_no_mail(): void {
        $physio = $this->makePhysio( $this->team );
        $this->revokeHeadCoachInjuryRead();
        $this->assertFalse( AuthorizationService::canRecordInjury( $this->coach, $this->player, 'read' ), 'precondition: the head coach is refused' );
        $this->assertTrue( AuthorizationService::canRecordInjury( $physio, $this->player, 'read' ), 'precondition: the physio may read' );

        $mails = $this->captureMail();
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );

        $assignees = array_map( static fn ( array $t ): int => (int) $t['assignee_user_id'], $this->all() );
        $this->assertSame( [ $physio ], $assignees );

        $coach_email = (string) get_userdata( $this->coach )->user_email;
        foreach ( $mails->sent as $mail ) {
            $this->assertNotContains( $coach_email, (array) $mail['to'], 'no e-mail to the refused head coach' );
        }
    }

    public function test_with_no_one_allowed_to_read_nothing_is_created_or_sent(): void {
        $this->revokeHeadCoachInjuryRead();

        $mails = $this->captureMail();
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
        ] );
        InjuryRecoveryTasks::sweep();

        $this->assertSame( [], $this->all() );
        $this->assertSame( [], $mails->sent );
    }

    public function test_the_mail_carries_no_injury_detail(): void {
        $mails = $this->captureMail();
        ( new InjuryRepository() )->create( [
            'player_id'       => $this->player,
            'started_on'      => $this->day( -10 ),
            'expected_return' => $this->day( -1 ),
            'notes'           => 'ankle',
        ] );

        $this->assertNotEmpty( $mails->sent, 'the head coach is mailed, or nothing below proves anything' );
        $json = (string) wp_json_encode( $mails->sent );
        foreach ( [ 'ankle', $this->day( -10 ), 'expected_return', 'body_part', 'severity' ] as $leak ) {
            $this->assertStringNotContainsString( $leak, $json );
        }
    }

    // ---- fixtures ------------------------------------------------------

    /** Takes the head coach persona's team-scope injury read out of the matrix. */
    private function revokeHeadCoachInjuryRead(): void {
        ( new MatrixRepository() )->removeRow( 'head_coach', 'player_injuries', MatrixGate::READ, MatrixGate::SCOPE_TEAM );
        MatrixRepository::clearCache();
        FunctionalRoleGrants::clearCache();
        AuthorizationService::flushCache();
    }

    /**
     * Every e-mail the task mailer hands to Comms, taken at the adapter's
     * transport hook so none leaves the test. Registers exactly the template
     * and channel the path needs (see CommsPushChokepointTest), and pins the
     * quiet hours shut so the wall clock cannot hold the mail back.
     */
    private function captureMail(): \stdClass {
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new NotificationTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );

        $box       = new \stdClass();
        $box->sent = [];
        add_filter( 'tt_comms_email_send', static function ( $accepted, $args ) use ( $box ) {
            $box->sent[] = [
                'to'      => (string) ( $args['to'] ?? '' ),
                'subject' => (string) ( $args['subject'] ?? '' ),
                'body'    => (string) ( $args['body'] ?? '' ),
            ];
            return true;
        }, 10, 2 );
        return $box;
    }

    private function day( int $offset ): string {
        return gmdate( 'Y-m-d', (int) strtotime( $this->today . ' 00:00:00 UTC ' . ( $offset >= 0 ? '+' : '' ) . $offset . ' days' ) );
    }

    /** @return list<array<string, mixed>> */
    private function all(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks WHERE template_key = %s AND player_id = %d ORDER BY id ASC",
            InjuryRecoveryDueTemplate::KEY,
            $this->player
        ), ARRAY_A );
        return is_array( $rows ) ? array_values( $rows ) : [];
    }

    /** @return list<array<string, mixed>> */
    private function active(): array {
        return array_values( array_filter(
            $this->all(),
            static fn ( array $t ): bool => TaskStatus::isActionable( (string) $t['status'] )
        ) );
    }

    private function status( int $task_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d",
            $task_id
        ) );
    }

    private function insertTeam( string $name ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_teams", [ 'club_id' => $this->club, 'name' => $name ] );
        return (int) $wpdb->insert_id;
    }

    private function insertPlayer( int $team_id ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_players", [
            'club_id'    => $this->club,
            'team_id'    => $team_id,
            'first_name' => 'Reminder',
            'last_name'  => 'Player',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A head coach on one team (see InjuryOverviewAuditTest). */
    private function makeHeadCoach( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_roles WHERE role_key = %s AND club_id = %d LIMIT 1",
            'head_coach',
            $this->club
        ) );
        $this->assertGreaterThan( 0, $role_id, 'the head coach auth role must be seeded' );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Head',
            'last_name'  => 'Coach',
            'role_type'  => 'head_coach',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        // The reminder finds the head coach the way every recipient lookup
        // does: through the `head_coach` functional role on the assignment.
        $functional = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'head_coach', $this->club
        ) );
        if ( $functional <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'head_coach',
                'label'       => 'Head coach',
                'description' => 'Created by InjuryRecoveryReminderTest.',
                'is_system'   => 1,
                'sort_order'  => 10,
            ] );
            $functional = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $functional,
            'role_in_team'       => 'head_coach',
            'is_head_coach'      => 1,
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'club_id'    => $this->club,
            'person_id'  => $person_id,
            'role_id'    => $role_id,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        return $uid;
    }

    /** A staff member who is the physio of one team (see InjuriesCollectionRestTest). */
    private function makePhysio( int $team_id ): int {
        global $wpdb;
        $p   = $wpdb->prefix;
        $uid = self::factory()->user->create( [ 'role' => 'tt_staff' ] );

        $wpdb->insert( "{$p}tt_people", [
            'club_id'    => $this->club,
            'first_name' => 'Team',
            'last_name'  => 'Physio',
            'role_type'  => 'staff',
            'wp_user_id' => $uid,
            'status'     => 'active',
        ] );
        $person_id = (int) $wpdb->insert_id;

        $role_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_functional_roles WHERE role_key = %s AND club_id = %d",
            'physio', $this->club
        ) );
        if ( $role_id <= 0 ) {
            $wpdb->insert( "{$p}tt_functional_roles", [
                'club_id'     => $this->club,
                'role_key'    => 'physio',
                'label'       => 'Physio',
                'description' => 'Created by InjuryRecoveryReminderTest.',
                'is_system'   => 1,
                'sort_order'  => 90,
            ] );
            $role_id = (int) $wpdb->insert_id;
        }

        $wpdb->insert( "{$p}tt_team_people", [
            'club_id'            => $this->club,
            'team_id'            => $team_id,
            'person_id'          => $person_id,
            'functional_role_id' => $role_id,
            'role_in_team'       => 'physio',
        ] );
        $wpdb->insert( "{$p}tt_user_role_scopes", [
            'person_id'  => $person_id,
            'role_id'    => 1,
            'scope_type' => 'team',
            'scope_id'   => $team_id,
        ] );

        AuthorizationService::flushCache();
        FunctionalRoleGrants::clearCache();
        return $uid;
    }
}
