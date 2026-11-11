<?php
namespace TT\Modules\CustomWidgets\DataSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\GoalStatus;
use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Query\GoalLifecycle;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Infrastructure\Query\QueryHelpers;
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

    public function requiredCap(): string { return 'tt_view_goals'; }

    public function columns(): array {
        return [
            [ 'key' => 'player',    'label' => __( 'Player', 'talenttrack' ),    'kind' => 'string' ],
            [ 'key' => 'title',     'label' => __( 'Title', 'talenttrack' ),     'kind' => 'string' ],
            [ 'key' => 'status',    'label' => __( 'Status', 'talenttrack' ),    'kind' => 'pill' ],
            [ 'key' => 'due_date',  'label' => __( 'Due date', 'talenttrack' ),  'kind' => 'date' ],
            [ 'key' => 'principle', 'label' => __( 'Principle', 'talenttrack' ), 'kind' => 'string' ],
        ];
    }

    /** @var list<array{value:string, label:string}>|null the status options, read once */
    private ?array $status_options = null;

    /**
     * #4596 — the status filter offers the `goal_status` lookup rows, with
     * their labels through the lookup translation. It used to offer
     * `active`, `completed` and `archived`, which no goal carries. The value
     * is the normalised status (`GoalLifecycle::normalise()`).
     */
    public function filters(): array {
        return [
            [ 'key' => 'status', 'label' => __( 'Status', 'talenttrack' ), 'kind' => 'enum', 'options' => $this->statusOptions() ],
        ];
    }

    /** @return list<array{value:string, label:string}> */
    private function statusOptions(): array {
        if ( $this->status_options !== null ) return $this->status_options;
        $options = [];
        foreach ( QueryHelpers::get_lookups( 'goal_status' ) as $row ) {
            $name = (string) ( $row->name ?? '' );
            if ( $name === '' ) continue;
            $options[] = [ 'value' => GoalLifecycle::normalise( $name ), 'label' => LookupTranslator::name( $row ) ];
        }
        return $this->status_options = $options;
    }

    public function aggregations(): array {
        return [
            [ 'key' => 'count',           'label' => __( 'Count of goals',     'talenttrack' ), 'kind' => 'count' ],
            [ 'key' => 'distinct_players','label' => __( 'Distinct players',   'talenttrack' ), 'kind' => 'distinct', 'column' => 'player_id' ],
        ];
    }

    /**
     * The WHERE the rows and the aggregate share: club, live goals (not
     * archived, not in the recycle bin, as the goals list shows them) and
     * the status.
     *
     * @param array<string,mixed> $filters
     * @return array{0:list<string>, 1:list<int|string>}
     */
    private function whereFor( array $filters, int $user_id ): array {
        $where  = [
            'g.club_id = %d',
            ArchiveRepository::filterClause( 'active', 'g' ),
            SourceScope::playerClause( $user_id, 'goals', 'g.player_id' ),
        ];
        $params = [ CurrentClub::id() ];

        // #4596 — a chosen status from the lookup, or a goal status code;
        // otherwise the open set, through the goal lifecycle chokepoint.
        $status  = isset( $filters['status'] ) ? GoalLifecycle::normalise( (string) $filters['status'] ) : '';
        $allowed = array_merge(
            array_map( [ GoalLifecycle::class, 'normalise' ], GoalStatus::ALL ),
            array_column( $this->statusOptions(), 'value' )
        );
        if ( $status !== '' && in_array( $status, $allowed, true ) ) {
            $where[]  = GoalLifecycle::statusClause( 'g' );
            $params[] = $status;
        } else {
            $where[] = GoalLifecycle::openClause( 'g' );
        }
        return [ $where, $params ];
    }

    /** #4566 — one SQL aggregate over the rows `fetch()` selects. */
    public function aggregate( int $user_id, array $filters, string $aggregation_key ): ?float {
        $select = [
            'count'            => 'COUNT(*)',
            'distinct_players' => 'COUNT( DISTINCT g.player_id )',
        ];
        if ( ! isset( $select[ $aggregation_key ] ) ) return null;

        global $wpdb;
        $p = $wpdb->prefix;
        [ $where, $params ] = $this->whereFor( $filters, $user_id );

        $sql = "SELECT {$select[ $aggregation_key ]} FROM {$p}tt_goals g WHERE " . implode( ' AND ', $where );
        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        return (float) $wpdb->get_var( $wpdb->prepare( $sql, ...$params ) );
    }

    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        [ $where, $params ] = $this->whereFor( $filters, $user_id );

        // #4596 — the column is `due_date`; `target_date` does not exist.
        // Goals without a due date sort last.
        $sql = "SELECT g.id, g.title, g.status, g.due_date, p.first_name, p.last_name
                  FROM {$p}tt_goals g
             LEFT JOIN {$p}tt_players p ON p.id = g.player_id
                 WHERE " . implode( ' AND ', $where )
              . " ORDER BY g.due_date IS NULL, g.due_date ASC, g.id ASC
                 LIMIT %d";
        $params[] = max( 1, min( 1000, $limit ) );

        // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        $rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = [
                'player'    => trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) ),
                'title'     => (string) ( $row->title ?? '' ),
                'status'    => LookupTranslator::byTypeAndName( 'goal_status', (string) ( $row->status ?? '' ) ),
                'due_date'  => (string) ( $row->due_date ?? '' ),
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
