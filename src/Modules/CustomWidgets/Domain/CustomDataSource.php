<?php
namespace TT\Modules\CustomWidgets\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomDataSource — contract for the data layer behind admin-authored
 * custom widgets (#0078 Phase 1).
 *
 * Per spec decision 1: registered data-source classes only — no
 * free-text SQL, no visual SQL builder. Admins configure (filters,
 * columns, label, format) but cannot author the underlying query.
 *
 * Each implementation declares its columns, filters, and aggregations
 * via metadata. The builder UI (Phase 3) reads the metadata to render
 * the column / filter / aggregation pickers; the rendering engine
 * (Phase 4) calls `fetch()` with the operator's choices and produces
 * the widget.
 *
 * **Tenancy enforcement**. Every implementation MUST scope to the
 * current `club_id` + apply demo-mode scope inside `fetch()`. The
 * registry doesn't enforce — it can't know which `tt_*` table this
 * source reads. The `CustomDataSourceTestKit` (Phase 5 follow-up)
 * runs assertion tests against every registered source: pass a
 * known multi-club fixture, verify the source returns only rows
 * for the current club.
 *
 * **No cross-source joins** (per spec out-of-scope). Each widget
 * reads exactly one data source. Operators wanting joined data ask
 * for a new data source class.
 */
interface CustomDataSource {

    /**
     * Stable id, e.g. `'players_active'`. Used as foreign key in
     * `tt_custom_widgets.data_source_id`. Snake_case.
     */
    public function id(): string;

    /**
     * Human-readable label for the builder UI's data-source picker.
     * Translatable.
     */
    public function label(): string;

    /**
     * Columns the source exposes. The builder UI renders one
     * checkbox per column; widgets persist the chosen subset.
     *
     * Each entry shape:
     *   [
     *     'key'   => 'string column id',
     *     'label' => 'human label (translatable)',
     *     'kind'  => 'string' | 'int' | 'float' | 'date' | 'pill',
     *   ]
     *
     * The `kind` drives column formatting in table renders + the
     * builder's preview.
     *
     * @return list<array{key:string,label:string,kind:string}>
     */
    public function columns(): array;

    /**
     * Filter declarations. Each entry shape:
     *   [
     *     'key'   => 'string filter id',
     *     'label' => 'human label (translatable)',
     *     'kind'  => 'date_range' | 'team' | 'player' | 'enum' | 'season',
     *     ...    // kind-specific extras (e.g. 'enum' carries `'options' => [...]`)
     *   ]
     *
     * The builder UI renders one input per filter; widgets persist
     * the chosen values in `definition.filters`.
     *
     * @return list<array<string,mixed>>
     */
    public function filters(): array;

    /**
     * Fetch rows respecting tenancy + filters + the requested column
     * subset. Returns `list<array<string,mixed>>` keyed by column id.
     *
     * Implementations MUST:
     *   - filter by current `club_id` via `CurrentClub::id()`,
     *   - apply demo-mode scope via the existing scope helpers,
     *   - validate filter values against the declared `filters()`
     *     metadata (drop unknown keys; coerce types).
     *
     * `$user_id` lets sources that need it (e.g. "my players")
     * scope further; sources that don't simply ignore it.
     *
     * `$limit` is a hard cap from the renderer (table widgets pass
     * a small N for top-N tables; KPI widgets pass 1).
     *
     * @param int                              $user_id
     * @param array<string,mixed>              $filters
     * @param string[]                         $column_keys
     * @param int                              $limit
     * @return list<array<string,mixed>>
     */
    public function fetch( int $user_id, array $filters, array $column_keys, int $limit = 100 ): array;

    /**
     * Aggregations the source exposes for KPI / bar / line widgets.
     * Each entry shape:
     *   [
     *     'key'    => 'string aggregation id',
     *     'label'  => 'human label (translatable)',
     *     'kind'   => 'count' | 'avg' | 'sum' | 'distinct',
     *     'column' => 'optional column key the aggregation runs over',
     *   ]
     *
     * Used by KPI widgets (single number) + bar / line widgets
     * (one aggregated value per group).
     *
     * @return list<array<string,mixed>>
     */
    public function aggregations(): array;
}
