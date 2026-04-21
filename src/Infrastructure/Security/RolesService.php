<?php
namespace TT\Infrastructure\Security;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * RolesService — centralized role and capability management.
 *
 * The 7 TalentTrack roles (Sprint 0 aligned, extended with tt_head_dev):
 *   system_admin   = WordPress 'administrator' (inherits all caps)
 *   tt_head_dev    = Head of Development (technical/methodology)
 *   tt_club_admin  = Club Admin (operational/management)
 *   tt_coach       = Coach
 *   tt_scout       = Scout
 *   tt_staff       = Staff
 *   tt_player      = Player
 *   tt_parent      = Parent
 *
 * Custom capabilities:
 *   tt_manage_players   — create/edit/delete players & teams
 *   tt_evaluate_players — submit evaluations, goals, attendance
 *   tt_manage_settings  — change configuration & branding
 *   tt_view_reports     — access reporting module
 */
class RolesService {

    /** @return array<string, array<string, string|array<string,bool>>> */
    public function roleDefinitions(): array {
        return [
            'tt_head_dev' => [
                'label' => __( 'Head of Development', 'talenttrack' ),
                'caps'  => [
                    'read' => true,
                    'tt_manage_players'   => true,
                    'tt_evaluate_players' => true,
                    'tt_manage_settings'  => true,
                    'tt_view_reports'     => true,
                ],
            ],
            'tt_club_admin' => [
                'label' => __( 'Club Admin', 'talenttrack' ),
                'caps'  => [
                    'read' => true,
                    'tt_manage_players'  => true,
                    'tt_manage_settings' => true,
                    'tt_view_reports'    => true,
                ],
            ],
            'tt_coach' => [
                'label' => __( 'Coach', 'talenttrack' ),
                'caps'  => [
                    'read' => true,
                    'tt_evaluate_players' => true,
                    'tt_view_reports'     => true,
                ],
            ],
            'tt_scout' => [
                'label' => __( 'Scout', 'talenttrack' ),
                'caps'  => [
                    'read' => true,
                    'tt_evaluate_players' => true,
                ],
            ],
            'tt_staff' => [
                'label' => __( 'Staff', 'talenttrack' ),
                'caps'  => [
                    'read' => true,
                    'tt_manage_players' => true,
                ],
            ],
            'tt_player' => [
                'label' => __( 'Player', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            'tt_parent' => [
                'label' => __( 'Parent', 'talenttrack' ),
                'caps'  => [ 'read' => true ],
            ],
            // v2.21.0: narrow read-only role. Sees reports, teams, players,
            // evaluations, sessions, and goals through the frontend tile
            // grid — but has NO management or evaluation caps, so every
            // write action (add/edit/delete) is blocked at controller
            // level. Use for assistant coaches in training, scouts who
            // should observe only, parent board members, external
            // reviewers, etc. Full cap-split refactor (separate view/edit
            // capabilities per entity) is slated for a future sprint;
            // this role is the lightweight gate-keeper for now.
            'tt_readonly_observer' => [
                'label' => __( 'Read-Only Observer', 'talenttrack' ),
                'caps'  => [
                    'read'            => true,
                    'tt_view_reports' => true,
                ],
            ],
        ];
    }

    /**
     * Install all TT roles. Safe to call on every activation — add_role is a no-op
     * if the role exists.
     */
    public function installRoles(): void {
        foreach ( $this->roleDefinitions() as $slug => $def ) {
            add_role( $slug, (string) $def['label'], (array) $def['caps'] );
        }
        $this->ensureCapabilities();
    }

    /**
     * Ensure administrator has all TT capabilities. Also re-asserts role caps
     * in case something removed them. Idempotent.
     */
    public function ensureCapabilities(): void {
        $admin = get_role( 'administrator' );
        if ( $admin ) {
            foreach ( [ 'tt_manage_players', 'tt_evaluate_players', 'tt_manage_settings', 'tt_view_reports' ] as $cap ) {
                if ( ! $admin->has_cap( $cap ) ) {
                    $admin->add_cap( $cap );
                }
            }
        }
        // Re-assert role caps
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
}
