<?php
/**
 * Migration 0111 — seed `goal_approval_decision` as a `tt_lookups`
 * lookup_type so the three approval-form decisions (approve / amend /
 * reject) become operator-editable + translatable through the
 * frontend Lookups admin (#803 audit; #841).
 *
 * Why not retire the `GoalApprovalForm::DECISION_*` PHP constants?
 * They're the contract between the form (`if ($d === self::DECISION_APPROVE)`)
 * and the stored value in `tt_workflow_tasks.response_json`. Keep them
 * as keys; add per-locale rendered labels through `tt_lookups` +
 * `tt_translations`.
 *
 * Idempotent — INSERT IGNORE on the unique indexes; operator-edited
 * rows are preserved on re-run.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0111_seed_goal_approval_decision_lookup';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $lookups_table      = $p . 'tt_lookups';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $lookups_table ) ) !== $lookups_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = 1;
        $now     = current_time( 'mysql', true );

        $seeds = [
            [
                'name'       => 'approve',
                'sort_order' => 10,
                'labels'     => [
                    'en_US' => 'Approve',
                    'nl_NL' => 'Goedkeuren',
                    'fr_FR' => 'Approuver',
                    'de_DE' => 'Genehmigen',
                    'es_ES' => 'Aprobar',
                ],
            ],
            [
                'name'       => 'amend',
                'sort_order' => 20,
                'labels'     => [
                    'en_US' => 'Approve with amendment',
                    'nl_NL' => 'Goedkeuren met aanpassing',
                    'fr_FR' => 'Approuver avec modification',
                    'de_DE' => 'Mit Änderung genehmigen',
                    'es_ES' => 'Aprobar con modificación',
                ],
            ],
            [
                'name'       => 'reject',
                'sort_order' => 30,
                'labels'     => [
                    'en_US' => 'Reject',
                    'nl_NL' => 'Afwijzen',
                    'fr_FR' => 'Rejeter',
                    'de_DE' => 'Ablehnen',
                    'es_ES' => 'Rechazar',
                ],
            ],
        ];

        foreach ( $seeds as $seed ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'goal_approval_decision' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'goal_approval_decision',
                    'name'        => (string) $seed['name'],
                    'sort_order'  => (int) $seed['sort_order'],
                ] );
                $lookup_id = (int) $wpdb->insert_id;
            } else {
                $lookup_id = $existing_id;
            }
            if ( $lookup_id <= 0 ) continue;

            foreach ( $seed['labels'] as $locale => $value ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, 'lookup', %d, 'name', %s, %s, %s)",
                    $club_id, $lookup_id, $locale, $value, $now
                ) );
            }
        }
    }
};
