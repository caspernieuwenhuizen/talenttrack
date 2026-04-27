<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RolesService — centralized role and capability management.
 *
 * v3.0.0 — capability refactor. Each cap now exists in both a VIEW
 * and an EDIT flavor, following the "medium granularity" design:
 *
 *   OLD (pre-3.0)               NEW (3.0+)
 *   tt_manage_players       →   tt_view_players + tt_edit_players
 *   tt_evaluate_players     →   tt_view_evaluations + tt_edit_evaluations
 *                           +   tt_view_activities + tt_edit_activities
 *                           +   tt_view_goals + tt_edit_goals
 *   tt_manage_settings      →   tt_view_settings + tt_edit_settings
 *   tt_view_reports         →   tt_view_reports (kept; no write companion)
 *
 * Backward compat: the OLD caps still work. They are soft aliases
 * implemented via a `map_meta_cap` filter (see CapabilityAliases in
 * src/Infrastructure/Security/CapabilityAliases.php). Any call like
 * `current_user_can('tt_manage_players')` resolves to a check for
 * `tt_edit_players` (assuming they can edit, they can also "manage").
 * This keeps all existing ~60-80 call sites working while slice 2
 * gradually rewrites them to the granular caps.
 *
 * Observer role: now has all tt_view_* caps and none of the tt_edit_*
 * caps, so it's a genuinely useful read-only role across every admin
 * and frontend surface.
 */
class RolesService {

    /* ═══════════════ Capability inventory ═══════════════ */

    /**
     * All TalentTrack capabilities, grouped by area. The right-hand
     * side is [view_cap, edit_cap] or [view_cap, null] when no write
     * companion exists (reports, debug).
     */
    public const VIEW_CAPS = [
        'tt_view_teams',
        'tt_view_players',
        'tt_view_people',
        'tt_view_evaluations',
        'tt_view_activities',
        'tt_view_goals',
        'tt_view_reports',
        'tt_view_settings',
    ];

    public const EDIT_CAPS = [
        'tt_edit_teams',
        'tt_edit_players',
        'tt_edit_people',
        'tt_edit_evaluations',
        'tt_edit_activities',
        'tt_edit_goals',
        'tt_edit_settings',
    ];

    /**
     * Legacy caps kept for backward compatibility during v3 transition.
     * These are included in role assignments so any code still checking
     * them (via current_user_can or role membership) continues to work
     * until slice 2 finishes migrating call sites.
     */
    public const LEGACY_CAPS = [
        'tt_manage_players',
        'tt_evaluate_players',
        'tt_manage_settings',
        // 'tt_view_reports' kept as-is — no change
    ];

    /** @return array<string, array<string, string|array<string,bool>>> */
    public function roleDefinitions(): array {
        return [
            'tt_head_dev' => [
                'label' => __( 'Head of Development', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allCapsTrue(),  // everything: view + edit everywhere
                    self::legacyCapsTrue(),
                    // #0019 Sprint 5 — frontend admin tier access. Granted
                    // by default to head-dev + administrator. Other roles
                    // never get this cap unless explicitly assigned.
                    [ 'tt_access_frontend_admin' => true ]
                ),
            ],
            'tt_club_admin' => [
                'label' => __( 'Club Admin', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allViewCapsTrue(),
                    [
                        'tt_edit_teams'      => true,
                        'tt_edit_players'    => true,
                        'tt_edit_people'     => true,
                        'tt_edit_activities'   => true,
                        'tt_edit_goals'      => true,
                        'tt_edit_settings'   => true,
                        // NOT tt_edit_evaluations — Club Admin doesn't evaluate
                    ],
                    [
                        'tt_manage_players'  => true,
                        'tt_manage_settings' => true,
                    ]
                ),
            ],
            'tt_coach' => [
                'label' => __( 'Coach', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'       => true,
                        'tt_view_players'     => true,
                        'tt_view_people'      => true,
                        'tt_view_evaluations' => true,
                        'tt_view_activities'    => true,
                        'tt_view_goals'       => true,
                        'tt_view_reports'     => true,
                        // NOT tt_view_settings — coaches don't touch config
                    ],
                    [
                        'tt_edit_evaluations' => true,
                        'tt_edit_activities'    => true,
                        'tt_edit_goals'       => true,
                        // NOT tt_edit_players/teams/people/settings
                    ],
                    [
                        'tt_evaluate_players' => true,
                    ]
                ),
            ],
            'tt_scout' => [
                'label' => __( 'Scout', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'       => true,
                        'tt_view_players'     => true,
                        'tt_view_evaluations' => true,
                    ],
                    [
                        'tt_edit_evaluations' => true,
                    ],
                    [
                        'tt_evaluate_players' => true,
                    ]
                ),
            ],
            'tt_staff' => [
                'label' => __( 'Staff', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    [
                        'tt_view_teams'     => true,
                        'tt_view_players'   => true,
                        'tt_view_people'    => true,
                    ],
                    [
                        'tt_edit_players'   => true,
                        'tt_edit_people'    => true,
                    ],
                    [
                        'tt_manage_players' => true,
                    ]
                ),
            ],
            'tt_player' => [
                'label' => __( 'Player', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            'tt_parent' => [
                'label' => __( 'Parent', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            // v3.0.0 — now a meaningful role: view EVERYTHING, edit NOTHING.
            'tt_readonly_observer' => [
                'label' => __( 'Read-Only Observer', 'talenttrack' ),
                'caps'  => array_merge(
                    [ 'read' => true ],
                    self::allViewCapsTrue()
                    // No edit caps. No legacy caps. Pure view.
                ),
            ],
        ];
    }

    /* ═══════════════ Role + cap installation ═══════════════ */

    /**
     * Install all TT roles + ensure administrator has every TT cap.
     * Safe to call on every activation — add_role is a no-op if the
     * role exists. WordPress's native behaviour when add_role is
     * called on an existing role is to leave caps alone; our
     * ensureCapabilities() covers the re-grant case.
     */
    public function installRoles(): void {
        foreach ( $this->roleDefinitions() as $slug => $def ) {
            add_role( $slug, (string) $def['label'], (array) $def['caps'] );
        }
        $this->ensureCapabilities();
    }

    /**
     * Grant all TT caps to administrator + re-assert role caps. Called
     * as part of every runMigrations() so role definition changes
     * (new caps added in new releases) propagate without manual
     * intervention.
     */
    public function ensureCapabilities(): void {
        $all = array_merge( self::VIEW_CAPS, self::EDIT_CAPS, self::LEGACY_CAPS, [ 'tt_view_reports', 'tt_access_frontend_admin' ] );

        $administrator = get_role( 'administrator' );
        if ( $administrator ) {
            foreach ( array_unique( $all ) as $cap ) {
                if ( ! $administrator->has_cap( $cap ) ) {
                    $administrator->add_cap( $cap );
                }
            }
        }

        foreach ( $this->roleDefinitions() as $slug => $def ) {
            $role = get_role( $slug );
            if ( ! $role ) continue;
            foreach ( (array) $def['caps'] as $cap => $granted ) {
                if ( $granted && ! $role->has_cap( $cap ) ) {
                    $role->add_cap( $cap );
                }
            }
        }
    }

    /**
     * Remove TT roles (used by a future cleanup/uninstall path).
     */
    public function uninstallRoles(): void {
        foreach ( array_keys( $this->roleDefinitions() ) as $slug ) {
            remove_role( $slug );
        }
    }

    /* ═══════════════ Helpers ═══════════════ */

    /** @return array<string,bool> */
    private static function allViewCapsTrue(): array {
        return array_fill_keys( self::VIEW_CAPS, true );
    }

    /** @return array<string,bool> */
    private static function allEditCapsTrue(): array {
        return array_fill_keys( self::EDIT_CAPS, true );
    }

    /** @return array<string,bool> */
    private static function allCapsTrue(): array {
        return array_merge( self::allViewCapsTrue(), self::allEditCapsTrue() );
    }

    /** @return array<string,bool> */
    private static function legacyCapsTrue(): array {
        return array_fill_keys( self::LEGACY_CAPS, true );
    }
}
