<?php
namespace TT\Modules\Backup;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Core\Container;
use TT\Core\ModuleInterface;

/**
 * BackupModule — TalentTrack backup + disaster recovery (#0013).
 *
 * Sprint 1 (this release) — engine + JSON/gzip serializer + local
 * destination + email destination + presets + settings page +
 * scheduler + health notice.
 *
 * Sprint 2 — partial restore with diff + pre-bulk auto-backup +
 * undo shortcut. (Not in this release.)
 *
 * The module's Settings UI is reachable as a tab on the existing
 * Configuration page (`Configuration → Backups`). The capability
 * `tt_manage_backups` gates everything; granted to administrator
 * and tt_head_dev by default.
 */
class BackupModule implements ModuleInterface {

    public function getName(): string { return 'backup'; }

    public function register( Container $container ): void {}

    public function boot( Container $container ): void {
        // Cron handler is registered everywhere so the scheduled event
        // fires whether the request is admin or front-end.
        Scheduler::init();
        // Pre-bulk safety hook fires from BulkActionsHelper which runs
        // on admin-post.php (admin context), but registering here is
        // free and keeps wiring in one place.
        BulkSafetyHook::init();
        // Make sure the cap exists for any user-cap check that runs
        // before an admin loads the settings page.
        add_action( 'init', [ self::class, 'ensureCapability' ] );

        if ( is_admin() ) {
            Admin\BackupSettingsPage::init();
            Admin\BackupHealthNotice::init();
            Admin\BulkUndoNotice::init();
        }
    }

    /**
     * Add `tt_manage_backups` to the administrator + tt_head_dev roles
     * if either is missing it. Idempotent — WP_Role::add_cap() is a
     * no-op when the cap is already granted.
     */
    public static function ensureCapability(): void {
        $cap = 'tt_manage_backups';
        foreach ( [ 'administrator', 'tt_head_dev' ] as $role_slug ) {
            $role = get_role( $role_slug );
            if ( $role && ! $role->has_cap( $cap ) ) {
                $role->add_cap( $cap );
            }
        }
    }
}
