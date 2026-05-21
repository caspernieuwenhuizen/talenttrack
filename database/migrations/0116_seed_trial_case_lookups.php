<?php
/**
 * Migration 0116 — seed two related `tt_lookups` lookup_types for the
 * trial-cases workflow:
 *
 *   `trial_case_status`   — 4 values (open, extended, decided, archived)
 *   `trial_case_decision` — 6 values (admit, deny_final, deny_encouragement,
 *                                     offered_team_position, declined_offered_position,
 *                                     continue_in_trial_group)
 *
 * Heavy operator surface — trial workflow varies a lot by academy
 * (#803 audit; #842).
 *
 * Stored values stay sacred (contracts with `tt_trial_cases.status`
 * and `tt_trial_cases.decision`, defined by `TrialCasesRepository::*`
 * constants). Lookup row `name` matches the stored value so
 * `LookupTranslator::byTypeAndName(<type>, $value)` resolves directly.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes. Pattern mirrors
 * `0098_tournament_lookups_seed.php` which seeds two related types in
 * a single migration.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0116_seed_trial_case_lookups';
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

        $statuses = [
            [ 'name' => 'open',     'sort_order' => 10, 'labels' => [ 'en_US' => 'Open',     'nl_NL' => 'Open',         'fr_FR' => 'Ouvert',     'de_DE' => 'Offen',     'es_ES' => 'Abierto'    ] ],
            [ 'name' => 'extended', 'sort_order' => 20, 'labels' => [ 'en_US' => 'Extended', 'nl_NL' => 'Verlengd',     'fr_FR' => 'Prolongé',   'de_DE' => 'Verlängert','es_ES' => 'Prolongado' ] ],
            [ 'name' => 'decided',  'sort_order' => 30, 'labels' => [ 'en_US' => 'Decided',  'nl_NL' => 'Besloten',     'fr_FR' => 'Décidé',     'de_DE' => 'Entschieden','es_ES' => 'Decidido'  ] ],
            [ 'name' => 'archived', 'sort_order' => 40, 'labels' => [ 'en_US' => 'Archived', 'nl_NL' => 'Gearchiveerd', 'fr_FR' => 'Archivé',    'de_DE' => 'Archiviert','es_ES' => 'Archivado'  ] ],
        ];

        $decisions = [
            [ 'name' => 'admit',                        'sort_order' => 10, 'labels' => [ 'en_US' => 'Admit (offer a place)',                       'nl_NL' => 'Toelaten (een plek bieden)',                        'fr_FR' => 'Admettre (offrir une place)',                          'de_DE' => 'Aufnehmen (Platz anbieten)',                              'es_ES' => 'Admitir (ofrecer una plaza)'                                ] ],
            [ 'name' => 'deny_final',                   'sort_order' => 20, 'labels' => [ 'en_US' => 'Decline (final)',                             'nl_NL' => 'Afwijzen (definitief)',                             'fr_FR' => 'Refuser (définitif)',                                  'de_DE' => 'Ablehnen (endgültig)',                                    'es_ES' => 'Rechazar (definitivo)'                                       ] ],
            [ 'name' => 'deny_encouragement',           'sort_order' => 30, 'labels' => [ 'en_US' => 'Decline (with encouragement to re-apply)',   'nl_NL' => 'Afwijzen (met aanmoediging om opnieuw te solliciteren)','fr_FR' => 'Refuser (avec encouragement à se re-présenter)',     'de_DE' => 'Ablehnen (mit Ermutigung zur erneuten Bewerbung)',        'es_ES' => 'Rechazar (con aliento para volver a presentarse)'           ] ],
            [ 'name' => 'offered_team_position',        'sort_order' => 40, 'labels' => [ 'en_US' => 'Offered team position',                       'nl_NL' => 'Teamplek aangeboden',                                'fr_FR' => "Place dans l'équipe proposée",                        'de_DE' => 'Teamplatz angeboten',                                     'es_ES' => 'Plaza en el equipo ofrecida'                                ] ],
            [ 'name' => 'declined_offered_position',    'sort_order' => 50, 'labels' => [ 'en_US' => 'Declined offered position',                   'nl_NL' => 'Aangeboden plek afgewezen',                          'fr_FR' => 'Place proposée déclinée',                              'de_DE' => 'Angebotenen Platz abgelehnt',                             'es_ES' => 'Plaza ofrecida rechazada'                                    ] ],
            [ 'name' => 'continue_in_trial_group',      'sort_order' => 60, 'labels' => [ 'en_US' => 'Continue in trial group',                     'nl_NL' => 'Doorgaan in proefgroep',                             'fr_FR' => "Continuer dans le groupe d'essai",                    'de_DE' => 'In Probegruppe weitermachen',                             'es_ES' => 'Continuar en el grupo de prueba'                            ] ],
        ];

        foreach ( [ 'trial_case_status' => $statuses, 'trial_case_decision' => $decisions ] as $type => $seeds ) {
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
