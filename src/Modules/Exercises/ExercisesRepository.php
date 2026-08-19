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
     * The principles an exercise trains (#2497).
     *
     * This link is what the generator ranks candidates by and what wave 7
     * computes per-player training exposure through — an exercise with no
     * principle is invisible to both. Migration 0215 seeds it from the
     * tactical theme where one exists; everything else is tagged here.
     *
     * @return list<int>
     */
    public function principleIdsFor( int $exercise_id ): array {
        if ( $exercise_id <= 0 ) return [];
        global $wpdb;

        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT principle_id FROM {$wpdb->prefix}tt_exercise_principles
              WHERE exercise_id = %d AND club_id = %d
              ORDER BY principle_id ASC",
            $exercise_id,
            CurrentClub::id()
        ) );

        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    /**
     * Replace an exercise's principle links wholesale. The caller hands
     * over the desired set rather than a diff, which is what makes the
     * form's multi-select a single save.
     *
     * @param list<int> $principle_ids
     */
    public function setPrincipleIds( int $exercise_id, array $principle_ids ): bool {
        if ( $exercise_id <= 0 ) return false;
        global $wpdb;

        $club  = CurrentClub::id();
        $table = $wpdb->prefix . 'tt_exercise_principles';

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table} WHERE exercise_id = %d AND club_id = %d",
            $exercise_id,
            $club
        ) );
        if ( $deleted === false ) return false;

        $now = current_time( 'mysql' );
        foreach ( array_unique( array_filter( array_map( 'intval', $principle_ids ) ) ) as $principle_id ) {
            $wpdb->insert( $table, [
                'club_id'      => $club,
                'exercise_id'  => $exercise_id,
                'principle_id' => $principle_id,
                'created_at'   => $now,
            ] );
        }

        return true;
    }

    /**
     * The methodology principles an academy can tag against, newest
     * methodology first. Small enough to load whole for a select.
     *
     * @return list<object>
     */
    public function listPrinciples(): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, code, methodology_id, team_function_key, team_task_key, title_json
               FROM {$wpdb->prefix}tt_principles
              WHERE archived_at IS NULL
                AND ( club_id = %d OR club_id IS NULL )
              ORDER BY methodology_id ASC, code ASC",
            CurrentClub::id()
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Browse the library for the management surface (#2495).
     *
     * Distinct from `listForTeam()`, which answers "what may this team
     * pick from" and applies the per-team override rules. This answers
     * "what is in the library", and is what the list screen and its
     * search / filters / pager read.
     *
     * Superseded rows are excluded throughout: editing an exercise writes
     * a new version, and the library shows the current one.
     *
     * @param array{search?:string, category_id?:int, principle_id?:int, tactical_theme?:string, intensity_band?:int, players?:int, visibility?:string, source?:string, status?:string, orderby?:string, order?:string, limit?:int, offset?:int} $args
     * @return list<object>
     */
    public function browse( array $args = [] ): array {
        global $wpdb;

        [ $join, $where, $params ] = $this->browseClause( $args );

        $sql = "SELECT e.* FROM {$this->table()} e {$join} {$where} "
             . $this->browseOrder( $args );

        $limit    = isset( $args['limit'] ) ? max( 1, min( 200, (int) $args['limit'] ) ) : 25;
        $sql     .= ' LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Unpaged count for the same filters. Shares `browseClause()` with
     * `browse()` so the pager can never promise rows the list will not
     * show.
     *
     * @param array<string,mixed> $args
     */
    public function countBrowse( array $args = [] ): int {
        global $wpdb;

        [ $join, $where, $params ] = $this->browseClause( $args );

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(DISTINCT e.id) FROM {$this->table()} e {$join} {$where}",
            $params
        ) );
    }

    /**
     * @param array<string,mixed> $args
     * @return array{0:string, 1:string, 2:list<mixed>}
     */
    private function browseClause( array $args ): array {
        global $wpdb;

        $join   = '';
        $where  = ' WHERE e.club_id = %d AND e.superseded_by_id IS NULL';
        $params = [ CurrentClub::id() ];

        $status = (string) ( $args['status'] ?? 'active' );
        if ( $status === 'archived' ) {
            $where .= ' AND e.archived_at IS NOT NULL';
        } elseif ( $status !== 'all' ) {
            $where .= ' AND e.archived_at IS NULL';
        }

        if ( ! empty( $args['search'] ) ) {
            $like     = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
            $where   .= ' AND (e.name LIKE %s OR e.description LIKE %s OR e.code LIKE %s)';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
        if ( ! empty( $args['category_id'] ) ) {
            $where   .= ' AND e.category_id = %d';
            $params[] = (int) $args['category_id'];
        }
        if ( ! empty( $args['tactical_theme'] ) ) {
            $where   .= ' AND e.tactical_theme = %s';
            $params[] = (string) $args['tactical_theme'];
        }
        if ( ! empty( $args['intensity_band'] ) ) {
            $where   .= ' AND e.intensity_band = %d';
            $params[] = (int) $args['intensity_band'];
        }
        if ( ! empty( $args['visibility'] ) ) {
            $where   .= ' AND e.visibility = %s';
            $params[] = (string) $args['visibility'];
        }
        if ( ! empty( $args['source'] ) ) {
            $where   .= ' AND e.source = %s';
            $params[] = (string) $args['source'];
        }
        // "Fits a group of N" — an exercise with no player range is
        // unconstrained and stays in the results rather than dropping out.
        if ( ! empty( $args['players'] ) ) {
            $where   .= ' AND (e.players_min IS NULL OR e.players_min <= %d)'
                      . ' AND (e.players_max IS NULL OR e.players_max >= %d)';
            $params[] = (int) $args['players'];
            $params[] = (int) $args['players'];
        }
        if ( ! empty( $args['principle_id'] ) ) {
            $join    .= " INNER JOIN {$wpdb->prefix}tt_exercise_principles ep"
                      . ' ON ep.exercise_id = e.id AND ep.club_id = e.club_id';
            $where   .= ' AND ep.principle_id = %d';
            $params[] = (int) $args['principle_id'];
        }

        return [ $join, $where, $params ];
    }

    /**
     * ORDER BY from an allowlist, so a caller cannot sort by an arbitrary
     * string.
     *
     * @param array<string,mixed> $args
     */
    private function browseOrder( array $args ): string {
        $allowed = [
            'name'             => 'e.name',
            'duration_minutes' => 'e.duration_minutes',
            'intensity_band'   => 'e.intensity_band',
            'created_at'       => 'e.created_at',
            'updated_at'       => 'e.updated_at',
        ];

        $column = $allowed[ (string) ( $args['orderby'] ?? '' ) ] ?? null;
        if ( $column === null ) return 'ORDER BY e.name ASC, e.id ASC';

        $direction = strtolower( (string) ( $args['order'] ?? 'asc' ) ) === 'desc' ? 'DESC' : 'ASC';

        return "ORDER BY {$column} {$direction}, e.id ASC";
    }

    /**
     * Exercises a coach has authored at team visibility, awaiting a
     * head-of-development decision (epic #2493 D9).
     *
     * Team-scoped rows are usable in their author's own plans from the
     * moment they are saved — nothing waits on approval. Promotion only
     * decides whether the rest of the club, and the generator's club-wide
     * candidate pool, get them too.
     *
     * @return list<object>
     */
    public function promotionQueue( int $limit = 50 ): array {
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT e.*, COUNT(DISTINCT b.plan_id) AS used_in_plans
               FROM {$this->table()} e
          LEFT JOIN {$wpdb->prefix}tt_training_plan_blocks b
                 ON b.exercise_id = e.id AND b.club_id = e.club_id
              WHERE e.club_id = %d
                AND e.visibility = 'team'
                AND e.archived_at IS NULL
                AND e.superseded_by_id IS NULL
           GROUP BY e.id
           ORDER BY e.created_at DESC
              LIMIT %d",
            CurrentClub::id(),
            max( 1, min( 200, $limit ) )
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    public function countPromotionQueue(): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table()}
              WHERE club_id = %d AND visibility = 'team'
                AND archived_at IS NULL AND superseded_by_id IS NULL",
            CurrentClub::id()
        ) );
    }

    /**
     * Make a team-scoped exercise club-wide.
     *
     * Deliberately a direct visibility flip rather than an edit-as-new-
     * version: promotion changes who can see the exercise, not what it
     * says, and versioning it would leave the team pointing at a stale
     * copy of its own drill.
     */
    public function promote( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;

        $ok = $wpdb->update(
            $this->table(),
            [ 'visibility' => 'club' ],
            [ 'id' => $id, 'club_id' => CurrentClub::id(), 'visibility' => 'team' ]
        );

        return $ok !== false && $ok > 0;
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

        // Merged VCT attributes (migration 0212). Every one is optional:
        // an exercise without an age window and an intensity band stays
        // out of VCT session generation, which is correct — it cannot be
        // judged age-safe. Values are clamped rather than trusted so a
        // REST caller cannot store an intensity of 47.
        $ranges = [
            'intensity_band'       => [ 1, 5 ],
            'duration_minutes_min' => [ 0, 240 ],
            'duration_minutes_max' => [ 0, 240 ],
            'players_min'          => [ 1, 40 ],
            'players_max'          => [ 1, 40 ],
            'age_min'              => [ 4, 21 ],
            'age_max'              => [ 4, 21 ],
        ];
        foreach ( $ranges as $key => [ $low, $high ] ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            $value      = $data[ $key ];
            $out[ $key ] = ( $value === null || $value === '' )
                ? null
                : max( $low, min( $high, (int) $value ) );
        }

        foreach ( [ 'code', 'tactical_theme', 'pitch_preset' ] as $key ) {
            if ( ! array_key_exists( $key, $data ) ) continue;
            $value       = $data[ $key ] === null ? '' : trim( (string) $data[ $key ] );
            $out[ $key ] = $value === '' ? null : $value;
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
