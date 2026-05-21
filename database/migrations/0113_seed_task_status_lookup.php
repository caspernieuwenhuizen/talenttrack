<?php
/**
 * Migration 0113 — seed `task_status` as a `tt_lookups` lookup_type
 * so the six workflow task statuses (open / in_progress / completed /
 * overdue / skipped / cancelled) become operator-editable +
 * translatable through the frontend Lookups admin (#803 audit; #839).
 *
 * Most-seen vocabulary across the dashboard — task lists, inbox,
 * detail panels. Pilot has asked specifically about Dutch translations
 * for these.
 *
 * Stored values stay sacred (contract with `tt_workflow_tasks.status`,
 * defined by `TaskStatus::*` constants). The lookup row `name` matches
 * the lowercase stored value so `LookupTranslator::byTypeAndName(
 * 'task_status', $value)` resolves directly.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0113_seed_task_status_lookup';
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
            [ 'name' => 'open',        'sort_order' => 10, 'labels' => [ 'en_US' => 'Open',        'nl_NL' => 'Open',           'fr_FR' => 'Ouverte',     'de_DE' => 'Offen',        'es_ES' => 'Abierta'     ] ],
            [ 'name' => 'in_progress', 'sort_order' => 20, 'labels' => [ 'en_US' => 'In progress', 'nl_NL' => 'In behandeling', 'fr_FR' => 'En cours',    'de_DE' => 'In Bearbeitung', 'es_ES' => 'En curso'    ] ],
            [ 'name' => 'completed',   'sort_order' => 30, 'labels' => [ 'en_US' => 'Completed',   'nl_NL' => 'Voltooid',       'fr_FR' => 'Terminée',    'de_DE' => 'Abgeschlossen', 'es_ES' => 'Completada'  ] ],
            [ 'name' => 'overdue',     'sort_order' => 40, 'labels' => [ 'en_US' => 'Overdue',     'nl_NL' => 'Te laat',        'fr_FR' => 'En retard',   'de_DE' => 'Überfällig',    'es_ES' => 'Atrasada'    ] ],
            [ 'name' => 'skipped',     'sort_order' => 50, 'labels' => [ 'en_US' => 'Skipped',     'nl_NL' => 'Overgeslagen',   'fr_FR' => 'Ignorée',     'de_DE' => 'Übersprungen',  'es_ES' => 'Omitida'     ] ],
            [ 'name' => 'cancelled',   'sort_order' => 60, 'labels' => [ 'en_US' => 'Cancelled',   'nl_NL' => 'Geannuleerd',    'fr_FR' => 'Annulée',     'de_DE' => 'Abgebrochen',   'es_ES' => 'Cancelada'   ] ],
        ];

        foreach ( $seeds as $seed ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'task_status' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'task_status',
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
