<?php
/**
 * WP-CLI stub for PHPStan (#2981).
 *
 * `wp-cli/wp-cli` is not a composer dependency — it is the runtime that loads
 * WordPress, not a library WordPress loads — so PHPStan has never seen a single
 * WP_CLI symbol. Every call reads as "unknown class", which is why
 * MfaCliCommand's are grandfathered in the baseline one method at a time.
 *
 * Baselining was the wrong lever: it grandfathers the FILE, so the next CLI
 * command starts the same argument again, and any real mistake inside a
 * baselined file is invisible. Declaring the handful of symbols we actually
 * call teaches PHPStan the shape once, and new CLI code then passes clean —
 * which is the standard `phpstan.neon` already sets for new code.
 *
 * Only the signatures matter; the bodies are never executed, and this file is
 * listed in `scanFiles`, never required at runtime. Kept deliberately minimal:
 * adding a symbol here is a statement that plugin code calls it.
 */

namespace {

    class WP_CLI {
        /** @param string $message */
        public static function line( $message = '' ): void {}

        /** @param string $message */
        public static function log( $message ): void {}

        /** @param string $message */
        public static function success( $message ): void {}

        /** @param string $message */
        public static function warning( $message ): void {}

        /**
         * Terminates the process, which is why it is typed `never` rather than
         * `void`: WP-CLI exits here, so code after a call to it really is
         * unreachable and PHPStan should say so. Typing it `void` made every
         * existing guard clause in MfaCliCommand report as dead code.
         *
         * @param string|\Throwable $message
         * @param bool|int          $exit
         * @return never
         */
        public static function error( $message, $exit = true ) {
            exit( 1 );
        }

        /**
         * @param string                $name
         * @param callable|class-string $callable
         * @param array<string, mixed>  $args
         */
        public static function add_command( $name, $callable, $args = [] ): void {}
    }
}

namespace WP_CLI\Utils {

    /**
     * @param string                           $format
     * @param array<int, array<string, mixed>> $items
     * @param array<int, string>|string        $fields
     */
    function format_items( $format, $items, $fields ): void {}
}
