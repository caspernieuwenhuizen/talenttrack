<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\License\LicenseGate;
use TT\Modules\MatchAnalysis\MatchAnalysisModule;
use TT\Modules\MatchAnalysis\Repositories\MatchAnalysisRepository;

/**
 * #4494 — on a completed match, whether its analysis is written: a row
 * exists, the test the missing-analysis alert applies. One query for the
 * week's completed matches.
 */
final class MatchAnalysisBlock extends AbstractWeekBlock {

    public function key(): string { return 'analysis'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Match analysis', 'talenttrack' ); }

    public function help(): string { return __( 'Whether the match analysis is written.', 'talenttrack' ); }

    public function available(): bool {
        return ModuleRegistry::isEnabled( MatchAnalysisModule::class ) && LicenseGate::allows( 'match_analysis' );
    }

    /** persona => devices on by default (#4494); every other persona opts in. */
    private const DEFAULTS = [
        'head_coach'          => [ 'desktop' ],
        'head_of_development' => [ 'desktop' ],
    ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function rowDefaults(): array { return [ 'analysis_written' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        $written = self::writtenFor( $row, $week );
        return $written === null ? [] : [ 'analysis_written' => $written ];
    }

    /**
     * Whether a completed match has its analysis; null when it is not
     * completed or not the viewer's to see. Shared with the Write match
     * analysis button.
     */
    public static function writtenFor( WeekRow $row, WeekContext $week ): ?bool {
        if ( ! $row->done || ! $week->canSeeTeam( $row->teamId ) ) return null;
        $done = array_values( array_map(
            static fn( WeekRow $r ): int => $r->activityId,
            array_filter( $week->rows(), static fn( WeekRow $r ): bool => $r->done )
        ) );
        $with = $week->once( 'match_analyses', static fn(): array => ( new MatchAnalysisRepository() )->activityIdsWithAnalysis( $done ) );
        return isset( $with[ $row->activityId ] );
    }
}
