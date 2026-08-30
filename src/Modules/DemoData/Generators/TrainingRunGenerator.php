<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;

/**
 * TrainingRunGenerator — writes tt_training_plan_runs and
 * tt_training_plan_run_blocks.
 *
 * Wave 3 classified both tables as `planned` against #2499, and epic
 * decision D18 confirmed it: a demo academy should carry a real training
 * history, not an empty Training module with a New button.
 *
 * ## What makes these runs worth generating
 *
 * A run is the only record of what was *actually* trained, as opposed to
 * what was planned. So the interesting demo data is where the two differ:
 * a block that ran long, one that got skipped because the pitch flooded.
 * A demo where every run matches its plan exactly would teach a coach
 * nothing about why the run record exists.
 *
 * Every run therefore lands on a completed training in the past, with
 * actual durations that wander a little from the plan and the occasional
 * skipped cool-down — which is exactly what real academies record.
 */
class TrainingRunGenerator implements DependentGeneratorInterface {

    /**
     * How each run's actual durations differ from the plan, in minutes,
     * cycled by block index. Deterministic rather than random: demo data
     * has to reproduce from a seed, and a fixed drift reads as realistic
     * without being unrepeatable.
     *
     * @var list<int>
     */
    private const DRIFT = [ 0, 2, 5, -3, 0, -2 ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var array<string,int> */
    private array $users;

    public static function category(): string {
        return 'training_runs';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->users );
    }

    /**
     * @param object[]          $teams
     * @param array<string,int> $users
     */
    public function __construct( DemoBatchRegistry $registry, array $teams, array $users ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->users    = $users;
    }

    public function generate(): int {
        global $wpdb;

        $runs  = new TrainingPlanRunsRepository();
        $total = 0;

        foreach ( $this->teams as $team ) {
            $team_id = (int) ( $team->id ?? 0 );
            if ( $team_id <= 0 ) continue;

            $plans = (array) $wpdb->get_results( $wpdb->prepare(
                "SELECT id, created_at
                   FROM {$wpdb->prefix}tt_training_plans
                  WHERE club_id = %d AND team_id = %d AND archived_at IS NULL
               ORDER BY created_at ASC",
                CurrentClub::id(),
                $team_id
            ) );
            if ( ! $plans ) continue;

            $activities = $this->completedTrainings( $team_id, count( $plans ) );

            foreach ( $plans as $index => $plan ) {
                $activity = $activities[ $index ] ?? null;
                if ( ! $activity ) break;

                $run_id = $runs->attach(
                    (int) $plan->id,
                    (int) $activity->id,
                    $team_id,
                    (string) $activity->session_date
                );
                if ( $run_id <= 0 ) continue;

                $this->recordWhatHappened( $runs, $run_id );
                $runs->setStatus( $run_id, 'completed' );

                // Both types tagged with the RUN id: the block rows are
                // wiped by `delete_by` on run_id, and the cleaner skips a
                // child type whose own tag set is empty.
                $this->registry->tag( 'training_plan_run', $run_id, [ 'team_id' => $team_id ] );
                $this->registry->tag( 'training_plan_run_block', $run_id, [ 'team_id' => $team_id ] );
                $total++;
            }
        }

        return $total;
    }

    /**
     * Trainings that already happened, newest last. A run belongs on a
     * completed training: attaching one to next Tuesday would claim a
     * session took place that has not.
     *
     * @return list<object>
     */
    private function completedTrainings( int $team_id, int $limit ): array {
        global $wpdb;

        // Scoped by team, and the teams are this batch's (#3184 — the
        // subject set comes from `GeneratorContext::$teams`, not from a
        // club-wide read). The `club_id` filter was missing outright, which
        // on a multi-tenant install would have attached a run to another
        // club's training.
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, session_date
               FROM {$wpdb->prefix}tt_activities
              WHERE team_id = %d
                AND club_id = %d
                AND activity_type_key = 'training'
                AND session_date <= %s
                AND archived_at IS NULL
           ORDER BY session_date ASC, id ASC
              LIMIT %d",
            $team_id,
            CurrentClub::id(),
            current_time( 'mysql' ),
            max( 1, $limit )
        ) );

        return is_array( $rows ) ? $rows : [];
    }

    /**
     * The point of the run record: what actually happened, which is never
     * quite the plan.
     */
    private function recordWhatHappened( TrainingPlanRunsRepository $runs, int $run_id ): void {
        $blocks = $runs->listBlocks( $run_id );
        $last   = count( $blocks ) - 1;

        foreach ( $blocks as $index => $block ) {
            $planned = (int) ( $block->planned_duration_minutes ?? 0 );
            if ( $planned <= 0 ) continue;

            // One session in three loses its last block. Cool-downs are
            // the first thing to go when the pitch is needed at eight.
            if ( $index === $last && ( $run_id % 3 ) === 0 ) {
                $runs->updateBlock( (int) $block->id, [ 'was_skipped' => true ] );
                continue;
            }

            $drift = self::DRIFT[ $index % count( self::DRIFT ) ];
            $runs->updateBlock( (int) $block->id, [
                'actual_duration_minutes' => max( 1, $planned + $drift ),
            ] );
        }
    }
}
