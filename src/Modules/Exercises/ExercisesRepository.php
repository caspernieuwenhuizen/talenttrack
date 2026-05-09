<?php
namespace TT\Modules\Exercises;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ExercisesRepository (#0016 Sprint 1) — read + write API on
 * `tt_exercises`, `tt_exercise_categories`, `tt_exercise_principles`,
 * `tt_exercise_team_overrides`.
 *
 * Sprint 1 ships the foundational fetchers + create/update/archive
 * paths. Sprint 2 wires sessions to specific exercise versions; that
 * piece consumes `findById()` to pin against an immutable row id.
 *
 * Versioning model: every edit produces a new row with an
 * incremented `version`; the previous row's `superseded_by_id` is
 * set to the new row. Sessions that reference the old row continue
 * to render the same content even after a coach edits the exercise.
 *
 * Visibility model: an exercise's `visibility` is one of
 * `'club'` (default — every team in the club sees it),
 * `'team'` (only teams listed in `tt_exercise_team_overrides` with
 * `is_enabled=1`), `'private'` (only the author sees it). The
 * Sprint 2 picker consumes `listForTeam()` to apply the rules.
 *
 * All reads + writes scope to `CurrentClub::id()`.
 */
final class ExercisesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_exercises';
    }

    /**
     * @return list<object>
     */
    public function listCategories( bool $active_only = true ): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$wpdb->prefix}tt_exercise_categories
              WHERE club_id = %d
              ORDER BY sort_order ASC, label ASC",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    public function findById( int $id ): ?object {
        if ( $id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE id = %d AND club_id = %d",
            $id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    public function findByUuid( string $uuid ): ?object {
        $uuid = trim( $uuid );
        if ( $uuid === '' ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE uuid = %s AND club_id = %d",
            $uuid,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * List active exercises (not archived, not superseded). Use
     * `listForTeam()` when you need visibility rules applied.
     *
     * @return list<object>
     */
    public function listActive(): array {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE club_id = %d
                AND archived_at IS NULL
                AND superseded_by_id IS NULL
              ORDER BY name ASC",
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Apply visibility rules for a specific team:
     *   - 'club' visibility → always visible
     *   - 'team' visibility → only when an override row exists
     *     with `is_enabled = 1` for this team
     *   - 'private' visibility → only when the override row exists
     *     OR the calling user is the author
     *
     * Sprint 2's session-edit picker is the primary consumer.
     *
     * @return list<object>
     */
    public function listForTeam( int $team_id, ?int $current_user_id = null ): array {
        global $wpdb;
        $club = CurrentClub::id();
        $user = $current_user_id ?? (int) get_current_user_id();

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*,
                    o.is_enabled AS team_override_enabled
               FROM {$this->table()} e
          LEFT JOIN {$wpdb->prefix}tt_exercise_team_overrides o
                 ON o.exercise_id = e.id
                AND o.team_id = %d
                AND o.club_id = e.club_id
              WHERE e.club_id = %d
                AND e.archived_at IS NULL
                AND e.superseded_by_id IS NULL
              ORDER BY e.name ASC",
            $team_id,
            $club
        ) );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $row ) {
            $visibility = (string) ( $row->visibility ?? 'club' );
            $override   = isset( $row->team_override_enabled ) ? (int) $row->team_override_enabled : null;

            $visible = false;
            if ( $visibility === 'club' ) {
                // Default visible; team can opt out via override=0.
                $visible = ( $override === null ) || ( $override === 1 );
            } elseif ( $visibility === 'team' ) {
                // Default hidden; team opts in via override=1.
                $visible = ( $override === 1 );
            } elseif ( $visibility === 'private' ) {
                // Author always; explicit team opt-in otherwise.
                $author_match = ( (int) ( $row->author_user_id ?? 0 ) === $user );
                $visible      = $author_match || ( $override === 1 );
            }
            if ( $visible ) $out[] = $row;
        }
        return $out;
    }

    /**
     * @param array<string,mixed> $data
     * @return int New exercise id (0 on failure).
     */
    public function create( array $data ): int {
        global $wpdb;
        $clean = $this->sanitizePayload( $data, true );
        if ( empty( $clean['name'] ) ) return 0;
        $clean['uuid']           = $this->generateUuid();
        $clean['club_id']        = CurrentClub::id();
        $clean['version']        = 1;
        $clean['author_user_id'] = $clean['author_user_id'] ?? (int) get_current_user_id();
        $clean['created_at']     = current_time( 'mysql' );
        $clean['updated_at']     = $clean['created_at'];

        $ok = $wpdb->insert( $this->table(), $clean );
        if ( $ok === false ) return 0;
        return (int) $wpdb->insert_id;
    }

    /**
     * Edit an exercise — creates a new row at `version + 1` with the
     * patched fields, points the previous row's `superseded_by_id`
     * at the new id. Sessions that reference the old id keep their
     * historical rendering.
     *
     * @param array<string,mixed> $patch
     * @return int New row id (0 on failure or no change).
     */
    public function editAsNewVersion( int $id, array $patch ): int {
        $existing = $this->findById( $id );
        if ( ! $existing ) return 0;
        if ( (int) ( $existing->superseded_by_id ?? 0 ) > 0 ) return 0; // Already superseded.

        $clean = $this->sanitizePayload( $patch, false );
        if ( empty( $clean ) ) return 0;

        global $wpdb;

        // Build the new row from the existing snapshot + the patch.
        $new_row = [
            'uuid'             => $this->generateUuid(),
            'club_id'          => (int) ( $existing->club_id ?? CurrentClub::id() ),
            'name'             => (string) ( $clean['name'] ?? $existing->name ),
            'description'      => array_key_exists( 'description', $clean ) ? $clean['description'] : ( $existing->description ?? null ),
            'duration_minutes' => array_key_exists( 'duration_minutes', $clean ) ? (int) $clean['duration_minutes'] : (int) ( $existing->duration_minutes ?? 0 ),
            'category_id'      => array_key_exists( 'category_id', $clean ) ? $clean['category_id'] : ( $existing->category_id ?? null ),
            'diagram_url'      => array_key_exists( 'diagram_url', $clean ) ? $clean['diagram_url'] : ( $existing->diagram_url ?? null ),
            'author_user_id'   => (int) ( $existing->author_user_id ?? get_current_user_id() ),
            'visibility'       => array_key_exists( 'visibility', $clean ) ? (string) $clean['visibility'] : (string) ( $existing->visibility ?? 'club' ),
            'version'          => (int) ( $existing->version ?? 1 ) + 1,
            'created_at'       => current_time( 'mysql' ),
            'updated_at'       => current_time( 'mysql' ),
        ];
        $ok = $wpdb->insert( $this->table(), $new_row );
        if ( $ok === false ) return 0;
        $new_id = (int) $wpdb->insert_id;

        // Point the previous row at the new one.
        $wpdb->update(
            $this->table(),
            [ 'superseded_by_id' => $new_id, 'updated_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $new_id;
    }

    public function archive( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql' ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function sanitizePayload( array $data, bool $for_create ): array {
        $out = [];
        if ( isset( $data['name'] ) ) {
            $name = trim( sanitize_text_field( (string) $data['name'] ) );
            if ( $name !== '' ) $out['name'] = $name;
        }
        if ( array_key_exists( 'description', $data ) ) {
            $out['description'] = $data['description'] === null
                ? null
                : sanitize_textarea_field( (string) $data['description'] );
        }
        if ( array_key_exists( 'duration_minutes', $data ) ) {
            $out['duration_minutes'] = max( 0, min( 240, (int) $data['duration_minutes'] ) );
        }
        if ( array_key_exists( 'category_id', $data ) ) {
            $cat = (int) $data['category_id'];
            $out['category_id'] = $cat > 0 ? $cat : null;
        }
        if ( array_key_exists( 'diagram_url', $data ) ) {
            $url = $data['diagram_url'] === null ? null : esc_url_raw( (string) $data['diagram_url'] );
            $out['diagram_url'] = $url ?: null;
        }
        if ( array_key_exists( 'visibility', $data ) ) {
            $vis = (string) $data['visibility'];
            $out['visibility'] = in_array( $vis, [ 'club', 'team', 'private' ], true ) ? $vis : 'club';
        } elseif ( $for_create ) {
            $out['visibility'] = 'club';
        }
        if ( array_key_exists( 'author_user_id', $data ) ) {
            $out['author_user_id'] = max( 0, (int) $data['author_user_id'] );
        }
        return $out;
    }

    private function generateUuid(): string {
        if ( function_exists( 'wp_generate_uuid4' ) ) return (string) wp_generate_uuid4();
        $bytes    = random_bytes( 16 );
        $bytes[6] = chr( ord( $bytes[6] ) & 0x0F | 0x40 );
        $bytes[8] = chr( ord( $bytes[8] ) & 0x3F | 0x80 );
        return vsprintf(
            '%s%s-%s-%s-%s-%s%s%s',
            str_split( bin2hex( $bytes ), 4 )
        );
    }
}
