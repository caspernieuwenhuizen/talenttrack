<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Workflow\Contracts\AssigneeResolver;
use TT\Modules\Workflow\Contracts\FormInterface;
use TT\Modules\Workflow\Frontend\FrontendTaskDetailView;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Resolvers\LambdaResolver;
use TT\Modules\Workflow\TaskContext;
use TT\Modules\Workflow\TaskTemplate;
use TT\Modules\Workflow\WorkflowModule;

/**
 * #4423 — after a refused answer a workflow task form comes back with what
 * the user typed, not with its defaults, and the refusal writes nothing.
 *
 * The view sanitises the submitted values itself and never calls the form's
 * `serializeResponse()`. Every form
 * type is checked through the real view: a wrapper renders the real form,
 * refuses every answer, and fails the test if `serializeResponse()` is
 * reached. `request_consent` is also checked with its own validation.
 */
final class TaskFormKeepValuesTest extends WP_UnitTestCase {

    /** Tables a refused answer must not touch: the task, and what the forms write. */
    private const WATCHED = [
        'tt_workflow_tasks', 'tt_prospect_consent_requests', 'tt_prospects', 'tt_test_trainings',
        'tt_trial_cases', 'tt_goals', 'tt_evaluations', 'tt_audit_log',
    ];

    private int $user = 0;

    /** @var list<string> */
    private array $writes = [];

    public function set_up(): void {
        parent::set_up();
        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        AuthorizationService::flushCache();
        add_filter( 'query', [ $this, 'watch' ] );
    }

    public function tear_down(): void {
        remove_filter( 'query', [ $this, 'watch' ] );
        KeepValuesRefusingForm::$inner = '';
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    /** @param string $sql */
    public function watch( $sql ) {
        $sql = (string) $sql;
        if ( preg_match( '/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql ) ) {
            global $wpdb;
            foreach ( self::WATCHED as $table ) {
                if ( stripos( $sql, $wpdb->prefix . $table ) !== false ) {
                    $this->writes[] = $sql;
                    break;
                }
            }
        }
        return $sql;
    }

    // request_consent, with its own validation

    public function test_request_consent_comes_back_with_the_typed_date_and_club_and_the_notice(): void {
        global $wpdb;
        $asked_at = gmdate( 'Y-m-d', (int) strtotime( (string) current_time( 'Y-m-d' ) . ' 12:00:00' ) - 3 * DAY_IN_SECONDS );
        $task_id  = $this->task( $this->template( 'test_keep_request_consent', \TT\Modules\Workflow\Forms\RequestConsentForm::class ) );
        $before   = $this->row( $task_id );
        $requests = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospect_consent_requests" );

        $html = $this->submit( $task_id, [
            'asked_at' => $asked_at,
            'asked_of' => 'Jeugdcoördinator VV Hedel',
            'outcome'  => 'not-an-outcome',
            'notes'    => 'Belde dinsdag',
        ] );

        $this->assertStringContainsString( 'value="' . $asked_at . '"', $html, 'the typed date, not today' );
        $this->assertStringContainsString( 'value="Jeugdcoördinator VV Hedel"', $html, 'the typed club' );
        $this->assertStringContainsString( 'Belde dinsdag', $html );
        $this->assertStringContainsString( 'tt-workflow-task-errors', $html, 'the #4422 notice says why' );
        $this->assertDoesNotMatchRegularExpression( '/<input[^>]+name="asked_of"[^>]*disabled/', $html, 'an open task stays editable' );

        $this->assertSame( $before, $this->row( $task_id ), 'the task row is unchanged' );
        $this->assertSame( $requests, (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}tt_prospect_consent_requests" ), 'no consent request was logged' );
        $this->assertSame( [], $this->writes, 'a refused answer writes nothing' );
    }

    // Every form type

    /** @return array<string, array{0: class-string, 1: array<string, mixed>, 2: list<string>}> */
    public function forms(): array {
        $f = 'TT\\Modules\\Workflow\\Forms\\';
        return [
            'injury recovery'      => [ 'TT\\Modules\\Journey\\Workflow\\InjuryRecoveryConfirmForm', [ 'notes' => 'KV injury note' ], [ 'KV injury note' ] ],
            'team offer decision'  => [ $f . 'AwaitTeamOfferDecisionForm', [ 'notes' => 'KV offer note' ], [ 'KV offer note' ] ],
            'confirm test training' => [ $f . 'ConfirmTestTrainingForm', [ 'notes' => 'KV confirm note' ], [ 'KV confirm note' ] ],
            'goal approval'        => [ $f . 'GoalApprovalForm', [ 'decisions' => [ [ 'decision' => 'amend', 'note' => 'KV approval note' ] ] ], [ 'KV approval note' ] ],
            'goal setting'         => [ $f . 'GoalSettingForm', [ 'goals' => [ [ 'title' => 'KV goal title', 'why' => 'KV goal why' ] ] ], [ 'KV goal title', 'KV goal why' ] ],
            'invite to test training' => [ $f . 'InviteToTestTrainingForm', [ 'new_location' => 'KV Sportpark', 'invitation_message' => 'KV invitation' ], [ 'KV Sportpark', 'KV invitation' ] ],
            'log prospect'         => [ $f . 'LogProspectForm', [ 'first_name' => 'KVfirst', 'last_name' => 'KVlast', 'current_club' => 'KV club', 'scouting_notes' => 'KV scouting' ], [ 'KVfirst', 'KVlast', 'KV club', 'KV scouting' ] ],
            'player self-evaluation' => [ $f . 'PlayerSelfEvaluationForm', [ 'went_well' => 'KV went well', 'to_work_on' => 'KV work on' ], [ 'KV went well', 'KV work on' ] ],
            'post-game evaluation' => [ $f . 'PostGameEvaluationForm', [ 'went_well' => 'KV match went well', 'to_work_on' => 'KV match work on' ], [ 'KV match went well', 'KV match work on' ] ],
            'quarterly HoD review' => [ $f . 'QuarterlyHoDReviewForm', [ 'what_worked' => 'KV worked', 'what_didnt' => 'KV didnt', 'focus_next' => 'KV focus' ], [ 'KV worked', 'KV didnt', 'KV focus' ] ],
            'test training outcome' => [ $f . 'RecordTestTrainingOutcomeForm', [ 'observations' => 'KV observations' ], [ 'KV observations' ] ],
            'request consent'      => [ $f . 'RequestConsentForm', [ 'asked_of' => 'KV coordinator', 'notes' => 'KV consent note' ], [ 'KV coordinator', 'KV consent note' ] ],
            'trial group review'   => [ $f . 'ReviewTrialGroupMembershipForm', [ 'rationale' => 'KV rationale' ], [ 'KV rationale' ] ],
        ];
    }

    /**
     * @dataProvider forms
     * @param class-string         $form_class
     * @param array<string, mixed> $typed
     * @param list<string>         $shown
     */
    public function test_a_refused_answer_comes_back_with_what_was_typed( string $form_class, array $typed, array $shown ): void {
        KeepValuesRefusingForm::$inner = $form_class;
        $key     = 'test_keep_' . strtolower( (string) preg_replace( '/[^A-Za-z]/', '', substr( strrchr( '\\' . $form_class, '\\' ), 1 ) ) );
        $extra = [];
        if ( $form_class === 'TT\\Modules\\Workflow\\Forms\\GoalApprovalForm' ) {
            $extra['parent_task_id'] = $this->goalSettingParent();
        }
        if ( $form_class === 'TT\\Modules\\Journey\\Workflow\\InjuryRecoveryConfirmForm' ) {
            // The notes field is for a reader of the player's injuries.
            $extra['player_id'] = $this->player();
        }
        $task_id = $this->task( $this->template( $key, KeepValuesRefusingForm::class ), $extra );
        $before  = $this->row( $task_id );

        $html = $this->submit( $task_id, $typed );

        $this->assertStringContainsString( 'Refused for the test.', $html, 'precondition: the answer was refused' );
        foreach ( $shown as $value ) {
            $this->assertStringContainsString( $value, $html, "the form shows the typed '{$value}' again" );
        }
        $this->assertSame( $before, $this->row( $task_id ), 'the task row is unchanged' );
        $this->assertSame( [], $this->writes, 'a refused answer writes nothing' );
    }

    public function test_a_post_from_someone_who_may_not_submit_gets_nothing_echoed_back(): void {
        $task_id = $this->task( $this->template( 'test_keep_other_user', \TT\Modules\Workflow\Forms\RequestConsentForm::class ) );
        $before  = $this->row( $task_id );
        $other   = (int) self::factory()->user->create( [ 'role' => 'tt_coach' ] );

        $html = $this->submit( $task_id, [
            'asked_at' => '2026-01-01',
            'asked_of' => 'KV not the submitter',
            'outcome'  => 'not-an-outcome',
        ], $other );

        $this->assertStringNotContainsString( 'KV not the submitter', $html, 'only the person who may submit gets their own values back' );
        $this->assertSame( $before, $this->row( $task_id ), 'the task row is unchanged' );
        $this->assertSame( [], $this->writes );
    }

    public function test_a_quote_breaking_value_in_a_single_line_field_comes_back_escaped(): void {
        $task_id = $this->task( $this->template( 'test_keep_quote', \TT\Modules\Workflow\Forms\RequestConsentForm::class ) );

        $html = $this->submit( $task_id, [
            'asked_at' => '2026-01-01',
            'asked_of' => 'KV" autofocus onfocus="alert(1)',
            'outcome'  => 'not-an-outcome',
        ] );

        $this->assertStringContainsString( 'value="KV&quot; autofocus onfocus=&quot;alert(1)"', $html, 'the quotes stay inside the attribute' );
        $this->assertStringNotContainsString( 'onfocus="alert(1)"', $html, 'no attribute is injected' );
    }

    public function test_markup_in_a_typed_value_comes_back_as_plain_text(): void {
        KeepValuesRefusingForm::$inner = 'TT\\Modules\\Workflow\\Forms\\QuarterlyHoDReviewForm';
        $task_id = $this->task( $this->template( 'test_keep_markup', KeepValuesRefusingForm::class ) );

        $html = $this->submit( $task_id, [ 'what_worked' => '<script>alert(1)</script>Pressing' ] );

        $this->assertStringContainsString( 'Pressing', $html );
        $this->assertStringNotContainsString( '<script>alert(1)</script>', $html );
    }

    // Fixtures

    /** @param class-string $form_class */
    private function template( string $key, string $form_class ): string {
        if ( ! WorkflowModule::registry()->has( $key ) ) {
            WorkflowModule::registry()->register( new class( $key, $form_class ) extends TaskTemplate {
                public function __construct( private string $k, private string $form ) {}
                public function key(): string { return $this->k; }
                public function name(): string { return 'Keep values task'; }
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

    private function player(): int {
        global $wpdb;
        $wpdb->insert( $wpdb->prefix . 'tt_teams', [ 'club_id' => 1, 'name' => 'JO15-1' ] );
        $team = (int) $wpdb->insert_id;
        $wpdb->insert( $wpdb->prefix . 'tt_players', [
            'club_id'    => 1,
            'team_id'    => $team,
            'first_name' => 'Keep',
            'last_name'  => 'Values',
            'status'     => 'active',
        ] );
        return (int) $wpdb->insert_id;
    }

    /** A completed goal-setting task whose goals a goal-approval task reviews. */
    private function goalSettingParent(): int {
        global $wpdb;
        $parent = $this->task( $this->template( 'test_keep_goal_parent', KeepValuesRefusingForm::class ) );
        $wpdb->update( $wpdb->prefix . 'tt_workflow_tasks', [
            'status'        => 'completed',
            'response_json' => (string) wp_json_encode( [ 'goals' => [ [ 'title' => 'Scan before receiving', 'why' => 'Plays faster' ] ] ] ),
        ], [ 'id' => $parent ] );
        return $parent;
    }

    /** @return array<string, mixed> */
    private function row( int $task_id ): array {
        global $wpdb;
        return (array) $wpdb->get_row( $wpdb->prepare(
            "SELECT status, response_json, completed_at FROM {$wpdb->prefix}tt_workflow_tasks WHERE id = %d",
            $task_id
        ), ARRAY_A );
    }

    /** @param array<string, mixed> $typed */
    private function submit( int $task_id, array $typed, int $as = 0 ): string {
        $as = $as > 0 ? $as : $this->user;
        wp_set_current_user( $as );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $typed + [
            'tt_workflow_submit'                => '1',
            FrontendTaskDetailView::NONCE_FIELD => wp_create_nonce( FrontendTaskDetailView::NONCE_ACTION ),
        ];
        $this->writes = [];
        ob_start();
        FrontendTaskDetailView::render( $as, $task_id );
        return (string) ob_get_clean();
    }
}

/**
 * Renders the real form named in `$inner`, refuses every answer, and fails
 * the test if the view ever asks it to serialise one.
 */
final class KeepValuesRefusingForm implements FormInterface {

    /** @var string class-string of the real form */
    public static string $inner = '';

    public function render( array $task ): string {
        $class = self::$inner;
        return $class !== '' ? ( new $class() )->render( $task ) : '';
    }

    public function validate( array $raw, array $task ): array {
        return [ '__form' => 'Refused for the test.' ];
    }

    public function serializeResponse( array $raw, array $task ): array {
        throw new \LogicException( 'serializeResponse() must not run for a refused answer.' );
    }
}
