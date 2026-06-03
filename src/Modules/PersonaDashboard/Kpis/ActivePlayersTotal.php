<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;
use TT\Modules\PersonaDashboard\Domain\RenderContext;

class ActivePlayersTotal extends AbstractKpiDataSource {
    public function id(): string { return 'active_players_total'; }
    public function label(): string { return __( 'Active players', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    /**
     * v4.20.23 (#1209) — Deep-link to the players list with the same
     * status filter `compute()` applies. Without this, KPI "12 active
     * players" landed on `?tt_view=players` which defaults to ALL
     * non-archived (including trial / released / inactive with
     * `archived_at IS NULL`). Operators saw 17 rows under a "12"
     * headline. Now the destination is filtered to `status=active`,
     * matching the KPI 1:1. Lands live in the dominant `kpi_card`
     * placement courtesy of v4.20.22's `KpiCardWidget` → `linkUrl()`
     * routing (#1207).
     */
    public function linkUrl( RenderContext $ctx ): string {
        $view = $this->linkView();
        if ( $view === '' ) return '';
        return add_query_arg( [ 'filter' => [ 'status' => 'active' ] ], $ctx->viewUrl( $view ) );
    }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_players';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $col_exists = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'club_id'",
            $table
        ) );
        // v3.108.5 — was counting every row including archived /
        // released players. The KPI is labelled "Active players"
        // so apply both the `archived_at IS NULL` filter and
        // `status='active'` (skipping trial / released / inactive).
        $has_archived = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'archived_at'",
            $table
        ) );
        $has_status = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'status'",
            $table
        ) );
        $where = [];
        if ( $col_exists !== null ) $where[] = $wpdb->prepare( 'p.club_id = %d', $club_id );
        if ( $has_archived !== null ) $where[] = 'p.archived_at IS NULL';
        if ( $has_status !== null )   $where[] = $wpdb->prepare( "p.status = %s", 'active' );
        $where_sql = $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '';
        // v3.110.164 (#478) — apply demo-scope so the KPI matches the
        // players-list view. Pre-fix: pilot in demo-ON mode saw the
        // KPI say 30 while the list said 29. The list applies
        // `apply_demo_scope('p','player')`; this KPI didn't. Off-by-N
        // = the number of players that exist on disk but aren't in
        // `tt_demo_tags`. With the demo-scope filter both surfaces
        // agree on visibility. Table aliased as `p` so the scope
        // fragment (`AND p.id IN (…)`) joins cleanly.
        $scope = QueryHelpers::apply_demo_scope( 'p', 'player' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} p{$where_sql}{$scope}" );
        return KpiValue::of( (string) $count );
    }
}
