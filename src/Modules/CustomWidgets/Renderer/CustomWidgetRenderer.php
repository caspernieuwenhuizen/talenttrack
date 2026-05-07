<?php
namespace TT\Modules\CustomWidgets\Renderer;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\CustomWidgets\CustomDataSourceRegistry;
use TT\Modules\CustomWidgets\Domain\CustomWidget;
use TT\Modules\CustomWidgets\Repository\CustomWidgetRepository;

/**
 * CustomWidgetRenderer (#0078 Phase 4) — renders one custom widget
 * row into HTML for the persona-dashboard render path.
 *
 * The synthetic `CustomWidgetWidget` (registered with `WidgetRegistry`)
 * delegates here. Splitting the renderer out keeps the Widget glue
 * thin and lets a future REST endpoint reuse the same code path
 * without going through the persona-dashboard editor.
 *
 * Chart-type behaviour:
 *   - **table** — `<table>` with the saved column subset; rows from
 *     the source's `fetch()`. Empty data → empty-state message.
 *   - **kpi** — single big number; reads the first row's first
 *     non-null value when an aggregation is present, falls back to
 *     row count otherwise.
 *   - **bar** / **line** — `<canvas>` element + Chart.js bootstrap
 *     payload in a `<script>` block. Chart.js itself is enqueued via
 *     `enqueueChartJsIfNeeded()` once per page render.
 *
 * Phase 5 will add the per-widget transient cache + source-cap
 * inheritance check around `fetchRows()`. For now the cap inheritance
 * is implicit: the source's `fetch()` honours `CurrentClub::id()` +
 * `apply_demo_scope`, but does NOT gate on the operator's per-record
 * read access — Phase 5 hardens that.
 */
final class CustomWidgetRenderer {

    private static bool $chartjs_enqueued = false;

    /**
     * Render the widget by uuid. Returns an empty string when the
     * uuid resolves to nothing or the source has been removed — the
     * synthetic `CustomWidgetWidget` decides whether to emit an empty
     * placeholder or hide the slot entirely.
     */
    public static function render( string $uuid, int $user_id ): string {
        $widget = ( new CustomWidgetRepository() )->findByUuid( $uuid );
        if ( $widget === null ) {
            return self::stub( __( 'Custom widget not found.', 'talenttrack' ) );
        }
        return self::renderWidget( $widget, $user_id );
    }

    public static function renderWidget( CustomWidget $widget, int $user_id ): string {
        $source = CustomDataSourceRegistry::find( $widget->dataSourceId );
        if ( $source === null ) {
            return self::stub( __( 'Data source no longer registered.', 'talenttrack' ) );
        }

        $columns = isset( $widget->definition['columns'] ) && is_array( $widget->definition['columns'] )
            ? $widget->definition['columns']
            : [];
        $filters = isset( $widget->definition['filters'] ) && is_array( $widget->definition['filters'] )
            ? $widget->definition['filters']
            : [];

        // Per-chart-type fetch bound. Tables show up to 100 rows; bar /
        // line cap to 50 categories so the chart stays legible; KPI
        // takes 1 row.
        $limit = self::limitForChart( $widget->chartType );
        $rows  = $source->fetch( $user_id, $filters, $columns, $limit );

        switch ( $widget->chartType ) {
            case CustomWidget::CHART_TABLE:
                return self::renderTable( $widget, $rows, $columns, $source );
            case CustomWidget::CHART_KPI:
                return self::renderKpi( $widget, $rows );
            case CustomWidget::CHART_BAR:
                return self::renderBarOrLine( $widget, $rows, 'bar' );
            case CustomWidget::CHART_LINE:
                return self::renderBarOrLine( $widget, $rows, 'line' );
        }
        return self::stub( __( 'Unknown chart type.', 'talenttrack' ) );
    }

    /**
     * Enqueues the Chart.js CDN script the first time a custom widget
     * needing it appears on the page. Safe to call multiple times.
     * Mirrors the comparison-view enqueue at v4.4.0 to share the
     * browser cache entry.
     */
    public static function enqueueChartJsIfNeeded(): void {
        if ( self::$chartjs_enqueued ) return;
        self::$chartjs_enqueued = true;
        wp_register_script(
            'tt-chartjs-cdn',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );
        wp_enqueue_script( 'tt-chartjs-cdn' );
    }

    private static function limitForChart( string $chart_type ): int {
        switch ( $chart_type ) {
            case CustomWidget::CHART_KPI:  return 1;
            case CustomWidget::CHART_BAR:
            case CustomWidget::CHART_LINE: return 50;
            default:                       return 100;
        }
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     * @param string[]                        $columns
     */
    private static function renderTable( CustomWidget $widget, array $rows, array $columns, $source ): string {
        $title = esc_html( $widget->name );
        if ( empty( $rows ) ) {
            return self::frame( $title,
                '<p class="tt-cw-empty">' . esc_html__( 'No rows to show.', 'talenttrack' ) . '</p>',
                $widget->uuid
            );
        }

        $col_meta = [];
        foreach ( $source->columns() as $c ) {
            if ( in_array( $c['key'], $columns, true ) ) {
                $col_meta[ $c['key'] ] = $c;
            }
        }
        // Fall back to row keys when the saved column list is empty.
        if ( empty( $col_meta ) ) {
            $first = reset( $rows );
            foreach ( array_keys( (array) $first ) as $k ) {
                $col_meta[ $k ] = [ 'key' => $k, 'label' => $k, 'kind' => 'string' ];
            }
        }

        $html = '<table class="tt-cw-render-table">';
        $html .= '<thead><tr>';
        foreach ( $col_meta as $key => $meta ) {
            $html .= '<th>' . esc_html( $meta['label'] ?? $key ) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        foreach ( $rows as $row ) {
            $html .= '<tr>';
            foreach ( $col_meta as $key => $meta ) {
                $val = $row[ $key ] ?? '';
                $html .= '<td>' . esc_html( (string) $val ) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';

        return self::frame( $title, $html, $widget->uuid );
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private static function renderKpi( CustomWidget $widget, array $rows ): string {
        $value = self::kpiValueFromRows( $rows );
        $body  = '<div class="tt-cw-kpi-value">' . esc_html( self::formatKpiValue( $value ) ) . '</div>';
        $body .= '<div class="tt-cw-kpi-label">' . esc_html( $widget->name ) . '</div>';
        return self::frame( '', $body, $widget->uuid, 'tt-cw-kpi' );
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private static function renderBarOrLine( CustomWidget $widget, array $rows, string $type ): string {
        self::enqueueChartJsIfNeeded();

        $labels = [];
        $values = [];
        foreach ( $rows as $row ) {
            $keys = array_keys( (array) $row );
            $labels[] = isset( $row[ $keys[0] ] ) ? (string) $row[ $keys[0] ] : '';
            $values[] = isset( $row[ $keys[1] ?? $keys[0] ] ) ? (float) $row[ $keys[1] ?? $keys[0] ] : 0.0;
        }

        $chart_id = 'tt-cw-chart-' . sanitize_html_class( $widget->uuid );
        $config = [
            'type' => $type,
            'data' => [
                'labels'   => $labels,
                'datasets' => [
                    [
                        'label'           => $widget->name,
                        'data'            => $values,
                        'backgroundColor' => $type === 'bar' ? 'rgba(34, 113, 177, 0.6)' : 'rgba(34, 113, 177, 0.2)',
                        'borderColor'     => '#2271b1',
                        'borderWidth'     => 1.5,
                        'tension'         => 0.2,
                    ],
                ],
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins'             => [ 'legend' => [ 'display' => false ] ],
                'scales'              => [ 'y' => [ 'beginAtZero' => true ] ],
            ],
        ];
        $config_json = wp_json_encode( $config );

        $body  = '<div class="tt-cw-chart-host" style="position:relative; min-height:220px;">';
        $body .= '<canvas id="' . esc_attr( $chart_id ) . '"></canvas>';
        $body .= '</div>';
        // Inline boot script — defers execution until Chart is on the
        // page (the CDN script also has `defer` semantics via `in_footer`).
        $body .= '<script>(function(){function b(){var c=window.Chart;if(!c){return setTimeout(b,80);}var el=document.getElementById('
            . wp_json_encode( $chart_id )
            . ');if(!el||el.dataset.ttBound)return;el.dataset.ttBound="1";new c(el.getContext("2d"),'
            . $config_json
            . ');}b();})();</script>';

        return self::frame( esc_html( $widget->name ), $body, $widget->uuid );
    }

    /**
     * @param array<int, array<string,mixed>> $rows
     */
    private static function kpiValueFromRows( array $rows ) {
        if ( empty( $rows ) ) return null;
        $first = (array) reset( $rows );
        if ( empty( $first ) ) return null;
        // Prefer a numeric column over a label column for the KPI big
        // number; fall back to first column.
        foreach ( $first as $v ) {
            if ( is_numeric( $v ) ) return $v;
        }
        $first_key = array_key_first( $first );
        return $first_key !== null ? $first[ $first_key ] : null;
    }

    private static function formatKpiValue( $v ): string {
        if ( $v === null ) return '—';
        if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( ltrim( $v, '-' ) ) ) ) {
            return number_format_i18n( (int) $v );
        }
        if ( is_numeric( $v ) ) {
            $f = (float) $v;
            return fmod( $f, 1.0 ) === 0.0 ? number_format_i18n( $f, 0 ) : number_format_i18n( $f, 1 );
        }
        return (string) $v;
    }

    private static function frame( string $title, string $body, string $uuid, string $variant = '' ): string {
        $cls = 'tt-cw-render' . ( $variant !== '' ? ' ' . sanitize_html_class( $variant ) : '' );
        $out = '<div class="' . esc_attr( $cls ) . '" data-tt-cw-uuid="' . esc_attr( $uuid ) . '">';
        if ( $title !== '' ) {
            $out .= '<div class="tt-cw-title">' . $title . '</div>';
        }
        $out .= $body;
        $out .= '</div>';
        return $out;
    }

    private static function stub( string $message ): string {
        return '<div class="tt-cw-render tt-cw-stub">' . esc_html( $message ) . '</div>';
    }
}
