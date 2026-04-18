<?php
namespace TT\Infrastructure\Audit;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\FeatureToggles\FeatureToggleService;
use TT\Infrastructure\Logging\Logger;

/**
 * AuditService — writes entries to the tt_audit_log table.
 *
 * Silently no-ops if the "audit_log" feature toggle is disabled.
 *
 * Actions are free-form strings by convention: "{entity}.{verb}" where
 * verb ∈ { created, updated, deleted, login, logout, ... }.
 * Examples:  player.created, evaluation.deleted, config.changed
 *
 * Payload is any JSON-serialisable context (before/after values, field
 * lists, etc.) and is stored as JSON text.
 */
class AuditService {

    /** @var FeatureToggleService|null */
    private $toggles;

    /** @var Logger|null */
    private $logger;

    public function __construct( ?FeatureToggleService $toggles = null, ?Logger $logger = null ) {
        $this->toggles = $toggles;
        $this->logger  = $logger;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function record( string $action, string $entity_type = '', int $entity_id = 0, array $payload = [] ): void {
        if ( $this->toggles && ! $this->toggles->isEnabled( 'audit_log' ) ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        // Guard: table may not exist yet on very first activation before migrations run.
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return;
        }

        $inserted = $wpdb->insert( $table, [
            'user_id'     => get_current_user_id(),
            'action'      => substr( $action, 0, 100 ),
            'entity_type' => substr( $entity_type, 0, 64 ),
            'entity_id'   => $entity_id,
            'payload'     => ! empty( $payload ) ? (string) wp_json_encode( $payload ) : '',
            'ip_address'  => self::currentIp(),
            'created_at'  => current_time( 'mysql' ),
        ]);

        if ( $inserted === false && $this->logger ) {
            $this->logger->warning( 'Audit log insert failed', [
                'action' => $action,
                'db_error' => $wpdb->last_error,
            ]);
        }
    }

    /**
     * Retrieve recent audit entries, most recent first.
     *
     * @param int                       $limit
     * @param array<string, string|int> $filters Optional: action, entity_type, user_id
     * @return object[]
     */
    public function recent( int $limit = 50, array $filters = [] ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_audit_log';

        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
        if ( ! $exists ) {
            return [];
        }

        $where  = '1=1';
        $params = [];
        if ( ! empty( $filters['action'] ) ) {
            $where   .= ' AND action = %s';
            $params[] = (string) $filters['action'];
        }
        if ( ! empty( $filters['entity_type'] ) ) {
            $where   .= ' AND entity_type = %s';
            $params[] = (string) $filters['entity_type'];
        }
        if ( ! empty( $filters['user_id'] ) ) {
            $where   .= ' AND user_id = %d';
            $params[] = (int) $filters['user_id'];
        }

        $params[] = max( 1, $limit );
        $sql = "SELECT a.*, u.display_name AS user_name FROM $table a LEFT JOIN {$wpdb->users} u ON a.user_id = u.ID WHERE $where ORDER BY a.created_at DESC LIMIT %d";

        return $wpdb->get_results( $wpdb->prepare( $sql, ...$params ) );
    }

    private static function currentIp(): string {
        $candidates = [ 'HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR' ];
        foreach ( $candidates as $key ) {
            if ( ! empty( $_SERVER[ $key ] ) ) {
                $ip = explode( ',', (string) $_SERVER[ $key ] )[0];
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '';
    }
}
