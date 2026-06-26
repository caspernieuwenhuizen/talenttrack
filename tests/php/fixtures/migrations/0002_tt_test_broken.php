<?php
/**
 * Test fixture migration (#1388) — fails. Used by MigrationRunnerTest to
 * assert the runner surfaces a failure (ok=false + error), records it in
 * FAILURES_OPTION, and never marks it applied (so it re-runs).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {
    public function getName(): string {
        return '0002_tt_test_broken';
    }
    public function up(): void {
        throw new \RuntimeException( 'deliberate fixture failure (#1388)' );
    }
};
