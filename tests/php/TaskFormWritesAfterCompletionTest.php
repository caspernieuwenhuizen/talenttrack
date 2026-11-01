<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Channel\Adapters\EmailChannelAdapter;
use TT\Modules\Comms\Channel\ChannelAdapterRegistry;
use TT\Modules\Comms\Template\TemplateRegistry;
use TT\Modules\Comms\Template\TemplateSwitch;
use TT\Modules\Comms\Templates\TrialPlayerWelcomeTemplate;
use TT\Modules\Invitations\PlayerParentsRepository;
use TT\Modules\Trials\Repositories\TrialCasesRepository;
use TT\Modules\Trials\Repositories\TrialTracksRepository;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Contracts\CompletionStepInterface;
use TT\Modules\Workflow\Contracts\FormInterface;
use TT\Modules\Workflow\Forms\RequestConsentForm;
use TT\Modules\Workflow\Frontend\FrontendTaskDetailView;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskStatus;
use TT\Modules\Workflow\TaskTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #4550 — a task form writes its records only once the task is completed.
 *
 * Six forms used to write inside `serializeResponse()`, which runs before the
 * task is marked completed. A completion that then failed left the record
 * behind with the task still open, and the next attempt wrote a second one.
 * The writes live in `CompletionStepInterface::onCompleted()` now, which
 * `TaskEngine::complete()` runs only after the row is completed.
 */
final class TaskFormWritesAfterCompletionTest extends WP_UnitTestCase {

    /** Tables the six forms write to. */
    private const RECORD_TABLES = [
        'tt_prospect_consent_requests', 'tt_prospects', 'tt_test_trainings', 'tt_trial_cases',
        'tt_players', 'tt_player_events', 'tt_audit_log',
    ];

    // The completion runs in its own transaction, whose START TRANSACTION
    // commits the one the suite opened for the test.
    use CommitsAfterCascade;

    private int $user = 0;

    private bool $fail_completion = false;

    private bool $welcome_enabled = false;

    /** A write this pattern matches fails, as a refused write would. */
    private string $refuse = '';

    /** @var list<array<string,mixed>> e-mails the comms channel was asked to send */
    private array $mails = [];

    /** Watch every tt_ table rather than only the record tables. */
    private bool $watch_every_table = false;

    /** @var list<string> */
    private array $writes = [];

    public function set_up(): void {
        parent::set_up();
        $this->markFixtureFloor();
        $this->markConfigKeys( TemplateSwitch::CONFIG_KEY, 'comms_quiet_hours_start', 'comms_quiet_hours_end' );
        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        wp_set_current_user( $this->user );
        AuthorizationService::flushCache();
        add_filter( 'query', [ $this, 'watch' ] );
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'watch' ] );
        remove_filter( 'tt_comms_email_send', [ $this, 'captureMail' ], 10 );
        if ( $this->welcome_enabled ) {
            TemplateRegistry::clear();
            ChannelAdapterRegistry::clear();
        }
        $this->refuse = '';
        ThrowingOnceConsentForm::$throw = false;
        ThrowingOnceConsentForm::$wrote = false;
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user( 0 );
        $this->cleanUpAndCommit();
        parent::tear_down();
    }

    /**
     * Records writes, and refuses the update that marks a task completed
     * when a test asks for a failed completion.
     *
     * @param string $sql
     */
    public function watch( $sql ) {
        $sql = (string) $sql;
        if ( ! preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) return $sql;

        global $wpdb;
        if ( $this->refuse !== '' && preg_match( $this->refuse, $sql ) ) return '';
        if ( stripos( $sql, $wpdb->prefix . 'tt_workflow_tasks' ) !== false ) {
            if ( $this->fail_completion && stripos( $sql, "'completed'" ) !== false ) return '';
            if ( ! $this->watch_every_table ) return $sql;
        }
        if ( $this->watch_every_table ) {
            if ( stripos( $sql, $wpdb->prefix . 'tt_' ) !== false ) $this->writes[] = $sql;
            return $sql;
        }
        foreach ( self::RECORD_TABLES as $table ) {
            if ( preg_match( '/' . preg_quote( $wpdb->prefix . $table, '/' ) . '\b/i', $sql ) ) {
                $this->writes[] = $sql;
                break;
            }
        }
        return $sql;
    }

    /** @return array<string, array{0: string}> */
    public static function writingForms(): array {
        return [
            'request consent'       => [ 'consent' ],
            'log prospect'          => [ 'prospect' ],
            'invite to test training' => [ 'invite' ],
            'test training outcome' => [ 'outcome' ],
            'team offer decision'   => [ 'offer' ],
            'trial group review'    => [ 'review' ],
        ];
    }

    /**
     * @dataProvider writingForms
     */
    public function test_serialising_the_answer_writes_nothing( string $case ): void {
        [ $form_class, $raw, $extra ] = $this->arrange( $case );
        $form = new $form_class();
        $task = $extra + [ 'assignee_user_id' => $this->user, 'status' => TaskStatus::OPEN ];

        $this->watch_every_table = true;
        $this->writes = [];
        $form->serializeResponse( $raw, $task );

        $this->assertSame( [], $this->writes, 'serializeResponse() only cleans the input' );
    }

    public function test_the_other_task_forms_write_nothing_when_serialising_either(): void {
        $forms = [
            'TT\\Modules\\Journey\\Workflow\\InjuryRecoveryConfirmForm',
            'TT\\Modules\\Workflow\\Forms\\ConfirmTestTrainingForm',
            'TT\\Modules\\Workflow\\Forms\\GoalApprovalForm',
            'TT\\Modules\\Workflow\\Forms\\GoalSettingForm',
            'TT\\Modules\\Workflow\\Forms\\PlayerSelfEvaluationForm',
            'TT\\Modules\\Workflow\\Forms\\PostGameEvaluationForm',
            'TT\\Modules\\Workflow\\Forms\\QuarterlyHoDReviewForm',
            'TT\\Modules\\Pdp\\Workflow\\PdpSelfReviewForm',
            'TT\\Modules\\Pdp\\Workflow\\PdpStubForm',
            'TT\\Modules\\StaffDevelopment\\Workflow\\StaffStubForm',
        ];
        $this->watch_every_table = true;
        foreach ( $forms as $form_class ) {
            /** @var FormInterface $form */
            $form = new $form_class();
            $this->writes = [];
            $form->serializeResponse( [ 'notes' => 'A note', 'went_well' => 'Pressing' ], [ 'assignee_user_id' => $this->user ] );
            $this->assertSame( [], $this->writes, "{$form_class}::serializeResponse() writes nothing" );
            $this->assertNotInstanceOf( CompletionStepInterface::class, $form, "{$form_class} has nothing to write on completion" );
        }
    }

    /**
     * @dataProvider writingForms
     */
    public function test_a_failed_completion_leaves_no_record_behind( string $case ): void {
        [ $form_class, $raw, $extra ] = $this->arrange( $case );
        $task_id = $this->task( $this->template( 'test_writes_after_' . $case, $form_class ), $extra );
        $before  = $this->records( $case, $extra );

        $this->fail_completion = true;
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $raw + [
            'tt_workflow_submit'                => '1',
            FrontendTaskDetailView::NONCE_FIELD => wp_create_nonce( FrontendTaskDetailView::NONCE_ACTION ),
        ];
        $this->writes = [];
        ob_start();
        FrontendTaskDetailView::render( $this->user, $task_id );
        $html = (string) ob_get_clean();
        $this->fail_completion = false;

        $this->assertStringContainsString( 'Could not save the task.', $html, 'precondition: the completion failed' );
        $this->assertNotSame( TaskStatus::COMPLETED, $this->status( $task_id ) );
        $this->assertSame( [], $this->writes, 'nothing is written for a task that did not complete' );
        $this->assertSame( $before, $this->records( $case, $extra ) );
    }

    /**
     * @dataProvider writingForms
     */
    public function test_completing_once_writes_the_record_once( string $case ): void {
        [ $form_class, $raw, $extra ] = $this->arrange( $case );
        $task_id = $this->task( $this->template( 'test_writes_after_' . $case, $form_class ), $extra );
        $before  = $this->records( $case, $extra );
        /** @var FormInterface&CompletionStepInterface $form */
        $form = new $form_class();
        $task = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );

        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->assertSame( TaskStatus::COMPLETED, $this->status( $task_id ) );
        $this->assertSame( $before + 1, $this->records( $case, $extra ), 'one record, as before' );

        $stored = json_decode( (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' ), true );
        $id_key = [
            'consent'  => 'entry_id',
            'prospect' => 'prospect_id',
            'invite'   => 'test_training_id',
            'outcome'  => 'trial_case_id',
            'offer'    => 'trial_case_id',
            'review'   => 'trial_case_id',
        ][ $case ];
        $this->assertGreaterThan( 0, (int) ( is_array( $stored ) ? ( $stored[ $id_key ] ?? 0 ) : 0 ), 'the stored answer carries the id of what was written' );

        // A second completion of the same task writes nothing more, and the
        // stored answer keeps the id.
        $stored_json  = (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' );
        $this->writes = [];
        $this->assertFalse(
            WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ),
            'the second completion completed nothing'
        );
        $this->assertSame( $before + 1, $this->records( $case, $extra ), 'a task already completed is not written for twice' );
        $this->assertSame( [], $this->writes );
        $this->assertSame( $stored_json, (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' ) );
    }

    public function test_a_completion_step_that_throws_leaves_the_task_open_and_a_retry_works(): void {
        [ , $raw, $extra ] = $this->arrange( 'consent' );
        $task_id = $this->task( $this->template( 'test_writes_after_throwing', ThrowingOnceConsentForm::class ), $extra );
        $before  = $this->records( 'consent', $extra );
        $form    = new ThrowingOnceConsentForm();
        $task    = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );

        // It writes the consent request, then throws: the request goes too.
        ThrowingOnceConsentForm::$throw = true;
        $this->assertFalse( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->assertTrue( ThrowingOnceConsentForm::$wrote, 'precondition: the step wrote before it threw' );
        $this->assertNotSame( TaskStatus::COMPLETED, $this->status( $task_id ), 'the task is still open' );
        $this->assertSame( $before, $this->records( 'consent', $extra ), 'the write before the failure was rolled back' );

        ThrowingOnceConsentForm::$throw = false;
        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ), 'the retry completes' );
        $this->assertSame( TaskStatus::COMPLETED, $this->status( $task_id ) );
        $this->assertSame( $before + 1, $this->records( 'consent', $extra ) );
    }

    public function test_a_write_that_returns_no_id_rolls_back_and_leaves_the_task_open(): void {
        global $wpdb;
        [ $form_class, $raw, $extra ] = $this->arrange( 'outcome' );
        $task_id = $this->task( $this->template( 'test_writes_after_zero_id', $form_class ), $extra );
        /** @var FormInterface&CompletionStepInterface $form */
        $form = new $form_class();
        $task = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );

        // The player is promoted first; then the trial case insert fails
        // without throwing, so `create()` answers 0.
        $this->refuse = '/^\s*INSERT\s+INTO\s+`?' . preg_quote( $wpdb->prefix, '/' ) . 'tt_trial_cases`?\s/i';
        $this->assertFalse( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->refuse = '';

        $this->assertNotSame( TaskStatus::COMPLETED, $this->status( $task_id ), 'the task is still open' );
        $this->assertSame( 0, (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_players WHERE first_name = %s AND last_name = %s",
            'Sem', 'Voltooid'
        ) ), 'the player promoted before the failure was rolled back' );
        $this->assertNull( $wpdb->get_var( $wpdb->prepare(
            "SELECT promoted_to_player_id FROM {$wpdb->prefix}tt_prospects WHERE id = %d",
            (int) $extra['prospect_id']
        ) ) );

        // With the insert working again, the retry admits the prospect.
        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->assertSame( 1, $this->records( 'outcome', $extra ) );
    }

    public function test_a_rolled_back_completion_sends_no_welcome_and_a_committed_one_does(): void {
        global $wpdb;
        $this->enableWelcomeMail();
        [ $form_class, $raw, $extra ] = $this->arrange( 'outcome' );
        $this->promoteWithFamily( (int) $extra['prospect_id'] );
        /** @var FormInterface&CompletionStepInterface $form */
        $form = new $form_class();

        // The trial case is opened, which triggers the welcome; storing the
        // ids on the task then fails, so the whole completion rolls back.
        $task_id = $this->task( $this->template( 'test_writes_after_welcome', $form_class ), $extra );
        $task    = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );
        $this->refuse = '/^\s*UPDATE\s+`?' . preg_quote( $wpdb->prefix, '/' ) . 'tt_workflow_tasks`?\s.*trial_case_id/is';
        $this->assertFalse( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->refuse = '';

        $this->assertNotSame( TaskStatus::COMPLETED, $this->status( $task_id ) );
        $this->assertSame( 0, $this->records( 'outcome', $extra ), 'the trial case was rolled back' );
        $this->assertSame( [], $this->mails, 'a rolled-back trial is not announced to the family' );

        // The retry commits, and the family is welcomed once, after it.
        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $this->assertSame( 1, $this->records( 'outcome', $extra ) );
        $this->assertCount( 1, $this->mails );
        $this->assertSame( 'family-' . $extra['prospect_id'] . '@example.test', (string) ( $this->mails[0]['to'] ?? '' ) );
    }

    public function test_a_task_without_a_completion_step_is_completed_once(): void {
        $task_id   = $this->task( $this->template( 'test_writes_after_plain', 'TT\\Modules\\Workflow\\Forms\\QuarterlyHoDReviewForm' ) );
        $completed = 0;
        $count     = static function () use ( &$completed ): void { $completed++; };
        add_action( 'tt_workflow_task_completed', $count );

        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, [ 'what_worked' => 'Pressing' ] ) );
        $stored = (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' );
        $this->assertFalse( WorkflowModule::engine()->complete( $task_id, [ 'what_worked' => 'Something else' ] ), 'a completed task is not completed again' );
        remove_action( 'tt_workflow_task_completed', $count );

        $this->assertSame( $stored, (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' ), 'the answer on record stays' );
        $this->assertSame( 1, $completed, 'the hook and chain ran once' );
    }

    public function test_a_resubmit_from_a_stale_tab_changes_nothing(): void {
        [ $form_class, $raw, $extra ] = $this->arrange( 'consent' );
        $task_id = $this->task( $this->template( 'test_writes_after_stale', $form_class ), $extra );
        /** @var FormInterface&CompletionStepInterface $form */
        $form = new $form_class();
        $task = ( new TasksRepository() )->find( $task_id );
        $this->assertIsArray( $task );
        $this->assertTrue( WorkflowModule::engine()->complete( $task_id, $form->serializeResponse( $raw, $task ), $form, $raw ) );
        $stored = (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' );
        $before = $this->records( 'consent', $extra );

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [ 'asked_of' => 'Stale tab coordinator' ] + $raw + [
            'tt_workflow_submit'                => '1',
            FrontendTaskDetailView::NONCE_FIELD => wp_create_nonce( FrontendTaskDetailView::NONCE_ACTION ),
        ];
        $this->writes = [];
        ob_start();
        FrontendTaskDetailView::render( $this->user, $task_id );
        $html = (string) ob_get_clean();

        $this->assertStringNotContainsString( 'Could not save the task.', $html );
        $this->assertStringNotContainsString( 'Stale tab coordinator', $html, 'the page shows the answer on record' );
        $this->assertSame( $stored, (string) ( ( new TasksRepository() )->find( $task_id )['response_json'] ?? '' ) );
        $this->assertSame( $before, $this->records( 'consent', $extra ) );
        $this->assertSame( [], $this->writes );
    }

    // Fixtures

    /**
     * The form, an answer it accepts, and the task columns it needs.
     *
     * @return array{0: class-string, 1: array<string, mixed>, 2: array<string, mixed>}
     */
    private function arrange( string $case ): array {
        $f = 'TT\\Modules\\Workflow\\Forms\\';
        switch ( $case ) {
            case 'consent':
                return [ $f . 'RequestConsentForm', [
                    'asked_at' => (string) current_time( 'Y-m-d' ),
                    'asked_of' => 'Jeugdcoördinator VV Hedel',
                    'outcome'  => 'awaiting',
                    'notes'    => 'Asked by phone.',
                ], [ 'prospect_id' => $this->prospect( false ) ] ];
            case 'prospect':
                return [ $f . 'LogProspectForm', [
                    'first_name'   => 'Wout',
                    'last_name'    => 'Naafronding',
                    'current_club' => 'VV Naafronding',
                ], [] ];
            case 'invite':
                return [ $f . 'InviteToTestTrainingForm', [
                    'new_date'           => gmdate( 'Y-m-d', time() + 14 * DAY_IN_SECONDS ) . 'T18:00',
                    'new_location'       => 'Sportpark Naafronding',
                    'invitation_message' => 'Welcome to a test training.',
                ], [ 'prospect_id' => $this->prospect( true ) ] ];
            case 'outcome':
                $this->trialTrack();
                return [ $f . 'RecordTestTrainingOutcomeForm', [
                    'recommendation' => 'admit_to_trial',
                    'observations'   => 'Quick in the turn.',
                ], [ 'prospect_id' => $this->prospect( true ) ] ];
            case 'offer':
                return [ $f . 'AwaitTeamOfferDecisionForm', [
                    'outcome' => 'declined',
                    'notes'   => 'Family chose another club.',
                ], $this->trialCase() ];
            case 'review':
                return [ $f . 'ReviewTrialGroupMembershipForm', [
                    'decision'  => 'decline_final',
                    'rationale' => 'Not at the level for the age group.',
                ], $this->trialCase() ];
        }
        $this->fail( "Unknown case {$case}" );
    }

    /**
     * How many of the records the case writes exist now.
     *
     * @param array<string, mixed> $extra
     */
    private function records( string $case, array $extra ): int {
        global $wpdb;
        $p = $wpdb->prefix;
        switch ( $case ) {
            case 'consent':
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_prospect_consent_requests WHERE prospect_id = %d", (int) $extra['prospect_id'] ) );
            case 'prospect':
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_prospects WHERE last_name = %s", 'Naafronding' ) );
            case 'invite':
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}tt_test_trainings WHERE location = %s", 'Sportpark Naafronding' ) );
            case 'outcome':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}tt_trial_cases tc JOIN {$p}tt_prospects pr ON pr.promoted_to_player_id = tc.player_id WHERE pr.id = %d",
                    (int) $extra['prospect_id']
                ) );
            case 'offer':
            case 'review':
                return (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$p}tt_player_events WHERE player_id = %d AND event_type = %s",
                    (int) $extra['player_id'],
                    'trial_ended'
                ) );
        }
        $this->fail( "Unknown case {$case}" );
    }

    /** Lets the trial welcome reach the e-mail channel, captured instead of sent. */
    private function enableWelcomeMail(): void {
        $this->welcome_enabled = true;
        TemplateRegistry::clear();
        ChannelAdapterRegistry::clear();
        TemplateRegistry::register( new TrialPlayerWelcomeTemplate() );
        ChannelAdapterRegistry::register( new EmailChannelAdapter() );
        QueryHelpers::set_config( TemplateSwitch::CONFIG_KEY, '' );
        // Quiet hours shut, so the send does not depend on the time of day.
        QueryHelpers::set_config( 'comms_quiet_hours_start', '03:00' );
        QueryHelpers::set_config( 'comms_quiet_hours_end', '03:01' );
        add_filter( 'tt_comms_email_send', [ $this, 'captureMail' ], 10, 2 );
    }

    /**
     * @param mixed               $accepted
     * @param array<string,mixed> $args
     */
    public function captureMail( $accepted, $args ): bool {
        $this->mails[] = is_array( $args ) ? $args : [];
        return true;
    }

    /** The prospect is already a player on the books, with a parent to welcome. */
    private function promoteWithFamily( int $prospect_id ): void {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Sem',
            'last_name'     => 'Gezin',
            'date_of_birth' => '2013-01-01',
            'status'        => 'trial',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $parent    = (int) self::factory()->user->create( [ 'user_email' => 'family-' . $prospect_id . '@example.test' ] );
        ( new PlayerParentsRepository() )->link( $player_id, $parent, true );
        $wpdb->update( $wpdb->prefix . 'tt_prospects', [ 'promoted_to_player_id' => $player_id ], [ 'id' => $prospect_id ] );
    }

    private function prospect( bool $consented ): int {
        global $wpdb;
        $wpdb->insert( "{$wpdb->prefix}tt_prospects", [
            'club_id'          => (int) CurrentClub::id(),
            'first_name'       => 'Sem',
            'last_name'        => 'Voltooid',
            'current_club'     => 'VV Voorbeeld',
            'consent_given_at' => $consented ? '2026-09-01 00:00:00' : null,
        ] );
        $id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $id, 'the fixture must write a prospect' );
        return $id;
    }

    private function trialTrack(): void {
        $id = ( new TrialTracksRepository() )->create( [ 'name' => 'Writes after completion', 'sort_order' => 0 ] );
        $this->assertGreaterThan( 0, $id, 'the fixture must write a trial track' );
    }

    /** @return array<string, int> */
    private function trialCase(): array {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'       => (int) CurrentClub::id(),
            'first_name'    => 'Trial',
            'last_name'     => 'Voltooid',
            'date_of_birth' => '2011-01-01',
            'status'        => 'trial',
        ] );
        $player_id = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_trial_cases', [
            'club_id'    => (int) CurrentClub::id(),
            'player_id'  => $player_id,
            'track_id'   => 0,
            'start_date' => '2026-01-05',
            'end_date'   => '2026-02-05',
            'status'     => TrialCasesRepository::STATUS_OPEN,
            'uuid'       => wp_generate_uuid4(),
            'created_by' => $this->user,
        ] );
        $case_id = (int) $wpdb->insert_id;
        $this->assertGreaterThan( 0, $case_id, 'the fixture must write a trial case' );
        return [ 'trial_case_id' => $case_id, 'player_id' => $player_id ];
    }

    /** @param class-string $form_class */
    private function template( string $key, string $form_class ): string {
        if ( ! WorkflowModule::registry()->has( $key ) ) {
            WorkflowModule::registry()->register( new class( $key, $form_class ) extends TaskTemplate {
                public function __construct( private string $k, private string $form ) {}
                public function key(): string { return $this->k; }
                public function name(): string { return 'Writes after completion task'; }
                public function description(): string { return ''; }
                public function defaultDeadlineOffset(): string { return '+3 days'; }
                public function defaultAssignee(): AssigneeResolver {
                    return new LambdaResolver( static fn( TaskContext $c ): array => [] );
                }
                public function formClass(): string { return $this->form; }
                public function entityLinks(): array { return []; }
            } );
        }
        return $key;
    }

    /** @param array<string, mixed> $extra */
    private function task( string $key, array $extra = [] ): int {
        $task_id = ( new TasksRepository() )->create( $extra + [
            'template_key'     => $key,
            'assignee_user_id' => $this->user,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
        ] );
        $this->assertGreaterThan( 0, $task_id, 'the fixture must write a task' );
        return $task_id;
    }

    private function status( int $task_id ): string {
        global $wpdb;
        return (string) $wpdb->get_var( $wpdb->prepare(
            "SELECT status FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d",
            $task_id
        ) );
    }
}

/**
 * The consent-request form, whose completion step writes its record and then
 * fails while `$throw` is set.
 */
final class ThrowingOnceConsentForm extends RequestConsentForm {

    public static bool $throw = false;

    public static bool $wrote = false;

    public function onCompleted( array $task, array $raw, array $response ): array {
        $written = parent::onCompleted( $task, $raw, $response );
        if ( self::$throw ) {
            self::$wrote = (int) ( $written['entry_id'] ?? 0 ) > 0;
            throw new \RuntimeException( 'Failed after writing, for the test.' );
        }
        return $written;
    }
}
