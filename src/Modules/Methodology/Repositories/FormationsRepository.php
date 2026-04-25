<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * FormationsRepository — data access for `tt_formations` and the
 * paired `tt_formation_positions` table.
 *
 * Formations are typically read by id (for the diagram component) or
 * listed all-at-once (the methodology browser shows the small set of
 * available formations as cards). Position cards always travel with
 * their parent formation, so `findWithPositions()` is the common path.
 */
class FormationsRepository {

    private function formationsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_formations';
    }

    private function positionsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_formation_positions';
    }

    /** @return object[] */
    public function listAll( bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->formationsTable();
        $where = $include_archived ? '' : ' WHERE archived_at IS NULL';
        return (array) $wpdb->get_results( "SELECT * FROM {$t}{$where} ORDER BY is_shipped DESC, slug ASC" );
    }

    public function find( int $id ): ?object {
        global $wpdb;
        $t = $this->formationsTable();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    public function findBySlug( string $slug ): ?object {
        global $wpdb;
        $t = $this->formationsTable();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE slug = %s LIMIT 1", $slug ) );
        return $row ?: null;
    }

    /**
     * @return array{formation:object, positions:array<int,object>}|null
     */
    public function findWithPositions( int $formation_id ): ?array {
        $formation = $this->find( $formation_id );
        if ( ! $formation ) return null;
        return [
            'formation' => $formation,
            'positions' => $this->positionsFor( $formation_id ),
        ];
    }

    /** @return object[] */
    public function positionsFor( int $formation_id, bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->positionsTable();
        $where = $include_archived ? '' : ' AND archived_at IS NULL';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE formation_id = %d{$where} ORDER BY sort_order ASC, jersey_number ASC",
            $formation_id
        ) );
    }

    /** @param array<string,mixed> $data */
    public function createFormation( array $data ): int {
        global $wpdb;
        $row = $this->normalizeFormation( $data, true );
        $wpdb->insert( $this->formationsTable(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function updateFormation( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalizeFormation( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->formationsTable(), $row, [ 'id' => $id ] ) !== false;
    }

    public function archiveFormation( int $id ): bool {
        global $wpdb;
        return $wpdb->update(
            $this->formationsTable(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        ) !== false;
    }

    /** @param array<string,mixed> $data */
    public function createPosition( array $data ): int {
        global $wpdb;
        $row = $this->normalizePosition( $data, true );
        $wpdb->insert( $this->positionsTable(), $row );
        return (int) $wpdb->insert_id;
    }

    /** @param array<string,mixed> $data */
    public function updatePosition( int $id, array $data ): bool {
        global $wpdb;
        $row = $this->normalizePosition( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update( $this->positionsTable(), $row, [ 'id' => $id ] ) !== false;
    }

    public function archivePosition( int $id ): bool {
        global $wpdb;
        return $wpdb->update(
            $this->positionsTable(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id ]
        ) !== false;
    }

    public function findPosition( int $id ): ?object {
        global $wpdb;
        $t = $this->positionsTable();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
        return $row ?: null;
    }

    /**
     * Clone a shipped position into a club-authored copy.
     */
    public function clonePosition( int $id ): int {
        $source = $this->findPosition( $id );
        if ( ! $source ) return 0;
        global $wpdb;
        $insert = (array) $source;
        unset( $insert['id'], $insert['created_at'], $insert['updated_at'] );
        $insert['is_shipped']     = 0;
        $insert['cloned_from_id'] = (int) $id;
        $wpdb->insert( $this->positionsTable(), $insert );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizeFormation( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['slug']       = isset( $data['slug'] ) ? sanitize_text_field( (string) $data['slug'] ) : '';
            $out['is_shipped'] = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'slug', $data ) ) {
                $out['slug'] = sanitize_text_field( (string) $data['slug'] );
            }
        }
        foreach ( [ 'name_json', 'description_json', 'diagram_data_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalizePosition( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['formation_id']  = isset( $data['formation_id'] )  ? (int) $data['formation_id'] : 0;
            $out['jersey_number'] = isset( $data['jersey_number'] ) ? max( 1, min( 11, (int) $data['jersey_number'] ) ) : 1;
            $out['sort_order']    = isset( $data['sort_order'] )    ? (int) $data['sort_order'] : 0;
            $out['is_shipped']    = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'jersey_number', $data ) ) $out['jersey_number'] = max( 1, min( 11, (int) $data['jersey_number'] ) );
            if ( array_key_exists( 'sort_order',    $data ) ) $out['sort_order']    = (int) $data['sort_order'];
        }
        foreach ( [ 'short_name_json', 'long_name_json', 'attacking_tasks_json', 'defending_tasks_json' ] as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
