<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Kpi — a fact-driven KPI specification (#0083 Child 2).
 *
 * Today's KPI in `Modules\PersonaDashboard\Kpis\` is a one-method
 * class with bespoke aggregation SQL inline. The new KPI is a
 * declarative value object that points at a fact + a measure +
 * filters. The platform computes it via `FactQuery`; no SQL lives
 * on the KPI itself.
 *
 * Migration of the 26 existing KPIs is mechanical (each maps to a
 * fact-driven `Kpi` declaration). Child 2 ships the value object +
 * registry + resolver + a small set of reference KPIs that exercise
 * the platform end-to-end. The bulk migration of the 26 legacy KPIs
 * lands in a follow-up — they keep working unchanged via the
 * existing `KpiDataSourceRegistry` until then.
 *
 * `context` controls which personas see the KPI:
 *   - `ACADEMY`       — academy-wide overview, surfaced to HoD / Admin.
 *   - `COACH`         — coach-facing, surfaced to head + assistant coaches.
 *   - `PLAYER_PARENT` — player + parent-facing, curated subset.
 *
 * `goalDirection` + `threshold` drive UI flagging:
 *   - `higher_better` + `threshold = 70.0` → the explorer flags the
 *      KPI red when its value drops below 70.
 *   - `lower_better` + `threshold = 5` → red when above 5 (e.g.
 *      "no-show count over 5 in 30 days").
 *
 * `primaryDimension` is the dimension used for the time-series chart
 * on the explorer drilldown — usually `'month'` for season-spanning
 * KPIs or `'day'` for short-window ones.
 *
 * `exploreDimensions` is the list of dimensions surfaced as filter
 * chips on the explorer. Order matters — first chip is the most
 * actionable for the typical user.
 */
final class Kpi {

    public const CONTEXT_ACADEMY       = 'ACADEMY';
    public const CONTEXT_COACH         = 'COACH';
    public const CONTEXT_PLAYER_PARENT = 'PLAYER_PARENT';

    public const GOAL_HIGHER_BETTER = 'higher_better';
    public const GOAL_LOWER_BETTER  = 'lower_better';

    public string $key;
    public string $label;
    public string $factKey;
    public string $measureKey;
    /** @var array<string,mixed> */
    public array $defaultFilters;
    public ?string $primaryDimension;
    /** @var string[] */
    public array $exploreDimensions;
    public ?string $context;
    public ?string $goalDirection;
    public ?float $threshold;
    public ?string $entityScope;

    /**
     * @param array<string,mixed> $defaultFilters
     * @param string[]            $exploreDimensions
     */
    public function __construct(
        string $key,
        string $label,
        string $factKey,
        string $measureKey,
        array $defaultFilters = [],
        ?string $primaryDimension = null,
        array $exploreDimensions = [],
        ?string $context = null,
        ?string $goalDirection = null,
        ?float $threshold = null,
        ?string $entityScope = null
    ) {
        $this->key               = $key;
        $this->label             = $label;
        $this->factKey           = $factKey;
        $this->measureKey        = $measureKey;
        $this->defaultFilters    = $defaultFilters;
        $this->primaryDimension  = $primaryDimension;
        $this->exploreDimensions = $exploreDimensions;
        $this->context           = $context;
        $this->goalDirection     = $goalDirection;
        $this->threshold         = $threshold;
        $this->entityScope       = $entityScope;
    }
}
