<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\ActionBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Services\CoachWeek;
use TT\Modules\License\LicenseGate;
use TT\Modules\MatchAnalysis\MatchAnalysisModule;

/**
 * #4494 — Write match analysis: on a completed match without one. Shown
 * only to a user who may edit that team's activities, which is what the
 * analysis screen's writes ask.
 */
final class WriteAnalysisAction extends AbstractWeekBlock implements ActionBlock {

    public function key(): string { return 'action_match_analysis'; }

    public function group(): string { return WeekBlockGroup::ACTIONS; }

    public function label(): string { return __( 'Write match analysis', 'talenttrack' ); }

    public function help(): string { return __( 'On a completed match without an analysis.', 'talenttrack' ); }

    public function available(): bool {
        return ModuleRegistry::isEnabled( MatchAnalysisModule::class ) && LicenseGate::allows( 'match_analysis' );
    }

    public function defaultFor( string $persona, string $device ): bool {
        return $persona === 'head_coach' && $device === 'desktop';
    }

    public function actionFor( WeekRow $row, WeekContext $week ): ?string {
        if ( ! $row->done || ! $week->canEditTeam( $row->teamId ) ) return null;
        // A completed match dated after today has not been played as far as
        // the analysis is concerned: the composer refuses it (as #4501 does
        // for attendance).
        if ( $row->when === WeekRow::WHEN_UPCOMING ) return null;
        return MatchAnalysisBlock::writtenFor( $row, $week ) === false ? CoachWeek::ACTION_MATCH_ANALYSIS : null;
    }
}
