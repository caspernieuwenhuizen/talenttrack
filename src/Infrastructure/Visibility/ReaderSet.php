<?php
namespace TT\Infrastructure\Visibility;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * ReaderSet (#4991) — everyone one copy of a report is read by, and the
 * rights they all hold.
 *
 * One reader on screen; a scheduled PDF's owner and every recipient
 * together, where 0 stands for a recipient whose rights cannot be checked
 * (`ScheduledReportReaders`). Built once per run and handed to every
 * section, so a right that does not depend on the player is worked out once,
 * not once per player, and the next section added to a report reads it from
 * here instead of deriving it again.
 *
 * - `journeyLevels()`: the journey visibility levels every reader holds
 *   (`RecordVisibility::forJourney()`); a reader of 0 holds public only.
 * - `testLevels()`: the measurement levels every reader holds
 *   (`RecordVisibility::forMeasurements()`).
 * - `seesMedical()`: every reader stands on the journey's medical level.
 * - `every()`: a right of every reader that does depend on the record.
 */
final class ReaderSet {

    /** @var list<int> */
    public readonly array $readers;

    /** @var list<string>|null */
    private ?array $journey = null;

    /** @var list<string>|null */
    private ?array $tests = null;

    /** @var array<string,mixed> */
    private array $memo = [];

    /** @param list<int> $readers */
    private function __construct( array $readers ) {
        $this->readers = $readers;
    }

    /**
     * @param list<int> $readers the first is the one a report is composed as;
     *        none at all reads as one reader without rights
     */
    public static function of( array $readers ): self {
        $readers = array_values( array_unique( array_map( 'intval', $readers ) ) );
        return new self( $readers === [] ? [ 0 ] : $readers );
    }

    /** The reader a report is composed as: a schedule's owner, or the viewer. */
    public function first(): int {
        return $this->readers[0];
    }

    /** Does every reader hold this right? */
    public function every( callable $holds ): bool {
        foreach ( $this->readers as $reader ) {
            if ( ! $holds( $reader ) ) return false;
        }
        return true;
    }

    /** @return list<string> */
    public function journeyLevels(): array {
        return $this->journey ??= $this->intersect( [ RecordVisibility::class, 'forJourney' ] );
    }

    /** @return list<string> */
    public function testLevels(): array {
        return $this->tests ??= $this->intersect( [ RecordVisibility::class, 'forMeasurements' ] );
    }

    public function seesMedical(): bool {
        return in_array( RecordVisibility::LEVEL_MEDICAL, $this->journeyLevels(), true );
    }

    /**
     * A value worked out once for this set of readers, such as the narrowest
     * audience they read a player report as.
     *
     * @template T
     * @param callable(): T $compute
     * @return T
     */
    public function remember( string $key, callable $compute ) {
        if ( ! array_key_exists( $key, $this->memo ) ) $this->memo[ $key ] = $compute();
        return $this->memo[ $key ];
    }

    /**
     * The levels every reader holds, in the first reader's order.
     *
     * @param callable(int): list<string> $levels
     * @return list<string>
     */
    private function intersect( callable $levels ): array {
        $out = null;
        foreach ( $this->readers as $reader ) {
            $held = $levels( $reader );
            $out  = $out === null ? $held : array_values( array_intersect( $out, $held ) );
        }
        return $out ?? [];
    }
}
