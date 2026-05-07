<?php
namespace TT\Modules\Analytics\Frontend;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Analytics\Domain\Kpi;
use TT\Modules\Analytics\FactRegistry;
use TT\Modules\Analytics\KpiRegistry;
use TT\Modules\Analytics\KpiResolver;

/**
 * EntityAnalyticsTabRenderer — reusable KPI grid for entity profiles
 * (#0083 Child 4).
 *
 * Each entity detail view (player profile, team profile, activity
 * detail) gains an "Analytics" tab. The tab content is generated
 * from `KpiRegistry::forEntity( $scope )` filtered to the persona's
 * `context`, with the entity's id baked in as a default filter so
 * each KPI value is scoped to "this player" / "this team" /
 * "this activity."
 *
 * **Mobile.** This renderer uses CSS-grid responsive at every
 * breakpoint — no per-template responsive code needed. The hosting
 * detail views set their own mobile_class; the analytics tab inside
 * them inherits.
 *
 * **Capability gating.** A parent on their child's profile sees
 * only `PLAYER_PARENT`-context KPIs. A coach sees `COACH` (plus
 * `ACADEMY` if they hold the academy roles too — superset). HoD /
 * Admin see every KPI for the scope.
 *
 * **Click-through.** Each card is a link to `?tt_view=explore&kpi={key}`
 * with the entity id pre-applied as a filter, so the explorer opens
 * scoped to this entity. The drilldown story is one click everywhere.
 */
final class EntityAnalyticsTabRenderer {

    /**
     * Render the KPI grid for `$scope` (`'player'` / `'team'` /
     * `'activity'`) and `$entity_id`. The entity id is mapped to
     * the right filter key per scope:
     *   - player   → `player_id_eq = $entity_id`
     *   - team     → `team_id_eq   = $entity_id`
     *   - activity → `activity_id_eq = $entity_id`
     */
    public static function render( string $scope, int $entity_id ): void {
        $kpis = self::resolveKpisForScope( $scope );
        if ( empty( $kpis ) ) {
            echo '<p class="tt-notice">' . esc_html__( 'No analytics available for this entity yet.', 'talenttrack' ) . '</p>';
            return;
        }

        $entity_filter_key = self::filterKeyForScope( $scope );

        echo '<div class="tt-analytics-grid" style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:12px; margin-top:12px;">';
        foreach ( $kpis as $key => $kpi ) {
            $extra_filters = $entity_filter_key !== null
                ? [ $entity_filter_key => $entity_id ]
                : [];
            $value = KpiResolver::value( $key, $extra_filters );
            $explore_url = add_query_arg(
                array_merge(
                    [ 'tt_view' => 'explore', 'kpi' => $key ],
                    $entity_filter_key !== null ? [ 'filter_' . $entity_filter_key => $entity_id ] : []
                ),
                ( class_exists( '\\TT\\Shared\\Wizards\\WizardEntryPoint' )
                    ? \TT\Shared\Wizards\WizardEntryPoint::dashboardBaseUrl()
                    : home_url( '/' ) )
            );
            self::renderCard( $kpi, $value, $explore_url );
        }
        echo '</div>';
    }

    /**
     * Resolve which KPIs to show for `$scope`, filtered to the
     * current persona context. The intersection — KPIs whose
     * `entityScope === $scope` AND whose `context` matches the
     * caller — is what the user sees.
     *
     * @return array<string, Kpi>
     */
    private static function resolveKpisForScope( string $scope ): array {
        $by_entity = KpiRegistry::forEntity( $scope );
        if ( empty( $by_entity ) ) return [];

        $allowed_contexts = self::contextsForCurrentUser();
        if ( empty( $allowed_contexts ) ) return [];

        $out = [];
        foreach ( $by_entity as $key => $kpi ) {
            if ( $kpi->context === null || in_array( $kpi->context, $allowed_contexts, true ) ) {
                $out[ $key ] = $kpi;
            }
        }
        return $out;
    }

    /**
     * Persona contexts the current user is entitled to see. Built
     * from WP roles / TT caps:
     *   - `tt_edit_settings` (admin / HoD)         → ACADEMY + COACH + PLAYER_PARENT
     *   - `tt_view_player_notes` (staff)           → COACH + PLAYER_PARENT
     *   - `tt_parent` role / linked-player check   → PLAYER_PARENT only
     *   - other logged-in users                    → PLAYER_PARENT only (curated subset)
     *
     * @return string[]
     */
    private static function contextsForCurrentUser(): array {
        if ( current_user_can( 'tt_edit_settings' ) ) {
            return [ Kpi::CONTEXT_ACADEMY, Kpi::CONTEXT_COACH, Kpi::CONTEXT_PLAYER_PARENT ];
        }
        if ( current_user_can( 'tt_view_player_notes' ) || current_user_can( 'tt_view_evaluations' ) ) {
            return [ Kpi::CONTEXT_COACH, Kpi::CONTEXT_PLAYER_PARENT ];
        }
        return [ Kpi::CONTEXT_PLAYER_PARENT ];
    }

    private static function filterKeyForScope( string $scope ): ?string {
        switch ( $scope ) {
            case 'player':   return 'player_id_eq';
            case 'team':     return 'team_id_eq';
            case 'activity': return 'activity_id_eq';
            default:         return null;
        }
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
        $fact    = FactRegistry::find( $kpi->factKey );
        $measure = $fact ? $fact->measure( $kpi->measureKey ) : null;
        $unit    = $measure ? ( $measure->unit ?? '' ) : '';
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
