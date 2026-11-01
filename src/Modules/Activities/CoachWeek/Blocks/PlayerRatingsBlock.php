<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Reports\RatingsGridQuery;
use TT\Modules\Activities\Services\ActivityRegisterProgress;
use TT\Modules\Evaluations\EvaluationsModule;

/**
 * #4494 — on a completed match, how many of the players who played carry a
 * rating ("Ratings 9 of 14"). Played is the register's attended count
 * (Present or Late); rated is counted among those same players, from the
 * ratings grid's own source (`RatingsGridQuery`). Both read once for the
 * week. Counts only.
 */
final class PlayerRatingsBlock extends AbstractWeekBlock {

    public function key(): string { return 'ratings'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Player ratings', 'talenttrack' ); }

    public function help(): string { return __( 'How many of the players who played have been rated.', 'talenttrack' ); }

    public function available(): bool { return ModuleRegistry::isEnabled( EvaluationsModule::class ); }

    /**
     * Not for the assistant coach: the matrix gives that persona no
     * evaluations read (#1060). The rights check below decides in any case.
     */
    public function defaultFor( string $persona, string $device ): bool {
        return $device === 'desktop' && in_array( $persona, [ 'head_coach', 'head_of_development' ], true );
    }

    public function rowDefaults(): array { return [ 'ratings' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        $counts = self::countsFor( $row, $week );
        return $counts === null ? [] : [ 'ratings' => $counts ];
    }

    /**
     * Rated against played for one completed match; null when it is not
     * completed, nobody played, or it is not the viewer's to see. Shared
     * with the Rate players button.
     *
     * @return array{rated:int, played:int}|null
     */
    public static function countsFor( WeekRow $row, WeekContext $week ): ?array {
        // A ratings count is evaluation data: it shows only to a viewer who
        // may read this team's evaluations, whatever the persona defaults say.
        if ( ! $row->done || ! $row->register || ! $week->canReadOnTeam( 'evaluations', $row->teamId ) ) return null;
        if ( ! $week->recorded( $row->activityId ) ) return null;
        $played = ActivityRegisterProgress::attendedCount( $row->activityId );
        if ( $played <= 0 ) return null;
        $done = array_values( array_map(
            static fn( WeekRow $r ): int => $r->activityId,
            array_filter( $week->rows(), static fn( WeekRow $r ): bool => $r->done )
        ) );
        $rated = $week->once( 'rated_players', static fn(): array => RatingsGridQuery::ratedPlayerCounts( $done ) );
        return [ 'rated' => (int) ( $rated[ $row->activityId ] ?? 0 ), 'played' => $played ];
    }
}
