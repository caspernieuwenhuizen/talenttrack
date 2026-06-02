<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Shared\Wizards\WizardEntryPoint;

/**
 * ExplorerUrl — central builder for "Explorer →" preset links
 * (#1063 standard-reports presets, #1096-#1101).
 *
 * Every preset is `?tt_view=explore&kpi=<key>&filter_<dim>=...&group_by=...`.
 * The explorer (`FrontendExploreView`) reads `filter_<dim>` and
 * `filter_date_after` / `filter_date_before` shortcuts; this helper
 * just composes those names so the call sites stay short.
 *
 * Defaults shipped per the standard-reports mockup batch:
 *   - Date window: 12 months back from today when not specified
 *     (preset mockups all show a "aug 2025 – mei 2026" season window).
 */
final class ExplorerUrl {

    /**
     * Build the URL for an explorer preset.
     *
     * @param string                 $kpi_key  Registered KPI key.
     * @param array<string,scalar>   $filters  `[ dim_key => value, … ]`. `date_after` / `date_before` are honoured.
     * @param string                 $group_by Optional group-by dimension.
     * @return string
     */
    public static function build( string $kpi_key, array $filters = [], string $group_by = '' ): string {
        $args = [
            'tt_view' => 'explore',
            'kpi'     => $kpi_key,
        ];
        foreach ( $filters as $dim => $value ) {
            if ( $value === null || $value === '' ) continue;
            $args[ 'filter_' . $dim ] = (string) $value;
        }
        if ( $group_by !== '' ) {
            $args['group_by'] = $group_by;
        }
        return add_query_arg( $args, WizardEntryPoint::dashboardBaseUrl() );
    }
}
