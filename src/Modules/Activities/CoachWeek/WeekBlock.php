<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * One switchable piece of the "This week" panel (#4492): a chip, a line, a
 * button or a section an academy turns on or off per persona and per device.
 *
 * A block is asked for its data only when it is on for at least one device
 * of the viewer's persona. It may read what it needs through the shared
 * `WeekContext`, which batches every source once per week. The contract is
 * documented in docs/coach-week-blocks.md.
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

    /** Whether the block is on for this persona and device when the academy has not chosen. */
    public function defaultFor( string $persona, string $device ): bool;

    /**
     * Fields this block sets on one row, merged into the row payload. []
     * when the block adds nothing to this row.
     *
     * @return array<string, mixed>
     */
    public function rowFields( WeekRow $row, WeekContext $week ): array;

    /**
     * Fields this block adds to the top level of the week payload. [] for a
     * block that only works per row.
     *
     * @return array<string, mixed>
     */
    public function panelFields( WeekContext $week ): array;
}
