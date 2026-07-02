<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\PhasesRepository;

/**
 * PhasesRestController (#2229) —
 * /wp-json/talenttrack/v1/methodology/phases
 *
 * Full CRUD for the framework primer's phases on
 * AbstractMethodologyRestController, sharing the PhasesRepository +
 * MultilingualField + MethodologyEnums domain layer the manage tab uses,
 * so a future SaaS front end gets identical answers (§4).
 *
 * Phases are children of the active framework primer: the list resolves
 * the club's primer, and create attaches new rows to it. Shipped rows are
 * read-only reference content — update / delete refuse them with a 409.
 *
 * Routes (inherited shape):
 *   GET    /methodology/phases        list (active primer, non-archived)
 *   POST   /methodology/phases        create a club-authored phase
 *   GET    /methodology/phases/{id}   one phase, NL + EN decoded
 *   PUT    /methodology/phases/{id}   edit a club-authored phase
 *   DELETE /methodology/phases/{id}   delete a club-authored phase
 */
final class PhasesRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/phases';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::ok( [ 'phases' => [] ] );
        }
        $rows = ( new PhasesRepository() )->listForPrimer( (int) $primer->id );
        return self::ok( [ 'phases' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new PhasesRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'phase_not_found', __( 'Phase not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        if ( ! MethodologyEnums::isValidSide( sanitize_key( (string) ( $r['side'] ?? '' ) ) ) ) {
            return self::fail( 'invalid_side', __( 'Invalid side.', 'talenttrack' ), 400 );
        }

        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::fail( 'no_primer', __( 'Author the framework primer before adding phases.', 'talenttrack' ), 409 );
        }

        $payload = self::writePayload( $r );
        $payload['primer_id']  = (int) $primer->id;
        $payload['is_shipped'] = 0;

        $id = ( new PhasesRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_phase.create.failed', [ 'primer_id' => (int) $primer->id ] );
            return self::fail( 'db_error', __( 'Could not save the phase.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new PhasesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'phase_not_found', __( 'Phase not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'phase_shipped', __( 'Shipped phases are read-only. Clone the primer to edit.', 'talenttrack' ), 409 );
        }
        if ( $r->has_param( 'side' ) && ! MethodologyEnums::isValidSide( sanitize_key( (string) $r['side'] ) ) ) {
            return self::fail( 'invalid_side', __( 'Invalid side.', 'talenttrack' ), 400 );
        }

        $ok = $repo->update( $id, self::writePayload( $r, true ) );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new PhasesRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'phase_not_found', __( 'Phase not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'phase_shipped', __( 'Shipped phases cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];

        if ( ! $partial || $r->has_param( 'side' ) ) {
            $out['side'] = sanitize_key( (string) ( $r['side'] ?? '' ) );
        }
        if ( ! $partial || $r->has_param( 'phase_number' ) ) {
            $out['phase_number'] = max( 1, min( 4, (int) ( $r['phase_number'] ?? 1 ) ) );
        }
        if ( ! $partial || $r->has_param( 'title' ) ) {
            $title = is_array( $r['title'] ?? null ) ? $r['title'] : [];
            $out['title_json'] = MultilingualField::encode( [
                'nl' => sanitize_text_field( is_string( $title['nl'] ?? null ) ? $title['nl'] : '' ),
                'en' => sanitize_text_field( is_string( $title['en'] ?? null ) ? $title['en'] : '' ),
            ] );
        }
        if ( ! $partial || $r->has_param( 'goal' ) ) {
            $goal = is_array( $r['goal'] ?? null ) ? $r['goal'] : [];
            $out['goal_json'] = MultilingualField::encode( [
                'nl' => sanitize_textarea_field( is_string( $goal['nl'] ?? null ) ? $goal['nl'] : '' ),
                'en' => sanitize_textarea_field( is_string( $goal['en'] ?? null ) ? $goal['en'] : '' ),
            ] );
        }

        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function shape( object $p, bool $full = false ): array {
        $out = [
            'id'           => (int) $p->id,
            'primer_id'    => (int) $p->primer_id,
            'side'         => (string) $p->side,
            'phase_number' => (int) $p->phase_number,
            'is_shipped'   => ! empty( $p->is_shipped ),
            'title'        => MultilingualField::string( $p->title_json ),
            'goal'         => MultilingualField::string( $p->goal_json ),
        ];
        if ( $full ) {
            $out['title_i18n'] = MultilingualField::decode( $p->title_json ) ?: (object) [];
            $out['goal_i18n']  = MultilingualField::decode( $p->goal_json ) ?: (object) [];
        }
        return $out;
    }
}
