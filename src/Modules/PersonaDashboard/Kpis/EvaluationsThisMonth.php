<?php
namespace TT\Modules\PersonaDashboard\Kpis;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\PersonaDashboard\Domain\AbstractKpiDataSource;
use TT\Modules\PersonaDashboard\Domain\KpiValue;
use TT\Modules\PersonaDashboard\Domain\PersonaContext;

class EvaluationsThisMonth extends AbstractKpiDataSource {
    public function id(): string { return 'evaluations_this_month'; }
    public function label(): string { return __( 'Evaluations this month', 'talenttrack' ); }
    public function context(): string { return PersonaContext::ACADEMY; }

    public function compute( int $user_id, int $club_id ): KpiValue {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_evaluations';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return KpiValue::unavailable();
        }
        $first = gmdate( 'Y-m-01 00:00:00' );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s",
            $first
        ) );

        // Sparkline: 4 trailing weekly buckets so the strip + cards
        // render a real trend without per-render history queries.
        $sparkline = [];
        for ( $w = 3; $w >= 0; $w-- ) {
            $start = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( ( $w + 1 ) * 7 ) . ' days' ) );
            $end   = gmdate( 'Y-m-d 00:00:00', strtotime( '-' . ( $w * 7 ) . ' days' ) );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            $sparkline[] = (float) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s",
                $start, $end
            ) );
        }
        $trend = $this->trendFromSparkline( $sparkline );
        return KpiValue::of( (string) $count, $trend, null, $sparkline );
    }

    /** @param list<float> $values */
    private function trendFromSparkline( array $values ): ?string {
        if ( count( $values ) < 2 ) return null;
        $last = end( $values );
        $prev = $values[ count( $values ) - 2 ];
        if ( $last > $prev ) return 'up';
        if ( $last < $prev ) return 'down';
        return 'flat';
    }
}
