<?php
namespace TT\Modules\PersonaDashboard\TableSources;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\PersonaDashboard\Registry\TableRowSource;

/**
 * AuditLogRecentSource (#0073 follow-up) — wires the `audit_log_recent`
 * `DataTableWidget` preset on the Academy Admin landing.
 *
 * Columns: When | Who | What.
 */
final class AuditLogRecentSource implements TableRowSource {

    /**
     * @param array<string, mixed> $config
     * @return list<list<string>>
     */
    public function rowsFor( int $user_id, array $config ): array {
        $limit  = max( 1, min( 50, (int) ( $config['limit'] ?? 5 ) ) );
        $audit  = new AuditService();
        $events = $audit->recent( $limit );
        if ( $events === [] ) return [];

        $out = [];
        foreach ( $events as $e ) {
            $when = '';
            if ( ! empty( $e->created_at ) ) {
                try {
                    $when = wp_date( 'D j M, H:i', ( new \DateTimeImmutable( $e->created_at ) )->getTimestamp() );
                } catch ( \Exception $ex ) {
                    $when = (string) $e->created_at;
                }
            }
            $who  = (string) ( $e->user_name ?? '' );
            if ( $who === '' && ! empty( $e->user_id ) ) $who = '#' . (int) $e->user_id;
            if ( $who === '' ) $who = __( 'system', 'talenttrack' );

            $what_parts = [ (string) ( $e->action ?? '' ) ];
            if ( ! empty( $e->entity_type ) ) $what_parts[] = (string) $e->entity_type;
            if ( ! empty( $e->entity_id ) )   $what_parts[] = '#' . (int) $e->entity_id;
            $what = implode( ' · ', array_filter( $what_parts ) );

            $out[] = [
                esc_html( $when !== '' ? $when : '—' ),
                esc_html( $who ),
                esc_html( $what !== '' ? $what : '—' ),
            ];
        }
        return $out;
    }
}
