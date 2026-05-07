<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class ActivePlayersTotal extends AbstractKpiDataSource {
    public function id(): string { return 'active_players_total'; }
    public function label(): string { return __( 'Active players', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

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
        if ( $col_exists !== null ) $where[] = $wpdb->prepare( 'club_id = %d', $club_id );
        if ( $has_archived !== null ) $where[] = 'archived_at IS NULL';
        if ( $has_status !== null )   $where[] = $wpdb->prepare( "status = %s", 'active' );
        $where_sql = $where ? ( ' WHERE ' . implode( ' AND ', $where ) ) : '';
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}{$where_sql}" );
        return KpiValue::of( (string) $count );
    }
}
