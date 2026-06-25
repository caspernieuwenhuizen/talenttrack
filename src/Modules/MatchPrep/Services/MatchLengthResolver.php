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
