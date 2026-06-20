<?php
namespace TT\Modules\Configuration;

if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * LookupTranslationSeeds (#1442) — curated display-label translations for
 * the player/coach/parent-facing lookup vocabularies, keyed by
 * lookup_type => canonical English name => locale => label.
 *
 * en_US is intentionally absent: the canonical English value lives in
 * tt_lookups.name and is the resolver's fallback, so it needs no row.
 *
 * Seeded into tt_translations by migration 0151 (INSERT IGNORE, so it
 * fills gaps without overwriting operator edits or earlier backfills).
 * Codes that are identical across languages (age-group U-codes,
 * positions, UEFA cert grades) are intentionally omitted.
 *
 * Locale-invariant values are simply not listed. The four UI locales
 * beyond en_US are nl_NL, fr_FR, de_DE, es_ES (I18nModule::REGISTERED_LOCALES).
 */
final class LookupTranslationSeeds {

    /** @var list<string> */
    public const LOCALES = [ 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    public static function map(): array {
        return [
            'foot_option' => [
                'Left'  => [ 'nl_NL' => 'Links',  'fr_FR' => 'Gauche',   'de_DE' => 'Links',  'es_ES' => 'Izquierda' ],
                'Right' => [ 'nl_NL' => 'Rechts', 'fr_FR' => 'Droit',    'de_DE' => 'Rechts', 'es_ES' => 'Derecha' ],
                'Both'  => [ 'nl_NL' => 'Beide',  'fr_FR' => 'Les deux', 'de_DE' => 'Beide',  'es_ES' => 'Ambos' ],
            ],

            'age_group' => [
                // #1528 — Dutch uses the "O" (Onder) convention: U14 → O14.
                // fr/de/es use the UEFA U-notation natively, so the canonical
                // "U…" value is already correct for them and carries no row
                // (locale-invariant values are simply not listed).
                'U7'  => [ 'nl_NL' => 'O7' ],
                'U8'  => [ 'nl_NL' => 'O8' ],
                'U9'  => [ 'nl_NL' => 'O9' ],
                'U10' => [ 'nl_NL' => 'O10' ],
                'U11' => [ 'nl_NL' => 'O11' ],
                'U12' => [ 'nl_NL' => 'O12' ],
                'U13' => [ 'nl_NL' => 'O13' ],
                'U14' => [ 'nl_NL' => 'O14' ],
                'U15' => [ 'nl_NL' => 'O15' ],
                'U16' => [ 'nl_NL' => 'O16' ],
                'U17' => [ 'nl_NL' => 'O17' ],
                'U18' => [ 'nl_NL' => 'O18' ],
                'U19' => [ 'nl_NL' => 'O19' ],
                'U20' => [ 'nl_NL' => 'O20' ],
                'U21' => [ 'nl_NL' => 'O21' ],
                'U23' => [ 'nl_NL' => 'O23' ],
                'Senior' => [ 'nl_NL' => 'Senioren', 'fr_FR' => 'Seniors', 'de_DE' => 'Senioren', 'es_ES' => 'Senior' ],
            ],

            'eval_category' => [
                'Technical' => [ 'nl_NL' => 'Technisch', 'fr_FR' => 'Technique', 'de_DE' => 'Technisch', 'es_ES' => 'Técnico' ],
                'Tactical'  => [ 'nl_NL' => 'Tactisch',  'fr_FR' => 'Tactique',  'de_DE' => 'Taktisch',  'es_ES' => 'Táctico' ],
                'Physical'  => [ 'nl_NL' => 'Fysiek',    'fr_FR' => 'Physique',  'de_DE' => 'Physisch',  'es_ES' => 'Físico' ],
                'Mental'    => [ 'nl_NL' => 'Mentaal',   'fr_FR' => 'Mental',    'de_DE' => 'Mental',    'es_ES' => 'Mental' ],
            ],

            'eval_type' => [
                'Training' => [ 'nl_NL' => 'Training',       'fr_FR' => 'Entraînement', 'de_DE' => 'Training',             'es_ES' => 'Entrenamiento' ],
                'Match'    => [ 'nl_NL' => 'Wedstrijd',      'fr_FR' => 'Match',        'de_DE' => 'Spiel',                'es_ES' => 'Partido' ],
                'Friendly' => [ 'nl_NL' => 'Oefenwedstrijd', 'fr_FR' => 'Match amical', 'de_DE' => 'Freundschaftsspiel',   'es_ES' => 'Amistoso' ],
            ],

            'activity_type' => [
                'Training'   => [ 'nl_NL' => 'Training',       'fr_FR' => 'Entraînement', 'de_DE' => 'Training',           'es_ES' => 'Entrenamiento' ],
                'Match'      => [ 'nl_NL' => 'Wedstrijd',      'fr_FR' => 'Match',        'de_DE' => 'Spiel',              'es_ES' => 'Partido' ],
                'Friendly'   => [ 'nl_NL' => 'Oefenwedstrijd', 'fr_FR' => 'Match amical', 'de_DE' => 'Freundschaftsspiel', 'es_ES' => 'Amistoso' ],
                'Tournament' => [ 'nl_NL' => 'Toernooi',       'fr_FR' => 'Tournoi',      'de_DE' => 'Turnier',            'es_ES' => 'Torneo' ],
                'Trial'      => [ 'nl_NL' => 'Proeftraining',  'fr_FR' => 'Essai',        'de_DE' => 'Probetraining',      'es_ES' => 'Prueba' ],
                'Other'      => [ 'nl_NL' => 'Overig',         'fr_FR' => 'Autre',        'de_DE' => 'Sonstiges',          'es_ES' => 'Otro' ],
            ],

            'activity_status' => [
                'draft'       => [ 'nl_NL' => 'Concept',         'fr_FR' => 'Brouillon',  'de_DE' => 'Entwurf',          'es_ES' => 'Borrador' ],
                'planned'     => [ 'nl_NL' => 'Gepland',         'fr_FR' => 'Planifié',   'de_DE' => 'Geplant',          'es_ES' => 'Planificado' ],
                'scheduled'   => [ 'nl_NL' => 'Ingepland',       'fr_FR' => 'Programmé',  'de_DE' => 'Angesetzt',        'es_ES' => 'Programado' ],
                'in_progress' => [ 'nl_NL' => 'Bezig',           'fr_FR' => 'En cours',   'de_DE' => 'In Bearbeitung',   'es_ES' => 'En curso' ],
                'completed'   => [ 'nl_NL' => 'Voltooid',        'fr_FR' => 'Terminé',    'de_DE' => 'Abgeschlossen',    'es_ES' => 'Completado' ],
                'cancelled'   => [ 'nl_NL' => 'Geannuleerd',     'fr_FR' => 'Annulé',     'de_DE' => 'Abgebrochen',      'es_ES' => 'Cancelado' ],
                'postponed'   => [ 'nl_NL' => 'Uitgesteld',      'fr_FR' => 'Reporté',    'de_DE' => 'Verschoben',       'es_ES' => 'Aplazado' ],
                'no_show'     => [ 'nl_NL' => 'Niet verschenen', 'fr_FR' => 'Absent',     'de_DE' => 'Nicht erschienen', 'es_ES' => 'No presentado' ],
            ],

            'competition_type' => [
                'League'    => [ 'nl_NL' => 'Competitie',     'fr_FR' => 'Championnat',  'de_DE' => 'Liga',              'es_ES' => 'Liga' ],
                'Cup'       => [ 'nl_NL' => 'Beker',          'fr_FR' => 'Coupe',        'de_DE' => 'Pokal',             'es_ES' => 'Copa' ],
                'Tournament'=> [ 'nl_NL' => 'Toernooi',       'fr_FR' => 'Tournoi',      'de_DE' => 'Turnier',           'es_ES' => 'Torneo' ],
                'Friendly'  => [ 'nl_NL' => 'Oefenwedstrijd', 'fr_FR' => 'Match amical', 'de_DE' => 'Freundschaftsspiel','es_ES' => 'Amistoso' ],
                'Indoor'    => [ 'nl_NL' => 'Zaal',           'fr_FR' => 'En salle',     'de_DE' => 'Halle',             'es_ES' => 'Sala' ],
            ],

            'game_subtype' => [
                'Eleven-a-side' => [ 'nl_NL' => 'Elftal',       'fr_FR' => 'À onze',  'de_DE' => 'Elf gegen elf',      'es_ES' => 'Once' ],
                'Seven-a-side'  => [ 'nl_NL' => 'Zevental',     'fr_FR' => 'À sept',  'de_DE' => 'Sieben gegen sieben','es_ES' => 'Siete' ],
                'Futsal'        => [ 'nl_NL' => 'Zaalvoetbal',  'fr_FR' => 'Futsal',  'de_DE' => 'Futsal',             'es_ES' => 'Fútbol sala' ],
                'Indoor'        => [ 'nl_NL' => 'Zaal',         'fr_FR' => 'En salle','de_DE' => 'Halle',              'es_ES' => 'Sala' ],
            ],

            'goal_status' => [
                'Pending'     => [ 'nl_NL' => 'Wachtend',     'fr_FR' => 'En attente', 'de_DE' => 'Ausstehend',     'es_ES' => 'Pendiente' ],
                'In Progress' => [ 'nl_NL' => 'Bezig',        'fr_FR' => 'En cours',   'de_DE' => 'In Bearbeitung', 'es_ES' => 'En curso' ],
                'Completed'   => [ 'nl_NL' => 'Voltooid',     'fr_FR' => 'Terminé',    'de_DE' => 'Abgeschlossen',  'es_ES' => 'Completado' ],
                'On Hold'     => [ 'nl_NL' => 'In de wacht',  'fr_FR' => 'Suspendu',   'de_DE' => 'Pausiert',       'es_ES' => 'En espera' ],
                'Cancelled'   => [ 'nl_NL' => 'Geannuleerd',  'fr_FR' => 'Annulé',     'de_DE' => 'Abgebrochen',    'es_ES' => 'Cancelado' ],
                'Proposed'    => [ 'nl_NL' => 'Voorgesteld',  'fr_FR' => 'Proposé',    'de_DE' => 'Vorgeschlagen',  'es_ES' => 'Propuesto' ],
                'Approved'    => [ 'nl_NL' => 'Goedgekeurd',  'fr_FR' => 'Approuvé',   'de_DE' => 'Genehmigt',      'es_ES' => 'Aprobado' ],
                'Rejected'    => [ 'nl_NL' => 'Afgewezen',    'fr_FR' => 'Rejeté',     'de_DE' => 'Abgelehnt',      'es_ES' => 'Rechazado' ],
            ],

            'goal_priority' => [
                'Low'    => [ 'nl_NL' => 'Laag',   'fr_FR' => 'Faible',  'de_DE' => 'Niedrig', 'es_ES' => 'Baja' ],
                'Medium' => [ 'nl_NL' => 'Middel', 'fr_FR' => 'Moyenne', 'de_DE' => 'Mittel',  'es_ES' => 'Media' ],
                'High'   => [ 'nl_NL' => 'Hoog',   'fr_FR' => 'Élevée',  'de_DE' => 'Hoch',    'es_ES' => 'Alta' ],
            ],

            'goal_approval_decision' => [
                'Pending'           => [ 'nl_NL' => 'Wachtend',            'fr_FR' => 'En attente',             'de_DE' => 'Ausstehend',             'es_ES' => 'Pendiente' ],
                'Approved'          => [ 'nl_NL' => 'Goedgekeurd',         'fr_FR' => 'Approuvé',               'de_DE' => 'Genehmigt',              'es_ES' => 'Aprobado' ],
                'Rejected'          => [ 'nl_NL' => 'Afgewezen',           'fr_FR' => 'Rejeté',                 'de_DE' => 'Abgelehnt',              'es_ES' => 'Rechazado' ],
                'Changes requested' => [ 'nl_NL' => 'Wijzigingen gevraagd','fr_FR' => 'Modifications demandées','de_DE' => 'Änderungen angefordert', 'es_ES' => 'Cambios solicitados' ],
            ],

            'attendance_status' => [
                'Present'  => [ 'nl_NL' => 'Aanwezig',        'fr_FR' => 'Présent',   'de_DE' => 'Anwesend',  'es_ES' => 'Presente' ],
                'Absent'   => [ 'nl_NL' => 'Afwezig',         'fr_FR' => 'Absent',    'de_DE' => 'Abwesend',  'es_ES' => 'Ausente' ],
                'Late'     => [ 'nl_NL' => 'Te laat',         'fr_FR' => 'En retard', 'de_DE' => 'Verspätet', 'es_ES' => 'Tarde' ],
                'Injured'  => [ 'nl_NL' => 'Geblesseerd',     'fr_FR' => 'Blessé',    'de_DE' => 'Verletzt',  'es_ES' => 'Lesionado' ],
                'Excused'  => [ 'nl_NL' => 'Verontschuldigd', 'fr_FR' => 'Excusé',    'de_DE' => 'Entschuldigt','es_ES' => 'Justificado' ],
            ],

            'journey_event_type' => [
                'Trial'              => [ 'nl_NL' => 'Proeftraining',      'fr_FR' => 'Essai',                   'de_DE' => 'Probetraining',          'es_ES' => 'Prueba' ],
                'Signing'            => [ 'nl_NL' => 'Contract',           'fr_FR' => 'Signature',               'de_DE' => 'Vertrag',                'es_ES' => 'Fichaje' ],
                'Age-group promotion'=> [ 'nl_NL' => 'Leeftijdspromotie',  'fr_FR' => 'Promotion de catégorie',  'de_DE' => 'Altersklassen-Aufstieg', 'es_ES' => 'Ascenso de categoría' ],
                'Position change'    => [ 'nl_NL' => 'Positiewissel',      'fr_FR' => 'Changement de poste',     'de_DE' => 'Positionswechsel',       'es_ES' => 'Cambio de posición' ],
                'Injury'             => [ 'nl_NL' => 'Blessure',           'fr_FR' => 'Blessure',                'de_DE' => 'Verletzung',             'es_ES' => 'Lesión' ],
                'Return to play'     => [ 'nl_NL' => 'Terugkeer',          'fr_FR' => 'Retour au jeu',           'de_DE' => 'Rückkehr',               'es_ES' => 'Vuelta a jugar' ],
                'Release'            => [ 'nl_NL' => 'Vertrek',            'fr_FR' => 'Libération',              'de_DE' => 'Freigabe',               'es_ES' => 'Baja' ],
                'Graduation'         => [ 'nl_NL' => 'Diplomering',        'fr_FR' => 'Fin de formation',        'de_DE' => 'Abschluss',              'es_ES' => 'Graduación' ],
                'Transfer'           => [ 'nl_NL' => 'Transfer',           'fr_FR' => 'Transfert',               'de_DE' => 'Transfer',               'es_ES' => 'Traspaso' ],
                'Loan'               => [ 'nl_NL' => 'Verhuur',            'fr_FR' => 'Prêt',                    'de_DE' => 'Leihe',                  'es_ES' => 'Cesión' ],
                'Recall'             => [ 'nl_NL' => 'Terughalen',         'fr_FR' => 'Rappel',                  'de_DE' => 'Rückruf',                'es_ES' => 'Regreso' ],
            ],

            'player_value' => [
                'Respect'   => [ 'nl_NL' => 'Respect',    'fr_FR' => 'Respect',          'de_DE' => 'Respekt',   'es_ES' => 'Respeto' ],
                'Teamwork'  => [ 'nl_NL' => 'Teamwork',   'fr_FR' => "Travail d'équipe", 'de_DE' => 'Teamwork',  'es_ES' => 'Trabajo en equipo' ],
                'Discipline'=> [ 'nl_NL' => 'Discipline', 'fr_FR' => 'Discipline',       'de_DE' => 'Disziplin', 'es_ES' => 'Disciplina' ],
                'Effort'    => [ 'nl_NL' => 'Inzet',      'fr_FR' => 'Effort',           'de_DE' => 'Einsatz',   'es_ES' => 'Esfuerzo' ],
                'Fair play' => [ 'nl_NL' => 'Fair play',  'fr_FR' => 'Fair-play',        'de_DE' => 'Fairplay',  'es_ES' => 'Juego limpio' ],
            ],

            'behaviour_rating_label' => [
                'Needs support'        => [ 'nl_NL' => 'Heeft ondersteuning nodig', 'fr_FR' => 'A besoin de soutien',      'de_DE' => 'Benötigt Unterstützung',  'es_ES' => 'Necesita apoyo' ],
                'Developing'           => [ 'nl_NL' => 'In ontwikkeling',           'fr_FR' => 'En progression',           'de_DE' => 'In Entwicklung',          'es_ES' => 'En desarrollo' ],
                'Meeting expectations' => [ 'nl_NL' => 'Voldoet aan verwachtingen', 'fr_FR' => 'Conforme aux attentes',    'de_DE' => 'Erfüllt Erwartungen',     'es_ES' => 'Cumple expectativas' ],
                'Above expectations'   => [ 'nl_NL' => 'Boven verwachting',         'fr_FR' => 'Au-dessus des attentes',   'de_DE' => 'Über den Erwartungen',    'es_ES' => 'Por encima de expectativas' ],
                'Exemplary'            => [ 'nl_NL' => 'Voorbeeldig',               'fr_FR' => 'Exemplaire',               'de_DE' => 'Vorbildlich',             'es_ES' => 'Ejemplar' ],
            ],

            'potential_band' => [
                'Far below club level' => [ 'nl_NL' => 'Ver onder clubniveau', 'fr_FR' => 'Très en dessous du niveau du club', 'de_DE' => 'Weit unter Vereinsniveau', 'es_ES' => 'Muy por debajo del nivel del club' ],
                'Below club level'     => [ 'nl_NL' => 'Onder clubniveau',     'fr_FR' => 'En dessous du niveau du club',      'de_DE' => 'Unter Vereinsniveau',      'es_ES' => 'Por debajo del nivel del club' ],
                'Club level'           => [ 'nl_NL' => 'Clubniveau',           'fr_FR' => 'Niveau du club',                    'de_DE' => 'Vereinsniveau',            'es_ES' => 'Nivel del club' ],
                'Above club level'     => [ 'nl_NL' => 'Boven clubniveau',     'fr_FR' => 'Au-dessus du niveau du club',       'de_DE' => 'Über Vereinsniveau',       'es_ES' => 'Por encima del nivel del club' ],
                'Elite potential'      => [ 'nl_NL' => 'Elitepotentieel',      'fr_FR' => 'Potentiel élite',                   'de_DE' => 'Elitepotenzial',           'es_ES' => 'Potencial de élite' ],
            ],

            'audience_type' => [
                'Coaches' => [ 'nl_NL' => 'Trainers', 'fr_FR' => 'Entraîneurs', 'de_DE' => 'Trainer',      'es_ES' => 'Entrenadores' ],
                'Parents' => [ 'nl_NL' => 'Ouders',   'fr_FR' => 'Parents',     'de_DE' => 'Eltern',       'es_ES' => 'Padres' ],
                'Players' => [ 'nl_NL' => 'Spelers',  'fr_FR' => 'Joueurs',     'de_DE' => 'Spieler',      'es_ES' => 'Jugadores' ],
                'Staff'   => [ 'nl_NL' => 'Staf',     'fr_FR' => 'Personnel',   'de_DE' => 'Mitarbeiter',  'es_ES' => 'Personal' ],
                'Scouts'  => [ 'nl_NL' => 'Scouts',   'fr_FR' => 'Recruteurs',  'de_DE' => 'Scouts',       'es_ES' => 'Ojeadores' ],
            ],

            'tournament_format' => [
                'Knockout'         => [ 'nl_NL' => 'Knock-out',        'fr_FR' => 'Élimination directe',  'de_DE' => 'K.-o.-System',        'es_ES' => 'Eliminatoria' ],
                'Round-robin'      => [ 'nl_NL' => 'Halve competitie', 'fr_FR' => 'Toutes rondes',        'de_DE' => 'Jeder gegen jeden',   'es_ES' => 'Todos contra todos' ],
                'Group + knockout' => [ 'nl_NL' => 'Groep + knock-out','fr_FR' => 'Groupes + élimination','de_DE' => 'Gruppe + K.-o.',      'es_ES' => 'Grupos + eliminatoria' ],
                'League'           => [ 'nl_NL' => 'Competitie',       'fr_FR' => 'Championnat',          'de_DE' => 'Liga',                'es_ES' => 'Liga' ],
            ],

            'vct_theme_status' => [
                'Draft'     => [ 'nl_NL' => 'Concept',     'fr_FR' => 'Brouillon',  'de_DE' => 'Entwurf',       'es_ES' => 'Borrador' ],
                'Planned'   => [ 'nl_NL' => 'Gepland',     'fr_FR' => 'Planifié',   'de_DE' => 'Geplant',       'es_ES' => 'Planificado' ],
                'Active'    => [ 'nl_NL' => 'Actief',      'fr_FR' => 'Actif',      'de_DE' => 'Aktiv',         'es_ES' => 'Activo' ],
                'Completed' => [ 'nl_NL' => 'Voltooid',    'fr_FR' => 'Terminé',    'de_DE' => 'Abgeschlossen', 'es_ES' => 'Completado' ],
                'Archived'  => [ 'nl_NL' => 'Gearchiveerd','fr_FR' => 'Archivé',    'de_DE' => 'Archiviert',    'es_ES' => 'Archivado' ],
            ],

            'cert_type' => [
                // UEFA grades are locale-invariant; only the generic certs translate.
                'First aid'          => [ 'nl_NL' => 'EHBO',              'fr_FR' => 'Premiers secours',      'de_DE' => 'Erste Hilfe',   'es_ES' => 'Primeros auxilios' ],
                'GDPR awareness'     => [ 'nl_NL' => 'AVG-bewustzijn',    'fr_FR' => 'Sensibilisation RGPD',  'de_DE' => 'DSGVO-Schulung','es_ES' => 'Concienciación RGPD' ],
                'Child safeguarding' => [ 'nl_NL' => 'Kinderbescherming', 'fr_FR' => "Protection de l'enfance",'de_DE' => 'Kinderschutz',  'es_ES' => 'Protección infantil' ],
            ],
        ];
    }
}
