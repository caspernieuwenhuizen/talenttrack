<?php
namespace TT\Modules\Exercises;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * ActivityExercisesRepository (#0016 Sprint 2a) — read + write API
 * for the `tt_activity_exercises` linkage table that pins specific
 * exercise versions to activities.
 *
 * The pinning model: `exercise_id` references a specific
 * `tt_exercises.id` row, NOT a logical exercise key. When a coach
 * edits an exercise, `ExercisesRepository::editAsNewVersion()`
 * creates a new row at `version + 1` and points the previous row's
 * `superseded_by_id` at it; activities that link to the previous
 * row continue to render the original drill description, durations,
 * and principles.
 *
 * Sprint 2a (this ship) ships the repository layer; Sprint 2b adds
 * the activity-edit UI section. Sprint 4 wires the AI-extraction
 * review wizard to call `replaceExercisesForActivity()` when the
 * operator commits the captured session.
 *
 * All reads + writes scope to `CurrentClub::id()`.
 */
final class ActivityExercisesRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_activity_exercises';
    }

    /**
     * List every linked exercise for an activity, ordered by
     * `order_index`. Joins `tt_exercises` so the caller has the
     * exercise name + duration + visibility without a second query.
     *
     * @return list<object>
     */
    public function listForActivity( int $activity_id ): array {
        if ( $activity_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ae.*,
                    e.name AS exercise_name,
                    e.description AS exercise_description,
                    e.duration_minutes AS exercise_planned_duration,
                    e.category_id AS exercise_category_id,
                    e.diagram_url AS exercise_diagram_url
               FROM {$this->table()} ae
          LEFT JOIN {$wpdb->prefix}tt_exercises e ON e.id = ae.exercise_id
              WHERE ae.club_id = %d AND ae.activity_id = %d
              ORDER BY ae.order_index ASC, ae.id ASC",
            CurrentClub::id(),
            $activity_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Return every activity that linked a given exercise (history
     * view). Caller filters by date / team / etc. as needed.
     *
     * @return list<object>
     */
    public function listForExercise( int $exercise_id ): array {
        if ( $exercise_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT ae.*,
                    a.title AS activity_title,
                    a.session_date AS activity_date,
                    a.team_id AS activity_team_id
               FROM {$this->table()} ae
          LEFT JOIN {$wpdb->prefix}tt_activities a ON a.id = ae.activity_id
              WHERE ae.club_id = %d AND ae.exercise_id = %d
              ORDER BY a.session_date DESC, ae.order_index ASC",
            CurrentClub::id(),
            $exercise_id
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Append a single exercise to an activity at the next free
     * order_index. Returns the new row id (0 on failure).
     *
     * @param array<string,mixed> $data Optional overrides:
     *   - actual_duration_minutes: int — coach's adjustment vs the
     *     exercise's planned duration
     *   - notes: string
     *   - is_draft: bool — Sprint 6 only
     */
    public function append( int $activity_id, int $exercise_id, array $data = [] ): int {
        if ( $activity_id <= 0 || $exercise_id <= 0 ) return 0;
        global $wpdb;

        $next_order = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(MAX(order_index), -1) + 1
               FROM {$this->table()}
              WHERE club_id = %d AND activity_id = %d",
            CurrentClub::id(),
            $activity_id
        ) );

        $row = [
            'club_id'                 => CurrentClub::id(),
            'activity_id'             => $activity_id,
            'exercise_id'             => $exercise_id,
            'order_index'             => isset( $data['order_index'] ) ? max( 0, (int) $data['order_index'] ) : $next_order,
            'actual_duration_minutes' => isset( $data['actual_duration_minutes'] ) ? max( 0, min( 240, (int) $data['actual_duration_minutes'] ) ) : null,
            'notes'                   => isset( $data['notes'] ) ? sanitize_textarea_field( (string) $data['notes'] ) : null,
            'is_draft'                => ! empty( $data['is_draft'] ) ? 1 : 0,
            'created_at'              => current_time( 'mysql' ),
            'updated_at'              => current_time( 'mysql' ),
        ];
        $ok = $wpdb->insert( $this->table(), $row );
        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * Update a single linkage row (re-order, adjust duration / notes,
     * confirm draft → final).
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $clean = [];
        if ( array_key_exists( 'order_index', $patch ) ) {
            $clean['order_index'] = max( 0, (int) $patch['order_index'] );
        }
        if ( array_key_exists( 'actual_duration_minutes', $patch ) ) {
            $clean['actual_duration_minutes'] = $patch['actual_duration_minutes'] === null
                ? null
                : max( 0, min( 240, (int) $patch['actual_duration_minutes'] ) );
        }
        if ( array_key_exists( 'notes', $patch ) ) {
            $clean['notes'] = $patch['notes'] === null
                ? null
                : sanitize_textarea_field( (string) $patch['notes'] );
        }
        if ( array_key_exists( 'is_draft', $patch ) ) {
            $clean['is_draft'] = empty( $patch['is_draft'] ) ? 0 : 1;
        }
        if ( empty( $clean ) ) return false;
        $clean['updated_at'] = current_time( 'mysql' );
        $ok = $wpdb->update( $this->table(), $clean, [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return $ok !== false;
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;
        global $wpdb;
        $ok = $wpdb->delete( $this->table(), [ 'id' => $id, 'club_id' => CurrentClub::id() ] );
        return $ok !== false;
    }

    public function deleteForActivity( int $activity_id ): bool {
        if ( $activity_id <= 0 ) return false;
        global $wpdb;
        $wpdb->delete( $this->table(), [ 'activity_id' => $activity_id, 'club_id' => CurrentClub::id() ] );
        return true;
    }

    /**
     * Replace the entire exercise list for an activity in one call.
     * Useful for the Sprint 4 review wizard's "commit the captured
     * session" path: every prior linkage is removed and the new
     * ordered list is appended.
     *
     * @param list<array{exercise_id:int,actual_duration_minutes?:int,notes?:string,is_draft?:bool}> $rows
     */
    public function replaceExercisesForActivity( int $activity_id, array $rows ): bool {
        if ( $activity_id <= 0 ) return false;
        $this->deleteForActivity( $activity_id );
        foreach ( $rows as $idx => $row ) {
            $exercise_id = (int) ( $row['exercise_id'] ?? 0 );
            if ( $exercise_id <= 0 ) continue;
            $this->append( $activity_id, $exercise_id, [
                'order_index'             => $idx,
                'actual_duration_minutes' => $row['actual_duration_minutes'] ?? null,
                'notes'                   => $row['notes'] ?? null,
                'is_draft'                => ! empty( $row['is_draft'] ),
            ] );
        }
        return true;
    }
}
