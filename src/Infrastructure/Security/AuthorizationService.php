<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Authorization\AuthorizationRepository;

/**
 * AuthorizationService — central authorization layer.
 *
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
 *   5. Legacy bridge: if a user has tt_team_people assignments, grant the
 *      equivalent permissions on the fly. This lets existing data keep
 *      working without a data migration.
 *
 * Everything is filterable via `tt_auth_can_*` and `tt_auth_check_result`
 * filters. Every decision fires `tt_auth_check` for audit.
 */
class AuthorizationService {

    /* ═══════════════ Per-request caches ═══════════════ */

    /** @var array<int, int|null> */
    private static $cache_person = [];

    /** @var array<int, array<int, array<string>>>  user_id => team_id => [role_keys] */
    private static $cache_team_roles = [];

    /**
     * Resolved scopes for a user (combination of DB-driven role scopes and
     * legacy bridge). Structure:
     *   [user_id => [ scope_entries ... ]]
     * Each entry: ['role_key'=>..., 'scope_type'=>..., 'scope_id'=>...|null,
     *              'permissions'=>[...], 'source'=>'role_scope'|'legacy_team_people'|'legacy_head_coach_id'|'wp_role',
     *              'scope_id_pk'=>int|null]
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

    public static function registerCacheInvalidators(): void {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;

        add_action( 'tt_person_assigned_to_team', [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_person_created',          [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_role_granted',            [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'tt_role_revoked',            [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'wp_login',                   [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'wp_logout',                  [ __CLASS__, 'flushCache' ], 10, 0 );
    }

    /* ═══════════════ Public API — entity-scoped decisions ═══════════════ */

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

    /* ═══════════════ Public API — the core primitive ═══════════════ */

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

    /* ═══════════════ Public API — helpers used by UI / debug page ═══════════════ */

    public static function getPersonIdByUserId( int $user_id ): ?int {
        if ( $user_id <= 0 ) return null;
        if ( array_key_exists( $user_id, self::$cache_person ) ) return self::$cache_person[ $user_id ];

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND status = 'active' LIMIT 1",
            $user_id
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
            "SELECT team_id, role_in_team FROM {$wpdb->prefix}tt_team_people WHERE person_id = %d",
            $person_id
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

    /* ═══════════════ Internals ═══════════════ */

    /**
     * Build the full set of scope entries for a user, combining:
     *   1. tt_user_role_scopes rows (via AuthorizationRepository)
     *   2. legacy bridge: tt_team_people assignments → implied role scopes
     *   3. legacy bridge: tt_teams.head_coach_id → implied head_coach scope
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

        /* ─── Source 1: tt_user_role_scopes (data-driven) ─── */
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

        /* ─── Source 2: legacy tt_team_people → implied scoped role ─── */
        // Maps role_in_team values to role_key values. Identical for the
        // common cases; 'other' falls back to a generic view-only permission
        // set (treat them like physio/read-only).
        $legacy_map = [
            'head_coach'      => 'head_coach',
            'assistant_coach' => 'assistant_coach',
            'manager'         => 'manager',
            'physio'          => 'physio',
            'other'           => 'physio', // read-only equivalent
        ];

        $team_roles = self::getUserTeamRoles( $user_id );
        foreach ( $team_roles as $team_id => $roles_in_team ) {
            foreach ( $roles_in_team as $role_in_team ) {
                $role_key = $legacy_map[ $role_in_team ] ?? null;
                if ( ! $role_key ) continue;
                $perms = self::getPermissionsForRoleKey( $role_key );
                $scopes[] = [
                    'role_key'    => $role_key,
                    'scope_type'  => 'team',
                    'scope_id'    => $team_id,
                    'permissions' => $perms,
                    'source'      => 'legacy_team_people',
                    'scope_id_pk' => null,
                    'start_date'  => null,
                    'end_date'    => null,
                ];
            }
        }

        /* ─── Source 3: legacy tt_teams.head_coach_id pointing at this user ─── */
        global $wpdb;
        $legacy_head_coach_team_ids = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_teams WHERE head_coach_id = %d",
            $user_id
        ) );
        if ( is_array( $legacy_head_coach_team_ids ) ) {
            $perms_hc = self::getPermissionsForRoleKey( 'head_coach' );
            foreach ( $legacy_head_coach_team_ids as $tid ) {
                $scopes[] = [
                    'role_key'    => 'head_coach',
                    'scope_type'  => 'team',
                    'scope_id'    => (int) $tid,
                    'permissions' => $perms_hc,
                    'source'      => 'legacy_head_coach_id',
                    'scope_id_pk' => null,
                    'start_date'  => null,
                    'end_date'    => null,
                ];
            }
        }

        /* ─── Source 4: derived `player` role ─── */
        // If this WP user is linked to a tt_players row, grant the player
        // role scoped to that player. Not stored in tt_user_role_scopes —
        // derived at runtime.
        $player_ids_i_am = $wpdb->get_col( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_players WHERE wp_user_id = %d AND status = 'active'",
            $user_id
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
             INNER JOIN {$wpdb->prefix}tt_roles r ON r.id = rp.role_id
             WHERE r.role_key = %s",
            $role_key
        ) );
        $cache[ $role_key ] = is_array( $rows ) ? array_map( 'strval', $rows ) : [];
        return $cache[ $role_key ];
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
            "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $tid ? (int) $tid : null;
    }

    private static function isPlayerOwnRecord( int $user_id, int $player_id ): bool {
        global $wpdb;
        $uid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $uid > 0 && $uid === $user_id;
    }
}
