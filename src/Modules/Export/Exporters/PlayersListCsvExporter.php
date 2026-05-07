<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * PlayersListCsvExporter (#0063 use case 3) — squad-list CSV.
 *
 * "Every active player in U13 with birthdate and parent email" — the
 * canonical federation / cup-registration export. One row per player,
 * filterable by team and status.
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/players_list?format=csv`
 *   filters:
 *     `team_id` (optional, default all teams)
 *     `status`  (optional, default 'active'; allowlist: active / archived / trial / all)
 *
 * Cap: `tt_view_players` — same gate as the players admin.
 */
final class PlayersListCsvExporter implements ExporterInterface {

    private const ALLOWED_STATUSES = [ 'active', 'archived', 'trial', 'all' ];

    public function key(): string { return 'players_list'; }

    public function label(): string { return __( 'Players list (CSV)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv' ]; }

    public function requiredCap(): string { return 'tt_view_players'; }

    public function validateFilters( array $raw ): ?array {
        $team_id = isset( $raw['team_id'] ) ? (int) $raw['team_id'] : 0;
        if ( $team_id < 0 ) $team_id = 0;

        $status = isset( $raw['status'] ) ? (string) $raw['status'] : 'active';
        if ( ! in_array( $status, self::ALLOWED_STATUSES, true ) ) $status = 'active';

        return [ 'team_id' => $team_id, 'status' => $status ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $filters = $request->filters;
        $team_id = (int) ( $filters['team_id'] ?? 0 );
        $status  = (string) ( $filters['status'] ?? 'active' );

        $where  = [ 'pl.club_id = %d' ];
        $params = [ (int) $request->clubId ];

        if ( $status !== 'all' ) {
            $where[]  = 'pl.status = %s';
            $params[] = $status;
        }
        if ( $team_id > 0 ) {
            $where[]  = 'pl.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT pl.id, pl.first_name, pl.last_name, pl.date_of_birth,
                       pl.jersey_number, pl.preferred_foot, pl.preferred_positions,
                       pl.guardian_name, pl.guardian_email, pl.guardian_phone,
                       pl.status, pl.date_joined,
                       t.name AS team_name
                  FROM {$p}tt_players pl
                  LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id AND t.club_id = pl.club_id
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY t.name ASC, pl.last_name ASC, pl.first_name ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        if ( ! is_array( $rows ) ) $rows = [];

        $headers = [
            __( 'Player ID',          'talenttrack' ),
            __( 'First name',         'talenttrack' ),
            __( 'Last name',          'talenttrack' ),
            __( 'Date of birth',      'talenttrack' ),
            __( 'Jersey number',      'talenttrack' ),
            __( 'Preferred foot',     'talenttrack' ),
            __( 'Preferred positions','talenttrack' ),
            __( 'Team',               'talenttrack' ),
            __( 'Guardian name',      'talenttrack' ),
            __( 'Guardian email',     'talenttrack' ),
            __( 'Guardian phone',     'talenttrack' ),
            __( 'Status',             'talenttrack' ),
            __( 'Date joined',        'talenttrack' ),
        ];

        $out_rows = [];
        foreach ( $rows as $r ) {
            $out_rows[] = [
                (int)    $r->id,
                (string) $r->first_name,
                (string) $r->last_name,
                (string) ( $r->date_of_birth ?? '' ),
                $r->jersey_number !== null ? (int) $r->jersey_number : '',
                (string) ( $r->preferred_foot ?? '' ),
                (string) ( $r->preferred_positions ?? '' ),
                (string) ( $r->team_name ?? '' ),
                (string) ( $r->guardian_name ?? '' ),
                (string) ( $r->guardian_email ?? '' ),
                (string) ( $r->guardian_phone ?? '' ),
                (string) ( $r->status ?? '' ),
                (string) ( $r->date_joined ?? '' ),
            ];
        }

        return [ 'headers' => $headers, 'rows' => $out_rows ];
    }
}
