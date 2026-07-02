<?php
namespace TT\Modules\Methodology\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Modules\Methodology\Helpers\MultilingualField;
use TT\Modules\Methodology\MethodologyEnums;
use TT\Modules\Methodology\Repositories\FrameworkPrimerRepository;
use TT\Modules\Methodology\Repositories\LearningGoalsRepository;

/**
 * LearningGoalsRestController (#2229) —
 * /wp-json/talenttrack/v1/methodology/learning-goals
 *
 * Full CRUD for the framework primer's learning goals on
 * AbstractMethodologyRestController, sharing the LearningGoalsRepository +
 * MultilingualField + MethodologyEnums domain layer the manage tab uses,
 * so a future SaaS front end gets identical answers (§4).
 *
 * Learning goals are children of the active framework primer, optionally
 * grouped by side + teamtaak. Shipped rows are read-only reference content
 * — update / delete refuse them with a 409.
 *
 * Routes (inherited shape):
 *   GET    /methodology/learning-goals        list (active primer, side filter)
 *   POST   /methodology/learning-goals        create a club-authored goal
 *   GET    /methodology/learning-goals/{id}   one goal, NL + EN decoded
 *   PUT    /methodology/learning-goals/{id}   edit a club-authored goal
 *   DELETE /methodology/learning-goals/{id}   delete a club-authored goal
 */
final class LearningGoalsRestController extends AbstractMethodologyRestController {

    protected static function restBase(): string {
        return 'methodology/learning-goals';
    }

    // ── read ────────────────────────────────────────────────────────

    public static function list_items( \WP_REST_Request $r ): \WP_REST_Response {
        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::ok( [ 'learning_goals' => [] ] );
        }
        $side = $r->has_param( 'side' ) ? sanitize_key( (string) $r['side'] ) : null;
        $rows = ( new LearningGoalsRepository() )->listForPrimer( (int) $primer->id, $side );
        return self::ok( [ 'learning_goals' => array_map( [ self::class, 'shape' ], $rows ) ] );
    }

    public static function get_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id  = absint( $r['id'] );
        $row = ( new LearningGoalsRepository() )->find( $id );
        if ( ! $row ) {
            return self::notFound( 'learning_goal_not_found', __( 'Learning goal not found.', 'talenttrack' ) );
        }
        return self::ok( self::shape( $row, true ) );
    }

    // ── write ───────────────────────────────────────────────────────

    public static function create_item( \WP_REST_Request $r ): \WP_REST_Response {
        $error = self::validate( $r );
        if ( $error !== null ) return $error;

        $slug = sanitize_key( (string) ( $r['slug'] ?? '' ) );
        if ( $slug === '' ) {
            return self::fail( 'missing_slug', __( 'A learning goal needs a slug.', 'talenttrack' ), 400 );
        }

        $primer = ( new FrameworkPrimerRepository() )->activeForClub();
        if ( ! $primer ) {
            return self::fail( 'no_primer', __( 'Author the framework primer before adding learning goals.', 'talenttrack' ), 409 );
        }

        $payload = self::writePayload( $r );
        $payload['slug']       = $slug;
        $payload['primer_id']  = (int) $primer->id;
        $payload['is_shipped'] = 0;

        $id = ( new LearningGoalsRepository() )->create( $payload );
        if ( $id <= 0 ) {
            Logger::error( 'methodology_learning_goal.create.failed', [ 'slug' => $slug ] );
            return self::fail( 'db_error', __( 'Could not save the learning goal.', 'talenttrack' ), 500 );
        }
        return self::ok( [ 'id' => $id ], 201 );
    }

    public static function update_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new LearningGoalsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'learning_goal_not_found', __( 'Learning goal not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'learning_goal_shipped', __( 'Shipped learning goals are read-only. Clone the primer to edit.', 'talenttrack' ), 409 );
        }
        $error = self::validate( $r, true );
        if ( $error !== null ) return $error;

        $data = self::writePayload( $r, true );
        if ( $r->has_param( 'slug' ) ) {
            $slug = sanitize_key( (string) $r['slug'] );
            if ( $slug === '' ) {
                return self::fail( 'missing_slug', __( 'A learning goal needs a slug.', 'talenttrack' ), 400 );
            }
            $data['slug'] = $slug;
        }

        $ok = $repo->update( $id, $data );
        return self::ok( [ 'updated' => $ok, 'id' => $id ] );
    }

    public static function delete_item( \WP_REST_Request $r ): \WP_REST_Response {
        $id   = absint( $r['id'] );
        $repo = new LearningGoalsRepository();
        $row  = $repo->find( $id );
        if ( ! $row ) {
            return self::notFound( 'learning_goal_not_found', __( 'Learning goal not found.', 'talenttrack' ) );
        }
        if ( ! empty( $row->is_shipped ) ) {
            return self::fail( 'learning_goal_shipped', __( 'Shipped learning goals cannot be deleted.', 'talenttrack' ), 409 );
        }
        $ok = $repo->delete( $id );
        return self::ok( [ 'deleted' => $ok, 'id' => $id ] );
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * Validate the closed taxonomies. On update, only validates the keys
     * actually supplied. Returns an error response or null when valid.
     */
    private static function validate( \WP_REST_Request $r, bool $partial = false ): ?\WP_REST_Response {
        if ( ! $partial || $r->has_param( 'side' ) ) {
            if ( ! MethodologyEnums::isValidSide( sanitize_key( (string) ( $r['side'] ?? '' ) ) ) ) {
                return self::fail( 'invalid_side', __( 'Invalid side.', 'talenttrack' ), 400 );
            }
        }
        if ( $r->has_param( 'team_task_key' ) ) {
            $task = sanitize_key( (string) $r['team_task_key'] );
            if ( $task !== '' && ! MethodologyEnums::isValidTask( $task ) ) {
                return self::fail( 'invalid_team_task', __( 'Invalid team-task.', 'talenttrack' ), 400 );
            }
        }
        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private static function writePayload( \WP_REST_Request $r, bool $partial = false ): array {
        $out = [];

        if ( ! $partial || $r->has_param( 'side' ) ) {
            $out['side'] = sanitize_key( (string) ( $r['side'] ?? '' ) );
        }
        if ( ! $partial || $r->has_param( 'team_task_key' ) ) {
            $task = sanitize_key( (string) ( $r['team_task_key'] ?? '' ) );
            $out['team_task_key'] = $task !== '' ? $task : null;
        }
        if ( ! $partial || $r->has_param( 'sort_order' ) ) {
            $out['sort_order'] = (int) ( $r['sort_order'] ?? 0 );
        }
        if ( ! $partial || $r->has_param( 'title' ) ) {
            $title = is_array( $r['title'] ?? null ) ? $r['title'] : [];
            $out['title_json'] = MultilingualField::encode( [
                'nl' => sanitize_text_field( is_string( $title['nl'] ?? null ) ? $title['nl'] : '' ),
                'en' => sanitize_text_field( is_string( $title['en'] ?? null ) ? $title['en'] : '' ),
            ] );
        }
        if ( ! $partial || $r->has_param( 'bullets' ) ) {
            $bullets = is_array( $r['bullets'] ?? null ) ? $r['bullets'] : [];
            $out['bullets_json'] = MultilingualField::encode( [
                'nl' => self::sanitizeBullets( $bullets['nl'] ?? null ),
                'en' => self::sanitizeBullets( $bullets['en'] ?? null ),
            ] );
        }

        return $out;
    }

    /**
     * @param mixed $value
     * @return string[]
     */
    private static function sanitizeBullets( $value ): array {
        if ( ! is_array( $value ) ) return [];
        $out = [];
        foreach ( $value as $item ) {
            if ( ! is_string( $item ) ) continue;
            $clean = trim( sanitize_text_field( $item ) );
            if ( $clean !== '' ) $out[] = $clean;
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function shape( object $g, bool $full = false ): array {
        $out = [
            'id'            => (int) $g->id,
            'primer_id'     => (int) $g->primer_id,
            'slug'          => (string) $g->slug,
            'side'          => (string) $g->side,
            'team_task_key' => $g->team_task_key !== null ? (string) $g->team_task_key : null,
            'is_shipped'    => ! empty( $g->is_shipped ),
            'title'         => MultilingualField::string( $g->title_json ),
            'bullets'       => MultilingualField::stringList( $g->bullets_json ),
        ];
        if ( $full ) {
            $out['title_i18n']   = MultilingualField::decode( $g->title_json ) ?: (object) [];
            $out['bullets_i18n'] = MultilingualField::decode( $g->bullets_json ) ?: (object) [];
        }
        return $out;
    }
}
