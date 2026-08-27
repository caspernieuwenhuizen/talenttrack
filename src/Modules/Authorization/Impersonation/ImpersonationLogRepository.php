<?php
namespace TT\Modules\Authorization\Impersonation;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ImpersonationLogRepository (#2861) — read side for `tt_impersonation_log`.
 *
 * The writer, the table and the orphan-closing cron have all shipped since
 * migration 0056; nothing could read any of it back. Impersonation lets a
 * staff member see a minor's full record — medical notes, safeguarding
 * entries, family situation — and the audit trail is the entire control
 * that makes that acceptable. A trail nobody can read is not a control,
 * it is a table.
 *
 * Reads are club-scoped like every other repository here, and the query
 * resolves actor and target display names so the caller never has to.
 */
final class ImpersonationLogRepository {

    /**
     * One page of sessions, newest first.
     *
     * @param array{actor_user_id?:int, target_user_id?:int, date_from?:string, date_to?:string, active_only?:bool, limit?:int, offset?:int} $filters
     * @return list<array<string,mixed>>
     */
    public function recent( array $filters = [] ): array {
        global $wpdb;

        $limit  = max( 1, min( 200, (int) ( $filters['limit'] ?? 50 ) ) );
        $offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

        [ $where, $params ] = self::whereClause( $filters );

        $sql = "SELECT l.id, l.actor_user_id, l.target_user_id, l.started_at, l.ended_at,
                       l.end_reason, l.actor_ip, l.actor_user_agent, l.reason
                  FROM {$wpdb->prefix}tt_impersonation_log l
                 WHERE {$where}
                 ORDER BY l.started_at DESC, l.id DESC
                 LIMIT %d OFFSET %d";

        $rows = $wpdb->get_results(
            $wpdb->prepare( $sql, array_merge( $params, [ $limit, $offset ] ) ),
            ARRAY_A
        );

        $out = [];
        foreach ( is_array( $rows ) ? $rows : [] as $row ) {
            $out[] = self::decorate( $row );
        }
        return $out;
    }

    /**
     * How many sessions match, for pagination.
     *
     * @param array<string,mixed> $filters
     */
    public function count( array $filters = [] ): int {
        global $wpdb;

        [ $where, $params ] = self::whereClause( $filters );

        $sql = "SELECT COUNT(*) FROM {$wpdb->prefix}tt_impersonation_log l WHERE {$where}";

        return (int) $wpdb->get_var(
            $params === [] ? $sql : $wpdb->prepare( $sql, $params )
        );
    }

    /**
     * Add the human-readable names the caller would otherwise look up.
     *
     * A deleted WP user leaves the row intact and the id visible — this is
     * an audit trail, so a session must not become unattributable just
     * because the account has since gone.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function decorate( array $row ): array {
        $row['actor_user_id']  = (int) $row['actor_user_id'];
        $row['target_user_id'] = (int) $row['target_user_id'];
        $row['actor_name']     = self::displayName( $row['actor_user_id'] );
        $row['target_name']    = self::displayName( $row['target_user_id'] );
        $row['is_active']      = empty( $row['ended_at'] );
        return $row;
    }

    private static function displayName( int $user_id ): string {
        if ( $user_id <= 0 ) return '';
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            /* translators: %d: the WordPress user id of a since-deleted account */
            return sprintf( __( 'Deleted user #%d', 'talenttrack' ), $user_id );
        }
        return (string) $user->display_name;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private static function whereClause( array $filters ): array {
        $where  = [ 'l.club_id = %d' ];
        $params = [ CurrentClub::id() ];

        if ( ! empty( $filters['actor_user_id'] ) ) {
            $where[]  = 'l.actor_user_id = %d';
            $params[] = (int) $filters['actor_user_id'];
        }
        if ( ! empty( $filters['target_user_id'] ) ) {
            $where[]  = 'l.target_user_id = %d';
            $params[] = (int) $filters['target_user_id'];
        }
        if ( ! empty( $filters['date_from'] ) ) {
            $where[]  = 'l.started_at >= %s';
            $params[] = (string) $filters['date_from'] . ' 00:00:00';
        }
        if ( ! empty( $filters['date_to'] ) ) {
            $where[]  = 'l.started_at <= %s';
            $params[] = (string) $filters['date_to'] . ' 23:59:59';
        }
        if ( ! empty( $filters['active_only'] ) ) {
            $where[] = 'l.ended_at IS NULL';
        }

        return [ implode( ' AND ', $where ), $params ];
    }
}
