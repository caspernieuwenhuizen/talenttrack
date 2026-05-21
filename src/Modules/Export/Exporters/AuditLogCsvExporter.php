<?php
namespace TT\Modules\Export\Exporters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Export\Domain\ExportRequest;
use TT\Modules\Export\ExporterInterface;

/**
 * AuditLogCsvExporter (#865) — dump of `tt_audit_log` entries for
 * compliance / GDPR review. Admin-only.
 *
 * URL:
 *   `POST /wp-json/talenttrack/v1/exports/audit_log?format=csv`
 *   filters:
 *     `date_from`   (Y-m-d, default 30 days ago)
 *     `date_to`     (Y-m-d, default today)
 *     `action`      (optional contains-match)
 *     `entity_type` (optional exact match)
 *
 * Cap: `tt_manage_settings`.
 */
final class AuditLogCsvExporter implements ExporterInterface {

    public function key(): string { return 'audit_log'; }

    public function label(): string { return __( 'Audit log', 'talenttrack' ); }

    public function supportedFormats(): array { return [ 'csv' ]; }

    public function requiredCap(): string { return 'tt_manage_settings'; }

    public function validateFilters( array $raw ): ?array {
        $date_from = isset( $raw['date_from'] ) ? (string) $raw['date_from'] : '';
        $date_to   = isset( $raw['date_to'] )   ? (string) $raw['date_to']   : '';
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) ) {
            $date_from = ( new \DateTime( '-30 days', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            $date_to = ( new \DateTime( 'today', wp_timezone() ) )->format( 'Y-m-d' );
        }
        if ( $date_from > $date_to ) {
            [ $date_from, $date_to ] = [ $date_to, $date_from ];
        }

        $filters = [ 'date_from' => $date_from, 'date_to' => $date_to ];
        if ( ! empty( $raw['action'] ) ) {
            $filters['action'] = (string) $raw['action'];
        }
        if ( ! empty( $raw['entity_type'] ) ) {
            $filters['entity_type'] = (string) $raw['entity_type'];
        }
        return $filters;
    }

    public function collect( ExportRequest $request ): array {
        global $wpdb;
        $p = $wpdb->prefix;
        $table = $p . 'tt_audit_log';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return [ 'headers' => [], 'rows' => [] ];
        }

        $club_id   = (int) $request->clubId;
        $filters   = $request->filters;
        $date_from = (string) ( $filters['date_from'] ?? '' );
        $date_to   = (string) ( $filters['date_to']   ?? '' );

        $where  = [ 'a.club_id = %d', 'a.created_at >= %s', 'a.created_at <= %s' ];
        $params = [ $club_id, $date_from . ' 00:00:00', $date_to . ' 23:59:59' ];
        if ( ! empty( $filters['action'] ) ) {
            $where[]  = 'a.action LIKE %s';
            $params[] = '%' . $wpdb->esc_like( (string) $filters['action'] ) . '%';
        }
        if ( ! empty( $filters['entity_type'] ) ) {
            $where[]  = 'a.entity_type = %s';
            $params[] = (string) $filters['entity_type'];
        }

        $sql = "SELECT a.id, a.created_at, a.user_id, u.display_name AS user_name,
                       a.action, a.entity_type, a.entity_id, a.ip_address, a.payload
                  FROM {$table} a
                  LEFT JOIN {$wpdb->users} u ON u.ID = a.user_id
                 WHERE " . implode( ' AND ', $where ) . "
                 ORDER BY a.created_at DESC, a.id DESC";
        $rows_raw = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        $rows_raw = is_array( $rows_raw ) ? $rows_raw : [];

        $headers = [
            __( 'Audit ID',    'talenttrack' ),
            __( 'Timestamp',   'talenttrack' ),
            __( 'User ID',     'talenttrack' ),
            __( 'User name',   'talenttrack' ),
            __( 'Action',      'talenttrack' ),
            __( 'Entity type', 'talenttrack' ),
            __( 'Entity ID',   'talenttrack' ),
            __( 'IP address',  'talenttrack' ),
            __( 'Payload',     'talenttrack' ),
        ];

        $rows = [];
        foreach ( $rows_raw as $r ) {
            $rows[] = [
                (int)    $r->id,
                (string) ( $r->created_at ?? '' ),
                (int)    ( $r->user_id ?? 0 ),
                (string) ( $r->user_name ?? '' ),
                (string) ( $r->action ?? '' ),
                (string) ( $r->entity_type ?? '' ),
                (int)    ( $r->entity_id ?? 0 ),
                (string) ( $r->ip_address ?? '' ),
                (string) ( $r->payload ?? '' ),
            ];
        }

        return [ 'headers' => $headers, 'rows' => $rows ];
    }
}
