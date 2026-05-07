<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * PdpFiles — reference data source over `tt_pdp_files` (#0078 Phase 1).
 */
final class PdpFiles implements CustomDataSource {

    public function id(): string { return 'pdp_files'; }
    public function label(): string { return __( 'PDP files', 'talenttrack' ); }

    public function requiredCap(): string { return 'tt_view_pdp'; }

    public function columns(): array {
        return [
            [ 'key' => 'player',             'label' => __( 'Player', 'talenttrack' ),               'kind' => 'string' ],
            [ 'key' => 'season',             'label' => __( 'Season', 'talenttrack' ),               'kind' => 'string' ],
            [ 'key' => 'status',             'label' => __( 'Status', 'talenttrack' ),               'kind' => 'pill' ],
            [ 'key' => 'conversations_done', 'label' => __( 'Conversations done', 'talenttrack' ),   'kind' => 'int' ],
            [ 'key' => 'cycle_size',         'label' => __( 'Cycle size', 'talenttrack' ),           'kind' => 'int' ],
        ];
    }

    public function filters(): array {
        return [
            [ 'key' => 'season_id', 'label' => __( 'Season', 'talenttrack' ), 'kind' => 'season' ],
            [ 'key' => 'status',    'label' => __( 'Status', 'talenttrack' ), 'kind' => 'enum',
              'options' => [
                  [ 'value' => 'active',    'label' => __( 'Active',    'talenttrack' ) ],
                  [ 'value' => 'completed', 'label' => __( 'Completed', 'talenttrack' ) ],
                  [ 'value' => 'archived',  'label' => __( 'Archived',  'talenttrack' ) ],
              ] ],
        ];
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count', 'label' => __( 'Count of PDP files', 'talenttrack' ), 'kind' => 'count' ],
        ];
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = $p . 'tt_pdp_files';
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return [];
        }

        $where  = [ 'pf.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        $status = isset( $filters['status'] ) ? (string) $filters['status'] : '';
        if ( $status !== '' && in_array( $status, [ 'active', 'completed', 'archived' ], true ) ) {
            $where[]  = 'pf.status = %s';
            $params[] = $status;
        }

        $season_id = isset( $filters['season_id'] ) ? (int) $filters['season_id'] : 0;
        if ( $season_id > 0 ) {
            $where[]  = 'pf.season_id = %d';
            $params[] = $season_id;
        }

        $sql = "SELECT pf.id, pf.status, pf.season_id, p.first_name, p.last_name
                  FROM {$table} pf
             LEFT JOIN {$p}tt_players p ON p.id = pf.player_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY pf.id DESC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'player'             => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'season'             => (string) ( $row->season_id ?? '' ),
                'status'             => (string) ( $row->status ?? '' ),
                'conversations_done' => 0,
                'cycle_size'         => 0,
            ];
        }
        if ( ! empty( $column_keys ) ) {
            $allowed = array_flip( $column_keys );
            foreach ( $out as $i => $row ) $out[ $i ] = array_intersect_key( $row, $allowed );
        }
        return $out;
    }
}
