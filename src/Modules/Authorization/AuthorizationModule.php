<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Modules\Authorization\Admin\RolesPage;
use TT\Modules\Authorization\Admin\DebugPage;

/**
 * AuthorizationModule — Sprint 1F (v2.9.0).
 *
 * Registers the admin UI for roles, role assignments, and the permission
 * diagnostic page. Pure UI wiring; no domain logic lives here.
 *
 * Properly implements ModuleInterface (register takes Container, boot takes
 * Container, getName() returns a stable slug). Lesson from v2.7.0 where
 * PeopleModule didn't and was silently skipped by ModuleRegistry.
 */
class AuthorizationModule implements ModuleInterface {

    public function getName(): string {
        return 'authorization';
    }

    public function register( Container $container ): void {
        // admin_post handlers — need to be registered before admin_menu fires.
        add_action( 'admin_post_tt_grant_role',  [ RolesPage::class, 'handleGrant' ] );
        add_action( 'admin_post_tt_revoke_role', [ RolesPage::class, 'handleRevoke' ] );
    }

    public function boot( Container $container ): void {
        add_action( 'admin_menu', [ $this, 'registerMenu' ], 20 );
    }

    public function registerMenu(): void {
        // Gate on tt_manage_settings — this is admin-level configuration.
        // Team managers (tt_manage_players only) should not see this UI.
        $cap = 'tt_manage_settings';

        add_submenu_page(
            'talenttrack',
            __( 'Roles & Permissions', 'talenttrack' ),
            __( 'Roles & Permissions', 'talenttrack' ),
            $cap,
            'tt-roles',
            [ RolesPage::class, 'render' ]
        );

        add_submenu_page(
            'talenttrack',
            __( 'Permission Debug', 'talenttrack' ),
            __( 'Permission Debug', 'talenttrack' ),
            $cap,
            'tt-roles-debug',
            [ DebugPage::class, 'render' ]
        );
    }
}
