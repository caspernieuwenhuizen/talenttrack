<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Abstract Migration base.
 *
 * Each migration file under /database/migrations/ returns an anonymous
 * class extending this.
 *
 *   - getName() must return the filename (without .php). The runner
 *     uses this as the unique key stored in tt_migrations.
 *   - up()     must be idempotent — use CREATE TABLE IF NOT EXISTS,
 *              empty-row checks before seeding, etc.
 *   - Run every statement through exec() (#1357). The runner's
 *     fallback check reads $wpdb->last_error once AFTER up() returns,
 *     so a failed statement followed by a successful one is invisible
 *     and the migration gets marked applied half-done. exec() throws
 *     at the failing statement instead.
 *   - Column adds on EXISTING tables use
 *     MigrationHelpers::addColumnIfMissing(), never dbDelta — dbDelta
 *     silently no-ops ALTERs when the live table drifts from the
 *     CREATE statement (the #1331/0129 incident class; CI lints this).
 */
abstract class Migration {

    abstract public function getName(): string;

    abstract public function up(): void;

    /**
     * Run one statement; throw on failure so the runner records the
     * migration as failed at the exact statement that broke.
     *
     * @param string $sql  Already-prepared SQL (caller interpolates
     *                     only trusted identifiers; values go through
     *                     $wpdb->prepare before reaching here).
     * @return int Rows affected.
     */
    protected function exec( string $sql ): int {
        global $wpdb;
        $result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
        if ( $result === false ) {
            throw new \RuntimeException(
                'Migration statement failed: ' . $wpdb->last_error . ' — SQL: ' . substr( $sql, 0, 200 )
            );
        }
        return (int) $result;
    }
}
