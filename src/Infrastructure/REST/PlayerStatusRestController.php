<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\PotentialBand;
use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Authorization\AllTeamsScope;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;
use TT\Modules\Players\Services\PotentialTrajectory;

/**
 * PlayerStatusRestController (#0057 Sprints 1 + 4) — REST surface for
 * the player status feature.
 *
 *   POST /players/{id}/behaviour-ratings   — log a behaviour rating
 *   POST /players/{id}/potential           — set potential band
 *   GET  /players/{id}/potential           — the potential trajectory
 *   GET  /players/{id}/status              — single-player status verdict
 *   GET  /teams/{id}/player-statuses       — bulk: all players on a team
 *
 * Permission gates use the capabilities registered in
 * `PlayerStatusModule`. The bulk endpoint is the read model the
 * traffic-light dot column on My Teams consumes.
 */
final class PlayerStatusRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/behaviour-ratings', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'createBehaviourRating' ],
            // #2574 — feature flag AND capability; see behaviourCaptureAvailable().
            // #3154 — and the player. The flag-plus-capability check takes no
            // player id, so a holder could write a behaviour judgement onto
            // any child in the club. `canEditPlayer` is the same gate
            // `PUT /players/{id}` uses; every role that holds
            // `tt_rate_player_behaviour` (head coach, head of development,
            // administrator, club admin) already passes it for their own
            // players, so this narrows rather than locks out.
            'permission_callback' => static fn( \WP_REST_Request $r ): bool =>
                \TT\Modules\Players\PlayerStatusModule::behaviourCaptureAvailable()
                && AuthorizationService::canEditPlayer( get_current_user_id(), (int) $r['id'] ),
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/potential', [
            'methods'             => 'POST',
            'callback'            => [ __CLASS__, 'setPotential' ],
            'permission_callback' => static fn() => current_user_can( 'tt_set_player_potential' ),
        ] );
        // #3226 — the trajectory, not just the current band. `tt_player_potential`
        // has been append-only since migration 0042 and the whole history was
        // readable by nothing but `EvidencePacket`. A potential revised down
        // twice in a season is a development signal, and it was invisible.
        //
        // Gated exactly like `GET /status`: reading a player's potential is
        // reading their status, and `canViewPlayer` is what lets a parent see
        // their own child and nobody else's.
        register_rest_route( self::NS, '/players/(?P<id>\d+)/potential', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'potentialHistory' ],
            'permission_callback' => static fn( \WP_REST_Request $r ): bool =>
                current_user_can( 'tt_view_player_status' )
                && AuthorizationService::canViewPlayer( get_current_user_id(), (int) $r['id'] ),
        ] );
        register_rest_route( self::NS, '/players/(?P<id>\d+)/status', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'playerStatus' ],
            // #3154 — the capability is held club-wide by both coach roles,
            // scouts and parents, and the response is the full verdict object
            // per player rather than a traffic-light colour. `canViewPlayer`
            // is what every other per-player route asks, and it is what lets a
            // parent read their own child and nobody else's.
            'permission_callback' => static fn( \WP_REST_Request $r ): bool =>
                current_user_can( 'tt_view_player_status' )
                && AuthorizationService::canViewPlayer( get_current_user_id(), (int) $r['id'] ),
        ] );
        register_rest_route( self::NS, '/teams/(?P<id>\d+)/player-statuses', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'teamStatuses' ],
            // #3154 — the team id came from the path and was never checked,
            // so iterating `{id}` walked every squad in the academy. Scoped on
            // `player_status`, the entity that names the data this route
            // returns, not on `team`: a persona granted global player-status
            // read but only team-scoped team read must still get the board.
            'permission_callback' => static fn( \WP_REST_Request $r ): bool =>
                current_user_can( 'tt_view_player_status' )
                && AllTeamsScope::canReadTeamFor( get_current_user_id(), (int) $r['id'], 'player_status' ),
        ] );
    }

    /**
     * The player's potential over time, oldest first, each entry carrying
     * the direction of the revision that produced it.
     *
     * Returns the series and the current band together so a consumer does
     * not have to call `GET /status` as well to render a headline.
     */
    public static function potentialHistory( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_input', __( 'A player is required.', 'talenttrack' ), 400 );
        }

        $entries = ( new PotentialTrajectory() )->forPlayer( $player_id );
        $current = $entries ? $entries[ count( $entries ) - 1 ] : null;

        return RestResponse::success( [
            'player_id' => $player_id,
            'current'   => $current,
            'entries'   => $entries,
        ] );
    }

    public static function createBehaviourRating( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $rating    = isset( $r['rating'] ) ? (float) $r['rating'] : 0.0;
        // v3.110.116 — was hardcoded 1.0–5.0. Reads the configured
        // scale so the validator stays in sync with the operator's
        // configured min/max. New default is 5–10 (Dutch academic
        // 6-point scale); installs that customise rating_min /
        // rating_max in tt_config are honoured.
        $rmin = (float) QueryHelpers::get_config( 'rating_min', '5' );
        $rmax = (float) QueryHelpers::get_config( 'rating_max', '10' );
        if ( $player_id <= 0 || $rating < $rmin || $rating > $rmax ) {
            return RestResponse::error(
                'bad_input',
                sprintf(
                    /* translators: 1: rating min, 2: rating max */
                    __( 'Player and rating (%1$s–%2$s) are required.', 'talenttrack' ),
                    (string) $rmin,
                    (string) $rmax
                ),
                400
            );
        }
        $rated_at = current_time( 'mysql' );
        $id = ( new PlayerBehaviourRatingsRepository() )->create( [
            'player_id'           => $player_id,
            'rating'              => $rating,
            'rated_at'            => $rated_at,
            'context'             => isset( $r['context'] ) ? sanitize_text_field( (string) $r['context'] ) : null,
            'notes'               => isset( $r['notes'] )   ? sanitize_textarea_field( (string) $r['notes'] ) : null,
            'related_activity_id' => isset( $r['related_activity_id'] ) ? (int) $r['related_activity_id'] : null,
        ] );
        return RestResponse::success( [
            'id'        => $id,
            'rating'    => $rating,
            'rated_at'  => $rated_at,
        ] );
    }

    public static function setPotential( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        $band      = isset( $r['potential_band'] ) ? sanitize_key( (string) $r['potential_band'] ) : '';
        $valid     = PotentialBand::ALL;
        if ( $player_id <= 0 || ! in_array( $band, $valid, true ) ) {
            return RestResponse::error( 'bad_input', __( 'Player and a valid potential band are required.', 'talenttrack' ), 400, [ 'allowed' => $valid ] );
        }
        $notes = isset( $r['notes'] ) ? sanitize_textarea_field( (string) $r['notes'] ) : null;

        // #2876 — a bare re-statement of the standing band is not a change of
        // mind and does not belong in the history. Until the popover
        // pre-selected the current band a coach could not see what it was, so
        // re-submitting the same value was the normal outcome rather than a
        // deliberate one, and the history filled with rows that looked like
        // revisions.
        //
        // Same band AND no notes: accept the request and record nothing.
        // Re-affirming a band *with* notes is a real act — "still first team,
        // but the last six weeks have been flat" — so that still appends.
        $repo   = new PlayerPotentialRepository();
        $latest = $repo->latestFor( $player_id );
        if ( $latest
             && (string) ( $latest->potential_band ?? '' ) === $band
             && ( $notes === null || $notes === '' )
        ) {
            return RestResponse::success( [
                'id'             => (int) ( $latest->id ?? 0 ),
                'potential_band' => $band,
                'set_at'         => (string) ( $latest->set_at ?? '' ),
                'unchanged'      => true,
            ] );
        }

        $set_at = current_time( 'mysql' );
        $id = $repo->create( [
            'player_id'      => $player_id,
            'potential_band' => $band,
            'notes'          => $notes,
        ] );
        return RestResponse::success( [
            'id'             => $id,
            'potential_band' => $band,
            'set_at'         => $set_at,
            'unchanged'      => false,
        ] );
    }

    public static function playerStatus( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = (int) $r['id'];
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'bad_player_id', __( 'Player id is required.', 'talenttrack' ), 400 );
        }
        $verdict = ( new PlayerStatusCalculator() )->calculate( $player_id );
        $payload = current_user_can( 'tt_view_player_status_breakdown' )
            ? $verdict->toArray()
            : [ 'color' => $verdict->color, 'label' => $verdict->softLabel(), 'as_of' => $verdict->as_of ];
        return RestResponse::success( $payload );
    }

    public static function teamStatuses( \WP_REST_Request $r ): \WP_REST_Response {
        global $wpdb;
        $team_id = (int) $r['id'];
        if ( $team_id <= 0 ) {
            return RestResponse::error( 'bad_team_id', __( 'Team id is required.', 'talenttrack' ), 400 );
        }
        $players = $wpdb->get_results( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players
              WHERE team_id = %d AND club_id = %d AND status = 'active'",
            $team_id, CurrentClub::id()
        ) );
        $calc       = new PlayerStatusCalculator();
        $can_detail = current_user_can( 'tt_view_player_status_breakdown' );
        $out        = [];
        foreach ( (array) $players as $row ) {
            $verdict = $calc->calculate( (int) $row->id );
            $out[]   = $can_detail
                ? array_merge( [ 'player_id' => (int) $row->id ], $verdict->toArray() )
                : [ 'player_id' => (int) $row->id, 'color' => $verdict->color, 'label' => $verdict->softLabel() ];
        }
        return RestResponse::success( [ 'rows' => $out ] );
    }
}
