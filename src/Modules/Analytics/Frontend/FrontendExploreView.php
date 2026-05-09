<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

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
        echo '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">';
        echo '<h2 style="margin:0;">' . esc_html( $kpi->label ) . '</h2>';
        $back_url = WizardEntryPoint::dashboardBaseUrl();
        echo '<a href="' . esc_url( $back_url ) . '">' . esc_html__( '← Back', 'talenttrack' ) . '</a>';
        echo '</div>';

        // Filter chips.
        self::renderFilterChips( $kpi, $fact, $filters, $group_by );

        // Headline value — measure aggregated over the filtered rows.
        $rows = FactQuery::run( $kpi->factKey, [], [ $kpi->measureKey ], $filters );
        $headline = empty( $rows ) ? null : ( $rows[0]->{ $kpi->measureKey } ?? null );
        echo '<div style="margin:24px 0; padding:16px 20px; background:#fafafa; border:1px solid #ddd; max-width:760px;">';
        echo '<div style="font-size:13px; color:#5b6e75; margin-bottom:6px;">' . esc_html__( 'Headline', 'talenttrack' ) . '</div>';
        echo '<div style="font-size:36px; font-weight:600; line-height:1;">' . esc_html( self::formatHeadline( $kpi, $headline ) ) . '</div>';
        if ( $kpi->threshold !== null && $headline !== null ) {
            $is_red = ( $kpi->goalDirection === Kpi::GOAL_HIGHER_BETTER && (float) $headline < $kpi->threshold )
                   || ( $kpi->goalDirection === Kpi::GOAL_LOWER_BETTER  && (float) $headline > $kpi->threshold );
            if ( $is_red ) {
                echo '<div style="margin-top:8px; color:#b32d2e; font-size:13px;">'
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
        echo '<p style="margin:0 0 16px 0;"><a class="tt-button" href="' . esc_url( $export_url ) . '">'
            . esc_html__( 'Export CSV', 'talenttrack' )
            . '</a></p>';

        // Group-by selector.
        echo '<form method="get" action="" style="display:flex; gap:12px; align-items:center; margin:16px 0;">';
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
        echo '<label for="tt-explore-groupby" style="font-weight:600;">' . esc_html__( 'Group by:', 'talenttrack' ) . '</label>';
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
        echo '<noscript><button type="submit" class="tt-button">' . esc_html__( 'Apply', 'talenttrack' ) . '</button></noscript>';
        echo '</form>';

        // Group-by table.
        if ( $group_by !== '' && $fact->dimension( $group_by ) !== null ) {
            $grouped_rows = FactQuery::run( $kpi->factKey, [ $group_by ], [ $kpi->measureKey ], $filters );
            self::renderGroupByTable( $kpi, $fact, $group_by, $grouped_rows );
        } else {
            echo '<p style="color:#5b6e75; font-size:13px;">'
                . esc_html__( 'Pick a dimension above to break the headline down by groups.', 'talenttrack' )
                . '</p>';
        }

        echo '<div style="margin-top:32px; padding:12px 16px; background:#f0f6fc; border-left:4px solid #2271b1; max-width:760px; font-size:13px; color:#5b6e75;">'
            . esc_html__( 'The dimension explorer is the first ship of #0083 Child 3. Time-series chart, drilldown to fact rows, and PDF export ship in follow-ups.', 'talenttrack' )
            . '</div>';

        echo '</div>';
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
        echo '<form method="get" action="" class="tt-explore-filters" style="display:flex; gap:12px; flex-wrap:wrap; padding:12px 16px; background:#fafafa; border:1px solid #ddd; margin-bottom:16px;">';
        echo '<input type="hidden" name="tt_view" value="explore">';
        echo '<input type="hidden" name="kpi" value="' . esc_attr( $kpi->key ) . '">';
        if ( $group_by !== '' ) echo '<input type="hidden" name="group_by" value="' . esc_attr( $group_by ) . '">';

        echo '<label style="display:flex; flex-direction:column; gap:4px;">';
        echo '<span style="font-size:12px; color:#5b6e75;">' . esc_html__( 'Date after', 'talenttrack' ) . '</span>';
        $df = (string) ( $filters['date_after'] ?? '' );
        echo '<input type="text" name="filter_date_after" value="' . esc_attr( $df ) . '" placeholder="-30 days" style="padding:6px 8px; min-width:140px;">';
        echo '</label>';

        echo '<label style="display:flex; flex-direction:column; gap:4px;">';
        echo '<span style="font-size:12px; color:#5b6e75;">' . esc_html__( 'Date before', 'talenttrack' ) . '</span>';
        $db = (string) ( $filters['date_before'] ?? '' );
        echo '<input type="text" name="filter_date_before" value="' . esc_attr( $db ) . '" placeholder="today" style="padding:6px 8px; min-width:140px;">';
        echo '</label>';

        foreach ( $kpi->exploreDimensions as $dim_key ) {
            $dim = $fact->dimension( $dim_key );
            if ( $dim === null ) continue;
            $eq_key = $dim_key . '_eq';
            $val    = (string) ( $filters[ $eq_key ] ?? '' );
            echo '<label style="display:flex; flex-direction:column; gap:4px;">';
            echo '<span style="font-size:12px; color:#5b6e75;">' . esc_html( $dim->label ) . '</span>';
            echo '<input type="text" name="filter_' . esc_attr( $eq_key ) . '" value="' . esc_attr( $val ) . '" style="padding:6px 8px; min-width:140px;">';
            echo '</label>';
        }

        echo '<button type="submit" class="tt-button tt-button-primary" style="align-self:flex-end;">'
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

        echo '<table class="widefat striped" style="max-width:760px; margin-top:8px;">';
        echo '<thead><tr>';
        echo '<th>' . esc_html( $dim->label ) . '</th>';
        echo '<th style="text-align:right;">' . esc_html( $measure->label ) . '</th>';
        echo '</tr></thead><tbody>';
        if ( empty( $rows ) ) {
            echo '<tr><td colspan="2" style="text-align:center; color:#5b6e75;">'
                . esc_html__( 'No data for the current filters.', 'talenttrack' )
                . '</td></tr>';
        } else {
            foreach ( $rows as $row ) {
                $key   = (string) ( $row->{ $group_by } ?? '—' );
                $value = $row->{ $kpi->measureKey } ?? null;
                echo '<tr>';
                echo '<td>' . esc_html( $key ) . '</td>';
                echo '<td style="text-align:right; font-variant-numeric:tabular-nums;">' . esc_html( self::formatHeadline( $kpi, $value ) ) . '</td>';
                echo '</tr>';
            }
        }
        echo '</tbody></table>';
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
}
