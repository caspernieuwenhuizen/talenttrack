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
 * #4421 — a workflow task form that refused an answer on a field reloaded
 * with nothing saying why: the view printed only `__form` and dropped every
 * field-keyed error `validate()` returned. Every error now shows in one
 * notice, `__form` first, announced to screen readers.
 */
final class TaskFormErrorsNoticeTest extends WP_UnitTestCase {

    private const KEY = 'test_task_form_errors';

    private int $user = 0;

    public function set_up(): void {
        parent::set_up();
        $this->user = (int) self::factory()->user->create( [ 'role' => 'administrator' ] );
        AuthorizationService::flushCache();

        if ( ! WorkflowModule::registry()->has( self::KEY ) ) {
            WorkflowModule::registry()->register( new class( self::KEY ) extends TaskTemplate {
                public function __construct( private string $k ) {}
                public function key(): string { return $this->k; }
                public function name(): string { return 'Stub task'; }
                public function description(): string { return ''; }
                public function defaultDeadlineOffset(): string { return '+3 days'; }
                public function defaultAssignee(): AssigneeResolver {
                    return new LambdaResolver( static fn( TaskContext $c ): array => [] );
                }
                public function formClass(): string { return TaskFormErrorsStubForm::class; }
                public function entityLinks(): array { return []; }
            } );
        }
    }

    public function tear_down(): void {
        TaskFormErrorsStubForm::$errors = [];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        wp_set_current_user( 0 );
        parent::tear_down();
    }

    public function test_a_field_error_is_shown_after_submit(): void {
        TaskFormErrorsStubForm::$errors = [ 'outcome' => 'Pick an outcome.' ];

        $html = $this->submit();

        $this->assertStringContainsString( 'Pick an outcome.', $html );
        $this->assertSame( 1, substr_count( $html, 'tt-workflow-task-errors"' ), 'one notice' );
    }

    public function test_the_form_error_comes_first_then_the_fields_in_order(): void {
        TaskFormErrorsStubForm::$errors = [
            'outcome'  => 'Pick an outcome.',
            '__form'   => 'Something is missing.',
            'new_date' => 'Enter a valid date.',
        ];

        $html = $this->submit();

        $form    = strpos( $html, 'Something is missing.' );
        $outcome = strpos( $html, 'Pick an outcome.' );
        $date    = strpos( $html, 'Enter a valid date.' );
        $this->assertNotFalse( $form );
        $this->assertNotFalse( $outcome );
        $this->assertNotFalse( $date );
        $this->assertLessThan( $outcome, $form, '__form comes first' );
        $this->assertLessThan( $date, $outcome, 'then the fields in the order validate() returned them' );
        $this->assertMatchesRegularExpression( '#tt-workflow-task-errors__list">(.*?)</ul>#s', $html );
        preg_match( '#tt-workflow-task-errors__list">(.*?)</ul>#s', $html, $m );
        $this->assertSame( 3, substr_count( $m[1], '<li>' ), 'one item per error, in one notice' );
    }

    public function test_no_errors_means_no_notice(): void {
        $html = $this->render();

        $this->assertStringContainsString( 'Stub task', $html, 'precondition: the task rendered' );
        $this->assertStringNotContainsString( 'tt-workflow-task-errors', $html );
    }

    public function test_the_notice_is_an_alert_without_inline_style(): void {
        TaskFormErrorsStubForm::$errors = [ 'outcome' => 'Pick an outcome.' ];

        $html = $this->submit();

        $this->assertMatchesRegularExpression( '/<div class="[^"]*tt-workflow-task-errors[^"]*" role="alert">/', $html );
        $this->assertDoesNotMatchRegularExpression( '/<div[^>]*tt-workflow-task-errors[^>]*style=/', $html );
    }

    public function test_a_message_is_escaped(): void {
        TaskFormErrorsStubForm::$errors = [ 'outcome' => '<b>Pick</b> an outcome.' ];

        $html = $this->submit();

        $this->assertStringContainsString( '&lt;b&gt;Pick&lt;/b&gt; an outcome.', $html );
    }

    // ---- fixtures -------------------------------------------------------

    private function task(): int {
        $task_id = ( new TasksRepository() )->create( [
            'template_key'     => self::KEY,
            'assignee_user_id' => $this->user,
            'due_at'           => gmdate( 'Y-m-d H:i:s', time() + WEEK_IN_SECONDS ),
        ] );
        $this->assertGreaterThan( 0, $task_id, 'the fixture must write a task' );
        return $task_id;
    }

    private function submit(): string {
        wp_set_current_user( $this->user );
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = [
            'tt_workflow_submit'                => '1',
            FrontendTaskDetailView::NONCE_FIELD => wp_create_nonce( FrontendTaskDetailView::NONCE_ACTION ),
        ];
        return $this->render();
    }

    private function render(): string {
        $task_id = $this->task();
        wp_set_current_user( $this->user );
        ob_start();
        FrontendTaskDetailView::render( $this->user, $task_id );
        return (string) ob_get_clean();
    }
}

/**
 * A form whose validate() returns whatever the test set.
 */
final class TaskFormErrorsStubForm implements FormInterface {

    /** @var array<string,string> */
    public static array $errors = [];

    public function render( array $task ): string {
        return '<p class="tt-stub-form">stub</p>';
    }

    public function validate( array $raw, array $task ): array {
        return self::$errors;
    }

    public function serializeResponse( array $raw, array $task ): array {
        return [];
    }
}
