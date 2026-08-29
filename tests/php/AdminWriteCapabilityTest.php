<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;

/**
 * #3023 — no wp-admin write handler is authorised by a read capability.
 *
 * The category-weights page gated its save and its reset on
 * `tt_view_settings`. A *view* capability was what stood between a user
 * and rewriting the weighting behind every composite rating in the
 * academy — and because `tt_view_settings` is a roll-up of the per-area
 * view sub-caps, Head of Development held it by construction, despite
 * #0071 and migration 0054 deliberately stripping their `tt_edit_*` caps.
 *
 * The shape was not unique to that page, which is why this is a test and
 * not four edits. It reads the source rather than exercising the
 * handlers: they end in `wp_die()` or `exit`, and what is being asserted
 * is which capability name appears in the guard, which is a property of
 * the text.
 */
final class AdminWriteCapabilityTest extends WP_UnitTestCase {

    /**
     * Handlers that legitimately still name a `tt_view_*` capability.
     *
     * Each entry is a decision, not a backlog: the rule for the #3023
     * sweep was to narrow only where a narrower capability **already
     * exists**, because inventing one is a matrix change and belongs in
     * its own issue with its own review.
     *
     * @var array<string, string> Class::method => why
     */
    private const ALLOWED = [
        // Granting and revoking an authorization role to a person. There
        // is no `tt_edit_roles` / `tt_manage_roles` capability, and the
        // nearest candidate — `tt_manage_authorization` — means "edit the
        // permission matrix", which is a different act. Naming the right
        // capability here is a matrix change; reported on #3023.
        'RolesPage::handleGrant'  => 'No capability exists for granting a role to a person.',
        'RolesPage::handleRevoke' => 'No capability exists for revoking a role from a person.',

        // Archiving a scheduled report gates on `tt_view_analytics`.
        // There is no `tt_edit_analytics` / `tt_manage_analytics`, and its
        // sibling `handleDeletePermanent` uses `tt_edit_settings` — a
        // capability about settings, not about analytics, so copying it
        // would be a guess rather than a fix. Reported on #3023.
        'ScheduledReportsActionHandlers::handleArchive' => 'No analytics write capability exists.',
    ];

    /**
     * A note on what this does NOT catch: a handler whose guard is not a
     * literal `current_user_can()` / `userCanOrMatrix()` call — one that
     * delegates to a helper, or relies on the menu capability alone —
     * reads as "no guard" and is skipped. The check under-reports rather
     * than guessing, which is the right failure direction for a lint that
     * gates a merge.
     */

    public function test_no_admin_write_handler_gates_on_a_view_capability(): void {
        $findings = [];

        foreach ( $this->adminFiles() as $file ) {
            $src   = (string) file_get_contents( $file );
            $class = basename( $file, '.php' );
            $consts = $this->constants( $src );

            foreach ( $this->handlerGuards( $src ) as $method => $cap ) {
                $cap = $consts[ $cap ] ?? $cap;
                if ( strpos( $cap, 'tt_view_' ) !== 0 ) continue;
                if ( isset( self::ALLOWED[ $class . '::' . $method ] ) ) continue;

                $findings[] = "{$class}::{$method} is authorised by `{$cap}`, a read capability.";
            }
        }

        $this->assertSame( [], $findings, implode( "\n", $findings ) );
    }

    /** Every allowlist entry still describes a handler that exists. */
    public function test_the_allowlist_has_no_dead_entries(): void {
        $seen = [];
        foreach ( $this->adminFiles() as $file ) {
            $class = basename( $file, '.php' );
            foreach ( array_keys( $this->handlerGuards( (string) file_get_contents( $file ) ) ) as $method ) {
                $seen[ $class . '::' . $method ] = true;
            }
        }

        foreach ( array_keys( self::ALLOWED ) as $entry ) {
            $this->assertArrayHasKey(
                $entry,
                $seen,
                "The allowlist names {$entry}, which no longer exists. Remove it."
            );
        }
    }

    /** @return list<string> */
    private function adminFiles(): array {
        $out = [];
        $it  = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator( TT_PLUGIN_DIR . 'src' )
        );
        foreach ( $it as $entry ) {
            if ( ! $entry->isFile() ) continue;
            $path = str_replace( '\\', '/', $entry->getPathname() );
            if ( substr( $path, -4 ) !== '.php' ) continue;
            if ( strpos( $path, '/Admin/' ) === false ) continue;
            $out[] = $path;
        }
        sort( $out );
        return $out;
    }

    /**
     * Class string constants, so a guard written as `self::CAP` resolves.
     *
     * @return array<string, string> "self::NAME" => value
     */
    private function constants( string $src ): array {
        $out = [];
        if ( preg_match_all( "/const\s+([A-Z0-9_]+)\s*=\s*'([^']+)'/", $src, $m, PREG_SET_ORDER ) ) {
            foreach ( $m as $hit ) {
                $out[ 'self::' . $hit[1] ] = $hit[2];
            }
        }
        return $out;
    }

    /**
     * The capability each `handle*` method's first guard names.
     *
     * A write handler's guard is the first `current_user_can()` or
     * `userCanOrMatrix()` inside it; anything after that is a finer
     * check, not the gate.
     *
     * @return array<string, string> method => capability or `self::CONST`
     */
    private function handlerGuards( string $src ): array {
        $out = [];
        if ( ! preg_match_all(
            '/public\s+static\s+function\s+(handle[A-Za-z0-9_]*)\s*\(/',
            $src,
            $m,
            PREG_OFFSET_CAPTURE
        ) ) {
            return $out;
        }

        $count = count( $m[0] );
        for ( $i = 0; $i < $count; $i++ ) {
            $start = (int) $m[0][ $i ][1];
            $end   = $i + 1 < $count ? (int) $m[0][ $i + 1 ][1] : strlen( $src );
            $body  = substr( $src, $start, $end - $start );

            // The optional group is spelled out rather than written as
            // "anything up to a comma": a loose version runs past the
            // closing paren into the `esc_html__( 'Unauthorized',
            // 'talenttrack' )` on the next line and reports the text
            // domain as the capability.
            if ( preg_match(
                "/(?:current_user_can|userCanOrMatrix)\(\s*(?:get_current_user_id\(\)\s*,\s*)?(self::[A-Z0-9_]+|'[a-z_]+')/",
                $body,
                $g
            ) ) {
                $out[ (string) $m[1][ $i ][0] ] = trim( $g[1], "'" );
            }
        }
        return $out;
    }
}
