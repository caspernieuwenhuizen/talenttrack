<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FrameworkPrimerRepository — `tt_methodology_framework_primers`.
 *
 * Single record per club_scope (NULL for the shipped TT default).
 * `activeForClub()` resolves the current club's primer with shipped
 * fallback, mirroring MethodologyVisionRepository's pattern.
 */
final class FrameworkPrimerRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodology_framework_primers';
    }

    public function activeForClub( ?string $club_scope = null ): ?object {
        global $wpdb;
        $t = $this->table();
        if ( $club_scope !== null && $club_scope !== '' ) {
            $row = $wpdb->get_row( $wpdb->prepare(
                "SELECT * FROM {$t} WHERE club_scope = %s AND club_id = %d AND archived_at IS NULL ORDER BY id DESC LIMIT 1",
                $club_scope, CurrentClub::id()
            ) );
            if ( $row ) return $row;
        }
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$t} WHERE club_scope IS NULL AND club_id = %d AND archived_at IS NULL ORDER BY is_shipped DESC, id DESC LIMIT 1",
            CurrentClub::id()
        ) );
        return $row ?: null;
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

    /** @return object[] */
    public function listAll( bool $include_archived = false ): array {
        global $wpdb;
        $t = $this->table();
        $where = $include_archived
            ? ' WHERE club_id = %d'
            : ' WHERE club_id = %d AND archived_at IS NULL';
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$t}{$where} ORDER BY is_shipped DESC, id ASC",
            CurrentClub::id()
        ) );
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
        return $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    public function cloneShipped( int $id, ?string $club_scope = null ): int {
        $source = $this->find( $id );
        if ( ! $source ) return 0;
        global $wpdb;
        $insert = (array) $source;
        unset( $insert['id'], $insert['created_at'], $insert['updated_at'] );
        $insert['is_shipped']     = 0;
        $insert['cloned_from_id'] = (int) $id;
        $insert['club_scope']     = $club_scope;
        $insert['club_id']        = CurrentClub::id();
        $wpdb->insert( $this->table(), $insert );
        return (int) $wpdb->insert_id;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];
        if ( $for_insert ) {
            $out['club_scope'] = isset( $data['club_scope'] ) && $data['club_scope'] !== '' ? sanitize_key( (string) $data['club_scope'] ) : null;
            $out['is_shipped'] = ! empty( $data['is_shipped'] ) ? 1 : 0;
        } else {
            if ( array_key_exists( 'club_scope', $data ) ) {
                $out['club_scope'] = $data['club_scope'] === '' || $data['club_scope'] === null ? null : sanitize_key( (string) $data['club_scope'] );
            }
        }
        $json_cols = [
            'title_json', 'tagline_json', 'intro_json',
            'voetbalmodel_intro_json', 'voetbalhandelingen_intro_json',
            'phases_intro_json', 'learning_goals_intro_json', 'influence_factors_intro_json',
            'reflection_json', 'future_json',
        ];
        foreach ( $json_cols as $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] ) ? (string) wp_json_encode( $data[ $col ] ) : (string) $data[ $col ];
            }
        }
        return $out;
    }
}
