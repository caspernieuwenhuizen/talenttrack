<?php
namespace TT\Modules\Training;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\TrainingPlansRestController;
use TT\Infrastructure\REST\TrainingRunsRestController;
use TT\Modules\Training\Print\TrainingPlanPrintRouter;
use TT\Modules\Training\Wizard\NewTrainingPlanWizard;
use TT\Shared\Tiles\TileRegistry;
use TT\Shared\Wizards\WizardRegistry;

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

        // #2499 — the A4 clipboard sheet. Hooks `template_redirect` at
        // priority 1 so it emits a standalone document before the theme
        // shell renders anything onto paper.
        TrainingPlanPrintRouter::init();

        add_action( 'init', [ self::class, 'registerTiles' ], 20 );

        // #2497 — the generator's wizard. Registered on `init` so the
        // registry is populated before any request resolves
        // `?tt_view=wizard&slug=new-training-plan`.
        add_action( 'init', static function (): void {
            if ( class_exists( WizardRegistry::class ) ) {
                WizardRegistry::register( new NewTrainingPlanWizard() );
            }
        }, 20 );
    }

    /**
     * One tile, not two (epic decision D10).
     *
     * The exercise library, the generator and — later — the coverage
     * report are all reached from inside the plans list as header actions.
     * Registering them as sibling tiles would put four Training entries on
     * a dashboard that already carries plenty, and CLAUDE.md §5b is
     * explicit that module-level navigation is the shell's job, rendered
     * once, not a view's.
     */
    public static function registerTiles(): void {
        TileRegistry::register( [
            'module_class' => self::class,
            'view_slug'    => 'training-plans',
            'entity'       => 'training_plan',
            'group'        => __( 'Planning & tactics', 'talenttrack' ),
            'kind'         => 'work',
            'order'        => 24,
            'label'        => __( 'Training', 'talenttrack' ),
            'description'  => __( 'Build your trainings: pick a theme, work from the exercise library, and keep what you trained on the player record.', 'talenttrack' ),
            'icon'         => 'activities',
            'color'        => '#2f9e5e',
            'cap'          => 'tt_training_plan',
        ] );
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
