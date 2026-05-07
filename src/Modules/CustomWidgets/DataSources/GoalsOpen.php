<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * GoalsOpen — reference data source over `tt_goals` (#0078 Phase 1).
 * Spec acceptance scenario: "Goals per principle bar chart from the
 * Goals source."
 */
final class GoalsOpen implements CustomDataSource {

    public function id(): string { return 'goals_open'; }
    public function label(): string { return __( 'Open goals', 'talenttrack' ); }

    public function columns(): array {
        return [
            [ 'key' => 'player',    'label' => __( 'Player', 'talenttrack' ),    'kind' => 'string' ],
            [ 'key' => 'title',     'label' => __( 'Title', 'talenttrack' ),     'kind' => 'string' ],
            [ 'key' => 'status',    'label' => __( 'Status', 'talenttrack' ),    'kind' => 'pill' ],
            [ 'key' => 'due_date',  'label' => __( 'Due date', 'talenttrack' ),  'kind' => 'date' ],
            [ 'key' => 'principle', 'label' => __( 'Principle', 'talenttrack' ), 'kind' => 'string' ],
        ];
    }

    public function filters(): array {
        return [
            [ 'key' => 'status', 'label' => __( 'Status', 'talenttrack' ), 'kind' => 'enum',
              'options' => [
                  [ 'value' => 'active',    'label' => __( 'Active',    'talenttrack' ) ],
                  [ 'value' => 'completed', 'label' => __( 'Completed', 'talenttrack' ) ],
                  [ 'value' => 'archived',  'label' => __( 'Archived',  'talenttrack' ) ],
              ] ],
        ];
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count',           'label' => __( 'Count of goals',     'talenttrack' ), 'kind' => 'count' ],
            [ 'key' => 'distinct_players','label' => __( 'Distinct players',   'talenttrack' ), 'kind' => 'distinct', 'column' => 'player_id' ],
        ];
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'g.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $status = isset( $filters['status'] ) ? (string) $filters['status'] : '';
        if ( $status !== '' && in_array( $status, [ 'active', 'completed', 'archived' ], true ) ) {
            $where[]  = 'g.status = %s';
            $params[] = $status;
        } else {
            $where[] = "g.status = 'active'";
        }

        $sql = "SELECT g.id, g.title, g.status, g.target_date, p.first_name, p.last_name
                  FROM {$p}tt_goals g
             LEFT JOIN {$p}tt_players p ON p.id = g.player_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY g.target_date ASC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'player'    => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'title'     => (string) ( $row->title ?? '' ),
                'status'    => (string) ( $row->status ?? '' ),
                'due_date'  => (string) ( $row->target_date ?? '' ),
                'principle' => '',
            ];
        }
        if ( ! empty( $column_keys ) ) {
            $allowed = array_flip( $column_keys );
            foreach ( $out as $i => $row ) $out[ $i ] = array_intersect_key( $row, $allowed );
        }
        return $out;
    }
}
