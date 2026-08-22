<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\MatrixGate;
use TT\Modules\Training\Repositories\TrainingObservationsRepository;
use TT\Modules\Training\Services\PlayerExposureReader;

/**
 * TrainingExposureRestController (#2500, epic #2493).
 *
 * The read API for what a player has been taught:
 *
 *   GET /players/{id}/training-exposure   minutes per principle, plus the
 *                                         ones never trained
 *   GET /players/{id}/observations        what coaches noted about them
 *   GET /training/coverage                principle × team, academy-wide
 *
 * ## Why this is not on the plans controller
 *
 * `tt_training_plan` gates planning a training; this is player data, and
 * per D16 a different set of people may read it — a parent for their own
 * child, and not necessarily a coach who can build a plan. Sharing a
 * controller would have meant sharing a `permission_callback`, which is
 * how two different rights quietly become one.
 *
 * Every route gates on the `training_exposure` matrix entity. The
 * academy-wide coverage matrix additionally requires GLOBAL scope, so a
 * coach holding it at team scope reads their own players and not the
 * academy.
 *
 * The per-player routes deliberately do not re-implement "may this user
 * see this player" — `FrontendPlayerDetailView::canViewPlayer()` is the
 * single authority on that and is applied here rather than copied.
 */
final class TrainingExposureRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/training-exposure', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'player_exposure' ],
                'permission_callback' => static fn( \WP_REST_Request $r ) => self::canReadPlayer( (int) $r['id'] ),
            ],
        ] );

        register_rest_route( self::NS, '/players/(?P<id>\d+)/observations', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'player_observations' ],
                'permission_callback' => static fn( \WP_REST_Request $r ) => self::canReadPlayer( (int) $r['id'] ),
            ],
        ] );

        register_rest_route( self::NS, '/training/coverage', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'coverage' ],
                'permission_callback' => static fn() => self::canReadAcademy(),
            ],
        ] );
    }

    /**
     * Two gates, both required: the exposure right, and the existing
     * authority on whether this user may see this player at all.
     */
    private static function canReadPlayer( int $player_id ): bool {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 || $player_id <= 0 ) return false;

        if ( ! MatrixGate::canAnyScope( $user_id, 'training_exposure', MatrixGate::READ ) ) {
            return false;
        }

        // The single authority on player visibility — own record, own
        // team, global, or parent-of-this-player. Reimplementing it here
        // is how a parent ends up reading another family's child.
        if ( ! AuthorizationService::canViewPlayer( $user_id, $player_id ) ) {
            return false;
        }

        // #1867 — and a parent only reads a section the child has not
        // hidden. Training exposure is a per-principle ledger of a young
        // person's shortfalls; if they may withhold their measurements
        // from a parent, this belongs in the same bracket. Staff are
        // unaffected — the check returns true for anyone who is not a
        // linked parent.
        return AuthorizationService::parentCanViewSection( $user_id, $player_id, 'training' );
    }

    private static function canReadAcademy(): bool {
        return MatrixGate::can(
            get_current_user_id(),
            'training_exposure',
            MatrixGate::READ,
            MatrixGate::SCOPE_GLOBAL
        );
    }

    public static function player_exposure( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $season_id = $r->get_param( 'season_id' ) !== null ? (int) $r->get_param( 'season_id' ) : null;

        $reader = new PlayerExposureReader();

        return RestResponse::success( [
            'summary'    => $reader->summaryFor( $player_id ),
            // Every principle, including the untrained ones. A consumer
            // filtering them out is making a choice; a consumer never
            // being sent them is being denied one.
            'principles' => $reader->forPlayer( $player_id, $season_id ),
        ] );
    }

    public static function player_observations( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $limit     = $r->get_param( 'limit' ) !== null ? (int) $r->get_param( 'limit' ) : 20;

        $rows = ( new TrainingObservationsRepository() )->listForPlayer( $player_id, $limit );

        return RestResponse::success( [
            'observations' => array_map(
                static function ( object $o ): array {
                    return [
                        'id'           => (int) ( $o->id ?? 0 ),
                        'run_id'       => (int) ( $o->run_id ?? 0 ),
                        'run_date'     => $o->run_date ?? null,
                        'principle_id' => isset( $o->principle_id ) ? (int) $o->principle_id : null,
                        'rating'       => $o->rating === null ? null : (float) $o->rating,
                        'note'         => $o->note ?? null,
                        'created_at'   => (string) ( $o->created_at ?? '' ),
                    ];
                },
                $rows
            ),
        ] );
    }

    public static function coverage( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = $r->get_param( 'season_id' ) !== null ? (int) $r->get_param( 'season_id' ) : null;

        $reader = new PlayerExposureReader();

        return RestResponse::success( [
            'principles' => $reader->principles(),
            'coverage'   => $reader->coverageByTeam( $season_id ),
        ] );
    }
}
