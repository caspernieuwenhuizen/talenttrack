<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * InfluenceFactorsRepository — `tt_methodology_influence_factors`.
 *
 * Factoren van invloed: club vision, own vision, players, staff, team
 * dynamics, level of play, support. Each factor carries a description
 * and an optional `sub_factors_json` array of sub-cards (per-locale
 * pairs of {title, description}).
 */
final class InfluenceFactorsRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_influence_factors';
    }

    /** @return object[] */
    public function listForPrimer( int $primer_id, bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->table();
        $where = $include_archived ? '' : ' AND archived_at IS NULL';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE primer_id = %d AND club_id = %d{$where} ORDER BY sort_order ASC, id ASC",
            $primer_id, CurrentClub::id()
        ) );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->table();
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE id = %d AND club_id = %d",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** @param array<string,mixed> $data */
    public function create( array $data ): int {
        global $wpdb;
        $row = $this->normalize( $data, true );
        $row['club_id'] = CurrentClub::id();
        $wpdb->insert( $this->table(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalize( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->table(), $row, [ 'id' => $id, 'club_id' => CurrentClub::id() ] ) !== false;
    }

    public function archive( int $id ): bool {
        global $wpdb;
        return $wpdb->update( $this->table(), [ 'archived_at' => current_time( 'mysql', true ) ], [ 'id' => $id, 'club_id' => CurrentClub::id() ] ) !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['primer_id']  = isset( $data['primer_id'] ) ? (int) $data['primer_id'] : 0;
            $out['slug']       = isset( $data['slug'] ) ? sanitize_key( (string) $data['slug'] ) : '';
            $out['sort_order'] = isset( $data['sort_order'] ) ? (int) $data['sort_order'] : 0;
            $out['is_shipped'] = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'slug',       $data ) ) $out['slug']       = sanitize_key( (string) $data['slug'] );
            if ( array_key_exists( 'sort_order', $data ) ) $out['sort_order'] = (int) $data['sort_order'];
        }
        foreach ( [ 'title_json', 'description_json', 'sub_factors_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
