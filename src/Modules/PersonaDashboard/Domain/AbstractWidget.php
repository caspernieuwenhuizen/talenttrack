<?php
namespace TT\Modules\PersonaDashboard\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AbstractWidget — base for the 14 shipped widget types.
 *
 * Concrete widgets typically only override id(), label(), defaultSize(),
 * allowedSizes(), and render(). Sensible defaults for cap, persona
 * context, mobile priority, and module class let single-line widget
 * subclasses stay tight.
 */
abstract class AbstractWidget implements Widget {

    abstract public function id(): string;

    abstract public function label(): string;

    public function defaultSize(): string {
        return Size::M;
    }

    /** @return list<string> */
    public function allowedSizes(): array {
        return [ Size::S, Size::M, Size::L, Size::XL ];
    }

    public function defaultMobilePriority(): int {
        return 50;
    }

    public function personaContext(): string {
        return PersonaContext::ACADEMY;
    }

    public function capRequired(): string {
        return '';
    }

    public function moduleClass(): string {
        return \TT\Modules\PersonaDashboard\PersonaDashboardModule::class;
    }

    /**
     * Persona-dashboard editor catalogue of valid `data_source` values
     * for this widget (#0077 M1). Returns `[ id => human label ]` pairs.
     * Empty default → editor falls back to a free-text input. Widgets
     * with a fixed preset list (DataTableWidget, ActionCardWidget,
     * InfoCardWidget, MiniPlayerListWidget) override; NavigationTileWidget
     * publishes a runtime list pulled from TileRegistry.
     *
     * @return array<string,string>
     */
    public function dataSourceCatalogue(): array {
        return [];
    }

    abstract public function render( WidgetSlot $slot, RenderContext $ctx ): string;

    /**
     * Helper for rendering the standard outer wrapper. Concrete widgets
     * supply the inner HTML; the wrapper carries the bento-grid sizing
     * classes + drag-handle hooks the sprint 2 editor will read.
     */
    protected function wrap( WidgetSlot $slot, string $inner_html, string $variant = '' ): string {
        $size_cls = 'tt-pd-size-' . strtolower( $slot->size );
        $row_span = $slot->row_span > 1 ? ' tt-pd-rows-' . $slot->row_span : '';
        $variant_cls = $variant !== '' ? ' tt-pd-variant-' . sanitize_html_class( $variant ) : '';
        $widget_id   = sanitize_html_class( $this->id() );
        $data_source = $slot->data_source !== '' ? ' data-tt-pd-data="' . esc_attr( $slot->data_source ) . '"' : '';
        return '<div class="tt-pd-widget tt-pd-widget-' . $widget_id . ' ' . $size_cls . $row_span . $variant_cls . '"'
            . ' data-tt-pd-widget="' . esc_attr( $this->id() ) . '"'
            . $data_source
            . '>' . $inner_html . '</div>';
    }
}
