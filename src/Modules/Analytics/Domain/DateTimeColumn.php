<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * DateTimeColumn — the timeline anchor of a fact (#0083 Child 1).
 *
 * Time-series KPIs use this column for `date_after` / `date_before`
 * filters and for the "month" / "season" derived dimensions. Some
 * facts have a column on the fact table itself (`evaluations.created_at`);
 * others need a join (`attendance.activity_id` → `tt_activities.start_at`).
 *
 * `joinedTable` + `joinKey` describe the optional join:
 *   - `joinedTable: 'tt_activities a'` — table + alias.
 *   - `joinKey: 'activity_id'` — the FK on the fact table that
 *     references `<joinedTable>.id`.
 *
 * When both are null, `expression` resolves on the fact table itself.
 */
final class DateTimeColumn {

    public string $expression;
    public ?string $joinedTable;
    public ?string $joinKey;

    public function __construct(
        string $expression,
        ?string $joinedTable = null,
        ?string $joinKey = null
    ) {
        $this->expression  = $expression;
        $this->joinedTable = $joinedTable;
        $this->joinKey     = $joinKey;
    }
}
