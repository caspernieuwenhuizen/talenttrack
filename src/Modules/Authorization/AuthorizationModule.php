<?php
namespace TT\Modules\Authorization;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;
use TT\Infrastructure\REST\FunctionalRolesRestController;
use TT\Modules\Authorization\Admin\RolesPage;
use TT\Modules\Authorization\Admin\FunctionalRolesPage;
use TT\Modules\Authorization\Admin\DebugPage;

/**
 * AuthorizationModule — Sprint 1F (v2.9.0), Sprint 1G (v2.10.0).
 *
 * Registers the admin UI for roles, role assignments, functional roles,
 * and the permission diagnostic page. Pure UI wiring; no domain logic
 * lives here.
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
        add_action( 'admin_post_tt_save_functional_role_mapping',
            [ FunctionalRolesPage::class, 'handleSaveMapping' ] );
        // #0033 Sprint 3 + 5 + 8 — matrix editor + module toggles +
        // migration preview handlers.
        Admin\MatrixPage::init();
        Admin\ModulesPage::init();
        Admin\PreviewPage::init();
        // #0071 child 5 — impersonation start/end admin-post handlers.
        Impersonation\ImpersonationAdminPost::init();
    }

    public function boot( Container $container ): void {
        // v2.20.0: menu page registration moved to Menu::register() so
        // the Authorization pages live inside the proper "Access Control"
        // group with other grouped submenus. The admin_post handlers
        // registered in register() continue to work.
        FunctionalRolesRestController::init();

        // #0071 child 5 — impersonation banner + daily orphan cleanup cron.
        Impersonation\ImpersonationBanner::init();
        Impersonation\ImpersonationCron::init();

        // #0033 Sprint 2 — wire the user_has_cap bridge from legacy
        // `tt_*` capability checks into MatrixGate. Dormant by default
        // (the active flag is 0); Sprint 8 ships the apply toggle.
        if ( $this->isMatrixActive() ) {
            add_filter( 'user_has_cap', [ self::class, 'filterUserHasCap' ], 10, 4 );
        }
    }

    /**
     * Read the `tt_authorization_active` flag from tt_config. When set
     * to '1', the user_has_cap filter routes legacy caps through the
     * matrix; otherwise it is a no-op and native WP cap checks decide.
     *
     * Sprint 8's preview-and-apply UI flips this flag.
     */
    private function isMatrixActive(): bool {
        if ( ! class_exists( '\\TT\\Infrastructure\\Config\\ConfigService' ) ) return false;
        $config = new \TT\Infrastructure\Config\ConfigService();
        return $config->getBool( 'tt_authorization_active', false );
    }

    /**
     * #0033 Sprint 2 — user_has_cap filter callback.
     *
     * For each `tt_*` capability the caller asked about, look it up in
     * LegacyCapMapper. If the mapper has a tuple, call MatrixGate to
     * compute the matrix-driven answer and write it back into the
     * $allcaps array. Unknown caps fall through unchanged so native
     * WP cap evaluation keeps deciding.
     *
     * @param array<string, bool> $allcaps  current cap booleans
     * @param array<int, string>  $caps     caps the caller is asking about
     * @param array<int, mixed>   $args     [0] = cap, [1] = user_id, [2..] = optional context
     * @param \WP_User            $user
     * @return array<string, bool>
     */
    public static function filterUserHasCap( $allcaps, $caps, $args, $user ): array {
        if ( ! $user instanceof \WP_User ) return (array) $allcaps;
        if ( ! is_array( $allcaps ) )      $allcaps = [];

        foreach ( (array) $caps as $cap ) {
            if ( ! is_string( $cap ) || strpos( $cap, 'tt_' ) !== 0 ) continue;
            $result = LegacyCapMapper::evaluate( $cap, $user, (array) $args );
            if ( $result === null ) continue; // unknown — let native WP decide
            $allcaps[ $cap ] = $result;
        }
        return $allcaps;
    }
}
