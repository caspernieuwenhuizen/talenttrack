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
 *
 * Row scope (#4568): a fact names the matrix entity it is read under and how
 * a team-scoped reader is narrowed: by `teamColumn` (the row's team), or by
 * `playerColumn` (the player's current team). A fact with neither cannot be
 * narrowed and is read only with club-wide scope. `visibilityColumn` narrows
 * journey rows to the levels the reader holds.
 *
 * Dimension keys are a contract (#4860): saved blocks, explorer links and
 * filters name them. A key is never renamed in place. A deliberate rename
 * declares the old key in `aliases` (old key => new key), so a stored
 * reference keeps reading the same rows; `FactQuery` resolves an alias
 * before it validates, and refuses any key that is neither declared nor
 * aliased. `tests/php/FactKeyContractTest.php` pins every fact's keys.
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
    public string $readEntity;
    public ?string $teamColumn;
    public ?string $playerColumn;
    public ?string $visibilityColumn;
    /** @var array<string,string> retired dimension key => the key it was renamed to */
    public array $aliases;

    /**
     * @param Dimension[]          $dimensions
     * @param Measure[]            $measures
     * @param array<string,string> $aliases old dimension key => new key
     */
    public function __construct(
        string $key,
        string $tableName,
        string $label,
        array $dimensions,
        array $measures,
        DateTimeColumn $timeColumn,
        ?string $entityScope = null,
        string $tableAlias = 'f',
        string $readEntity = '',
        ?string $teamColumn = null,
        ?string $playerColumn = null,
        ?string $visibilityColumn = null,
        array $aliases = []
    ) {
        $this->aliases          = $aliases;
        $this->key              = $key;
        $this->tableName        = $tableName;
        $this->tableAlias       = $tableAlias;
        $this->label            = $label;
        $this->dimensions       = $dimensions;
        $this->measures         = $measures;
        $this->timeColumn       = $timeColumn;
        $this->entityScope      = $entityScope;
        $this->readEntity       = $readEntity;
        $this->teamColumn       = $teamColumn;
        $this->playerColumn     = $playerColumn;
        $this->visibilityColumn = $visibilityColumn;
    }

    /** Can a team-scoped reader be narrowed to their own rows? */
    public function isScopable(): bool {
        return $this->teamColumn !== null || $this->playerColumn !== null;
    }

    /** The current key for a dimension key, following an alias once. */
    public function resolveKey( string $key ): string {
        return $this->aliases[ $key ] ?? $key;
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
