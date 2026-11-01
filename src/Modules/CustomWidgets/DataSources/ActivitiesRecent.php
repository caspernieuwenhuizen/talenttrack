<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * ActivitiesRecent — reference data source over `tt_activities`
 * (#0078 Phase 1).
 */
final class ActivitiesRecent implements CustomDataSource {

    public function id(): string { return 'activities_recent'; }
    public function label(): string { return __( 'Recent activities', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_view_activities'; }

    public function columns(): array {
        return [
            [ 'key' => 'title',          'label' => __( 'Title', 'talenttrack' ),          'kind' => 'string' ],
            [ 'key' => 'type',           'label' => __( 'Type', 'talenttrack' ),           'kind' => 'string' ],
            [ 'key' => 'team',           'label' => __( 'Team', 'talenttrack' ),           'kind' => 'string' ],
            [ 'key' => 'date',           'label' => __( 'Date', 'talenttrack' ),           'kind' => 'date' ],
            [ 'key' => 'attendance_pct', 'label' => __( 'Attendance %', 'talenttrack' ),   'kind' => 'float' ],
        ];
    }

    public function filters(): array {
        return [
            [ 'key' => 'date_from', 'label' => __( 'Date from', 'talenttrack' ), 'kind' => 'date_range' ],
            [ 'key' => 'date_to',   'label' => __( 'Date to',   'talenttrack' ), 'kind' => 'date_range' ],
            [ 'key' => 'team_id',   'label' => __( 'Team', 'talenttrack' ),       'kind' => 'team' ],
        ];
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count', 'label' => __( 'Count of activities', 'talenttrack' ), 'kind' => 'count' ],
        ];
    }

    /**
     * #4566 — one SQL COUNT over the source's activities, with the declared
     * filters. The date range reads `session_date`, the activities table's
     * date column: there is no `start_at` on `tt_activities` (see
     * `AnalyticsModule::registerInitialFacts()`), so a count keyed on it
     * would fail outright.
     */
    public function aggregate( int $user_id, array $filters, string $aggregation_key ): ?float {
        if ( $aggregation_key !== 'count' ) return null;

        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'a.club_id = %d' ];
        $params = [ CurrentClub::id() ];
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'a.session_date >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'a.session_date <= %s';
            $params[] = (string) $filters['date_to'];
        }
        $team_id = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;
        if ( $team_id > 0 ) {
            $where[]  = 'a.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT COUNT(*) FROM {$p}tt_activities a WHERE " . implode( ' AND ', $where );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (float) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'a.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'a.start_at >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'a.start_at <= %s';
            $params[] = (string) $filters['date_to'];
        }
        $team_id = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;
        if ( $team_id > 0 ) {
            $where[]  = 'a.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT a.id, a.title, a.session_type, a.start_at, t.name AS team_name
                  FROM {$p}tt_activities a
             LEFT JOIN {$p}tt_teams t ON t.id = a.team_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY a.start_at DESC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'title'          => (string) ( $row->title ?? '' ),
                'type'           => (string) ( $row->session_type ?? '' ),
                'team'           => (string) ( $row->team_name ?? '' ),
                'date'           => (string) ( $row->start_at ?? '' ),
                'attendance_pct' => '',
            ];
        }
        if ( ! empty( $column_keys ) ) {
            $allowed = array_flip( $column_keys );
            foreach ( $out as $i => $row ) $out[ $i ] = array_intersect_key( $row, $allowed );
        }
        return $out;
    }
}
