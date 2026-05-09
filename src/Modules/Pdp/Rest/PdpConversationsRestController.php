<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;

/**
 * PdpConversationsRestController — PATCH endpoints for individual
 * conversations within a PDP file. The file-scoped GET lives on
 * PdpFilesRestController::get_one (full-file payload).
 *
 * Coach owns coach_signoff_at + agenda/notes/agreed_actions; the
 * player owns player_reflection + player_ack_at; parents own
 * parent_ack_at. The repository whitelists the columns; this
 * controller just gates by capability + ownership.
 */
class PdpConversationsRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-conversations/(?P<id>\d+)', [
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return is_user_logged_in();
    }

    public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid conversation id.', 'talenttrack' ), 400 );
        }
        $repo = new PdpConversationsRepository();
        $conv = $repo->find( $id );
        if ( ! $conv ) {
            return RestResponse::error( 'not_found', __( 'Conversation not found.', 'talenttrack' ), 404 );
        }
        $file = ( new PdpFilesRepository() )->find( (int) $conv->pdp_file_id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'Parent PDP file not found.', 'talenttrack' ), 404 );
        }

        $allowed = self::allowedFieldsFor( $file );
        if ( ! $allowed ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have permission to update this conversation.', 'talenttrack' ), 403 );
        }

        $patch = [];
        foreach ( $allowed as $field ) {
            if ( ! array_key_exists( $field, (array) $r->get_params() ) ) continue;
            $patch[ $field ] = self::sanitizeField( $field, $r[ $field ] );
        }
        if ( ! $patch ) {
            return RestResponse::success( [ 'id' => $id, 'unchanged' => true ] );
        }

        // v3.110.52 — gate `player_reflection` writes on the same 2-week
        // pre-meeting window the player surface (FrontendMyPdpView) uses
        // to render the form. The view-side gate hides the textarea, but
        // a bookmark / saved request / API consumer could otherwise
        // PATCH `player_reflection` at any time. Mirroring the rule
        // here: writes are accepted only when the conversation has a
        // `scheduled_at` AND that time is at most 14 days in the future
        // (or already passed). Coach + admin paths bypass this gate;
        // they need to be able to edit the field at any time
        // (e.g. backfilling a player quote on behalf of the player).
        if ( array_key_exists( 'player_reflection', $patch )
             && self::isLinkedPlayer( get_current_user_id(), (int) $file->player_id )
             && ! self::selfReflectionWindowOpen( $conv ) ) {
            return RestResponse::error(
                'window_closed',
                __( 'You can add your self-reflection up to 2 weeks before the planned meeting. Check back closer to the planned date.', 'talenttrack' ),
                403
            );
        }

        if ( ! $repo->update( $id, $patch ) ) {
            Logger::error( 'pdp.conversation.update.failed', [ 'id' => $id, 'patch' => $patch ] );
            return RestResponse::error( 'db_error',
                __( 'The conversation could not be updated.', 'talenttrack' ), 500 );
        }

        return RestResponse::success( [ 'id' => $id ] );
    }

    /**
     * Returns the column whitelist this user can write to on this file.
     * Empty array = forbidden.
     *
     * @return list<string>
     */
    private static function allowedFieldsFor( object $file ): array {
        $uid = get_current_user_id();
        $is_admin = current_user_can( 'tt_edit_settings' );
        $is_coach_for_player = QueryHelpers::coach_owns_player( $uid, (int) $file->player_id );

        // Coach (or admin): full conversation edit + sign-off.
        if ( $is_admin || ( current_user_can( 'tt_edit_pdp' ) && $is_coach_for_player ) ) {
            return [
                'scheduled_at', 'conducted_at', 'agenda', 'notes',
                'agreed_actions', 'player_reflection',
                'coach_signoff_at', 'parent_ack_at', 'player_ack_at',
            ];
        }

        // Player linked to the PDP: own reflection + own ack only.
        if ( self::isLinkedPlayer( $uid, (int) $file->player_id ) ) {
            return [ 'player_reflection', 'player_ack_at' ];
        }

        // Parent of the linked player: parent_ack_at only.
        if ( self::isParentOfPlayer( $uid, (int) $file->player_id ) ) {
            return [ 'parent_ack_at' ];
        }

        return [];
    }

    private static function sanitizeField( string $field, $value ) {
        switch ( $field ) {
            case 'scheduled_at':
            case 'conducted_at':
            case 'coach_signoff_at':
            case 'parent_ack_at':
            case 'player_ack_at':
                if ( $value === null || $value === '' ) return null;
                return sanitize_text_field( (string) $value );
            case 'agenda':
            case 'notes':
            case 'agreed_actions':
            case 'player_reflection':
                return wp_kses_post( (string) $value );
        }
        return null;
    }

    private static function isLinkedPlayer( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        global $wpdb; $p = $wpdb->prefix;
        $hit = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$p}tt_players WHERE id = %d AND wp_user_id = %d",
            $player_id, $user_id
        ) );
        return $hit > 0;
    }

    /**
     * v3.110.52 — same gate `FrontendMyPdpView::selfReflectionWindowOpen()`
     * uses on the view side. Returns true when the conversation has a
     * `scheduled_at` AND that scheduled time is at most 14 days in the
     * future (or already passed); false when the meeting hasn't been
     * scheduled yet OR it's more than 2 weeks out.
     *
     * Kept here as a private duplicate (not extracted to a shared
     * helper) because the rule may diverge between the surfaces over
     * time and a single shared helper would mask that intent. Five
     * lines is a small price for clarity at each callsite.
     */
    private static function selfReflectionWindowOpen( object $conv ): bool {
        $scheduled = (string) ( $conv->scheduled_at ?? '' );
        if ( $scheduled === '' ) return false;
        // `scheduled_at` is stored as a UTC datetime via gmdate(), so
        // force UTC parsing — PHP's strtotime() otherwise interprets
        // the bare string in the server's local TZ, producing a skew
        // on any non-UTC install. See FrontendMyPdpView for the
        // matching view-side fix and the pilot-operator report.
        $ts = strtotime( $scheduled . ' UTC' );
        if ( $ts === false ) return false;
        $now    = (int) current_time( 'timestamp', true );
        $window = 14 * DAY_IN_SECONDS;
        return ( $ts - $now ) <= $window;
    }

    private static function isParentOfPlayer( int $user_id, int $player_id ): bool {
        if ( $user_id <= 0 || $player_id <= 0 ) return false;
        global $wpdb; $p = $wpdb->prefix;
        $hit = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT 1 FROM {$p}tt_player_parents
              WHERE player_id = %d AND parent_user_id = %d LIMIT 1",
            $player_id, $user_id
        ) );
        return $hit === 1;
    }
}
