<?php
namespace TT\Modules\Methodology\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Methodology\ActiveMethodologyResolver;
use TT\Modules\Methodology\Helpers\MultilingualField;

/**
 * MethodologiesRepository — data access for `tt_methodologies`, the
 * named, selectable methodology set introduced in #2317.
 *
 * A set groups every methodology entity (vision / formation /
 * principles / phases / …) so an install can run more than one and
 * choose which is active per team. This repository is the read side
 * used by ActiveMethodologyResolver and the manage surface, plus the
 * full CRUD (#2320) the admin tab + REST controller consume.
 *
 * All reads and writes are club-scoped and exclude archived sets.
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

    // ── write side (#2320) ───────────────────────────────────────────

    /**
     * Create a club-authored methodology set. Returns the new id, or 0
     * on failure / when the table is absent. name_json / description_json
     * accept either a `{nl,en}` array (encoded here) or a pre-encoded
     * JSON string; the slug is derived from the NL name when blank.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;
        if ( ! $this->tableReady() ) return 0;

        $row = $this->normalize( $data, true );
        $row['club_id'] = CurrentClub::id();
        if ( empty( $row['uuid'] ) ) {
            $row['uuid'] = wp_generate_uuid4();
        }
        $wpdb->insert( $this->table(), $row );
        return (int) $wpdb->insert_id;
    }

    /**
     * Update a club-authored set. Only the supplied fields are written.
     *
     * @param array<string,mixed> $data
     */
    public function update( int $id, array $data ): bool {
        global $wpdb;
        if ( $id <= 0 || ! $this->tableReady() ) return false;

        $row = $this->normalize( $data, false );
        if ( empty( $row ) ) return true;
        return $wpdb->update(
            $this->table(),
            $row,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Make a set the club's default: clear `is_default` on every other
     * set, set it on this one, and persist it as the install-wide active
     * methodology (ActiveMethodologyResolver). Refuses an unknown or
     * archived set.
     */
    public function setDefault( int $id ): bool {
        global $wpdb;
        if ( ! $this->exists( $id ) ) return false;

        $club = CurrentClub::id();
        $wpdb->update(
            $this->table(),
            [ 'is_default' => 0 ],
            [ 'club_id' => $club ]
        );
        $ok = $wpdb->update(
            $this->table(),
            [ 'is_default' => 1 ],
            [ 'id' => $id, 'club_id' => $club ]
        ) !== false;
        if ( $ok ) {
            ActiveMethodologyResolver::setInstallDefault( $id );
        }
        return $ok;
    }

    /**
     * Soft-archive a set. Refuses to archive a shipped set (read-only
     * reference content) and refuses to archive the last remaining
     * active set, so an install never ends up with zero methodologies.
     */
    public function archive( int $id ): bool {
        global $wpdb;
        $row = $this->find( $id );
        if ( ! $row || ! empty( $row->is_shipped ) ) return false;

        $remaining = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()}
              WHERE club_id = %d AND archived_at IS NULL",
            CurrentClub::id()
        ) );
        if ( $remaining <= 1 ) return false;

        return $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Shape a write payload for `tt_methodologies`. name / description
     * arrive as `{nl,en}` arrays (or `*_json` pre-encoded strings) and
     * are normalized to JSON; the slug falls back to the NL name.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalize( array $data, bool $for_insert ): array {
        $out = [];

        foreach ( [ 'name' => 'name_json', 'description' => 'description_json' ] as $field => $col ) {
            if ( array_key_exists( $col, $data ) ) {
                $out[ $col ] = is_array( $data[ $col ] )
                    ? MultilingualField::encode( $data[ $col ] )
                    : (string) $data[ $col ];
            } elseif ( array_key_exists( $field, $data ) && is_array( $data[ $field ] ) ) {
                $out[ $col ] = MultilingualField::encode( $data[ $field ] );
            }
        }

        // Slug: explicit value wins; otherwise derive from the NL name
        // (create only, so an edit that omits the slug keeps the old one).
        $slug = isset( $data['slug'] ) ? sanitize_title( (string) $data['slug'] ) : '';
        if ( $slug === '' && $for_insert ) {
            $nl = '';
            if ( isset( $out['name_json'] ) ) {
                $decoded = MultilingualField::decode( $out['name_json'] ) ?: [];
                $nl = (string) ( $decoded['nl'] ?? ( $decoded['en'] ?? '' ) );
            }
            $slug = sanitize_title( $nl );
        }
        if ( $slug !== '' ) {
            $out['slug'] = $slug;
        }

        if ( array_key_exists( 'sort_order', $data ) ) {
            $out['sort_order'] = (int) $data['sort_order'];
        }
        if ( $for_insert ) {
            $out['is_shipped'] = ! empty( $data['is_shipped'] ) ? 1 : 0;
            $out['is_default'] = ! empty( $data['is_default'] ) ? 1 : 0;
            if ( ! isset( $out['sort_order'] ) ) $out['sort_order'] = 0;
        }

        return $out;
    }
}
