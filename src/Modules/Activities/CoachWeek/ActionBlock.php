<?php
namespace TT\Modules\Activities\CoachWeek;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * A block that offers one button on a row. Action blocks are asked in
 * registry order, which is their priority: the first that applies on a
 * device is that device's main button, and at most `MAX_PER_ROW` show.
 */
interface ActionBlock extends WeekBlock {

    public const MAX_PER_ROW = 2;

    /** The action key for this row (a `CoachWeek::ACTION_*` value), or null when it does not apply. */
    public function actionFor( WeekRow $row, WeekContext $week ): ?string;
}
