<?php
namespace TT\Core;

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\MigrationRunner;
use TT\Infrastructure\Security\RolesService;

/**
 * Activator — runs on plugin activation/deactivation.
 *
 * Phase 2 change: schema creation + seed logic no longer live here.
 * They live in database/migrations/0001_initial_schema.php and are
 * executed by MigrationRunner. This keeps Activator focused on
 * one-time install concerns (roles, rewrite flush) while letting
 * all future schema changes ship as versioned migrations.
 */
class Activator {

    public static function activate(): void {
        // 1. Install / refresh roles (unchanged).
        ( new RolesService() )->installRoles();

        // 2. Run any pending migrations. Covers:
        //    - fresh installs (0001_initial_schema creates all tables)
        //    - legacy installs upgrading from v2.0.1 (runner auto-detects
        //      existing tt_lookups and records 0001 as already-applied)
        //    - future migrations added in later releases.
        ( new MigrationRunner() )->run();

        flush_rewrite_rules();
    }

    public static function deactivate(): void {
        flush_rewrite_rules();
    }
}
