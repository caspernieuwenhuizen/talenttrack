<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\Workflow\Repositories\TasksRepository;
use TT\Modules\Workflow\WorkflowModule;

/**
 * TaskListPanelWidget — preview of the user's open workflow tasks.
 *
 * Renders up to N rows from the workflow engine, plus a "see all" link
 * to ?tt_view=my-tasks. Reads via TasksRepository::listActionableForUser
 * so the widget and the my-tasks page never diverge on what counts as
 * "open" (open / in_progress / overdue, snoozed rows excluded).
 */
class TaskListPanelWidget extends AbstractWidget {

    public function id(): string { return 'task_list_panel'; }

    public function label(): string { return __( 'Task list panel', 'talenttrack' ); }

    public function defaultSize(): string { return Size::L; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L, Size::XL ]; }

    public function defaultMobilePriority(): int { return 20; }

    public function personaContext(): string { return PersonaContext::COACH; }

    public function capRequired(): string { return 'tt_view_own_tasks'; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $rows = $this->fetchRows( $ctx->user_id );
        $title = $slot->persona_label !== '' ? $slot->persona_label : __( 'My tasks', 'talenttrack' );
        $see_all = $ctx->viewUrl( 'my-tasks' );

        $body = '';
        if ( empty( $rows ) ) {
            $body = '<div class="tt-pd-task-empty">' . esc_html__( 'No open tasks.', 'talenttrack' ) . '</div>';
        } else {
            $items = '';
            foreach ( $rows as $row ) {
                $href = (string) ( $row['url'] ?? '' );
                $title = (string) ( $row['title'] ?? '' );
                $due = (string) ( $row['due_label'] ?? '' );
                $row_html = '<span class="tt-pd-task-title">' . esc_html( $title ) . '</span>'
                    . '<span class="tt-pd-task-due">' . esc_html( $due ) . '</span>';
                $items .= '<li class="tt-pd-task-row">'
                    . ( $href !== ''
                        ? '<a href="' . esc_url( $href ) . '" style="display:flex; justify-content:space-between; gap:12px; text-decoration:none; color:inherit;">' . $row_html . '</a>'
                        : $row_html )
                    . '</li>';
            }
            $body = '<ul class="tt-pd-task-list">' . $items . '</ul>';
        }

        $inner = '<div class="tt-pd-panel-head">'
            . '<span class="tt-pd-panel-title">' . esc_html( $title ) . '</span>'
            . '<a class="tt-pd-panel-more" href="' . esc_url( $see_all ) . '">' . esc_html__( 'See all', 'talenttrack' ) . '</a>'
            . '</div>'
            . $body;
        return $this->wrap( $slot, $inner, 'panel' );
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function fetchRows( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( ! class_exists( TasksRepository::class ) ) return [];

        $repo = new TasksRepository();
        $rows = $repo->listActionableForUser( $user_id );
        if ( empty( $rows ) ) return [];

        $registry = class_exists( WorkflowModule::class ) ? WorkflowModule::registry() : null;
        $base_url = home_url( '/' );
        $page_id  = (int) QueryHelpers::get_config( 'dashboard_page_id', '0' );
        if ( $page_id > 0 ) {
            $perma = get_permalink( $page_id );
            if ( $perma ) $base_url = $perma;
        }

        $out = [];
        $limit = 5;
        foreach ( $rows as $r ) {
            if ( count( $out ) >= $limit ) break;
            $template_key = (string) ( $r['template_key'] ?? '' );
            $tpl = $registry !== null ? $registry->get( $template_key ) : null;
            $template_name = $tpl !== null ? $tpl->name() : $template_key;
            // Player-centric (CLAUDE.md § 1): lead the line with the
            // player name so the panel is scannable in one glance.
            $player_name = ! empty( $r['player_id'] )
                ? (string) self::playerName( (int) $r['player_id'] )
                : '';
            $title = $player_name !== ''
                ? $player_name . ' — ' . $template_name
                : $template_name;
            $task_id = (int) ( $r['id'] ?? 0 );
            $out[] = [
                'title'     => $title,
                'due_label' => self::formatDue( (string) ( $r['due_at'] ?? '' ) ),
                'url'       => $task_id > 0
                    ? add_query_arg( [ 'tt_view' => 'my-tasks', 'task_id' => $task_id ], $base_url )
                    : '',
            ];
        }
        return $out;
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

    private static function formatDue( string $due_at ): string {
        if ( $due_at === '' ) return '';
        $ts = strtotime( $due_at );
        if ( $ts === false ) return $due_at;
        $now = current_time( 'timestamp' );
        $diff = $ts - $now;
        if ( $diff < 0 ) return __( 'overdue', 'talenttrack' );
        if ( $diff < 86400 ) {
            $hours = max( 1, (int) round( $diff / 3600 ) );
            return sprintf( _n( 'in %d hour', 'in %d hours', $hours, 'talenttrack' ), $hours );
        }
        $format = (string) QueryHelpers::get_config( 'date_format', 'Y-m-d' );
        return wp_date( $format, $ts );
    }
}
