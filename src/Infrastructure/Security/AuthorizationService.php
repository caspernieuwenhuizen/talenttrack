<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\AuthorizationRepository;
use TT\Infrastructure\Authorization\FunctionalRolesRepository;
use TT\Infrastructure\Tenancy\CurrentClub;

/**
 * AuthorizationService — central authorization layer.
 *
 * v2.10.0 (Sprint 1G): The legacy role_in_team bridge and the
 * tt_teams.head_coach_id bridge are retired. Team-based permissions now
 * flow through the explicit functional-role → authorization-role mapping
 * (tt_functional_roles + tt_functional_role_auth_roles). This allows one
 * functional role (e.g. head_coach) to grant multiple authorization roles
 * (e.g. head_coach + physio) via the configurable mapping table.
 * v2.9.0 (Sprint 1F): Internals rewritten to evaluate permissions from
 * the data-driven tt_roles / tt_role_permissions / tt_user_role_scopes
 * tables, plus a legacy bridge that auto-grants equivalent permissions
 * from existing tt_team_people and tt_teams.head_coach_id data.
 *
 * **Public API is unchanged** — all pilot sites from Sprint 1E (v2.8.0)
 * keep working without modification. canViewPlayer(), canEditPlayer(),
 * canEvaluatePlayer(), canManageTeam(), canAssignStaff() now compose
 * over a single underlying primitive: userHasPermission().
 *
 * Decision flow (per request):
 *   1. Resolve user_id → person_id (cached per request)
 *   2. Load all active role-scopes for that person (cached)
 *   3. Compute "what permissions do I have in scope X?"
 *   4. For each high-level canXxx() call, map it to one or more
 *      permission checks in the correct scope.
 *   5. Functional-role resolution: for each tt_team_people row the user
 *      owns (via tt_people.wp_user_id), read its functional_role_id,
 *      follow tt_functional_role_auth_roles to the set of auth roles,
 *      and emit one scope entry per auth role at team scope.
 *
 * Everything is filterable via `tt_auth_can_*` and `tt_auth_check_result`
 * filters. Every decision fires `tt_auth_check` for audit.
 */
class AuthorizationService {

    // Per-request caches

    /** @var array<int, int|null> */
    private static $cache_person = [];

    /** @var array<int, array<int, array<string>>>  user_id => team_id => [role_keys] */
    private static $cache_team_roles = [];

    /**
     * Resolved scopes for a user (combination of data-driven role scopes,
     * functional-role mapping, and derived player link). Structure:
     *   [user_id => [ scope_entries ... ]]
     * Each entry: ['role_key'=>..., 'scope_type'=>..., 'scope_id'=>...|null,
     *              'permissions'=>[...],
     *              'source'=>'role_scope'|'functional_role'|'derived_player_link',
     *              'scope_id_pk'=>int|null,
     *              'via_functional_role_key'=>string|null (only when source=functional_role),
     *              'via_functional_role_id'=>int|null    (only when source=functional_role)]
     *
     * @var array<int, array<int, array<string,mixed>>>
     */
    private static $cache_scopes = [];

    /** @var array<string, bool> */
    private static $cache_decisions = [];

    public static function flushCache(): void {
        self::$cache_person = [];
        self::$cache_team_roles = [];
        self::$cache_scopes = [];
        self::$cache_decisions = [];
    }

    /**
     * v3.71.4 — capability check that also consults the matrix bridge
     * when the legacy WP cap check returns false. Used by REST
     * permission_callbacks and other gates to ensure users granted a
     * `tt_*` cap via Functional Role assignment + matrix scope-rows
     * are not denied just because the `user_has_cap` filter is dormant
     * (`tt_authorization_active = 0`).
     *
     * Mirrors `TileRegistry::userMayAccess()` so the navigation surface
     * and the REST surface agree on visibility. Runtime gates at the
     * actual write/read sites still scope-check on the entity level —
     * this only decides whether the request is permitted to reach them.
     */
    public static function userCanOrMatrix( int $user_id, string $cap ): bool {
        if ( $cap === '' || $user_id <= 0 ) return false;
        if ( user_can( $user_id, $cap ) ) return true;
        if ( strpos( $cap, 'tt_' ) !== 0 ) return false;
        if ( ! class_exists( '\\TT\\Modules\\Authorization\\LegacyCapMapper' ) ) return false;
        $user = get_userdata( $user_id );
        if ( ! $user instanceof \WP_User ) return false;
        $matrix = \TT\Modules\Authorization\LegacyCapMapper::evaluate( $cap, $user, [] );
        return $matrix === true;
    }

    public static function registerCacheInvalidators(): void {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;

        add_action( 'tt_person_assigned_to_team', [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_person_created',          [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_role_granted',            [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_role_revoked',            [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_functional_role_mapping_updated', [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'wp_login',                   [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'wp_logout',                  [ __CLASS__, 'flushCache' ], 10, 0 );
    }

    // Public API — entity-scoped decisions

    public static function canViewPlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'view_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            // Own player record → always yes.
            if ( self::isPlayerOwnRecord( $user_id, $player_id ) ) return true;

            // Global permission on any scope.
            if ( self::userHasPermission( $user_id, 'players.view' ) ) return true;

            // Scoped to the player's team.
            $team_id = self::getPlayerTeamId( $player_id );
            if ( $team_id && self::userHasPermission( $user_id, 'players.view', 'team', $team_id ) ) return true;

            // Parent scoped to this player.
            if ( self::userHasPermission( $user_id, 'players.view_own_children', 'player', $player_id ) ) return true;

            return false;
        } );
    }

    public static function canEditPlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'edit_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            if ( self::userHasPermission( $user_id, 'players.edit' ) ) return true;

            $team_id = self::getPlayerTeamId( $player_id );
            if ( $team_id && self::userHasPermission( $user_id, 'players.edit', 'team', $team_id ) ) return true;

            return false;
        } );
    }

    public static function canEvaluatePlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'evaluate_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            if ( self::userHasPermission( $user_id, 'evaluations.create' ) ) return true;

            $team_id = self::getPlayerTeamId( $player_id );
            if ( $team_id && self::userHasPermission( $user_id, 'evaluations.create', 'team', $team_id ) ) return true;

            return false;
        } );
    }

    public static function canManageTeam( int $user_id, int $team_id ): bool {
        return self::decide( 'manage_team', $user_id, 'team', $team_id, function () use ( $user_id, $team_id ) {
            if ( self::userHasPermission( $user_id, 'team.manage' ) ) return true;
            if ( self::userHasPermission( $user_id, 'team.manage', 'team', $team_id ) ) return true;
            return false;
        } );
    }

    public static function canAssignStaff( int $user_id, int $team_id ): bool {
        return self::decide( 'assign_staff', $user_id, 'team', $team_id, function () use ( $user_id ) {
            // Assigning staff is a club-level authority. Requires a GLOBAL
            // permission — team-scoped managers cannot reorganize each other.
            return self::userHasPermission( $user_id, 'people.manage' )
                || self::userHasPermission( $user_id, '*.*' );
        } );
    }

    // Public API — the core primitive

    /**
     * Does this user have the given permission?
     *
     * If $scope_type/$scope_id are omitted, returns true only if the user
     * has the permission granted GLOBALLY (scope_type='global').
     *
     * If $scope_type and $scope_id are provided, returns true if the user
     * has the permission granted either:
     *   - globally, OR
     *   - scoped to exactly that (scope_type, scope_id)
     *
     * The `*.*` wildcard permission matches every permission string.
     * The `<domain>.*` wildcard matches any action within that domain.
     */
    public static function userHasPermission( int $user_id, string $permission, ?string $scope_type = null, ?int $scope_id = null ): bool {
        if ( $user_id <= 0 ) return false;

        // WP administrator role — unconditional yes. This keeps admin users
        // functional even before any tt_people/role-scope records exist.
        if ( user_can( $user_id, 'administrator' ) ) return true;

        // #0069 — Head of Development is the academy-wide development
        // lens by role definition; their work spans every team and
        // every player regardless of whether they personally coach
        // anyone. Without this shortcut the matrix-driven scope
        // resolution requires a tt_people record + role-scope row,
        // which a fresh `tt_head_dev` user typically lacks. WP
        // administrator gets the same shortcut on the line above.
        if ( user_can( $user_id, 'tt_head_dev' ) ) return true;

        $scopes = self::resolveScopesForUser( $user_id );
        if ( empty( $scopes ) ) return false;

        foreach ( $scopes as $scope ) {
            // Scope must match: global always applies; specific scopes must
            // match the target exactly. If caller didn't provide a target,
            // only global scopes are accepted.
            $scope_matches = false;
            if ( $scope['scope_type'] === 'global' ) {
                $scope_matches = true;
            } elseif ( $scope_type !== null && $scope_id !== null
                && $scope['scope_type'] === $scope_type
                && (int) $scope['scope_id'] === $scope_id ) {
                $scope_matches = true;
            }
            if ( ! $scope_matches ) continue;

            foreach ( $scope['permissions'] as $granted ) {
                if ( self::permissionMatches( $granted, $permission ) ) return true;
            }
        }

        return false;
    }

    /**
     * Does `$granted` satisfy a request for `$wanted`?
     * Handles the `*.*` and `<domain>.*` wildcards.
     */
    private static function permissionMatches( string $granted, string $wanted ): bool {
        if ( $granted === $wanted ) return true;
        if ( $granted === '*.*' || $granted === '*' ) return true;

        // `<domain>.*` matches `<domain>.anything`.
        if ( substr( $granted, -2 ) === '.*' ) {
            $prefix = substr( $granted, 0, -1 ); // keeps the trailing dot
            if ( strpos( $wanted, $prefix ) === 0 ) return true;
        }

        return false;
    }

    // Public API — helpers used by UI / debug page

    public static function getPersonIdByUserId( int $user_id ): ?int {
        if ( $user_id <= 0 ) return null;
        if ( array_key_exists( $user_id, self::$cache_person ) ) return self::$cache_person[ $user_id ];

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND status = 'active' AND club_id = %d LIMIT 1",
            $user_id, CurrentClub::id()
        ) );
        $person_id = $row ? (int) $row : null;
        self::$cache_person[ $user_id ] = $person_id;
        return $person_id;
    }

    public static function getCurrentPersonId(): ?int {
        $uid = get_current_user_id();
        return $uid ? self::getPersonIdByUserId( $uid ) : null;
    }

    /**
     * Return the role_in_team values a user holds on a specific team, from
     * the legacy tt_team_people table. Kept for back-compat with code that
     * called this helper in Sprint 1E.
     *
     * @return string[]
     */
    public static function getUserRolesOnTeam( int $user_id, int $team_id ): array {
        $map = self::getUserTeamRoles( $user_id );
        return $map[ $team_id ] ?? [];
    }

    /** @return array<int, array<int, string>> */
    public static function getUserTeamRoles( int $user_id ): array {
        if ( isset( self::$cache_team_roles[ $user_id ] ) ) return self::$cache_team_roles[ $user_id ];

        $person_id = self::getPersonIdByUserId( $user_id );
        if ( ! $person_id ) {
            self::$cache_team_roles[ $user_id ] = [];
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT team_id, role_in_team FROM {$wpdb->prefix}tt_team_people WHERE person_id = %d AND club_id = %d",
            $person_id, CurrentClub::id()
        ) );

        $map = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $map[ (int) $r->team_id ][] = (string) $r->role_in_team;
            }
        }
        self::$cache_team_roles[ $user_id ] = $map;
        return $map;
    }

    public static function userHasRole( int $user_id, string $role_slug ): bool {
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( ! $user ) return false;
        return in_array( $role_slug, (array) $user->roles, true );
    }

    /**
     * Debug diagnostic: return every active scope a user resolves to,
     * annotated with source (role_scope | legacy_team_people |
     * legacy_head_coach_id). Used by the admin debug page.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getResolvedScopesForUser( int $user_id ): array {
        return self::resolveScopesForUser( $user_id );
    }

    // Internals

    /**
     * Build the full set of scope entries for a user, combining:
     *   1. tt_user_role_scopes rows (via AuthorizationRepository)
     *   2. Functional role resolution: tt_team_people.functional_role_id →
     *      tt_functional_role_auth_roles → one scope entry per auth role
     *   3. Derived `player` role from tt_players.wp_user_id
     *
     * The legacy bridges (role_in_team string lookup, tt_teams.head_coach_id)
     * that existed in Sprint 1F were retired in Sprint 1G once the data
     * migration 0006 translated them into explicit tt_team_people rows with
     * functional_role_id set.
     *
     * Each entry carries the role_key, scope, and resolved permissions.
     *
     * Cached per request.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function resolveScopesForUser( int $user_id ): array {
        if ( isset( self::$cache_scopes[ $user_id ] ) ) return self::$cache_scopes[ $user_id ];

        $scopes = [];
        $person_id = self::getPersonIdByUserId( $user_id );

        // Source 1: tt_user_role_scopes (data-driven)
        if ( $person_id ) {
            $repo = new AuthorizationRepository();
            foreach ( $repo->getActiveScopesForPerson( $person_id ) as $row ) {
                $scopes[] = [
                    'role_key'    => $row['role_key'],
                    'scope_type'  => $row['scope_type'],
                    'scope_id'    => $row['scope_id'],
                    'permissions' => $row['permissions'],
                    'source'      => 'role_scope',
                    'scope_id_pk' => $row['scope_id_pk'],
                    'start_date'  => $row['start_date'],
                    'end_date'    => $row['end_date'],
                ];
            }
        }

        // Source 2: functional role → auth role mapping
        // For each team the person is assigned to, look up their functional
        // role and follow the mapping table to the set of auth roles. One
        // functional role can map to multiple auth roles (e.g. head_coach
        // mapped to both head_coach and physio auth roles), so this loop
        // can emit multiple scope entries for a single team assignment.
        if ( $person_id ) {
            $fn_repo = new FunctionalRolesRepository();
            $assignments = $fn_repo->getFunctionalRoleAssignmentsForPerson( $person_id );

            // Cache auth role ids per functional role id within this call
            // to avoid a query per assignment when the same functional role
            // shows up on multiple teams.
            $auth_role_ids_cache = [];
            $today = current_time( 'Y-m-d' );

            foreach ( $assignments as $a ) {
                // Honor start/end dates — inactive assignments don't grant
                // permissions. Matches how tt_user_role_scopes is filtered.
                if ( ! self::dateActive( $a->start_date, $a->end_date, $today ) ) continue;

                $fn_role_id = (int) $a->functional_role_id;
                if ( $fn_role_id <= 0 ) continue;

                if ( ! array_key_exists( $fn_role_id, $auth_role_ids_cache ) ) {
                    $auth_role_ids_cache[ $fn_role_id ] = $fn_repo->getAuthRoleIdsForFunctionalRole( $fn_role_id );
                }
                $auth_role_ids = $auth_role_ids_cache[ $fn_role_id ];
                if ( empty( $auth_role_ids ) ) continue;

                $team_id = (int) $a->team_id;
                $fn_role_key = (string) $a->functional_role_key;

                foreach ( $auth_role_ids as $auth_role_id ) {
                    $auth_role_key = self::getRoleKeyById( (int) $auth_role_id );
                    if ( $auth_role_key === null ) continue;

                    $perms = self::getPermissionsForRoleKey( $auth_role_key );

                    $scopes[] = [
                        'role_key'           => $auth_role_key,
                        'scope_type'         => 'team',
                        'scope_id'           => $team_id,
                        'permissions'        => $perms,
                        'source'             => 'functional_role',
                        'scope_id_pk'        => (int) $a->assignment_id,
                        'start_date'         => $a->start_date,
                        'end_date'           => $a->end_date,
                        'via_functional_role_key' => $fn_role_key,
                        'via_functional_role_id'  => $fn_role_id,
                    ];
                }
            }
        }

        // Source 3: derived `player` role
        // If this WP user is linked to a tt_players row, grant the player
        // role scoped to that player. Not stored in tt_user_role_scopes —
        // derived at runtime.
        global $wpdb;
        $player_ids_i_am = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND status = 'active' AND club_id = %d",
            $user_id, CurrentClub::id()
        ) );
        if ( is_array( $player_ids_i_am ) && ! empty( $player_ids_i_am ) ) {
            $perms_player = self::getPermissionsForRoleKey( 'player' );
            foreach ( $player_ids_i_am as $pid ) {
                $scopes[] = [
                    'role_key'    => 'player',
                    'scope_type'  => 'player',
                    'scope_id'    => (int) $pid,
                    'permissions' => $perms_player,
                    'source'      => 'derived_player_link',
                    'scope_id_pk' => null,
                    'start_date'  => null,
                    'end_date'    => null,
                ];
            }
        }

        /**
         * Filter the resolved scope list for a user. Third parties can add
         * or modify entries without writing to tt_user_role_scopes.
         *
         * @param array $scopes
         * @param int   $user_id
         */
        $scopes = (array) apply_filters( 'tt_auth_resolve_permissions', $scopes, $user_id );

        self::$cache_scopes[ $user_id ] = $scopes;
        return $scopes;
    }

    /**
     * Small helper used by the legacy bridge and derived-player code paths.
     * Caches permission lookups by role_key to avoid repeated DB hits
     * inside the same request.
     *
     * @return string[]
     */
    private static function getPermissionsForRoleKey( string $role_key ): array {
        static $cache = [];
        if ( isset( $cache[ $role_key ] ) ) return $cache[ $role_key ];

        global $wpdb;
        $rows = $wpdb->get_col( $wpdb->prepare(
            "SELECT rp.permission
             FROM {$wpdb->prefix}tt_role_permissions rp
             INNER JOIN {$wpdb->prefix}tt_roles r ON r.id = rp.role_id AND r.club_id = rp.club_id
             WHERE r.role_key = %s AND r.club_id = %d",
            $role_key, CurrentClub::id()
        ) );
        $cache[ $role_key ] = is_array( $rows ) ? array_map( 'strval', $rows ) : [];
        return $cache[ $role_key ];
    }

    /**
     * Resolve a tt_roles.id to its role_key. Request-scoped cache.
     */
    private static function getRoleKeyById( int $role_id ): ?string {
        static $cache = [];
        if ( array_key_exists( $role_id, $cache ) ) return $cache[ $role_id ];

        global $wpdb;
        $key = $wpdb->get_var( $wpdb->prepare(
            "SELECT role_key FROM {$wpdb->prefix}tt_roles WHERE id = %d AND club_id = %d",
            $role_id, CurrentClub::id()
        ) );
        $cache[ $role_id ] = $key ? (string) $key : null;
        return $cache[ $role_id ];
    }

    /**
     * Is the (start_date, end_date) range active for the given day?
     * NULL on either side means open-ended in that direction.
     */
    private static function dateActive( ?string $start, ?string $end, string $today ): bool {
        if ( $start !== null && $start !== '' && $start > $today ) return false;
        if ( $end   !== null && $end   !== '' && $end   < $today ) return false;
        return true;
    }

    private static function decide( string $action, int $user_id, string $entity_type, int $entity_id, callable $rule ): bool {
        $key = $user_id . ':' . $action . ':' . $entity_type . ':' . $entity_id;
        if ( array_key_exists( $key, self::$cache_decisions ) ) return self::$cache_decisions[ $key ];

        $allowed = (bool) $rule();

        $filter_name = 'tt_auth_can_' . $action;
        $allowed = (bool) apply_filters( $filter_name, $allowed, $user_id, $entity_id );
        $allowed = (bool) apply_filters( 'tt_auth_check_result', $allowed, $action, $user_id, $entity_type, $entity_id );

        self::$cache_decisions[ $key ] = $allowed;

        do_action( 'tt_auth_check', $action, $user_id, $entity_id, $allowed );
        return $allowed;
    }

    private static function getPlayerTeamId( int $player_id ): ?int {
        global $wpdb;
        $tid = $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
        return $tid ? (int) $tid : null;
    }

    private static function isPlayerOwnRecord( int $user_id, int $player_id ): bool {
        global $wpdb;
        $uid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d AND club_id = %d",
            $player_id, CurrentClub::id()
        ) );
        return $uid > 0 && $uid === $user_id;
    }
}
