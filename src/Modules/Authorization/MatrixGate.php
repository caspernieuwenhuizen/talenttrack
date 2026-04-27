<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\Authorization\Matrix\MatrixRepository;

/**
 * MatrixGate — single read API for "can persona P perform activity A
 * on entity E in scope S?" (#0033 Sprint 1).
 *
 * Sprint 1 ships the read API only; nothing calls it yet. Sprint 2
 * routes the legacy `user_has_cap` filter through here. Sprint 8 ships
 * the apply toggle that lets a club switch to matrix-driven enforcement.
 *
 * Algorithm:
 *   1. Resolve effective personas for the user (active-persona lens or
 *      union — see PersonaResolver::effectivePersonas).
 *   2. For each persona, look up the matrix row for
 *      (persona, entity, activity, scope_kind).
 *   3. If `scope_kind` is not `global`, verify the user actually holds
 *      that scope (e.g. for `team`, that they're assigned to
 *      `$scope_target_id` via tt_user_role_scopes).
 *   4. Sprint 5 hook: short-circuit `false` if the entity's owning
 *      module is disabled. Wired here as a TODO comment until Sprint 5
 *      lands `ModuleRegistry::isEnabled()`.
 *   5. Return `true` on first persona that satisfies all checks.
 *
 * Static API for ergonomic call sites: `MatrixGate::can($user_id, ...)`.
 */
class MatrixGate {

    /** Activity constants — matches the seed. */
    public const READ          = 'read';
    public const CHANGE        = 'change';
    public const CREATE_DELETE = 'create_delete';

    /** Scope kind constants — matches the seed. */
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_TEAM   = 'team';
    public const SCOPE_PLAYER = 'player';
    public const SCOPE_SELF   = 'self';

    /**
     * Can the user perform the activity on the entity in the scope?
     *
     * @param int      $user_id          WP user id (NOT person_id).
     * @param string   $entity           e.g. 'players', 'evaluations', 'team'.
     * @param string   $activity         self::READ | CHANGE | CREATE_DELETE.
     * @param string   $scope_kind       self::SCOPE_*.
     * @param int|null $scope_target_id  team_id when scope='team'; player_id when 'player';
     *                                   user_id when 'self'. Required for non-global scopes.
     */
    public static function can(
        int $user_id,
        string $entity,
        string $activity,
        string $scope_kind = self::SCOPE_GLOBAL,
        ?int $scope_target_id = null
    ): bool {
        if ( $user_id <= 0 ) return false;

        $personas = PersonaResolver::effectivePersonas( $user_id );
        if ( empty( $personas ) ) return false;

        $repo = new MatrixRepository();

        foreach ( $personas as $persona ) {
            if ( ! $repo->lookup( $persona, $entity, $activity, $scope_kind ) ) {
                continue;
            }

            // #0033 Sprint 5 — short-circuit if the entity's owning
            // module is disabled. One auth check, no parallel "is the
            // module on?" branch in callers.
            $module_class = $repo->moduleFor( $persona, $entity, $activity, $scope_kind );
            if ( $module_class !== null && ! \TT\Core\ModuleRegistry::isEnabled( $module_class ) ) {
                continue;
            }

            if ( $scope_kind === self::SCOPE_GLOBAL ) {
                return true;
            }

            if ( $scope_target_id === null || $scope_target_id <= 0 ) {
                // Non-global scope without a target = caller bug. Refuse safely.
                continue;
            }

            if ( self::userHasScope( $user_id, $scope_kind, $scope_target_id ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Does the user actually hold the requested runtime scope?
     *
     * Sprint 1 covers the three scope kinds in active use:
     *   - team:   assigned via tt_user_role_scopes.scope_type='team' AND scope_id=$target.
     *   - player: linked via tt_players.wp_user_id (the player themselves)
     *             OR via tt_player_parents (a parent of the player).
     *             Scouts (#0017) pick up trial-case access via the same
     *             tt_user_role_scopes table; until #0017 ships there's
     *             no surface to exercise that path.
     *   - self:   $target_id matches the user_id.
     */
    private static function userHasScope( int $user_id, string $scope_kind, int $target_id ): bool {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( $scope_kind === self::SCOPE_SELF ) {
            return $user_id === $target_id;
        }

        if ( $scope_kind === self::SCOPE_PLAYER ) {
            // Player viewing self?
            $self_player = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_players WHERE wp_user_id = %d LIMIT 1",
                $user_id
            ) );
            if ( $self_player === $target_id ) return true;

            // Parent of the player?
            $is_parent = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}tt_player_parents WHERE player_id = %d AND parent_user_id = %d LIMIT 1",
                $target_id, $user_id
            ) );
            if ( $is_parent === 1 ) return true;

            return false;
        }

        if ( $scope_kind === self::SCOPE_TEAM ) {
            // tt_user_role_scopes is keyed on person_id, not user_id.
            // Resolve via tt_people.wp_user_id (the canonical TT-person link).
            $person_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_people WHERE wp_user_id = %d LIMIT 1",
                $user_id
            ) );
            if ( $person_id <= 0 ) return false;

            $today = current_time( 'Y-m-d' );
            $hit = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT 1 FROM {$p}tt_user_role_scopes
                  WHERE person_id = %d
                    AND scope_type = 'team'
                    AND scope_id = %d
                    AND ( start_date IS NULL OR start_date <= %s )
                    AND ( end_date   IS NULL OR end_date   >= %s )
                  LIMIT 1",
                $person_id, $target_id, $today, $today
            ) );
            return $hit === 1;
        }

        return false;
    }
}
