<?php
namespace TT\Modules\Training\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrainingPlanBlocksRepository (#2496) — the ordered rows of a plan.
 *
 * `order_index` is dense and zero-based, and `UNIQUE (club_id, plan_id,
 * order_index)` enforces it at the database. Every mutation that can
 * disturb the ordering re-packs it, so a caller never has to reason about
 * gaps and the builder's drag-reorder cannot leave two blocks claiming
 * the same slot.
 *
 * `exercise_id` pins a specific `tt_exercises` row. That table versions on
 * edit (a change writes a new row and points the old one at it), so a plan
 * keeps rendering the drill as it was written when it was chosen. A NULL
 * `exercise_id` is a free-text block — a team talk, a walk-through, or a
 * drill the coach has not put in the library yet.
 */
final class TrainingPlanBlocksRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_plan_blocks';
    }

    /**
     * Blocks of a plan in order, joined to the exercise so a caller gets
     * the drill's name, duration hints and diagram without a second query.
     *
     * @return list<object>
     */
    public function listForPlan( int $plan_id ): array {
        if ( $plan_id <= 0 ) return [];
        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT b.*,
                    e.name             AS exercise_name,
                    e.description      AS exercise_description,
                    e.diagram_url      AS exercise_diagram_url,
                    e.intensity_band   AS exercise_intensity_band,
                    e.players_min      AS exercise_players_min,
                    e.players_max      AS exercise_players_max
               FROM {$this->table()} b
          LEFT JOIN {$wpdb->prefix}tt_exercises e
                 ON e.id = b.exercise_id AND e.club_id = b.club_id
              WHERE b.plan_id = %d AND b.club_id = %d
           ORDER BY b.order_index ASC",
            $plan_id,
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

    /**
     * Append a block to the end of a plan. Returns the new id, or 0.
     *
     * @param array<string,mixed> $data
     */
    public function append( int $plan_id, array $data ): int {
        if ( $plan_id <= 0 ) return 0;
        global $wpdb;

        $row                = $this->normalise( $data );
        $row['uuid']        = wp_generate_uuid4();
        $row['club_id']     = CurrentClub::id();
        $row['plan_id']     = $plan_id;
        $row['order_index'] = $this->nextOrderIndex( $plan_id );

        $ok = $wpdb->insert( $this->table(), $row );
        if ( $ok === false ) return 0;

        $id = (int) $wpdb->insert_id;
        $this->afterMutation( $plan_id );
        return $id;
    }

    /**
     * Partial update of one block. `order_index` is not writable here —
     * use move() or replaceAll(), which keep the ordering dense.
     *
     * @param array<string,mixed> $patch
     */
    public function update( int $id, array $patch ): bool {
        $block = $this->findById( $id );
        if ( ! $block ) return false;

        $clean = $this->normalise( $patch, true );
        unset( $clean['order_index'], $clean['plan_id'] );
        if ( ! $clean ) return true;

        global $wpdb;
        $ok = $wpdb->update(
            $this->table(),
            $clean,
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) return false;

        $this->afterMutation( (int) $block->plan_id );
        return true;
    }

    public function delete( int $id ): bool {
        $block = $this->findById( $id );
        if ( ! $block ) return false;

        global $wpdb;
        $ok = $wpdb->delete(
            $this->table(),
            [ 'id' => $id, 'club_id' => CurrentClub::id() ]
        );
        if ( $ok === false ) return false;

        $this->repack( (int) $block->plan_id );
        $this->afterMutation( (int) $block->plan_id );
        return true;
    }

    /**
     * Move a block to a new position. `$to` is clamped into range, so the
     * builder's "up" on the first block and "down" on the last are no-ops
     * rather than errors.
     */
    public function move( int $id, int $to ): bool {
        $block = $this->findById( $id );
        if ( ! $block ) return false;

        $plan_id = (int) $block->plan_id;
        $ordered = $this->listForPlan( $plan_id );
        if ( count( $ordered ) < 2 ) return true;

        $ids = [];
        foreach ( $ordered as $row ) {
            if ( (int) $row->id !== $id ) $ids[] = (int) $row->id;
        }

        $to = max( 0, min( count( $ids ), $to ) );
        array_splice( $ids, $to, 0, [ $id ] );

        return $this->applyOrder( $plan_id, $ids );
    }

    /**
     * Replace every block of a plan in one call — the bulk-commit target
     * for the builder's save and for the generator's output.
     *
     * Deletes first, then re-inserts in the given order, so the caller
     * hands over the whole desired state rather than a diff.
     *
     * @param list<array<string,mixed>> $blocks
     */
    public function replaceAll( int $plan_id, array $blocks ): bool {
        if ( $plan_id <= 0 ) return false;
        global $wpdb;

        $club = CurrentClub::id();

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$this->table()} WHERE plan_id = %d AND club_id = %d",
            $plan_id,
            $club
        ) );
        if ( $deleted === false ) return false;

        foreach ( array_values( $blocks ) as $index => $block ) {
            $row                = $this->normalise( is_array( $block ) ? $block : [] );
            $row['uuid']        = wp_generate_uuid4();
            $row['club_id']     = $club;
            $row['plan_id']     = $plan_id;
            $row['order_index'] = $index;

            if ( $wpdb->insert( $this->table(), $row ) === false ) return false;
        }

        $this->afterMutation( $plan_id );
        return true;
    }

    /** Copy every block of one plan onto another. Used by duplicate(). */
    public function copyBlocks( int $from_plan_id, int $to_plan_id ): bool {
        if ( $from_plan_id <= 0 || $to_plan_id <= 0 ) return false;

        $payload = [];
        foreach ( $this->listForPlan( $from_plan_id ) as $block ) {
            $payload[] = [
                'block_type'       => $block->block_type,
                'exercise_id'      => $block->exercise_id,
                'title_override'   => $block->title_override,
                'organisation'     => $block->organisation,
                'coaching_points'  => $block->coaching_points,
                'duration_minutes' => (int) $block->duration_minutes,
                'intensity_band'   => $block->intensity_band,
                'players_min'      => $block->players_min,
                'players_max'      => $block->players_max,
            ];
        }

        return $this->replaceAll( $to_plan_id, $payload );
    }

    /**
     * Re-index a plan's blocks to 0..n-1 in their current order.
     *
     * Two passes through a parked offset: `UNIQUE (plan_id, order_index)`
     * would trip mid-loop if a row were written straight onto an index a
     * later row still holds.
     */
    private function repack( int $plan_id ): void {
        $ids = [];
        foreach ( $this->listForPlan( $plan_id ) as $row ) {
            $ids[] = (int) $row->id;
        }
        $this->applyOrder( $plan_id, $ids );
    }

    /** @param list<int> $ordered_ids */
    private function applyOrder( int $plan_id, array $ordered_ids ): bool {
        global $wpdb;
        $club   = CurrentClub::id();
        $offset = 10000;

        foreach ( $ordered_ids as $position => $id ) {
            $ok = $wpdb->update(
                $this->table(),
                [ 'order_index' => $offset + $position ],
                [ 'id' => (int) $id, 'club_id' => $club, 'plan_id' => $plan_id ]
            );
            if ( $ok === false ) return false;
        }

        $ok = $wpdb->query( $wpdb->prepare(
            "UPDATE {$this->table()} SET order_index = order_index - %d
              WHERE plan_id = %d AND club_id = %d AND order_index >= %d",
            $offset,
            $plan_id,
            $club,
            $offset
        ) );

        return $ok !== false;
    }

    private function nextOrderIndex( int $plan_id ): int {
        global $wpdb;
        $max = $wpdb->get_var( $wpdb->prepare(
            "SELECT MAX(order_index) FROM {$this->table()} WHERE plan_id = %d AND club_id = %d",
            $plan_id,
            CurrentClub::id()
        ) );
        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     * Keep the plan's derived state in step with its blocks. Called after
     * every write so the duration on the list and the principle pills on
     * the detail can never disagree with what the builder shows.
     */
    private function afterMutation( int $plan_id ): void {
        $plans = new TrainingPlansRepository();
        $plans->recalculateDuration( $plan_id );
        $plans->syncDerivedPrinciples( $plan_id );
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function normalise( array $data, bool $partial = false ): array {
        $out = [];

        $strings  = [ 'block_type', 'title_override', 'organisation', 'coaching_points' ];
        $ints     = [ 'exercise_id', 'duration_minutes', 'intensity_band', 'players_min', 'players_max' ];
        $nullable = [ 'title_override', 'organisation', 'coaching_points', 'exercise_id', 'intensity_band', 'players_min', 'players_max' ];

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

        if ( ! $partial ) {
            $out['block_type']       = $this->validBlockType( (string) ( $out['block_type'] ?? 'main' ) );
            $out['duration_minutes'] = max( 0, (int) ( $out['duration_minutes'] ?? 0 ) );
        } elseif ( array_key_exists( 'block_type', $out ) ) {
            $out['block_type'] = $this->validBlockType( (string) $out['block_type'] );
        }

        return $out;
    }

    /**
     * The block-type vocabulary. Deliberately a fixed list rather than a
     * lookup: these are the shape of a session, not academy vocabulary,
     * and they drive the colour coding that has to mean the same thing on
     * every surface.
     */
    private function validBlockType( string $value ): string {
        $allowed = [ 'warmup', 'rondo', 'main', 'game', 'finishing', 'cooldown', 'talk' ];
        return in_array( $value, $allowed, true ) ? $value : 'main';
    }
}
