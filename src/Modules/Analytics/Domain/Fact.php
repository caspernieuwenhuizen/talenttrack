<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Fact — a row that something happened (#0083 Child 1).
 *
 * The unit of declarative reporting in TalentTrack. A fact is a
 * cataloguing of an existing fact table (`tt_evaluations`,
 * `tt_attendance`, `tt_journey_events`) plus the dimensions you
 * can group / filter by and the measures you can aggregate.
 *
 * The whole reporting framework (#0083) reads facts from the
 * `FactRegistry`. KPIs (#0083 Child 2) reference a fact + a measure
 * + default filters; the explorer (#0083 Child 3) iterates a fact's
 * dimensions to render filter chips and group-by selectors. No
 * bespoke aggregation SQL outside the framework — every analytical
 * question goes through `FactQuery::run()`.
 *
 * Verbosity is intentional. Each declaration is the union of
 * "everything we care about asking about this thing." Refactoring
 * a fact is cheaper than refactoring the bespoke SQL it replaces.
 *
 * `entityScope` lets KPIs ask "give me the attendance facts scoped
 * to player X" without re-deriving every time. Set to `'player'` for
 * facts that belong to a player (every attendance row is one player's
 * attendance), `'team'` for team-scoped, `'activity'` for activity-
 * scoped, `null` when the fact is global (e.g. trial decisions live
 * at academy scope, not per-player).
 */
final class Fact {

    public string $key;
    public string $tableName;
    public string $tableAlias;
    public string $label;
    /** @var Dimension[] */
    public array $dimensions;
    /** @var Measure[] */
    public array $measures;
    public DateTimeColumn $timeColumn;
    public ?string $entityScope;

    /**
     * @param Dimension[] $dimensions
     * @param Measure[]   $measures
     */
    public function __construct(
        string $key,
        string $tableName,
        string $label,
        array $dimensions,
        array $measures,
        DateTimeColumn $timeColumn,
        ?string $entityScope = null,
        string $tableAlias = 'f'
    ) {
        $this->key         = $key;
        $this->tableName   = $tableName;
        $this->tableAlias  = $tableAlias;
        $this->label       = $label;
        $this->dimensions  = $dimensions;
        $this->measures    = $measures;
        $this->timeColumn  = $timeColumn;
        $this->entityScope = $entityScope;
    }

    public function dimension( string $key ): ?Dimension {
        foreach ( $this->dimensions as $d ) {
            if ( $d->key === $key ) return $d;
        }
        return null;
    }

    public function measure( string $key ): ?Measure {
        foreach ( $this->measures as $m ) {
            if ( $m->key === $key ) return $m;
        }
        return null;
    }
}
