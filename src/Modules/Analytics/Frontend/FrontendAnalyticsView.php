<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\KpiResolver;
use TT\Shared\Frontend\FrontendBackButton;
use TT\Shared\Frontend\FrontendViewBase;
use TT\Shared\Wizards\WizardEntryPoint;

/**
 * FrontendAnalyticsView — central analytics surface (#0083 Child 5).
 *
 * Reachable at `?tt_view=analytics`. Cap-gated on `tt_view_analytics`
 * which bridges to the `analytics:read` matrix tuple — HoD + Admin
 * by default. Coaches reach analytics through the per-entity tabs
 * (#0083 Child 4) on the players + teams + activities they have
 * access to; they don't get the central exploration view because
 * their analytical work is bounded to their teams.
 *
 * Classified `desktop_only` per #0084 — phone-class user agents see
 * the polite "Open on desktop" page from #0084 Child 1.
 *
 * Child 5 minimum-viable scope: render an academy-wide KPI grid
 * pulling every `ACADEMY`-context KPI plus every KPI without an
 * explicit context (defensive default — uncategorised KPIs surface
 * here rather than disappear). Each card click-throughs to the
 * dimension explorer (#0083 Child 3).
 *
 * **What's deferred** (per spec §`feat-central-analytics-surface`):
 *   - Two-column layout: entity selector on the left, KPI grid on
 *     the right. Today's view renders just the KPI grid; the
 *     entity selector lands in a follow-up.
 *   - Entity-instance picker (e.g. "U13 / U15 / U17" tiles under
 *     "Player").
 *
 * Already operational from earlier children: per-entity drilldown
 * via the existing entity profiles' Analytics tab (Child 4); the
 * dimension explorer (Child 3) where any KPI hand-off lands.
 */
class FrontendAnalyticsView extends FrontendViewBase {

    public static function render( int $user_id, bool $is_admin ): void {
        if ( ! current_user_can( 'tt_view_analytics' ) ) {
            FrontendBackButton::render();
            echo '<p class="tt-notice">' . esc_html__( 'You do not have permission to view central analytics.', 'talenttrack' ) . '</p>';
            return;
        }

        self::renderHeader( __( 'Analytics', 'talenttrack' ) );

        $academy_kpis = KpiRegistry::byContext( Kpi::CONTEXT_ACADEMY );
        if ( empty( $academy_kpis ) ) {
            echo '<p class="tt-notice">'
                . esc_html__( 'No academy-wide KPIs registered yet. Per-entity KPIs are still reachable via the Analytics tab on player, team, and activity profiles.', 'talenttrack' )
                . '</p>';
            return;
        }

        echo '<p style="max-width:760px; color:#5b6e75;">'
            . esc_html__( 'Academy-wide KPIs. Click any card to open the explorer with that KPI loaded; from there you can pivot, group, and filter. For per-entity analytics, open a player, team, or activity and use the Analytics tab on its detail page.', 'talenttrack' )
            . '</p>';

        echo '<div class="tt-analytics-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:16px;">';
        foreach ( $academy_kpis as $key => $kpi ) {
            $value = KpiResolver::value( $key );
            $explore_url = add_query_arg(
                [ 'tt_view' => 'explore', 'kpi' => $key ],
                WizardEntryPoint::dashboardBaseUrl()
            );
            self::renderCard( $kpi, $value, $explore_url );
        }
        echo '</div>';

        echo '<div style="margin-top:32px; padding:12px 16px; background:#f0f6fc; border-left:4px solid #2271b1; max-width:760px; font-size:13px; color:#5b6e75;">'
            . esc_html__( "The central analytics view is the first ship of #0083 Child 5. Two-column layout with an entity selector on the left lands in a follow-up; today the page surfaces academy-wide KPIs only.", 'talenttrack' )
            . '</div>';
    }

    private static function renderCard( Kpi $kpi, ?float $value, string $explore_url ): void {
        $formatted = self::formatValue( $kpi, $value );
        $threshold_color = self::thresholdColor( $kpi, $value );

        echo '<a class="tt-kpi-card" href="' . esc_url( $explore_url ) . '" '
            . 'style="display:block; padding:14px 16px; background:#ffffff; border:1px solid #ddd; border-radius:6px; text-decoration:none; color:inherit;">';
        echo '<div style="font-size:12px; color:#5b6e75; margin-bottom:6px;">'
            . esc_html( $kpi->label )
            . '</div>';
        echo '<div style="font-size:24px; font-weight:600; line-height:1.1; ' . esc_attr( $threshold_color ) . '">'
            . esc_html( $formatted )
            . '</div>';
        echo '</a>';
    }

    private static function thresholdColor( Kpi $kpi, ?float $value ): string {
        if ( $kpi->threshold === null || $value === null ) return '';
        $is_red = ( $kpi->goalDirection === Kpi::GOAL_HIGHER_BETTER && $value < $kpi->threshold )
               || ( $kpi->goalDirection === Kpi::GOAL_LOWER_BETTER  && $value > $kpi->threshold );
        return $is_red ? 'color:#b32d2e;' : '';
    }

    private static function formatValue( Kpi $kpi, ?float $value ): string {
        if ( $value === null ) return '—';
        $fact = \TT\Modules\Analytics\FactRegistry::find( $kpi->factKey );
        $measure = $fact ? $fact->measure( $kpi->measureKey ) : null;
        $unit = $measure ? ( $measure->unit ?? '' ) : '';
        if ( $unit === 'percent' ) return number_format_i18n( $value, 1 ) . '%';
        if ( $unit === 'minutes' ) {
            $h = (int) floor( $value / 60 );
            $m = (int) round( fmod( $value, 60 ) );
            return $h > 0 ? ( $h . 'h ' . $m . 'm' ) : ( $m . 'm' );
        }
        if ( $unit === 'rating' ) return number_format_i18n( $value, 2 );
        if ( fmod( $value, 1.0 ) === 0.0 ) return number_format_i18n( $value, 0 );
        return number_format_i18n( $value, 1 );
    }
}
