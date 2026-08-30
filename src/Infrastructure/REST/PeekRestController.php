<?php
namespace TT\Infrastructure\REST;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Security\AuthorizationService;
use TT\Modules\Authorization\AllTeamsScope;
use WP_REST_Request;
use WP_REST_Response;

/**
 * PeekRestController (#2458) — read-only record summaries for the peek
 * panel.
 *
 * The peek panel opens a related record beside the one you are on instead
 * of navigating away, so a cross-entity move stays reversible. Following a
 * link from a player to Tuesday's training currently abandons the player
 * and costs you your scroll position and any open section on the way back;
 * that round trip is the complaint behind most "I lost my place" feedback.
 *
 * Three deliberate constraints:
 *
 * 1. **Read-only.** No writes here in v1. Editing inside a panel means a
 *    second save path and a stale-parent problem, for a surface whose job
 *    is orientation rather than data entry.
 * 2. **Per-record permission, not per-endpoint.** Each route gates on the
 *    same authorization the detail view uses. A capability check on the
 *    route alone would let a user peek a record they cannot open.
 * 3. **Small, fixed payloads.** A summary, not a serialised record. The
 *    panel shows enough to decide whether to open the thing properly.
 *
 * These are the endpoints CLAUDE.md §4 says should exist anyway: the
 * feature is reachable through REST, so a non-WordPress front end gets the
 * same answers as the rendered HTML.
 */
final class PeekRestController extends BaseController {

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
    }

    public static function routes(): void {
        register_rest_route( self::NS, '/players/(?P<id>\d+)/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'player' ],
            'permission_callback' => static function ( WP_REST_Request $r ): bool {
                return AuthorizationService::canViewPlayer( get_current_user_id(), (int) $r['id'] );
            },
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/teams/(?P<id>\d+)/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'team' ],
            // #3152 — "a record you cannot open is a record you cannot peek"
            // (docs/rest-api.md § Search + peek). That held for the player
            // peek above, which asks `canViewPlayer`, and not for this one:
            // `tt_view_teams` is club-wide, so the peek returned name, age
            // group, season and roster count for any team id. Same predicate
            // the detail route and `GET /teams` now use.
            'permission_callback' => static function ( WP_REST_Request $r ): bool {
                return current_user_can( 'tt_view_teams' )
                    && AllTeamsScope::canReadTeam( get_current_user_id(), (int) $r['id'] );
            },
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );

        register_rest_route( self::NS, '/activities/(?P<id>\d+)/summary', [
            'methods'             => 'GET',
            'callback'            => [ __CLASS__, 'activity' ],
            'permission_callback' => self::permCan( 'tt_view_activities' ),
            'args'                => [ 'id' => [ 'type' => 'integer', 'required' => true ] ],
        ] );
    }

    /**
     * Shape shared by all three, so the panel renders one way:
     * { type, id, title, subtitle, url, facts: [ {label, value} ] }
     */
    private static function envelope( string $type, int $id, string $title, string $subtitle, string $list_slug, array $facts ): WP_REST_Response {
        return new WP_REST_Response( [
            'type'     => $type,
            'id'       => $id,
            'title'    => $title,
            'subtitle' => $subtitle,
            'url'      => \TT\Shared\Frontend\Components\RecordLink::detailUrlFor( $list_slug, $id ),
            'facts'    => array_values( array_filter(
                $facts,
                static fn( $f ) => (string) ( $f['value'] ?? '' ) !== ''
            ) ),
        ], 200 );
    }

    public static function player( WP_REST_Request $request ): WP_REST_Response {
        $id     = (int) $request['id'];
        $player = QueryHelpers::get_player( $id );
        if ( ! $player ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'talenttrack' ) ], 404 );
        }

        $team = ! empty( $player->team_id ) ? QueryHelpers::get_team( (int) $player->team_id ) : null;

        return self::envelope(
            'player',
            $id,
            trim( (string) $player->first_name . ' ' . (string) $player->last_name ),
            $team ? (string) $team->name : '',
            'players',
            [
                [
                    'label' => __( 'Status', 'talenttrack' ),
                    'value' => \TT\Infrastructure\Query\LabelTranslator::playerStatus( (string) ( $player->status ?? '' ) ),
                ],
                [
                    'label' => __( 'Jersey number', 'talenttrack' ),
                    'value' => ! empty( $player->jersey_number ) ? (string) (int) $player->jersey_number : '',
                ],
                [
                    'label' => __( 'Date of birth', 'talenttrack' ),
                    'value' => ! empty( $player->birth_date ) ? \TT\Shared\Dates\TTDate::date( (string) $player->birth_date ) : '',
                ],
            ]
        );
    }

    public static function team( WP_REST_Request $request ): WP_REST_Response {
        $id   = (int) $request['id'];
        $team = QueryHelpers::get_team( $id );
        if ( ! $team ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'talenttrack' ) ], 404 );
        }

        $roster = QueryHelpers::get_players( $id );

        return self::envelope(
            'team',
            $id,
            (string) $team->name,
            ! empty( $team->age_group )
                ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'age_group', (string) $team->age_group )
                : '',
            'teams',
            [
                [
                    'label' => __( 'Players', 'talenttrack' ),
                    'value' => (string) count( (array) $roster ),
                ],
                [
                    'label' => __( 'Season', 'talenttrack' ),
                    'value' => (string) ( $team->season ?? '' ),
                ],
            ]
        );
    }

    public static function activity( WP_REST_Request $request ): WP_REST_Response {
        $id  = (int) $request['id'];
        $row = ( new \TT\Modules\Activities\Repositories\ActivitiesRepository() )->findById( $id );
        if ( ! $row ) {
            return new WP_REST_Response( [ 'message' => __( 'Not found.', 'talenttrack' ) ], 404 );
        }

        $team = ! empty( $row->team_id ) ? QueryHelpers::get_team( (int) $row->team_id ) : null;

        return self::envelope(
            'activity',
            $id,
            (string) $row->title,
            $team ? (string) $team->name : '',
            'activities',
            [
                [
                    'label' => __( 'Date', 'talenttrack' ),
                    'value' => ! empty( $row->session_date ) ? \TT\Shared\Dates\TTDate::date( (string) $row->session_date ) : '',
                ],
                [
                    'label' => __( 'Type', 'talenttrack' ),
                    'value' => ! empty( $row->activity_type_key )
                        ? \TT\Infrastructure\Query\LookupTranslator::byTypeAndName( 'activity_type', (string) $row->activity_type_key )
                        : '',
                ],
            ]
        );
    }
}
