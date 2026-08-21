<?php
namespace TT\Modules\Training\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrainingPlanRunsRepository (#2496) — one execution of a plan against one
 * activity.
 *
 * This is the player-bearing half of the plan/run split. A plan is a
 * reusable template with no player in it; a run happened, on a date, to a
 * squad. Attendance on the run's activity plus these blocks is what yields
 * per-player principle exposure in wave 7 — so the integrity rules here
 * are the ones that later numbers rest on.
 *
 * Two of them matter:
 *
 *   1. `blocks_snapshot_json` is written once, at attach time, and never
 *      rewritten. Editing the plan afterwards — or archiving it, or
 *      deleting a block — cannot change what this session contained.
 *   2. `UNIQUE (club_id, activity_id)` means one run per activity, enforced
 *      at the database rather than trusted to the caller. attach() returns
 *      the existing run instead of erroring, so a double-tap on "attach"
 *      is idempotent rather than a duplicate.
 */
final class TrainingPlanRunsRepository {

    /** Lifecycle states. `abandoned` is a coach starting and walking away. */
    public const STATUSES = [ 'planned', 'running', 'completed', 'abandoned' ];

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_plan_runs';
    }

    private function blocksTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_plan_run_blocks';
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

    public function findForActivity( int $activity_id ): ?object {
        if ( $activity_id <= 0 ) return null;
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT * FROM {$this->table()} WHERE activity_id = %d AND club_id = %d",
            $activity_id,
            CurrentClub::id()
        ) );
        return $row ?: null;
    }

    /**
     * Runs of a plan, newest first. This is the plan detail's
     * "Uitvoeringen" tab.
     *
     * @return list<object>
     */
    public function listForPlan( int $plan_id ): array {
        if ( $plan_id <= 0 ) return [];
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE plan_id = %d AND club_id = %d
           ORDER BY run_date DESC, id DESC",
            $plan_id,
            CurrentClub::id()
        ) );
        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Attach a plan to an activity, snapshotting its blocks.
     *
     * Returns the run id. When the activity already has a run, returns
     * that one untouched — re-attaching must never silently replace a
     * snapshot, because a completed session's history would go with it.
     */
    public function attach( int $plan_id, int $activity_id, ?int $team_id, string $run_date ): int {
        if ( $plan_id <= 0 || $activity_id <= 0 ) return 0;

        $existing = $this->findForActivity( $activity_id );
        if ( $existing ) return (int) $existing->id;

        $plans = new TrainingPlansRepository();
        $plan  = $plans->findById( $plan_id );
        if ( ! $plan ) return 0;

        global $wpdb;

        $blocks   = ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id );
        $snapshot = wp_json_encode( $this->snapshotShape( $plan, $blocks ) );

        $ok = $wpdb->insert( $this->table(), [
            'uuid'                 => wp_generate_uuid4(),
            'club_id'              => CurrentClub::id(),
            'plan_id'              => $plan_id,
            'activity_id'          => $activity_id,
            'team_id'              => $team_id ?: ( $plan->team_id ? (int) $plan->team_id : null ),
            'run_date'             => $this->validDate( $run_date ),
            'status'               => 'planned',
            'blocks_snapshot_json' => $snapshot,
        ] );
        if ( $ok === false ) return 0;

        $run_id = (int) $wpdb->insert_id;
        $this->seedRunBlocks( $run_id, $blocks );

        return $run_id;
    }

    /**
     * Move a run through its lifecycle. Stamps `started_at` and
     * `completed_at` on the transitions that own them, so a caller cannot
     * forget to.
     */
    public function setStatus( int $run_id, string $status ): bool {
        if ( $run_id <= 0 || ! in_array( $status, self::STATUSES, true ) ) return false;
        global $wpdb;

        $patch = [ 'status' => $status ];
        $now   = current_time( 'mysql', true );

        if ( $status === 'running' ) {
            $run = $this->findById( $run_id );
            if ( $run && empty( $run->started_at ) ) $patch['started_at'] = $now;
        }
        if ( $status === 'completed' ) {
            $patch['completed_at'] = $now;
        }

        return $wpdb->update(
            $this->table(),
            $patch,
            [ 'id' => $run_id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Run blocks in order, joined to the plan block so a caller can show
     * planned versus actual side by side. LEFT JOIN because the plan block
     * may since have been edited away — the snapshot is authoritative.
     *
     * #2499 — and when it *has* been edited away, the join returns NULL
     * and a completed session reads "27 minutes actual against 0
     * planned". The docblock always said the snapshot was authoritative;
     * this now makes it true in the data rather than only in the comment,
     * by filling the planned columns from the snapshot whenever the live
     * plan block is gone. Every wave-7 number is computed from these
     * rows, so a silent zero here is a wrong number on a player's file.
     *
     * @return list<object>
     */
    public function listBlocks( int $run_id ): array {
        if ( $run_id <= 0 ) return [];
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT rb.*,
                    pb.block_type       AS planned_block_type,
                    pb.duration_minutes AS planned_duration_minutes,
                    pb.exercise_id      AS planned_exercise_id,
                    pb.title_override   AS planned_title_override
               FROM {$this->blocksTable()} rb
          LEFT JOIN {$wpdb->prefix}tt_training_plan_blocks pb
                 ON pb.id = rb.plan_block_id AND pb.club_id = rb.club_id
              WHERE rb.run_id = %d AND rb.club_id = %d
           ORDER BY rb.order_index ASC",
            $run_id,
            CurrentClub::id()
        ) );

        return $this->fillPlannedFromSnapshot( $run_id, is_array( $rows ) ? $rows : [] );
    }

    /**
     * Fill in the planned figures the join could not supply.
     *
     * Only reads the snapshot when at least one row needs it, so the
     * ordinary case — a plan nobody has touched since — costs nothing.
     *
     * @param list<object> $rows
     * @return list<object>
     */
    private function fillPlannedFromSnapshot( int $run_id, array $rows ): array {
        $needs = false;
        foreach ( $rows as $row ) {
            if ( $row->planned_duration_minutes === null ) { $needs = true; break; }
        }
        if ( ! $needs ) return $rows;

        $by_order = [];
        foreach ( (array) ( $this->snapshot( $run_id )['blocks'] ?? [] ) as $block ) {
            $by_order[ (int) ( $block['order_index'] ?? 0 ) ] = $block;
        }

        foreach ( $rows as $row ) {
            if ( $row->planned_duration_minutes !== null ) continue;

            $snap = $by_order[ (int) $row->order_index ] ?? null;
            if ( $snap === null ) continue;

            $row->planned_duration_minutes = isset( $snap['duration_minutes'] ) ? (int) $snap['duration_minutes'] : null;
            $row->planned_block_type       = $snap['block_type'] ?? $row->planned_block_type;
            $row->planned_exercise_id      = $snap['exercise_id'] ?? $row->planned_exercise_id;
            $row->planned_title_override   = $snap['title'] ?? $row->planned_title_override;
        }

        return $rows;
    }

    /**
     * Record what actually happened in one block.
     *
     * @param array{actual_duration_minutes?:int|null, was_skipped?:bool, notes?:string|null} $patch
     */
    public function updateBlock( int $run_block_id, array $patch ): bool {
        if ( $run_block_id <= 0 ) return false;
        global $wpdb;

        $clean = [];
        if ( array_key_exists( 'actual_duration_minutes', $patch ) ) {
            $v = $patch['actual_duration_minutes'];
            $clean['actual_duration_minutes'] = ( $v === null || $v === '' ) ? null : max( 0, (int) $v );
        }
        if ( array_key_exists( 'was_skipped', $patch ) ) {
            $clean['was_skipped'] = empty( $patch['was_skipped'] ) ? 0 : 1;
        }
        if ( array_key_exists( 'notes', $patch ) ) {
            $v = $patch['notes'];
            $clean['notes'] = ( $v === null || $v === '' ) ? null : (string) $v;
        }
        if ( ! $clean ) return true;

        return $wpdb->update(
            $this->blocksTable(),
            $clean,
            [ 'id' => $run_block_id, 'club_id' => CurrentClub::id() ]
        ) !== false;
    }

    /**
     * Delete a run and its blocks. Deliberately NOT called when a plan is
     * archived — a plan going away must not take its history with it.
     */
    public function delete( int $run_id ): bool {
        if ( $run_id <= 0 ) return false;
        global $wpdb;

        $club = CurrentClub::id();

        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->blocksTable()} WHERE run_id = %d AND club_id = %d",
            $run_id,
            $club
        ) );

        return $wpdb->delete( $this->table(), [ 'id' => $run_id, 'club_id' => $club ] ) !== false;
    }

    /**
     * Decode the immutable snapshot. Returns an empty array rather than
     * null on malformed JSON so callers can iterate without guarding.
     *
     * @return array<string,mixed>
     */
    public function snapshot( int $run_id ): array {
        $run = $this->findById( $run_id );
        if ( ! $run || empty( $run->blocks_snapshot_json ) ) return [];
        $decoded = json_decode( (string) $run->blocks_snapshot_json, true );
        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * The snapshot's shape. Deliberately flat and self-contained — it has
     * to render a session years later without joining anything that may
     * have moved on.
     *
     * @param list<object> $blocks
     * @return array<string,mixed>
     */
    private function snapshotShape( object $plan, array $blocks ): array {
        $out = [
            'plan_id'                => (int) $plan->id,
            'plan_uuid'              => (string) $plan->uuid,
            'title'                  => (string) $plan->title,
            'theme_key'              => $plan->theme_key !== null ? (string) $plan->theme_key : null,
            'total_duration_minutes' => (int) $plan->total_duration_minutes,
            'snapshot_at'            => current_time( 'mysql', true ),
            'blocks'                 => [],
        ];

        foreach ( $blocks as $b ) {
            $out['blocks'][] = [
                'plan_block_id'    => (int) $b->id,
                'order_index'      => (int) $b->order_index,
                'block_type'       => (string) $b->block_type,
                'exercise_id'      => $b->exercise_id !== null ? (int) $b->exercise_id : null,
                'title'            => $b->title_override !== null && $b->title_override !== ''
                                        ? (string) $b->title_override
                                        : (string) ( $b->exercise_name ?? '' ),
                'organisation'     => $b->organisation !== null ? (string) $b->organisation : null,
                'coaching_points'  => $b->coaching_points !== null ? (string) $b->coaching_points : null,
                'duration_minutes' => (int) $b->duration_minutes,
                'intensity_band'   => $b->intensity_band !== null ? (int) $b->intensity_band : null,
            ];
        }

        return $out;
    }

    /** @param list<object> $blocks */
    private function seedRunBlocks( int $run_id, array $blocks ): void {
        global $wpdb;
        $club = CurrentClub::id();

        foreach ( $blocks as $index => $b ) {
            $wpdb->insert( $this->blocksTable(), [
                'club_id'       => $club,
                'run_id'        => $run_id,
                'plan_block_id' => (int) $b->id,
                'order_index'   => $index,
                'was_skipped'   => 0,
            ] );
        }
    }

    private function validDate( string $date ): string {
        $ts = strtotime( $date );
        return $ts ? gmdate( 'Y-m-d', $ts ) : current_time( 'Y-m-d' );
    }
}
