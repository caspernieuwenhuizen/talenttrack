<?php
/**
 * Migration 0108 — re-apply migration 0104's intent with the right
 * `tt_auth_matrix.activity` enum values (#824).
 *
 * Migration 0104 (v3.110.154) was meant to upgrade `scout × prospects`
 * rows from `self` scope to `global` scope on upgraded installs whose
 * matrix was seeded before the policy change in #0081. It carried a
 * silent logic bug: the UPDATE filtered `activity IN ('r', 'c', 'd')`
 * but the column stores the **expanded enums** `'read'` / `'change'` /
 * `'create_delete'` (set by the `$expand()` helper in
 * `config/authorization_seed.php`). The UPDATE matched zero rows on
 * every install, marked itself applied, and silently no-oped.
 *
 * Knock-on effect: `tt_scout` users on upgraded installs hit "no
 * access" on `?tt_view=scouting-visits` (and any other
 * prospect-gated surface) because `LegacyCapMapper::evaluate(
 * 'tt_view_prospects' )` bridges to `(prospects, read)` which on those
 * installs is still scoped to `self`. The role's baseline caps
 * intentionally do NOT carry `tt_view_prospects` (#0081, matrix-only
 * by design so the scope can mutate per install).
 *
 * Why a fresh migration number rather than editing 0104: editing 0104
 * won't re-run on installs that already have 0104 marked applied in
 * `tt_migrations`. Adding a new entry is the only path.
 *
 * Why "by design — don't bake into the baseline": the matrix-only
 * routing is intentional. A future install could legitimately want
 * scouts back at `self` scope (single-academy scouting boundary, GDPR
 * regional split, etc.). Baking `tt_view_prospects` into the role
 * baseline would freeze that decision; this migration preserves it
 * by repairing the matrix data instead.
 *
 * Idempotent: matches zero rows on already-correct installs. Preserves
 * operator-edited rows via the `is_default = 1` guard.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0108_fix_scout_prospects_scope_global';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) return;

        $wpdb->query( $wpdb->prepare(
            "UPDATE {$table}
                SET scope_kind = %s
              WHERE persona      = %s
                AND entity       = %s
                AND activity     IN (%s, %s, %s)
                AND scope_kind   = %s
                AND is_default   = 1",
            'global',
            'scout',
            'prospects',
            'read',
            'change',
            'create_delete',
            'self'
        ) );
    }
};
