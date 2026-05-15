<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\TaskStatus;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendTaskDetailView — renders a single task's form for the
 * assignee. Reached from the inbox via `?tt_view=my-tasks&task_id=N`.
 *
 * The form is delegated to the template's FormInterface implementation.
 * On POST submission, this view validates + serialises + calls
 * `TaskEngine::complete()`. On success, redirects back to the inbox
 * with a flash banner.
 */
class FrontendTaskDetailView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_workflow_task_complete';
    public const NONCE_FIELD  = '_tt_workflow_nonce';

    public static function render( int $user_id, int $task_id ): void {
        self::enqueueAssets();

        $repo = new TasksRepository();
        $task = $repo->find( $task_id );

        // Breadcrumb chain — resolve the template's translated name
        // instead of leaking the raw `template_key` into the crumb (a
        // technical slug like "record_test_training_outcome" is never
        // a Dutch translation and confuses operators).
        $tasks_label = __( 'My tasks', 'talenttrack' );
        $current_label = __( 'Task', 'talenttrack' );
        if ( $task !== null && ! empty( $task['template_key'] ) ) {
            $tpl = WorkflowModule::registry()->get( (string) $task['template_key'] );
            if ( $tpl !== null ) {
                $current_label = $tpl->name();
            }
        }
        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            $current_label,
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'my-tasks', $tasks_label ) ]
        );

        if ( $task === null ) {
            self::renderHeader( __( 'Task not found', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'This task does not exist (or has been removed).', 'talenttrack' ) . '</p>';
            return;
        }
        // v3.110.98 — non-assignees can VIEW the task (template name,
        // description, assignee, status, response when completed) but
        // cannot edit / submit. Reached when an operator clicks a
        // kanban card whose underlying task is held by someone else
        // (typically scout viewing an HoD-assigned invite task).
        $is_assignee = (int) $task['assignee_user_id'] === $user_id;

        $template = WorkflowModule::registry()->get( (string) $task['template_key'] );
        if ( $template === null ) {
            self::renderHeader( __( 'Task template missing', 'talenttrack' ) );
            echo '<p class="tt-notice">' . esc_html__( 'The template for this task is no longer registered. Contact an administrator.', 'talenttrack' ) . '</p>';
            return;
        }

        $form_class = $template->formClass();
        if ( $form_class === '' || ! class_exists( $form_class ) ) {
            self::renderHeader( $template->name() );
            echo '<p class="tt-notice">' . esc_html__( 'This task has no form configured.', 'talenttrack' ) . '</p>';
            return;
        }

        /** @var \TT\Modules\Workflow\Contracts\FormInterface $form */
        $form = new $form_class();

        $errors = [];
        $flash  = '';

        // Submission path — only the assignee can submit. Non-assignee
        // POSTs are silently ignored (no error notice — the submit
        // button isn't rendered for them so reaching this branch means
        // a manual POST or a stale form).
        if ( $is_assignee && $_SERVER['REQUEST_METHOD'] === 'POST' && isset( $_POST['tt_workflow_submit'] ) ) {
            $nonce_ok = isset( $_POST[ self::NONCE_FIELD ] )
                && wp_verify_nonce( sanitize_text_field( (string) $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION );
            if ( ! $nonce_ok ) {
                $errors['__form'] = __( 'Security check failed. Please refresh the page and try again.', 'talenttrack' );
            } else {
                $raw = wp_unslash( $_POST );
                if ( ! is_array( $raw ) ) $raw = [];
                $errors = $form->validate( $raw, $task );
                if ( empty( $errors ) ) {
                    $response = $form->serializeResponse( $raw, $task );
                    $ok = WorkflowModule::engine()->complete( $task_id, $response );
                    if ( $ok ) {
                        $redirect = remove_query_arg( 'task_id' ) . '&tt_workflow_done=1';
                        if ( ! headers_sent() ) {
                            wp_safe_redirect( $redirect );
                            exit;
                        }
                        $flash = __( 'Task completed.', 'talenttrack' );
                    } else {
                        $errors['__form'] = __( 'Could not save the task. Please try again.', 'talenttrack' );
                    }
                }
            }
        }

        // Re-fetch in case completion succeeded but redirect failed.
        $task = $repo->find( $task_id );
        if ( $task === null ) {
            self::renderHeader( __( 'Task not found', 'talenttrack' ) );
            return;
        }
        $is_completed = (string) $task['status'] === TaskStatus::COMPLETED;

        echo '<h1 class="tt-fview-title" style="margin:6px 0 18px; font-size:22px; color:#1a1d21;">'
            . esc_html( $template->name() )
            . '</h1>';

        if ( $template->description() !== '' ) {
            echo '<p style="color:#5b6e75; margin: 0 0 16px;">' . esc_html( $template->description() ) . '</p>';
        }

        if ( $flash !== '' ) {
            echo '<div class="tt-notice notice-success" style="background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px; margin: 8px 0 16px;">'
                . esc_html( $flash ) . '</div>';
        }

        if ( $is_completed ) {
            echo '<p style="color:#5b6e75; margin: 0 0 16px;"><em>'
                . esc_html__( 'This task has already been completed. Below is the response that was submitted.', 'talenttrack' )
                . '</em></p>';
        }

        // v3.110.98 — surfacing assignee + status + due date for everyone
        // (assignee and viewer alike). Non-assignees get an explicit
        // banner explaining they can read but not edit.
        echo self::renderTaskFacts( $task, $is_assignee );

        if ( ! empty( $errors['__form'] ) ) {
            echo '<div class="tt-notice notice-error" style="background:#fdecea; border-left:4px solid #b32d2e; padding:8px 12px; margin: 8px 0 16px;">'
                . esc_html( $errors['__form'] ) . '</div>';
        }

        echo '<form method="post" class="tt-workflow-form">';
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        // Wrap the form in <fieldset disabled> when the viewer isn't
        // the assignee. <fieldset disabled> is the native HTML way to
        // disable every interactive control inside without per-field
        // changes to the form class.
        $needs_lock = ! $is_assignee && ! $is_completed;
        if ( $needs_lock ) echo '<fieldset disabled style="border:0; padding:0; margin:0;">';
        echo $form->render( $task );
        if ( $needs_lock ) echo '</fieldset>';
        if ( $is_assignee && ! $is_completed ) {
            echo '<p style="margin-top: 18px;"><button type="submit" name="tt_workflow_submit" value="1" class="button button-primary" style="padding:8px 18px;">'
                . esc_html__( 'Submit', 'talenttrack' )
                . '</button></p>';
        }
        echo '</form>';
    }

    /**
     * Render a small facts panel above the form: who the task is
     * assigned to, current status, due date. Non-assignees see an
     * explicit "view-only" banner.
     *
     * @param array<string,mixed> $task
     */
    private static function renderTaskFacts( array $task, bool $is_assignee ): string {
        $assignee_id = (int) ( $task['assignee_user_id'] ?? 0 );
        $assignee_name = '';
        if ( $assignee_id > 0 ) {
            $u = get_userdata( $assignee_id );
            if ( $u ) $assignee_name = (string) $u->display_name;
        }
        if ( $assignee_name === '' ) $assignee_name = __( 'unassigned', 'talenttrack' );

        $status_raw = (string) ( $task['status'] ?? '' );
        $status_map = [
            'open'        => __( 'Open',        'talenttrack' ),
            'in_progress' => __( 'In progress', 'talenttrack' ),
            'overdue'     => __( 'Overdue',     'talenttrack' ),
            'completed'   => __( 'Completed',   'talenttrack' ),
            'cancelled'   => __( 'Cancelled',   'talenttrack' ),
        ];
        $status_label = $status_map[ $status_raw ] ?? $status_raw;

        $due_raw = (string) ( $task['due_at'] ?? '' );
        $due_label = '';
        if ( $due_raw !== '' ) {
            $ts = strtotime( $due_raw );
            if ( $ts !== false ) {
                $due_label = (string) wp_date( (string) get_option( 'date_format', 'Y-m-d' ), $ts );
            }
        }

        $out  = '<dl class="tt-task-facts" style="display:grid; grid-template-columns: max-content 1fr; gap: 6px 16px; margin: 0 0 16px; color:#1a1d21;">';
        $out .= '<dt style="font-weight:600; color:#5b6e75;">' . esc_html__( 'Assigned to', 'talenttrack' ) . '</dt><dd style="margin:0;">' . esc_html( $assignee_name ) . '</dd>';
        $out .= '<dt style="font-weight:600; color:#5b6e75;">' . esc_html__( 'Status',      'talenttrack' ) . '</dt><dd style="margin:0;">' . esc_html( $status_label ) . '</dd>';
        if ( $due_label !== '' ) {
            $out .= '<dt style="font-weight:600; color:#5b6e75;">' . esc_html__( 'Due', 'talenttrack' ) . '</dt><dd style="margin:0;">' . esc_html( $due_label ) . '</dd>';
        }
        $out .= '</dl>';

        if ( ! $is_assignee ) {
            $out .= '<div class="tt-notice" style="background:#fff7e6; border-left:4px solid #d8a83b; padding:10px 12px; margin: 0 0 16px;">'
                . esc_html__( 'You can view this task, but only the assignee can edit or complete it.', 'talenttrack' )
                . '</div>';
        }
        return $out;
    }
}
