<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RoleResolver (#0052 PR-B) — single chokepoint for the small number
 * of legitimately role-aware reads in the codebase.
 *
 * Capabilities are the auth contract. `current_user_can()` is the
 * answer to *can this user do X*. Role names are an implementation
 * detail of the WordPress auth backend. SaaS migration will replace
 * `WP_User`, `add_role()`, and the role table together; localising
 * every role-string read behind this helper means there's exactly
 * one file to re-implement when that swap happens.
 *
 * Two legitimate uses today:
 *
 *   - **Audience routing** (Documentation\AudienceResolver) — picks
 *     a docs audience based on the user's primary role. This is a
 *     domain decision (admin docs vs coach docs vs player docs) that
 *     happens to align with role names.
 *   - **Idempotency guards** (Onboarding\OnboardingHandlers) — checks
 *     whether `add_role()` would be a no-op before calling it. Tied
 *     to WP's role API by definition.
 *
 * Anything else is a smell. New code that wants to know "is this
 * user an X" should ask "can this user do Y" via `current_user_can()`.
 */
final class RoleResolver {

    /**
     * Returns the user's primary role for audience routing — first
     * matching role from the priority order, or 'guest' for logged-out
     * users. Higher-privilege roles win when a user has several.
     */
    public static function primaryRoleFor( int $user_id ): string {
        if ( $user_id <= 0 ) return 'guest';
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) return 'guest';

        $priority = [
            'administrator',
            'tt_head_dev',
            'tt_club_admin',
            'tt_coach',
            'tt_team_manager',
            'tt_scout',
            'tt_readonly_observer',
            'tt_parent',
            'tt_player',
        ];
        foreach ( $priority as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) return $role;
        }
        return 'guest';
    }

    /**
     * Idempotency check: does this user already hold the given role?
     * Used by Onboarding to skip a no-op `add_role()` and by
     * DemoDataCleaner to refuse to delete the last administrator.
     *
     * Treats this as the only place in the plugin where direct role
     * comparison is acceptable; future SaaS auth re-implements it.
     */
    public static function userHasRole( int $user_id, string $role ): bool {
        if ( $user_id <= 0 || $role === '' ) return false;
        $user = get_user_by( 'id', $user_id );
        if ( ! $user instanceof \WP_User ) return false;
        return in_array( $role, (array) $user->roles, true );
    }
}
