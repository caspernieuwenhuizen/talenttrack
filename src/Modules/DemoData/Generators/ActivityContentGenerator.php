<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;
use TT\Modules\DemoData\DemoCalendar;

/**
 * ActivityContentGenerator — gives a training session content.
 *
 * Attaches exercises from the club's library to each generated training,
 * links methodology principles to it, adds a few per-team exercise overrides,
 * and puts the season's holiday windows on the calendar.
 *
 * The exercise library itself is seeded by migration 0090, so this attaches
 * what is already there rather than inventing a parallel set — a demo club
 * with two different exercise libraries would be worse than one with none.
 */
class ActivityContentGenerator implements DependentGeneratorInterface {

    /** Exercises per training, and the share of the session they fill. */
    private const MIN_PER_SESSION = 4;
    private const MAX_PER_SESSION = 6;

    /**
     * The school year's breaks, by the calendar date they fall on (#4040):
     * `[ key, start month, start day, length in days, colour ]`, in calendar
     * order. Christmas runs into January, which is why the end is a length
     * rather than a second date.
     *
     * Approximate middle-region Dutch school dates. They only need to be the
     * right break in the right month — an academy editing the generated
     * calendar is expected, a July "Winterstop" is not.
     *
     * @var array<int, array{key:string, month:int, day:int, days:int, color:string}>
     */
    private const BREAKS = [
        [ 'key' => 'spring',    'month' => 2,  'day' => 14, 'days' => 8,  'color' => '#e4f0e2' ],
        [ 'key' => 'may',       'month' => 4,  'day' => 25, 'days' => 8,  'color' => '#f6f0d8' ],
        [ 'key' => 'summer',    'month' => 7,  'day' => 11, 'days' => 43, 'color' => '#fbe6d4' ],
        [ 'key' => 'autumn',    'month' => 10, 'day' => 17, 'days' => 8,  'color' => '#f0e0e8' ],
        [ 'key' => 'christmas', 'month' => 12, 'day' => 19, 'days' => 15, 'color' => '#d9e8f5' ],
    ];

    /** @var array<string, array<string, string>> */
    private const HOLIDAYS_BY_LANGUAGE = [
        'en_US' => [
            'spring'    => 'Spring break',
            'may'       => 'May break',
            'summer'    => 'Summer break',
            'autumn'    => 'Autumn break',
            'christmas' => 'Christmas break',
        ],
        'nl_NL' => [
            'spring'    => 'Voorjaarsvakantie',
            'may'       => 'Meivakantie',
            'summer'    => 'Zomerstop',
            'autumn'    => 'Herfstvakantie',
            'christmas' => 'Kerstvakantie',
        ],
    ];

    /** @var array<string, string> */
    private const HOLIDAY_NOTE_BY_LANGUAGE = [
        'en_US' => 'No training sessions.',
        'nl_NL' => 'Geen trainingen.',
    ];

    /** @var array<string, string[]> */
    private const NOTES_BY_LANGUAGE = [
        'en_US' => [
            'Keep the groups small so everyone gets touches.',
            'Coach the first touch — away from pressure.',
            'Progress to a free game if the tempo holds up.',
            'Watch the spacing; widen the pitch if it gets congested.',
        ],
        'nl_NL' => [
            'Houd de groepjes klein zodat iedereen veel balcontacten maakt.',
            'Coach op de aanname — weg van de druk.',
            'Bouw op naar een vrije partij als het tempo goed blijft.',
            'Let op de onderlinge afstanden; maak het veld breder als het te vol wordt.',
        ],
    ];

    private DemoBatchRegistry $registry;

    /** @var object[] */
    private array $teams;

    private int $weeks;

    private string $language;

    private DemoCalendar $calendar;

    public static function category(): string {
        return 'activity_content';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->weeks(), $ctx->contentLanguage, $ctx->calendar() );
    }

    /** @param object[] $teams */
    public function __construct(
        DemoBatchRegistry $registry,
        array $teams,
        int $weeks,
        string $language = '',
        ?DemoCalendar $calendar = null
    ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
        // #4040 — the run's pinned clock, not the wall clock of whichever
        // request happens to be running this chunk.
        $this->calendar = $calendar ?? new DemoCalendar( $this->weeks );
    }

    public function generate(): int {
        $total  = 0;
        $total += $this->attachExercises();
        $total += $this->linkPrinciples();
        $total += $this->generateTeamOverrides();
        $total += $this->generateHolidays();
        return $total;
    }

    /**
     * 4–6 exercises per generated training, ordered, with durations that add
     * up to roughly the session length.
     */
    private function attachExercises(): int {
        global $wpdb;

        $exercises = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, duration_minutes FROM {$wpdb->prefix}tt_exercises
              WHERE club_id = %d AND archived_at IS NULL",
            CurrentClub::id()
        ) );
        if ( ! $exercises ) return 0;

        $activities = $this->demoActivities( 'training' );
        if ( ! $activities ) return 0;

        $notes = self::NOTES_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $total = 0;

        // #3216 — `uk_activity_order (club_id, activity_id, order_index)`
        // makes a second pass over the same training collide from index 0.
        // The activity list is this batch's, so this only bites when a run
        // adopts another run's activities; it is cheap and it makes the
        // generator idempotent rather than order-dependent.
        $covered = $this->activitiesWithRows( 'tt_activity_exercises' );

        foreach ( $activities as $activity_id ) {
            if ( isset( $covered[ (int) $activity_id ] ) ) continue;
            $count = mt_rand( self::MIN_PER_SESSION, self::MAX_PER_SESSION );
            $picked = (array) array_rand( $exercises, min( $count, count( $exercises ) ) );

            $order = 0;
            foreach ( $picked as $index ) {
                $exercise = $exercises[ $index ];
                $planned  = (int) ( $exercise->duration_minutes ?? 0 );
                if ( $planned <= 0 ) $planned = 15;

                $wpdb->insert( "{$wpdb->prefix}tt_activity_exercises", [
                    'club_id'                 => CurrentClub::id(),
                    'activity_id'             => (int) $activity_id,
                    'exercise_id'             => (int) $exercise->id,
                    'order_index'             => $order,
                    'actual_duration_minutes' => max( 5, $planned + mt_rand( -3, 5 ) ),
                    'notes'                   => mt_rand( 1, 100 ) <= 40 ? $notes[ mt_rand( 0, count( $notes ) - 1 ) ] : null,
                    'is_draft'                => 0,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'activity_exercise', $id, [ 'activity_id' => (int) $activity_id ] );
                    $total++;
                }
                $order++;
            }
        }
        return $total;
    }

    /** 1–3 methodology principles per training. */
    private function linkPrinciples(): int {
        global $wpdb;

        $principles = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_principles WHERE club_id = %d",
            CurrentClub::id()
        ) );
        if ( ! $principles ) return 0;

        $activities = $this->demoActivities( 'training' );
        $total = 0;

        // #3216 — `uniq_activity_principle (activity_id, principle_id)`.
        $covered = $this->activitiesWithRows( 'tt_activity_principles' );

        foreach ( $activities as $activity_id ) {
            if ( isset( $covered[ (int) $activity_id ] ) ) continue;
            $count = mt_rand( 1, 3 );
            $picked = (array) array_rand( $principles, min( $count, count( $principles ) ) );
            $sort = 0;
            foreach ( $picked as $index ) {
                $wpdb->insert( "{$wpdb->prefix}tt_activity_principles", [
                    'club_id'      => CurrentClub::id(),
                    'activity_id'  => (int) $activity_id,
                    'principle_id' => (int) $principles[ $index ],
                    'sort_order'   => $sort++,
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'activity_principle', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * A couple of per-team exercise overrides, so the override surface has
     * something in it rather than being uniformly empty.
     */
    private function generateTeamOverrides(): int {
        global $wpdb;

        $exercises = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_exercises WHERE club_id = %d AND archived_at IS NULL LIMIT 20",
            CurrentClub::id()
        ) );
        if ( ! $exercises ) return 0;

        $total = 0;
        foreach ( $this->teams as $team ) {
            $count = mt_rand( 1, 3 );
            for ( $i = 0; $i < $count; $i++ ) {
                $exercise_id = (int) $exercises[ mt_rand( 0, count( $exercises ) - 1 ) ];

                $ok = $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$wpdb->prefix}tt_exercise_team_overrides
                        (club_id, exercise_id, team_id, is_enabled)
                     VALUES (%d, %d, %d, %d)",
                    CurrentClub::id(), $exercise_id, (int) $team->id, mt_rand( 0, 1 )
                ) );
                $id = (int) $wpdb->insert_id;
                if ( $ok && $id ) {
                    $this->registry->tag( 'exercise_team_override', $id );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * The school year's breaks that fall inside the generated span.
     *
     * #4040 — a break is named by the date it falls on, not by where it sits
     * in the window. The old version placed three breaks at fixed fractions
     * of the span and labelled them winter, spring and summer in that order,
     * which on a September run produced a "Winterstop" in July and a
     * "Voorjaarsvakantie" in August. A holiday calendar decides when a team
     * trains, so getting it wrong hides where a player's next weeks go.
     *
     * Only breaks the window actually touches are written — a Christmas break
     * on a four-week summer demo would sit outside every calendar the
     * operator opens. So a short window may get none, which is correct.
     */
    private function generateHolidays(): int {
        global $wpdb;

        $language = self::resolveLanguage( $this->language );
        $labels   = self::HOLIDAYS_BY_LANGUAGE[ $language ];
        $note     = self::HOLIDAY_NOTE_BY_LANGUAGE[ $language ];

        $window_start = $this->calendar->windowStart();
        // The activity calendar reaches past today; a break in that horizon
        // belongs on the calendar too.
        $window_end = $this->calendar->now() + ( DemoCalendar::HORIZON_WEEKS * WEEK_IN_SECONDS );

        // A Christmas break that opened in the December before the window
        // still runs into it, so start a year early.
        $first_year = (int) gmdate( 'Y', $window_start ) - 1;
        $last_year  = (int) gmdate( 'Y', $window_end );

        $total = 0;
        for ( $year = $first_year; $year <= $last_year; $year++ ) {
            foreach ( self::BREAKS as $break ) {
                $start_ts = (int) gmmktime( 0, 0, 0, $break['month'], $break['day'], $year );
                $end_ts   = $start_ts + ( $break['days'] * DAY_IN_SECONDS );

                if ( $end_ts < $window_start || $start_ts > $window_end ) continue;

                $start_date = gmdate( 'Y-m-d', $start_ts );
                if ( $this->holidayExists( $start_date ) ) continue;

                $wpdb->insert( "{$wpdb->prefix}tt_holidays", [
                    'club_id'    => CurrentClub::id(),
                    'uuid'       => self::uuid(),
                    'name'       => $labels[ $break['key'] ],
                    'start_date' => $start_date,
                    'end_date'   => gmdate( 'Y-m-d', $end_ts ),
                    'note'       => $note,
                    'color'      => $break['color'],
                ] );
                $id = (int) $wpdb->insert_id;
                if ( $id ) {
                    $this->registry->tag( 'holiday', $id, [ 'key' => $break['key'], 'year' => $year ] );
                    $total++;
                }
            }
        }
        return $total;
    }

    /**
     * Is a holiday already on the calendar for this date? A second run into
     * the same install writes the same breaks, and the table has no unique
     * key to lean on.
     */
    private function holidayExists( string $start_date ): bool {
        global $wpdb;

        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}tt_holidays
              WHERE club_id = %d AND start_date = %s",
            CurrentClub::id(), $start_date
        ) ) > 0;
    }

    /**
     * Activity ids that already have at least one row in `$table` (#3216),
     * as a set. One query rather than one per activity.
     *
     * @param string $table Unprefixed table name, a literal from this class.
     * @return array<int, true>
     */
    private function activitiesWithRows( string $table ): array {
        global $wpdb;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT DISTINCT activity_id FROM {$wpdb->prefix}{$table} WHERE club_id = %d",
            CurrentClub::id()
        ) );
        $out = [];
        foreach ( (array) $ids as $id ) $out[ (int) $id ] = true;
        return $out;
    }

    /**
     * Activity ids this batch generated, optionally narrowed by type.
     *
     * @return int[]
     */
    private function demoActivities( string $type = '' ): array {
        global $wpdb;

        $ids = $this->registry->entityIds( 'activity' );
        if ( ! $ids || $type === '' ) return $ids;

        // #3030 — past activities only. What this generator writes is the
        // record of a session that RAN — `actual_duration_minutes`, notes on
        // how it went — so attaching it to next Tuesday's training would
        // assert a result that has not happened. Harmless while every
        // generated activity was in the past; now that the window reaches
        // forward, the filter has to be stated.
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_activities
              WHERE id IN ({$placeholders}) AND club_id = %d AND activity_type_key = %s
                AND session_date <= %s",
            ...array_merge( $ids, [ CurrentClub::id(), $type, current_time( 'Y-m-d' ) ] )
        ) );
        return array_map( 'intval', (array) $rows );
    }

    /**
     * #3102 — outside the seeded stream, so a second run into the same
     * install does not re-mint the uuid the first one already stored. See
     * \TT\Modules\DemoData\DemoUuid.
     */
    private static function uuid(): string {
        return \TT\Modules\DemoData\DemoUuid::mint();
    }

    private static function resolveLanguage( string $locale ): string {
        if ( isset( self::HOLIDAYS_BY_LANGUAGE[ $locale ] ) ) return $locale;
        $prefix = substr( $locale, 0, 2 );
        foreach ( array_keys( self::HOLIDAYS_BY_LANGUAGE ) as $key ) {
            if ( strpos( $key, $prefix ) === 0 ) return $key;
        }
        return 'en_US';
    }
}
