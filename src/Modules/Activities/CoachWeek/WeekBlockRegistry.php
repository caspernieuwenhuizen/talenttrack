<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Every block the "This week" panel knows, in display order. The order is
 * also the action priority: of the action blocks, the first that applies
 * is a row's main button.
 *
 * A new block is one class implementing `WeekBlock` (or `ActionBlock`),
 * added to `BLOCKS` at the place it should show. Nothing else in the
 * service, the view's settings screen or the REST route changes for it.
 */
final class WeekBlockRegistry {

    /** @var list<class-string<WeekBlock>> */
    private const BLOCKS = [
        Blocks\TeamNameBlock::class,
        Blocks\NextWeekBlock::class,
        Blocks\SummaryBlock::class,
        Blocks\TypeBlock::class,
        Blocks\TimesBlock::class,
        Blocks\LocationBlock::class,
        Blocks\AttendanceBlock::class,
        Blocks\ExpectedPlayersBlock::class,
        Blocks\TrainingPlanBlock::class,
        Blocks\OpponentBlock::class,
        Blocks\AvailabilityBlock::class,
        Blocks\LineupBlock::class,
        Blocks\MarkAttendanceAction::class,
        Blocks\MatchPrepAction::class,
        Blocks\StartMatchAction::class,
        Blocks\OpenActivityAction::class,
        Blocks\PdpDueBlock::class,
        Blocks\EvalsDueBlock::class,
        Blocks\TasksDueBlock::class,
    ];

    /** @var array<string, WeekBlock>|null */
    private static ?array $blocks = null;

    /** @return array<string, WeekBlock> key => block, in display order */
    public static function all(): array {
        if ( self::$blocks === null ) {
            self::$blocks = [];
            foreach ( self::BLOCKS as $class ) {
                $block = new $class();
                self::$blocks[ $block->key() ] = $block;
            }
        }
        return self::$blocks;
    }

    /**
     * The blocks that can work on this install (`WeekBlock::available()`):
     * the ones the settings screen lists and the week asks.
     *
     * @return array<string, WeekBlock> key => block, in display order
     */
    public static function available(): array {
        return array_filter( self::all(), static fn( WeekBlock $b ): bool => $b->available() );
    }

    public static function get( string $key ): ?WeekBlock {
        return self::all()[ $key ] ?? null;
    }

    /** @return list<string> */
    public static function keys(): array {
        return array_keys( self::all() );
    }

    /**
     * The available blocks of one settings group, in display order.
     *
     * @return list<WeekBlock>
     */
    public static function inGroup( string $group ): array {
        return array_values( array_filter( self::available(), static fn( WeekBlock $b ): bool => $b->group() === $group ) );
    }

    /**
     * The action blocks, in priority order.
     *
     * @return list<ActionBlock>
     */
    public static function actions(): array {
        $out = [];
        foreach ( self::all() as $block ) {
            if ( $block instanceof ActionBlock ) $out[] = $block;
        }
        return $out;
    }

    /** Drop the built instances, so a test that swaps translations rebuilds the labels. */
    public static function reset(): void {
        self::$blocks = null;
    }
}
