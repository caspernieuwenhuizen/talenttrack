<?php
namespace TT\Modules\Players;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\PlayerStatusRestController;

/**
 * PlayerStatusModule (#0057) — capabilities + REST registration for the
 * player status feature (behaviour ratings, potential bands, status
 * calculator).
 *
 * Sprint 1: caps + behaviour/potential REST.
 * Sprint 4: read-model REST + traffic-light dot on My Teams.
 *
 * Sprint 3 (methodology config UI) + Sprint 5 (PDP integration) ride
 * in follow-up releases.
 */
final class PlayerStatusModule implements ModuleInterface {

    public function getName(): string { return 'player_status'; }

    /**
     * #2574 — may behaviour be captured here, by this user?
     *
     * Two independent questions, both of which must pass. The
     * `behaviour_rating` feature flag answers "does this academy score
     * behaviour at all?" — a per-club setting. `tt_rate_player_behaviour`
     * answers "may this user record it?" — a per-role one. Neither
     * substitutes for the other, and every behaviour entry point asks both
     * through here so they cannot drift apart.
     *
     * Read-side surfaces (a rating already captured, shown on a profile or
     * in a report) deliberately do NOT consult this: switching the feature
     * off stops new capture and hides the entry points, it does not
     * retroactively hide records the academy already has.
     *
     * @param int|null $user_id Defaults to the current user.
     */
    public static function behaviourCaptureAvailable( ?int $user_id = null ): bool {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'behaviour_rating' ) ) return false;

        return $user_id === null
            ? current_user_can( 'tt_rate_player_behaviour' )
            : user_can( $user_id, 'tt_rate_player_behaviour' );
    }

    /**
     * May potential be recorded here, by this user? (#3243)
     *
     * The twin of {@see self::behaviourCaptureAvailable()}, and the same
     * two independent questions: `potential_rating` answers "does this
     * academy work in potential bands at all?", `tt_set_player_potential`
     * answers "may this user set one?". Neither substitutes for the other.
     *
     * Read-side surfaces deliberately do NOT consult this — the current
     * band on a profile and the trajectory behind it (#3226) keep
     * rendering with the feature off. Switching it off means "stop asking
     * us for this", not "hide what we already decided". An academy that
     * stops maintaining potential still wants to see the bands it set last
     * season.
     *
     * @param int|null $user_id Defaults to the current user.
     */
    public static function potentialCaptureAvailable( ?int $user_id = null ): bool {
        if ( ! \TT\Core\FeatureRegistry::isEnabled( 'potential_rating' ) ) return false;

        return $user_id === null
            ? current_user_can( 'tt_set_player_potential' )
            : user_can( $user_id, 'tt_set_player_potential' );
    }

    /**
     * The age below which the academy is not asked for a potential band.
     *
     * The bands describe a **professional ceiling**. That is a reasonable
     * question about a fifteen-year-old and not one about a seven-year-old,
     * and U13 is where age-group football starts treating trajectory as a
     * real question rather than a guess dressed up as evidence.
     *
     * Deliberately a constant and not a `tt_config` key. A minimum age is
     * exactly the sort of setting that gets set once, wrongly, and then
     * explains a gap nobody can find — and unlike a cadence window, there is
     * no legitimate spread of academy practice here to accommodate. An
     * academy that genuinely disagrees gets a follow-up with a reason
     * attached.
     */
    public const POTENTIAL_MIN_AGE = 13;

    /**
     * Is this player old enough for the potential question to be asked?
     * (#3265)
     *
     * The third independent question, alongside the two
     * {@see self::potentialCaptureAvailable()} asks: that one answers "does
     * this academy do potential, and may this user set one", this answers
     * "is there a sensible question to ask about this player at all". The
     * two are kept apart because they have different arguments — one takes a
     * user, this takes a player — and folding them together would mean every
     * caller had to have both to hand.
     *
     * **An unknown birthdate passes.** A player with no date on record is not
     * evidence of being too young, and letting a data gap become a permission
     * gap would make a missing field look like a broken screen.
     *
     * Not {@see \TT\Modules\Authorization\AgeTier}: its buckets are 9 / 11 /
     * 12 and exist to choose a notification channel. Borrowing them here
     * would tie a judgement about football to a decision about email, and
     * moving one would silently move the other.
     *
     * Read-side surfaces deliberately do NOT consult this. A band recorded on
     * a younger player last season stays readable, and the #3226 trajectory
     * still draws it — the same asymmetry #3243 established for the feature
     * flag. What stops is being *asked* again.
     */
    public static function potentialAppliesToPlayer( int $player_id ): bool {
        if ( $player_id <= 0 ) return true;

        global $wpdb;
        $dob = $wpdb->get_var( $wpdb->prepare(
            "SELECT date_of_birth FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d LIMIT 1",
            $player_id,
            \TT\Infrastructure\Tenancy\CurrentClub::id()
        ) );

        return self::potentialAppliesAtBirthdate( $dob === null ? null : (string) $dob );
    }

    /**
     * The same rule against a date rather than a player id, for callers that
     * already hold the row and should not go back to the database for it.
     */
    public static function potentialAppliesAtBirthdate( ?string $dob ): bool {
        $age = self::ageFromBirthdate( $dob );
        return $age === null || $age >= self::POTENTIAL_MIN_AGE;
    }

    /**
     * Completed years, or null when the date is missing, unparseable or in
     * the future.
     *
     * Calendar age rather than football-season age: a floor is a floor, and
     * a player who turns 13 in March should not be un-askable until August
     * because of where the season boundary falls.
     */
    private static function ageFromBirthdate( ?string $dob ): ?int {
        if ( $dob === null || trim( $dob ) === '' ) return null;

        $ts = strtotime( $dob );
        if ( $ts === false ) return null;

        $now = current_time( 'timestamp' );
        if ( $ts > $now ) return null;

        $age = (int) gmdate( 'Y', $now ) - (int) gmdate( 'Y', $ts );
        if ( (int) gmdate( 'md', $now ) < (int) gmdate( 'md', $ts ) ) $age--;

        return max( 0, $age );
    }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        add_action( 'init', [ self::class, 'ensureCapabilities' ] );
        PlayerStatusRestController::init();
    }

    /**
     * Idempotent capability assignment.
     *
     *   tt_rate_player_behaviour  — head_coach + head_dev + administrator.
     *                               #1941: assistant_coach NO LONGER holds
     *                               it (matrix tighten — see below).
     *   tt_set_player_potential   — head_dev + administrator. Coaches
     *                               of a team don't set potential
     *                               (HoD-level call).
     *   tt_view_player_status     — anyone who can view the player.
     *                               Granted to the standard view-
     *                               players roles.
     *   tt_view_player_status_breakdown — coach + head_dev +
     *                               administrator. Parents see only
     *                               the soft label, never the
     *                               numerics.
     */
    public static function ensureCapabilities(): void {
        $rate         = 'tt_rate_player_behaviour';
        $set          = 'tt_set_player_potential';
        $view         = 'tt_view_player_status';
        $view_detail  = 'tt_view_player_status_breakdown';

        // #1941 — `tt_rate_player_behaviour` no longer goes to
        // assistant_coach. `tt_rate_player_behaviour` now bridges to
        // `player_behaviour_ratings:change` (LegacyCapMapper), whose matrix
        // seed omits assistant_coach (#1060 "AC is operational"). Behaviour-
        // rating is a development judgment that belongs to head coaches +
        // development staff. The breakdown-view caps still go to AC (they
        // SEE the status, they just don't author behaviour ratings).
        $rate_roles     = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_head_coach' ];
        $coach_roles    = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_head_coach', 'tt_assistant_coach' ];
        $hod_roles      = [ 'administrator', 'tt_head_dev', 'tt_club_admin' ];
        $any_view_roles = [ 'administrator', 'tt_head_dev', 'tt_club_admin', 'tt_head_coach', 'tt_assistant_coach', 'tt_scout', 'tt_parent' ];

        foreach ( $rate_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $rate ) ) $role->add_cap( $rate );
        }

        // #1941 — revoke the stale raw grant from assistant_coach so that
        // matrix-dormant installs converge on the matrix authority too
        // (mirrors #1922's readonly_observer team_chemistry revoke). Without
        // this, an install that hasn't applied the matrix keeps letting AC
        // rate behaviour via the native WP cap.
        $ac_role = get_role( 'tt_assistant_coach' );
        if ( $ac_role && $ac_role->has_cap( $rate ) ) {
            $ac_role->remove_cap( $rate );
        }
        foreach ( $hod_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $set ) )         $role->add_cap( $set );
            if ( $role && ! $role->has_cap( $view_detail ) ) $role->add_cap( $view_detail );
        }
        foreach ( $coach_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view_detail ) ) $role->add_cap( $view_detail );
        }
        foreach ( $any_view_roles as $r ) {
            $role = get_role( $r );
            if ( $role && ! $role->has_cap( $view ) ) $role->add_cap( $view );
        }
    }
}
