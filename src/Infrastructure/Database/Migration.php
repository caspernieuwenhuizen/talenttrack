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

    /**
     * #3854 — say what a migration did, and say when it did nothing.
     *
     * `exec()` above covers a statement that *fails*. It does not cover the
     * other way a migration comes to nothing: an early `return` on a guard.
     * The authorization seed top-ups (0276, 0277, 0278) all open with three
     * of them — the matrix table is missing, the seed file is unreadable,
     * the seed did not parse — and each returns without writing and without
     * saying so. The runner then records the migration as **run**.
     *
     * A missing table, an unreadable file and a complete success are
     * therefore indistinguishable afterwards: no rows, no error, no trace.
     * That is why #3706's grant was believed to be in place while a
     * reproduction said otherwise, and why nobody could tell from the
     * outside whether the top-up had run or had quietly done nothing.
     *
     * Call this on every exit path of a migration whose job is to write a
     * known set of rows. `$written` of 0 where rows were expected is a
     * failure to look into, not a no-op to shrug at.
     */
    protected function report( int $written, string $note = '' ): void {
        $line = sprintf(
            '[TT migration] %s: %d row(s) written%s',
            $this->getName(),
            $written,
            $note !== '' ? ' — ' . $note : ''
        );

        if ( class_exists( '\\TT\\Infrastructure\\Logging\\Logger' ) ) {
            if ( $written === 0 ) {
                \TT\Infrastructure\Logging\Logger::warning( 'migration.wrote_nothing', [
                    'migration' => $this->getName(),
                    'note'      => $note,
                ] );
            } else {
                \TT\Infrastructure\Logging\Logger::info( 'migration.applied', [
                    'migration' => $this->getName(),
                    'written'   => $written,
                ] );
            }
            return;
        }

        error_log( $line );
    }
}
