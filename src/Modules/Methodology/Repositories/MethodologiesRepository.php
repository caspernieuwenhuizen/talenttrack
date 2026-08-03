<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MethodologiesRepository — data access for `tt_methodologies`, the
 * named, selectable methodology set introduced in #2317.
 *
 * A set groups every methodology entity (vision / formation /
 * principles / phases / …) so an install can run more than one and
 * choose which is active per team. This repository is the read side
 * used by ActiveMethodologyResolver and the manage surface (#2320);
 * full CRUD lands with the admin work.
 *
 * All reads are club-scoped and exclude archived sets.
 */
class MethodologiesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_methodologies';
    }

    /** Whether the table exists yet (graceful pre-migration behaviour). */
    public function tableReady(): bool {
        global $wpdb;
        $t = $this->table();
        return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $t ) ) === $t;
    }

    /** @return object[] Active (non-archived) sets for the current club. */
    public function allForClub(): array {
        global $wpdb;
        if ( ! $this->tableReady() ) return [];
        return (array) $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE club_id = %d AND archived_at IS NULL
              ORDER BY sort_order ASC, id ASC",
            CurrentClub::id()
        ) );
    }

    /** One set by id, club-scoped and not archived. */
    public function find( int $id ): ?object {
        global $wpdb;
        if ( $id <= 0 || ! $this->tableReady() ) return null;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE id = %d AND club_id = %d AND archived_at IS NULL",
            $id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /** True when the id names a usable set for the current club. */
    public function exists( int $id ): bool {
        return $this->find( $id ) !== null;
    }

    /**
     * The club's default set id (`is_default = 1`), falling back to the
     * lowest-id active set, or 0 when none exist / the table is absent.
     */
    public function defaultId(): int {
        global $wpdb;
        if ( ! $this->tableReady() ) return 0;

        $id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()}
              WHERE club_id = %d AND is_default = 1 AND archived_at IS NULL
              ORDER BY id ASC LIMIT 1",
            CurrentClub::id()
        ) );
        if ( $id > 0 ) return $id;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->table()}
              WHERE club_id = %d AND archived_at IS NULL
              ORDER BY id ASC LIMIT 1",
            CurrentClub::id()
        ) );
    }
}
