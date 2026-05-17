<?php
/**
 * Migration 0103 — re-apply the evaluation demo-tag backfill that
 * migration 0102 un-applied.
 *
 * Pilot symptom (after v3.110.148, which shipped migration 0102):
 *   "evaluations problem is still not solved."
 *
 * Diagnostic state on the pilot install:
 *   - `tt_demo_mode` option = 'on'.
 *   - 12 active evaluations, all club_id=1, none archived.
 *   - `tt_demo_tags WHERE entity_type='evaluation'` = 0 rows.
 *   - All three migrations 0096 + 0099 + 0102 have applied.
 *
 * Reconstruction: 0096 + 0099 tagged the 12 evals with the
 * `wizard-untagged-recovery-v3.110.130` / `eval-retag-v3.110.136`
 * batch_ids. 0102 then deleted exactly those rows. The 0102 ship
 * (v3.110.148) was trying to fix a *different* failure mode — real
 * evals on mixed-data installs getting hidden in demo-OFF mode. Its
 * own docblock explicitly accepts the residual risk:
 *
 *   "pre-v3.110.130 wizard-created evals (the population the backfill
 *    was trying to recover) become invisible again in demo-ON mode.
 *    Small window, small population; operator can re-rate or manually
 *    tag any surfacing."
 *
 * That trade-off is wrong for installs where every evaluation IS demo
 * data, which the pilot explicitly confirmed:
 *
 *   "there are no real evaluations that can be a problem. I am running
 *    in demo mode and everything created so far should rightfully be
 *    demo tagged."
 *
 * This migration re-runs the backfill with a fresh `getName()`. Same
 * idempotent `INSERT … WHERE NOT EXISTS` shape as 0099; same demo-ON
 * gate. Three outcomes:
 *
 *   - Install in pilot's state (demo-ON, untagged evals from the 0102
 *     un-tag): every untagged eval gets a `eval-retag-v3.110.154`
 *     tag, demo filter now passes them, list shows.
 *
 *   - Install where 0102 ran but going-forward `user-created` tags
 *     are still there for some evals: those evals are unaffected
 *     (NOT EXISTS clause filters them out of the SELECT); the rest
 *     get the new batch tag.
 *
 *   - Install currently in demo-OFF: gate returns early. Real
 *     production data stays untagged.
 *
 * The recurring loop (0096 → 0099 → 0102 → 0103) is fully aware: each
 * migration runs at most once on each install. Re-running 0103 on an
 * install where everything is already tagged is a no-op. The
 * fundamental design conflict (demo-only installs want everything
 * tagged; mixed-data installs want only true demo tagged) is
 * addressable only by a per-install operator flag — out of scope for
 * this fix. For now: pilot's intent is "tag everything", so this
 * migration honours it.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0103_backfill_evaluation_demo_tags_after_untag';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        if ( ! class_exists( '\\TT\\Modules\\DemoData\\DemoMode' ) ) {
            return;
        }
        if ( \TT\Modules\DemoData\DemoMode::current() !== \TT\Modules\DemoData\DemoMode::ON ) {
            return;
        }

        $evals_table = $p . 'tt_evaluations';
        $tags_table  = $p . 'tt_demo_tags';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $evals_table ) ) !== $evals_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $tags_table  ) ) !== $tags_table  ) return;

        $batch_id = 'eval-retag-v3.110.154';

        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared — table names + literal batch_id are safe.
        $wpdb->query( $wpdb->prepare(
            "INSERT INTO {$tags_table} (club_id, batch_id, entity_type, entity_id, extra_json)
             SELECT e.club_id, %s, 'evaluation', e.id, NULL
               FROM {$evals_table} e
              WHERE NOT EXISTS (
                  SELECT 1 FROM {$tags_table} t
                   WHERE t.entity_type = 'evaluation' AND t.entity_id = e.id
              )",
            $batch_id
        ) );
    }
};
