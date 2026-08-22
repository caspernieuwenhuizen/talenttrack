<?php
namespace TT\Modules\Alerts\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Cron\AlertSweepCron;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use WP_REST_Request;

/**
 * AlertsRestController (#2631, epic #2629).
 *
 *   GET  /alerts                  my open occurrences
 *   GET  /alerts/{uuid}           one of mine
 *   POST /alerts/{uuid}/read      mark read
 *   GET  /alerts/definitions      the catalogue
 *   POST /alerts/evaluate         force a sweep (diagnostic)
 *
 * Per CLAUDE.md §4 this exists from day one, not once a consumer needs it:
 * the banner and this controller read the same repository, so deleting
 * every file under `src/Shared/Frontend/` would leave the API answering
 * correctly.
 *
 * Authorization shape worth being explicit about. The list routes are
 * `permLoggedIn` rather than capability-gated, because they are scoped to
 * `recipient_user_id = me` in SQL — the capability question was already
 * answered when the evaluator decided whether to write the row, and it
 * re-answers it on every sweep. A second cap check here would gate on a
 * capability the *recipient* holds, which is what produced the row in the
 * first place. Single-occurrence reads are additionally scoped by uuid AND
 * user, so a leaked uuid is not a capability.
 */
final class AlertsRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/alerts', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'listMine' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
                'args'                => [
                    'module'   => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'severity' => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'per_page' => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
                ],
            ],
        ] );

        register_rest_route( self::NS, '/alerts/definitions', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'definitions' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
        ] );

        register_rest_route( self::NS, '/alerts/evaluate', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'evaluate' ],
                'permission_callback' => self::permCan( 'tt_edit_settings' ),
            ],
        ] );

        // Registered after the literal routes above so `definitions` and
        // `evaluate` are not swallowed by the uuid pattern.
        register_rest_route( self::NS, '/alerts/(?P<uuid>[a-f0-9\-]{36})', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'getOne' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
        ] );

        register_rest_route( self::NS, '/alerts/(?P<uuid>[a-f0-9\-]{36})/read', [
            [
                'methods'             => 'POST',
                'callback'            => [ self::class, 'markRead' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
            ],
        ] );
    }

    public static function listMine( WP_REST_Request $req ): \WP_REST_Response {
        $user_id = get_current_user_id();
        $repo    = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return RestResponse::success( [] );

        $rows = $repo->openForUser( $user_id, (int) $req->get_param( 'per_page' ) );

        $module   = (string) $req->get_param( 'module' );
        $severity = (string) $req->get_param( 'severity' );

        $out = [];
        foreach ( $rows as $row ) {
            $item = self::serialize( $row );
            if ( $module !== '' && $item['module'] !== $module ) continue;
            if ( $severity !== '' && $item['severity'] !== $severity ) continue;
            $out[] = $item;
        }

        return RestResponse::success( $out );
    }

    public static function getOne( WP_REST_Request $req ): \WP_REST_Response {
        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return RestResponse::error( 'not_found', __( 'Alert not found.', 'talenttrack' ), 404 );

        $row = $repo->findForUser( (string) $req->get_param( 'uuid' ), get_current_user_id() );
        if ( $row === null ) {
            return RestResponse::error( 'not_found', __( 'Alert not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( self::serialize( $row ) );
    }

    public static function markRead( WP_REST_Request $req ): \WP_REST_Response {
        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return RestResponse::error( 'not_found', __( 'Alert not found.', 'talenttrack' ), 404 );

        $ok = $repo->markRead(
            (string) $req->get_param( 'uuid' ),
            get_current_user_id(),
            current_time( 'mysql' )
        );
        if ( ! $ok ) {
            return RestResponse::error( 'not_found', __( 'Alert not found.', 'talenttrack' ), 404 );
        }
        return RestResponse::success( [ 'read' => true ] );
    }

    /** The catalogue that backs the settings matrix in #2632. */
    public static function definitions(): \WP_REST_Response {
        $out = [];
        foreach ( AlertRegistry::all() as $key => $alert ) {
            $out[] = [
                'key'              => $key,
                'module'           => $alert->module(),
                'label'            => $alert->label(),
                'description'      => $alert->description(),
                'default_severity' => $alert->defaultSeverity(),
                'default_surfaces' => $alert->defaultSurfaces(),
                'operational'      => $alert->isOperational(),
            ];
        }
        return RestResponse::success( $out );
    }

    /**
     * Force a sweep. Diagnostic, not a user-facing action — the engine is
     * cron-driven by design (epic decision 2) and this exists so an operator
     * can answer "is the sweep working" without waiting an hour.
     */
    public static function evaluate(): \WP_REST_Response {
        $stats = ( new AlertSweepCron() )->runAllClubs();
        return RestResponse::success( [ 'clubs' => $stats ] );
    }

    /** @return array<string,mixed> */
    private static function serialize( object $row ): array {
        $payload = [];
        $raw     = (string) ( $row->payload_json ?? '' );
        if ( $raw !== '' ) {
            $decoded = json_decode( $raw, true );
            if ( is_array( $decoded ) ) $payload = $decoded;
        }

        $key        = (string) ( $row->alert_key ?? '' );
        $definition = AlertRegistry::find( $key );

        return [
            'uuid'          => (string) ( $row->uuid ?? '' ),
            'alert_key'     => $key,
            // Derived from the definition rather than stored on the row:
            // a definition moving module should not need a data migration.
            'module'        => $definition !== null ? $definition->module() : '',
            'label'         => $definition !== null ? $definition->label() : '',
            'severity'      => Severity::normalise( (string) ( $row->severity ?? '' ) ),
            'title'         => isset( $payload['title'] ) ? (string) $payload['title'] : '',
            'url'           => isset( $payload['url'] ) ? (string) $payload['url'] : '',
            'subject_type'  => (string) ( $row->subject_type ?? '' ),
            'subject_id'    => (int) ( $row->subject_id ?? 0 ),
            'player_id'     => isset( $row->player_id ) && $row->player_id !== null ? (int) $row->player_id : null,
            'first_seen_at' => (string) ( $row->first_seen_at ?? '' ),
            'last_seen_at'  => (string) ( $row->last_seen_at ?? '' ),
            'read_at'       => isset( $row->read_at ) && $row->read_at !== null ? (string) $row->read_at : null,
        ];
    }
}
