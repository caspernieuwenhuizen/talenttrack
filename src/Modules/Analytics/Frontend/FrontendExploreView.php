<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\DimensionValueResolver;
use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\Export\CsvExporter;
use TT\Modules\Analytics\FactQuery;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiRegistry;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendExploreView — the dimension explorer (#0083 Child 3).
 *
 * Reachable at `?tt_view=explore&kpi={key}`. Any KPI's "explore"
 * affordance hands off to this view with the KPI's defaults
 * pre-populated. The user picks filters, picks a group-by
 * dimension, and ends up at the underlying fact rows.
 *
 * Classified `desktop_only` per #0084 (dense filtering and chart
 * interaction are desktop work; nobody analyses attendance trends
 * on a phone). The classification is registered in
 * `CoreSurfaceRegistration::registerMobileClasses()`.
 *
 * Child 3 minimum-viable scope:
 *   - Filter chips for each KPI exploreDimension (dropdowns over
 *     dimension values, with a free-form text input for `enum` /
 *     `lookup` types where a value catalogue isn't pre-loaded).
 *   - Group-by selector (None / dimension list).
 *   - Headline value (the KPI's measure aggregated across the
 *     filtered rows).
 *   - Group-by table when a group-by is chosen (rows = group
 *     buckets; columns = dimension value + measure).
 *   - URL state: filters + group-by round-trip via querystring,
 *     so sharing a link reproduces the view exactly.
 *
 * **What's deferred to follow-ups** (per spec §`feat-dimension-explorer`):
 *   - Chart.js time-series chart (the spec mentions "reuses Chart.js
 *     wiring from #0077 M6"; ships when the explorer earns it).
 *   - Trend arrow + delta vs previous period.
 *   - Drilldown to underlying fact rows (top 50 paginated).
 *   - Export CSV / PDF buttons (Child 6 ships export).
 *
 * Capability: requires `read`. Per-KPI capability gating is the
 * KPI's `context` (ACADEMY / COACH / PLAYER_PARENT) — Child 4 and
 * Child 5 enforce that at the entry surfaces (entity tab + central
 * view). The explorer is reachable directly only via URL today.
 */
class FrontendExploreView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        $kpi_key = isset( $_GET['kpi'] ) ? sanitize_key( (string) $_GET['kpi'] ) : '';
        $kpi     = KpiRegistry::find( $kpi_key );
        $action  = isset( $_GET['action'] ) ? sanitize_key( (string) $_GET['action'] ) : '';

        if ( $action === 'export_csv' && $kpi !== null ) {
            self::streamCsv( $kpi );
            return;
        }
        // #875 — PDF export. Streams a branded one-pager via DomPDF.
        if ( $action === 'export_pdf' && $kpi !== null ) {
            self::streamPdf( $kpi );
            return;
        }

        self::enqueueAssets();

        \TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard(
            __( 'Explore', 'talenttrack' ),
            [ \TT\Shared\Frontend\Components\FrontendBreadcrumbs::viewCrumb( 'analytics', __( 'Analytics', 'talenttrack' ) ) ]
        );
        self::renderHeader( __( 'Explore', 'talenttrack' ) );

        if ( $kpi === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'No KPI selected. Open the explorer from a KPI card or entity Analytics tab.', 'talenttrack' ) . '</p>';
            return;
        }

        $fact = FactRegistry::find( $kpi->factKey );
        if ( $fact === null ) {
            echo '<p class="tt-notice">' . esc_html__( 'The KPI references a fact that is no longer registered.', 'talenttrack' ) . '</p>';
            return;
        }

        $filters  = self::filtersFromRequest( $kpi );
        $group_by = isset( $_GET['group_by'] ) ? sanitize_key( (string) $_GET['group_by'] ) : '';

        echo '<div class="tt-explore">';

        // Header — KPI label + back link.
        echo '<div class="tt-explore-head">';
        echo '<h2>' . esc_html( $kpi->label ) . '</h2>';
        $back_url = WizardEntryPoint::dashboardBaseUrl();
        echo '<a href="' . esc_url( $back_url ) . '">' . esc_html__( '← Back', 'talenttrack' ) . '</a>';
        echo '</div>';

        // Filter chips.
        self::renderFilterChips( $kpi, $fact, $filters, $group_by );

        // Headline value — measure aggregated over the filtered rows.
        $rows = FactQuery::run( $kpi->factKey, [], [ $kpi->measureKey ], $filters );
        $headline = empty( $rows ) ? null : ( $rows[0]->{ $kpi->measureKey } ?? null );

        // #873 — time-series chart between the headline and the filter row.
        // Gated on the KPI declaring a `primaryDimension`; KPIs without one
        // (a snapshot value with no temporal axis) skip the chart entirely.
        if ( $kpi->primaryDimension !== null && $kpi->primaryDimension !== ''
             && $fact->dimension( $kpi->primaryDimension ) !== null ) {
            self::renderTimeSeriesChart( $kpi, $fact, $filters, $group_by );
        }
        echo '<div class="tt-explore-headline">';
        echo '<div class="tt-explore-headline-label">' . esc_html__( 'Headline', 'talenttrack' ) . '</div>';
        echo '<div class="tt-explore-headline-val">' . esc_html( self::formatHeadline( $kpi, $headline ) ) . '</div>';
        if ( $kpi->threshold !== null && $headline !== null ) {
            $is_red = ( $kpi->goalDirection === Kpi::GOAL_HIGHER_BETTER && (float) $headline < $kpi->threshold )
                   || ( $kpi->goalDirection === Kpi::GOAL_LOWER_BETTER  && (float) $headline > $kpi->threshold );
            if ( $is_red ) {
                echo '<div class="tt-explore-headline-flag">'
                    . esc_html__( 'Below threshold — review with the team.', 'talenttrack' )
                    . '</div>';
            }
        }
        echo '</div>';

        // Export CSV link — preserves current KPI + filters + group-by.
        $export_args = [
            'tt_view' => 'explore',
            'kpi'     => $kpi->key,
            'action'  => 'export_csv',
        ];
        if ( $group_by !== '' ) $export_args['group_by'] = $group_by;
        foreach ( $filters as $fk => $fv ) {
            $export_args[ 'filter_' . $fk ] = is_array( $fv ) ? $fv : (string) $fv;
        }
        $export_url = add_query_arg( $export_args, WizardEntryPoint::dashboardBaseUrl() );
        $pdf_args = $export_args;
        $pdf_args['action'] = 'export_pdf';
        $pdf_url = add_query_arg( $pdf_args, WizardEntryPoint::dashboardBaseUrl() );
        echo '<p class="tt-explore-export">';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $export_url ) . '">'
            . esc_html__( 'Export CSV', 'talenttrack' )
            . '</a>';
        echo '<a class="tt-btn tt-btn-secondary" href="' . esc_url( $pdf_url ) . '">'
            . esc_html__( 'Export PDF', 'talenttrack' )
            . '</a>';
        echo '</p>';

        // Group-by selector.
        echo '<form method="get" action="" class="tt-explore-groupby">';
        // Carry forward the kpi + every active filter as hidden inputs so the
        // group-by submit doesn't drop them.
        echo '<input type="hidden" name="tt_view" value="explore">';
        echo '<input type="hidden" name="kpi" value="' . esc_attr( $kpi->key ) . '">';
        foreach ( $filters as $fk => $fv ) {
            if ( is_array( $fv ) ) {
                foreach ( $fv as $fvv ) {
                    echo '<input type="hidden" name="filter_' . esc_attr( $fk ) . '[]" value="' . esc_attr( (string) $fvv ) . '">';
                }
            } else {
                echo '<input type="hidden" name="filter_' . esc_attr( $fk ) . '" value="' . esc_attr( (string) $fv ) . '">';
            }
        }
        echo '<label for="tt-explore-groupby">' . esc_html__( 'Group by:', 'talenttrack' ) . '</label>';
        echo '<select id="tt-explore-groupby" name="group_by" onchange="this.form.submit()">';
        echo '<option value="">' . esc_html__( '— None —', 'talenttrack' ) . '</option>';
        foreach ( $kpi->exploreDimensions as $dim_key ) {
            $dim = $fact->dimension( $dim_key );
            if ( $dim === null ) continue;
            echo '<option value="' . esc_attr( $dim_key ) . '" ' . selected( $group_by, $dim_key, false ) . '>'
                . esc_html( $dim->label )
                . '</option>';
        }
        echo '</select>';
        echo '<noscript><button type="submit" class="tt-btn tt-btn-secondary">' . esc_html__( 'Apply', 'talenttrack' ) . '</button></noscript>';
        echo '</form>';

        // Group-by table.
        if ( $group_by !== '' && $fact->dimension( $group_by ) !== null ) {
            $grouped_rows = FactQuery::run( $kpi->factKey, [ $group_by ], [ $kpi->measureKey ], $filters );
            self::renderGroupByTable( $kpi, $fact, $group_by, $grouped_rows );
        } else {
            echo '<p class="tt-explore-hint">'
                . esc_html__( 'Pick a dimension above to break the headline down by groups, or browse the underlying rows below.', 'talenttrack' )
                . '</p>';
            // #874 — drilldown to fact rows. Renders only when ungrouped.
            self::renderDrilldownTable( $kpi, $fact, $filters );
        }

        echo '</div>';
    }

    /**
     * B3 — enqueue the per-view 2026 stylesheet on top of the shared
     * frontend assets. Styles the filter card, headline card, export
     * bar, group-by row, result tables and pager; depends on the
     * app-chrome sheet for the brand tokens. The Chart.js canvas and
     * data queries are untouched.
     */
    protected static function enqueueAssets(): void {
        parent::enqueueAssets();
        wp_enqueue_style(
            'tt-frontend-explore',
            TT_PLUGIN_URL . 'assets/css/frontend-explore.css',
            [ 'tt-frontend-app-chrome' ],
            TT_VERSION
        );
    }

    /**
     * #873 — render the time-series Chart.js canvas + init script.
     *
     * When `$group_by` is set, runs a 2-dimension query keyed by
     * `[primaryDimension, group_by]` so each group becomes a series.
     * Caps at 6 series; the tail is rolled into "Other" so the legend
     * stays legible. When `$group_by` is empty, a single series.
     */
    private static function renderTimeSeriesChart( Kpi $kpi, $fact, array $filters, string $group_by ): void {
        $primary = (string) $kpi->primaryDimension;

        $is_grouped = $group_by !== '' && $fact->dimension( $group_by ) !== null && $group_by !== $primary;
        $dims = $is_grouped ? [ $primary, $group_by ] : [ $primary ];
        $rows = FactQuery::run( $kpi->factKey, $dims, [ $kpi->measureKey ], $filters );

        if ( empty( $rows ) ) {
            echo '<div class="tt-empty tt-explore-empty">'
                . esc_html__( 'No data points for the current filters. Adjust filters or pick another date range.', 'talenttrack' )
                . '</div>';
            return;
        }

        // Collect distinct bucket labels in chronological order, plus
        // per-series points keyed by group label.
        $buckets  = [];
        $series   = []; // group_label => [ bucket_label => value ]
        $primary_dim = $fact->dimension( $primary );
        $group_dim   = $is_grouped ? $fact->dimension( $group_by ) : null;

        foreach ( $rows as $r ) {
            $bucket_raw = $r->{ $primary } ?? null;
            if ( $bucket_raw === null ) continue;
            $bucket_label = (string) DimensionValueResolver::resolve( $primary_dim, $bucket_raw );
            $buckets[ $bucket_label ] = true;

            $group_label = $is_grouped
                ? (string) DimensionValueResolver::resolve( $group_dim, $r->{ $group_by } ?? null )
                : (string) $kpi->label;
            $value = $r->{ $kpi->measureKey } ?? null;
            if ( $value === null ) continue;
            $series[ $group_label ][ $bucket_label ] = (float) $value;
        }

        if ( empty( $buckets ) || empty( $series ) ) {
            echo '<div class="tt-empty tt-explore-empty">'
                . esc_html__( 'No data points for the current filters. Adjust filters or pick another date range.', 'talenttrack' )
                . '</div>';
            return;
        }

        $bucket_labels = array_keys( $buckets );
        sort( $bucket_labels, SORT_STRING ); // chronological by lexical key (Y-m or Y-m-d sorts naturally).

        // Cap series at 6 — roll the remainder into "Other".
        $MAX_SERIES = 6;
        if ( count( $series ) > $MAX_SERIES ) {
            // Sum each series across all buckets and keep the top N-1
            // by total; collapse the rest into "Other".
            $totals = [];
            foreach ( $series as $label => $points ) {
                $totals[ $label ] = array_sum( $points );
            }
            arsort( $totals, SORT_NUMERIC );
            $keep   = array_slice( array_keys( $totals ), 0, $MAX_SERIES - 1 );
            $other  = [];
            foreach ( $series as $label => $points ) {
                if ( in_array( $label, $keep, true ) ) continue;
                foreach ( $points as $b => $v ) {
                    $other[ $b ] = ( $other[ $b ] ?? 0.0 ) + (float) $v;
                }
            }
            $kept = [];
            foreach ( $keep as $label ) {
                $kept[ $label ] = $series[ $label ];
            }
            $kept[ __( 'Other', 'talenttrack' ) ] = $other;
            $series = $kept;
        }

        // Materialise series into Chart.js dataset shape — fill missing
        // bucket points with null so the line breaks rather than zero-
        // dipping.
        $datasets = [];
        $palette  = [
            '#0b3d2e', '#2271b1', '#b32d2e', '#d97706', '#7e22ce',
            '#0891b2', '#65a30d', '#be185d',
        ];
        $i = 0;
        foreach ( $series as $label => $points ) {
            $data = [];
            foreach ( $bucket_labels as $b ) {
                $data[] = isset( $points[ $b ] ) ? $points[ $b ] : null;
            }
            $color = $palette[ $i % count( $palette ) ];
            $datasets[] = [
                'label'           => $label,
                'data'            => $data,
                'borderColor'     => $color,
                'backgroundColor' => $color,
                'tension'         => 0.2,
                'pointRadius'     => 3,
                'spanGaps'        => true,
            ];
            $i++;
        }

        // Enqueue Chart.js — mirrors the FrontendComparisonView pattern.
        wp_enqueue_script(
            'tt-chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
            [],
            '4.4.0',
            true
        );

        $cfg = [
            'type' => 'line',
            'data' => [
                'labels'   => $bucket_labels,
                'datasets' => $datasets,
            ],
            'options' => [
                'responsive'          => true,
                'maintainAspectRatio' => false,
                'plugins'             => [
                    'legend' => [ 'display' => count( $datasets ) > 1 ],
                ],
                'scales' => [
                    'y' => [ 'beginAtZero' => true ],
                ],
            ],
        ];

        $canvas_id = 'tt-explore-chart-' . wp_generate_uuid4();
        ?>
        <div class="tt-explore-chart">
            <div class="tt-explore-chart-label">
                <?php esc_html_e( 'Time series', 'talenttrack' ); ?>
            </div>
            <div class="tt-explore-chart-canvas">
                <canvas id="<?php echo esc_attr( $canvas_id ); ?>" data-tt-chart="explorer-timeseries"></canvas>
            </div>
        </div>
        <script type="application/json" id="<?php echo esc_attr( $canvas_id . '-cfg' ); ?>"><?php echo wp_json_encode( $cfg ); ?></script>
        <script>
        (function () {
            'use strict';
            function init() {
                if ( typeof window.Chart === 'undefined' ) {
                    setTimeout( init, 50 );
                    return;
                }
                var cfgEl = document.getElementById('<?php echo esc_js( $canvas_id . '-cfg' ); ?>');
                var canvas = document.getElementById('<?php echo esc_js( $canvas_id ); ?>');
                if ( ! cfgEl || ! canvas ) return;
                try {
                    var cfg = JSON.parse(cfgEl.textContent || '{}');
                    new window.Chart(canvas.getContext('2d'), cfg);
                } catch (e) { /* swallow — empty chart is acceptable failure mode */ }
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', init);
            } else {
                init();
            }
        }());
        </script>
        <?php
    }

    /**
     * Emit the explorer view as a CSV download. Uses the same KPI +
     * filters + group-by the on-screen view would render — sharing a
     * link with `&action=export_csv` reproduces the export exactly.
     *
     * The dimension list is the active `group_by` (if set) plus the
     * KPI's `exploreDimensions` so the exported CSV always carries
     * the breakdown columns even when no group-by is picked.
     */
    private static function streamCsv( Kpi $kpi ): void {
        $fact = FactRegistry::find( $kpi->factKey );
        if ( $fact === null ) return;

        $filters  = self::filtersFromRequest( $kpi );
        $group_by = isset( $_GET['group_by'] ) ? sanitize_key( (string) $_GET['group_by'] ) : '';

        $dim_keys = [];
        if ( $group_by !== '' && $fact->dimension( $group_by ) !== null ) {
            $dim_keys[] = $group_by;
        }

        $csv = CsvExporter::raw( $kpi->factKey, $dim_keys, [ $kpi->measureKey ], $filters, $kpi->label );
        if ( $csv === '' ) return;

        $filename = sanitize_file_name( $kpi->key . '-' . gmdate( 'Y-m-d' ) . '.csv' );
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . strlen( $csv ) );
        echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * #875 — stream a PDF for the current KPI + filters + group-by.
     *
     * Builds an HTML body matching what the on-screen explorer shows
     * (KPI label + headline + grouped table OR drilldown rows) and
     * hands it to the shared `PdfRenderer` from #0063. The renderer
     * self-gates on DomPDF availability and returns a `no_renderer`
     * 500 if the dependency isn't installed (dev environments only;
     * composer ships it as a prod dependency).
     *
     * URL pattern mirrors the CSV export:
     * `?tt_view=explore&kpi=…&filter_…=…&group_by=…&action=export_pdf`.
     * Filename: `<kpi-key>-<YYYY-MM-DD>.pdf`.
     *
     * Out of scope:
     *  - Brand-kit token application beyond the default body styles —
     *    a follow-up that hits the same path can wire `tt_pdf_render_html`.
     *  - Embedding the Chart.js canvas as a PDF image — DomPDF doesn't
     *    execute JS so the chart isn't rasterised; the workbook still
     *    carries the headline + tables which is the analytical content.
     */
    private static function streamPdf( Kpi $kpi ): void {
        $fact = FactRegistry::find( $kpi->factKey );
        if ( $fact === null ) return;

        $filters  = self::filtersFromRequest( $kpi );
        $group_by = isset( $_GET['group_by'] ) ? sanitize_key( (string) $_GET['group_by'] ) : '';

        $headline_rows = FactQuery::run( $kpi->factKey, [], [ $kpi->measureKey ], $filters );
        $headline = empty( $headline_rows ) ? null : ( $headline_rows[0]->{ $kpi->measureKey } ?? null );

        $body  = '<h1>' . esc_html( $kpi->label ) . '</h1>';
        $body .= '<p style="color:#5b6e75; font-size:10pt;">'
              . esc_html( sprintf(
                  /* translators: %s = ISO date */
                  __( 'Generated %s', 'talenttrack' ),
                  gmdate( 'Y-m-d' )
              ) )
              . '</p>';
        $body .= '<div style="margin:12pt 0 16pt; padding:8pt 12pt; border:1px solid #ccc; background:#fafafa;">'
              . '<div style="font-size:9pt; color:#5b6e75;">' . esc_html__( 'Headline', 'talenttrack' ) . '</div>'
              . '<div style="font-size:24pt; font-weight:bold;">' . esc_html( self::formatHeadline( $kpi, $headline ) ) . '</div>'
              . '</div>';

        // Filters summary line (compact).
        if ( ! empty( $filters ) ) {
            $bits = [];
            foreach ( $filters as $k => $v ) {
                $bits[] = esc_html( $k ) . ' = ' . esc_html( is_array( $v ) ? implode( ',', $v ) : (string) $v );
            }
            $body .= '<p style="font-size:9pt; color:#5b6e75;"><strong>'
                  . esc_html__( 'Filters', 'talenttrack' )
                  . ':</strong> ' . implode( ' · ', $bits ) . '</p>';
        }

        if ( $group_by !== '' && $fact->dimension( $group_by ) !== null ) {
            $grouped_rows = FactQuery::run( $kpi->factKey, [ $group_by ], [ $kpi->measureKey ], $filters );
            $dim = $fact->dimension( $group_by );
            $measure = $fact->measure( $kpi->measureKey );
            $body .= '<h2 style="margin-top:14pt;">' . esc_html( $dim->label ) . '</h2>';
            $body .= '<table><thead><tr>'
                  . '<th>' . esc_html( $dim->label ) . '</th>'
                  . '<th style="text-align:right;">' . esc_html( $measure ? $measure->label : '' ) . '</th>'
                  . '</tr></thead><tbody>';
            foreach ( $grouped_rows as $row ) {
                $raw   = $row->{ $group_by } ?? null;
                $label = DimensionValueResolver::resolve( $dim, $raw );
                $value = $row->{ $kpi->measureKey } ?? null;
                $body .= '<tr>'
                      . '<td>' . esc_html( (string) $label ) . '</td>'
                      . '<td style="text-align:right;">' . esc_html( self::formatHeadline( $kpi, $value ) ) . '</td>'
                      . '</tr>';
            }
            $body .= '</tbody></table>';
        } else {
            // Drilldown — top 50 rows.
            $rows = FactQuery::rows( $kpi->factKey, $filters, 50, 0 );
            $total = FactQuery::countRows( $kpi->factKey, $filters );
            $body .= '<h2 style="margin-top:14pt;">' . esc_html__( 'Underlying rows', 'talenttrack' )
                  . ' <span style="font-weight:normal; font-size:9pt; color:#5b6e75;">'
                  . esc_html( sprintf(
                      /* translators: 1: shown rows, 2: total rows */
                      __( '(first %1$d of %2$d)', 'talenttrack' ),
                      count( $rows ), $total
                  ) )
                  . '</span></h2>';
            $body .= '<table><thead><tr>';
            foreach ( $fact->dimensions as $d ) {
                $body .= '<th>' . esc_html( $d->label ) . '</th>';
            }
            $body .= '</tr></thead><tbody>';
            foreach ( $rows as $r ) {
                $body .= '<tr>';
                foreach ( $fact->dimensions as $d ) {
                    $raw = $r->{ $d->key } ?? null;
                    $label = DimensionValueResolver::resolve( $d, $raw );
                    $body .= '<td>' . esc_html( (string) $label ) . '</td>';
                }
                $body .= '</tr>';
            }
            $body .= '</tbody></table>';
        }

        $body .= '<p style="margin-top:18pt; font-size:8pt; color:#5b6e75;">'
              . esc_html__( 'Generated by TalentTrack — analytics explorer.', 'talenttrack' )
              . '</p>';

        try {
            $renderer = new \TT\Modules\Export\Format\Renderers\PdfRenderer();
            $request  = new \TT\Modules\Export\Domain\ExportRequest(
                exporterKey:      $kpi->key . '_explorer',
                format:           'pdf',
                clubId:           (int) \TT\Infrastructure\Tenancy\CurrentClub::id(),
                requesterUserId:  (int) get_current_user_id(),
            );
            $result = $renderer->render( $request, [
                'html'    => $body,
                'options' => [ 'paper' => 'A4', 'orientation' => 'portrait' ],
            ] );
        } catch ( \Throwable $e ) {
            status_header( 500 );
            echo esc_html__( 'PDF rendering failed. Run `composer install` and try again.', 'talenttrack' );
            exit;
        }

        $filename = sanitize_file_name( $kpi->key . '-' . gmdate( 'Y-m-d' ) . '.pdf' );
        nocache_headers();
        header( 'Content-Type: application/pdf' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
        header( 'Content-Length: ' . $result->size );
        echo $result->bytes; // phpcs:ignore WordPress.Security.EscapeOutput
        exit;
    }

    /**
     * Pull filter values out of `$_GET`. The form names them
     * `filter_<dim>_eq`, `filter_<dim>_in[]`, `filter_date_after`,
     * etc. — `FactQuery::run()` consumes the same `<dim>_<op>` shape.
     *
     * @return array<string,mixed>
     */
    private static function filtersFromRequest( Kpi $kpi ): array {
        $filters = $kpi->defaultFilters;

        // Special date filters — accept Y-m-d strings or relative
        // ("-30 days") forms.
        if ( isset( $_GET['filter_date_after'] ) ) {
            $val = sanitize_text_field( wp_unslash( (string) $_GET['filter_date_after'] ) );
            if ( $val !== '' ) $filters['date_after'] = $val;
        }
        if ( isset( $_GET['filter_date_before'] ) ) {
            $val = sanitize_text_field( wp_unslash( (string) $_GET['filter_date_before'] ) );
            if ( $val !== '' ) $filters['date_before'] = $val;
        }

        // Per-dimension filters — `filter_<dim>_eq=…`, `filter_<dim>_in[]=…`.
        if ( ! is_array( $_GET ) ) return $filters;
        foreach ( $_GET as $raw_key => $raw_val ) {
            $key = sanitize_key( (string) $raw_key );
            if ( strpos( $key, 'filter_' ) !== 0 ) continue;
            $stripped = substr( $key, strlen( 'filter_' ) );
            if ( $stripped === '' ) continue;
            // Ignore the date_* shortcuts handled above.
            if ( $stripped === 'date_after' || $stripped === 'date_before' ) continue;
            // Sanitize the value(s).
            if ( is_array( $raw_val ) ) {
                $clean = array_values( array_filter( array_map(
                    static function ( $v ) {
                        return sanitize_text_field( wp_unslash( (string) $v ) );
                    },
                    $raw_val
                ) ) );
                if ( ! empty( $clean ) ) $filters[ $stripped ] = $clean;
            } else {
                $clean = sanitize_text_field( wp_unslash( (string) $raw_val ) );
                if ( $clean !== '' ) $filters[ $stripped ] = $clean;
            }
        }
        return $filters;
    }

    /**
     * Render the chips that surface the KPI's exploreDimensions as
     * simple `<form>` inputs. Each chip is a labelled text input
     * named `filter_<dim>_eq` — the user types a value, submits,
     * the request reloads with the filter applied. Real combobox
     * pickers ship in a follow-up.
     */
    private static function renderFilterChips( Kpi $kpi, $fact, array $filters, string $group_by ): void {
        echo '<form method="get" action="" class="tt-explore-filters">';
        echo '<input type="hidden" name="tt_view" value="explore">';
        echo '<input type="hidden" name="kpi" value="' . esc_attr( $kpi->key ) . '">';
        if ( $group_by !== '' ) echo '<input type="hidden" name="group_by" value="' . esc_attr( $group_by ) . '">';

        echo '<label class="tt-explore-field">';
        echo '<span>' . esc_html__( 'Date after', 'talenttrack' ) . '</span>';
        $df = (string) ( $filters['date_after'] ?? '' );
        echo '<input type="text" name="filter_date_after" value="' . esc_attr( $df ) . '" placeholder="-30 days">';
        echo '</label>';

        echo '<label class="tt-explore-field">';
        echo '<span>' . esc_html__( 'Date before', 'talenttrack' ) . '</span>';
        $db = (string) ( $filters['date_before'] ?? '' );
        echo '<input type="text" name="filter_date_before" value="' . esc_attr( $db ) . '" placeholder="today">';
        echo '</label>';

        foreach ( $kpi->exploreDimensions as $dim_key ) {
            $dim = $fact->dimension( $dim_key );
            if ( $dim === null ) continue;
            $eq_key = $dim_key . '_eq';
            $val    = (string) ( $filters[ $eq_key ] ?? '' );
            echo '<label class="tt-explore-field">';
            echo '<span>' . esc_html( $dim->label ) . '</span>';
            echo '<input type="text" name="filter_' . esc_attr( $eq_key ) . '" value="' . esc_attr( $val ) . '">';
            echo '</label>';
        }

        echo '<button type="submit" class="tt-btn tt-btn-primary">'
            . esc_html__( 'Apply filters', 'talenttrack' )
            . '</button>';
        echo '</form>';
    }

    /**
     * Render the group-by result as a two-column table:
     * dimension value + aggregated measure.
     */
    private static function renderGroupByTable( Kpi $kpi, $fact, string $group_by, array $rows ): void {
        $dim     = $fact->dimension( $group_by );
        $measure = $fact->measure( $kpi->measureKey );
        if ( $dim === null || $measure === null ) return;

        echo '<div class="tt-report-card"><div class="tt-explore-table-wrap">';
        echo '<table class="tt-table tt-explore-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html( $dim->label ) . '</th>';
        echo '<th class="tt-explore-num">' . esc_html( $measure->label ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="2" class="tt-explore-hint">'
                . esc_html__( 'No data for the current filters.', 'talenttrack' )
                . '</td></tr>';
        } else {
            foreach ( $rows as $row ) {
                $raw   = $row->{ $group_by } ?? null;
                $label = DimensionValueResolver::resolve( $dim, $raw );
                $value = $row->{ $kpi->measureKey } ?? null;
                echo '<tr>';
                echo '<td>' . esc_html( $label ) . '</td>';
                echo '<td class="tt-explore-num">' . esc_html( self::formatHeadline( $kpi, $value ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
        echo '</div></div>';
    }

    /**
     * Format the measure value per its unit / format hints. Crude
     * v1 — units `percent` / `minutes` / `rating` get a suffix; the
     * rest fall through as a numeric string. Child 3 follow-up
     * tightens this into a shared formatter the entity tab + central
     * view both consume.
     */
    private static function formatHeadline( Kpi $kpi, $value ): string {
        if ( $value === null ) return '—';
        // Look up the measure for unit info.
        $fact    = FactRegistry::find( $kpi->factKey );
        $measure = $fact ? $fact->measure( $kpi->measureKey ) : null;
        $unit    = $measure ? ( $measure->unit ?? '' ) : '';

        $num = (float) $value;
        if ( $unit === 'percent' ) {
            return number_format_i18n( $num, 1 ) . '%';
        }
        if ( $unit === 'minutes' ) {
            $h = (int) floor( $num / 60 );
            $m = (int) round( fmod( $num, 60 ) );
            return $h > 0 ? ( $h . 'h ' . $m . 'm' ) : ( $m . 'm' );
        }
        if ( $unit === 'rating' ) {
            return number_format_i18n( $num, 2 );
        }
        // Default: integers as-is, decimals to one place.
        if ( fmod( $num, 1.0 ) === 0.0 ) return number_format_i18n( $num, 0 );
        return number_format_i18n( $num, 1 );
    }

    /**
     * #874 — drilldown table. Renders below the chart when the user
     * picks no group-by. Iterates the fact's dimensions as columns,
     * resolves FK values via `DimensionValueResolver`. Pager via
     * `&page=N` (50 rows per page). Row link routes to the natural
     * entity detail page derived from `Fact::entityScope`.
     */
    private static function renderDrilldownTable( Kpi $kpi, $fact, array $filters ): void {
        $per_page = 50;
        $page = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
        $offset = ( $page - 1 ) * $per_page;

        $total = FactQuery::countRows( $kpi->factKey, $filters );
        $rows  = FactQuery::rows( $kpi->factKey, $filters, $per_page, $offset );

        if ( $total === 0 ) {
            echo '<div class="tt-empty tt-explore-empty">'
                . esc_html__( 'No fact rows match the current filters.', 'talenttrack' )
                . '</div>';
            return;
        }

        $dims = $fact->dimensions;

        // Build the entity-detail URL template for the row link. We
        // map entityScope to the slug + the dimension key that carries
        // the entity id on each row. Fall through gracefully when the
        // mapping doesn't resolve — the row just won't be linked.
        $scope_map = [
            'player'   => [ 'slug' => 'players',    'id_key' => 'player_id' ],
            'team'     => [ 'slug' => 'teams',      'id_key' => 'team_id' ],
            'activity' => [ 'slug' => 'activities', 'id_key' => 'activity_id' ],
        ];
        $link_target = $scope_map[ (string) $fact->entityScope ] ?? null;

        echo '<div class="tt-report-card"><div class="tt-explore-table-wrap">';
        echo '<table class="tt-table tt-explore-table tt-explore-table--wide">';
        echo '<thead><tr>';
        foreach ( $dims as $d ) {
            echo '<th>' . esc_html( $d->label ) . '</th>';
        }
        if ( $link_target !== null ) {
            echo '<th></th>';
        }
        echo '</tr></thead><tbody>';

        foreach ( $rows as $r ) {
            echo '<tr>';
            foreach ( $dims as $d ) {
                $raw = $r->{ $d->key } ?? null;
                $label = DimensionValueResolver::resolve( $d, $raw );
                echo '<td>' . esc_html( (string) $label ) . '</td>';
            }
            if ( $link_target !== null ) {
                $entity_id = (int) ( $r->{ $link_target['id_key'] } ?? 0 );
                if ( $entity_id > 0 ) {
                    $url = \TT\Shared\Frontend\Components\RecordLink::detailUrlForWithBack(
                        $link_target['slug'],
                        $entity_id
                    );
                    echo '<td><a class="tt-record-link" href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'talenttrack' ) . '</a></td>';
                } else {
                    echo '<td></td>';
                }
            }
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div></div>';

        // Pager — `&page=N`. Carries every other current query param so
        // filters + KPI + group_by round-trip.
        $total_pages = (int) ceil( $total / $per_page );
        if ( $total_pages > 1 ) {
            $current = $_GET ?? [];
            $current['tt_view'] = 'explore';
            unset( $current['page'] );
            $prev_url = $page > 1 ? add_query_arg( array_merge( $current, [ 'page' => $page - 1 ] ) ) : '';
            $next_url = $page < $total_pages ? add_query_arg( array_merge( $current, [ 'page' => $page + 1 ] ) ) : '';
            echo '<p class="tt-explore-pager">';
            if ( $prev_url !== '' ) {
                echo '<a href="' . esc_url( $prev_url ) . '">' . esc_html__( '← Prev', 'talenttrack' ) . '</a> ';
            }
            printf(
                /* translators: 1: current page, 2: total pages, 3: total rows */
                esc_html__( 'Page %1$d of %2$d (%3$d rows)', 'talenttrack' ),
                $page, $total_pages, $total
            );
            if ( $next_url !== '' ) {
                echo ' <a href="' . esc_url( $next_url ) . '">' . esc_html__( 'Next →', 'talenttrack' ) . '</a>';
            }
            echo '</p>';
        } else {
            echo '<p class="tt-explore-pager">';
            printf(
                /* translators: %d: total row count */
                esc_html__( 'Showing %d row(s).', 'talenttrack' ),
                $total
            );
            echo '</p>';
        }
    }
}
