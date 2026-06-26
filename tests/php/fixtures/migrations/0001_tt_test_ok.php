<?php
/**
 * Test fixture migration (#1388) — succeeds. Used by MigrationRunnerTest to
 * assert a clean migration is applied and recorded. Harmless, transactional
 * op only (no DDL, so WP_UnitTestCase's per-test rollback cleans it up).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {
    public function getName(): string {
        return '0001_tt_test_ok';
    }
    public function up(): void {
        update_option( 'tt_test_migration_ok_ran', '1', false );
    }
};
