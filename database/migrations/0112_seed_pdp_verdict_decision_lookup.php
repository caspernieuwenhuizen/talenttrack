<?php
/**
 * Migration 0112 — seed `pdp_verdict_decision` as a `tt_lookups`
 * lookup_type so the four end-of-season verdict decisions (promote /
 * retain / release / transfer) become operator-editable + translatable
 * through the frontend Lookups admin (#803 audit; #843).
 *
 * Pilot has hinted at academy-specific terminology — e.g. *progressed*
 * / *signed* / *released* / *moved*. Stored keys stay sacred (contract
 * with `tt_pdp_verdicts.decision`); only labels move.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0112_seed_pdp_verdict_decision_lookup';
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
                'name'       => 'promote',
                'sort_order' => 10,
                'labels'     => [
                    'en_US' => 'Promote',
                    'nl_NL' => 'Bevorderen',
                    'fr_FR' => 'Promouvoir',
                    'de_DE' => 'Befördern',
                    'es_ES' => 'Promover',
                ],
            ],
            [
                'name'       => 'retain',
                'sort_order' => 20,
                'labels'     => [
                    'en_US' => 'Retain',
                    'nl_NL' => 'Behouden',
                    'fr_FR' => 'Maintenir',
                    'de_DE' => 'Behalten',
                    'es_ES' => 'Mantener',
                ],
            ],
            [
                'name'       => 'release',
                'sort_order' => 30,
                'labels'     => [
                    'en_US' => 'Release',
                    'nl_NL' => 'Vrijgeven',
                    'fr_FR' => 'Libérer',
                    'de_DE' => 'Freigeben',
                    'es_ES' => 'Liberar',
                ],
            ],
            [
                'name'       => 'transfer',
                'sort_order' => 40,
                'labels'     => [
                    'en_US' => 'Transfer',
                    'nl_NL' => 'Overdragen',
                    'fr_FR' => 'Transférer',
                    'de_DE' => 'Transferieren',
                    'es_ES' => 'Transferir',
                ],
            ],
        ];

        foreach ( $seeds as $seed ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'pdp_verdict_decision' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'pdp_verdict_decision',
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
