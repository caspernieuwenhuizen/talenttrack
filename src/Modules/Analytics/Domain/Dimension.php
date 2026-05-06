<?php
namespace TT\Modules\Analytics\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Dimension — a column you can group by or filter on
 * (#0083 Child 1).
 *
 * One dimension per "thing you can ask about" inside a fact: team,
 * age group, position, season, month, etc. The fact registry lives
 * or dies by how granular these are — every dimension you don't
 * register is a question someone can't ask.
 *
 * Dimension types govern how the query engine renders the column
 * in `GROUP BY` and how filter values are validated:
 *
 *   - `foreign_key` — references another tt_* table (`tt_players`,
 *      `tt_teams`). The query engine joins by id; the explorer
 *      humanises the value via the foreign table's display name.
 *   - `lookup` — the value lives in a `tt_lookups` row keyed by
 *      `lookup_type` (positions, age groups, attendance statuses).
 *      Filter values are lookup-key strings.
 *   - `enum` — the value is a fixed string (e.g. `'present'`, `'absent'`).
 *      The fact declaration must inline the allowed values.
 *   - `date_range` — the value is a derived date bucket (`'2026-04'`,
 *      `'2025/26'`). `sqlExpression` is mandatory and emits the
 *      bucketing expression (e.g. `DATE_FORMAT(start_at, '%Y-%m')`).
 */
final class Dimension {

    public const TYPE_FOREIGN_KEY = 'foreign_key';
    public const TYPE_LOOKUP      = 'lookup';
    public const TYPE_ENUM        = 'enum';
    public const TYPE_DATE_RANGE  = 'date_range';

    public string $key;
    public string $label;
    public string $type;
    public ?string $foreignTable;
    public ?string $lookupType;
    public ?string $sqlExpression;

    /**
     * @param string $key             Stable key used in queries + URL state. Snake-case.
     * @param string $label           Human-readable label (translatable).
     * @param string $type            One of TYPE_* constants.
     * @param string|null $foreignTable  For `foreign_key` types: the referenced tt_* table.
     * @param string|null $lookupType    For `lookup` types: the `tt_lookups.lookup_type` key.
     * @param string|null $sqlExpression Override the column expression. Mandatory for
     *                                   `date_range`. Optional for others (defaults to
     *                                   `<table_alias>.<key>`).
     */
    public function __construct(
        string $key,
        string $label,
        string $type,
        ?string $foreignTable = null,
        ?string $lookupType = null,
        ?string $sqlExpression = null
    ) {
        $this->key           = $key;
        $this->label         = $label;
        $this->type          = $type;
        $this->foreignTable  = $foreignTable;
        $this->lookupType    = $lookupType;
        $this->sqlExpression = $sqlExpression;
    }
}
