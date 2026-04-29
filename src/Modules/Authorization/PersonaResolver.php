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
        'administrator'        => 'academy_admin',
        'tt_club_admin'        => 'academy_admin',
        'tt_head_dev'          => 'head_of_development',
        // tt_coach splits in Sprint 7 via the tt_team_people.is_head_coach
        // flag — see resolveCoachPersona() below.
        'tt_scout'             => 'scout',
        'tt_player'            => 'player',
        'tt_parent'            => 'parent',
        'tt_team_manager'      => 'team_manager',
        // #0060 — persona dashboards need an addressable slot for board /
        // sponsor / consultant users who only ever read. Mapping is
        // additive; cap-bridge layer continues to handle authorization.
        'tt_readonly_observer' => 'readonly_observer',
    ];

    /**
     * Personas the user holds. Multi-persona users get multiple entries.
     *
     * #0033 Sprint 7: a `tt_coach` user is split into `head_coach` (when
     * any `tt_team_people` row for the linked person has
     * `is_head_coach = 1`) or `assistant_coach` (otherwise). A user can
     * end up with both personas if they head-coach one team and assist
     * another.
     *
     * @return string[]
     */
    public static function personasFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];

        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) return [];

        $personas = [];
        foreach ( $user->roles as $role_slug ) {
            if ( $role_slug === 'tt_coach' ) {
                foreach ( self::resolveCoachPersona( $user_id ) as $p ) {
                    $personas[] = $p;
                }
                continue;
            }
            if ( isset( self::WP_ROLE_TO_PERSONA[ $role_slug ] ) ) {
                $personas[] = self::WP_ROLE_TO_PERSONA[ $role_slug ];
            }
        }
        return array_values( array_unique( $personas ) );
    }

    /**
     * #0033 Sprint 7: split a `tt_coach` WP role into head_coach and/or
     * assistant_coach personas based on the `tt_team_people.is_head_coach`
     * flag. A coach with no tt_team_people rows still gets head_coach
     * (defensive default — better to over-grant than to lock them out
     * during the matrix bridge's dormant phase).
     *
     * @return string[]
     */
    private static function resolveCoachPersona( int $user_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        // Find the person record(s) linked to this WP user.
        $person_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d",
            $user_id
        ) );
        if ( empty( $person_ids ) ) {
            return [ 'head_coach' ]; // defensive default
        }

        $tp_table = "{$p}tt_team_people";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tp_table ) ) !== $tp_table ) {
            return [ 'head_coach' ];
        }

        // Check if the is_head_coach column even exists yet (guarded for
        // installs where Sprint 7's migration hasn't run).
        $col = $wpdb->get_var( $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_head_coach'",
            $tp_table
        ) );
        if ( $col === null ) return [ 'head_coach' ];

        $placeholders = implode( ',', array_fill( 0, count( $person_ids ), '%d' ) );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT is_head_coach, COUNT(*) AS n
               FROM {$tp_table}
              WHERE person_id IN ({$placeholders})
              GROUP BY is_head_coach",
            $person_ids
        ) );

        $has_head = false;
        $has_assistant = false;
        foreach ( (array) $rows as $r ) {
            if ( (int) $r->is_head_coach === 1 && (int) $r->n > 0 ) $has_head = true;
            if ( (int) $r->is_head_coach === 0 && (int) $r->n > 0 ) $has_assistant = true;
        }
        $out = [];
        if ( $has_head )      $out[] = 'head_coach';
        if ( $has_assistant ) $out[] = 'assistant_coach';
        if ( empty( $out ) )  $out[] = 'head_coach'; // no team assignments — defensive default
        return $out;
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
        if ( $user_id <= 0 ) return null;

        // #0060 — read the persisted choice from user-meta. The persona
        // dashboard switcher writes this on every flip; the legacy
        // sessionStorage path stays in DashboardShortcode as a transient
        // lens that overrides this for the current tab.
        $stored = get_user_meta( $user_id, 'tt_active_persona', true );
        if ( is_string( $stored ) && $stored !== '' ) {
            $available = self::personasFor( $user_id );
            if ( in_array( $stored, $available, true ) ) {
                return $stored;
            }
        }
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
