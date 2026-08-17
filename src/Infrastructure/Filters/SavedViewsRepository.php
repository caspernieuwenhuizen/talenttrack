<?php
namespace TT\Infrastructure\Filters;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SavedViewsRepository (#2448) — personal named filter presets for any view
 * that renders the shared FilterBar.
 *
 * Promoted from `Modules\Analytics\Reports\SavedFiltersRepository` (#2385),
 * which was reachable only from inside the Analytics module and so could not
 * serve the list views. Behaviour is unchanged; the `report_key` column is
 * now `view_key` because the presets are no longer reports-only.
 *
 * Every query is scoped to the current club AND the owning user: views are
 * personal, never shared, so a user only ever sees or mutates their own rows.
 * The `filters_json` payload is opaque here — the caller decides what to
 * store and how to re-apply it.
 */
class SavedViewsRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_saved_filters';
    }

    /**
     * A user's saved views for one surface, ordered by name.
     *
     * @return object[]
     */
    public function listForUser( int $user_id, string $view_key ): array {
        if ( $user_id <= 0 || $view_key === '' ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT id, uuid, name, filters_json, is_default, created_at
               FROM {$this->table}
              WHERE club_id = %d AND user_id = %d AND view_key = %s
              ORDER BY name ASC, id ASC",
            CurrentClub::id(), $user_id, $view_key
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /** One row the user owns, or null. */
    public function find( int $id, int $user_id ): ?object {
        if ( $id <= 0 || $user_id <= 0 ) return null;
        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id, uuid, view_key, name, filters_json, is_default, created_at
               FROM {$this->table}
              WHERE id = %d AND user_id = %d AND club_id = %d",
            $id, $user_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Persist a saved view. Returns the stored row, or null on failure.
     *
     * @param array<string,scalar> $filters
     */
    public function create( int $user_id, string $view_key, string $name, array $filters ): ?object {
        $name = trim( $name );
        if ( $user_id <= 0 || $view_key === '' || $name === '' ) return null;

        $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallbackUuid();
        $ok = $this->wpdb->insert( $this->table, [
            'club_id'      => CurrentClub::id(),
            'uuid'         => $uuid,
            'user_id'      => $user_id,
            'view_key'     => $view_key,
            'name'         => $name,
            'filters_json' => wp_json_encode( $filters ),
        ] );
        if ( ! $ok ) return null;

        return $this->find( (int) $this->wpdb->insert_id, $user_id );
    }

    /** Delete a saved view the user owns. Returns true when a row was removed. */
    public function delete( int $id, int $user_id ): bool {
        if ( $id <= 0 || $user_id <= 0 ) return false;
        $deleted = $this->wpdb->delete( $this->table, [
            'id'      => $id,
            'user_id' => $user_id,
            'club_id' => CurrentClub::id(),
        ] );
        return (int) $deleted > 0;
    }

    private static function fallbackUuid(): string {
        // Deterministic-enough fallback for the (rare) case wp_generate_uuid4
        // is unavailable; the column only needs uniqueness.
        return sprintf(
            '%04x%04x-%04x-4%03x-%04x-%04x%04x%04x',
            wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ),
            wp_rand( 0, 0xffff ), wp_rand( 0, 0x0fff ),
            wp_rand( 0, 0x3fff ) | 0x8000,
            wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff ), wp_rand( 0, 0xffff )
        );
    }
}
