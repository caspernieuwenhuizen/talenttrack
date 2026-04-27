<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * PersonaResolver — maps a WP user to one or more persona keys (#0033 Sprint 1).
 *
 * Sprint 1 ships a simple WP-role → persona LUT. Sprint 2's migration
 * + Sprint 7's Functional-Role head/assistant flag will refine the
 * derivation (e.g. `tt_coach` + FR `is_head_coach=0` → `assistant_coach`).
 *
 * Multi-persona resolution: a user with two TT WP roles
 * (e.g. `tt_coach` AND `tt_parent`) resolves to both personas. MatrixGate
 * uses the union (any persona that grants permission wins).
 *
 * Personas not yet covered by Sprint 1's LUT (no clean WP-role mapping):
 *   - `team_manager` — needs the new `tt_team_manager` WP role (Sprint 7).
 *   - `assistant_coach` — needs FR `is_head_coach=0` (Sprint 7).
 * Until those land, `tt_coach` always resolves to `head_coach`.
 *
 * `tt_staff` and `tt_readonly_observer` intentionally have no persona
 * mapping in Sprint 1 — those users rely on legacy `current_user_can`
 * checks and the Sprint 2 user_has_cap → MatrixGate bridge will
 * keep them working unchanged. A separate `staff_observer` persona may
 * land in Sprint 7 if needed.
 */
class PersonaResolver {

    /**
     * WordPress role slug → persona key.
     *
     * Order matters only when multiple roles map to overlapping personas
     * (currently they don't). Multi-role users get the union of personas
     * via array_unique() in personasFor().
     */
    private const WP_ROLE_TO_PERSONA = [
        'administrator'   => 'academy_admin',
        'tt_club_admin'   => 'academy_admin',
        'tt_head_dev'     => 'head_of_development',
        'tt_coach'        => 'head_coach',     // Sprint 7 splits via FR flag.
        'tt_scout'        => 'scout',
        'tt_player'       => 'player',
        'tt_parent'       => 'parent',
        // 'tt_team_manager' => 'team_manager',  // added in Sprint 7
    ];

    /**
     * Personas the user holds. Multi-persona users get multiple entries.
     *
     * @return string[]
     */
    public static function personasFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];

        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) return [];

        $personas = [];
        foreach ( $user->roles as $role_slug ) {
            if ( isset( self::WP_ROLE_TO_PERSONA[ $role_slug ] ) ) {
                $personas[] = self::WP_ROLE_TO_PERSONA[ $role_slug ];
            }
        }
        return array_values( array_unique( $personas ) );
    }

    /**
     * Personas the user could pick from in the switcher (Sprint 4 UI).
     * Currently identical to personasFor(); a v2 extension may filter
     * out personas the user holds but never uses.
     *
     * @return string[]
     */
    public static function availablePersonas( int $user_id ): array {
        return self::personasFor( $user_id );
    }

    /**
     * Active persona from sessionStorage (set by Sprint 4's switcher).
     *
     * Sprint 1 always returns null — the switcher UI doesn't exist yet,
     * and MatrixGate falls back to the union view (any persona grants).
     * Wired here so Sprint 4 can flip the implementation without
     * touching the gate.
     */
    public static function activePersona( int $user_id ): ?string {
        // Sprint 4 will read $_COOKIE['tt_active_persona'] (or similar)
        // and validate against personasFor() before returning. For now,
        // the union view is the only behavior.
        return null;
    }

    /**
     * Effective persona set for a request: the active one if set + valid,
     * otherwise the full union. MatrixGate calls this — not personasFor()
     * directly — so the switcher's lens applies automatically.
     *
     * @return string[]
     */
    public static function effectivePersonas( int $user_id ): array {
        $active = self::activePersona( $user_id );
        if ( $active !== null ) {
            $available = self::personasFor( $user_id );
            if ( in_array( $active, $available, true ) ) {
                return [ $active ];
            }
        }
        return self::personasFor( $user_id );
    }
}
