<?php
namespace TT\Modules\Training\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Goals\GoalsRepository;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Training\Repositories\TrainingPlanBlocksRepository;
use TT\Modules\Training\Repositories\TrainingPlansRepository;

/**
 * PlanCoverageService (#2498) — which players a plan actually works on.
 *
 * The question the epic exists to answer, in the form the surfaces need
 * it: given a set of blocks, whose open goals does this training touch,
 * and whose does it miss?
 *
 * ## Why this is its own class
 *
 * #2497 computed the same thing privately inside `TrainingPlanComposer`,
 * against a *draft* whose blocks already carried the principles the
 * selection pass had matched. The builder asks the same question of a
 * *saved* plan, where a block carries an exercise id and the principles
 * have to be read back through `tt_exercise_principles`. Two call sites
 * for one piece of domain reasoning is the moment to extract it rather
 * than copy it (CLAUDE.md §4 — logic lives outside the view, and the
 * REST controller and the PHP view call the same layer).
 *
 * So this class owns the reasoning in three layers, each one the input to
 * the next:
 *
 *   - `forPrincipleIds()` — the core. Whose open goals name any of these
 *     principles? The composer's draft path calls this directly.
 *   - `forExerciseIds()` — the same, having first resolved exercises to
 *     the principles they train.
 *   - `forPlan()` — the same again, having first read a plan's blocks and
 *     found its team's roster.
 *
 * ## Player-centricity
 *
 * The return carries player *names*, not just ids, because every surface
 * that asks this question shows people (CLAUDE.md §1). A coach reading
 * "misses 4 players" learns nothing; "misses Sem, Daan, Youssef and Nora"
 * is a decision they can act on before Tuesday.
 */
final class PlanCoverageService {

    private GoalsRepository $goals;

    public function __construct( ?GoalsRepository $goals = null ) {
        $this->goals = $goals ?? new GoalsRepository();
    }

    /**
     * The core read.
     *
     * @param list<int> $principle_ids     what the training covers
     * @param list<int> $roster_player_ids who is expected to be there
     * @return array{
     *   principle_ids: list<int>,
     *   player_ids: list<int>,
     *   missed_player_ids: list<int>,
     *   players: list<array{id:int, name:string, covered:bool, principle_ids:list<int>}>
     * }
     */
    public function forPrincipleIds( array $principle_ids, array $roster_player_ids ): array {
        $covered = [];
        foreach ( $principle_ids as $principle_id ) {
            $principle_id = (int) $principle_id;
            if ( $principle_id > 0 ) $covered[ $principle_id ] = true;
        }

        $hit     = [];
        $missed  = [];
        $players = [];

        $targets = $this->goals->openPrincipleTargetsForPlayers( $roster_player_ids );
        $names   = $this->namesFor( array_map( 'intval', array_keys( $targets ) ) );

        foreach ( $targets as $player_id => $wanted ) {
            $player_id = (int) $player_id;
            $touched   = false;
            foreach ( $wanted as $principle_id ) {
                if ( isset( $covered[ (int) $principle_id ] ) ) { $touched = true; break; }
            }

            if ( $touched ) $hit[] = $player_id; else $missed[] = $player_id;

            $players[] = [
                'id'            => $player_id,
                'name'          => $names[ $player_id ] ?? '',
                'covered'       => $touched,
                'principle_ids' => array_values( array_map( 'intval', $wanted ) ),
            ];
        }

        // Covered first, then by name, so the panel reads as "these are
        // served, these are not" without the coach sorting it themselves.
        usort( $players, static function ( array $a, array $b ): int {
            if ( $a['covered'] !== $b['covered'] ) return $a['covered'] ? -1 : 1;
            return strcasecmp( $a['name'], $b['name'] );
        } );

        return [
            'principle_ids'     => array_map( 'intval', array_keys( $covered ) ),
            'player_ids'        => $hit,
            'missed_player_ids' => $missed,
            'players'           => $players,
        ];
    }

    /**
     * @param list<int> $exercise_ids
     * @param list<int> $roster_player_ids
     * @return array<string,mixed>
     */
    public function forExerciseIds( array $exercise_ids, array $roster_player_ids ): array {
        return $this->forPrincipleIds(
            $this->principlesForExercises( $exercise_ids ),
            $roster_player_ids
        );
    }

    /**
     * Coverage for a saved plan, resolving its own roster.
     *
     * A template has no team, so it has no roster and therefore no
     * coverage to report — that is not a failure, it is what a template
     * is. The empty shape keeps callers from special-casing it.
     *
     * @return array<string,mixed>
     */
    public function forPlan( int $plan_id, ?array $roster_player_ids = null ): array {
        $plan = ( new TrainingPlansRepository() )->findById( $plan_id );
        if ( ! $plan ) return $this->forPrincipleIds( [], [] );

        $exercise_ids = [];
        foreach ( ( new TrainingPlanBlocksRepository() )->listForPlan( $plan_id ) as $block ) {
            $exercise_id = (int) ( $block->exercise_id ?? 0 );
            if ( $exercise_id > 0 ) $exercise_ids[] = $exercise_id;
        }

        $roster = $roster_player_ids ?? ( new SquadSizeEstimator() )->rosterFor( (int) ( $plan->team_id ?? 0 ) );

        return $this->forExerciseIds( $exercise_ids, $roster );
    }

    /**
     * How many of this roster's open goals each exercise would serve.
     * The picker sorts on this, which is the whole reason it is not just
     * an alphabetical list of drills.
     *
     * @param list<int> $exercise_ids
     * @param list<int> $roster_player_ids
     * @return array<int,int> exercise id => number of players served
     */
    public function playersServedByExercise( array $exercise_ids, array $roster_player_ids ): array {
        $wanted_by_principle = [];
        foreach ( $this->goals->openPrincipleTargetsForPlayers( $roster_player_ids ) as $player_id => $wanted ) {
            foreach ( $wanted as $principle_id ) {
                $wanted_by_principle[ (int) $principle_id ][ (int) $player_id ] = true;
            }
        }

        $out = [];
        foreach ( $this->principleMapFor( $exercise_ids ) as $exercise_id => $principle_ids ) {
            $served = [];
            foreach ( $principle_ids as $principle_id ) {
                foreach ( array_keys( $wanted_by_principle[ $principle_id ] ?? [] ) as $player_id ) {
                    $served[ $player_id ] = true;
                }
            }
            $out[ $exercise_id ] = count( $served );
        }

        foreach ( $exercise_ids as $exercise_id ) {
            $out[ (int) $exercise_id ] = $out[ (int) $exercise_id ] ?? 0;
        }

        return $out;
    }

    /**
     * @param list<int> $exercise_ids
     * @return list<int>
     */
    private function principlesForExercises( array $exercise_ids ): array {
        $ids = [];
        foreach ( $this->principleMapFor( $exercise_ids ) as $principle_ids ) {
            foreach ( $principle_ids as $principle_id ) $ids[ $principle_id ] = true;
        }

        return array_map( 'intval', array_keys( $ids ) );
    }

    /**
     * @param list<int> $exercise_ids
     * @return array<int,list<int>>
     */
    private function principleMapFor( array $exercise_ids ): array {
        $exercise_ids = array_values( array_unique( array_filter( array_map( 'intval', $exercise_ids ) ) ) );
        if ( $exercise_ids === [] ) return [];

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $exercise_ids ), '%d' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            "SELECT exercise_id, principle_id
               FROM {$wpdb->prefix}tt_exercise_principles
              WHERE club_id = %d AND exercise_id IN ({$placeholders})",
            array_merge( [ CurrentClub::id() ], $exercise_ids )
        ) );

        $map = [];
        foreach ( (array) $rows as $row ) {
            $map[ (int) $row->exercise_id ][] = (int) $row->principle_id;
        }

        return $map;
    }

    /**
     * @param list<int> $player_ids
     * @return array<int,string>
     */
    private function namesFor( array $player_ids ): array {
        $player_ids = array_values( array_unique( array_filter( array_map( 'intval', $player_ids ) ) ) );
        if ( $player_ids === [] ) return [];

        global $wpdb;

        $placeholders = implode( ',', array_fill( 0, count( $player_ids ), '%d' ) );
        $rows         = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id IN ({$placeholders})",
            $player_ids
        ) );

        $out = [];
        foreach ( (array) $rows as $row ) {
            $out[ (int) $row->id ] = trim( (string) $row->first_name . ' ' . (string) $row->last_name );
        }

        return $out;
    }
}
