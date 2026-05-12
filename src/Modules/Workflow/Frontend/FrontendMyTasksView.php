<?php
namespace TT\Modules\Workflow\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
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
 *
 * #0022 Phase 2 additions:
 *   - Filters: by template, by status, by due-window.
 *   - Bulk actions: skip multiple tasks at once.
 *   - Snooze: per-row "snooze 1d / 3d / 7d" buttons that hide the task
 *     until snoozed_until elapses. Hidden tasks reappear automatically.
 */
class FrontendMyTasksView extends FrontendViewBase {

    public const NONCE_ACTION = 'tt_workflow_inbox_action';
    public const NONCE_FIELD  = '_tt_workflow_inbox_nonce';

    /**
     * Render the inbox for the current user.
     */
    public static function render( int $user_id ): void {
        self::enqueueAssets();

        // Process bulk / snooze actions before listing — keeps the view a
        // simple "load → render" flow and avoids redirect churn.
        $flash = self::handleSubmission( $user_id );

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard( __( 'My tasks', 'talenttrack' ) );
        self::renderHeader( __( 'My tasks', 'talenttrack' ) );

        $repo = new TasksRepository();
        $filters = self::filtersFromQuery();
        $actionable = $repo->listActionableForUser( $user_id, $filters );
        $recent_done = self::recentlyCompletedForUser( $user_id, 5 );
        $template_keys = $repo->templateKeysForUser( $user_id );

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
            .tt-mtasks-checkbox { flex: 0 0 auto; }
            .tt-mtasks-meta { flex: 1; min-width: 0; }
            .tt-mtasks-title { font-weight: 600; font-size: 15px; color: #1a1d21; margin: 0 0 2px; }
            .tt-mtasks-sub { font-size: 12px; color: #5b6e75; margin: 0; }
            .tt-mtasks-due { font-size: 12px; color: #444; white-space: nowrap; }
            .tt-mtasks-due.tt-overdue-text { color: #b32d2e; font-weight: 600; }
            .tt-mtasks-action a,
            .tt-mtasks-action a:link,
            .tt-mtasks-action a:visited,
            .tt-mtasks-snooze button {
                display: inline-block; padding: 6px 10px;
                background: #2271b1; color: #fff !important; border-radius: 5px;
                text-decoration: none; font-size: 12px; font-weight: 600;
                border: 0; cursor: pointer;
            }
            .tt-mtasks-snooze button { background: #5b6e75; color: #fff; margin-left: 4px; }
            .tt-mtasks-snooze button:hover,
            .tt-mtasks-snooze button:focus { background: #444; color: #fff; }
            .tt-mtasks-action a:hover,
            .tt-mtasks-action a:focus { background: #195a8e; color: #fff !important; text-decoration: none; }
            /* Filter-bar Apply button — force readable hover; some themes
               invert the contrast on `.tt-btn-secondary:hover` and the
               white-on-light-grey result is unreadable. */
            .tt-mtasks-filters button.tt-btn,
            .tt-mtasks-filters button.tt-btn:link,
            .tt-mtasks-filters button.tt-btn:visited {
                background: #2271b1; color: #fff; border: 1px solid #2271b1;
                padding: 6px 14px; border-radius: 5px; font-weight: 600; cursor: pointer;
            }
            .tt-mtasks-filters button.tt-btn:hover,
            .tt-mtasks-filters button.tt-btn:focus {
                background: #195a8e; color: #fff; border-color: #195a8e;
            }
            .tt-mtasks-section-label {
                font-size: 11px; font-weight: 700; letter-spacing: 0.08em;
                text-transform: uppercase; color: #8a9099;
                margin: 24px 0 10px;
            }
            .tt-mtasks-empty { color: #5b6e75; font-style: italic; padding: 12px 0; }
            .tt-mtasks-filters {
                background: #fff; border: 1px solid #e5e7ea; border-radius: 8px;
                padding: 10px 14px; margin: 0 0 16px;
                display: flex; gap: 12px; align-items: center; flex-wrap: wrap;
            }
            .tt-mtasks-filters label { font-size: 12px; color: #5b6e75; }
            .tt-mtasks-filters select { font-size: 13px; padding: 4px 6px; }
            .tt-mtasks-bulkbar {
                background: #fafbfc; border: 1px solid #e5e7ea; border-radius: 6px;
                padding: 8px 12px; margin: 0 0 12px; display: none; align-items: center; gap: 12px;
            }
            .tt-mtasks-bulkbar.tt-active { display: flex; }
            .tt-mtasks-flash {
                background:#e9f5e9; border-left:4px solid #2c8a2c; padding:8px 12px;
                margin: 0 0 12px; font-size: 13px;
            }
        </style>
        <?php

        if ( $flash !== '' ) {
            echo '<div class="tt-mtasks-flash">' . esc_html( $flash ) . '</div>';
        }

        self::renderFilters( $template_keys, $filters );

        echo '<form method="post" data-tt-mtasks-form="1">';
        wp_nonce_field( self::NONCE_ACTION, self::NONCE_FIELD );
        // Preserve filter state through the bulk submit.
        foreach ( self::passThroughFilters( $filters ) as $k => $v ) {
            printf( '<input type="hidden" name="%s" value="%s" />', esc_attr( $k ), esc_attr( $v ) );
        }
        echo '<div class="tt-mtasks-bulkbar" data-tt-mtasks-bulkbar="1">';
        echo '<span data-tt-mtasks-count="0" style="font-size:13px; color:#5b6e75;">0 ' . esc_html__( 'selected', 'talenttrack' ) . '</span>';
        echo '<button type="submit" name="tt_inbox_action" value="bulk_skip" class="tt-btn tt-btn-secondary tt-btn-sm" onclick="return confirm(\'' . esc_js( __( 'Skip the selected tasks? They will be marked as no-longer-applicable.', 'talenttrack' ) ) . '\')">' . esc_html__( 'Skip selected', 'talenttrack' ) . '</button>';
        echo '<button type="submit" name="tt_inbox_action" value="bulk_snooze_1d" class="tt-btn tt-btn-secondary tt-btn-sm">' . esc_html__( 'Snooze 1 day', 'talenttrack' ) . '</button>';
        echo '<button type="submit" name="tt_inbox_action" value="bulk_snooze_7d" class="tt-btn tt-btn-secondary tt-btn-sm">' . esc_html__( 'Snooze 7 days', 'talenttrack' ) . '</button>';
        echo '</div>';

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

        echo '</form>';

        if ( ! empty( $recent_done ) ) {
            echo '<div class="tt-mtasks-section-label">' . esc_html__( 'Recently completed', 'talenttrack' ) . '</div>';
            echo '<ul class="tt-mtasks-list">';
            foreach ( $recent_done as $task ) {
                self::renderRow( $task, true );
            }
            echo '</ul>';
        }

        // Tiny inline JS hooks the bulk-bar visibility to checkbox state.
        ?>
        <script>
        (function () {
            var form = document.querySelector('[data-tt-mtasks-form]');
            if (!form) return;
            var bar = form.querySelector('[data-tt-mtasks-bulkbar]');
            var count = bar ? bar.querySelector('[data-tt-mtasks-count]') : null;
            if (!bar || !count) return;
            form.addEventListener('change', function () {
                var checks = form.querySelectorAll('.tt-mtasks-checkbox input[type=checkbox]:checked');
                count.setAttribute('data-tt-mtasks-count', checks.length);
                count.textContent = checks.length + ' <?php echo esc_js( __( 'selected', 'talenttrack' ) ); ?>';
                bar.classList.toggle('tt-active', checks.length > 0);
            });
        })();
        </script>
        <?php
    }

    /** @param array<string,mixed> $task */
    private static function renderRow( array $task, bool $completed ): void {
        $template = WorkflowModule::registry()->get( (string) ( $task['template_key'] ?? '' ) );
        $template_name = $template ? $template->name() : (string) ( $task['template_key'] ?? '' );
        $description = $template ? $template->description() : '';
        $due_at = (string) ( $task['due_at'] ?? '' );
        $due_ts = $due_at !== '' ? strtotime( $due_at ) : false;
        $now = current_time( 'timestamp' );
        $is_overdue = ! $completed && $due_ts !== false && $due_ts < $now;

        $row_class = 'tt-mtasks-row';
        if ( $completed ) $row_class .= ' tt-completed';
        elseif ( $is_overdue ) $row_class .= ' tt-overdue';

        // Player-centric (CLAUDE.md § 1): when the task targets a player,
        // lead the row title with the player's name so the inbox is
        // scannable. The template name becomes a subordinate line — the
        // operator answers "who is this about" before "what is the task".
        $player_name = ! empty( $task['player_id'] ) ? self::playerName( (int) $task['player_id'] ) : '';
        $title = $player_name !== '' ? $player_name : $template_name;
        $sub_lines = [];
        if ( $player_name !== '' ) {
            $sub_lines[] = $template_name;
        }
        $context_label = self::contextLabel( $task, /* skip_player */ true );
        if ( $context_label !== '' ) {
            $sub_lines[] = $context_label;
        } elseif ( $player_name === '' && $description !== '' ) {
            $sub_lines[] = $description;
        }
        $task_id = (int) ( $task['id'] ?? 0 );

        ?>
        <li class="<?php echo esc_attr( $row_class ); ?>">
            <?php if ( ! $completed ) : ?>
                <div class="tt-mtasks-checkbox">
                    <input type="checkbox" name="task_ids[]" value="<?php echo (int) $task_id; ?>" />
                </div>
            <?php endif; ?>
            <div class="tt-mtasks-meta">
                <p class="tt-mtasks-title"><?php echo esc_html( $title ); ?></p>
                <?php foreach ( $sub_lines as $line ) : ?>
                    <p class="tt-mtasks-sub"><?php echo esc_html( $line ); ?></p>
                <?php endforeach; ?>
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
                    <a href="<?php echo esc_url( self::detailUrl( $task_id ) ); ?>">
                        <?php esc_html_e( 'Open', 'talenttrack' ); ?>
                    </a>
                </div>
                <div class="tt-mtasks-snooze">
                    <button type="submit" name="tt_inbox_action" value="snooze_1d:<?php echo (int) $task_id; ?>" title="<?php esc_attr_e( 'Snooze for 1 day', 'talenttrack' ); ?>"><?php esc_html_e( '1d', 'talenttrack' ); ?></button>
                    <button type="submit" name="tt_inbox_action" value="snooze_7d:<?php echo (int) $task_id; ?>" title="<?php esc_attr_e( 'Snooze for 7 days', 'talenttrack' ); ?>"><?php esc_html_e( '7d', 'talenttrack' ); ?></button>
                </div>
            <?php endif; ?>
        </li>
        <?php
    }

    /** @param list<string> $template_keys @param array<string,mixed> $filters */
    private static function renderFilters( array $template_keys, array $filters ): void {
        $base = self::dashboardBaseUrl();
        ?>
        <form class="tt-mtasks-filters" method="get" action="<?php echo esc_url( $base ); ?>">
            <input type="hidden" name="tt_view" value="my-tasks" />
            <label>
                <?php esc_html_e( 'Template', 'talenttrack' ); ?>:
                <select name="filter_template">
                    <option value=""><?php esc_html_e( 'All', 'talenttrack' ); ?></option>
                    <?php foreach ( $template_keys as $tk ) :
                        $template = WorkflowModule::registry()->get( $tk );
                        $label = $template ? $template->name() : $tk;
                        ?>
                        <option value="<?php echo esc_attr( $tk ); ?>" <?php selected( ( $filters['template_key'] ?? '' ), $tk ); ?>><?php echo esc_html( $label ); ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label>
                <?php esc_html_e( 'Status', 'talenttrack' ); ?>:
                <select name="filter_status">
                    <option value=""><?php esc_html_e( 'All actionable', 'talenttrack' ); ?></option>
                    <option value="open" <?php selected( implode( ',', $filters['status'] ?? [] ), 'open' ); ?>><?php esc_html_e( 'Open', 'talenttrack' ); ?></option>
                    <option value="in_progress" <?php selected( implode( ',', $filters['status'] ?? [] ), 'in_progress' ); ?>><?php esc_html_e( 'In progress', 'talenttrack' ); ?></option>
                    <option value="overdue" <?php selected( implode( ',', $filters['status'] ?? [] ), 'overdue' ); ?>><?php esc_html_e( 'Overdue', 'talenttrack' ); ?></option>
                </select>
            </label>
            <label>
                <?php esc_html_e( 'Due within', 'talenttrack' ); ?>:
                <select name="filter_due_within">
                    <option value=""><?php esc_html_e( 'Any', 'talenttrack' ); ?></option>
                    <option value="1" <?php selected( ( $filters['due_within_days'] ?? '' ), 1 ); ?>><?php esc_html_e( '24 hours', 'talenttrack' ); ?></option>
                    <option value="3" <?php selected( ( $filters['due_within_days'] ?? '' ), 3 ); ?>><?php esc_html_e( '3 days', 'talenttrack' ); ?></option>
                    <option value="7" <?php selected( ( $filters['due_within_days'] ?? '' ), 7 ); ?>><?php esc_html_e( '7 days', 'talenttrack' ); ?></option>
                </select>
            </label>
            <label>
                <input type="checkbox" name="show_snoozed" value="1" <?php checked( ! empty( $filters['include_snoozed'] ) ); ?> />
                <?php esc_html_e( 'Show snoozed', 'talenttrack' ); ?>
            </label>
            <button type="submit" class="tt-btn tt-btn-secondary tt-btn-sm"><?php esc_html_e( 'Apply', 'talenttrack' ); ?></button>
        </form>
        <?php
    }

    /** @return array<string,mixed> */
    private static function filtersFromQuery(): array {
        $out = [];
        if ( ! empty( $_GET['filter_template'] ) ) {
            $out['template_key'] = sanitize_key( (string) $_GET['filter_template'] );
        }
        if ( ! empty( $_GET['filter_status'] ) ) {
            $s = sanitize_key( (string) $_GET['filter_status'] );
            if ( $s !== '' ) $out['status'] = [ $s ];
        }
        if ( ! empty( $_GET['filter_due_within'] ) ) {
            $out['due_within_days'] = (int) $_GET['filter_due_within'];
        }
        if ( ! empty( $_GET['show_snoozed'] ) ) {
            $out['include_snoozed'] = true;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,string>
     */
    private static function passThroughFilters( array $filters ): array {
        $out = [ 'tt_view' => 'my-tasks' ];
        if ( ! empty( $filters['template_key'] ) ) $out['filter_template'] = (string) $filters['template_key'];
        if ( ! empty( $filters['status'] ) ) $out['filter_status'] = (string) $filters['status'][0];
        if ( ! empty( $filters['due_within_days'] ) ) $out['filter_due_within'] = (string) $filters['due_within_days'];
        if ( ! empty( $filters['include_snoozed'] ) ) $out['show_snoozed'] = '1';
        return $out;
    }

    /**
     * Process bulk + snooze submissions. Returns a flash message on
     * success, empty string otherwise.
     */
    private static function handleSubmission( int $user_id ): string {
        if ( $_SERVER['REQUEST_METHOD'] !== 'POST' ) return '';
        if ( empty( $_POST['tt_inbox_action'] ) ) return '';
        if ( ! isset( $_POST[ self::NONCE_FIELD ] )
            || ! wp_verify_nonce( sanitize_text_field( (string) $_POST[ self::NONCE_FIELD ] ), self::NONCE_ACTION ) ) {
            return __( 'Security check failed. Please refresh and try again.', 'talenttrack' );
        }

        $action = sanitize_text_field( (string) $_POST['tt_inbox_action'] );
        $repo = new TasksRepository();

        // Per-row snooze actions: "snooze_1d:123" / "snooze_7d:123".
        if ( strpos( $action, ':' ) !== false ) {
            [ $kind, $id_str ] = explode( ':', $action, 2 );
            $task_id = (int) $id_str;
            if ( $task_id > 0 ) {
                $task = $repo->find( $task_id );
                if ( $task && (int) $task['assignee_user_id'] === $user_id ) {
                    $until = self::snoozeUntil( $kind );
                    if ( $until !== null ) {
                        $repo->snooze( $task_id, $until );
                        return __( 'Task snoozed.', 'talenttrack' );
                    }
                }
            }
            return '';
        }

        $ids = isset( $_POST['task_ids'] ) && is_array( $_POST['task_ids'] )
            ? array_map( 'absint', $_POST['task_ids'] )
            : [];
        $ids = array_filter( $ids );
        if ( empty( $ids ) ) return '';

        // Owner-check every id before mutating.
        $own_ids = [];
        foreach ( $ids as $id ) {
            $task = $repo->find( $id );
            if ( $task && (int) $task['assignee_user_id'] === $user_id ) {
                $own_ids[] = $id;
            }
        }
        if ( empty( $own_ids ) ) return '';

        switch ( $action ) {
            case 'bulk_skip':
                foreach ( $own_ids as $id ) $repo->skip( $id );
                return sprintf(
                    /* translators: %d task count */
                    _n( '%d task skipped.', '%d tasks skipped.', count( $own_ids ), 'talenttrack' ),
                    count( $own_ids )
                );
            case 'bulk_snooze_1d':
                $until = self::snoozeUntil( 'snooze_1d' );
                foreach ( $own_ids as $id ) $repo->snooze( $id, $until );
                return sprintf(
                    _n( '%d task snoozed for 1 day.', '%d tasks snoozed for 1 day.', count( $own_ids ), 'talenttrack' ),
                    count( $own_ids )
                );
            case 'bulk_snooze_7d':
                $until = self::snoozeUntil( 'snooze_7d' );
                foreach ( $own_ids as $id ) $repo->snooze( $id, $until );
                return sprintf(
                    _n( '%d task snoozed for 7 days.', '%d tasks snoozed for 7 days.', count( $own_ids ), 'talenttrack' ),
                    count( $own_ids )
                );
        }
        return '';
    }

    private static function snoozeUntil( string $kind ): ?string {
        $now = current_time( 'timestamp' );
        switch ( $kind ) {
            case 'snooze_1d': return date( 'Y-m-d H:i:s', $now + 86400 );
            case 'snooze_3d': return date( 'Y-m-d H:i:s', $now + 3 * 86400 );
            case 'snooze_7d': return date( 'Y-m-d H:i:s', $now + 7 * 86400 );
        }
        return null;
    }

    /** @param array<string,mixed> $task */
    private static function contextLabel( array $task, bool $skip_player = false ): string {
        $bits = [];
        if ( ! $skip_player && ! empty( $task['player_id'] ) ) {
            $name = self::playerName( (int) $task['player_id'] );
            if ( $name !== '' ) $bits[] = $name;
        }
        if ( ! empty( $task['team_id'] ) ) {
            $name = self::teamName( (int) $task['team_id'] );
            if ( $name !== '' ) $bits[] = $name;
        }
        if ( ! empty( $task['activity_id'] ) ) {
            $bits[] = sprintf( __( 'activity #%d', 'talenttrack' ), (int) $task['activity_id'] );
        }
        return implode( ' · ', $bits );
    }

    private static function playerName( int $player_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        if ( ! $row ) return '';
        return trim( ( $row->first_name ?? '' ) . ' ' . ( $row->last_name ?? '' ) );
    }

    private static function teamName( int $team_id ): string {
        global $wpdb;
        $name = $wpdb->get_var( $wpdb->prepare(
            "SELECT name FROM {$wpdb->prefix}tt_teams WHERE id = %d AND club_id = %d LIMIT 1",
            $team_id, CurrentClub::id()
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
            return remove_query_arg(
                [ 'tt_view', 'task_id', 'filter_template', 'filter_status', 'filter_due_within', 'show_snoozed' ],
                $current
            );
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
             WHERE assignee_user_id = %d AND status = %s AND club_id = %d
             ORDER BY completed_at DESC, id DESC
             LIMIT %d",
            $user_id, TaskStatus::COMPLETED, CurrentClub::id(), $limit
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
             WHERE assignee_user_id = %d
               AND club_id = %d
               AND status IN ('open','in_progress','overdue')
               AND (snoozed_until IS NULL OR snoozed_until <= %s)",
            $user_id, CurrentClub::id(), current_time( 'mysql' )
        ) );
    }
}
