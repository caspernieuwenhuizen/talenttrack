<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Domain\Vocabularies\Lookups\AttendanceStatus;
use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;
use TT\Modules\DemoData\DemoRoster;

/**
 * ActivityGenerator — fills tt_activities + tt_attendance.
 *
 * Cadence: 2 sessions per team per week across the activity window, from
 * `DemoCalendar` so the evaluation generator can write a match evaluation
 * about a fixture that exists rather than about a date it guessed (#3401).
 *
 * Who attended comes from `DemoRoster`, not from the player's current team:
 * a session two seasons ago belongs to the squad the player was in then
 * (#3402). A team with nobody on its roster in a season gets no sessions in
 * it at all — an academy adds age groups as its cohort grows, and a
 * calendar full of sessions nobody attended is worse than a shorter one.
 *
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

    private DemoCalendar $calendar;

    private DemoRoster $roster;

    public static function category(): string {
        return 'activities';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self(
            $ctx->registry,
            $ctx->teams,
            $ctx->historicPlayers(),
            $ctx->weeks(),
            $ctx->contentLanguage,
            $ctx->calendar(),
            $ctx->roster()
        );
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
        string $language = '',
        ?DemoCalendar $calendar = null,
        ?DemoRoster $roster = null
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->players  = $players;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
        $this->roster   = $roster ?? new DemoRoster( $this->calendar, $teams, $players );
    }

    public function generate(): int {
        global $wpdb;

        $attendance_writer = new \TT\Modules\Activities\Repositories\AttendanceWriter();

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

        $resolved_language = self::resolveLanguage( $this->language );
        $strings           = self::SESSION_STRINGS_BY_LANGUAGE[ $resolved_language ];

        // #3030 — the window straddles today instead of ending on it. The
        // four-week horizon ahead is what the week planner, match prep and
        // the upcoming-activity alerts point at; without it a demo install
        // had nothing planned at all. It lives in `DemoCalendar` now, with
        // the fixture rule, so the two cannot drift apart.
        $slots = $this->calendar->activitySlots();

        $total = 0;
        foreach ( $this->teams as $team ) {
            $team_id  = (int) $team->id;
            $coach_id = (int) $team->head_coach_user_id;

            foreach ( $slots as $slot ) {
                $when   = (string) $slot['date'];
                $roster = $this->roster->rosterFor( $team_id, $when );
                if ( ! $roster ) continue; // the team did not field a squad yet

                $is_future = (bool) $slot['is_future'];
                $is_game   = (bool) $slot['is_game'];
                $title     = sprintf(
                    $is_game ? $strings['game_title_template'] : $strings['title_template'],
                    (int) $slot['week'] + 1,
                    (int) $slot['slot'] + 1
                );

                $wpdb->insert( "{$wpdb->prefix}tt_activities", [
                    'club_id'             => CurrentClub::id(),
                    'title'               => $title,
                    'session_date'        => $when,
                    'location'            => $strings['default_location'],
                    'team_id'             => $team_id,
                    'coach_id'            => $coach_id,
                    'notes'               => '',
                    'activity_type_key'   => $is_game ? 'game' : 'training',
                    'activity_status_key' => $is_future ? 'planned' : 'completed',
                    // #3030 — `plan_state` is the other lifecycle axis
                    // (migration 0144), and the planner, match prep and
                    // the player profile's activity list all read it. It
                    // used to fall to the column's 'completed' default,
                    // which was harmless while every generated row was in
                    // the past and is wrong now that some are not.
                    'plan_state'          => $is_future ? 'scheduled' : 'completed',
                    'activity_source_key' => 'generated',
                    'game_subtype_key'    => $slot['subtype'],
                    'other_label'         => null,
                ] );
                $activity_id = (int) $wpdb->insert_id;
                if ( ! $activity_id ) continue;
                $this->registry->tag( 'activity', $activity_id, [ 'team_id' => $team_id ] );
                $total++;

                // #3484 — plan the squad, then register it. The two are
                // separate rows of separate kinds, and a generated academy
                // used to contain only the second: 13,344 `actual` rows and
                // zero `expected` ones, so half of what `tt_attendance`
                // models did not exist in demo data.
                //
                // That is why the ten defects of the last week — a planned
                // roster read as a recorded register, or a write landing on
                // the wrong kind of row — were none of them reproducible
                // locally, and why the fixes for them cannot be exercised
                // end to end either.
                //
                // A coach plans, then registers, so the plan is written for
                // every activity including the future ones.
                $this->planSquad( $attendance_writer, $activity_id, $roster );

                // #3030 — an activity that has not happened yet carries no
                // *register*. Writing `actual` rows for next Tuesday would be
                // inventing a result, and would leave the attendance flow
                // with nothing to demonstrate. The plan above is exactly the
                // state `your_attendance_status` got wrong in #3390.
                if ( $is_future ) continue;

                // #3484 — and a deliberate minority of past activities are
                // left planned-but-unregistered, so the completeness work
                // from epic #3442 (the empty-register confirm, the N/N
                // counts, AttendanceUnrecordedAlert) has a case to show.
                // One in eight, by a stable hash of the activity rather than
                // a roll, so the same seed produces the same academy.
                if ( $activity_id % 8 === 3 ) continue;

                foreach ( $roster as $player ) {
                    $player_id = (int) ( $player->id ?? 0 );
                    if ( $player_id <= 0 ) continue;

                    $label  = $this->pickAttendance( (int) ( $tendencies[ $player_id ] ?? 0 ) );
                    $status = $attendance_lookup[ $label ] ?? $label;

                    // #3029 — stated rather than inherited from the column
                    // default. Every minutes and attendance read filters on
                    // `record_type = 'actual'` (#2193), and MatchDayGenerator
                    // matches on it when it writes minutes back onto these
                    // rows; a demo dataset should not depend on a schema
                    // default staying put for either to work. #3451 moved the
                    // statement from a key in the map to the method name.
                    $att_id = (int) ( $attendance_writer->recordActual( [
                        'club_id'     => CurrentClub::id(),
                        'activity_id' => $activity_id,
                        'player_id'  => $player_id,
                        'status'     => $status,
                        'notes'      => '',
                    ] ) ?? 0 );
                    if ( $att_id ) {
                        $this->registry->tag( 'attendance', $att_id );
                    }
                }
            }
        }
        return $total;
    }

    /**
     * #3484 — the planned roster for one activity: one `expected` row per
     * player on the squad, written when the activity is created.
     *
     * The statuses are the real plan vocabulary, not all-Present. The plan
     * form offers Expected / Not coming / Maybe, which store as Present /
     * Absent / Excused (`ActivitiesRestController::plannedStatusMap()`), and
     * "a Maybe pre-selecting as a recorded Excused" was one of #3443's three
     * bugs — a demo where every plan says Present cannot show it.
     *
     * Mostly Expected, because that is what a squad list looks like: a
     * handful of replies and everyone else assumed in.
     *
     * @param list<object> $roster tt_players rows.
     */
    private function planSquad(
        \TT\Modules\Activities\Repositories\AttendanceWriter $writer,
        int $activity_id,
        array $roster
    ): void {
        foreach ( $roster as $player ) {
            $player_id = (int) ( $player->id ?? 0 );
            if ( $player_id <= 0 ) continue;

            $roll = mt_rand( 1, 100 );
            if ( $roll <= 8 ) {
                $status = AttendanceStatus::ABSENT;   // "Not coming"
            } elseif ( $roll <= 20 ) {
                $status = AttendanceStatus::EXCUSED;  // "Maybe"
            } else {
                $status = AttendanceStatus::PRESENT;  // "Expected"
            }

            $row_id = (int) ( $writer->planExpected( [
                'club_id'     => CurrentClub::id(),
                'activity_id' => $activity_id,
                'player_id'   => $player_id,
                'status'      => $status,
                'notes'       => '',
            ] ) ?? 0 );

            if ( $row_id ) {
                $this->registry->tag( 'attendance', $row_id );
            }
        }
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
