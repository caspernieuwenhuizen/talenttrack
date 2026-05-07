<?php
namespace TT\Modules\CustomWidgets\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomWidgets\CustomWidgetsModule;
use TT\Modules\CustomWidgets\Renderer\CustomWidgetRenderer;
use TT\Modules\CustomWidgets\Repository\CustomWidgetRepository;
use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;

/**
 * CustomWidgetWidget (#0078 Phase 4) — synthetic Widget that the
 * persona-dashboard editor lists in its palette under "Custom widgets."
 *
 * Each saved row in `tt_custom_widgets` shows up as a draggable tile.
 * The slot's `data_source` field carries the widget's `uuid`, so
 * dragging the tile onto the canvas creates a slot of widget id
 * `custom_widget` with `data_source: <uuid>`. The renderer resolves
 * the uuid back to the saved definition + chart type at render time.
 *
 * `dataSourceCatalogue()` produces the picker entries — one per
 * non-archived saved widget for the current club. That populates
 * the editor's "data source" dropdown (which here doubles as the
 * widget picker) without requiring a registry-wide refactor.
 */
class CustomWidgetWidget extends AbstractWidget {

    public function id(): string { return 'custom_widget'; }

    public function label(): string { return __( 'Custom widget', 'talenttrack' ); }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array {
        return [ Size::S, Size::M, Size::L, Size::XL ];
    }

    public function defaultMobilePriority(): int { return 60; }

    public function personaContext(): string { return PersonaContext::ACADEMY; }

    public function moduleClass(): string { return CustomWidgetsModule::class; }

    /**
     * The picker the editor renders for `data_source` is the live
     * catalogue of saved custom widgets. Empty on a fresh install
     * → editor surfaces a hint to author one first.
     *
     * @return array<string,string>
     */
    public function dataSourceCatalogue(): array {
        $repo    = new CustomWidgetRepository();
        $widgets = $repo->listForClub( false );
        $out     = [];
        foreach ( $widgets as $w ) {
            $out[ $w->uuid ] = $w->name;
        }
        return $out;
    }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $uuid = trim( $slot->data_source );
        if ( $uuid === '' ) {
            return $this->wrap( $slot,
                '<div class="tt-cw-render tt-cw-stub">'
                . esc_html__( 'Pick a custom widget for this slot.', 'talenttrack' )
                . '</div>'
            );
        }
        $inner = CustomWidgetRenderer::render( $uuid, $ctx->user_id );
        return $this->wrap( $slot, $inner );
    }
}
