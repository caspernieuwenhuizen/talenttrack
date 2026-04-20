<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * AuthorizationService — central authorization layer for TalentTrack.
 *
 * Sprint 1E foundation. Provides entity-scoped authorization decisions that
 * sit on top of WordPress capabilities but add team/player scope awareness
 * via tt_team_people assignments.
 *
 * Design notes
 * ------------
 *  - This service does NOT replace WordPress roles or capabilities. It adds
 *    a domain-aware layer that combines capability checks with data lookups
 *    (which teams a user's linked person is assigned to, etc.).
 *  - Per-request caches for user→person_id, user→team_ids, and individual
 *    decision results. Caches flush on relevant write hooks.
 *  - Fail-safe deny: if no rule matches, return false.
 *  - Every decision fires `do_action('tt_auth_check', ...)` for audit and
 *    debugging.
 *  - Every decision passes through a `tt_auth_*` filter so other plugins
 *    or future Sprint 1F role-data can intervene without touching this
 *    class.
 *
 * How this maps to the enterprise RBAC roadmap
 * --------------------------------------------
 * Sprint 1E (NOW): hardcoded rules based on WP capabilities + team_people
 *                  assignments.
 * Sprint 1F:       replace hardcoded rules with data-driven evaluation
 *                  from tt_roles / tt_role_permissions / tt_user_role_scopes.
 *                  The public API of this class stays stable.
 * Sprint 1G:       admin UI for managing roles and permissions as data.
 *
 * This class's public method signatures are the stable contract. The
 * internal rule logic will be replaced in 1F; callers won't notice.
 */
class AuthorizationService {

    /* ═══════════════ Per-request caches ═══════════════ */

    /** @var array<int, int|null>  user_id => person_id|null */
    private static $cache_person = [];

    /** @var array<int, array<int, array<string>>>  user_id => team_id => [roles] */
    private static $cache_team_roles = [];

    /** @var array<string, bool>  "user_id:action:entity_type:entity_id" => bool */
    private static $cache_decisions = [];

    /**
     * Flush all per-request caches. Called automatically by registered hooks
     * (see register_cache_invalidators()) when relevant writes happen.
     */
    public static function flushCache(): void {
        self::$cache_person = [];
        self::$cache_team_roles = [];
        self::$cache_decisions = [];
    }

    /**
     * Register WP hooks that flush the cache on writes that could change
     * authorization outcomes. Idempotent — safe to call multiple times.
     *
     * Call once during plugin boot.
     */
    public static function registerCacheInvalidators(): void {
        static $registered = false;
        if ( $registered ) return;
        $registered = true;

        // Team-person assignments changed → team/permission caches stale.
        add_action( 'tt_person_assigned_to_team', [ __CLASS__, 'flushCache' ], 10, 0 );

        // Person's wp_user_id or status changed → user→person link may change.
        add_action( 'tt_person_created', [ __CLASS__, 'flushCache' ], 10, 0 );

        // User logs in/out → fresh session, fresh cache.
        add_action( 'wp_login',  [ __CLASS__, 'flushCache' ], 10, 0 );
        add_action( 'wp_logout', [ __CLASS__, 'flushCache' ], 10, 0 );
    }

    /* ═══════════════ Public API — entity-scoped decisions ═══════════════ */

    public static function canViewPlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'view_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            // Global: anyone who can manage players can view anyone.
            if ( self::userCan( $user_id, 'tt_manage_players' ) ) return true;

            // Scout can view any player.
            if ( self::userHasRole( $user_id, 'tt_scout' ) ) return true;

            // The player themselves can view their own record.
            if ( self::isPlayerOwnRecord( $user_id, $player_id ) ) return true;

            // Coach/staff assigned to player's team can view.
            $team_id = self::getPlayerTeamId( $player_id );
            if ( $team_id && self::userIsStaffOfTeam( $user_id, $team_id ) ) return true;

            return false;
        } );
    }

    public static function canEditPlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'edit_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            // Global capability.
            if ( self::userCan( $user_id, 'tt_manage_players' ) ) return true;

            // Head coach or manager of the player's team can edit. Assistant
            // coaches, physios, and others cannot edit player records.
            $team_id = self::getPlayerTeamId( $player_id );
            if ( ! $team_id ) return false;

            $roles = self::getUserRolesOnTeam( $user_id, $team_id );
            return in_array( 'head_coach', $roles, true )
                || in_array( 'manager', $roles, true );
        } );
    }

    public static function canEvaluatePlayer( int $user_id, int $player_id ): bool {
        return self::decide( 'evaluate_player', $user_id, 'player', $player_id, function () use ( $user_id, $player_id ) {
            // Admin / Head of Development / Club Admin with evaluate cap.
            if ( self::userCan( $user_id, 'tt_evaluate_players' )
                 && self::userCan( $user_id, 'tt_manage_players' ) ) {
                return true;
            }

            // Scouts can evaluate any player.
            if ( self::userHasRole( $user_id, 'tt_scout' ) ) return true;

            // Coaches of the player's team can evaluate.
            $team_id = self::getPlayerTeamId( $player_id );
            if ( ! $team_id ) return false;

            if ( ! self::userCan( $user_id, 'tt_evaluate_players' ) ) return false;

            $roles = self::getUserRolesOnTeam( $user_id, $team_id );
            return in_array( 'head_coach', $roles, true )
                || in_array( 'assistant_coach', $roles, true );
        } );
    }

    public static function canManageTeam( int $user_id, int $team_id ): bool {
        return self::decide( 'manage_team', $user_id, 'team', $team_id, function () use ( $user_id, $team_id ) {
            // Global capability.
            if ( self::userCan( $user_id, 'tt_manage_players' ) ) return true;

            // Head coach / manager of the specific team.
            $roles = self::getUserRolesOnTeam( $user_id, $team_id );
            return in_array( 'head_coach', $roles, true )
                || in_array( 'manager', $roles, true );
        } );
    }

    public static function canAssignStaff( int $user_id, int $team_id ): bool {
        return self::decide( 'assign_staff', $user_id, 'team', $team_id, function () use ( $user_id, $team_id ) {
            // Assigning staff is a stricter action than managing the team —
            // only admins / head-of-development / club-admin should be able to
            // reorganize team structure. Coaches who can manage training can't
            // reassign each other.
            return self::userCan( $user_id, 'tt_manage_players' )
                && self::userCan( $user_id, 'tt_manage_settings' );
        } );
    }

    /* ═══════════════ Public API — helpers ═══════════════ */

    /**
     * Resolve a WordPress user_id to a tt_people.id (or null if not linked).
     * Cached per request.
     */
    public static function getPersonIdByUserId( int $user_id ): ?int {
        if ( $user_id <= 0 ) return null;
        if ( array_key_exists( $user_id, self::$cache_person ) ) {
            return self::$cache_person[ $user_id ];
        }

        global $wpdb;
        $row = $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$wpdb->prefix}tt_people WHERE wp_user_id = %d AND status = 'active' LIMIT 1",
            $user_id
        ) );
        $person_id = $row ? (int) $row : null;
        self::$cache_person[ $user_id ] = $person_id;
        return $person_id;
    }

    /**
     * Convenience: the currently-logged-in user's person_id.
     */
    public static function getCurrentPersonId(): ?int {
        $uid = get_current_user_id();
        return $uid ? self::getPersonIdByUserId( $uid ) : null;
    }

    /**
     * Return the role_in_team values a user holds on a specific team.
     * Returns [] if the user has no linked person or no assignments.
     *
     * @return string[]
     */
    public static function getUserRolesOnTeam( int $user_id, int $team_id ): array {
        $map = self::getUserTeamRoles( $user_id );
        return $map[ $team_id ] ?? [];
    }

    /**
     * Full user → (team_id → [roles]) mapping. Cached per request.
     *
     * @return array<int, array<int, string>>
     */
    public static function getUserTeamRoles( int $user_id ): array {
        if ( isset( self::$cache_team_roles[ $user_id ] ) ) {
            return self::$cache_team_roles[ $user_id ];
        }

        $person_id = self::getPersonIdByUserId( $user_id );
        if ( ! $person_id ) {
            self::$cache_team_roles[ $user_id ] = [];
            return [];
        }

        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT team_id, role_in_team
             FROM {$wpdb->prefix}tt_team_people
             WHERE person_id = %d",
            $person_id
        ) );

        $map = [];
        if ( is_array( $rows ) ) {
            foreach ( $rows as $r ) {
                $tid  = (int) $r->team_id;
                $role = (string) $r->role_in_team;
                $map[ $tid ][] = $role;
            }
        }

        self::$cache_team_roles[ $user_id ] = $map;
        return $map;
    }

    /**
     * Does the given user have the given WP role slug?
     */
    public static function userHasRole( int $user_id, string $role_slug ): bool {
        $user = $user_id > 0 ? get_userdata( $user_id ) : false;
        if ( ! $user ) return false;
        return in_array( $role_slug, (array) $user->roles, true );
    }

    /* ═══════════════ Internals ═══════════════ */

    /**
     * Wrapper that handles caching, filtering, and hook firing for every
     * decision. Callers pass a closure that computes the raw rule result.
     */
    private static function decide( string $action, int $user_id, string $entity_type, int $entity_id, callable $rule ): bool {
        $key = $user_id . ':' . $action . ':' . $entity_type . ':' . $entity_id;

        if ( array_key_exists( $key, self::$cache_decisions ) ) {
            return self::$cache_decisions[ $key ];
        }

        $allowed = (bool) $rule();

        // Public filters — named per action so callers can be specific.
        $filter_name = 'tt_auth_can_' . $action;
        $allowed = (bool) apply_filters( $filter_name, $allowed, $user_id, $entity_id );

        // Generic filter — covers any action in one place if wanted.
        $allowed = (bool) apply_filters( 'tt_auth_check_result', $allowed, $action, $user_id, $entity_type, $entity_id );

        self::$cache_decisions[ $key ] = $allowed;

        /**
         * Fires after every authorization decision. Useful for audit logging.
         *
         * @param string $action     e.g. 'view_player', 'evaluate_player'
         * @param int    $user_id
         * @param int    $entity_id
         * @param bool   $result
         */
        do_action( 'tt_auth_check', $action, $user_id, $entity_id, $allowed );

        return $allowed;
    }

    private static function userCan( int $user_id, string $cap ): bool {
        return $user_id > 0 && user_can( $user_id, $cap );
    }

    private static function getPlayerTeamId( int $player_id ): ?int {
        global $wpdb;
        $tid = $wpdb->get_var( $wpdb->prepare(
            "SELECT team_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $tid ? (int) $tid : null;
    }

    private static function userIsStaffOfTeam( int $user_id, int $team_id ): bool {
        $map = self::getUserTeamRoles( $user_id );
        return ! empty( $map[ $team_id ] );
    }

    /**
     * Is the given player this user's own record?
     * True when tt_players.wp_user_id matches user_id (i.e. the player logged in).
     */
    private static function isPlayerOwnRecord( int $user_id, int $player_id ): bool {
        global $wpdb;
        $uid = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT wp_user_id FROM {$wpdb->prefix}tt_players WHERE id = %d",
            $player_id
        ) );
        return $uid > 0 && $uid === $user_id;
    }
}
