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
class ActivityGenerator implements DependentGeneratorInterface {

    /** Attendance distribution as cumulative weights. */
    private const ATTENDANCE = [
        [ 85, 'Present' ],
        [ 95, 'Absent'  ],
        [ 100, 'Late'   ],
    ];

    /** @var array<string, array{title_template:string, game_title_template:string, default_location:string}> */
    private const SESSION_STRINGS_BY_LANGUAGE = [
        'en_US' => [
            'title_template'      => 'Training %d.%d',
            'game_title_template' => 'Match %d.%d',
            'default_location'    => 'Home pitch',
        ],
        'nl_NL' => [
            'title_template'      => 'Training %d.%d',
            'game_title_template' => 'Wedstrijd %d.%d',
            'default_location'    => 'Thuisveld',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    /** @var object[] */
    private array $players;

    private int $weeks;

    private string $language;

    public static function category(): string {
        return 'activities';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->players, $ctx->weeks(), $ctx->contentLanguage );
    }

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

        // #3030 — the window straddles today instead of ending on it.
        //
        // `weeks` is the depth of HISTORY the preset asks for; the horizon
        // below is what comes next. Without it the last generated activity
        // landed on today and a demo install had nothing planned at all —
        // no next match, nothing for the week planner, match prep or the
        // upcoming-activity alerts to point at. Half the product is about
        // what happens next, and it looked empty in the dataset used to
        // show the product.
        //
        // Four weeks regardless of preset: it is enough for every surface
        // that reads forward, and scaling it with `weeks` would give the
        // large preset a nine-month fixture list nobody looks at.
        $horizon_weeks = 4;
        $total_weeks   = $this->weeks + $horizon_weeks;

        $resolved_language = self::resolveLanguage( $this->language );
        $strings           = self::SESSION_STRINGS_BY_LANGUAGE[ $resolved_language ];

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id  = (int) $team->id;
            $coach_id = (int) $team->head_coach_user_id;
            $roster   = $players_by_team[ $team_id ] ?? [];
            if ( ! $roster ) continue;

            for ( $w = 0; $w < $total_weeks; $w++ ) {
                for ( $s = 0; $s < 2; $s++ ) {
                    // 2 activities per week, spaced Tue / Thu-ish
                    // — second slot of every 3rd week becomes a game.
                    $day_offset = ( $w * 7 ) + ( $s === 0 ? 1 : 3 );
                    $when = $start_date + $day_offset * DAY_IN_SECONDS;

                    $is_future = $when >= time();
                    $is_game = ( $s === 1 && ( $w % 3 ) === 2 );
                    $type    = $is_game ? 'game' : 'training';
                    $subtype = null;
                    if ( $is_game ) {
                        $sub_pool = [ 'League', 'League', 'Cup', 'Friendly' ];
                        $subtype  = $sub_pool[ $w % count( $sub_pool ) ];
                    }
                    $title = sprintf(
                        $is_game ? $strings['game_title_template'] : $strings['title_template'],
                        $w + 1,
                        $s + 1
                    );

                    $wpdb->insert( "{$wpdb->prefix}tt_activities", [
                        'club_id'             => CurrentClub::id(),
                        'title'               => $title,
                        'session_date'        => gmdate( 'Y-m-d', $when ),
                        'location'            => $strings['default_location'],
                        'team_id'             => $team_id,
                        'coach_id'            => $coach_id,
                        'notes'               => '',
                        'activity_type_key'   => $type,
                        'activity_status_key' => $is_future ? 'planned' : 'completed',
                        // #3030 — `plan_state` is the other lifecycle axis
                        // (migration 0144), and the planner, match prep and
                        // the player profile's activity list all read it. It
                        // used to fall to the column's 'completed' default,
                        // which was harmless while every generated row was in
                        // the past and is wrong now that some are not.
                        'plan_state'          => $is_future ? 'scheduled' : 'completed',
                        'activity_source_key' => 'generated',
                        'game_subtype_key'    => $subtype,
                        'other_label'         => null,
                    ] );
                    $activity_id = (int) $wpdb->insert_id;
                    if ( ! $activity_id ) continue;
                    $this->registry->tag( 'activity', $activity_id, [ 'team_id' => $team_id ] );
                    $total++;

                    // #3030 — an activity that has not happened yet carries no
                    // attendance. These rows are `record_type = 'actual'`, the
                    // record of who turned up; writing them for next Tuesday
                    // would be inventing a result, and it would leave the
                    // attendance flow with nothing to demonstrate. Match prep
                    // for future fixtures still comes from MatchDayGenerator,
                    // which already writes prep without execution.
                    if ( $is_future ) continue;

                    foreach ( $roster as $player_id ) {
                        $label = $this->pickAttendance( (int) $tendencies[ $player_id ] );
                        $status = $attendance_lookup[ $label ] ?? $label;

                        $wpdb->insert( "{$wpdb->prefix}tt_attendance", [
                            'club_id'     => CurrentClub::id(),
                            'activity_id' => $activity_id,
                            'player_id'  => $player_id,
                            'status'     => $status,
                            'notes'      => '',
                            // #3029 — stated rather than inherited from the
                            // column default. Every minutes and attendance
                            // read filters on `record_type = 'actual'`
                            // (#2193), and MatchDayGenerator matches on it
                            // when it writes minutes back onto these rows;
                            // a demo dataset should not depend on a schema
                            // default staying put for either to work.
                            'record_type' => 'actual',
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
