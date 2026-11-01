<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Evaluations\EvalRatingsRepository;
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

    /**
     * The WHERE the rows and the aggregate share: club and the date range.
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>, 1:list<int|string>}
     */
    private function whereFor( array $filters ): array {
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
        return [ $where, $params ];
    }

    /**
     * #4566 — `count` is one SQL COUNT. `avg_overall` averages the
     * evaluations' overall ratings as the rest of the product computes
     * them: `EvalRatingsRepository::overallRatingsForEvaluations()`, the
     * weighted mean of the main categories per age group. There is no
     * stored overall column to average in SQL, and a second formula here
     * would disagree with the player profile. Evaluations nobody rated are
     * left out, so they do not pull the average to 0.
     */
    public function aggregate( int $user_id, array $filters, string $aggregation_key ): ?float {
        if ( $aggregation_key !== 'count' && $aggregation_key !== 'avg_overall' ) return null;

        global $wpdb;
        $p = $wpdb->prefix;
        [ $where, $params ] = $this->whereFor( $filters );

        if ( $aggregation_key === 'count' ) {
            $sql = "SELECT COUNT(*) FROM {$p}tt_evaluations e WHERE " . implode( ' AND ', $where );
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
            return (float) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
        }

        $sql = "SELECT e.id FROM {$p}tt_evaluations e WHERE " . implode( ' AND ', $where );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, ...$params ) ) );

        $sum = 0.0;
        $n   = 0;
        foreach ( ( new EvalRatingsRepository() )->overallRatingsForEvaluations( $ids ) as $overall ) {
            if ( $overall['value'] === null ) continue;
            $sum += (float) $overall['value'];
            $n++;
        }
        return $n > 0 ? round( $sum / $n, 1 ) : null;
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        [ $where, $params ] = $this->whereFor( $filters );

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
