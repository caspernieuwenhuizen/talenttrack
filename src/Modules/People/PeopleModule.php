<?php
namespace TT\Modules\People;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\People\Admin\PeoplePage;

/**
 * PeopleModule — registers the People admin page and its form handlers.
 *
 * v2.7.1: Fixed to properly implement ModuleInterface. Previous version's
 * register() and boot() had the wrong signatures and the class didn't
 * declare `implements ModuleInterface`, causing ModuleRegistry to silently
 * skip it during load().
 */
class PeopleModule implements ModuleInterface {

    public function getName(): string {
        return 'people';
    }

    public function register( Container $container ): void {
        // admin_menu registration is deferred to boot() since it must run
        // inside the WP admin lifecycle.
        add_action( 'admin_post_tt_save_person',       [ PeoplePage::class, 'handleSave' ] );
        add_action( 'admin_post_tt_set_person_status', [ PeoplePage::class, 'handleSetStatus' ] );
        add_action( 'admin_post_tt_unassign_staff',    [ PeoplePage::class, 'handleUnassignStaff' ] );
        add_action( 'admin_post_tt_assign_staff',      [ self::class, 'handleAssignStaff' ] );
    }

    public function boot( Container $container ): void {
        add_action( 'admin_menu', [ $this, 'registerMenu' ], 15 );
    }

    public function registerMenu(): void {
        // v2.20.0: People menu registration moved to Menu::register() so
        // it appears under the People group separator. This method kept
        // as a no-op for backward compatibility with bootstrap callers.
    }

    /**
     * Handle the tt_assign_staff form submission from the team edit page.
     * Lives on the module (not PeoplePage) because it's triggered from the
     * Teams admin context, not the People page.
     *
     * v2.10.0 (Sprint 1G): Reads functional_role_id from the form (the
     * dropdown is now populated from tt_functional_roles).
     */
    public static function handleAssignStaff(): void {
        $team_id            = isset( $_POST['team_id'] ) ? absint( wp_unslash( (string) $_POST['team_id'] ) ) : 0;
        $person_id          = isset( $_POST['person_id'] ) ? absint( wp_unslash( (string) $_POST['person_id'] ) ) : 0;
        $functional_role_id = isset( $_POST['functional_role_id'] ) ? absint( wp_unslash( (string) $_POST['functional_role_id'] ) ) : 0;
        $start              = isset( $_POST['start_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['start_date'] ) ) : '';
        $end                = isset( $_POST['end_date'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['end_date'] ) ) : '';

        check_admin_referer( 'tt_assign_staff_' . $team_id, 'tt_nonce' );

        // v2.8.0: entity-scoped authorization. Assigning staff is a stricter
        // action than managing a team — only users who can also manage settings
        // can reorganize team structure.
        if ( ! \TT\Infrastructure\Security\AuthorizationService::canAssignStaff( get_current_user_id(), $team_id ) ) {
            wp_die( esc_html__( 'Unauthorized', 'talenttrack' ) );
        }

        $repo = new \TT\Infrastructure\People\PeopleRepository();
        $ok = $team_id > 0 && $person_id > 0 && $functional_role_id > 0
            && $repo->assignToTeam( $team_id, $person_id, $functional_role_id, $start ?: null, $end ?: null );

        $redirect = admin_url( 'admin.php?page=tt-teams&action=edit&id=' . $team_id . '&tt_msg=' . ( $ok ? 'saved' : 'error' ) );
        wp_safe_redirect( $redirect );
        exit;
    }
}
