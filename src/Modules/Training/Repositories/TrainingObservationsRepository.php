<?php
namespace TT\Modules\Training\Repositories;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * TrainingObservationsRepository (#2500, epic #2493).
 *
 * What a coach saw one player do, during one block, on one evening.
 *
 * This is the only place in the Training module where a human writes
 * about a named child, which is why it is treated differently from the
 * rest: permission-gated on read, attributed to its author, and surfaced
 * on the player's journey rather than in a feed. CLAUDE.md §1 —
 * sensitive fields about minors are gated and traceable.
 *
 * ## Why `rating` is nullable everywhere
 *
 * A note with no score is a legitimate observation, and on a wet Tuesday
 * it is the common one. Requiring a number would mean coaches invent one,
 * and an invented 7 is worse than no rating at all — it looks like
 * evidence.
 *
 * `DECIMAL(3,1)` because the scale is operator-configured
 * (`rating_min` / `rating_max` / `rating_step` in `tt_config`) and some
 * installs use half steps. Storing an INT would silently round 7.5.
 */
final class TrainingObservationsRepository {

    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tt_training_observations';
    }

    /**
     * Record one observation.
     *
     * @param array{
     *   run_id:int, player_id:int, run_block_id?:int|null,
     *   principle_id?:int|null, football_action_id?:int|null,
     *   rating?:float|string|null, note?:string|null
     * } $data
     */
    public function create( array $data ): int {
        global $wpdb;

        $run_id    = (int) ( $data['run_id'] ?? 0 );
        $player_id = (int) ( $data['player_id'] ?? 0 );
        if ( $run_id <= 0 || $player_id <= 0 ) return 0;

        // An observation with neither a rating nor a note records
        // nothing. Refusing it keeps the timeline free of blank entries a
        // coach would have to click to discover were empty.
        $rating = $this->cleanRating( $data['rating'] ?? null );
        $note   = $this->cleanNote( $data['note'] ?? null );
        if ( $rating === null && $note === null ) return 0;

        $ok = $wpdb->insert( $this->table(), [
            'uuid'               => wp_generate_uuid4(),
            'club_id'            => CurrentClub::id(),
            'run_id'             => $run_id,
            'run_block_id'       => (int) ( $data['run_block_id'] ?? 0 ) ?: null,
            'player_id'          => $player_id,
            'principle_id'       => (int) ( $data['principle_id'] ?? 0 ) ?: null,
            'football_action_id' => (int) ( $data['football_action_id'] ?? 0 ) ?: null,
            'rating'             => $rating,
            'note'               => $note,
            'author_user_id'     => get_current_user_id() ?: null,
        ] );

        return $ok === false ? 0 : (int) $wpdb->insert_id;
    }

    /**
     * One player's observations, newest first — the player file's list.
     *
     * @return list<object>
     */
    public function listForPlayer( int $player_id, int $limit = 20 ): array {
        if ( $player_id <= 0 ) return [];

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT o.*, r.run_date, r.activity_id
               FROM {$this->table()} o
          LEFT JOIN {$wpdb->prefix}tt_training_plan_runs r
                 ON r.id = o.run_id AND r.club_id = o.club_id
              WHERE o.player_id = %d AND o.club_id = %d
           ORDER BY o.created_at DESC
              LIMIT %d",
            $player_id,
            CurrentClub::id(),
            max( 1, min( 100, $limit ) )
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * Everything recorded during one run — the sideline view's own list,
     * so a coach can see what they have already written tonight.
     *
     * @return list<object>
     */
    public function listForRun( int $run_id ): array {
        if ( $run_id <= 0 ) return [];

        global $wpdb;

        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$this->table()}
              WHERE run_id = %d AND club_id = %d
           ORDER BY created_at ASC",
            $run_id,
            CurrentClub::id()
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    public function delete( int $id ): bool {
        if ( $id <= 0 ) return false;

        global $wpdb;

        return $wpdb->delete( $this->table(), [
            'id'      => $id,
            'club_id' => CurrentClub::id(),
        ] ) !== false;
    }

    /**
     * Remove every observation belonging to a run.
     *
     * Called when a run is deleted: an observation is *about* a training
     * that happened, so it cannot outlive the record of that training —
     * it would become a note attached to nothing, on a minor's file.
     */
    public function deleteForRun( int $run_id ): int {
        if ( $run_id <= 0 ) return 0;

        global $wpdb;

        $deleted = $wpdb->delete( $this->table(), [
            'run_id'  => $run_id,
            'club_id' => CurrentClub::id(),
        ] );

        return $deleted === false ? 0 : (int) $deleted;
    }

    /**
     * Clamp a rating to the install's configured scale, or null.
     *
     * Out of range is refused rather than clamped: a 9 typed on a 5–7
     * install is a mistake, and silently storing 7 would put a number on
     * a child's record that nobody chose.
     */
    private function cleanRating( $raw ): ?string {
        if ( $raw === null || $raw === '' ) return null;
        if ( ! is_numeric( $raw ) ) return null;

        $value = (float) $raw;
        $min   = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_min', '5' );
        $max   = (float) \TT\Infrastructure\Query\QueryHelpers::get_config( 'rating_max', '9' );

        if ( $value < $min || $value > $max ) return null;

        return number_format( $value, 1, '.', '' );
    }

    private function cleanNote( $raw ): ?string {
        if ( ! is_string( $raw ) ) return null;

        $note = trim( wp_strip_all_tags( $raw ) );

        return $note === '' ? null : $note;
    }
}
