<?php
/**
 * Migration: 0182_authorization_seed_topup_report_generation
 *
 * #1946 — backfill the report-generation grants into
 * `tt_authorization_matrix`. The `tt_generate_report` act-cap (distinct
 * from `tt_generate_scout_report`) gates report generation and is held
 * today by tt_head_dev + tt_coach (BOTH coach personas) + tt_club_admin
 * (+ administrator [bypass]). The #1946 LegacyCapMapper bridge routes it
 * to `reports:create_delete`, but the matrix seed previously gave those
 * personas only `reports:read` — so once the matrix is active the bridge
 * would resolve to false for coaches + HoD, silently REVOKING report
 * generation. This migration adds the missing `create_delete` grant to
 * preserve every raw holder.
 *
 * Grants added (mirroring the seed change in config/authorization_seed.php):
 *   - head_coach        reports:create_delete  [team]
 *   - assistant_coach   reports:create_delete  [team]   (tt_coach dual-persona)
 *   - head_of_development reports:create_delete [global]
 *
 * academy_admin already holds `reports:rcd [global]` — not re-added.
 * team_manager / scout / player / parent hold only `reports:read` and gain
 * nothing. Per-player team-scope gating already lives in
 * FrontendReportWizardView, so [team] scope for the coaches is correct.
 *
 * Scoped to the report-generation rows only (the three persona+entity+
 * activity+scope tuples below) so it never re-adds rows an operator
 * deliberately removed for other entities.
 *
 * Idempotent / re-runnable. INSERT IGNORE on the unique key
 * (persona, entity, activity, scope_kind) leaves any operator-edited
 * rows untouched and only adds the missing tuples.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0182_authorization_seed_topup_report_generation';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $table = "{$p}tt_authorization_matrix";
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        $seed_path = TT_PLUGIN_DIR . 'config/authorization_seed.php';
        if ( ! is_readable( $seed_path ) ) return;

        $rows = require $seed_path;
        if ( ! is_array( $rows ) ) return;

        $sql = "INSERT IGNORE INTO {$table}
                  (persona, entity, activity, scope_kind, module_class, is_default)
                VALUES (%s, %s, %s, %s, %s, 1)";

        // The exact report-generation grants this migration backfills.
        // Each tuple is (persona, scope_kind); entity = 'reports',
        // activity = 'create_delete' for all of them.
        $targets = [
            [ 'head_coach',          'team'   ],
            [ 'assistant_coach',     'team'   ],
            [ 'head_of_development', 'global' ],
        ];

        foreach ( $rows as $row ) {
            if ( ( $row['entity'] ?? '' ) !== 'reports' ) {
                continue;
            }
            if ( ( $row['activity'] ?? '' ) !== 'create_delete' ) {
                continue;
            }
            foreach ( $targets as [ $persona, $scope_kind ] ) {
                if ( ( $row['persona'] ?? '' ) === $persona
                    && ( $row['scope_kind'] ?? '' ) === $scope_kind ) {
                    $wpdb->query( $wpdb->prepare(
                        $sql,
                        (string) $row['persona'],
                        (string) $row['entity'],
                        (string) $row['activity'],
                        (string) $row['scope_kind'],
                        (string) $row['module_class']
                    ) );
                    break;
                }
            }
        }
    }
};
