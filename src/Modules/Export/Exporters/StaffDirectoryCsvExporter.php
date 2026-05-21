<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * StaffDirectoryCsvExporter (#865) — coach / scout / staff contact list.
 *
 * One row per non-archived `tt_people` row whose `role_type` is in the
 * non-parent set (`coach`, `scout`, `staff`, `other`). Includes their
 * email, phone, role and the comma-joined list of teams they're
 * assigned to (via `tt_team_people`).
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/staff_directory?format=csv|xlsx`
 *   filters:
 *     `role_type` (optional, allowlist: coach / scout / staff / other / all; default all)
 *
 * Cap: `tt_view_people`.
 */
final class StaffDirectoryCsvExporter implements ExporterInterface {

    private const ALLOWED_ROLES = [ 'coach', 'scout', 'staff', 'other', 'all' ];

    public function key(): string { return 'staff_directory'; }

    public function label(): string { return __( 'Coach / staff directory', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv', 'xlsx' ]; }

    public function requiredCap(): string { return 'tt_view_people'; }

    public function validateFilters( array $raw ): ?array {
        $role = isset( $raw['role_type'] ) ? (string) $raw['role_type'] : 'all';
        if ( ! in_array( $role, self::ALLOWED_ROLES, true ) ) $role = 'all';
        return [ 'role_type' => $role ];
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $club_id = (int) $request->clubId;
        $role    = (string) ( $request->filters['role_type'] ?? 'all' );

        $where  = [ 'p.club_id = %d', 'p.archived_at IS NULL', "p.role_type <> 'parent'" ];
        $params = [ $club_id ];
        if ( $role !== 'all' ) {
            $where[]  = 'p.role_type = %s';
            $params[] = $role;
        }

        $sql = "SELECT p.id, p.first_name, p.last_name, p.email, p.phone,
                       p.role_type, p.status,
                       GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS teams
                  FROM {$p}tt_people p
                  LEFT JOIN {$p}tt_team_people tp ON tp.person_id = p.id AND tp.club_id = p.club_id
                  LEFT JOIN {$p}tt_teams t        ON t.id = tp.team_id  AND t.club_id  = p.club_id
                 WHERE " . implode( ' AND ', $where ) . "
                 GROUP BY p.id
                 ORDER BY p.last_name ASC, p.first_name ASC";
        $rows_raw = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $rows_raw = is_array( $rows_raw ) ? $rows_raw : [];

        $headers = [
            __( 'Person ID',  'talenttrack' ),
            __( 'First name', 'talenttrack' ),
            __( 'Last name',  'talenttrack' ),
            __( 'Email',      'talenttrack' ),
            __( 'Phone',      'talenttrack' ),
            __( 'Role type',  'talenttrack' ),
            __( 'Status',     'talenttrack' ),
            __( 'Teams',      'talenttrack' ),
        ];

        $rows = [];
        foreach ( $rows_raw as $r ) {
            $rows[] = [
                (int)    $r->id,
                (string) ( $r->first_name ?? '' ),
                (string) ( $r->last_name ?? '' ),
                (string) ( $r->email ?? '' ),
                (string) ( $r->phone ?? '' ),
                (string) ( $r->role_type ?? '' ),
                (string) ( $r->status ?? '' ),
                (string) ( $r->teams ?? '' ),
            ];
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
