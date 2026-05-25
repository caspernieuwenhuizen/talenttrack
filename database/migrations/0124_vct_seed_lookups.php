<?php
/**
 * Migration 0124 — VCT lookup categories + direct tt_translations writes
 * (#908, VCT-3, epic #905).
 *
 * Five new `tt_lookups` categories with seed values, plus
 * `tt_translations` rows for all four non-canonical locales
 * (nl_NL / fr_FR / de_DE / es_ES). English canonical stays on
 * `tt_lookups.name`.
 *
 * Per the #902 architectural lesson (memory
 * `feedback_lookup_seed_translations`): translations are written
 * directly from a PHP label map in this migration — NOT via
 * `switch_to_locale` + `__()` + .po backfill. The .po backfill silently
 * writes nothing when the .po lacks the string; the direct-write
 * pattern guarantees every value lands in every locale on day one.
 *
 * Canonical English values are also wrapped in `__()` via the new
 * LabelTranslator vct* methods so the .pot extractor picks them up on
 * next regeneration (the extractor companion pattern from #902).
 *
 * Lookup categories seeded:
 *   - vct_exercise_category (6 values)
 *   - vct_tactical_theme    (10 values)
 *   - vct_md_context        (8 values)
 *   - vct_intensity_band    (10 values)
 *   - vct_session_status    (4 values)
 *
 * Pattern mirrors 0116_seed_trial_case_lookups.php — single migration,
 * multi-type, idempotent via existence check on (club_id, lookup_type,
 * name) before INSERT and INSERT IGNORE on tt_translations unique key.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0124_vct_seed_lookups';
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

        $categories = [
            [ 'name' => 'warmup',       'sort_order' => 10, 'labels' => [ 'en_US' => 'Warm-up',     'nl_NL' => 'Warming-up',    'fr_FR' => 'Échauffement',   'de_DE' => 'Aufwärmen',      'es_ES' => 'Calentamiento' ] ],
            [ 'name' => 'technical',    'sort_order' => 20, 'labels' => [ 'en_US' => 'Technical',   'nl_NL' => 'Techniek',      'fr_FR' => 'Technique',      'de_DE' => 'Technik',        'es_ES' => 'Técnica' ] ],
            [ 'name' => 'sided_game',   'sort_order' => 30, 'labels' => [ 'en_US' => 'Sided game',  'nl_NL' => 'Partijspel',    'fr_FR' => 'Jeu réduit',     'de_DE' => 'Spielform',      'es_ES' => 'Juego reducido' ] ],
            [ 'name' => 'conditioning', 'sort_order' => 40, 'labels' => [ 'en_US' => 'Conditioning','nl_NL' => 'Conditie',      'fr_FR' => 'Préparation physique','de_DE' => 'Kondition',  'es_ES' => 'Acondicionamiento' ] ],
            [ 'name' => 'finishing',    'sort_order' => 50, 'labels' => [ 'en_US' => 'Finishing',   'nl_NL' => 'Afronding',     'fr_FR' => 'Finition',       'de_DE' => 'Abschluss',      'es_ES' => 'Finalización' ] ],
            [ 'name' => 'cool_down',    'sort_order' => 60, 'labels' => [ 'en_US' => 'Cool-down',   'nl_NL' => 'Cooling-down',  'fr_FR' => 'Récupération',   'de_DE' => 'Abwärmen',       'es_ES' => 'Vuelta a la calma' ] ],
        ];

        $themes = [
            [ 'name' => 'build_up',     'sort_order' => 10, 'labels' => [ 'en_US' => 'Build-up',        'nl_NL' => 'Opbouw',            'fr_FR' => 'Construction',          'de_DE' => 'Spielaufbau',        'es_ES' => 'Construcción' ] ],
            [ 'name' => 'pressing',     'sort_order' => 20, 'labels' => [ 'en_US' => 'Pressing',        'nl_NL' => 'Drukzetten',        'fr_FR' => 'Pressing',              'de_DE' => 'Pressing',           'es_ES' => 'Presión' ] ],
            [ 'name' => 'transition',   'sort_order' => 30, 'labels' => [ 'en_US' => 'Transition',      'nl_NL' => 'Omschakeling',      'fr_FR' => 'Transition',            'de_DE' => 'Umschalten',         'es_ES' => 'Transición' ] ],
            [ 'name' => 'counter',      'sort_order' => 40, 'labels' => [ 'en_US' => 'Counter-attack',  'nl_NL' => 'Counter',           'fr_FR' => 'Contre-attaque',        'de_DE' => 'Konter',             'es_ES' => 'Contraataque' ] ],
            [ 'name' => 'defending',    'sort_order' => 50, 'labels' => [ 'en_US' => 'Defending',       'nl_NL' => 'Verdedigen',        'fr_FR' => 'Défense',               'de_DE' => 'Verteidigen',        'es_ES' => 'Defensa' ] ],
            [ 'name' => 'finishing',    'sort_order' => 60, 'labels' => [ 'en_US' => 'Finishing',       'nl_NL' => 'Afronding',         'fr_FR' => 'Finition',              'de_DE' => 'Abschluss',          'es_ES' => 'Finalización' ] ],
            [ 'name' => 'set_pieces',   'sort_order' => 70, 'labels' => [ 'en_US' => 'Set pieces',      'nl_NL' => 'Standaardsituaties','fr_FR' => 'Coups de pied arrêtés', 'de_DE' => 'Standardsituationen','es_ES' => 'Balones parados' ] ],
            [ 'name' => '1v1_duels',    'sort_order' => 80, 'labels' => [ 'en_US' => '1v1 duels',       'nl_NL' => '1-tegen-1 duels',   'fr_FR' => 'Duels 1 contre 1',      'de_DE' => '1-gegen-1 Duelle',   'es_ES' => 'Duelos 1 contra 1' ] ],
            [ 'name' => 'possession',   'sort_order' => 90, 'labels' => [ 'en_US' => 'Possession',      'nl_NL' => 'Balbezit',          'fr_FR' => 'Possession',            'de_DE' => 'Ballbesitz',         'es_ES' => 'Posesión' ] ],
            [ 'name' => 'mixed',        'sort_order' => 100,'labels' => [ 'en_US' => 'Mixed',           'nl_NL' => 'Gemengd',           'fr_FR' => 'Mixte',                 'de_DE' => 'Gemischt',           'es_ES' => 'Mixto' ] ],
        ];

        // MD context — "MD" = match day; MD-N is N days before, MD+N is N days after.
        // Stored values match the spec verbatim; same string in every locale because
        // football coaching vocabulary uses the same abbreviation universally.
        $md_contexts = [
            [ 'name' => 'MD-4',  'sort_order' => 10, 'labels' => [ 'en_US' => 'MD-4',       'nl_NL' => 'MD-4',          'fr_FR' => 'MD-4',           'de_DE' => 'MD-4',           'es_ES' => 'MD-4' ] ],
            [ 'name' => 'MD-3',  'sort_order' => 20, 'labels' => [ 'en_US' => 'MD-3',       'nl_NL' => 'MD-3',          'fr_FR' => 'MD-3',           'de_DE' => 'MD-3',           'es_ES' => 'MD-3' ] ],
            [ 'name' => 'MD-2',  'sort_order' => 30, 'labels' => [ 'en_US' => 'MD-2',       'nl_NL' => 'MD-2',          'fr_FR' => 'MD-2',           'de_DE' => 'MD-2',           'es_ES' => 'MD-2' ] ],
            [ 'name' => 'MD-1',  'sort_order' => 40, 'labels' => [ 'en_US' => 'MD-1',       'nl_NL' => 'MD-1',          'fr_FR' => 'MD-1',           'de_DE' => 'MD-1',           'es_ES' => 'MD-1' ] ],
            [ 'name' => 'MD',    'sort_order' => 50, 'labels' => [ 'en_US' => 'Match day',  'nl_NL' => 'Wedstrijddag',  'fr_FR' => 'Jour de match',  'de_DE' => 'Spieltag',       'es_ES' => 'Día de partido' ] ],
            [ 'name' => 'MD+1',  'sort_order' => 60, 'labels' => [ 'en_US' => 'MD+1',       'nl_NL' => 'MD+1',          'fr_FR' => 'MD+1',           'de_DE' => 'MD+1',           'es_ES' => 'MD+1' ] ],
            [ 'name' => 'MD+2',  'sort_order' => 70, 'labels' => [ 'en_US' => 'MD+2',       'nl_NL' => 'MD+2',          'fr_FR' => 'MD+2',           'de_DE' => 'MD+2',           'es_ES' => 'MD+2' ] ],
            [ 'name' => 'NONE',  'sort_order' => 80, 'labels' => [ 'en_US' => 'No match context','nl_NL' => 'Geen wedstrijdcontext','fr_FR' => 'Aucun contexte de match','de_DE' => 'Kein Spielkontext','es_ES' => 'Sin contexto de partido' ] ],
        ];

        // Intensity bands 1–10. Localised English label uses ordinal phrasing;
        // numeric label is identical across locales. Spec says "check during
        // implementation" — going with band_N keys for human readability and
        // alignment with the TINYINT intensity_band column on tt_vct_exercises
        // (where 1..10 is the stored numeric value).
        $intensity_bands = [];
        $band_labels = [
            'en_US' => 'Intensity band %d',
            'nl_NL' => 'Intensiteitsniveau %d',
            'fr_FR' => "Niveau d'intensité %d",
            'de_DE' => 'Intensitätsstufe %d',
            'es_ES' => 'Nivel de intensidad %d',
        ];
        for ( $i = 1; $i <= 10; $i++ ) {
            $labels = [];
            foreach ( $band_labels as $loc => $tpl ) {
                $labels[ $loc ] = sprintf( $tpl, $i );
            }
            $intensity_bands[] = [ 'name' => 'band_' . $i, 'sort_order' => $i * 10, 'labels' => $labels ];
        }

        // VCT-session lifecycle states. Status names match the ENUM on
        // tt_vct_sessions.status (defined in migration 0122).
        $session_statuses = [
            [ 'name' => 'draft',     'sort_order' => 10, 'labels' => [ 'en_US' => 'Draft',     'nl_NL' => 'Concept',     'fr_FR' => 'Brouillon',  'de_DE' => 'Entwurf',     'es_ES' => 'Borrador' ] ],
            [ 'name' => 'published', 'sort_order' => 20, 'labels' => [ 'en_US' => 'Published', 'nl_NL' => 'Gepubliceerd','fr_FR' => 'Publié',     'de_DE' => 'Veröffentlicht','es_ES' => 'Publicado' ] ],
            [ 'name' => 'completed', 'sort_order' => 30, 'labels' => [ 'en_US' => 'Completed', 'nl_NL' => 'Voltooid',    'fr_FR' => 'Terminé',    'de_DE' => 'Abgeschlossen','es_ES' => 'Completado' ] ],
            [ 'name' => 'archived',  'sort_order' => 40, 'labels' => [ 'en_US' => 'Archived',  'nl_NL' => 'Gearchiveerd','fr_FR' => 'Archivé',    'de_DE' => 'Archiviert',  'es_ES' => 'Archivado' ] ],
        ];

        $all = [
            'vct_exercise_category' => $categories,
            'vct_tactical_theme'    => $themes,
            'vct_md_context'        => $md_contexts,
            'vct_intensity_band'    => $intensity_bands,
            'vct_session_status'    => $session_statuses,
        ];

        foreach ( $all as $type => $seeds ) {
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
