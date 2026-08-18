<?php
namespace TT\Modules\Training;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\TrainingPlansRestController;
use TT\Infrastructure\REST\TrainingRunsRestController;

/**
 * TrainingModule (#2496, epic #2493) — owns the training plan.
 *
 * A plan is the reusable template a coach builds a session from; a run is
 * one execution of that plan against one activity, and the record every
 * later per-player training figure is derived from. The module owns
 * `tt_training_plans`, `tt_training_plan_blocks`,
 * `tt_training_plan_principles`, `tt_training_plan_runs` and
 * `tt_training_plan_run_blocks` (migration 0213), their repositories, and
 * the REST surface over both.
 *
 * The exercise library it draws blocks from is not owned here — that is
 * `ExercisesModule`, which since migration 0212 holds the one merged
 * catalogue including the VCT conditioning exercises.
 *
 * Shipped:
 *   - The five tables + three repositories.
 *   - `tt_training_plan` capability, matrix-only, bridged in
 *     `LegacyCapMapper` to the `training_plan` entity and seeded for the
 *     coach / head-of-development / academy-admin personas.
 *   - REST at `/training/plans` and `/training/runs`.
 *
 * Not yet, by wave:
 *   - The `Training` tile, the plan list and the read-only detail (#2496).
 *   - The generator and its wizard (#2497).
 *   - The builder UI (#2498).
 *   - Attach-to-activity UI, the sideline view and the A4 print (#2499).
 *   - Observations and per-player training exposure (#2500) — the wave
 *     that makes the module worth having.
 */
class TrainingModule implements ModuleInterface {

    public function getName(): string { return 'training'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );

        TrainingPlansRestController::init();
        TrainingRunsRestController::init();
    }

    /**
     * `tt_training_plan` is matrix-only — no role baseline beyond the
     * grants seeded in `config/authorization_seed.php`. It is registered
     * on the coach roles here so the raw capability exists to be bridged;
     * the matrix decides the scope.
     */
    public static function ensureCapabilities(): void {
        foreach ( [ 'administrator', 'tt_club_admin', 'tt_head_dev', 'tt_coach' ] as $role_name ) {
            $role = get_role( $role_name );
            if ( $role && ! $role->has_cap( 'tt_training_plan' ) ) {
                $role->add_cap( 'tt_training_plan' );
            }
        }
    }
}
