<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * TaskListPanelWidget — preview of the user's open workflow tasks.
 *
 * Renders up to N rows from the workflow engine, plus a "see all" link
 * to ?tt_view=my-tasks. Sprint 3 wires the actual TaskRepository read;
 * sprint 1 ships the scaffolding with an empty-state.
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
            $body = '<div class="tt-pd-task-empty">' . esc_html__( 'No open tasks. ', 'talenttrack' ) . '</div>';
        } else {
            $items = '';
            foreach ( $rows as $row ) {
                $items .= '<li class="tt-pd-task-row">'
                    . '<span class="tt-pd-task-title">' . esc_html( (string) ( $row['title'] ?? '' ) ) . '</span>'
                    . '<span class="tt-pd-task-due">' . esc_html( (string) ( $row['due_label'] ?? '' ) ) . '</span>'
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
        // Sprint 3 will query the workflow engine via its TaskRepository.
        // Sprint 1 returns empty so the empty-state path is the live UX.
        return [];
    }
}
