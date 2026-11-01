<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * The one place the plugin opens, commits and rolls back a transaction.
 *
 * MySQL has no nested transactions: a `START TRANSACTION` while one is open
 * silently commits it. Before this, each repository that needed atomic
 * writes opened its own, so one of them running inside another's committed
 * the outer work early and a later failure could no longer undo it (#4561).
 *
 * Here the outermost `begin()` issues `START TRANSACTION`, and an inner one
 * a savepoint. `commit()` and `rollback()` close the innermost level: at the
 * outside `COMMIT` / `ROLLBACK`, inside `RELEASE SAVEPOINT` /
 * `ROLLBACK TO SAVEPOINT`. An inner rollback undoes only its own writes and
 * leaves the outer transaction to decide.
 *
 * Every `begin()` must be closed by exactly one `commit()` or `rollback()`
 * on every path, including a throw.
 *
 * `AfterCommit` holds outbound sends until the outermost commit, and drops
 * those queued inside a level that rolled back.
 */
final class Transaction {

    private static int $depth = 0;

    /** How many levels are open: 0 outside a transaction. */
    public static function depth(): int {
        return self::$depth;
    }

    public static function begin(): void {
        global $wpdb;
        if ( self::$depth === 0 ) {
            $wpdb->query( 'START TRANSACTION' );
        } else {
            $wpdb->query( 'SAVEPOINT ' . self::savepoint( self::$depth ) );
        }
        self::$depth++;
    }

    public static function commit(): void {
        global $wpdb;
        if ( self::$depth === 0 ) return;
        self::$depth--;
        if ( self::$depth === 0 ) {
            $wpdb->query( 'COMMIT' );
            AfterCommit::committed();
            return;
        }
        $wpdb->query( 'RELEASE SAVEPOINT ' . self::savepoint( self::$depth ) );
        AfterCommit::released( self::$depth );
    }

    public static function rollback(): void {
        global $wpdb;
        if ( self::$depth === 0 ) return;
        self::$depth--;
        if ( self::$depth === 0 ) {
            $wpdb->query( 'ROLLBACK' );
            AfterCommit::rolledBack( 0 );
            return;
        }
        $name = self::savepoint( self::$depth );
        $wpdb->query( 'ROLLBACK TO SAVEPOINT ' . $name );
        $wpdb->query( 'RELEASE SAVEPOINT ' . $name );
        AfterCommit::rolledBack( self::$depth );
    }

    /** The savepoint that opened the level above `$level`. */
    private static function savepoint( int $level ): string {
        return 'tt_sp_' . $level;
    }
}
