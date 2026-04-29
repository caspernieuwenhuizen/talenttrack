<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class MyEvaluationsThisWeek extends AbstractKpiDataSource {
    public function id(): string { return 'my_evaluations_this_week'; }
    public function label(): string { return __( 'My evaluations this week', 'talenttrack' ); }
    public function context(): string { return PersonaContext::COACH; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        // Some installs use `coach_id` for evaluation authorship rather than
        // `created_by`. Detect once per call and pick the right column.
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'created_by'",
            $table
        ) );
        $author_col = $col ? 'created_by' : 'coach_id';

        $since = gmdate( 'Y-m-d 00:00:00', strtotime( '-7 days' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE {$author_col} = %d AND created_at >= %s",
            $user_id,
            $since
        ) );

        // Sparkline: 4 trailing weekly buckets of *my* evaluations.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( ( $w + 1 ) * 7 ) . ' days' ) );
            $end   = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $w * 7 ) . ' days' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $sparkline[] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE {$author_col} = %d AND created_at >= %s AND created_at < %s",
                $user_id, $start, $end
            ) );
        }
        $trend = null;
        if ( count( $sparkline ) >= 2 ) {
            $last = end( $sparkline );
            $prev = $sparkline[ count( $sparkline ) - 2 ];
            $trend = $last > $prev ? 'up' : ( $last < $prev ? 'down' : 'flat' );
        }
        return KpiValue::of( (string) $count, $trend, null, $sparkline );
    }
}
