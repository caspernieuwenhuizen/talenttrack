<?php
namespace TT\Modules\CustomWidgets\Domain;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CustomWidget — immutable value object representing one row of
 * `tt_custom_widgets` (#0078 Phase 2).
 *
 * The `definition` array is the operator's authoring choices,
 * shape:
 *   [
 *     'columns'           => string[],            // column keys the widget shows
 *     'filters'           => array<string,mixed>, // per-filter values
 *     'aggregation'       => array{key:string,kind:string,column?:string} | null,
 *     'format'            => array<string,mixed>, // per-column format hints (Phase 4)
 *     'cache_ttl_minutes' => int,                 // Phase 5 honours it
 *   ]
 *
 * The shape is loosely-typed in the value object so additive evolution
 * (new format hints, new aggregation kinds) doesn't require a schema
 * migration; the service-layer validator enforces the invariants the
 * builder UI relies on.
 */
final class CustomWidget {

    public const CHART_TABLE = 'table';
    public const CHART_KPI   = 'kpi';
    public const CHART_BAR   = 'bar';
    public const CHART_LINE  = 'line';

    /** @var string[] */
    public const CHART_TYPES = [ self::CHART_TABLE, self::CHART_KPI, self::CHART_BAR, self::CHART_LINE ];

    public int $id;
    public int $clubId;
    public string $uuid;
    public string $name;
    public string $dataSourceId;
    public string $chartType;

    /** @var array<string,mixed> */
    public array $definition;

    public ?int $createdBy;
    public ?int $updatedBy;
    public string $createdAt;
    public ?string $updatedAt;
    public ?string $archivedAt;

    /**
     * @param array<string,mixed> $definition
     */
    public function __construct(
        int $id,
        int $clubId,
        string $uuid,
        string $name,
        string $dataSourceId,
        string $chartType,
        array $definition,
        ?int $createdBy = null,
        ?int $updatedBy = null,
        string $createdAt = '',
        ?string $updatedAt = null,
        ?string $archivedAt = null
    ) {
        $this->id           = $id;
        $this->clubId       = $clubId;
        $this->uuid         = $uuid;
        $this->name         = $name;
        $this->dataSourceId = $dataSourceId;
        $this->chartType    = $chartType;
        $this->definition   = $definition;
        $this->createdBy    = $createdBy;
        $this->updatedBy    = $updatedBy;
        $this->createdAt    = $createdAt;
        $this->updatedAt    = $updatedAt;
        $this->archivedAt   = $archivedAt;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array {
        return [
            'id'             => $this->id,
            'uuid'           => $this->uuid,
            'name'           => $this->name,
            'data_source_id' => $this->dataSourceId,
            'chart_type'     => $this->chartType,
            'definition'     => $this->definition,
            'created_by'     => $this->createdBy,
            'updated_by'     => $this->updatedBy,
            'created_at'     => $this->createdAt,
            'updated_at'     => $this->updatedAt,
            'archived_at'    => $this->archivedAt,
        ];
    }
}
