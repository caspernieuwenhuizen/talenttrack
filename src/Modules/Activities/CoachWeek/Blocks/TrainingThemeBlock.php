<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Domain\Vocabularies\Lookups\ActivityTypeKey;
use TT\Infrastructure\Query\LookupTranslator;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Training\Repositories\TrainingPlanRunsRepository;
use TT\Modules\Training\TrainingModule;

/**
 * #4493 — the theme of the training plan a training runs, as a neutral
 * chip. Nothing shows when no plan is linked or the plan has no theme. The
 * themes of the whole week are read in one query.
 */
final class TrainingThemeBlock extends AbstractWeekBlock {

    public function key(): string { return 'training_theme'; }

    public function group(): string { return WeekBlockGroup::TRAINING; }

    public function label(): string { return __( 'Training theme', 'talenttrack' ); }

    public function help(): string { return __( 'The theme of the linked training plan.', 'talenttrack' ); }

    public function available(): bool { return ModuleRegistry::isEnabled( TrainingModule::class ); }

    public function defaultFor( string $persona, string $device ): bool {
        if ( $device !== 'desktop' ) return false;
        return $persona === 'head_coach' || $persona === 'assistant_coach';
    }

    public function rowDefaults(): array { return [ 'training_theme' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( $row->type !== ActivityTypeKey::TRAINING || ! $week->canSeeTeam( $row->teamId ) ) return [];
        $themes = $week->once( 'training_themes', fn(): array => ( new TrainingPlanRunsRepository() )->themeKeysForActivities( $week->activityIds() ) );
        $key    = (string) ( $themes[ $row->activityId ] ?? '' );
        if ( $key === '' ) return [];
        $label = $week->once( 'training_theme_label:' . $key, static fn(): string => (string) LookupTranslator::byTypeAndName( 'vct_tactical_theme', $key ) );
        return [ 'training_theme' => $label !== '' ? $label : $key ];
    }
}
