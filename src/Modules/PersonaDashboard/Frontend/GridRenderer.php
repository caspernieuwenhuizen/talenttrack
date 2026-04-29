<?php
namespace TT\Modules\PersonaDashboard\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\PersonaTemplate;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\WidgetRegistry;

/**
 * GridRenderer — renders a PersonaTemplate as hero band + task band +
 * 12-column bento grid.
 *
 * Desktop reads (x, y) for placement and uses CSS grid; tablet shrinks
 * to 6 columns by halving column starts; mobile sorts by mobile_priority
 * and stacks 1-col, hiding any slot whose mobile_visible is false.
 *
 * Mobile collapse is controlled by CSS media queries reading classes the
 * renderer emits (tt-pd-mobile-priority-N), not by re-rendering — this
 * keeps the markup identical at every breakpoint and the CSS the only
 * authoritative responsive layer.
 */
final class GridRenderer {

    public static function render( PersonaTemplate $template, RenderContext $ctx ): void {
        if ( $template->hero !== null ) {
            self::renderBand( $template->hero, $ctx, 'hero-band' );
        }
        if ( $template->task !== null ) {
            self::renderBand( $template->task, $ctx, 'task-band' );
        }
        if ( ! $template->grid->isEmpty() ) {
            self::renderGrid( $template, $ctx );
        }
    }

    private static function renderBand( WidgetSlot $slot, RenderContext $ctx, string $band_cls ): void {
        $widget = WidgetRegistry::get( $slot->widget_id );
        if ( $widget === null ) return;
        if ( ! self::widgetVisibleFor( $widget, $ctx->user_id ) ) return;
        echo '<div class="tt-pd-band tt-pd-' . esc_attr( $band_cls ) . '">';
        echo $widget->render( $slot, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
        echo '</div>';
    }

    private static function renderGrid( PersonaTemplate $template, RenderContext $ctx ): void {
        echo '<div class="tt-pd-grid" role="list">';
        foreach ( $template->grid->desktopOrder() as $slot ) {
            $widget = WidgetRegistry::get( $slot->widget_id );
            if ( $widget === null ) continue;
            if ( ! self::widgetVisibleFor( $widget, $ctx->user_id ) ) continue;

            $col_start = $slot->x + 1; // CSS grid is 1-indexed.
            $row_start = $slot->y + 1;
            $col_span  = self::colsForSize( $slot->size );
            $row_span  = max( 1, $slot->row_span );

            $style = sprintf(
                'grid-column:%d / span %d; grid-row:%d / span %d;',
                $col_start,
                $col_span,
                $row_start,
                $row_span
            );
            $cls = 'tt-pd-grid-cell tt-pd-mobile-priority-' . max( 1, $slot->mobile_priority );
            if ( ! $slot->mobile_visible ) $cls .= ' tt-pd-mobile-hidden';

            echo '<div class="' . esc_attr( $cls ) . '" style="' . esc_attr( $style ) . '" role="listitem">';
            echo $widget->render( $slot, $ctx ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
            echo '</div>';
        }
        echo '</div>';
    }

    private static function colsForSize( string $size ): int {
        return \TT\Modules\PersonaDashboard\Domain\Size::cols( $size ) ?: 6;
    }

    /**
     * Widget visibility — module enabled, capability available.
     */
    private static function widgetVisibleFor( \TT\Modules\PersonaDashboard\Domain\Widget $widget, int $user_id ): bool {
        $module = $widget->moduleClass();
        if ( $module !== '' && class_exists( '\\TT\\Core\\ModuleRegistry' ) ) {
            if ( ! \TT\Core\ModuleRegistry::isEnabled( $module ) ) return false;
        }
        $cap = $widget->capRequired();
        if ( $cap !== '' && ! user_can( $user_id, $cap ) ) return false;
        return true;
    }
}
