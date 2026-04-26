<?php
namespace TT\Modules\Workflow\Notifications;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\WorkflowModule;

/**
 * TaskMailer — listens on `tt_workflow_task_created` and sends the
 * assignee a plain-text email. Keeps the engine's dispatch path lean
 * (the engine doesn't know about email; it just fires the action).
 *
 * Idempotency: the engine fires the action exactly once per persisted
 * task row, so duplicate sends are not a concern at this layer.
 *
 * Failure handling: wp_mail returning false is logged under WP_DEBUG
 * but does not affect task creation. The cron-self-diagnostic banner
 * (CronHealthCheck) flags persistently-overdue tasks separately.
 */
class TaskMailer {

    public static function init(): void {
        add_action( 'tt_workflow_task_created', [ self::class, 'sendOnCreate' ], 10, 3 );
    }

    public static function sendOnCreate( int $task_id, string $template_key, int $assignee_user_id ): void {
        if ( $assignee_user_id <= 0 ) return;
        $user = get_userdata( $assignee_user_id );
        if ( ! $user || empty( $user->user_email ) ) return;

        $task = ( new TasksRepository() )->find( $task_id );
        if ( $task === null ) return;

        $template = WorkflowModule::registry()->get( $template_key );
        $template_name = $template ? $template->name() : $template_key;

        $site_name = get_bloginfo( 'name' ) ?: 'TalentTrack';

        /* translators: 1: site name, 2: template name */
        $subject = sprintf(
            __( '[%1$s] New task: %2$s', 'talenttrack' ),
            $site_name,
            $template_name
        );

        $due_label = self::formatDue( (string) ( $task['due_at'] ?? '' ) );
        $inbox_url = self::inboxUrl();

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

        $sent = wp_mail( $user->user_email, $subject, implode( "\n", $body_lines ) );
        if ( ! $sent && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[TalentTrack workflow] TaskMailer: wp_mail returned false for task %d to %s',
                $task_id,
                $user->user_email
            ) );
        }
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
