<?php
/**
 * Migration 0228 — escalation tracking on alert occurrences (#2635, epic #2629).
 *
 * One column: `tt_alert_occurrences.escalated_task_id`.
 *
 * Migration 0220 explicitly declined to scaffold this on spec, noting it
 * would land as an ALTER when escalation actually shipped. This is that
 * ALTER.
 *
 * It is what makes escalation happen **once**. Without it the sweep has no
 * memory of having escalated, so an occurrence past its threshold would
 * dispatch a fresh workflow task every hour until somebody fixed the
 * underlying thing — turning one ignored alert into a task queue nobody can
 * clear. A nullable task id is the whole guard: non-null means done.
 *
 * It also records WHICH task, so the alerts inbox can say "this became a
 * task" and link to it rather than leaving the user wondering where their
 * alert went.
 *
 * Column add on an existing table, so `MigrationHelpers::addColumnIfMissing`
 * and never `dbDelta` (CI gate; the #1331/0129 incident class).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Database\MigrationHelpers;

return new class extends Migration {

    public function getName(): string {
        return '0228_alert_escalation_tracking';
    }

    public function up(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'tt_alert_occurrences';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) {
            return;
        }

        MigrationHelpers::addColumnIfMissing(
            $table,
            'escalated_task_id',
            'BIGINT UNSIGNED DEFAULT NULL',
            'digested_at'
        );
    }
};
