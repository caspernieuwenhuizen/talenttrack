<?php
namespace TT\Modules\CustomWidgets\Repository;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\CustomWidgets\Domain\CustomWidget;

/**
 * CustomWidgetRepository (#0078 Phase 2) — CRUD over `tt_custom_widgets`.
 *
 * Every read and write scopes to `CurrentClub::id()` so a future
 * second tenant can't see another club's widgets. `archived_at` is a
 * soft-delete tombstone; default reads filter on `archived_at IS NULL`
 * unless the caller asks for the archived tail.
 *
 * The repository does not validate definition shape — that's the
 * service layer's job. Repository accepts whatever the service hands
 * it and round-trips via `wp_json_encode()` / `json_decode()`.
 */
final class CustomWidgetRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_custom_widgets';
    }

    /**
     * @return CustomWidget[]
     */
    public function listForClub( bool $include_archived = false ): array {
        global $wpdb;
        $club = CurrentClub::id();

        if ( $include_archived ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . $this->table() . ' WHERE club_id = %d ORDER BY name ASC',
                    $club
                ),
                ARRAY_A
            );
        } else {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    'SELECT * FROM ' . $this->table() . ' WHERE club_id = %d AND archived_at IS NULL ORDER BY name ASC',
                    $club
                ),
                ARRAY_A
            );
        }

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[] = $this->hydrate( $row );
        }
        return $out;
    }

    public function findById( int $id ): ?CustomWidget {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE id = %d AND club_id = %d',
                $id,
                CurrentClub::id()
            ),
            ARRAY_A
        );
        return $row ? $this->hydrate( $row ) : null;
    }

    public function findByUuid( string $uuid ): ?CustomWidget {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . $this->table() . ' WHERE uuid = %s AND club_id = %d',
                $uuid,
                CurrentClub::id()
            ),
            ARRAY_A
        );
        return $row ? $this->hydrate( $row ) : null;
    }

    /**
     * @param array<string,mixed> $definition
     */
    public function create(
        string $name,
        string $data_source_id,
        string $chart_type,
        array $definition,
        ?int $user_id = null
    ): CustomWidget {
        global $wpdb;
        $now = current_time( 'mysql', true );
        $uuid = wp_generate_uuid4();

        $wpdb->insert(
            $this->table(),
            [
                'club_id'        => CurrentClub::id(),
                'uuid'           => $uuid,
                'name'           => $name,
                'data_source_id' => $data_source_id,
                'chart_type'     => $chart_type,
                'definition'     => (string) wp_json_encode( $definition ),
                'created_by'     => $user_id !== null ? (int) $user_id : null,
                'updated_by'     => $user_id !== null ? (int) $user_id : null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ],
            [ '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
        );

        return $this->findById( (int) $wpdb->insert_id );
    }

    /**
     * @param array<string,mixed> $definition
     */
    public function update(
        int $id,
        string $name,
        string $data_source_id,
        string $chart_type,
        array $definition,
        ?int $user_id = null
    ): ?CustomWidget {
        global $wpdb;
        $wpdb->update(
            $this->table(),
            [
                'name'           => $name,
                'data_source_id' => $data_source_id,
                'chart_type'     => $chart_type,
                'definition'     => (string) wp_json_encode( $definition ),
                'updated_by'     => $user_id !== null ? (int) $user_id : null,
                'updated_at'     => current_time( 'mysql', true ),
            ],
            [
                'id'      => $id,
                'club_id' => CurrentClub::id(),
            ],
            [ '%s', '%s', '%s', '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        return $this->findById( $id );
    }

    /**
     * Soft-delete: stamps `archived_at`. The row stays in the table so
     * an audit trail + accidental-delete recovery remain possible.
     */
    public function softDelete( int $id, ?int $user_id = null ): bool {
        global $wpdb;
        $rows = $wpdb->update(
            $this->table(),
            [
                'archived_at' => current_time( 'mysql', true ),
                'updated_by'  => $user_id !== null ? (int) $user_id : null,
                'updated_at'  => current_time( 'mysql', true ),
            ],
            [
                'id'      => $id,
                'club_id' => CurrentClub::id(),
            ],
            [ '%s', '%d', '%s' ],
            [ '%d', '%d' ]
        );
        return $rows > 0;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate( array $row ): CustomWidget {
        $definition = [];
        if ( isset( $row['definition'] ) && is_string( $row['definition'] ) && $row['definition'] !== '' ) {
            $decoded = json_decode( $row['definition'], true );
            if ( is_array( $decoded ) ) {
                $definition = $decoded;
            }
        }
        return new CustomWidget(
            (int) $row['id'],
            (int) ( $row['club_id'] ?? 1 ),
            (string) ( $row['uuid'] ?? '' ),
            (string) ( $row['name'] ?? '' ),
            (string) ( $row['data_source_id'] ?? '' ),
            (string) ( $row['chart_type'] ?? '' ),
            $definition,
            isset( $row['created_by'] ) ? (int) $row['created_by'] : null,
            isset( $row['updated_by'] ) ? (int) $row['updated_by'] : null,
            (string) ( $row['created_at'] ?? '' ),
            $row['updated_at'] ?? null,
            $row['archived_at'] ?? null
        );
    }
}
