<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LegacyCapMapper — bridges legacy `tt_*` WordPress capability strings
 * to the matrix vocabulary `(entity, activity, scope_kind)` used by
 * MatrixGate (#0033 Sprint 2).
 *
 * Sprint 2 ships the bridge but leaves it dormant. The user_has_cap
 * filter (registered in AuthorizationModule::boot) consults the
 * `tt_authorization_active` config flag — when 0 (default) the filter
 * is a no-op and native WP capability checks decide. When 1 (set by
 * Sprint 8's apply toggle), the filter intercepts every `tt_*` cap
 * lookup and routes it through MatrixGate.
 *
 * This split lets Sprint 2 land additively. No production behavior
 * changes until an admin clicks Apply in the migration preview report
 * (Sprint 8). Until then the matrix is shadow data only.
 *
 * Mapping notes:
 *   - `tt_view_*` → ('entity', 'read')
 *   - `tt_edit_*` → ('entity', 'change')   — change is the v1 vocabulary;
 *                                            create_delete is a separate verb
 *   - `tt_manage_*` → ('entity', 'create_delete')
 *   - `tt_evaluate_players` → ('evaluations', 'create_delete') — owns the
 *                              act-of-evaluation, not the player record
 *   - `tt_access_frontend_admin` → ('frontend_admin', 'read')
 *   - `tt_edit_settings` / `tt_view_settings` / `tt_manage_settings`
 *     map to a `settings` entity that any module can subgate via the
 *     matrix's per-module config_tab entities (Sprint 6).
 *
 * Caps not in the table fall through (the filter callback leaves them
 * untouched, so native WP cap checks decide).
 */
final class LegacyCapMapper {

    /**
     * @var array<string, array{0:string, 1:string}>
     *      cap_slug => [ entity, activity ]
     */
    private const MAPPING = [
        // Core domain entities
        'tt_view_teams'                  => [ 'team',           'read' ],
        'tt_edit_teams'                  => [ 'team',           'change' ],
        'tt_view_players'                => [ 'players',        'read' ],
        'tt_edit_players'                => [ 'players',        'change' ],
        'tt_manage_players'              => [ 'players',        'create_delete' ],
        'tt_view_people'                 => [ 'people',         'read' ],
        'tt_edit_people'                 => [ 'people',         'change' ],
        'tt_view_evaluations'            => [ 'evaluations',    'read' ],
        'tt_edit_evaluations'            => [ 'evaluations',    'change' ],
        'tt_evaluate_players'            => [ 'evaluations',    'create_delete' ],
        'tt_view_goals'                  => [ 'goals',          'read' ],
        'tt_edit_goals'                  => [ 'goals',          'change' ],
        'tt_view_activities'             => [ 'activities',     'read' ],
        'tt_edit_activities'             => [ 'activities',     'change' ],
        'tt_view_methodology'            => [ 'methodology',    'read' ],
        'tt_edit_methodology'            => [ 'methodology',    'change' ],
        'tt_view_reports'                => [ 'reports',        'read' ],

        // Settings + admin
        'tt_view_settings'               => [ 'settings',       'read' ],
        'tt_edit_settings'               => [ 'settings',       'change' ],
        'tt_manage_settings'             => [ 'settings',       'create_delete' ],
        'tt_access_frontend_admin'       => [ 'frontend_admin', 'read' ],
        'tt_manage_functional_roles'     => [ 'functional_role_assignments', 'create_delete' ],
        'tt_manage_backups'              => [ 'backup',         'create_delete' ],

        // Workflow (#0022)
        'tt_view_own_tasks'              => [ 'workflow_tasks', 'read' ],
        'tt_view_tasks_dashboard'        => [ 'tasks_dashboard','read' ],
        'tt_configure_workflow_templates'=> [ 'workflow_templates','change' ],
        'tt_manage_workflow_templates'   => [ 'workflow_templates','create_delete' ],

        // Development management (#0009)
        'tt_submit_idea'                 => [ 'dev_ideas',      'change' ],
        'tt_refine_idea'                 => [ 'dev_ideas',      'change' ],
        'tt_view_dev_board'              => [ 'dev_ideas',      'read' ],
        'tt_promote_idea'                => [ 'dev_ideas',      'create_delete' ],

        // Invitations (#0032)
        'tt_send_invitation'             => [ 'invitations',    'create_delete' ],
        'tt_revoke_invitation'           => [ 'invitations',    'create_delete' ],
        'tt_manage_invite_messages'      => [ 'invitations',    'change' ],
        'tt_view_parent_dashboard'       => [ 'my_card',        'read' ],
    ];

    public static function isKnown( string $cap ): bool {
        return isset( self::MAPPING[ $cap ] );
    }

    /**
     * Translate a legacy `tt_*` capability into the matrix tuple that
     * represents it. Returns null for unknown caps so the filter
     * callback can leave them untouched.
     *
     * @return array{0:string, 1:string}|null  [entity, activity] or null
     */
    public static function tupleFor( string $cap ): ?array {
        return self::MAPPING[ $cap ] ?? null;
    }

    /**
     * The filter entry point. Given a cap + user, returns:
     *   - true  — the matrix grants the cap
     *   - false — the matrix denies it
     *   - null  — the cap is unknown to the mapper; caller should fall
     *             back to native WP cap evaluation
     *
     * @param array<int,mixed> $args raw $args from the user_has_cap filter
     */
    public static function evaluate( string $cap, \WP_User $user, array $args ): ?bool {
        $tuple = self::tupleFor( $cap );
        if ( $tuple === null ) return null;

        [ $entity, $activity ] = $tuple;

        // #0033 follow-up — answer the cap with `canAnyScope`. The
        // legacy `tt_*` cap vocabulary asks "does this user have this
        // ability anywhere?", which maps to "in any scope where they
        // hold a matching assignment". Returning the global-only check
        // here would silently revoke every team-scoped permission
        // (head_coach with `evaluations [rcd, team]` would fail
        // `tt_view_evaluations` because they have no global row), even
        // though the runtime gate at the actual write site would let
        // them through. `canAnyScope` keeps the matrix view trustworthy:
        // green ticks at any scope = true, no scope = false.
        return MatrixGate::canAnyScope( (int) $user->ID, $entity, $activity );
    }

    /**
     * @return list<string> all known cap slugs — used by tests / preview report.
     */
    public static function knownCaps(): array {
        return array_keys( self::MAPPING );
    }
}
