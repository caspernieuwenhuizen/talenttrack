<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\TaskStatus;
use TT\Modules\Workflow\WorkflowModule;
use TT\Shared\Frontend\FrontendViewBase;

/**
 * FrontendMyTasksView — the inbox surface. Lists the current user's
 * actionable tasks (open / in-progress / overdue) ordered by deadline,
 * with a "Recently completed" section underneath.
 *
 * Each row links to the focused task page where the form is rendered
 * + submitted (FrontendTaskDetailView). The form rendering itself
 * lives behind the FormInterface contract — see Sprint 3 templates.
 */
class FrontendMyTasksView extends FrontendViewBase {

    /**
     * Render the inbox for the current user.
     *
     * @param int $user_id Current user (passed by the dispatcher rather
     *                     than re-derived so the dispatcher's auth gate
     *                     stays the source of truth).
     */
    public static function render( int $user_id ): void {
        self::enqueueAssets();
        self::renderHeader( __( 'My tasks', 'talenttrack' ) );

        $repo = new TasksRepository();
        $actionable = $repo->listActionableForUser( $user_id );
        $recent_done = self::recentlyCompletedForUser( $user_id, 5 );

        ?>
        <style>
            .tt-mtasks-list { list-style: none; padding: 0; margin: 0 0 24px; }
            .tt-mtasks-row {
                display: flex; align-items: center; gap: 12px;
                background: #fff; border: 1px solid #e5e7ea; border-radius: 8px;
                padding: 12px 14px; margin-bottom: 8px;
            }
            .tt-mtasks-row.tt-overdue { border-color: #b32d2e; background: #fff6f6; }
            .tt-mtasks-row.tt-completed { background: #f4f6f4; opacity: 0.85; }
            .tt-mtasks-meta { flex: 1; min-width: 0; }
            .tt-mtasks-title { font-weight: 600; font-size: 15px; color: #1a1d21; margin: 0 0 2px; }
            .tt-mtasks-sub { font-size: 12px; color: #5b6e75; margin: 0; }
            .tt-mtasks-due { font-size: 12px; color: #444; white-space: nowrap; }
            .tt-mtasks-due.tt-overdue-text { color: #b32d2e; font-weight: 600; }
            .tt-mtasks-action a {
                display: inline-block; padding: 6px 12px;
                background: #2271b1; color: #fff; border-radius: 5px;
                text-decoration: none; font-size: 13px;
            }
            .tt-mtasks-action a:hover { background: #195a8e; color: #fff; }
            .tt-mtasks-section-label {
                font-size: 11px; font-weight: 700; letter-spacing: 0.08em;
                text-transform: uppercase; color: #8a9099;
                margin: 24px 0 10px;
            }
            .tt-mtasks-empty { color: #5b6e75; font-style: italic; padding: 12px 0; }
        </style>
        <?php

        echo '<div class="tt-mtasks-section-label">' . esc_html__( 'Open and in progress', 'talenttrack' ) . '</div>';
        if ( empty( $actionable ) ) {
            echo '<p class="tt-mtasks-empty">' . esc_html__( 'No open tasks. You\'re all caught up.', 'talenttrack' ) . '</p>';
        } else {
            echo '<ul class="tt-mtasks-list">';
            foreach ( $actionable as $task ) {
                self::renderRow( $task, false );
            }
            echo '</ul>';
        }

        if ( ! empty( $recent_done ) ) {
            echo '<div class="tt-mtasks-section-label">' . esc_html__( 'Recently completed', 'talenttrack' ) . '</div>';
            echo '<ul class="tt-mtasks-list">';
            foreach ( $recent_done as $task ) {
                self::renderRow( $task, true );
            }
            echo '</ul>';
        }
    }

    /** @param array<string,mixed> $task */
    private static function renderRow( array $task, bool $completed ): void {
        $template = WorkflowModule::registry()->get( (string) ( $task['template_key'] ?? '' ) );
        $title = $template ? $template->name() : (string) ( $task['template_key'] ?? '' );
        $description = $template ? $template->description() : '';
        $due_at = (string) ( $task['due_at'] ?? '' );
        $due_ts = $due_at !== '' ? strtotime( $due_at ) : false;
        $now = current_time( 'timestamp' );
        $is_overdue = ! $completed && $due_ts !== false && $due_ts < $now;

        $row_class = 'tt-mtasks-row';
        if ( $completed ) $row_class .= ' tt-completed';
        elseif ( $is_overdue ) $row_class .= ' tt-overdue';

        $context_label = self::contextLabel( $task );

        ?>
        <li class="<?php echo esc_attr( $row_class ); ?>">
            <div class="tt-mtasks-meta">
                <p class="tt-mtasks-title"><?php echo esc_html( $title ); ?></p>
                <?php if ( $context_label !== '' ) : ?>
                    <p class="tt-mtasks-sub"><?php echo esc_html( $context_label ); ?></p>
                <?php elseif ( $description !== '' ) : ?>
                    <p class="tt-mtasks-sub"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </div>
            <?php if ( $due_at !== '' && ! $completed ) : ?>
                <div class="tt-mtasks-due <?php echo $is_overdue ? 'tt-overdue-text' : ''; ?>">
                    <?php echo esc_html( self::formatDue( $due_at ) ); ?>
                </div>
            <?php elseif ( $completed ) : ?>
                <div class="tt-mtasks-due">
                    <?php echo esc_html( self::formatCompleted( (string) ( $task['completed_at'] ?? '' ) ) ); ?>
                </div>
            <?php endif; ?>
            <?php if ( ! $completed ) : ?>
                <div class="tt-mtasks-action">
                    <a href="<?php echo esc_url( self::detailUrl( (int) $task['id'] ) ); ?>">
                        <?php esc_html_e( 'Open', 'talenttrack' ); ?>
                    </a>
                </div>
            <?php endif; ?>
        </li>
        <?php
    }

    /** @param array<string,mixed> $task */
    private static function contextLabel( array $task ): string {
        $bits = [];
        if ( ! empty( $task['player_id'] ) ) {
            $name = self::playerName( (int) $task['player_id'] );
            if ( $name !== '' ) $bits[] = $name;
        }
        if ( ! empty( $task['team_id'] ) ) {
            $name = self::teamName( (int) $task['team_id'] );
            if ( $name !== '' ) $bits[] = $name;
        }
        if ( ! empty( $task['session_id'] ) ) {
            $bits[] = sprintf( __( 'session #%d', 'talenttrack' ), (int) $task['session_id'] );
        }
        return implode( ' · ', $bits );
    }

    private static function playerName( int $player_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d LIMIT 1",
            $player_id
        ) );
        if ( ! $row ) return '';
        return trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
    }

    private static function teamName( int $team_id ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d LIMIT 1",
            $team_id
        ) );
        return is_string( $name ) ? $name : '';
    }

    private static function formatDue( string $due_at ): string {
        $ts = strtotime( $due_at );
        if ( $ts === false ) return $due_at;
        $now = current_time( 'timestamp' );
        $diff = $ts - $now;
        if ( $diff > 0 && $diff < 86400 ) {
            $hours = (int) round( $diff / 3600 );
            return sprintf( _n( 'in %d hour', 'in %d hours', max( 1, $hours ), 'talenttrack' ), max( 1, $hours ) );
        }
        if ( $diff < 0 && $diff > -86400 ) {
            return __( 'overdue', 'talenttrack' );
        }
        $format = (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' );
        return wp_date( $format, $ts );
    }

    private static function formatCompleted( string $completed_at ): string {
        if ( $completed_at === '' ) return '';
        $ts = strtotime( $completed_at );
        if ( $ts === false ) return $completed_at;
        $format = (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' );
        return wp_date( $format, $ts );
    }

    public static function detailUrl( int $task_id ): string {
        $base = self::dashboardBaseUrl();
        return add_query_arg(
            [ 'tt_view' => 'my-tasks', 'task_id' => $task_id ],
            $base
        );
    }

    private static function dashboardBaseUrl(): string {
        if ( isset( $_SERVER['REQUEST_URI'] ) ) {
            $current = esc_url_raw( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) );
            return remove_query_arg( [ 'tt_view', 'task_id' ], $current );
        }
        return home_url( '/' );
    }

    /**
     * Recently completed tasks for a user (default: last 5).
     *
     * @return array<int, array<string,mixed>>
     */
    private static function recentlyCompletedForUser( int $user_id, int $limit = 5 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_workflow_tasks
             WHERE assignee_user_id = %d AND status = %s
             ORDER BY completed_at DESC, id DESC
             LIMIT %d",
            $user_id, TaskStatus::COMPLETED, $limit
        ), ARRAY_A );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Open-task count for the bell badge. Public so NotificationBell can call it.
     */
    public static function openCountForUser( int $user_id ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_workflow_tasks
             WHERE assignee_user_id = %d AND status IN ('open','in_progress','overdue')",
            $user_id
        ) );
    }
}
