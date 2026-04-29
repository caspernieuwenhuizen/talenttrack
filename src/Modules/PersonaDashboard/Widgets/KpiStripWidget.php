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
 * KpiStripWidget — XL composite of 4–6 KPI cards in one row.
 *
 * data_source carries a comma-joined list of KPI ids:
 *   "active_players_total,evaluations_this_month,attendance_pct_rolling,open_trial_cases"
 */
class KpiStripWidget extends AbstractWidget {

    public function id(): string { return 'kpi_strip'; }

    public function label(): string { return __( 'KPI strip', 'talenttrack' ); }

    public function defaultSize(): string { return Size::XL; }

    /** @return list<string> */
    public function allowedSizes(): array { return [ Size::XL ]; }

    public function defaultMobilePriority(): int { return 5; }

    public function render( WidgetSlot $slot, RenderContext $ctx ): string {
        $ids = array_filter( array_map( 'trim', explode( ',', $slot->data_source ) ) );
        if ( empty( $ids ) ) return '';

        $cards = '';
        foreach ( $ids as $id ) {
            $source = KpiDataSourceRegistry::get( $id );
            $kpi    = $source !== null ? $source->compute( $ctx->user_id, $ctx->club_id ) : KpiValue::unavailable();
            $label  = $source !== null ? $source->label() : $id;
            $trend_cls = $kpi->trend !== null ? ' tt-pd-trend-' . sanitize_html_class( $kpi->trend ) : '';
            $cards .= '<div class="tt-pd-strip-kpi">'
                . '<div class="tt-pd-strip-label">' . esc_html( $label ) . '</div>'
                . '<div class="tt-pd-strip-current' . $trend_cls . '">' . esc_html( $kpi->current ) . '</div>'
                . ( $kpi->delta !== null ? '<div class="tt-pd-strip-delta">' . esc_html( $kpi->delta ) . '</div>' : '' )
                . '</div>';
        }
        return $this->wrap( $slot, '<div class="tt-pd-strip-track">' . $cards . '</div>', 'kpi-strip' );
    }
}
