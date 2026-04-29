<?php
namespace TT\Modules\StaffDevelopment\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

class StaffCertificationsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_staff_certifications';
    }

    public function find( int $id ): ?object {
        if ( $id <= 0 ) return null;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) ) ?: null;
    }

    /** @return object[] */
    public function listForPerson( int $person_id ): array {
        if ( $person_id <= 0 ) return [];
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE person_id = %d AND club_id = %d AND archived_at IS NULL
              ORDER BY (expires_on IS NULL), expires_on ASC, id DESC",
            $person_id, CurrentClub::id()
        ) ) ?: [];
    }

    /**
     * Return certifications expiring on or before $window_days days from
     * today. Used by the workflow template + HoD overview.
     *
     * @return object[]
     */
    public function listExpiringWithin( int $window_days ): array {
        $cutoff = gmdate( 'Y-m-d', time() + max( 0, $window_days ) * 86400 );
        return $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
              WHERE club_id = %d AND archived_at IS NULL
                AND expires_on IS NOT NULL AND expires_on <= %s
              ORDER BY expires_on ASC",
            CurrentClub::id(), $cutoff
        ) ) ?: [];
    }

    public function create( array $data ): int {
        $row = [
            'club_id'             => CurrentClub::id(),
            'person_id'           => (int) ( $data['person_id'] ?? 0 ),
            'cert_type_lookup_id' => (int) ( $data['cert_type_lookup_id'] ?? 0 ),
            'issuer'              => (string) ( $data['issuer'] ?? '' ),
            'issued_on'           => (string) ( $data['issued_on'] ?? gmdate( 'Y-m-d' ) ),
            'expires_on'          => $data['expires_on'] ?? null,
            'document_url'        => (string) ( $data['document_url'] ?? '' ),
        ];
        $ok = $this->wpdb->insert( $this->table, $row );
        return $ok ? (int) $this->wpdb->insert_id : 0;
    }

    public function update( int $id, array $data ): bool {
        if ( $id <= 0 ) return false;
        $allowed = [ 'cert_type_lookup_id', 'issuer', 'issued_on', 'expires_on', 'document_url', 'archived_at' ];
        $row = array_intersect_key( $data, array_flip( $allowed ) );
        if ( ! $row ) return false;
        return (bool) $this->wpdb->update(
            $this->table,
            $row,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
    }

    public function archive( int $id ): bool {
        return $this->update( $id, [ 'archived_at' => current_time( 'mysql' ) ] );
    }
}
