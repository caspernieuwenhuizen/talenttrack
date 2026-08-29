<?php
namespace TT\Modules\DemoData\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\REST\RestResponse;
use TT\Modules\DemoData\DemoGenerator;
use TT\Modules\DemoData\DemoMode;
use TT\Modules\DemoData\DemoRunState;

/**
 * DemoRunRestController (#3041) — advance a demo-generation run one step.
 *
 * Generating the large preset used to be one `admin-post.php` request, and a
 * hosted install's reverse proxy gave up on it long before PHP did. The run
 * is now a list of steps with a cursor that survives the request, and the
 * generate page's overlay walks it through these routes:
 *
 *   GET  /demo-runs/current   — what is on file, and how far it got
 *   POST /demo-runs/step      — run the next step
 *   POST /demo-runs/discard   — abandon an unfinished run
 *
 * `manage_options` throughout — the same bar the generate page itself sets.
 * Nothing here starts a run: starting needs the typed password and the
 * uploaded workbook, which stay in the form POST that carries them.
 */
class DemoRunRestController {

    private const NS  = 'talenttrack/v1';
    private const CAP = 'manage_options';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/demo-runs/current', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'current' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/demo-runs/step', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'step' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );

        register_rest_route( self::NS, '/demo-runs/discard', [
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'discard' ],
                'permission_callback' => [ __CLASS__, 'can_manage' ],
            ],
        ] );
    }

    public static function can_manage(): bool {
        return current_user_can( self::CAP );
    }

    public static function current( \WP_REST_Request $r ): \WP_REST_Response {
        $state = DemoRunState::load();

        return RestResponse::success( $state === null ? [ 'run_id' => '' ] : $state->progress() );
    }

    /**
     * Run the next step and report where the run got to.
     *
     * One step per request is the point: no single request has to outlive a
     * gateway timeout, and the client can render which step is running.
     */
    public static function step( \WP_REST_Request $r ): \WP_REST_Response {
        $run_id = sanitize_text_field( (string) ( $r['run_id'] ?? '' ) );
        $state  = $run_id !== '' ? DemoRunState::loadById( $run_id ) : null;

        if ( $state === null ) {
            return RestResponse::error( 'no_run', __( 'That demo run is no longer on file.', 'talenttrack' ), 404 );
        }
        if ( $state->status() !== DemoRunState::STATUS_RUNNING ) {
            return RestResponse::success( $state->progress() );
        }

        // Generation reads tagged data across every batch, the same as the
        // form handler does.
        DemoMode::overrideForRequest( DemoMode::NEUTRAL );
        try {
            DemoGenerator::advance( $state );

            // The last step is also where the run's summary is written: the
            // demo account list is shown once, and losing it because the run
            // finished over REST rather than in the form POST would be a
            // regression the operator only notices when they need a password.
            if ( $state->isFinished() ) {
                \TT\Modules\DemoData\Admin\DemoDataPage::finishRun( DemoGenerator::result( $state ) );
            }
        } finally {
            DemoMode::clearOverride();
        }

        return RestResponse::success( $state->progress() );
    }

    public static function discard( \WP_REST_Request $r ): \WP_REST_Response {
        DemoRunState::clear();

        return RestResponse::success( [ 'run_id' => '' ] );
    }
}
