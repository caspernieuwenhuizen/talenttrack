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
 * #3177 — `tt_staff` now maps too. The Sprint 1 note that used to sit
 * here said `tt_staff` and `tt_readonly_observer` could rely on legacy
 * `current_user_can` checks and the user_has_cap → MatrixGate bridge to
 * "keep them working unchanged". That reasoning does not survive the
 * bridge it names: `AuthorizationModule::filterUserHasCap()` *assigns*
 * `$allcaps[$cap]`, so on an install with `tt_authorization_active` set
 * it overwrites the role's own grant with the matrix answer. A role that
 * resolves to no persona gets `[]` from `personasFor()`, `MatrixGate`
 * short-circuits, and every mapped capability comes back false — the
 * role is denied outright rather than left alone.
 *
 * The persona is named `staff`, not the `staff_observer` that note
 * mooted: the role holds `tt_edit_players`, `tt_edit_people` and
 * `tt_edit_player_notes`, so calling it an observer would misdescribe it
 * in the one place a reader goes to find out what it can do.
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
        // #3177 — the physio / kit-manager / generic-staff seat. Seeded
        // at team scope; see config/authorization_seed.php for the list
        // and for why `players:create_delete` is deliberately absent.
        'tt_staff'             => 'staff',
    ];

    /**
     * #4731 — `personasFor()` answers per request, keyed by user id. Every
     * scoped `MatrixGate::can()` asks it, at four queries for a coach, so a
     * list checking `can()` per team paid that per team.
     *
     * @var array<int, string[]>
     */
    private static array $personas = [];

    /**
     * The coach's team assignments, read once per request with the
     * personas and kept for `coachPersonaHoldsTeam()`, which asks per
     * team-scoped check.
     *
     * @var array<int, list<array{team_id:int, head:bool, live:bool, ended_on:string}>>
     */
    private static array $coach_rows = [];

    /** Whether `tt_team_people.is_head_coach` exists; asked once per request. */
    private static ?bool $head_coach_flag = null;

    /**
     * Forget the per-request answers. `AuthorizationService::flushCache()`
     * calls this on every role change, grant, revoke, team assignment and
     * login.
     */
    public static function clearCache(): void {
        self::$personas   = [];
        self::$coach_rows = [];
    }

    /**
     * #1102 — Inverse of WP_ROLE_TO_PERSONA. Returns the WP role slug a
     * persona typically maps from, so the persona-dashboard editor can
     * answer "would a default user with this persona's typical WP role
     * see this widget?" without needing a representative live user.
     * `tt_coach` is hard-coded because it doesn't appear in the forward
     * map — it splits at runtime via `resolveCoachPersona()` into
     * head_coach / assistant_coach.
     */
    public static function defaultWpRoleFor( string $persona ): ?string {
        if ( $persona === 'head_coach' || $persona === 'assistant_coach' ) {
            return 'tt_coach';
        }
        foreach ( self::WP_ROLE_TO_PERSONA as $wp_role => $mapped_persona ) {
            if ( $mapped_persona === $persona ) {
                return $wp_role;
            }
        }
        return null;
    }

    /**
     * Every WordPress role a persona can come from: `defaultWpRoleFor()`
     * names one, and `academy_admin` comes from two. For a caller that
     * finds the holders of a persona (#4571), so none is missed.
     *
     * @return list<string>
     */
    public static function wpRolesFor( string $persona ): array {
        if ( $persona === 'head_coach' || $persona === 'assistant_coach' ) return [ 'tt_coach' ];
        $out = [];
        foreach ( self::WP_ROLE_TO_PERSONA as $wp_role => $mapped_persona ) {
            if ( $mapped_persona === $persona ) $out[] = $wp_role;
        }
        return $out;
    }

    /**
     * Personas the user holds. Multi-persona users get multiple entries.
     *
     * #0033 Sprint 7: a `tt_coach` user is split into `head_coach` (when
     * a `tt_team_people` row for the linked person that is live today has
     * `is_head_coach = 1`) or `assistant_coach` (otherwise). A user can
     * end up with both personas if they head-coach one team and assist
     * another; `coachPersonaHoldsTeam()` then keeps each to its own team.
     *
     * @return string[]
     */
    public static function personasFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];

        if ( ! isset( self::$personas[ $user_id ] ) ) {
            self::$personas[ $user_id ] = self::resolvePersonas( $user_id );
        }
        return self::$personas[ $user_id ];
    }

    /** @return string[] */
    private static function resolvePersonas( int $user_id ): array {
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
     * #0033 Sprint 7 + #1315: split a `tt_coach` WP role into
     * head_coach and/or assistant_coach personas via the
     * `tt_team_people.is_head_coach` flag.
     *
     * A user with multiple team assignments may be head of one team
     * and assistant on another; both personas get added.
     *
     * Only assignments live today count (start and end date inclusive), so
     * a head-coach assignment that ended, or one that has not started,
     * does not make somebody a head coach now. A coach with no live
     * assignment is read from the most recent one that ended (every
     * assignment that ended on that last day), so a former assistant
     * stays an assistant rather than falling to the no-assignments
     * default below. Only when nothing has ended either, so every
     * assignment is still to start, are those read.
     *
     * A coach with no assignments still gets `head_coach` (defensive
     * default — better to over-grant than to lock them out during
     * the matrix bridge's dormant phase).
     *
     * History: until #1315 a path 1 also consulted
     * `tt_teams.head_coach_id` for back-compat with the v3.x team
     * form's "Head coach" dropdown. That column was retired in
     * #1315 — `tt_team_people.is_head_coach` is now the single
     * source of truth.
     *
     * @return string[]
     */
    private static function resolveCoachPersona( int $user_id ): array {
        $rows = self::coachRows( $user_id );
        $read = array_values( array_filter( $rows, static fn( array $r ): bool => $r['live'] ) );
        if ( $read === [] ) {
            $last = '';
            foreach ( $rows as $r ) {
                if ( $r['ended_on'] > $last ) $last = $r['ended_on'];
            }
            $read = $last === ''
                ? $rows
                : array_values( array_filter( $rows, static fn( array $r ): bool => $r['ended_on'] === $last ) );
        }

        $has_head      = false;
        $has_assistant = false;
        foreach ( $read as $r ) {
            if ( $r['head'] ) {
                $has_head = true;
            } else {
                $has_assistant = true;
            }
        }

        $out = [];
        if ( $has_head )      $out[] = 'head_coach';
        if ( $has_assistant ) $out[] = 'assistant_coach';
        if ( empty( $out ) )  $out[] = 'head_coach'; // no team assignments — defensive default
        return $out;
    }

    /**
     * Does the coach persona hold on this team? `MatrixGate` asks it on
     * every team-scoped check.
     *
     * The coach persona follows the person's current assignment on that
     * team: `head_coach` needs a head-coach assignment on the team that is
     * live today, `assistant_coach` an assistant assignment. A coach who
     * heads one team and assists another holds both personas, and without
     * this the head-coach rows would reach the team they assist.
     *
     * Applies only to somebody who holds team assignments at all. A user
     * with none, whose team scope an operator granted directly, is
     * answered by that scope alone, as before. For a coach who does hold
     * an assignment, a team role granted under Roles & Permissions on a
     * team they are not assigned to does not carry the coach rows there:
     * their team access follows the assignments. Any other persona passes.
     */
    public static function coachPersonaHoldsTeam( int $user_id, string $persona, int $team_id ): bool {
        if ( $persona !== 'head_coach' && $persona !== 'assistant_coach' ) return true;

        $rows = self::coachRows( $user_id );
        if ( $rows === [] ) return true;

        $want_head = $persona === 'head_coach';
        foreach ( $rows as $r ) {
            if ( $r['live'] && $r['team_id'] === $team_id && $r['head'] === $want_head ) return true;
        }
        return false;
    }

    /**
     * Every team assignment of the people linked to the user in the
     * current club, with whether it is a head-coach assignment, whether it
     * is live today, and the end date when it has ended (`''` otherwise).
     * Read once per request.
     *
     * @return list<array{team_id:int, head:bool, live:bool, ended_on:string}>
     */
    private static function coachRows( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( ! isset( self::$coach_rows[ $user_id ] ) ) {
            self::$coach_rows[ $user_id ] = self::loadCoachRows( $user_id );
        }
        return self::$coach_rows[ $user_id ];
    }

    /** @return list<array{team_id:int, head:bool, live:bool, ended_on:string}> */
    private static function loadCoachRows( int $user_id ): array {
        global $wpdb;
        $p    = $wpdb->prefix;
        $club = \TT\Infrastructure\Tenancy\CurrentClub::id();

        $person_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d AND club_id = %d",
            $user_id, $club
        ) );
        if ( ! is_array( $person_ids ) || $person_ids === [] || ! self::hasHeadCoachFlag() ) return [];

        $person_ids   = array_map( 'intval', $person_ids );
        $tp_table     = "{$p}tt_team_people";
        $placeholders = implode( ',', array_fill( 0, count( $person_ids ), '%d' ) );
        $today        = current_time( 'Y-m-d' );
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT team_id, is_head_coach, end_date,
                    ( ( start_date IS NULL OR start_date <= %s )
                  AND ( end_date   IS NULL OR end_date   >= %s ) ) AS is_live
               FROM {$tp_table}
              WHERE club_id = %d
                AND person_id IN ({$placeholders})",
            array_merge( [ $today, $today, $club ], $person_ids )
        ) );
        if ( ! is_array( $rows ) ) return [];

        $out = [];
        foreach ( $rows as $r ) {
            $end   = is_string( $r->end_date ) ? $r->end_date : '';
            $out[] = [
                'team_id'  => (int) $r->team_id,
                'head'     => (int) $r->is_head_coach === 1,
                'live'     => (int) $r->is_live === 1,
                'ended_on' => ( $end !== '' && $end < $today ) ? $end : '',
            ];
        }
        return $out;
    }

    /**
     * The table and its `is_head_coach` column (guarded for installs where
     * Sprint 7's migration hasn't run). Schema, not a user fact, so it is
     * asked once per request and `clearCache()` leaves it alone.
     */
    private static function hasHeadCoachFlag(): bool {
        if ( self::$head_coach_flag === null ) {
            global $wpdb;
            $flag = false;
            if ( \TT\Infrastructure\Query\TableExists::has( 'tt_team_people' ) ) {
                $flag = $wpdb->get_var( $wpdb->prepare(
                    "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'is_head_coach'",
                    $wpdb->prefix . 'tt_team_people'
                ) ) !== null;
            }
            self::$head_coach_flag = $flag;
        }
        return self::$head_coach_flag;
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
     * The persona a screen is dressed as: the switcher's choice when one is
     * stored, otherwise the user's first persona. activePersona() alone is
     * null for anyone who never used the switcher (#4428).
     */
    public static function landingPersona( int $user_id ): ?string {
        return self::activePersona( $user_id ) ?? ( self::personasFor( $user_id )[0] ?? null );
    }

    /**
     * Effective persona set for *presentation*: the active one if set +
     * valid, otherwise the full union.
     *
     * #1982 — this is not an authorization API. MatrixGate used to call
     * it, which made the dashboard switcher a permanent capability gate:
     * a coach whose own child is in the academy, who flipped to `parent`
     * to look at their child's page, lost every staff capability on
     * every surface until they flipped back — persisted in user meta, so
     * across sessions and devices, with no message naming the cause.
     * MatrixGate now resolves against personasFor() (the full union) and
     * this stays what its name says: which persona the UI is dressed as.
     *
     * To genuinely act as another role, use Impersonation (#0071) or the
     * matrix Preview page — mechanisms that are visible while they are on.
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
