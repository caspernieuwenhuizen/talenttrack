<?php
/**
 * Migration 0114 — seed `audience_type` as a `tt_lookups` lookup_type
 * so the eight report audiences (standard / parent_monthly / …) become
 * operator-editable + translatable through the frontend Lookups admin
 * (#803 audit; #844).
 *
 * Stored values stay sacred (contract with `tt_reports.audience_type`,
 * defined by `AudienceType::*` constants). The lookup row `name`
 * matches the stored value so `LookupTranslator::byTypeAndName(
 * 'audience_type', $value)` resolves directly. Description column is
 * also seeded with the existing canonical English `describe()` text
 * + per-locale translations so the lookup-admin description field
 * round-trips through `LookupTranslator::descriptionByTypeAndName()`.
 *
 * Idempotent — `INSERT IGNORE` on the unique indexes.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0114_seed_audience_type_lookup';
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
                'name'       => 'standard',
                'sort_order' => 10,
                'description'=> 'The familiar A4 report — rate card, headline numbers, breakdown, charts. Same as before.',
                'labels'     => [ 'en_US' => 'Standard', 'nl_NL' => 'Standaard', 'fr_FR' => 'Standard', 'de_DE' => 'Standard', 'es_ES' => 'Estándar' ],
                'descriptions' => [
                    'nl_NL' => 'Het vertrouwde A4-rapport — rate card, kerncijfers, breakdown, grafieken. Onveranderd.',
                    'fr_FR' => 'Le rapport A4 habituel — fiche de notation, chiffres clés, décomposition, graphiques. Inchangé.',
                    'de_DE' => 'Der vertraute A4-Bericht — Rate-Card, Kennzahlen, Aufschlüsselung, Diagramme. Unverändert.',
                    'es_ES' => 'El informe A4 habitual — rate card, cifras clave, desglose, gráficos. Sin cambios.',
                ],
            ],
            [
                'name'       => 'parent_monthly',
                'sort_order' => 20,
                'description'=> 'Warm, plain-language summary of the past month. Strengths and one or two focus areas. No coach free-text by default.',
                'labels'     => [ 'en_US' => 'Parent (monthly summary)', 'nl_NL' => 'Ouder (maandsamenvatting)', 'fr_FR' => 'Parent (résumé mensuel)', 'de_DE' => 'Eltern (Monatsbericht)', 'es_ES' => 'Padre/madre (resumen mensual)' ],
                'descriptions' => [
                    'nl_NL' => 'Warme, eenvoudige samenvatting van de afgelopen maand. Sterke punten en één of twee aandachtsgebieden. Geen vrije tekst van de coach standaard.',
                    'fr_FR' => 'Résumé chaleureux et accessible du mois écoulé. Points forts et un ou deux axes d\'amélioration. Pas de commentaire libre du coach par défaut.',
                    'de_DE' => 'Warmer, klar verständlicher Rückblick auf den vergangenen Monat. Stärken und ein bis zwei Schwerpunkte. Standardmäßig ohne Freitext-Kommentar des Trainers.',
                    'es_ES' => 'Resumen cálido y sencillo del último mes. Puntos fuertes y una o dos áreas de mejora. Sin texto libre del entrenador por defecto.',
                ],
            ],
            [
                'name'       => 'internal_detailed',
                'sort_order' => 30,
                'description'=> 'Formal, data-rich report for coaches. Specific numbers, trends, all categories, all sections. Coach notes included.',
                'labels'     => [ 'en_US' => 'Internal coaches (detailed)', 'nl_NL' => 'Interne coaches (gedetailleerd)', 'fr_FR' => 'Coachs internes (détaillé)', 'de_DE' => 'Interne Trainer (detailliert)', 'es_ES' => 'Entrenadores internos (detallado)' ],
                'descriptions' => [
                    'nl_NL' => 'Formeel, datarijk rapport voor coaches. Concrete cijfers, trends, alle categorieën, alle secties. Inclusief coach-aantekeningen.',
                    'fr_FR' => 'Rapport formel et riche en données pour les coachs. Chiffres précis, tendances, toutes les catégories, toutes les sections. Notes du coach incluses.',
                    'de_DE' => 'Formeller, datenreicher Bericht für Trainer. Konkrete Zahlen, Trends, alle Kategorien, alle Abschnitte. Inklusive Trainernotizen.',
                    'es_ES' => 'Informe formal y rico en datos para entrenadores. Cifras concretas, tendencias, todas las categorías, todas las secciones. Incluye notas del entrenador.',
                ],
            ],
            [
                'name'       => 'player_personal',
                'sort_order' => 40,
                'description'=> "A friendly, visual keepsake for the player. Top attributes and progress, no weak-spot callouts.",
                'labels'     => [ 'en_US' => 'Player (personal keepsake)', 'nl_NL' => 'Speler (persoonlijke herinnering)', 'fr_FR' => 'Joueur (souvenir personnel)', 'de_DE' => 'Spieler (persönliche Erinnerung)', 'es_ES' => 'Jugador (recuerdo personal)' ],
                'descriptions' => [
                    'nl_NL' => 'Vriendelijke, visuele herinnering voor de speler. Sterke punten en vooruitgang; geen aandacht voor zwakke punten.',
                    'fr_FR' => 'Souvenir visuel et chaleureux pour le joueur. Points forts et progrès; pas de mise en avant des points faibles.',
                    'de_DE' => 'Freundliche, visuelle Erinnerung für den Spieler. Stärken und Fortschritte; keine Hervorhebung von Schwachstellen.',
                    'es_ES' => 'Recuerdo visual y amable para el jugador. Puntos fuertes y avances; sin destacar puntos débiles.',
                ],
            ],
            [
                'name'       => 'scout',
                'sort_order' => 50,
                'description'=> 'A privacy-aware report for an external scout. Photo and ratings included; contact details, full date of birth, and coach notes off by default.',
                'labels'     => [ 'en_US' => 'Scout', 'nl_NL' => 'Scout', 'fr_FR' => 'Recruteur', 'de_DE' => 'Scout', 'es_ES' => 'Ojeador' ],
                'descriptions' => [
                    'nl_NL' => 'Privacybewust rapport voor een externe scout. Foto en beoordelingen erin; contactgegevens, volledige geboortedatum en coach-aantekeningen standaard uit.',
                    'fr_FR' => 'Rapport respectueux de la vie privée pour un recruteur externe. Photo et évaluations incluses; coordonnées, date de naissance complète et notes du coach désactivées par défaut.',
                    'de_DE' => 'Datenschutzbewusster Bericht für einen externen Scout. Foto und Bewertungen enthalten; Kontaktdaten, vollständiges Geburtsdatum und Trainernotizen standardmäßig deaktiviert.',
                    'es_ES' => 'Informe respetuoso con la privacidad para un ojeador externo. Incluye foto y valoraciones; los datos de contacto, fecha completa de nacimiento y notas del entrenador están desactivados por defecto.',
                ],
            ],
            [
                'name'       => 'trial_admittance',
                'sort_order' => 60,
                'description'=> 'Warm welcome letter offering a place after a successful trial. Optional acceptance slip on page 2.',
                'labels'     => [ 'en_US' => 'Trial admittance letter', 'nl_NL' => 'Toelatingsbrief proefperiode', 'fr_FR' => 'Lettre d\'admission après essai', 'de_DE' => 'Aufnahmeschreiben nach Probetraining', 'es_ES' => 'Carta de admisión tras prueba' ],
                'descriptions' => [
                    'nl_NL' => 'Warme welkomstbrief met aanbod na een geslaagde proefperiode. Optioneel akkoordstrookje op pagina 2.',
                    'fr_FR' => 'Lettre d\'accueil chaleureuse offrant une place après un essai réussi. Bon d\'acceptation en option sur la page 2.',
                    'de_DE' => 'Herzliches Willkommensschreiben mit Platzangebot nach erfolgreichem Probetraining. Optionaler Zustimmungsabschnitt auf Seite 2.',
                    'es_ES' => 'Carta cálida de bienvenida que ofrece una plaza tras una prueba exitosa. Hoja de aceptación opcional en la página 2.',
                ],
            ],
            [
                'name'       => 'trial_denial_final',
                'sort_order' => 70,
                'description'=> 'Respectful, definitive letter declining a place after the trial.',
                'labels'     => [ 'en_US' => 'Trial denial letter (final)', 'nl_NL' => 'Afwijsbrief proefperiode (definitief)', 'fr_FR' => 'Lettre de refus après essai (définitive)', 'de_DE' => 'Absageschreiben nach Probetraining (endgültig)', 'es_ES' => 'Carta de rechazo tras prueba (definitiva)' ],
                'descriptions' => [
                    'nl_NL' => 'Respectvolle, definitieve brief waarin een plaats na de proefperiode wordt afgewezen.',
                    'fr_FR' => 'Lettre respectueuse et définitive refusant une place après l\'essai.',
                    'de_DE' => 'Respektvolles, endgültiges Schreiben, das einen Platz nach dem Probetraining ablehnt.',
                    'es_ES' => 'Carta respetuosa y definitiva que rechaza una plaza tras la prueba.',
                ],
            ],
            [
                'name'       => 'trial_denial_encouragement',
                'sort_order' => 80,
                'description'=> 'Respectful denial letter that names strengths and growth areas, and invites a re-application next season.',
                'labels'     => [ 'en_US' => 'Trial denial letter (with encouragement)', 'nl_NL' => 'Afwijsbrief proefperiode (met aanmoediging)', 'fr_FR' => 'Lettre de refus après essai (avec encouragement)', 'de_DE' => 'Absageschreiben nach Probetraining (mit Ermutigung)', 'es_ES' => 'Carta de rechazo tras prueba (con aliento)' ],
                'descriptions' => [
                    'nl_NL' => 'Respectvolle afwijsbrief die sterke punten en groeigebieden benoemt en uitnodigt voor een herkansing volgend seizoen.',
                    'fr_FR' => 'Lettre de refus respectueuse qui nomme les points forts et les axes de progression, et invite à postuler à nouveau la saison prochaine.',
                    'de_DE' => 'Respektvolles Absageschreiben, das Stärken und Entwicklungsfelder benennt und zu einer erneuten Bewerbung in der nächsten Saison einlädt.',
                    'es_ES' => 'Carta de rechazo respetuosa que nombra puntos fuertes y áreas de crecimiento, e invita a volver a presentarse la próxima temporada.',
                ],
            ],
        ];

        foreach ( $seeds as $seed ) {
            $existing_id = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$lookups_table}
                  WHERE club_id = %d AND lookup_type = 'audience_type' AND name = %s
                  LIMIT 1",
                $club_id, $seed['name']
            ) );

            if ( $existing_id <= 0 ) {
                $wpdb->insert( $lookups_table, [
                    'club_id'     => $club_id,
                    'lookup_type' => 'audience_type',
                    'name'        => (string) $seed['name'],
                    'description' => (string) $seed['description'],
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
            foreach ( $seed['descriptions'] as $locale => $value ) {
                $wpdb->query( $wpdb->prepare(
                    "INSERT IGNORE INTO {$translations_table}
                       (club_id, entity_type, entity_id, field, locale, value, updated_at)
                     VALUES (%d, 'lookup', %d, 'description', %s, %s, %s)",
                    $club_id, $lookup_id, $locale, $value, $now
                ) );
            }
        }
    }
};
