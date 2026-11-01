<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One switchable piece of the "This week" panel (#4492): a chip, a line, a
 * button or a section an academy turns on or off per persona and per device.
 *
 * A block is asked for its data only when it is available and on for at
 * least one device of the viewer's persona. It reads what it needs through
 * the shared `WeekContext`, which batches every source once per week, and
 * gates on the context's rights checks, never on the persona. It writes
 * only the fields it declares in `rowDefaults()` / `panelDefaults()`. The
 * contract is documented in docs/coach-week-blocks.md.
 */
interface WeekBlock {

    /** Stable key: stored in `coach_week.config` and listed in `GET me/week`. */
    public function key(): string;

    /** One of the `WeekBlockGroup` keys; decides where the settings screen lists it. */
    public function group(): string;

    /** Translated name on the settings screen. */
    public function label(): string;

    /** Translated one-line explanation on the settings screen; '' for none. */
    public function help(): string;

    /**
     * Whether the block can work on this install: its module is on, its
     * plan allows it. An unavailable block has no switch on the settings
     * screen, is never asked for data and is listed for no device.
     */
    public function available(): bool;

    /** Whether the block is on for this persona and device when the academy has not chosen. */
    public function defaultFor( string $persona, string $device ): bool;

    /**
     * True when the block reaches this persona only once an academy switches
     * it on for them: no default and no fallback turns it on.
     */
    public function optInFor( string $persona ): bool;

    /**
     * The row fields this block owns, each with its value when the block is
     * off or has nothing for the row. A block writes only these fields.
     *
     * @return array<string, mixed>
     */
    public function rowDefaults(): array;

    /**
     * The top-level week fields this block owns, each with its value when
     * the block is off. A block writes only these fields.
     *
     * @return array<string, mixed>
     */
    public function panelDefaults(): array;

    /**
     * This block's fields for one row; [] when it adds nothing to this row.
     * Keys outside `rowDefaults()` are ignored.
     *
     * @return array<string, mixed>
     */
    public function rowFields( WeekRow $row, WeekContext $week ): array;

    /**
     * This block's top-level fields; [] for a block that only works per row.
     * Keys outside `panelDefaults()` are ignored.
     *
     * @return array<string, mixed>
     */
    public function panelFields( WeekContext $week ): array;
}
