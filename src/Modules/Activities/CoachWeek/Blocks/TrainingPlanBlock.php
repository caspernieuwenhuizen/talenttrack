<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Training\TrainingModule;

/** Whether a training today or later has a training plan attached. Needs the Training module. */
final class TrainingPlanBlock extends CoreWeekBlock {

    public function key(): string { return 'training_plan'; }

    public function group(): string { return WeekBlockGroup::TRAINING; }

    public function label(): string { return __( 'Training plan linked', 'talenttrack' ); }

    public function help(): string { return __( 'Whether the training has a training plan yet.', 'talenttrack' ); }

    public function available(): bool { return ModuleRegistry::isEnabled( TrainingModule::class ); }

    public function rowDefaults(): array { return [ 'training_plan' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( $row->type !== ActivityTypeKey::TRAINING || $row->isDone() || ! $week->canSeeTeam( $row->teamId ) ) return [];
        return [ 'training_plan' => $week->hasTrainingRun( $row->activityId ) ];
    }
}
