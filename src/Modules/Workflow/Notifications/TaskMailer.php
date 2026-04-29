<?php
namespace TT\Modules\Workflow\Notifications;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Push\DispatcherChain;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\Repositories\TemplateConfigRepository;
use TT\Modules\Workflow\WorkflowModule;

/**
 * TaskMailer — listens on `tt_workflow_task_created` and dispatches
 * the new task to the assignee. Keeps the engine's dispatch path lean
 * (the engine doesn't know about channels; it just fires the action).
 *
 * Channel selection comes from the template's
 * `tt_workflow_template_config.dispatcher_chain` value (#0042 Sprint 5).
 * NULL or `email` keeps the legacy behaviour — a single `wp_mail` to
 * the assignee. Push-aware presets route through `DispatcherChain`,
 * which falls back to email only if push is unavailable for the user.
 *
 * Idempotency: the engine fires the action exactly once per persisted
 * task row, so duplicate sends are not a concern at this layer.
 *
 * Failure handling: a delivery failure logs under WP_DEBUG but does
 * not affect task creation. The cron-self-diagnostic banner
 * (CronHealthCheck) flags persistently-overdue tasks separately.
 */
class TaskMailer {

    public static function init(): void {
        add_action( 'tt_workflow_task_created', [ self::class, 'sendOnCreate' ], 10, 3 );
    }

    public static function sendOnCreate( int $task_id, string $template_key, int $assignee_user_id ): void {
        if ( $assignee_user_id <= 0 ) return;
        $user = get_userdata( $assignee_user_id );
        if ( ! $user ) return;

        $task = ( new TasksRepository() )->find( $task_id );
        if ( $task === null ) return;

        $template      = WorkflowModule::registry()->get( $template_key );
        $template_name = $template ? $template->name() : $template_key;
        $site_name     = get_bloginfo( 'name' ) ?: 'TalentTrack';
        $due_label     = self::formatDue( (string) ( $task['due_at'] ?? '' ) );
        $inbox_url     = self::inboxUrl();

        /* translators: 1: site name, 2: template name */
        $subject = sprintf(
            __( '[%1$s] New task: %2$s', 'talenttrack' ),
            $site_name,
            $template_name
        );

        $body_lines = [];
        $body_lines[] = sprintf(
            /* translators: %s: assignee display name */
            __( 'Hi %s,', 'talenttrack' ),
            $user->display_name ?: $user->user_login
        );
        $body_lines[] = '';
        $body_lines[] = sprintf(
            /* translators: 1: task name, 2: deadline */
            __( 'A new task has been assigned to you: %1$s. Deadline: %2$s.', 'talenttrack' ),
            $template_name,
            $due_label
        );
        $body_lines[] = '';
        if ( $inbox_url !== '' ) {
            $body_lines[] = __( 'Open your task inbox:', 'talenttrack' );
            $body_lines[] = $inbox_url;
            $body_lines[] = '';
        }
        $body_lines[] = sprintf(
            /* translators: %s: site name */
            __( '— %s', 'talenttrack' ),
            $site_name
        );
        $body = implode( "\n", $body_lines );

        // #0042 Sprint 5 — read the template's chain preset. NULL /
        // 'email' keeps the legacy single-email path so installed
        // clubs see no behaviour change.
        $config = ( new TemplateConfigRepository() )->findByKey( $template_key );
        $chain  = (string) ( $config['dispatcher_chain'] ?? '' );

        if ( $chain === '' || $chain === DispatcherChain::PRESET_EMAIL_ONLY ) {
            if ( empty( $user->user_email ) ) return;
            $sent = wp_mail( $user->user_email, $subject, $body );
            if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[TalentTrack workflow] TaskMailer: wp_mail returned false for task %d to %s',
                    $task_id,
                    $user->user_email
                ) );
            }
            return;
        }

        // Push-aware preset — route through the dispatcher chain.
        DispatcherChain::run( $chain, [
            'user_id' => $assignee_user_id,
            'title'   => $subject,
            'body'    => sprintf(
                /* translators: 1: task name, 2: deadline */
                __( 'New task: %1$s. Deadline: %2$s.', 'talenttrack' ),
                $template_name,
                $due_label
            ),
            'url'     => $inbox_url !== '' ? $inbox_url : home_url( '/' ),
            'tag'     => 'tt-task-' . $task_id,
            'event'   => 'workflow_task_created',
            'data'    => [
                'task_id'       => $task_id,
                'template_key'  => $template_key,
                'plain_email'   => [
                    'subject' => $subject,
                    'body'    => $body,
                ],
            ],
        ] );
    }

    private static function formatDue( string $due_at ): string {
        if ( $due_at === '' ) return __( 'no deadline', 'talenttrack' );
        $ts = strtotime( $due_at );
        if ( $ts === false ) return $due_at;
        $format = (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' );
        return wp_date( $format . ' H:i', $ts );
    }

    /**
     * Best-effort URL to the dashboard's My Tasks view. Reads the
     * configured dashboard page; if none, falls back to home_url.
     */
    private static function inboxUrl(): string {
        $page_id = (int) QueryHelpers::get_config( 'dashboard_page_id', '0' );
        if ( $page_id > 0 ) {
            $url = get_permalink( $page_id );
            if ( $url ) return add_query_arg( 'tt_view', 'my-tasks', $url );
        }
        return add_query_arg( 'tt_view', 'my-tasks', home_url( '/' ) );
    }
}
