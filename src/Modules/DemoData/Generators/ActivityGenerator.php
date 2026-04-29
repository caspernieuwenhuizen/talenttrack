<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

/**
 * ActivityGenerator — fills tt_activities + tt_attendance.
 *
 * Cadence: 2 sessions per team per week across the activity window.
 * Attendance mix per session: 85% Present, 10% Absent, 5% Late, plus
 * a per-player tendency so the same player skews a little high or low
 * across all their sessions (more realistic than uniform random).
 *
 * Content language: session title template + default location render in
 * whichever locale the demo operator picked on the Generate form. Uses
 * the same first-class per-language dictionary pattern as
 * GoalGenerator — not reliant on .po/.mo tooling. Extend by adding a
 * key to SESSION_STRINGS_BY_LANGUAGE.
 */
class ActivityGenerator {

    /** Attendance distribution as cumulative weights. */
    private const ATTENDANCE = [
        [ 85, 'Present' ],
        [ 95, 'Absent'  ],
        [ 100, 'Late'   ],
    ];

    /** @var array<string, array{title_template:string, default_location:string}> */
    private const SESSION_STRINGS_BY_LANGUAGE = [
        'en_US' => [
            'title_template'   => 'Training %d.%d',
            'default_location' => 'Home pitch',
        ],
        'nl_NL' => [
            'title_template'   => 'Training %d.%d',
            'default_location' => 'Thuisveld',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var object[] */
    private array $players;

    private int $weeks;

    private string $language;

    /**
     * @param object[] $teams
     * @param object[] $players
     */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        array $players,
        int $weeks,
        string $language = ''
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
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

        $resolved_language = self::resolveLanguage( $this->language );
        $strings           = self::SESSION_STRINGS_BY_LANGUAGE[ $resolved_language ];

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id  = (int) $team->id;
            $coach_id = (int) $team->head_coach_id;
            $roster   = $players_by_team[ $team_id ] ?? [];
            if ( ! $roster ) continue;

            for ( $w = 0; $w < $this->weeks; $w++ ) {
                for ( $s = 0; $s < 2; $s++ ) {
                    // 2 activities per week, spaced Tue / Thu-ish
                    // — second slot of every 3rd week becomes a game.
                    $day_offset = ( $w * 7 ) + ( $s === 0 ? 1 : 3 );
                    $when = $start_date + $day_offset * DAY_IN_SECONDS;

                    $is_game = ( $s === 1 && ( $w % 3 ) === 2 );
                    $type    = $is_game ? 'game' : 'training';
                    $subtype = null;
                    if ( $is_game ) {
                        $sub_pool = [ 'League', 'League', 'Cup', 'Friendly' ];
                        $subtype  = $sub_pool[ $w % count( $sub_pool ) ];
                    }
                    $title = $is_game
                        ? sprintf( 'Game %d.%d', $w + 1, $s + 1 )
                        : sprintf( $strings['title_template'], $w + 1, $s + 1 );

                    $wpdb->insert( "{$wpdb->prefix}tt_activities", [
                        'club_id'           => CurrentClub::id(),
                        'title'             => $title,
                        'session_date'      => gmdate( 'Y-m-d', $when ),
                        'location'          => $strings['default_location'],
                        'team_id'           => $team_id,
                        'coach_id'          => $coach_id,
                        'notes'             => '',
                        'activity_type_key' => $type,
                        'game_subtype_key'  => $subtype,
                        'other_label'       => null,
                    ] );
                    $activity_id = (int) $wpdb->insert_id;
                    if ( ! $activity_id ) continue;
                    $this->registry->tag( 'activity', $activity_id, [ 'team_id' => $team_id ] );
                    $total++;

                    foreach ( $roster as $player_id ) {
                        $label = $this->pickAttendance( (int) $tendencies[ $player_id ] );
                        $status = $attendance_lookup[ $label ] ?? $label;

                        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
                            'club_id'     => CurrentClub::id(),
                            'activity_id' => $activity_id,
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

    /**
     * Full-locale match first, language-prefix match second (e.g.
     * `nl_BE` → `nl_NL`), en_US last-resort.
     */
    public static function resolveLanguage( string $locale ): string {
        if ( $locale !== '' && isset( self::SESSION_STRINGS_BY_LANGUAGE[ $locale ] ) ) {
            return $locale;
        }
        $prefix = substr( $locale, 0, 2 );
        if ( $prefix !== '' ) {
            foreach ( array_keys( self::SESSION_STRINGS_BY_LANGUAGE ) as $key ) {
                if ( substr( (string) $key, 0, 2 ) === $prefix ) return (string) $key;
            }
        }
        return 'en_US';
    }
}
