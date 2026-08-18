<?php
namespace TT\Modules\Training\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrainingPlansRepository (#2496) — read + write on `tt_training_plans`
 * and its principle-coverage child.
 *
 * A plan is the reusable template: mutable, carrying no version chain
 * (epic decision D5). Editing one cannot rewrite history because a run
 * snapshots its blocks at attach time — see TrainingPlanRunsRepository.
 *
 * Visibility mirrors `tt_exercises`:
 *   'club'    every team sees it
 *   'team'    only the owning team (plan.team_id)
 *   'private' only the author
 *
 * A plan with `team_id IS NULL` is club-wide. Combined with
 * `is_template = 1` that is what the templates bucket lists.
 *
 * Every read and write is scoped to CurrentClub::id(). It is always 1
 * today; the filter is what stops a second tenant on this install seeing
 * another club's plans, and is a no-op until there is one.
 */
final class TrainingPlansRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_plans';
    }

    private function principlesTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_plan_principles';
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
     * List plans, newest first.
     *
     * @param array{team_id?:int|null, is_template?:bool|null, include_archived?:bool, theme_key?:string, author_user_id?:int, limit?:int, offset?:int} $args
     * @return list<object>
     */
    public function listPlans( array $args = [] ): array {
        global $wpdb;

        $sql    = "SELECT * FROM {$this->table()} WHERE club_id = %d";
        $params = [ CurrentClub::id() ];

        if ( empty( $args['include_archived'] ) ) {
            $sql .= ' AND archived_at IS NULL';
        }
        if ( array_key_exists( 'team_id', $args ) && $args['team_id'] !== null ) {
            // A team's plans, plus the club-wide ones it can draw on.
            $sql     .= ' AND (team_id = %d OR team_id IS NULL)';
            $params[] = (int) $args['team_id'];
        }
        if ( array_key_exists( 'is_template', $args ) && $args['is_template'] !== null ) {
            $sql     .= ' AND is_template = %d';
            $params[] = $args['is_template'] ? 1 : 0;
        }
        if ( ! empty( $args['theme_key'] ) ) {
            $sql     .= ' AND theme_key = %s';
            $params[] = (string) $args['theme_key'];
        }
        if ( ! empty( $args['author_user_id'] ) ) {
            $sql     .= ' AND author_user_id = %d';
            $params[] = (int) $args['author_user_id'];
        }

        $sql .= ' ORDER BY created_at DESC, id DESC';

        $limit = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
        $sql     .= ' LIMIT %d OFFSET %d';
        $params[] = $limit;
        $params[] = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

        $rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Create a plan. Returns the new id, or 0 on failure.
     *
     * @param array<string,mixed> $data
     */
    public function create( array $data ): int {
        global $wpdb;

        $row = $this->normalise( $data );
        if ( ( $row['title'] ?? '' ) === '' ) return 0;

        $row['uuid']    = wp_generate_uuid4();
        $row['club_id'] = CurrentClub::id();

        $ok = $wpdb->insert( $this->table(), $row );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Partial update. Returns true when the row was reachable and the
     * write did not error — an update that changes nothing still counts.
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;

        $clean = $this->normalise( $patch, true );
        if ( ! $clean ) return true;

        $ok = $wpdb->update(
            $this->table(),
            $clean,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Copy a plan and its blocks. The copy is always a fresh row with its
     * own uuid; `source` records where it came from so the list can tell a
     * duplicated plan from a generated one.
     *
     * Returns the new plan id, or 0 if the source was unreachable.
     */
    public function duplicate( int $id, ?string $title = null, ?int $team_id = null, bool $as_template = false ): int {
        $plan = $this->findById( $id );
        if ( ! $plan ) return 0;

        $new_id = $this->create( [
            'title'                  => $title ?? $this->copyTitle( (string) $plan->title ),
            'notes'                  => $plan->notes,
            'team_id'                => $as_template ? null : ( $team_id ?? $plan->team_id ),
            'age_group_key'          => $plan->age_group_key,
            'season_id'              => $plan->season_id,
            'theme_key'              => $plan->theme_key,
            'total_duration_minutes' => (int) $plan->total_duration_minutes,
            'intensity_target'       => $plan->intensity_target,
            'is_template'            => $as_template ? 1 : 0,
            'visibility'             => $plan->visibility,
            'source'                 => 'duplicated',
            'author_user_id'         => get_current_user_id() ?: null,
        ] );

        if ( $new_id > 0 ) {
            ( new TrainingPlanBlocksRepository() )->copyBlocks( $id, $new_id );
            $this->syncDerivedPrinciples( $new_id );
        }

        return $new_id;
    }

    /** Soft-delete. The plan's runs are untouched — history is theirs. */
    public function archive( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'archived_at' => current_time( 'mysql', true ) ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    public function restore( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            [ 'archived_at' => null ],
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        return $ok !== false;
    }

    /**
     * Recompute `total_duration_minutes` from the plan's blocks. Called
     * after any block write so the list and the wizard never disagree with
     * the builder about how long a session is.
     */
    public function recalculateDuration( int $plan_id ): int {
        if ( $plan_id <= 0 ) return 0;
        global $wpdb;

        $total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(duration_minutes), 0)
               FROM {$wpdb->prefix}tt_training_plan_blocks
              WHERE plan_id = %d AND club_id = %d",
            $plan_id,
            CurrentClub::id()
        ) );

        $wpdb->update(
            $this->table(),
            [ 'total_duration_minutes' => $total ],
            [ 'id' => $plan_id, 'club_id' => CurrentClub::id() ]
        );

        return $total;
    }

    /**
     * Principle ids covered by this plan.
     *
     * @return list<int>
     */
    public function listPrincipleIds( int $plan_id ): array {
        if ( $plan_id <= 0 ) return [];
        global $wpdb;
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT principle_id FROM {$this->principlesTable()}
              WHERE plan_id = %d AND club_id = %d
              ORDER BY principle_id ASC",
            $plan_id,
            CurrentClub::id()
        ) );
        return is_array( $ids ) ? array_map( 'intval', $ids ) : [];
    }

    /**
     * Rebuild the derived principle rows from the plan's blocks'
     * exercises. Manual pins (`is_manual = 1`) are left alone — they are
     * the coach's own statement of intent and must survive a block swap.
     *
     * Returns the number of derived rows after the rebuild.
     */
    public function syncDerivedPrinciples( int $plan_id ): int {
        if ( $plan_id <= 0 ) return 0;
        global $wpdb;

        $club = CurrentClub::id();

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->principlesTable()}
              WHERE plan_id = %d AND club_id = %d AND is_manual = 0",
            $plan_id,
            $club
        ) );

        $principle_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT ep.principle_id
               FROM {$wpdb->prefix}tt_training_plan_blocks b
               JOIN {$wpdb->prefix}tt_exercise_principles ep
                 ON ep.exercise_id = b.exercise_id AND ep.club_id = b.club_id
              WHERE b.plan_id = %d AND b.club_id = %d AND b.exercise_id IS NOT NULL",
            $plan_id,
            $club
        ) );
        if ( ! is_array( $principle_ids ) ) return 0;

        $count = 0;
        foreach ( $principle_ids as $principle_id ) {
            $principle_id = (int) $principle_id;
            if ( $principle_id <= 0 ) continue;

            // A manual pin already covers this principle — leave it as the
            // coach set it rather than downgrading it to derived.
            $already = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->principlesTable()}
                  WHERE plan_id = %d AND club_id = %d AND principle_id = %d",
                $plan_id,
                $club,
                $principle_id
            ) );
            if ( $already ) continue;

            $wpdb->insert( $this->principlesTable(), [
                'club_id'      => $club,
                'plan_id'      => $plan_id,
                'principle_id' => $principle_id,
                'is_manual'    => 0,
            ] );
            $count++;
        }

        return $count;
    }

    /** Pin a principle to a plan by hand. Idempotent. */
    public function pinPrinciple( int $plan_id, int $principle_id ): bool {
        if ( $plan_id <= 0 || $principle_id <= 0 ) return false;
        global $wpdb;

        $club = CurrentClub::id();

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$this->principlesTable()}
              WHERE plan_id = %d AND club_id = %d AND principle_id = %d",
            $plan_id,
            $club,
            $principle_id
        ) );

        if ( $existing ) {
            // Promote a derived row to a manual pin.
            return $wpdb->update(
                $this->principlesTable(),
                [ 'is_manual' => 1 ],
                [ 'id' => $existing, 'club_id' => $club ]
            ) !== false;
        }

        return $wpdb->insert( $this->principlesTable(), [
            'club_id'      => $club,
            'plan_id'      => $plan_id,
            'principle_id' => $principle_id,
            'is_manual'    => 1,
        ] ) !== false;
    }

    public function unpinPrinciple( int $plan_id, int $principle_id ): bool {
        if ( $plan_id <= 0 || $principle_id <= 0 ) return false;
        global $wpdb;
        return $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->principlesTable()}
              WHERE plan_id = %d AND club_id = %d AND principle_id = %d AND is_manual = 1",
            $plan_id,
            CurrentClub::id(),
            $principle_id
        ) ) !== false;
    }

    /**
     * Whitelist + coerce a create/update payload. Unknown keys are dropped
     * rather than passed through to $wpdb, so a REST caller cannot write a
     * column the API does not expose.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalise( array $data, bool $partial = false ): array {
        $out = [];

        $strings  = [ 'title', 'notes', 'age_group_key', 'theme_key', 'visibility', 'source' ];
        $ints     = [ 'team_id', 'season_id', 'total_duration_minutes', 'intensity_target', 'author_user_id' ];
        $bools    = [ 'is_template' ];
        $nullable = [ 'notes', 'age_group_key', 'theme_key', 'team_id', 'season_id', 'intensity_target', 'author_user_id' ];

        foreach ( $strings as $k ) {
            if ( ! array_key_exists( $k, $data ) ) continue;
            $v = $data[ $k ];
            $out[ $k ] = $v === null ? null : (string) $v;
            if ( $out[ $k ] === '' && in_array( $k, $nullable, true ) ) $out[ $k ] = null;
        }
        foreach ( $ints as $k ) {
            if ( ! array_key_exists( $k, $data ) ) continue;
            $v = $data[ $k ];
            $out[ $k ] = ( $v === null || $v === '' ) && in_array( $k, $nullable, true ) ? null : (int) $v;
        }
        foreach ( $bools as $k ) {
            if ( array_key_exists( $k, $data ) ) $out[ $k ] = empty( $data[ $k ] ) ? 0 : 1;
        }

        if ( ! $partial ) {
            $out['visibility'] = $this->validVisibility( $out['visibility'] ?? 'club' );
            $out['source']     = $this->validSource( $out['source'] ?? 'manual' );
            $out['title']      = trim( (string) ( $out['title'] ?? '' ) );
        } else {
            if ( array_key_exists( 'visibility', $out ) ) $out['visibility'] = $this->validVisibility( (string) $out['visibility'] );
            if ( array_key_exists( 'source', $out ) )     $out['source']     = $this->validSource( (string) $out['source'] );
        }

        return $out;
    }

    private function validVisibility( string $value ): string {
        return in_array( $value, [ 'club', 'team', 'private' ], true ) ? $value : 'club';
    }

    private function validSource( string $value ): string {
        return in_array( $value, [ 'generated', 'manual', 'photo', 'duplicated' ], true ) ? $value : 'manual';
    }

    private function copyTitle( string $title ): string {
        /* translators: %s is the title of the plan being copied. */
        $copy = sprintf( __( '%s (copy)', 'talenttrack' ), $title );
        return mb_substr( $copy, 0, 190 );
    }
}
