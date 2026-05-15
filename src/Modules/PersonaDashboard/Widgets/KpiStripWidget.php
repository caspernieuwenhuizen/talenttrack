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
            $body = '<div class="tt-pd-strip-label">' . esc_html( $label ) . '</div>'
                . '<div class="tt-pd-strip-current' . $trend_cls . '">' . esc_html( $kpi->current ) . '</div>'
                . ( $kpi->delta !== null ? '<div class="tt-pd-strip-delta">' . esc_html( $kpi->delta ) . '</div>' : '' );
            // Whole card becomes the tap target when the KPI declares a
            // deep-link URL (preserves the 48px-min mobile affordance and
            // matches KpiCardWidget's pattern). v3.110.112 — prefer the
            // newer `linkUrl( $ctx )` builder over `linkView()` so KPIs
            // can deep-link with filter querystrings; falls back to the
            // legacy view-only builder for KPIs that haven't migrated.
            $url = '';
            if ( $source !== null ) {
                if ( method_exists( $source, 'linkUrl' ) ) {
                    $url = $source->linkUrl( $ctx );
                } elseif ( method_exists( $source, 'linkView' ) ) {
                    $v = $source->linkView();
                    if ( $v !== '' ) $url = $ctx->viewUrl( $v );
                }
            }
            if ( $url !== '' ) {
                $cards .= '<a class="tt-pd-strip-kpi tt-pd-strip-link" href="' . esc_url( $url ) . '">' . $body . '</a>';
            } else {
                $cards .= '<div class="tt-pd-strip-kpi">' . $body . '</div>';
            }
        }
        return $this->wrap( $slot, '<div class="tt-pd-strip-track">' . $cards . '</div>', 'kpi-strip' );
    }
}
