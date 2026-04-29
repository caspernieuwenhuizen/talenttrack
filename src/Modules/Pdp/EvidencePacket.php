<?php
namespace TT\Modules\Pdp;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\PlayerStatus\PlayerStatusCalculator;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\Players\Repositories\PlayerBehaviourRatingsRepository;
use TT\Modules\Players\Repositories\PlayerPotentialRepository;

/**
 * EvidencePacket (#0057 Sprint 5) — auto-assembles the data the HoD
 * needs to defend a verdict at a PDP meeting.
 *
 * Reads-only aggregation from existing tables; nothing is mutated by
 * building a packet. The packet shape is stable enough to JSON-serialise
 * for export but the primary consumer today is `FrontendPdpManageView`'s
 * meeting surface.
 *
 *   $packet = EvidencePacket::forFile( $file_id );
 *   $packet['status']          // current StatusVerdict
 *   $packet['behaviour']       // recent ratings in window
 *   $packet['potential']       // history in window
 *   $packet['evaluations']     // finalised evals in window
 *   $packet['attendance']      // sessions/present/absent/excused
 */
final class EvidencePacket {

    /**
     * @return array{
     *   file_id:int,
     *   player_id:int,
     *   season:array{id:int,name:string,start_date:string,end_date:string},
     *   status:array<string,mixed>,
     *   behaviour:list<object>,
     *   potential:list<object>,
     *   evaluations:list<object>,
     *   attendance:array<string,mixed>,
     *   recent_journey:list<object>
     * }|null
     */
    public static function forFile( int $file_id ): ?array {
        global $wpdb;
        $p = $wpdb->prefix;

        $file = $wpdb->get_row( $wpdb->prepare(
            "SELECT f.id, f.player_id, f.season_id, s.name AS season_name, s.start_date, s.end_date
               FROM {$p}tt_pdp_files f
          LEFT JOIN {$p}tt_seasons s ON s.id = f.season_id AND s.club_id = f.club_id
              WHERE f.id = %d AND f.club_id = %d",
            $file_id, CurrentClub::id()
        ) );
        if ( ! $file ) return null;

        $player_id = (int) $file->player_id;
        $win_from  = (string) ( $file->start_date ?? gmdate( 'Y-m-d', strtotime( '-1 year' ) ) );
        $win_to    = (string) ( $file->end_date   ?? gmdate( 'Y-m-d' ) );

        $verdict = ( new PlayerStatusCalculator() )->calculate( $player_id );

        $behaviour = ( new PlayerBehaviourRatingsRepository() )->listForPlayer( $player_id, 50 );
        $behaviour = array_values( array_filter( $behaviour, static function ( $row ) use ( $win_from, $win_to ) {
            $rated = substr( (string) ( $row->rated_at ?? '' ), 0, 10 );
            return $rated >= $win_from && $rated <= $win_to;
        } ) );

        $potential = ( new PlayerPotentialRepository() )->historyFor( $player_id );
        $potential = array_values( array_filter( $potential, static function ( $row ) use ( $win_from, $win_to ) {
            $set = substr( (string) ( $row->set_at ?? '' ), 0, 10 );
            return $set >= $win_from && $set <= $win_to;
        } ) );

        $evaluations = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, eval_date, overall_rating
               FROM {$p}tt_evaluations
              WHERE player_id = %d
                AND club_id = %d
                AND status_finalized = 1
                AND eval_date >= %s
                AND eval_date <= %s
              ORDER BY eval_date DESC",
            $player_id, CurrentClub::id(), $win_from, $win_to
        ) );

        $attendance_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS sessions,
                SUM(CASE WHEN att.status = 'Present' THEN 1 ELSE 0 END) AS present,
                SUM(CASE WHEN att.status = 'Absent'  THEN 1 ELSE 0 END) AS absent,
                SUM(CASE WHEN att.status = 'Excused' THEN 1 ELSE 0 END) AS excused
              FROM {$p}tt_attendance att
              JOIN {$p}tt_activities act ON act.id = att.activity_id AND act.club_id = att.club_id
             WHERE att.player_id = %d
               AND att.club_id = %d
               AND att.is_guest = 0
               AND act.session_date >= %s
               AND act.session_date <= %s",
            $player_id, CurrentClub::id(), $win_from, $win_to
        ), ARRAY_A );

        $recent_journey = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, event_type, event_date, summary
               FROM {$p}tt_player_events
              WHERE player_id = %d
                AND club_id = %d
                AND superseded_by_event_id IS NULL
                AND event_date >= %s
                AND event_date <= %s
              ORDER BY event_date DESC, id DESC
              LIMIT 30",
            $player_id, CurrentClub::id(), $win_from, $win_to
        ) );

        return [
            'file_id'        => $file_id,
            'player_id'      => $player_id,
            'season'         => [
                'id'         => (int) ( $file->season_id ?? 0 ),
                'name'       => (string) ( $file->season_name ?? '' ),
                'start_date' => $win_from,
                'end_date'   => $win_to,
            ],
            'status'         => $verdict->toArray(),
            'behaviour'      => is_array( $behaviour )      ? $behaviour      : [],
            'potential'      => is_array( $potential )      ? $potential      : [],
            'evaluations'    => is_array( $evaluations )    ? $evaluations    : [],
            'attendance'     => is_array( $attendance_row ) ? $attendance_row : [],
            'recent_journey' => is_array( $recent_journey ) ? $recent_journey : [],
        ];
    }

    /**
     * Map a StatusVerdict colour → suggested verdict decision.
     */
    public static function suggestDecisionFromStatus( string $status_color ): string {
        switch ( $status_color ) {
            case 'green':   return 'renew';
            case 'amber':   return 'renew_with_dev_plan';
            case 'red':     return 'terminate';
            default:        return '';
        }
    }
}
