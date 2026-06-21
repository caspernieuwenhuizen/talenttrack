<?php
namespace TT\Infrastructure\Teams;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * TeamDetailSections — resolve which sections of the team detail page a
 * given user wants to see (#1613).
 *
 * Mirrors EvalDisplayMode / the persona dashboard's per-user GridLayout:
 * the layout is personal, stored as a per-user preference (user meta),
 * keyed by the canonical user id. User meta is the WordPress-side
 * backing store only — a SaaS front end reads/writes the same shape
 * through `GET/PUT /me/preferences/team-detail`.
 *
 * Scope: the preference applies to that coach's own view, across every
 * team they coach. Anyone without a saved preference (players, parents,
 * admins, coaches who haven't customised) gets the default — all
 * sections on. The hero (team identity) is always shown and is not a
 * member of the toggleable set.
 *
 * Business logic only: which sections exist, the defaults, and the
 * read/write of the preference. The view composes against this; it
 * doesn't decide visibility itself.
 */
class TeamDetailSections {

    private const USER_META = 'tt_team_detail_sections';

    /**
     * Canonical toggleable section keys, in render order. The hero is
     * deliberately absent — it is always shown.
     *
     * @var list<string>
     */
    public const SECTIONS = [
        'key_facts',
        'kpis',
        'roster',
        'staff',
        'team_info',
        'trial_roster',
        'upcoming_activities',
    ];

    /**
     * Human label for each section key. Resolved lazily so __() runs
     * after textdomain load.
     *
     * @return array<string,string>
     */
    public static function labels(): array {
        return [
            'key_facts'           => __( 'Key facts', 'talenttrack' ),
            'kpis'                => __( 'At a glance', 'talenttrack' ),
            'roster'              => __( 'Roster', 'talenttrack' ),
            'staff'               => __( 'Staff', 'talenttrack' ),
            'team_info'           => __( 'Team info', 'talenttrack' ),
            'trial_roster'        => __( 'Trial roster', 'talenttrack' ),
            'upcoming_activities' => __( 'Upcoming activities', 'talenttrack' ),
        ];
    }

    /**
     * Default visibility map — every section on.
     *
     * @return array<string,bool>
     */
    public static function defaults(): array {
        $out = [];
        foreach ( self::SECTIONS as $key ) {
            $out[ $key ] = true;
        }
        return $out;
    }

    /**
     * Effective visibility map for a user. Falls back to the all-on
     * default when the user has no override. Unknown keys in the stored
     * value are ignored; missing keys default to on.
     *
     * @return array<string,bool>
     */
    public static function forUser( int $user_id ): array {
        $defaults = self::defaults();
        if ( $user_id <= 0 ) {
            return $defaults;
        }
        $stored = get_user_meta( $user_id, self::USER_META, true );
        if ( ! is_array( $stored ) || $stored === [] ) {
            return $defaults;
        }
        $out = $defaults;
        foreach ( self::SECTIONS as $key ) {
            if ( array_key_exists( $key, $stored ) ) {
                $out[ $key ] = (bool) $stored[ $key ];
            }
        }
        return $out;
    }

    /** Convenience: is one section visible for the user? */
    public static function isVisible( int $user_id, string $section ): bool {
        $map = self::forUser( $user_id );
        return $map[ $section ] ?? true;
    }

    /**
     * Persist a per-user override. Only known keys are kept; any key
     * absent from the input defaults to on (so a coach who unchecks a
     * box and submits gets that box off and every other box on). Passing
     * an empty array clears the override and restores the default.
     *
     * @param array<string,mixed> $map  section key => truthy/falsy
     */
    public static function setUserOverride( int $user_id, array $map ): void {
        if ( $user_id <= 0 ) return;
        if ( $map === [] ) {
            delete_user_meta( $user_id, self::USER_META );
            return;
        }
        $clean = [];
        foreach ( self::SECTIONS as $key ) {
            $clean[ $key ] = array_key_exists( $key, $map ) ? (bool) $map[ $key ] : false;
        }
        update_user_meta( $user_id, self::USER_META, $clean );
    }
}
