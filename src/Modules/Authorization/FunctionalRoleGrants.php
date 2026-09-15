<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * FunctionalRoleGrants — the fourth authorization axis (#3257).
 *
 * The matrix keys on `(persona, entity, activity, scope_kind)`. A physio
 * and a kit manager resolve to the same persona, `staff`, because they
 * hold the same `tt_staff` WordPress role — so no matrix row can
 * separate them, and #3232's `player_injuries [rc, team]` grant reached
 * both. What separates them is the job they do on a squad: the
 * functional role in `tt_team_people.functional_role_id`.
 *
 * This class reads `config/functional_role_grants.php` and answers two
 * questions for `MatrixGate`:
 *
 *   1. Does this user's functional role grant `(entity, activity)` on a
 *      given team? — the additive half.
 *   2. Is this persona's own matrix row on this entity superseded? — the
 *      narrowing half, which applies ONLY to a user who holds at least
 *      one functional role.
 *
 * Both are consumed inside `MatrixGate`, never at a call site. Two
 * answers in two places is how `tt_view_players` and its parent persona
 * drifted apart in #3391.
 *
 * ## The date asymmetry, which is deliberate
 *
 * A grant is only read from an assignment that is live today
 * (`start_date` / `end_date` on `tt_team_people`). Supersession is read
 * from the assignment regardless of its dates. So a physio whose
 * assignment ended loses the injury grant and does NOT fall back to the
 * old persona-wide one. Both halves therefore fail narrow, which is the
 * right direction for medical data about minors.
 *
 * ## No migration, and why that is correct
 *
 * Nothing here writes a table, and no migration removes the `staff`
 * persona's `player_injuries` rows from an existing
 * `tt_authorization_matrix`. That stale row IS the upgrade behaviour the
 * decision asked for: an existing Staff account with no functional role
 * keeps exactly the access it has today. Supersession is what takes it
 * away, and only once somebody has been given a functional role.
 */
final class FunctionalRoleGrants {

    /**
     * Parsed config, or null before first load.
     *
     * @var array{grants: array<string, array<string, array{0:string,1:string}>>, supersedes: array<string, list<string>>}|null
     */
    private static $config = null;

    /**
     * Per-request assignment cache, user_id => assignments.
     *
     * @var array<int, list<array{team_id:int, role_key:string, live:bool}>>
     */
    private static $assignments = [];

    /** Compact activity letter => matrix activity name. */
    private const ACTIVITY_LETTERS = [
        'r' => MatrixGate::READ,
        'c' => MatrixGate::CHANGE,
        'd' => MatrixGate::CREATE_DELETE,
    ];

    /**
     * Drop both caches. Tests call this between scenarios; the
     * functional-role admin surfaces call it after an assignment changes.
     */
    public static function clearCache(): void {
        self::$config      = null;
        self::$assignments = [];
    }

    /**
     * True when the user holds at least one functional role on any team,
     * live or expired. This is the switch that turns supersession on: an
     * academy assigning somebody a functional role is the act that moves
     * them from the legacy persona-wide grant to the narrower one.
     */
    public static function holdsAnyFunctionalRole( int $user_id ): bool {
        return self::assignmentsFor( $user_id ) !== [];
    }

    /**
     * True when the functional-role layer owns the answer for
     * `(persona, entity)` for this user, so `MatrixGate` must skip that
     * persona's matrix row and let the grants below decide alone.
     *
     * False for a user holding no functional role — their matrix row is
     * untouched, which is the documented upgrade behaviour.
     */
    public static function supersedes( int $user_id, string $persona, string $entity ): bool {
        $config = self::config();
        $owned  = $config['supersedes'][ $persona ] ?? [];
        if ( ! in_array( $entity, $owned, true ) ) return false;

        return self::holdsAnyFunctionalRole( $user_id );
    }

    /**
     * True when a functional role the user holds on `$team_id` grants
     * `(entity, activity)`. Team scope is the only scope a functional
     * role has — it is held on a squad.
     */
    public static function grantsOnTeam( int $user_id, string $entity, string $activity, int $team_id ): bool {
        if ( $team_id <= 0 ) return false;
        if ( ! self::anyRoleMentions( $entity ) ) return false;

        foreach ( self::assignmentsFor( $user_id ) as $assignment ) {
            if ( ! $assignment['live'] ) continue;
            if ( $assignment['team_id'] !== $team_id ) continue;
            if ( self::roleGrants( $assignment['role_key'], $entity, $activity ) ) return true;
        }
        return false;
    }

    /**
     * The first team on which a functional role the user holds grants
     * `(entity, activity)`, or null. Answers `MatrixGate`'s "any scope"
     * question and supplies `describeAccess()` with a concrete team id.
     */
    public static function firstTeamGranting( int $user_id, string $entity, string $activity ): ?int {
        if ( ! self::anyRoleMentions( $entity ) ) return null;

        foreach ( self::assignmentsFor( $user_id ) as $assignment ) {
            if ( ! $assignment['live'] ) continue;
            if ( self::roleGrants( $assignment['role_key'], $entity, $activity ) ) return $assignment['team_id'];
        }
        return null;
    }

    /**
     * The functional role key that grants `(entity, activity)`, on
     * `$team_id` when given or on any team otherwise. Null when none
     * does. Used by `describeAccess()` so the admin comparison page can
     * name the role rather than reporting an unexplained allow.
     */
    public static function roleGranting( int $user_id, string $entity, string $activity, ?int $team_id = null ): ?string {
        foreach ( self::assignmentsFor( $user_id ) as $assignment ) {
            if ( ! $assignment['live'] ) continue;
            if ( $team_id !== null && $assignment['team_id'] !== $team_id ) continue;
            if ( self::roleGrants( $assignment['role_key'], $entity, $activity ) ) return $assignment['role_key'];
        }
        return null;
    }

    /**
     * Entities a functional role grants at all, whatever the activity.
     * Exists so the docs and the tests can read the positive kit-manager
     * list out of the config rather than restating it.
     *
     * @return list<string>
     */
    public static function entitiesFor( string $role_key ): array {
        $config = self::config();
        return array_keys( $config['grants'][ $role_key ] ?? [] );
    }

    /**
     * Does ANY functional role grant this entity at all?
     *
     * Cheap config-only check that runs before the assignment query, so
     * the gate does not go to the database for the ~130 entities no
     * functional role mentions. `MatrixGate` asks on every team-scoped
     * decision, which is why this guard is here and not at the call site.
     */
    private static function anyRoleMentions( string $entity ): bool {
        foreach ( self::config()['grants'] as $entities ) {
            if ( isset( $entities[ $entity ] ) ) return true;
        }
        return false;
    }

    /**
     * Does the configured grant set for `$role_key` cover
     * `(entity, activity)`, with the owning module switched on?
     */
    private static function roleGrants( string $role_key, string $entity, string $activity ): bool {
        $config = self::config();
        $spec   = $config['grants'][ $role_key ][ $entity ] ?? null;
        if ( $spec === null ) return false;

        $granted = [];
        foreach ( str_split( $spec[0] ) as $letter ) {
            if ( isset( self::ACTIVITY_LETTERS[ $letter ] ) ) $granted[] = self::ACTIVITY_LETTERS[ $letter ];
        }
        if ( ! in_array( $activity, $granted, true ) ) return false;

        // Mirror MatrixGate's per-row owning-module short-circuit, so a
        // functional-role grant cannot outlive its module being switched
        // off.
        $module_class = $spec[1];
        if ( $module_class !== '' && ! \TT\Core\ModuleRegistry::isEnabled( $module_class ) ) return false;

        return true;
    }

    /**
     * Every functional-role assignment the user holds, across every
     * `tt_people` row linked to their WordPress account.
     *
     * `live` is the date window on the assignment evaluated against
     * today. Expired rows are kept in the list because supersession
     * reads them — see the class docblock.
     *
     * @return list<array{team_id:int, role_key:string, live:bool}>
     */
    private static function assignmentsFor( int $user_id ): array {
        if ( $user_id <= 0 ) return [];
        if ( isset( self::$assignments[ $user_id ] ) ) return self::$assignments[ $user_id ];

        self::$assignments[ $user_id ] = self::loadAssignments( $user_id );
        return self::$assignments[ $user_id ];
    }

    /**
     * @return list<array{team_id:int, role_key:string, live:bool}>
     */
    private static function loadAssignments( int $user_id ): array {
        global $wpdb;
        $p = $wpdb->prefix;

        $tp_table = "{$p}tt_team_people";
        $fr_table = "{$p}tt_functional_roles";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tp_table ) ) !== $tp_table ) return [];
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $fr_table ) ) !== $fr_table ) return [];

        // Every linked person row, not just the first. A duplicate
        // `tt_people` row must not silently decide whether somebody is a
        // physio.
        $person_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d",
            $user_id
        ) );
        if ( ! is_array( $person_ids ) || $person_ids === [] ) return [];
        $person_ids = array_map( 'intval', $person_ids );

        $placeholders = implode( ',', array_fill( 0, count( $person_ids ), '%d' ) );
        $params       = array_merge( $person_ids, [ CurrentClub::id() ] );

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT tp.team_id, tp.start_date, tp.end_date, fr.role_key
               FROM {$tp_table} tp
               INNER JOIN {$fr_table} fr ON fr.id = tp.functional_role_id
              WHERE tp.person_id IN ({$placeholders})
                AND tp.club_id = %d
                AND tp.functional_role_id IS NOT NULL",
            $params
        ) );
        if ( ! is_array( $rows ) ) return [];

        $today = current_time( 'Y-m-d' );
        $out   = [];
        foreach ( $rows as $row ) {
            $team_id = (int) $row->team_id;
            if ( $team_id <= 0 ) continue;

            $start = is_string( $row->start_date ) ? $row->start_date : '';
            $end   = is_string( $row->end_date ) ? $row->end_date : '';

            $out[] = [
                'team_id'  => $team_id,
                'role_key' => (string) $row->role_key,
                'live'     => ( $start === '' || $start <= $today ) && ( $end === '' || $end >= $today ),
            ];
        }
        return $out;
    }

    /**
     * @return array{grants: array<string, array<string, array{0:string,1:string}>>, supersedes: array<string, list<string>>}
     */
    private static function config(): array {
        if ( self::$config !== null ) return self::$config;

        $empty = [ 'grants' => [], 'supersedes' => [] ];

        $path = defined( 'TT_PLUGIN_DIR' ) ? TT_PLUGIN_DIR . 'config/functional_role_grants.php' : '';
        if ( $path === '' || ! is_readable( $path ) ) {
            self::$config = $empty;
            return self::$config;
        }

        /** @var mixed $raw */
        $raw = require $path;
        if ( ! is_array( $raw ) ) {
            self::$config = $empty;
            return self::$config;
        }

        $grants = [];
        /** @var array<string, mixed> $raw_grants */
        $raw_grants = is_array( $raw['grants'] ?? null ) ? $raw['grants'] : [];
        foreach ( $raw_grants as $role_key => $entities ) {
            if ( ! is_array( $entities ) ) continue;
            foreach ( $entities as $entity => $spec ) {
                if ( ! is_array( $spec ) || ! isset( $spec[0] ) ) continue;
                $grants[ (string) $role_key ][ (string) $entity ] = [
                    (string) $spec[0],
                    isset( $spec[1] ) ? (string) $spec[1] : '',
                ];
            }
        }

        $supersedes = [];
        /** @var array<string, mixed> $raw_supersedes */
        $raw_supersedes = is_array( $raw['supersedes'] ?? null ) ? $raw['supersedes'] : [];
        foreach ( $raw_supersedes as $persona => $entities ) {
            if ( ! is_array( $entities ) ) continue;
            $supersedes[ (string) $persona ] = array_values( array_map( 'strval', $entities ) );
        }

        self::$config = [ 'grants' => $grants, 'supersedes' => $supersedes ];
        return self::$config;
    }
}
