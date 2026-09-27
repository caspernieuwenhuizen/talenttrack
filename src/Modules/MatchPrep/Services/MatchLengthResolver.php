<?php
namespace TT\Modules\MatchPrep\Services;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Query\QueryHelpers;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * MatchLengthResolver — single source of truth for "how long is this
 * match?" (#1727).
 *
 * Match length feeds a player's recorded minutes, which in turn drives
 * the load + development picture. Resolving it consistently — rather
 * than re-deriving 35/half (70 total) at each prefill site — keeps the
 * minutes data trustworthy across match prep, the live surface, and the
 * direct completion-form entry (#1726).
 *
 * Resolution order for a half length, most-specific first:
 *   1. explicit per-match value already stored on the activity / prep
 *      (caller passes it in via the *Activity helpers below);
 *   2. the age-category default — activity → team (`team_id`) →
 *      `team.age_group` → `match_minutes_by_age_group[age_group]`;
 *   3. the global fallback of 35 minutes per half.
 *
 * The per-age-category map lives in `tt_config` under the single JSON
 * key `match_minutes_by_age_group` (operator-editable via the
 * Configuration -> Match minutes sub-form, and readable via
 * `GET /v1/config`). All reads are club-scoped.
 */
class MatchLengthResolver {

    /** Global fallback when nothing more specific is configured. */
    public const FALLBACK_HALF_MINUTES = 35;

    /** tt_config key holding the JSON age-group -> half-minutes map. */
    public const CONFIG_KEY = 'match_minutes_by_age_group';

    private \wpdb $wpdb;
    private string $t_activities;
    private string $t_teams;

    public function __construct() {
        global $wpdb;
        $this->wpdb         = $wpdb;
        $this->t_activities = $wpdb->prefix . 'tt_activities';
        $this->t_teams      = $wpdb->prefix . 'tt_teams';
    }

    /**
     * Resolve the half length (minutes per half) for one activity,
     * applying the full precedence order.
     *
     * @param int $activity_id  The activity (match) id.
     * @param int $explicit     A per-match override already known to the
     *                          caller (e.g. a stored
     *                          `half_length_minutes` or
     *                          `match_length_minutes / 2`). Pass 0 / a
     *                          non-positive value to skip this step.
     */
    public function halfMinutesForActivity( int $activity_id, int $explicit = 0 ): int {
        if ( $explicit > 0 ) {
            return $explicit;
        }

        $age_group = $this->ageGroupForActivity( $activity_id );
        if ( $age_group !== '' ) {
            $half = $this->lookupHalf( $age_group );
            if ( $half > 0 ) {
                return $half;
            }
        }

        return self::FALLBACK_HALF_MINUTES;
    }

    /**
     * Resolve the full match length (both halves) for one activity.
     */
    public function matchMinutesForActivity( int $activity_id, int $explicit = 0 ): int {
        return $this->halfMinutesForActivity( $activity_id, $explicit ) * 2;
    }

    /**
     * #3682 — the half length a *new* match prep starts from.
     *
     * Step 1 of the precedence order above says "the explicit per-match
     * value already stored on the activity", and the activity has one:
     * `match_length_minutes`, set on the activity form / REST (#1726).
     * Every caller of `halfMinutesForActivity()` that creates a prep was
     * skipping it, so a coach who set an U11 match to 60 minutes still
     * got a prep planned at 2 x 35. Their playing-time reports then read
     * from the prep, so the 10-minute disagreement landed in a player's
     * minutes.
     *
     * This is the one entry point that applies the activity's own length
     * before falling through to the age-group map and the 35 fallback.
     */
    public function halfMinutesForActivityDefault( int $activity_id ): int {
        return $this->halfMinutesForActivity(
            $activity_id,
            self::halfOf( $this->activityMatchLength( $activity_id ) )
        );
    }

    /**
     * The per-match full length stored on the activity itself, in
     * minutes. 0 when the activity has none, or is unknown / in another
     * club. Club-scoped.
     */
    public function activityMatchLength( int $activity_id ): int {
        if ( $activity_id <= 0 ) {
            return 0;
        }
        /** @var string|null $stored */
        $stored = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT match_length_minutes
               FROM {$this->t_activities}
              WHERE id = %d AND club_id = %d
              LIMIT 1",
            $activity_id, CurrentClub::id()
        ) );
        $minutes = (int) $stored;
        return $minutes > 0 ? $minutes : 0;
    }

    /**
     * #4077 — how long a match that has been played lasted, in minutes.
     * The one chain every minutes report reads (minutes report, minutes
     * audit, minutes share), so the three cannot disagree about the same
     * match. Most specific first:
     *
     *  1. the match prep's period length × the number of periods — what
     *     the match execution ran its clock and minutes against;
     *  2. the activity's own `match_length_minutes`;
     *  3. the configured half length for the team's age group × 2;
     *  4. the scheduled duration, `end_time − start_time`;
     *  5. {@see FALLBACK_HALF_MINUTES} × 2.
     *
     * Step 4 is the slot and can include warm-up (#4058): a coach whose
     * figures read wrong fixes it by setting the match length or the
     * age-group default, not by recording different minutes.
     *
     * Deliberately separate from {@see halfMinutesForActivityDefault()}:
     * a new match prep must not start from a slot that includes warm-up.
     */
    public static function resolvePlayedLength(
        int $prep_period_minutes,
        int $activity_minutes,
        int $age_group_half_minutes,
        string $start_time,
        string $end_time,
        int $periods = 2
    ): int {
        if ( $prep_period_minutes > 0 ) return $prep_period_minutes * max( 1, $periods );
        if ( $activity_minutes > 0 ) return $activity_minutes;
        if ( $age_group_half_minutes > 0 ) return $age_group_half_minutes * 2;

        $scheduled = self::scheduledMinutes( $start_time, $end_time );
        if ( $scheduled > 0 ) return $scheduled;

        return self::FALLBACK_HALF_MINUTES * 2;
    }

    /**
     * #4077 — {@see resolvePlayedLength()} for one activity, reading the
     * activity's length, its scheduled times and its team's age group in
     * one club-scoped query. The prep's period length is passed in by the
     * caller, which usually holds the prep row already.
     */
    public function playedMatchMinutes( int $activity_id, int $prep_period_minutes = 0, int $periods = 2 ): int {
        if ( $prep_period_minutes > 0 || $activity_id <= 0 ) {
            return self::resolvePlayedLength( $prep_period_minutes, 0, 0, '', '', $periods );
        }

        $row = $this->wpdb->get_row( $this->wpdb->prepare(
            "SELECT a.match_length_minutes, a.start_time, a.end_time, t.age_group
               FROM {$this->t_activities} a
               LEFT JOIN {$this->t_teams} t
                 ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.id = %d AND a.club_id = %d
              LIMIT 1",
            $activity_id, CurrentClub::id()
        ) );
        if ( ! is_object( $row ) ) {
            return self::resolvePlayedLength( 0, 0, 0, '', '', $periods );
        }

        $age_group = trim( (string) ( $row->age_group ?? '' ) );

        return self::resolvePlayedLength(
            0,
            (int) ( $row->match_length_minutes ?? 0 ),
            $age_group !== '' ? $this->lookupHalf( $age_group ) : 0,
            (string) ( $row->start_time ?? '' ),
            (string) ( $row->end_time ?? '' ),
            $periods
        );
    }

    /**
     * Minutes between two `TIME` values on the same day; 0 when either is
     * missing or the end is not after the start.
     */
    public static function scheduledMinutes( string $start_time, string $end_time ): int {
        $start_time = trim( $start_time );
        $end_time   = trim( $end_time );
        if ( $start_time === '' || $end_time === '' ) return 0;

        $start = strtotime( '1970-01-01 ' . $start_time . ' UTC' );
        $end   = strtotime( '1970-01-01 ' . $end_time . ' UTC' );
        if ( $start === false || $end === false || $end <= $start ) return 0;

        return (int) floor( ( $end - $start ) / 60 );
    }

    /**
     * A full match length as minutes per half, rounded up so an odd
     * length (25 minutes for the youngest ages) never silently loses a
     * minute. 0 in, 0 out — "no length set".
     */
    public static function halfOf( int $match_length_minutes ): int {
        return $match_length_minutes > 0 ? (int) ceil( $match_length_minutes / 2 ) : 0;
    }

    /**
     * Default half length for a given age category, or the global
     * fallback when that category has no configured value.
     */
    public function defaultHalfForAgeGroup( string $age_group ): int {
        $age_group = trim( $age_group );
        if ( $age_group !== '' ) {
            $half = $this->lookupHalf( $age_group );
            if ( $half > 0 ) {
                return $half;
            }
        }
        return self::FALLBACK_HALF_MINUTES;
    }

    /**
     * The configured per-age-category map, decoded and sanitised to
     * `[ age_group => half_minutes ]`. Only positive integer minute
     * values survive; everything else is dropped.
     *
     * @return array<string,int>
     */
    public function configuredMap(): array {
        $raw = QueryHelpers::get_config( self::CONFIG_KEY, '' );
        if ( $raw === '' ) {
            return [];
        }
        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }
        $out = [];
        foreach ( $decoded as $group => $minutes ) {
            $group = trim( (string) $group );
            $n     = (int) $minutes;
            if ( $group !== '' && $n > 0 ) {
                $out[ $group ] = $n;
            }
        }
        return $out;
    }

    /**
     * Look up the configured half length for one age group. Returns 0
     * when the group is absent or non-positive.
     */
    private function lookupHalf( string $age_group ): int {
        $map = $this->configuredMap();
        return $map[ $age_group ] ?? 0;
    }

    /**
     * Resolve the age-group string for an activity by joining the
     * activity's team. Club-scoped. Empty string when the activity has
     * no team, the team has no age group, or the activity is unknown.
     */
    private function ageGroupForActivity( int $activity_id ): string {
        if ( $activity_id <= 0 ) {
            return '';
        }
        /** @var string|null $age_group */
        $age_group = $this->wpdb->get_var( $this->wpdb->prepare(
            "SELECT t.age_group
               FROM {$this->t_activities} a
               JOIN {$this->t_teams} t
                 ON t.id = a.team_id AND t.club_id = a.club_id
              WHERE a.id = %d AND a.club_id = %d
              LIMIT 1",
            $activity_id, CurrentClub::id()
        ) );
        return trim( (string) ( $age_group ?? '' ) );
    }
}
