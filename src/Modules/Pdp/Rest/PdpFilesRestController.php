<?php
namespace TT\Modules\Pdp\Rest;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Logging\Logger;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\REST\RestResponse;
use TT\Modules\Pdp\Calendar\PdpCalendarWriters;
use TT\Modules\Pdp\Repositories\PdpConversationsRepository;
use TT\Modules\Pdp\Repositories\PdpFilesRepository;
use TT\Modules\Pdp\Repositories\SeasonsRepository;

/**
 * PdpFilesRestController — /wp-json/talenttrack/v1/pdp-files
 *
 * #0044 Sprint 1. CRUD for PDP files with coach scoping. Creating a
 * file auto-templates the conversation cycle (createCycle) and emits
 * one calendar-link row per conversation via the PdpCalendarWriter
 * (NativeWriter today; Spond writer plugs in via #0031).
 */
class PdpFilesRestController {

    private const NS = 'talenttrack/v1';

    public static function init(): void {
        add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
    }

    public static function register(): void {
        register_rest_route( self::NS, '/pdp-files', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'list' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'POST',
                'callback'            => [ __CLASS__, 'create' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
        register_rest_route( self::NS, '/pdp-files/(?P<id>\d+)', [
            [
                'methods'             => 'GET',
                'callback'            => [ __CLASS__, 'get_one' ],
                'permission_callback' => [ __CLASS__, 'can_view' ],
            ],
            [
                'methods'             => 'PATCH',
                'callback'            => [ __CLASS__, 'patch' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ] );
    }

    public static function can_view(): bool {
        return current_user_can( 'tt_view_pdp' ) || current_user_can( 'tt_edit_pdp' );
    }

    public static function can_edit(): bool {
        return current_user_can( 'tt_edit_pdp' );
    }

    /** GET /pdp-files — coach sees own players' files; admin sees all. */
    public static function list( \WP_REST_Request $r ): \WP_REST_Response {
        $season_id = absint( $r['season_id'] ?? 0 );
        if ( $season_id <= 0 ) {
            $current = ( new SeasonsRepository() )->current();
            $season_id = $current ? (int) $current->id : 0;
        }
        if ( $season_id <= 0 ) {
            return RestResponse::success( [ 'rows' => [], 'season_id' => 0 ] );
        }

        $repo = new PdpFilesRepository();
        if ( current_user_can( 'tt_edit_settings' ) ) {
            $rows = $repo->listForSeason( $season_id );
        } else {
            $rows = $repo->listForCoach( get_current_user_id(), $season_id );
        }

        return RestResponse::success( [
            'rows'      => array_map( [ __CLASS__, 'format_file' ], $rows ),
            'season_id' => $season_id,
        ] );
    }

    /**
     * POST /pdp-files — body: { player_id, season_id?, owner_coach_id?,
     * cycle_size?, notes? }. Creates the file and the conversation cycle.
     */
    public static function create( \WP_REST_Request $r ): \WP_REST_Response {
        $player_id = absint( $r['player_id'] ?? 0 );
        if ( $player_id <= 0 ) {
            return RestResponse::error( 'missing_fields',
                __( 'Player is required.', 'talenttrack' ), 400 );
        }

        $seasons = new SeasonsRepository();
        $season_id = absint( $r['season_id'] ?? 0 );
        $season    = $season_id > 0 ? $seasons->find( $season_id ) : $seasons->current();
        if ( ! $season ) {
            return RestResponse::error( 'no_season',
                __( 'No current season is set. Configure a season before creating PDP files.', 'talenttrack' ), 400 );
        }

        // Coach-scope guard: non-admins can only create for their own players.
        if ( ! current_user_can( 'tt_edit_settings' ) ) {
            if ( ! QueryHelpers::coach_owns_player( get_current_user_id(), $player_id ) ) {
                return RestResponse::error( 'forbidden',
                    __( 'You can only create PDP files for players on your own teams.', 'talenttrack' ), 403 );
            }
        }

        $cycle_size = self::resolveCycleSize( $r, $player_id );
        $owner_id   = absint( $r['owner_coach_id'] ?? get_current_user_id() );

        $files = new PdpFilesRepository();
        $file_id = $files->create( [
            'player_id'      => $player_id,
            'season_id'      => (int) $season->id,
            'owner_coach_id' => $owner_id,
            'cycle_size'     => $cycle_size,
            'notes'          => (string) ( $r['notes'] ?? '' ),
        ] );
        if ( $file_id <= 0 ) {
            return RestResponse::error( 'duplicate_or_failed',
                __( 'A PDP file already exists for this player and season, or the database refused the write.', 'talenttrack' ),
                409 );
        }

        $convs    = new PdpConversationsRepository();
        $created  = $convs->createCycle( $file_id, $cycle_size, (string) $season->start_date, (string) $season->end_date );
        if ( $created !== $cycle_size ) {
            Logger::error( 'pdp.cycle.partial', [
                'file_id'   => $file_id,
                'expected'  => $cycle_size,
                'created'   => $created,
            ] );
        }

        // Calendar link per conversation. Sprint 1 native writer no-ops
        // beyond inserting a tt_pdp_calendar_links row; the Spond writer
        // (#0031) replaces the implementation, not the call site.
        $writer = PdpCalendarWriters::default();
        foreach ( $convs->listForFile( $file_id ) as $c ) {
            $writer->onConversationScheduled( (int) $c->id );
        }

        return RestResponse::success( [
            'id'                  => $file_id,
            'cycle_size'          => $cycle_size,
            'conversations_seeded' => $created,
        ] );
    }

    public static function get_one( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $files = new PdpFilesRepository();
        $file  = $files->find( $id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canSeeFile( $file ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have access to this PDP file.', 'talenttrack' ), 403 );
        }

        $conversations = ( new PdpConversationsRepository() )->listForFile( $id );

        return RestResponse::success( [
            'file'          => self::format_file( $file ),
            'conversations' => array_map( [ __CLASS__, 'format_conversation' ], $conversations ),
        ] );
    }

    /** PATCH /pdp-files/{id} — owner_coach_id and/or status. */
    public static function patch( \WP_REST_Request $r ): \WP_REST_Response {
        $id = absint( $r['id'] );
        if ( $id <= 0 ) {
            return RestResponse::error( 'bad_id', __( 'Invalid PDP file id.', 'talenttrack' ), 400 );
        }
        $files = new PdpFilesRepository();
        $file  = $files->find( $id );
        if ( ! $file ) {
            return RestResponse::error( 'not_found', __( 'PDP file not found.', 'talenttrack' ), 404 );
        }
        if ( ! self::canEditFile( $file ) ) {
            return RestResponse::error( 'forbidden',
                __( 'You do not have permission to edit this PDP file.', 'talenttrack' ), 403 );
        }

        $changed = false;
        if ( array_key_exists( 'owner_coach_id', (array) $r->get_params() ) ) {
            $owner = $r['owner_coach_id'];
            $owner_id = ( $owner === null || $owner === '' ) ? null : absint( $owner );
            if ( ! $files->setOwner( $id, $owner_id ) ) {
                return RestResponse::error( 'db_error',
                    __( 'Owner update failed.', 'talenttrack' ), 500 );
            }
            $changed = true;
        }
        if ( isset( $r['status'] ) ) {
            $status = sanitize_text_field( (string) $r['status'] );
            if ( ! $files->setStatus( $id, $status ) ) {
                return RestResponse::error( 'bad_status',
                    __( 'Invalid status. Use open, completed, or archived.', 'talenttrack' ), 400 );
            }
            $changed = true;
        }
        if ( ! $changed ) {
            return RestResponse::success( [ 'id' => $id, 'unchanged' => true ] );
        }
        return RestResponse::success( [ 'id' => $id ] );
    }

    private static function resolveCycleSize( \WP_REST_Request $r, int $player_id ): int {
        $explicit = absint( $r['cycle_size'] ?? 0 );
        if ( in_array( $explicit, [ 2, 3, 4 ], true ) ) return $explicit;

        // Per-team override → club default → 3.
        global $wpdb; $p = $wpdb->prefix;
        $team_size = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT t.pdp_cycle_size
               FROM {$p}tt_players pl
               LEFT JOIN {$p}tt_teams t ON t.id = pl.team_id
              WHERE pl.id = %d",
            $player_id
        ) );
        if ( in_array( $team_size, [ 2, 3, 4 ], true ) ) return $team_size;

        $club_default = (int) QueryHelpers::get_config( 'pdp_cycle_default', '3' );
        return in_array( $club_default, [ 2, 3, 4 ], true ) ? $club_default : 3;
    }

    private static function canSeeFile( object $file ): bool {
        if ( current_user_can( 'tt_edit_settings' ) ) return true;
        if ( current_user_can( 'tt_edit_pdp' ) ) {
            return QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
        }
        return current_user_can( 'tt_view_pdp' )
            && QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
    }

    private static function canEditFile( object $file ): bool {
        if ( current_user_can( 'tt_edit_settings' ) ) return true;
        if ( ! current_user_can( 'tt_edit_pdp' ) ) return false;
        return QueryHelpers::coach_owns_player( get_current_user_id(), (int) $file->player_id );
    }

    /** @return array<string,mixed> */
    private static function format_file( object $row ): array {
        return [
            'id'             => (int) $row->id,
            'player_id'      => (int) $row->player_id,
            'season_id'      => (int) $row->season_id,
            'owner_coach_id' => $row->owner_coach_id !== null ? (int) $row->owner_coach_id : null,
            'cycle_size'     => $row->cycle_size !== null ? (int) $row->cycle_size : null,
            'status'         => (string) $row->status,
            'notes'          => $row->notes,
            'created_at'     => $row->created_at,
            'updated_at'     => $row->updated_at,
        ];
    }

    /** @return array<string,mixed> */
    private static function format_conversation( object $row ): array {
        return [
            'id'                => (int) $row->id,
            'pdp_file_id'       => (int) $row->pdp_file_id,
            'sequence'          => (int) $row->sequence,
            'template_key'      => (string) $row->template_key,
            'scheduled_at'      => $row->scheduled_at,
            'conducted_at'      => $row->conducted_at,
            'agenda'            => $row->agenda,
            'notes'             => $row->notes,
            'agreed_actions'    => $row->agreed_actions,
            'player_reflection' => $row->player_reflection,
            'coach_signoff_at'  => $row->coach_signoff_at,
            'parent_ack_at'     => $row->parent_ack_at,
            'player_ack_at'     => $row->player_ack_at,
        ];
    }
}
