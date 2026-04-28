<?php
namespace TT\Infrastructure\Tenancy;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * CurrentClub (#0052 PR-A) — single-source identity for the SaaS-readiness
 * scaffold.
 *
 * Returns the active club's numeric id. Today the plugin is single-tenant
 * so this is always `1`; the value is filterable via `tt_current_club_id`
 * so a future SaaS auth backend can resolve the club from a session,
 * subdomain, JWT claim, or whatever the eventual mechanism turns out to
 * be — without rewriting every query.
 *
 * The repository sweep pattern is:
 *
 *     SELECT ... FROM tt_xxx WHERE club_id = %d
 *
 * The shared helper `QueryHelpers::clubScopeWhere()` produces the
 * prepared fragment so call sites stay terse. Inserts pull the value
 * via `QueryHelpers::clubScopeInsertColumn()`.
 */
final class CurrentClub {

    /**
     * Resolve the active club id. Returns `1` until SaaS migration adds
     * a real resolver. Hooks into `tt_current_club_id` so a forthcoming
     * auth layer can substitute without touching this class.
     */
    public static function id(): int {
        $id = (int) apply_filters( 'tt_current_club_id', 1 );
        return $id > 0 ? $id : 1;
    }
}
