<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Analytics\AnalyticsModule;
use TT\Modules\Analytics\EvalCoverageService;

/**
 * #4497 — "Evaluations due": the players on the panel's teams with a gap in
 * the evaluation round that is open now, as the Evaluation coverage view
 * marks them (`EvalCoverageService::coverage()`, cell state `gap`). Links to
 * that view, which only an academy-wide reader may open.
 */
final class EvalsDueBlock extends FollowUpBlock {

    public function key(): string { return 'evals'; }

    public function label(): string { return __( 'Evaluations due', 'talenttrack' ); }

    public function help(): string { return __( 'Players not yet evaluated in the evaluation round that is open now. Opens Evaluation coverage.', 'talenttrack' ); }

    protected function defaults(): array {
        return [
            'head_coach'          => [ true, false ],
            'head_of_development' => [ true, false ],
        ];
    }

    public function available(): bool {
        return ModuleRegistry::isEnabled( AnalyticsModule::class );
    }

    public function panelDefaults(): array { return [ 'evals_due' => null ]; }

    public function panelFields( WeekContext $week ): array {
        $uid = $week->userId;
        if ( ! EvalCoverageService::readerMayOpen( $uid ) || ! self::surfaceOpen( 'eval-coverage', $uid ) ) return [];

        $service = new EvalCoverageService();
        $round   = null;
        $windows = $service->windows();
        foreach ( $windows as $i => $window ) {
            $start = (string) ( $window['start'] ?? '' );
            $end   = (string) ( $window['end'] ?? '' );
            if ( $start !== '' && $start <= $week->today && ( $end === '' || $end >= $week->today ) ) {
                $round = (int) $i;
                break;
            }
        }
        if ( $round === null ) return [];

        $coverage = $week->once( 'eval_coverage', static fn(): array => $service->coverage() );
        $count    = 0;
        foreach ( (array) ( $coverage['teams'] ?? [] ) as $team ) {
            if ( ! $week->canSeeTeam( (int) ( $team['team_id'] ?? 0 ) ) ) continue;
            foreach ( (array) ( $team['players'] ?? [] ) as $player ) {
                if ( ( $player['cells'][ $round ]['state'] ?? '' ) === 'gap' ) $count++;
            }
        }
        if ( $count === 0 ) return [];

        return [ 'evals_due' => [
            'count'  => $count,
            'round'  => (string) ( $windows[ $round ]['name'] ?? '' ),
            'closes' => (string) ( $windows[ $round ]['end'] ?? '' ),
        ] ];
    }
}
