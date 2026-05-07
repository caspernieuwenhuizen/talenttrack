<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * EvaluationsRecent — reference data source over `tt_evaluations`
 * (#0078 Phase 1). Spec acceptance scenario: "Average evaluation
 * rating per coach (last 30 days) KPI from the Evaluations source."
 */
final class EvaluationsRecent implements CustomDataSource {

    public function id(): string { return 'evaluations_recent'; }
    public function label(): string { return __( 'Recent evaluations', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_view_evaluations'; }

    public function columns(): array {
        return [
            [ 'key' => 'player',    'label' => __( 'Player', 'talenttrack' ),    'kind' => 'string' ],
            [ 'key' => 'eval_date', 'label' => __( 'Date', 'talenttrack' ),     'kind' => 'date' ],
            [ 'key' => 'evaluator', 'label' => __( 'Evaluator', 'talenttrack' ), 'kind' => 'string' ],
            [ 'key' => 'overall',   'label' => __( 'Overall', 'talenttrack' ),  'kind' => 'float' ],
        ];
    }

    public function filters(): array {
        return [
            [ 'key' => 'date_from', 'label' => __( 'Date from', 'talenttrack' ), 'kind' => 'date_range' ],
            [ 'key' => 'date_to',   'label' => __( 'Date to', 'talenttrack' ),   'kind' => 'date_range' ],
            [ 'key' => 'team_id',   'label' => __( 'Team', 'talenttrack' ),      'kind' => 'team' ],
        ];
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count',       'label' => __( 'Count of evaluations', 'talenttrack' ), 'kind' => 'count' ],
            [ 'key' => 'avg_overall', 'label' => __( 'Average overall', 'talenttrack' ),     'kind' => 'avg', 'column' => 'overall' ],
        ];
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'e.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'e.created_at >= %s';
            $params[] = (string) $filters['date_from'];
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'e.created_at <= %s';
            $params[] = (string) $filters['date_to'];
        }

        $sql = "SELECT e.id, e.created_at, e.evaluator_id, p.first_name, p.last_name,
                       u.display_name AS evaluator_name
                  FROM {$p}tt_evaluations e
             LEFT JOIN {$p}tt_players p ON p.id = e.player_id
             LEFT JOIN {$wpdb->users} u ON u.ID = e.evaluator_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY e.created_at DESC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'player'    => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'eval_date' => (string) ( $row->created_at ?? '' ),
                'evaluator' => (string) ( $row->evaluator_name ?? '' ),
                'overall'   => '',
            ];
        }
        if ( ! empty( $column_keys ) ) {
            $allowed = array_flip( $column_keys );
            foreach ( $out as $i => $row ) $out[ $i ] = array_intersect_key( $row, $allowed );
        }
        return $out;
    }
}
