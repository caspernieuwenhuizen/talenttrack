<?php
namespace TT\Modules\Analytics\Reports;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * SavedFiltersRepository (#2385) — personal named filter presets for the
 * standard reports' shared filter bar.
 *
 * Every query is scoped to the current club AND the owning user: presets
 * are personal, never shared, so a user only ever sees or mutates their
 * own rows. The `filters_json` payload is opaque here — the caller decides
 * what to store (a period key, an activity-type key, a resolved from/to
 * range) and how to re-apply it.
 */
class SavedFiltersRepository {

    private \wpdb $wpdb;
    private string $table;

    public function __construct() {
        global $wpdb;
        $this->wpdb  = $wpdb;
        $this->table = $wpdb->prefix . 'tt_saved_filters';
    }

    /**
     * A user's presets for one report, newest first.
     *
     * @return object[]
     */
    public function listForUser( int $user_id, string $report_key ): array {
        if ( $user_id <= 0 || $report_key === '' ) return [];
        $rows = $this->wpdb->get_results( $this->wpdb->prepare(
            "SELECT id, uuid, name, filters_json, created_at
               FROM {$this->table}
              WHERE club_id = %d AND user_id = %d AND report_key = %s
              ORDER BY name ASC, id ASC",
            CurrentClub::id(), $user_id, $report_key
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Persist a preset. Returns the stored row, or null on failure.
     *
     * @param array<string,scalar> $filters
     */
    public function create( int $user_id, string $report_key, string $name, array $filters ): ?object {
        $name = trim( $name );
        if ( $user_id <= 0 || $report_key === '' || $name === '' ) return null;

        $uuid = function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : self::fallbackUuid();
        $ok = $this->wpdb->insert( $this->table, [
            'club_id'      => CurrentClub::id(),
            'uuid'         => $uuid,
            'user_id'      => $user_id,
            'report_key'   => $report_key,
            'name'         => $name,
            'filters_json' => wp_json_encode( $filters ),
        ] );
        if ( ! $ok ) return null;

        $id = (int) $this->wpdb->insert_id;
        return $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT id, uuid, name, filters_json, created_at FROM {$this->table} WHERE id = %d",
            $id
        ) );
    }

    /** Delete a preset the user owns. Returns true when a row was removed. */
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
