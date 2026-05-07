<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * FederationJsonExporter (#0063 use case 11) — federation registration
 * JSON. Per spec Q5 lean: "v1 = single neutral envelope; v2 = per-
 * federation adapters as clubs request them." This is the v1 neutral
 * envelope — clubs map it to their federation's submission format
 * themselves (KNVB / FA / DFB / NFF).
 *
 * URL:
 *   `GET /wp-json/talenttrack/v1/exports/federation_json?format=json`
 *   filters:
 *     `team_id` (optional, default all active teams)
 *     `status`  (optional, default `active`; allowlist: active / archived / trial / all)
 *
 * Output shape (after `JsonRenderer` wraps it in the standard meta envelope):
 *
 *   {
 *     "meta": { ... standard JsonRenderer meta block ... },
 *     "data": {
 *       "club":  { "id": 1, "name": "Club name", "wp_site_url": "..." },
 *       "teams": [
 *         { "id": 42, "name": "U13", "age_group": "U13",
 *           "players": [
 *             { "id": 101, "first_name": "...", "last_name": "...",
 *               "date_of_birth": "2013-04-12", "jersey": 7,
 *               "preferred_foot": "right", "preferred_positions": "ST,LW",
 *               "guardian": { "name": "...", "email": "...", "phone": "..." },
 *               "status": "active", "date_joined": "2024-09-01" }
 *           ]
 *         }
 *       ]
 *     }
 *   }
 *
 * Federation-specific adapters (KNVB / FA / DFB / NFF) ship as separate
 * exporters that consume the same SQL but emit the federation's required
 * shape. They land per-club request rather than upfront — none of the
 * pilot installs use a federation API today.
 *
 * Cap: `tt_view_players` — same gate as the squad-list export.
 */
final class FederationJsonExporter implements ExporterInterface {

    private const ALLOWED_STATUSES = [ 'active', 'archived', 'trial', 'all' ];

    public function key(): string { return 'federation_json'; }

    public function label(): string { return __( 'Federation registration (JSON, neutral envelope)', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'json' ]; }

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

        $where  = [ 'p.club_id = %d' ];
        $params = [ (int) $request->clubId ];

        if ( $status !== 'all' ) {
            $where[]  = 'p.status = %s';
            $params[] = $status;
        }
        if ( $team_id > 0 ) {
            $where[]  = 'p.team_id = %d';
            $params[] = $team_id;
        }

        $sql = "SELECT
                p.id, p.first_name, p.last_name, p.date_of_birth, p.jersey_number,
                p.preferred_foot, p.preferred_positions,
                p.team_id,
                p.status, p.date_joined,
                p.guardian_name, p.guardian_email, p.guardian_phone,
                t.name AS team_name, t.age_group AS team_age_group
            FROM {$p}tt_players p
            LEFT JOIN {$p}tt_teams t ON t.id = p.team_id AND t.club_id = p.club_id
            WHERE " . implode( ' AND ', $where ) . "
            ORDER BY t.age_group ASC, p.last_name ASC, p.first_name ASC";
        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
        $rows = is_array( $rows ) ? $rows : [];

        $teams_index = [];
        foreach ( $rows as $row ) {
            $tid = (int) $row['team_id'];
            if ( ! isset( $teams_index[ $tid ] ) ) {
                $teams_index[ $tid ] = [
                    'id'        => $tid > 0 ? $tid : null,
                    'name'      => (string) ( $row['team_name'] ?? '' ),
                    'age_group' => (string) ( $row['team_age_group'] ?? '' ),
                    'players'   => [],
                ];
            }
            $teams_index[ $tid ]['players'][] = [
                'id'                  => (int) $row['id'],
                'first_name'          => (string) $row['first_name'],
                'last_name'           => (string) $row['last_name'],
                'date_of_birth'       => $row['date_of_birth'] !== null ? (string) $row['date_of_birth'] : null,
                'jersey'              => $row['jersey_number'] !== null ? (int) $row['jersey_number'] : null,
                'preferred_foot'      => (string) $row['preferred_foot'],
                'preferred_positions' => (string) $row['preferred_positions'],
                'guardian'            => [
                    'name'  => (string) $row['guardian_name'],
                    'email' => (string) $row['guardian_email'],
                    'phone' => (string) $row['guardian_phone'],
                ],
                'status'              => (string) $row['status'],
                'date_joined'         => $row['date_joined'] !== null ? (string) $row['date_joined'] : null,
            ];
        }

        $club_name = (string) ( get_option( 'blogname' ) ?: 'TalentTrack club' );

        return [
            'club' => [
                'id'          => (int) $request->clubId,
                'name'        => $club_name,
                'wp_site_url' => (string) home_url(),
            ],
            'teams' => array_values( $teams_index ),
        ];
    }
}
