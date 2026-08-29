<?php
namespace TT\Modules\Comms\Send;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Comms\Dispatch\CommsDispatcher;
use TT\Modules\Comms\Domain\CommsResult;
use TT\Modules\Comms\Domain\MessageType;
use TT\Modules\Comms\Domain\Recipient;
use TT\Modules\Comms\Recipient\RecipientResolver;
use TT\Modules\Comms\Templates\PdpReadyTemplate;
use TT\Shared\Frontend\Components\RecordLink;

/**
 * PdpReadySend (#2605 Gate D) — use case 3's trigger.
 *
 * ## Why sign-off is the moment
 *
 * The template's own note says "when a development plan is published",
 * and a PDP has no published state: `PdpStatus` is `open | completed |
 * archived` and nothing in the module means "the family may now read
 * this". What does exist is the verdict sign-off — the point at which
 * the coach and the head of academy have both put their name to the
 * plan and it stops being a working draft. That is the moment a family
 * should hear about, so that is the moment this listens for.
 *
 * `tt_pdp_verdict_signed_off` is edge-triggered in
 * `PdpVerdictsRepository::upsertForFile()` — it fires on the false→true
 * transition and not on a re-save — so a family is told once and not
 * again every time a coach corrects a typo in the summary.
 *
 * The hook carries the verdict id and the PDP file id and no player, so
 * the file is re-read here to find whose plan it is.
 */
final class PdpReadySend {

    public static function init(): void {
        add_action( 'tt_pdp_verdict_signed_off', [ __CLASS__, 'handle' ], 10, 2 );
    }

    /**
     * Action-hook entry point. `do_action()` has nowhere to put a return
     * value; the obligation here is the audit trail.
     */
    public static function handle( int $verdict_id, int $pdp_file_id ): void {
        self::send( $pdp_file_id );
    }

    /**
     * @return CommsResult[]
     */
    public static function send( int $pdp_file_id ): array {
        if ( $pdp_file_id <= 0 ) return [];

        $file = self::loadFile( $pdp_file_id );
        if ( $file === null ) return [];

        $player_id = (int) ( $file->player_id ?? 0 );
        if ( $player_id <= 0 ) return [];

        return CommsDispatcher::dispatchSync(
            PdpReadyTemplate::KEY,
            [
                'player_name' => self::playerName( $player_id ),
                'season_name' => (string) ( $file->season_name ?? '' ),
                // Into the player's record, not at the plan document — the
                // template's own note, and the record is where a parent can
                // see the plan in the context of everything else.
                'deep_link'   => add_query_arg(
                    [ 'tt_view' => 'players', 'id' => $player_id ],
                    RecordLink::dashboardUrl()
                ),
            ],
            ( new RecipientResolver() )->forPlayer( $player_id ),
            [
                'message_type'   => MessageType::PDP_READY,
                'sender_user_id' => 0,
            ]
        );
    }

    /**
     * The PDP file with its season name joined on. Archived files are
     * included: a plan signed off and then archived was still signed off,
     * and swallowing the message would be the surprising behaviour.
     */
    private static function loadFile( int $pdp_file_id ): ?object {
        global $wpdb;
        $p = $wpdb->prefix;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT f.id, f.player_id, s.name AS season_name
               FROM {$p}tt_pdp_files f
               LEFT JOIN {$p}tt_seasons s ON s.id = f.season_id
              WHERE f.id = %d AND f.club_id = %d
              LIMIT 1",
            $pdp_file_id, CurrentClub::id()
        ) );
        return $row ?: null;
    }

    private static function playerName( int $player_id ): string {
        global $wpdb;
        $row = $wpdb->get_row( $wpdb->prepare(
            "SELECT first_name, last_name FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id, CurrentClub::id()
        ) );
        if ( ! $row ) return '';
        return trim( (string) ( $row->first_name ?? '' ) . ' ' . (string) ( $row->last_name ?? '' ) );
    }
}
