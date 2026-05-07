<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomDataSource;

/**
 * PlayersActive — reference data source over `tt_players` for the
 * custom widget builder (#0078 Phase 1).
 *
 * Returns rows for the active player roster scoped to the current
 * club. Filters: team, age range. Aggregations: count + distinct
 * teams. Used by the spec's first acceptance scenario ("Top 10
 * active players from the Players source").
 */
final class PlayersActive implements CustomDataSource {

    public function id(): string { return 'players_active'; }
    public function label(): string { return __( 'Active players', 'talenttrack' ); }

    /**
     * Source-cap inheritance (#0078 Phase 5). The renderer + REST data
     * route check this and refuse to fetch when the viewer can't read
     * the underlying records. Detected via `method_exists` so adding
     * the method doesn't break the `CustomDataSource` interface
     * contract for plugin authors.
     */
    public function requiredCap(): string { return 'tt_view_players'; }

    public function columns(): array {
        return [
            [ 'key' => 'name',     'label' => __( 'Name', 'talenttrack' ),     'kind' => 'string' ],
            [ 'key' => 'team',     'label' => __( 'Team', 'talenttrack' ),     'kind' => 'string' ],
            [ 'key' => 'age',      'label' => __( 'Age', 'talenttrack' ),      'kind' => 'int' ],
            [ 'key' => 'position', 'label' => __( 'Position', 'talenttrack' ), 'kind' => 'string' ],
            [ 'key' => 'status',   'label' => __( 'Status', 'talenttrack' ),   'kind' => 'pill' ],
        ];
    }

    public function filters(): array {
        return [
            [ 'key' => 'team_id',      'label' => __( 'Team', 'talenttrack' ),      'kind' => 'team' ],
            [
                'key'   => 'age_range',
                'label' => __( 'Age range', 'talenttrack' ),
                'kind'  => 'enum',
                'options' => [
                    [ 'value' => 'u9',  'label' => __( 'U9',  'talenttrack' ) ],
                    [ 'value' => 'u11', 'label' => __( 'U11', 'talenttrack' ) ],
                    [ 'value' => 'u13', 'label' => __( 'U13', 'talenttrack' ) ],
                    [ 'value' => 'u15', 'label' => __( 'U15', 'talenttrack' ) ],
                    [ 'value' => 'u17', 'label' => __( 'U17', 'talenttrack' ) ],
                    [ 'value' => 'u19', 'label' => __( 'U19', 'talenttrack' ) ],
                ],
            ],
        ];
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count',          'label' => __( 'Count of players', 'talenttrack' ), 'kind' => 'count' ],
            [ 'key' => 'distinct_teams', 'label' => __( 'Distinct teams',   'talenttrack' ), 'kind' => 'distinct', 'column' => 'team_id' ],
        ];
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $where  = [ 'p.club_id = %d', "p.status <> 'archived'" ];
        $params = [ CurrentClub::id() ];

        $team_id = isset( $filters['team_id'] ) ? (int) $filters['team_id'] : 0;
        if ( $team_id > 0 ) {
            $where[]  = 'p.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT p.id, p.first_name, p.last_name, p.position, p.status, p.team_id, p.date_of_birth,
                       t.name AS team_name
                  FROM {$p}tt_players p
             LEFT JOIN {$p}tt_teams t ON t.id = p.team_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY p.last_name ASC, p.first_name ASC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $age = '';
            if ( ! empty( $row->date_of_birth ) ) {
                $dob = strtotime( (string) $row->date_of_birth );
                if ( $dob !== false ) {
                    $age = (int) ( ( time() - $dob ) / ( 365.25 * DAY_IN_SECONDS ) );
                }
            }
            $out[] = [
                'name'     => trim( (string) $row->first_name . ' ' . (string) $row->last_name ),
                'team'     => (string) ( $row->team_name ?? '' ),
                'age'      => $age,
                'position' => (string) ( $row->position ?? '' ),
                'status'   => (string) ( $row->status ?? '' ),
            ];
        }
        // Filter to requested columns only.
        if ( ! empty( $column_keys ) ) {
            $allowed = array_flip( $column_keys );
            foreach ( $out as $i => $row ) {
                $out[ $i ] = array_intersect_key( $row, $allowed );
            }
        }
        return $out;
    }
}
