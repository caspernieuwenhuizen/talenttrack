<?php
namespace TT\Infrastructure\Database;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Outbound side effects that must wait for a transaction to commit.
 *
 * A message, an e-mail or a notification cannot be rolled back. When it is
 * triggered by a write inside a transaction, sending it straight away
 * announces something the rollback may then undo. Code that opens such a
 * transaction calls `open()` first and `commit()` or `discard()` after; a
 * sender calls `run()`, which sends at once when no transaction is open and
 * otherwise holds the send until the commit.
 *
 * Opened today only by `TaskEngine::complete()` around a form's completion
 * step (#4550).
 */
final class AfterCommit {

    private static int $depth = 0;

    /** @var list<callable(): void> */
    private static array $queue = [];

    public static function open(): void {
        self::$depth++;
    }

    /** Run `$send` now, or after the open transaction commits. */
    public static function run( callable $send ): void {
        if ( self::$depth === 0 ) {
            $send();
            return;
        }
        self::$queue[] = $send;
    }

    /** The transaction committed: send what it held back. */
    public static function commit(): void {
        if ( self::$depth === 0 ) return;
        self::$depth--;
        if ( self::$depth > 0 ) return;

        $queue       = self::$queue;
        self::$queue = [];
        foreach ( $queue as $send ) {
            $send();
        }
    }

    /** The transaction rolled back: nothing it held back is sent. */
    public static function discard(): void {
        if ( self::$depth === 0 ) return;
        self::$depth--;
        if ( self::$depth === 0 ) self::$queue = [];
    }
}
