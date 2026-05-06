<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Measure — a column you can aggregate (#0083 Child 1).
 *
 * Each fact registers a list of measures. KPIs reference one
 * measure plus zero-or-more filters. The query engine renders the
 * measure in the SELECT list using the `aggregation` function.
 *
 * Conditional aggregations (e.g. "count of attendance rows where
 * status='present'") use a CASE expression in `column`:
 *
 *   new Measure(
 *       key:         'count_present',
 *       label:       __('Present', 'talenttrack'),
 *       aggregation: 'count',
 *       column:      "CASE WHEN status='present' THEN 1 END"
 *   )
 *
 * `count` aggregations with `column = null` emit `COUNT(*)`.
 *
 * `unit` and `format` drive the explorer's display (rating shows
 * `7.2`, percent shows `82%`, minutes shows `1h 20m`). The fact
 * registry doesn't render — it just labels the data so the
 * presentation layer (#0083 Child 3 explorer) can format
 * consistently across KPIs.
 */
final class Measure {

    public const AGG_COUNT = 'count';
    public const AGG_AVG   = 'avg';
    public const AGG_SUM   = 'sum';
    public const AGG_MIN   = 'min';
    public const AGG_MAX   = 'max';

    public const UNIT_RATING  = 'rating';
    public const UNIT_MINUTES = 'minutes';
    public const UNIT_PERCENT = 'percent';
    public const UNIT_DAYS    = 'days';

    public const FORMAT_INTEGER = 'integer';
    public const FORMAT_DECIMAL = 'decimal';
    public const FORMAT_PERCENT = 'percent';

    public string $key;
    public string $label;
    public string $aggregation;
    public ?string $column;
    public ?string $unit;
    public ?string $format;

    public function __construct(
        string $key,
        string $label,
        string $aggregation,
        ?string $column = null,
        ?string $unit = null,
        ?string $format = null
    ) {
        $this->key         = $key;
        $this->label       = $label;
        $this->aggregation = $aggregation;
        $this->column      = $column;
        $this->unit        = $unit;
        $this->format     = $format;
    }
}
