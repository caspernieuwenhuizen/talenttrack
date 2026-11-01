<?php
namespace TT\Modules\Activities\CoachWeek\Blocks;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\ModuleRegistry;
use TT\Modules\Activities\CoachWeek\AbstractWeekBlock;
use TT\Modules\Activities\CoachWeek\WeekBlockGroup;
use TT\Modules\Activities\CoachWeek\WeekContext;
use TT\Modules\Activities\CoachWeek\WeekRow;
use TT\Modules\MatchPrep\MatchPrepModule;
use TT\Modules\MatchPrep\Repositories\MatchPrepRepository;
use TT\Modules\MatchPrep\Services\MatchPrepState;

/**
 * #4494 — before a match: whether its preparation has a game plan written,
 * read from the goal fields `MatchPrepState` exposes. One query for the
 * week's matches. No preparation is no game plan.
 */
final class GamePlanBlock extends AbstractWeekBlock {

    public function key(): string { return 'prep_notes'; }

    public function group(): string { return WeekBlockGroup::MATCH; }

    public function label(): string { return __( 'Game plan ready', 'talenttrack' ); }

    public function help(): string { return __( 'Whether the match preparation has its game plan written.', 'talenttrack' ); }

    public function available(): bool { return ModuleRegistry::isEnabled( MatchPrepModule::class ); }

    /** persona => devices on by default (#4494); every other persona opts in. */
    private const DEFAULTS = [ 'head_coach' => [ 'desktop' ] ];

    public function defaultFor( string $persona, string $device ): bool {
        return in_array( $device, self::DEFAULTS[ $persona ] ?? [], true );
    }

    public function optInFor( string $persona ): bool {
        return ! isset( self::DEFAULTS[ $persona ] );
    }

    public function rowDefaults(): array { return [ 'game_plan' => null ]; }

    public function rowFields( WeekRow $row, WeekContext $week ): array {
        if ( ! $row->isOpenMatch() || ! $week->canSeeTeam( $row->teamId ) ) return [];
        $preps = $week->once( 'match_preps', fn(): array => ( new MatchPrepRepository() )->findByActivities( array_keys( $week->openMatches() ) ) );
        $prep  = isset( $preps[ $row->activityId ] ) ? (array) $preps[ $row->activityId ] : [];
        $ready = false;
        foreach ( MatchPrepState::GOAL_FIELDS as $field ) {
            if ( trim( (string) ( $prep[ $field ] ?? '' ) ) !== '' ) {
                $ready = true;
                break;
            }
        }
        return [ 'game_plan' => $ready ];
    }
}
