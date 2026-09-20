<?php
/**
 * Migration: 0280_drop_scout_trial_synthesis
 *
 * #3566 — the scout persona carried `trial_synthesis [read, player]` in
 * the seed. It never took effect: `MatrixGate` had no scout branch on the
 * `player` scope, so every player-scoped scout row resolved to false.
 *
 * #3566 adds that branch. The moment it lands, a dormant row becomes a
 * live grant — and this one would open the trial Execution tab, showing a
 * scout the other panellists' inputs before release. That contradicts the
 * decision settled on the issue: a scout sees their own input before
 * release and the panel's only after it, which
 * `TrialStaffInputsRepository::listVisibleForUser()` already enforces.
 *
 * So the row goes, here and in the seed, BEFORE the branch can wake it.
 *
 * ## Why this deletes rather than tops up
 *
 * The sibling migrations (0276, 0277, 0278) add rows the seed gained.
 * This one removes a row the seed lost, which is rarer and needs the
 * narrower guard: only the default row is touched. `is_default = 1`
 * marks a row this plugin seeded; an operator who deliberately granted
 * a scout the synthesis through the matrix admin owns that decision and
 * keeps it. We undo our own mistake, not theirs.
 *
 * Nobody loses access that worked, because the row never worked.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0280_drop_scout_trial_synthesis';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_authorization_matrix';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            // #3854 — a top-up that cannot report failure is how a grant
            // silently disappears. Say why nothing was written.
            error_log( '[TT 0280] tt_authorization_matrix missing; scout trial_synthesis row not removed.' );
            return;
        }

        $deleted = $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$table}
              WHERE persona = %s
                AND entity  = %s
                AND activity = %s
                AND scope_kind = %s
                AND is_default = 1",
            'scout', 'trial_synthesis', 'read', 'player'
        ) );

        error_log( sprintf(
            '[TT 0280] scout trial_synthesis default row(s) removed: %d',
            is_numeric( $deleted ) ? (int) $deleted : 0
        ) );

        if ( class_exists( '\\TT\\Modules\\Authorization\\Matrix\\MatrixRepository' ) ) {
            \TT\Modules\Authorization\Matrix\MatrixRepository::clearCache();
        }
    }
};
