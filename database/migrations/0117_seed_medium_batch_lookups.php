<?php
/**
 * Migration 0117 — final batch from the #803 audit. Seeds five
 * lookup_types in a single transaction (pattern mirrors
 * `0098_tournament_lookups_seed.php` and `0116_seed_trial_case_lookups.php`):
 *
 *   `invitation_kind`            — 3 values (player, parent, staff)
 *   `idea_type`                  — 4 values (feat, bug, epic, needs-triage)
 *   `scouting_visit_status`      — 3 values (planned, completed, cancelled)
 *   `scheduled_report_frequency` — 3 values (weekly_monday, monthly_first, season_end)
 *   `scheduled_report_status`    — 3 values (active, paused, archived)
 *
 * Each is too small to justify its own ship (#803, #845).
 *
 * Stored values stay sacred (PHP constants on the respective domain
 * classes). The lookup row `name` matches the stored value so
 * `LookupTranslator::byTypeAndName(<type>, $value)` resolves directly.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0117_seed_medium_batch_lookups';
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

        $types = [
            'invitation_kind' => [
                [ 'name' => 'player', 'sort_order' => 10, 'labels' => [ 'en_US' => 'Player', 'nl_NL' => 'Speler', 'fr_FR' => 'Joueur', 'de_DE' => 'Spieler', 'es_ES' => 'Jugador'  ] ],
                [ 'name' => 'parent', 'sort_order' => 20, 'labels' => [ 'en_US' => 'Parent', 'nl_NL' => 'Ouder',  'fr_FR' => 'Parent', 'de_DE' => 'Elternteil','es_ES' => 'Padre/madre' ] ],
                [ 'name' => 'staff',  'sort_order' => 30, 'labels' => [ 'en_US' => 'Staff',  'nl_NL' => 'Staf',   'fr_FR' => 'Staff',  'de_DE' => 'Mitarbeitende','es_ES' => 'Personal' ] ],
            ],
            'idea_type' => [
                [ 'name' => 'feat',         'sort_order' => 10, 'labels' => [ 'en_US' => 'Feature',      'nl_NL' => 'Functie',         'fr_FR' => 'Fonctionnalité', 'de_DE' => 'Funktion',     'es_ES' => 'Función'         ] ],
                [ 'name' => 'bug',          'sort_order' => 20, 'labels' => [ 'en_US' => 'Bug',          'nl_NL' => 'Bug',             'fr_FR' => 'Bogue',          'de_DE' => 'Fehler',       'es_ES' => 'Error'           ] ],
                [ 'name' => 'epic',         'sort_order' => 30, 'labels' => [ 'en_US' => 'Epic',         'nl_NL' => 'Epic',            'fr_FR' => 'Epic',           'de_DE' => 'Epic',         'es_ES' => 'Epopeya'         ] ],
                [ 'name' => 'needs-triage', 'sort_order' => 40, 'labels' => [ 'en_US' => 'Needs triage', 'nl_NL' => 'Triage vereist',  'fr_FR' => 'À trier',        'de_DE' => 'Triage nötig', 'es_ES' => 'Requiere triaje' ] ],
            ],
            'scouting_visit_status' => [
                [ 'name' => 'planned',   'sort_order' => 10, 'labels' => [ 'en_US' => 'Planned',   'nl_NL' => 'Gepland',     'fr_FR' => 'Planifiée', 'de_DE' => 'Geplant',     'es_ES' => 'Planificada' ] ],
                [ 'name' => 'completed', 'sort_order' => 20, 'labels' => [ 'en_US' => 'Completed', 'nl_NL' => 'Voltooid',    'fr_FR' => 'Terminée',  'de_DE' => 'Abgeschlossen','es_ES' => 'Completada'  ] ],
                [ 'name' => 'cancelled', 'sort_order' => 30, 'labels' => [ 'en_US' => 'Cancelled', 'nl_NL' => 'Geannuleerd', 'fr_FR' => 'Annulée',   'de_DE' => 'Abgebrochen', 'es_ES' => 'Cancelada'   ] ],
            ],
            'scheduled_report_frequency' => [
                [ 'name' => 'weekly_monday',  'sort_order' => 10, 'labels' => [ 'en_US' => 'Weekly (Monday)', 'nl_NL' => 'Wekelijks (maandag)', 'fr_FR' => 'Hebdomadaire (lundi)', 'de_DE' => 'Wöchentlich (Montag)', 'es_ES' => 'Semanal (lunes)' ] ],
                [ 'name' => 'monthly_first',  'sort_order' => 20, 'labels' => [ 'en_US' => 'Monthly (1st)',   'nl_NL' => 'Maandelijks (1e)',    'fr_FR' => 'Mensuel (1er)',        'de_DE' => 'Monatlich (1.)',       'es_ES' => 'Mensual (día 1)' ] ],
                [ 'name' => 'season_end',     'sort_order' => 30, 'labels' => [ 'en_US' => 'Season end',      'nl_NL' => 'Einde seizoen',       'fr_FR' => 'Fin de saison',        'de_DE' => 'Saisonende',           'es_ES' => 'Fin de temporada' ] ],
            ],
            'scheduled_report_status' => [
                [ 'name' => 'active',   'sort_order' => 10, 'labels' => [ 'en_US' => 'Active',   'nl_NL' => 'Actief',       'fr_FR' => 'Actif',     'de_DE' => 'Aktiv',       'es_ES' => 'Activo'      ] ],
                [ 'name' => 'paused',   'sort_order' => 20, 'labels' => [ 'en_US' => 'Paused',   'nl_NL' => 'Gepauzeerd',   'fr_FR' => 'En pause',  'de_DE' => 'Pausiert',    'es_ES' => 'En pausa'    ] ],
                [ 'name' => 'archived', 'sort_order' => 30, 'labels' => [ 'en_US' => 'Archived', 'nl_NL' => 'Gearchiveerd', 'fr_FR' => 'Archivé',   'de_DE' => 'Archiviert',  'es_ES' => 'Archivado'   ] ],
            ],
        ];

        foreach ( $types as $type => $seeds ) {
            foreach ( $seeds as $seed ) {
                $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                    "SELECT id FROM {$lookups_table}
                      WHERE club_id = %d AND lookup_type = %s AND name = %s
                      LIMIT 1",
                    $club_id, $type, $seed['name']
                ) );

                if ( $existing_id <= 0 ) {
                    $wpdb->insert( $lookups_table, [
                        'club_id'     => $club_id,
                        'lookup_type' => $type,
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
    }
};
