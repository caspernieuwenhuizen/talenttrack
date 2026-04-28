<?php
namespace TT\Modules\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ScoutReportsRepository — CRUD over `tt_player_reports`.
 *
 * #0014 Sprint 5. Persistence for scout reports across both access
 * paths (emailed link + assigned account). Other audiences stay
 * ephemeral; only scout reports persist.
 */
class ScoutReportsRepository {

    private string $table;

    public function __construct() {
        global $wpdb;
        $this->table = $wpdb->prefix . 'tt_player_reports';
    }

    /**
     * @return int|false New row id, or false on insert failure.
     */
    public function createEmailedLink(
        int $player_id,
        int $generated_by,
        ReportConfig $config,
        string $rendered_html,
        string $access_token,
        string $recipient_email,
        string $cover_message,
        \DateTimeImmutable $expires_at
    ) {
        global $wpdb;
        $ok = $wpdb->insert( $this->table, [
            'player_id'       => $player_id,
            'generated_by'    => $generated_by,
            'audience'        => 'scout_emailed_link',
            'config_json'     => (string) wp_json_encode( $config->toArray() ),
            'rendered_html'   => $rendered_html,
            'access_token'    => $access_token,
            'recipient_email' => $recipient_email,
            'cover_message'   => $cover_message !== '' ? $cover_message : null,
            'expires_at'      => $expires_at->format( 'Y-m-d H:i:s' ),
        ] );
        if ( $ok === false ) return false;
        return (int) $wpdb->insert_id;
    }

    /**
     * @return int|false New row id, or false on insert failure.
     */
    public function createAssignedAccountView(
        int $player_id,
        int $generated_by,
        int $scout_user_id,
        ReportConfig $config,
        string $rendered_html
    ) {
        global $wpdb;
        $ok = $wpdb->insert( $this->table, [
            'player_id'     => $player_id,
            'generated_by'  => $generated_by,
            'audience'      => 'scout_assigned_account',
            'config_json'   => (string) wp_json_encode( $config->toArray() ),
            'rendered_html' => $rendered_html,
            'scout_user_id' => $scout_user_id,
        ] );
        if ( $ok === false ) return false;
        return (int) $wpdb->insert_id;
    }

    public function findByToken( string $token ): ?object {
        global $wpdb;
        if ( $token === '' ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE access_token = %s LIMIT 1",
            $token
        ) );
        return $row ? (object) $row : null;
    }

    public function isAccessibleNow( object $row ): bool {
        if ( ! empty( $row->revoked_at ) ) return false;
        if ( ! empty( $row->expires_at ) ) {
            $expires = strtotime( (string) $row->expires_at );
            if ( $expires !== false && $expires < time() ) return false;
        }
        return true;
    }

    public function recordAccess( int $id ): void {
        global $wpdb;
        $now = current_time( 'mysql' );
        // Set first_accessed_at only on first visit; bump access_count
        // every time. Two queries to keep the conditional update cheap
        // and readable.
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table}
                SET first_accessed_at = COALESCE( first_accessed_at, %s ),
                    access_count = access_count + 1
              WHERE id = %d",
            $now,
            $id
        ) );
    }

    public function revoke( int $id ): bool {
        global $wpdb;
        $ok = $wpdb->update(
            $this->table,
            [ 'revoked_at' => current_time( 'mysql' ) ],
            [ 'id' => $id ]
        );
        return $ok !== false;
    }

    /**
     * @return array<int, object>
     */
    public function listForGenerator( int $generated_by, int $limit = 100 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE generated_by = %d
              ORDER BY created_at DESC
              LIMIT %d",
            $generated_by,
            max( 1, $limit )
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * @return array<int, object>
     */
    public function listAll( int $limit = 100 ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table}
              ORDER BY created_at DESC
              LIMIT %d",
            max( 1, $limit )
        ) );
        return is_array( $rows ) ? $rows : [];
    }
}
