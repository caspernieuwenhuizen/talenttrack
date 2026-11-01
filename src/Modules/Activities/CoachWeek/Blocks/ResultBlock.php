<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\Activities\Reports\MatchResultQuery;

/**
 * #4494 — the result of a completed match, from the stored scoreline read
 * through `MatchResultQuery::frame()`: our goals first whatever the venue
 * (#4066), and nothing when no score is stored, because empty is not 0–0.
 */
final class ResultBlock extends AbstractWeekBlock {

    public function key(): string { return 'score'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Result', 'talenttrack' ); }

    public function help(): string { return __( 'Won, lost or drew, on a completed match with a score.', 'talenttrack' ); }

    /** persona => devices on by default (#4494); every other persona opts in. */
    private const DEFAULTS = [
        'head_coach'          => [ 'desktop', 'phone' ],
        'head_of_development' => [ 'desktop', 'phone' ],
        'academy_admin'       => [ 'desktop' ],
    ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function rowDefaults(): array { return [ 'result' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->done || ! $week->canSeeTeam( $row->teamId ) ) return [];
        $frame = MatchResultQuery::frame( $row->activity['home_score'] ?? null, $row->activity['away_score'] ?? null );
        if ( $frame['outcome'] === '' ) return [];
        return [ 'result' => $frame ];
    }
}
