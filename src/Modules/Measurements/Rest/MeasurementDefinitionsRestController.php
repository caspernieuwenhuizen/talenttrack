<?php
namespace TT\Modules\Measurements\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Archive\ArchiveRepository;
use TT\Infrastructure\Archive\DeleteBlockedException;
use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Measurements\Levels\MeasurementLevelPalette;
use TT\Modules\Measurements\Repositories\MeasurementDefinitionsRepository;
use TT\Modules\Measurements\Repositories\MeasurementLevelsRepository;
use TT\Modules\Measurements\Repositories\MeasurementTargetsRepository;

/**
 * MeasurementDefinitionsRestController (#2120) —
 * /wp-json/talenttrack/v1/measurement-definitions
 *
 * The resource-oriented SaaS contract for the test catalogue (CLAUDE.md §4):
 * a test definition is fully CRUD-able over REST, sharing the same domain
 * layer (the definitions + targets repositories) the Configure view consumes,
 * so a future non-WordPress front end gets identical answers.
 *
 * Routes:
 *   GET    /measurement-definitions               list (?include_inactive=1)
 *   POST   /measurement-definitions               create
 *   GET    /measurement-definitions/{id}          single, with its targets
 *   PUT    /measurement-definitions/{id}          edit
 *   POST   /measurement-definitions/{id}/targets  upsert one age-group band
 *   DELETE /measurement-definitions/{id}          soft-archive
 *   DELETE /measurement-definitions/{id}/permanent  hard-delete (recycle-bin gated)
 *
 * Permission model (no role-string compare): every callback resolves through
 * MatrixGate on the `measurement_definitions` entity — `read` for GET,
 * `change` for edits, `create_delete` for create / archive. The permanent
 * delete re-gates onto `tt_manage_recycle_bin` so no purge path is weaker
 * than the bin's own (mirrors TestTrainingsRestController, #2024).
 */
class MeasurementDefinitionsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/measurement-definitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_definitions' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create_definition' ],
                'permission_callback' => [ __CLASS__, 'can_create_delete' ],
            ],
        ] );

        register_rest_route( self::NS, '/measurement-definitions/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_definition' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'PUT',
                'callback'            => [ __CLASS__, 'update_definition' ],
                'permission_callback' => [ __CLASS__, 'can_change' ],
            ],
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'archive_definition' ],
                'permission_callback' => [ __CLASS__, 'can_create_delete' ],
            ],
        ] );

        register_rest_route( self::NS, '/measurement-definitions/(?P<id>\d+)/targets', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'upsert_target' ],
                'permission_callback' => [ __CLASS__, 'can_change' ],
            ],
        ] );

        // #2138 — operator-defined coloured levels for a status-type test.
        register_rest_route( self::NS, '/measurement-definitions/(?P<id>\d+)/levels', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list_levels' ],
                'permission_callback' => [ __CLASS__, 'can_read' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'upsert_levels' ],
                'permission_callback' => [ __CLASS__, 'can_change' ],
            ],
        ] );

        // #2024 security — purge path no weaker than the recycle bin's own.
        register_rest_route( self::NS, '/measurement-definitions/(?P<id>\d+)/permanent', [
            [
                'methods'             => 'DELETE',
                'callback'            => [ __CLASS__, 'delete_permanently' ],
                'permission_callback' => static function () { return current_user_can( 'tt_manage_recycle_bin' ); },
            ],
        ] );
    }

    // ── permission helpers ──────────────────────────────────────────

    public static function can_read(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'measurement_definitions', 'read' );
    }

    public static function can_change(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'measurement_definitions', 'change' );
    }

    public static function can_create_delete(): bool {
        return MatrixGate::canAnyScope( get_current_user_id(), 'measurement_definitions', 'create_delete' );
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_definitions( \WP_REST_Request $r ): \WP_REST_Response {
        $include_inactive = (bool) absint( $r['include_inactive'] ?? 0 );
        $defs = ( new MeasurementDefinitionsRepository() )->listAll( $include_inactive );
        $out  = array_map( [ __CLASS__, 'shape_definition' ], $defs );
        return RestResponse::success( [ 'definitions' => $out ] );
    }

    public static function get_definition( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $def = ( new MeasurementDefinitionsRepository() )->find( $id );
        if ( ! $def ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }
        $targets = ( new MeasurementTargetsRepository() )->listForDefinition( $id );
        $levels  = ( new MeasurementLevelsRepository() )->listForDefinition( $id );
        $payload = self::shape_definition( $def );
        $payload['targets'] = array_map( [ __CLASS__, 'shape_target' ], $targets );
        $payload['levels']  = array_map( [ __CLASS__, 'shape_level' ], $levels );
        return RestResponse::success( $payload );
    }

    // ── levels (status value type, #2138) ───────────────────────────

    public static function list_levels( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( ! ( new MeasurementDefinitionsRepository() )->find( $id ) ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }
        $levels = ( new MeasurementLevelsRepository() )->listForDefinition( $id );
        return RestResponse::success( [
            'definition_id' => $id,
            'levels'        => array_map( [ __CLASS__, 'shape_level' ], $levels ),
        ] );
    }

    /**
     * Replace a status test's full level set from an ordered list. The
     * row position is the ordinal (worse → better), so a recorded status
     * snapshots a meaningful numeric rank alongside its label.
     */
    public static function upsert_levels( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid test id.', 'talenttrack' ), 400 );
        }
        if ( ! ( new MeasurementDefinitionsRepository() )->find( $id ) ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }

        $raw = $r['levels'] ?? [];
        if ( ! is_array( $raw ) ) {
            return RestResponse::error( 'bad_levels', __( 'Levels must be a list.', 'talenttrack' ), 400 );
        }

        $rows = [];
        foreach ( $raw as $row ) {
            if ( ! is_array( $row ) ) continue;
            $label = sanitize_text_field( (string) ( $row['label'] ?? '' ) );
            if ( $label === '' ) continue;
            $rows[] = [
                'id'          => isset( $row['id'] ) ? absint( $row['id'] ) : 0,
                'label'       => $label,
                'color_token' => MeasurementLevelPalette::safe( sanitize_text_field( (string) ( $row['color_token'] ?? '' ) ) ),
            ];
        }

        $kept   = ( new MeasurementLevelsRepository() )->replaceForDefinition( $id, $rows );
        $levels = ( new MeasurementLevelsRepository() )->listForDefinition( $id );
        return RestResponse::success( [
            'definition_id' => $id,
            'count'         => $kept,
            'levels'        => array_map( [ __CLASS__, 'shape_level' ], $levels ),
        ] );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_definition( \WP_REST_Request $r ): \WP_REST_Response {
        $name = sanitize_text_field( (string) ( $r['name'] ?? '' ) );
        if ( $name === '' ) {
            return RestResponse::error( 'missing_name', __( 'A test needs a name.', 'talenttrack' ), 400 );
        }

        $id = ( new MeasurementDefinitionsRepository() )->create( self::write_payload( $r, $name ) );
        if ( $id <= 0 ) {
            Logger::error( 'measurement_definition.create.failed', [ 'name' => $name ] );
            return RestResponse::error( 'db_error', __( 'Could not save the test.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $id ], 201 );
    }

    public static function update_definition( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid test id.', 'talenttrack' ), 400 );
        }
        $repo = new MeasurementDefinitionsRepository();
        if ( ! $repo->find( $id ) ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }

        $data = [];
        foreach ( [ 'category_id', 'value_type', 'unit', 'frequency', 'direction', 'scale_min', 'scale_max', 'is_active', 'show_on_profile', 'sort_order' ] as $k ) {
            if ( $r->has_param( $k ) ) {
                $data[ $k ] = is_string( $r[ $k ] ) ? sanitize_text_field( (string) $r[ $k ] ) : $r[ $k ];
            }
        }
        if ( $r->has_param( 'name' ) ) {
            $name = sanitize_text_field( (string) $r['name'] );
            if ( $name === '' ) {
                return RestResponse::error( 'missing_name', __( 'A test needs a name.', 'talenttrack' ), 400 );
            }
            $data['name'] = $name;
        }

        $ok = $repo->update( $id, $data );
        return RestResponse::success( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function upsert_target( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid test id.', 'talenttrack' ), 400 );
        }
        if ( ! ( new MeasurementDefinitionsRepository() )->find( $id ) ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }

        $age_group = sanitize_text_field( (string) ( $r['age_group'] ?? '' ) );
        if ( $age_group === '' ) {
            return RestResponse::error( 'missing_age_group', __( 'An age group is required for a target.', 'talenttrack' ), 400 );
        }

        $target_id = ( new MeasurementTargetsRepository() )->upsert( $id, $age_group, [
            'green_min' => $r['green_min'] ?? null,
            'green_max' => $r['green_max'] ?? null,
            'amber_min' => $r['amber_min'] ?? null,
            'amber_max' => $r['amber_max'] ?? null,
        ] );
        if ( $target_id <= 0 ) {
            Logger::error( 'measurement_target.upsert.failed', [ 'definition_id' => $id, 'age_group' => $age_group ] );
            return RestResponse::error( 'db_error', __( 'Could not save the target.', 'talenttrack' ), 500 );
        }
        return RestResponse::success( [ 'id' => $target_id, 'definition_id' => $id, 'age_group' => $age_group ] );
    }

    /** Soft-archive: stamps archived_at / archived_by (active → archived). */
    public static function archive_definition( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid test id.', 'talenttrack' ), 400 );
        }
        if ( ! ( new MeasurementDefinitionsRepository() )->find( $id ) ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }
        $n = ( new ArchiveRepository() )->archive( 'measurement_definition', [ $id ], get_current_user_id() );
        return RestResponse::success( [ 'archived' => $n > 0, 'id' => $id ] );
    }

    /** Hard-delete (irreversible). Gated on tt_manage_recycle_bin. */
    public static function delete_permanently( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid test id.', 'talenttrack' ), 400 );
        }
        try {
            $n = ( new ArchiveRepository() )->deletePermanently( 'measurement_definition', [ $id ] );
        } catch ( DeleteBlockedException $e ) {
            return RestResponse::error( 'delete_blocked', $e->getMessage(), 409 );
        }
        if ( $n === 0 ) {
            return RestResponse::notFound( 'definition_not_found', __( 'Test not found.', 'talenttrack' ) );
        }
        return RestResponse::success( [ 'deleted' => true, 'id' => $id ] );
    }

    // ── shaping ─────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function write_payload( \WP_REST_Request $r, string $name ): array {
        return [
            'category_id' => absint( $r['category_id'] ?? 0 ),
            'name'        => $name,
            'value_type'  => sanitize_text_field( (string) ( $r['value_type'] ?? 'numeric' ) ),
            'unit'        => sanitize_text_field( (string) ( $r['unit'] ?? '' ) ),
            'scale_min'   => $r->has_param( 'scale_min' ) ? $r['scale_min'] : null,
            'scale_max'   => $r->has_param( 'scale_max' ) ? $r['scale_max'] : null,
            'frequency'   => sanitize_text_field( (string) ( $r['frequency'] ?? 'adhoc' ) ),
            'direction'   => sanitize_text_field( (string) ( $r['direction'] ?? 'higher' ) ),
            'is_active'   => $r->has_param( 'is_active' ) ? (int) (bool) $r['is_active'] : 1,
            'show_on_profile' => $r->has_param( 'show_on_profile' ) ? (int) (bool) $r['show_on_profile'] : 1,
            'sort_order'  => absint( $r['sort_order'] ?? 0 ),
        ];
    }

    /** @return array<string, mixed> */
    private static function shape_definition( object $d ): array {
        return [
            'id'          => (int) $d->id,
            'category_id' => (int) ( $d->category_id ?? 0 ),
            'category'    => (string) ( $d->category_label ?? $d->category_name ?? '' ),
            'name'        => (string) $d->name,
            'value_type'  => (string) $d->value_type,
            'unit'        => $d->unit !== null ? (string) $d->unit : null,
            'scale_min'   => $d->scale_min !== null ? (float) $d->scale_min : null,
            'scale_max'   => $d->scale_max !== null ? (float) $d->scale_max : null,
            'frequency'   => (string) $d->frequency,
            'direction'   => (string) $d->direction,
            'is_active'   => (int) $d->is_active === 1,
            'show_on_profile' => (int) ( $d->show_on_profile ?? 1 ) === 1,
            'sort_order'  => (int) $d->sort_order,
        ];
    }

    /** @return array<string, mixed> */
    private static function shape_target( object $t ): array {
        return [
            'id'        => (int) $t->id,
            'age_group' => (string) $t->age_group,
            'green_min' => $t->green_min !== null ? (float) $t->green_min : null,
            'green_max' => $t->green_max !== null ? (float) $t->green_max : null,
            'amber_min' => $t->amber_min !== null ? (float) $t->amber_min : null,
            'amber_max' => $t->amber_max !== null ? (float) $t->amber_max : null,
        ];
    }

    /** @return array<string, mixed> */
    private static function shape_level( object $l ): array {
        return [
            'id'          => (int) $l->id,
            'label'       => (string) $l->label,
            'color_token' => MeasurementLevelPalette::safe( (string) $l->color_token ),
            'ordinal'     => (int) $l->ordinal,
        ];
    }
}
