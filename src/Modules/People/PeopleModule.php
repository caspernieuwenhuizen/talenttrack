<?php
namespace TT\Modules\People;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Modules\People\Admin\PeoplePage;

/**
 * PeopleModule — registers the People admin page and its form handlers.
 *
 * Follows the existing module pattern: instantiated by ModuleRegistry via
 * config/modules.php, wires up hooks on boot.
 */
class PeopleModule {

    public function register(): void {
        add_action( 'admin_menu',           [ $this, 'registerMenu' ], 15 );
        add_action( 'admin_post_tt_save_person',      [ PeoplePage::class, 'handleSave' ] );
        add_action( 'admin_post_tt_set_person_status',[ PeoplePage::class, 'handleSetStatus' ] );
        add_action( 'admin_post_tt_unassign_staff',   [ PeoplePage::class, 'handleUnassignStaff' ] );
        add_action( 'admin_post_tt_assign_staff',     [ self::class, 'handleAssignStaff' ] );
    }

    public function boot(): void {
        // Intentionally empty; hooks registered in register().
    }

    public function registerMenu(): void {
        // TODO: replace 'tt_manage_players' with a dedicated 'tt_manage_people'
        // capability once the RBAC module is updated (post-v2.7.0 backlog).
        $cap = 'tt_manage_players';

        add_submenu_page(
            'talenttrack',
            __( 'People', 'talenttrack' ),
            __( 'People', 'talenttrack' ),
            $cap,
            'tt-people',
            [ PeoplePage::class, 'render' ]
        );
    }

    /**
     * Handle the tt_assign_staff form submission from the team edit page.
     * Lives on the module (not PeoplePage) because it's triggered from the
     * Teams admin context, not the People page.
     */
    public static function handleAssignStaff(): void {
        if ( ! current_user_can( 'tt_manage_players' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $team_id   = isset( $_POST['team_id'] ) ? absint( wp_unslash( (string) $_POST['team_id'] ) ) : 0;
        $person_id = isset( $_POST['person_id'] ) ? absint( wp_unslash( (string) $_POST['person_id'] ) ) : 0;
        $role      = isset( $_POST['role_in_team'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['role_in_team'] ) ) : '';
        $start     = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_date'] ) ) : '';
        $end       = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['end_date'] ) ) : '';

        check_admin_referer( 'tt_assign_staff_' . $team_id, 'tt_nonce' );

        $repo = new \TT\Infrastructure\People\PeopleRepository();
        $ok = $team_id > 0 && $person_id > 0 && $role !== ''
            && $repo->assignToTeam( $team_id, $person_id, $role, $start ?: null, $end ?: null );

        $redirect = admin_url( 'admin.php?page=tt-teams&action=edit&id=' . $team_id . '&tt_msg=' . ( $ok ? 'saved' : 'error' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
