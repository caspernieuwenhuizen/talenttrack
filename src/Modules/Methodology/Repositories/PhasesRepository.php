<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PhasesRepository — `tt_methodology_phases`.
 *
 * Holds the four phases of attacking and the four phases of defending
 * (eight rows per primer). Side is 'attacking' | 'defending'.
 */
final class PhasesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_phases';
    }

    /** @return object[] */
    public function listForPrimer( int $primer_id, bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->table();
        $where = $include_archived ? '' : ' AND archived_at IS NULL';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE primer_id = %d{$where} ORDER BY side ASC, phase_number ASC",
            $primer_id
        ) );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = $this->normalize( $data, true );
        $wpdb->insert( $this->table(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalize( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->table(), $row, [ 'id' => $id ] ) !== false;
    }

    public function archive( int $id ): bool {
        global $wpdb;
        return $wpdb->update( $this->table(), [ 'archived_at' => current_time( 'mysql', true ) ], [ 'id' => $id ] ) !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['primer_id']    = isset( $data['primer_id'] ) ? (int) $data['primer_id'] : 0;
            $out['side']         = isset( $data['side'] ) ? sanitize_key( (string) $data['side'] ) : '';
            $out['phase_number'] = isset( $data['phase_number'] ) ? max( 1, min( 4, (int) $data['phase_number'] ) ) : 1;
            $out['sort_order']   = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
            $out['is_shipped']   = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'side', $data ) )         $out['side']         = sanitize_key( (string) $data['side'] );
            if ( array_key_exists( 'phase_number', $data ) ) $out['phase_number'] = max( 1, min( 4, (int) $data['phase_number'] ) );
            if ( array_key_exists( 'sort_order', $data ) )   $out['sort_order']   = (int) $data['sort_order'];
        }
        foreach ( [ 'title_json', 'goal_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
