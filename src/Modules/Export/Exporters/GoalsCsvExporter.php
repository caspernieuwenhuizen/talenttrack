<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * GoalsCsvExporter (#0063 use case 7) — every active goal across a
 * team, with status, priority, owner and target date.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/goals_list?format=csv`
 *   filters:
 *     `team_id` (optional)
 *     `status`  (optional, default 'pending'; allowlist: pending /
 *                in_progress / completed / archived / all)
 *
 * Cap: `tt_view_goals`.
 */
final class GoalsCsvExporter implements ExporterInterface {

    private const ALLOWED_STATUSES = [
        'pending', 'in_progress', 'completed', 'archived', 'all',
    ];

    public function key(): string { return 'goals_list'; }

    public function label(): string { return __( 'Goals list (CSV)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv' ]; }

    public function requiredCap(): string { return 'tt_view_goals'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

        $status = isset( $raw['status'] ) ? (string) $raw['status'] : 'pending';
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) $status = 'pending';

        return [ 'team_id' => $team_id, 'status' => $status ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $filters = $request->filters;
        $team_id = (int) ( $filters['team_id'] ?? 0 );
        $status  = (string) ( $filters['status']  ?? 'pending' );

        $where  = [ 'g.club_id = %d' ];
        $params = [ (int) $request->clubId ];

        if ( $status !== 'all' ) {
            $where[]  = 'g.status = %s';
            $params[] = $status;
        }
        if ( $team_id > 0 ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT g.id, g.title, g.description, g.status, g.priority, g.due_date,
                       g.created_at, g.updated_at,
                       pl.id AS player_id, pl.first_name, pl.last_name,
                       t.name AS team_name,
                       u.display_name AS owner_name
                  FROM {$p}tt_goals g
                  INNER JOIN {$p}tt_players pl ON pl.id = g.player_id AND pl.club_id = g.club_id
                  LEFT JOIN  {$p}tt_teams t    ON t.id = pl.team_id    AND t.club_id  = pl.club_id
                  LEFT JOIN  {$wpdb->users} u  ON u.ID = g.created_by
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY g.due_date ASC, t.name ASC, pl.last_name ASC, pl.first_name ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $headers = [
            __( 'Goal ID',     'talenttrack' ),
            __( 'Title',       'talenttrack' ),
            __( 'Description', 'talenttrack' ),
            __( 'Status',      'talenttrack' ),
            __( 'Priority',    'talenttrack' ),
            __( 'Due date',    'talenttrack' ),
            __( 'Player ID',   'talenttrack' ),
            __( 'First name',  'talenttrack' ),
            __( 'Last name',   'talenttrack' ),
            __( 'Team',        'talenttrack' ),
            __( 'Owner',       'talenttrack' ),
            __( 'Created',     'talenttrack' ),
            __( 'Updated',     'talenttrack' ),
        ];

        $out_rows = [];
        foreach ( $rows as $r ) {
            $out_rows[] = [
                (int)    $r->id,
                (string) ( $r->title ?? '' ),
                (string) ( $r->description ?? '' ),
                (string) ( $r->status ?? '' ),
                (string) ( $r->priority ?? '' ),
                (string) ( $r->due_date ?? '' ),
                (int)    $r->player_id,
                (string) ( $r->first_name ?? '' ),
                (string) ( $r->last_name ?? '' ),
                (string) ( $r->team_name ?? '' ),
                (string) ( $r->owner_name ?? '' ),
                (string) ( $r->created_at ?? '' ),
                (string) ( $r->updated_at ?? '' ),
            ];
        }

        return [ 'headers' => $headers, 'rows' => $out_rows ];
    }
}
