<?php
namespace TT\Modules\Alerts\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\BaseController;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Alerts\AlertRegistry;
use TT\Modules\Alerts\Cron\AlertSweepCron;
use TT\Modules\Alerts\Domain\Severity;
use TT\Modules\Alerts\Repositories\AlertOccurrencesRepository;
use TT\Modules\Alerts\Services\AlertOversight;
use WP_REST_Request;

/**
 * AlertsRestController (#2631, epic #2629).
 *
 *   GET  /alerts                  my open occurrences
 *   GET  /alerts/{uuid}           one of mine
 *   POST /alerts/{uuid}/read      mark read
 *   GET  /alerts/definitions      the catalogue
 *   POST /alerts/evaluate         force a sweep (diagnostic)
 *   GET  /alerts/rollup           open conditions per team I oversee (#2633)
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
                    // #2633 — the same filters the inline chips and the
                    // player surface use. Declared here so a non-WordPress
                    // front end can render the identical chip from the API
                    // (CLAUDE.md §4) rather than the chip being a privilege
                    // of the PHP renderer.
                    'state'        => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'subject_type' => [ 'sanitize_callback' => 'sanitize_key', 'required' => false ],
                    'subject_id'   => [ 'sanitize_callback' => 'absint', 'required' => false ],
                    'player_id'    => [ 'sanitize_callback' => 'absint', 'required' => false ],
                    'per_page' => [ 'sanitize_callback' => 'absint', 'default' => 50 ],
                ],
            ],
        ] );

        // #2633 — the oversight aggregate (epic decision 7's counterpart).
        // Registered before the uuid pattern for the same reason
        // `definitions` and `evaluate` are.
        register_rest_route( self::NS, '/alerts/rollup', [
            [
                'methods'             => 'GET',
                'callback'            => [ self::class, 'rollup' ],
                'permission_callback' => [ self::class, 'permLoggedIn' ],
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

        // #2633 — filtering moved into the repository. It used to fetch a
        // page and then drop rows in PHP, which silently under-returned:
        // asking for 50 urgent alerts fetched the 50 loudest of everything
        // and then filtered that down. Pushing the predicate into SQL also
        // means this route and the rendered inbox ask the same question of
        // the same method, so they cannot disagree about what "open" means.
        $module = (string) $req->get_param( 'module' );

        $rows = $repo->listForUser( $user_id, [
            'state'        => (string) $req->get_param( 'state' ),
            'alert_keys'   => $module !== '' ? array_keys( AlertRegistry::forModule( $module ) ) : [],
            'severity'     => (string) $req->get_param( 'severity' ),
            'subject_type' => (string) $req->get_param( 'subject_type' ),
            'subject_id'   => (int) $req->get_param( 'subject_id' ),
            'player_id'    => (int) $req->get_param( 'player_id' ),
            'limit'        => (int) $req->get_param( 'per_page' ),
        ] );

        // A module with no registered definitions must return nothing, not
        // everything. `alert_keys => []` means "no key filter" in the
        // repository, so the empty case is caught here rather than quietly
        // widening the result set.
        if ( $module !== '' && empty( AlertRegistry::forModule( $module ) ) ) {
            return RestResponse::success( [] );
        }

        $out = [];
        foreach ( $rows as $row ) {
            $out[] = self::serialize( $row );
        }

        return RestResponse::success( $out );
    }

    /**
     * The oversight roll-up: open conditions per team, for the teams this
     * user oversees.
     *
     * Cap-scoping happens in `AlertOversight`, from the capability model —
     * there is no request parameter that can widen it, which is what keeps
     * a drill-down from reaching a team the caller cannot see. It reads a
     * GROUP BY over rows that already exist and writes nothing: a Head of
     * Development receives no occurrences of their own (epic decision 7),
     * and this is what makes that decision liveable rather than a gap.
     */
    public static function rollup(): \WP_REST_Response {
        $repo = new AlertOccurrencesRepository();
        if ( ! $repo->tableExists() ) return RestResponse::success( [] );

        return RestResponse::success(
            AlertOversight::forUser( get_current_user_id() )
        );
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
