<?php
namespace TT\Modules\DemoData\Generators;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;
use TT\Modules\DemoData\DemoBatchRegistry;

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

    /** @var array<string, array<string, string>> */
    private const HOLIDAYS_BY_LANGUAGE = [
        'en_US' => [
            'winter' => 'Winter break',
            'spring' => 'Spring break',
            'summer' => 'Summer break',
        ],
        'nl_NL' => [
            'winter' => 'Winterstop',
            'spring' => 'Voorjaarsvakantie',
            'summer' => 'Zomerstop',
        ],
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

    public static function category(): string {
        return 'activity_content';
    }

    public static function fromContext( GeneratorContext $ctx ): self {
        return new self( $ctx->registry, $ctx->teams, $ctx->weeks(), $ctx->contentLanguage );
    }

    /** @param object[] $teams */
    public function __construct( DemoBatchRegistry $registry, array $teams, int $weeks, string $language = '' ) {
        $this->registry = $registry;
        $this->teams    = $teams;
        $this->weeks    = max( 1, $weeks );
        $this->language = $language !== '' ? $language : ( function_exists( 'get_locale' ) ? (string) get_locale() : 'en_US' );
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

        foreach ( $activities as $activity_id ) {
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

        foreach ( $activities as $activity_id ) {
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
     * Holiday windows inside the generated span. Only breaks that actually
     * fall in the window are written — a winter break on a four-week demo
     * would sit outside every calendar the operator opens.
     */
    private function generateHolidays(): int {
        global $wpdb;

        $labels = self::HOLIDAYS_BY_LANGUAGE[ self::resolveLanguage( $this->language ) ];
        $window_start = strtotime( '-' . $this->weeks . ' weeks' );
        if ( $window_start === false ) $window_start = time();

        // Place breaks at fixed fractions of the window so they land inside
        // it whatever the preset, and stay clear of each other.
        $plan = [];
        if ( $this->weeks >= 8 ) {
            $plan[] = [ 'winter', 0.35, 14 ];
        }
        if ( $this->weeks >= 16 ) {
            $plan[] = [ 'spring', 0.70, 7 ];
        }
        if ( $this->weeks >= 30 ) {
            $plan[] = [ 'summer', 0.95, 28 ];
        }
        if ( ! $plan ) return 0;

        $total = 0;
        foreach ( $plan as [ $key, $fraction, $days ] ) {
            $start_ts = $window_start + (int) ( $this->weeks * $fraction * WEEK_IN_SECONDS );
            $wpdb->insert( "{$wpdb->prefix}tt_holidays", [
                'club_id'    => CurrentClub::id(),
                'uuid'       => self::uuid(),
                'name'       => $labels[ $key ],
                'start_date' => gmdate( 'Y-m-d', $start_ts ),
                'end_date'   => gmdate( 'Y-m-d', $start_ts + ( $days * DAY_IN_SECONDS ) ),
                'note'       => null,
                'color'      => '#d9e8f5',
            ] );
            $id = (int) $wpdb->insert_id;
            if ( $id ) {
                $this->registry->tag( 'holiday', $id, [ 'key' => $key ] );
                $total++;
            }
        }
        return $total;
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

    private static function uuid(): string {
        return function_exists( 'wp_generate_uuid4' ) ? wp_generate_uuid4() : sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
            mt_rand( 0, 0x0fff ) | 0x4000, mt_rand( 0, 0x3fff ) | 0x8000,
            mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
        );
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
