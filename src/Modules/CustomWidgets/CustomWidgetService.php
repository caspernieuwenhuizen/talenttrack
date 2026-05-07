<?php
namespace TT\Modules\CustomWidgets;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Audit\AuditService;
use TT\Modules\CustomWidgets\Cache\CustomWidgetCache;
use TT\Modules\CustomWidgets\Domain\CustomWidget;
use TT\Modules\CustomWidgets\Repository\CustomWidgetRepository;

/**
 * CustomWidgetService (#0078 Phase 2) — orchestrates create / update /
 * archive on top of the repository.
 *
 * Responsibilities:
 *   - validate the data-source id against `CustomDataSourceRegistry`,
 *   - validate the chart type against `CustomWidget::CHART_TYPES`,
 *   - validate the definition shape (columns, filters, aggregation,
 *     cache_ttl_minutes) against the source's declared metadata,
 *   - normalise the values that survived validation into the repository's
 *     expected shape.
 *
 * Validation throws a discriminated `CustomWidgetException` so the REST
 * controller can map kinds → HTTP statuses without knowing what each
 * validation rule did.
 *
 * Phase 5 will hook the cache-flush + audit-log calls; this class is
 * the natural seam.
 */
final class CustomWidgetService {

    private CustomWidgetRepository $repo;

    public function __construct( ?CustomWidgetRepository $repo = null ) {
        $this->repo = $repo ?? new CustomWidgetRepository();
    }

    /**
     * @return CustomWidget[]
     */
    public function listAll( bool $include_archived = false ): array {
        return $this->repo->listForClub( $include_archived );
    }

    public function findByIdOrUuid( string $key ): ?CustomWidget {
        if ( ctype_digit( $key ) ) {
            return $this->repo->findById( (int) $key );
        }
        return $this->repo->findByUuid( $key );
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function create( array $payload, int $user_id ): CustomWidget {
        [ $name, $source_id, $chart_type, $definition ] = $this->validatePayload( $payload );
        $widget = $this->repo->create( $name, $source_id, $chart_type, $definition, $user_id );
        $this->audit( 'custom_widget.created', $widget );
        return $widget;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function update( int $id, array $payload, int $user_id ): CustomWidget {
        $existing = $this->repo->findById( $id );
        if ( $existing === null ) {
            throw new CustomWidgetException( 'not_found', __( 'Custom widget not found.', 'talenttrack' ) );
        }
        [ $name, $source_id, $chart_type, $definition ] = $this->validatePayload( $payload );
        $updated = $this->repo->update( $id, $name, $source_id, $chart_type, $definition, $user_id );
        if ( $updated === null ) {
            throw new CustomWidgetException( 'not_found', __( 'Custom widget not found after update.', 'talenttrack' ) );
        }
        // Phase 5 — invalidate the per-widget cache on every update,
        // so a saved tweak surfaces on the next dashboard render.
        CustomWidgetCache::flush( $updated->uuid );
        $this->audit( 'custom_widget.updated', $updated );
        return $updated;
    }

    public function archive( int $id, int $user_id ): bool {
        $existing = $this->repo->findById( $id );
        if ( $existing === null ) {
            throw new CustomWidgetException( 'not_found', __( 'Custom widget not found.', 'talenttrack' ) );
        }
        $ok = $this->repo->softDelete( $id, $user_id );
        if ( $ok ) {
            CustomWidgetCache::flush( $existing->uuid );
            $this->audit( 'custom_widget.archived', $existing );
        }
        return $ok;
    }

    /**
     * Audit-log wrapper. Silently no-ops when the AuditService isn't
     * available (very early in the request lifecycle, or on installs
     * with the audit_log feature disabled).
     */
    private function audit( string $action, CustomWidget $widget ): void {
        if ( ! class_exists( AuditService::class ) ) return;
        try {
            ( new AuditService() )->record( $action, 'custom_widget', $widget->id, [
                'uuid'           => $widget->uuid,
                'name'           => $widget->name,
                'data_source_id' => $widget->dataSourceId,
                'chart_type'     => $widget->chartType,
            ] );
        } catch ( \Throwable $e ) {
            // Audit failure must never block the operator's action.
        }
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{0:string,1:string,2:string,3:array<string,mixed>}
     */
    private function validatePayload( array $payload ): array {
        $name = isset( $payload['name'] ) ? trim( (string) $payload['name'] ) : '';
        if ( $name === '' || mb_strlen( $name ) > 120 ) {
            throw new CustomWidgetException( 'bad_name', __( 'Name is required and must be 120 characters or fewer.', 'talenttrack' ) );
        }

        $source_id = isset( $payload['data_source_id'] ) ? sanitize_key( (string) $payload['data_source_id'] ) : '';
        $source = CustomDataSourceRegistry::find( $source_id );
        if ( $source === null ) {
            throw new CustomWidgetException( 'unknown_data_source', __( 'Unknown data source.', 'talenttrack' ) );
        }

        $chart_type = isset( $payload['chart_type'] ) ? (string) $payload['chart_type'] : '';
        if ( ! in_array( $chart_type, CustomWidget::CHART_TYPES, true ) ) {
            throw new CustomWidgetException( 'invalid_chart_type', __( 'Invalid chart type.', 'talenttrack' ) );
        }

        $definition_raw = isset( $payload['definition'] ) && is_array( $payload['definition'] ) ? $payload['definition'] : [];
        $definition = $this->normaliseDefinition( $definition_raw, $source, $chart_type );

        return [ $name, $source_id, $chart_type, $definition ];
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<string,mixed>
     */
    private function normaliseDefinition( array $raw, $source, string $chart_type ): array {
        // Columns — must intersect the source's declared columns. KPI
        // widgets ignore columns entirely (they show a single number).
        $declared_column_keys = array_column( $source->columns(), 'key' );
        $picked = isset( $raw['columns'] ) && is_array( $raw['columns'] ) ? $raw['columns'] : [];
        $columns = [];
        foreach ( $picked as $col ) {
            $col = sanitize_key( (string) $col );
            if ( $col !== '' && in_array( $col, $declared_column_keys, true ) ) {
                $columns[] = $col;
            }
        }
        if ( $chart_type === CustomWidget::CHART_TABLE && empty( $columns ) ) {
            throw new CustomWidgetException( 'missing_columns', __( 'Pick at least one column to show.', 'talenttrack' ) );
        }

        // Filters — drop unknown keys; preserve declared ones.
        $declared_filter_keys = array_column( $source->filters(), 'key' );
        $filters_in = isset( $raw['filters'] ) && is_array( $raw['filters'] ) ? $raw['filters'] : [];
        $filters = [];
        foreach ( $filters_in as $key => $value ) {
            $key = sanitize_key( (string) $key );
            if ( $key !== '' && in_array( $key, $declared_filter_keys, true ) ) {
                $filters[ $key ] = is_array( $value )
                    ? array_map( static fn( $v ) => sanitize_text_field( (string) $v ), $value )
                    : sanitize_text_field( (string) $value );
            }
        }

        // Aggregation — mandatory for KPI / bar / line.
        $aggregation = null;
        $declared_agg_keys = array_column( $source->aggregations(), 'key' );
        if ( in_array( $chart_type, [ CustomWidget::CHART_KPI, CustomWidget::CHART_BAR, CustomWidget::CHART_LINE ], true ) ) {
            $agg_in = isset( $raw['aggregation'] ) && is_array( $raw['aggregation'] ) ? $raw['aggregation'] : [];
            $agg_key = isset( $agg_in['key'] ) ? sanitize_key( (string) $agg_in['key'] ) : '';
            if ( $agg_key === '' || ! in_array( $agg_key, $declared_agg_keys, true ) ) {
                throw new CustomWidgetException( 'missing_aggregation', __( 'Pick an aggregation for this chart type.', 'talenttrack' ) );
            }
            $aggregation = [
                'key'    => $agg_key,
                'kind'   => isset( $agg_in['kind'] ) ? sanitize_key( (string) $agg_in['kind'] ) : '',
                'column' => isset( $agg_in['column'] ) ? sanitize_key( (string) $agg_in['column'] ) : '',
            ];
        }

        // Format hints — copied through; Phase 4 reads them at render
        // time. Validation here is shape-only (must be associative).
        $format = isset( $raw['format'] ) && is_array( $raw['format'] ) ? $raw['format'] : [];

        // Cache TTL — bounded to a sane range so a typo doesn't disable
        // the cache entirely. Phase 5 honours the value.
        $ttl = isset( $raw['cache_ttl_minutes'] ) ? (int) $raw['cache_ttl_minutes'] : 5;
        if ( $ttl < 0 ) $ttl = 5;
        if ( $ttl > 1440 ) $ttl = 1440; // 24h ceiling.

        return [
            'columns'           => $columns,
            'filters'           => $filters,
            'aggregation'       => $aggregation,
            'format'            => $format,
            'cache_ttl_minutes' => $ttl,
        ];
    }
}
