<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * SessionGenerator — fills tt_sessions + tt_attendance.
 *
 * Cadence: 2 sessions per team per week across the activity window.
 * Attendance mix per session: 85% Present, 10% Absent, 5% Late, plus
 * a per-player tendency so the same player skews a little high or low
 * across all their sessions (more realistic than uniform random).
 */
class SessionGenerator {

    /** Attendance distribution as cumulative weights. */
    private const ATTENDANCE = [
        [ 85, 'Present' ],
        [ 95, 'Absent'  ],
        [ 100, 'Late'   ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var object[] */
    private array $players;

    private int $weeks;

    /**
     * @param object[] $teams
     * @param object[] $players
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        array $players,
        int $weeks
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
    }

    public function generate(): int {
        global $wpdb;

        $attendance_lookup = $this->loadAttendanceLookups();
        if ( ! $attendance_lookup ) {
            // Tolerable: insert with label strings, the plugin stores the label.
            $attendance_lookup = [ 'Present' => 'Present', 'Absent' => 'Absent', 'Late' => 'Late' ];
        }

        // Per-player attendance tendency: -20 .. +10 shifts the roll.
        $tendencies = [];
        foreach ( $this->players as $p ) {
            $tendencies[ (int) $p->id ] = mt_rand( -20, 10 );
        }

        $players_by_team = [];
        foreach ( $this->players as $p ) {
            $players_by_team[ (int) $p->team_id ][] = (int) $p->id;
        }

        $start_date = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $start_date === false ) $start_date = time();

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id  = (int) $team->id;
            $coach_id = (int) $team->head_coach_id;
            $roster   = $players_by_team[ $team_id ] ?? [];
            if ( ! $roster ) continue;

            for ( $w = 0; $w < $this->weeks; $w++ ) {
                for ( $s = 0; $s < 2; $s++ ) {
                    // 2 sessions per week, spaced Tue / Thu-ish
                    $day_offset = ( $w * 7 ) + ( $s === 0 ? 1 : 3 );
                    $when = $start_date + $day_offset * DAY_IN_SECONDS;

                    $wpdb->insert( "{$wpdb->prefix}tt_sessions", [
                        'title'        => sprintf( 'Training %d.%d', $w + 1, $s + 1 ),
                        'session_date' => gmdate( 'Y-m-d', $when ),
                        'location'     => 'Home pitch',
                        'team_id'      => $team_id,
                        'coach_id'     => $coach_id,
                        'notes'        => '',
                    ] );
                    $session_id = (int) $wpdb->insert_id;
                    if ( ! $session_id ) continue;
                    $this->registry->tag( 'session', $session_id, [ 'team_id' => $team_id ] );
                    $total++;

                    foreach ( $roster as $player_id ) {
                        $label = $this->pickAttendance( (int) $tendencies[ $player_id ] );
                        $status = $attendance_lookup[ $label ] ?? $label;

                        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
                            'session_id' => $session_id,
                            'player_id'  => $player_id,
                            'status'     => $status,
                            'notes'      => '',
                        ] );
                        $att_id = (int) $wpdb->insert_id;
                        if ( $att_id ) {
                            $this->registry->tag( 'attendance', $att_id );
                        }
                    }
                }
            }
        }
        return $total;
    }

    /**
     * @return array<string,string> label -> stored value
     */
    private function loadAttendanceLookups(): array {
        $items = QueryHelpers::get_lookups( 'attendance_status' );
        $out = [];
        foreach ( $items as $it ) {
            $out[ (string) $it->name ] = (string) $it->name;
        }
        return $out;
    }

    private function pickAttendance( int $tendency ): string {
        $roll = mt_rand( 1, 100 ) + $tendency;
        foreach ( self::ATTENDANCE as [ $cut, $label ] ) {
            if ( $roll <= $cut ) return $label;
        }
        return 'Present';
    }
}
