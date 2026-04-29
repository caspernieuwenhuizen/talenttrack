<?php
namespace TT\Modules\PersonaDashboard\Widgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractWidget;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\RenderContext;
use TT\Modules\PersonaDashboard\Domain\Size;
use TT\Modules\PersonaDashboard\Domain\WidgetSlot;
use TT\Modules\PersonaDashboard\Registry\KpiDataSourceRegistry;

/**
 * KpiCardWidget — a single KPI: headline number + trend arrow + sparkline.
 *
 * Slot's data_source = KpiDataSource id. The widget calls compute() and
 * renders the resulting KpiValue. Unavailable values render a "—" stub.
 */
class KpiCardWidget extends AbstractWidget {

    public function id(): string { return 'kpi_card'; }

    public function label(): string { return __( 'KPI card', 'talenttrack' ); }

    public function defaultSize(): string { return Size::M; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::S, Size::M, Size::L ]; }

    public function defaultMobilePriority(): int { return 40; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $source_id = $slot->data_source;
        if ( $source_id === '' ) return '';

        $source = KpiDataSourceRegistry::get( $source_id );
        $kpi    = $source !== null
            ? $source->compute( $ctx->user_id, $ctx->club_id )
            : KpiValue::unavailable();

        $label = $slot->persona_label !== ''
            ? $slot->persona_label
            : ( $source !== null ? $source->label() : $source_id );

        $trend_cls = $kpi->trend !== null ? ' tt-pd-trend-' . sanitize_html_class( $kpi->trend ) : '';
        $sparkline = $this->sparkline( $kpi );

        $inner = '<div class="tt-pd-kpi-label">' . esc_html( $label ) . '</div>'
            . '<div class="tt-pd-kpi-current' . $trend_cls . '">' . esc_html( $kpi->current ) . '</div>'
            . ( $kpi->delta !== null ? '<div class="tt-pd-kpi-delta">' . esc_html( $kpi->delta ) . '</div>' : '' )
            . $sparkline;
        return $this->wrap( $slot, $inner );
    }

    private function sparkline( KpiValue $kpi ): string {
        if ( count( $kpi->sparkline ) < 2 ) return '';
        $w = 80; $h = 24;
        $min = min( $kpi->sparkline ); $max = max( $kpi->sparkline );
        $range = ( $max - $min ) > 0 ? ( $max - $min ) : 1.0;
        $step  = $w / ( count( $kpi->sparkline ) - 1 );
        $points = [];
        foreach ( $kpi->sparkline as $i => $v ) {
            $x = (int) round( $i * $step );
            $y = (int) round( $h - ( ( $v - $min ) / $range ) * $h );
            $points[] = $x . ',' . $y;
        }
        return '<svg class="tt-pd-spark" viewBox="0 0 ' . $w . ' ' . $h . '" width="' . $w . '" height="' . $h . '" preserveAspectRatio="none" aria-hidden="true">'
            . '<polyline points="' . esc_attr( implode( ' ', $points ) ) . '" fill="none" stroke="currentColor" stroke-width="1.6" />'
            . '</svg>';
    }
}
