<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Outbound side effects that must wait for a transaction to commit.
 *
 * A message, an e-mail or a notification cannot be rolled back. When it is
 * triggered by a write inside a transaction, sending it straight away
 * announces something the rollback may then undo. A sender calls `run()`,
 * which sends at once when no `Transaction` is open and otherwise holds the
 * send until the outermost commit (#4550, #4561).
 *
 * Each held send remembers the level it was queued at. A savepoint that
 * rolls back drops what was queued inside it; one that is released hands
 * it to the level around it; the outermost commit sends it; the outermost
 * rollback drops it. `Transaction` reports those moments here.
 */
final class AfterCommit {

    /** @var list<array{0: int, 1: callable(): void}> level, send */
    private static array $queue = [];

    /** Run `$send` now, or after the open transaction commits. */
    public static function run( callable $send ): void {
        $depth = Transaction::depth();
        if ( $depth === 0 ) {
            $send();
            return;
        }
        self::$queue[] = [ $depth, $send ];
    }

    /** The outermost transaction committed: send what it held back. */
    public static function committed(): void {
        $queue       = self::$queue;
        self::$queue = [];
        foreach ( $queue as [ , $send ] ) {
            try {
                $send();
            } catch ( \Throwable $e ) {
                // The writes are committed; a failed send must not undo the
                // caller's success or stop the sends after it.
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( '[TalentTrack] A send held for the commit failed: ' . $e->getMessage() );
                }
            }
        }
    }

    /** The savepoint opened at `$level` was released: its sends move out a level. */
    public static function released( int $level ): void {
        foreach ( self::$queue as $i => $entry ) {
            if ( $entry[0] > $level ) self::$queue[ $i ][0] = $level;
        }
    }

    /** Everything above `$level` rolled back: what it queued is not sent. */
    public static function rolledBack( int $level ): void {
        self::$queue = array_values( array_filter(
            self::$queue,
            static fn( array $entry ): bool => $entry[0] <= $level
        ) );
    }
}
