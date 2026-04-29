<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;

/**
 * QuickActionsPanelWidget — 2x2 (or 4x1) grid of action_cards.
 *
 * data_source is a comma-joined list of action ids:
 *   "new_evaluation,new_goal,new_activity,new_player"
 */
class QuickActionsPanelWidget extends AbstractWidget {

    public function id(): string { return 'quick_actions_panel'; }

    public function label(): string { return __( 'Quick actions panel', 'talenttrack' ); }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::M, Size::L ]; }

    public function defaultMobilePriority(): int { return 35; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $action = WidgetRegistry::get( 'action_card' );
        if ( $action === null ) return '';
        $ids = array_filter( array_map( 'trim', explode( ',', $slot->data_source ) ) );
        if ( empty( $ids ) ) return '';

        $items = '';
        foreach ( $ids as $id ) {
            $sub_slot = new WidgetSlot( 'action_card', $id, Size::S );
            $items .= $action->render( $sub_slot, $ctx );
        }
        if ( $items === '' ) return '';

        $title = $slot->persona_label !== '' ? $slot->persona_label : __( 'Quick actions', 'talenttrack' );
        $inner = '<div class="tt-pd-panel-title">' . esc_html( $title ) . '</div>'
            . '<div class="tt-pd-panel-grid">' . $items . '</div>';
        return $this->wrap( $slot, $inner, 'panel' );
    }
}
