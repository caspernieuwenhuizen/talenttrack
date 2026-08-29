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
 * fills gaps without overwriting operator edits or earlier backfills),
 * re-applied with the corrected keys by migration 0248.
 * Codes that are identical across languages (age-group U-codes,
 * positions, UEFA cert grades) are intentionally omitted.
 *
 * Locale-invariant values are simply not listed. The four UI locales
 * beyond en_US are nl_NL, fr_FR, de_DE, es_ES (I18nModule::REGISTERED_LOCALES).
 *
 * ## The key must be `tt_lookups.name`, exactly (#3117)
 *
 * 68 of this map's entries once matched no lookup row at all, so
 * migration 0151 seeded nothing for 13 of the 20 types it claims to
 * cover. The vocabularies had been renamed underneath it — several moved
 * from Title Case labels to snake_case keys (`Trial` → `trial_started`,
 * `Match` → `game`), `competition_type` was renamed to `game_subtype` by
 * migration 0027, and `eval_category` left `tt_lookups` entirely for
 * `tt_eval_categories` in migration 0008 — while the map kept the old
 * names.
 *
 * **`INSERT IGNORE` against a key that matches nothing is a no-op with no
 * error.** That is why the drift was silent for so long, and it is the
 * one thing to know before adding a vocabulary: this map will not warn
 * you that your key is wrong. `LookupSeedMapCoverageTest` will — it fails
 * when an entry here resolves to no row on a freshly migrated install,
 * and when a live row inside a curated type has no entry here.
 *
 * The second half matters as much as the first. #3082 and migration
 * `0242_repair_foot_option_translations` both lean on "the curated seed
 * is the known-good value", and that claim has to hold per type, not on
 * average — a map covering half a type is worse than one covering none.
 *
 * Coverage is deliberately per-type, not global: a lookup_type absent
 * from this map (positions, injury kinds, VCT vocabularies) is uncurated
 * on purpose and the coverage test ignores it. A type listed here is a
 * promise that every one of its live rows is accounted for.
 */
final class LookupTranslationSeeds {

    /** @var list<string> */
    public const LOCALES = [ 'nl_NL', 'fr_FR', 'de_DE', 'es_ES' ];

    /**
     * Lookup types that appear in `map()` but are expected to have no
     * live rows on a freshly migrated install — a declared vocabulary
     * that nothing seeds yet. Empty today, and it should be hard to add
     * to: an entry here says "these translations sit idle until the rows
     * exist", which is only ever true when the missing rows are the bug.
     *
     * @var array<string, string> lookup_type => reason
     */
    public const UNSEEDED_VOCABULARIES = [];

    /**
     * Live rows inside a curated type that carry no entry in `map()` on
     * purpose, because the value reads the same in every locale. Listed
     * rather than silently skipped: "this word is identical in four
     * languages" is a claim worth writing down, and the coverage test
     * would otherwise have to guess.
     *
     * Age-group U-codes are NOT here — Dutch renders them as O7…O23, so
     * they are curated with an `nl_NL` row and nothing else.
     *
     * @var array<string, list<string>> lookup_type => canonical names
     */
    public const LOCALE_INVARIANT_ROWS = [
        // UEFA coaching grades are the same badge everywhere.
        'cert_type' => [ 'UEFA-A', 'UEFA-B', 'UEFA-C' ],
    ];

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

            // `eval_category` is deliberately absent. Migration 0008 moved
            // that vocabulary out of `tt_lookups` into `tt_eval_categories`
            // and deleted the lookup rows; its translations live under
            // `entity_type='eval_category'` and are seeded by migrations
            // 0084 / 0169. The entries that used to sit here matched
            // nothing (#3117).

            'eval_type' => [
                'Training'    => [ 'nl_NL' => 'Training',       'fr_FR' => 'Entraînement', 'de_DE' => 'Training',           'es_ES' => 'Entrenamiento' ],
                'Match'       => [ 'nl_NL' => 'Wedstrijd',      'fr_FR' => 'Match',        'de_DE' => 'Spiel',              'es_ES' => 'Partido' ],
                'Friendly'    => [ 'nl_NL' => 'Oefenwedstrijd', 'fr_FR' => 'Match amical', 'de_DE' => 'Freundschaftsspiel', 'es_ES' => 'Amistoso' ],
                // #3117 — live since migration 0057, never curated.
                'Tournament'  => [ 'nl_NL' => 'Toernooi',       'fr_FR' => 'Tournoi',      'de_DE' => 'Turnier',            'es_ES' => 'Torneo' ],
                'Observation' => [ 'nl_NL' => 'Observatie',     'fr_FR' => 'Observation',  'de_DE' => 'Beobachtung',        'es_ES' => 'Observación' ],
                'Other'       => [ 'nl_NL' => 'Overig',         'fr_FR' => 'Autre',        'de_DE' => 'Sonstiges',          'es_ES' => 'Otro' ],
            ],

            // #3117 — re-keyed. Migration 0033 seeds this vocabulary in
            // lowercase (`ActivityTypeKey::ALL`); the Title Case entries
            // this map used to carry matched no row, and `Friendly` /
            // `Trial` are not part of the vocabulary at all — a friendly is
            // a `game` with a `game_subtype`, and a trial is a trial case.
            'activity_type' => [
                'training'   => [ 'nl_NL' => 'Training',   'fr_FR' => 'Entraînement', 'de_DE' => 'Training',     'es_ES' => 'Entrenamiento' ],
                'game'       => [ 'nl_NL' => 'Wedstrijd',  'fr_FR' => 'Match',        'de_DE' => 'Spiel',        'es_ES' => 'Partido' ],
                'tournament' => [ 'nl_NL' => 'Toernooi',   'fr_FR' => 'Tournoi',      'de_DE' => 'Turnier',      'es_ES' => 'Torneo' ],
                'meeting'    => [ 'nl_NL' => 'Bespreking', 'fr_FR' => 'Réunion',      'de_DE' => 'Besprechung',  'es_ES' => 'Reunión' ],
                'other'      => [ 'nl_NL' => 'Overig',     'fr_FR' => 'Autre',        'de_DE' => 'Sonstiges',    'es_ES' => 'Otro' ],
            ],

            // #3117 — `scheduled`, `in_progress`, `postponed` and `no_show`
            // were dropped from the vocabulary; only these four are seeded.
            'activity_status' => [
                'draft'     => [ 'nl_NL' => 'Concept',     'fr_FR' => 'Brouillon', 'de_DE' => 'Entwurf',       'es_ES' => 'Borrador' ],
                'planned'   => [ 'nl_NL' => 'Gepland',     'fr_FR' => 'Planifié',  'de_DE' => 'Geplant',       'es_ES' => 'Planificado' ],
                'completed' => [ 'nl_NL' => 'Voltooid',    'fr_FR' => 'Terminé',   'de_DE' => 'Abgeschlossen', 'es_ES' => 'Completado' ],
                'cancelled' => [ 'nl_NL' => 'Geannuleerd', 'fr_FR' => 'Annulé',    'de_DE' => 'Abgebrochen',   'es_ES' => 'Cancelado' ],
            ],

            // `competition_type` is deliberately absent. Migration 0027
            // renamed the whole vocabulary to `game_subtype` and added
            // `Friendly`; the labels that used to sit under the old key are
            // below, under the new one (#3117).

            // #3117 — re-keyed. The `Eleven-a-side` / `Seven-a-side` /
            // `Futsal` / `Indoor` entries this carried are a football-format
            // vocabulary that does not exist; the live values are the
            // competition kinds migration 0027 renamed into this type.
            'game_subtype' => [
                'League'   => [ 'nl_NL' => 'Competitie',     'fr_FR' => 'Championnat',  'de_DE' => 'Liga',               'es_ES' => 'Liga' ],
                'Cup'      => [ 'nl_NL' => 'Beker',          'fr_FR' => 'Coupe',        'de_DE' => 'Pokal',              'es_ES' => 'Copa' ],
                'Friendly' => [ 'nl_NL' => 'Oefenwedstrijd', 'fr_FR' => 'Match amical', 'de_DE' => 'Freundschaftsspiel', 'es_ES' => 'Amistoso' ],
            ],

            // #3117 — `Proposed` / `Approved` / `Rejected` are not goal
            // statuses; they belong to `goal_approval_decision`, which has
            // its own (also re-keyed) block below. `GoalStatus::ALL` is the
            // reference for the six that remain.
            'goal_status' => [
                'Pending'          => [ 'nl_NL' => 'Wachtend',            'fr_FR' => 'En attente',              'de_DE' => 'Ausstehend',            'es_ES' => 'Pendiente' ],
                // #3082 — seeded by migration 0058 for the player-self-create
                // approval flow, never added here. It read English on every
                // non-Dutch locale and leaned on gettext for Dutch.
                'Pending Approval' => [ 'nl_NL' => 'Wacht op goedkeuring', 'fr_FR' => 'En attente d\'approbation', 'de_DE' => 'Wartet auf Genehmigung', 'es_ES' => 'Pendiente de aprobación' ],
                'In Progress'      => [ 'nl_NL' => 'Bezig',               'fr_FR' => 'En cours',                'de_DE' => 'In Bearbeitung',        'es_ES' => 'En curso' ],
                'Completed'        => [ 'nl_NL' => 'Voltooid',            'fr_FR' => 'Terminé',                 'de_DE' => 'Abgeschlossen',         'es_ES' => 'Completado' ],
                'On Hold'          => [ 'nl_NL' => 'In de wacht',         'fr_FR' => 'Suspendu',                'de_DE' => 'Pausiert',              'es_ES' => 'En espera' ],
                'Cancelled'        => [ 'nl_NL' => 'Geannuleerd',         'fr_FR' => 'Annulé',                  'de_DE' => 'Abgebrochen',           'es_ES' => 'Cancelado' ],
            ],

            'goal_priority' => [
                'Low'    => [ 'nl_NL' => 'Laag',   'fr_FR' => 'Faible',  'de_DE' => 'Niedrig', 'es_ES' => 'Baja' ],
                // #3082 — was 'Middel', which means "means / remedy / waist",
                // not a middling priority. The gettext catalogue had this
                // right all along (`msgstr "Gemiddeld"`); the curated seed
                // was the wrong one, which is why the repair migration
                // treats 'Middel' as a known-bad stored value too.
                'Medium' => [ 'nl_NL' => 'Gemiddeld', 'fr_FR' => 'Moyenne', 'de_DE' => 'Mittel',  'es_ES' => 'Media' ],
                'High'   => [ 'nl_NL' => 'Hoog',   'fr_FR' => 'Élevée',  'de_DE' => 'Hoch',    'es_ES' => 'Alta' ],
            ],

            // #3117 — re-keyed to the verbs migration 0111 seeds. These are
            // the actions an approver takes, so they read as imperatives
            // rather than as the statuses the old entries described.
            'goal_approval_decision' => [
                'approve' => [ 'nl_NL' => 'Goedkeuren',                'fr_FR' => 'Approuver',                     'de_DE' => 'Genehmigen',              'es_ES' => 'Aprobar' ],
                'amend'   => [ 'nl_NL' => 'Goedkeuren met aanpassing', 'fr_FR' => 'Approuver avec modification',   'de_DE' => 'Mit Änderung genehmigen', 'es_ES' => 'Aprobar con modificación' ],
                'reject'  => [ 'nl_NL' => 'Afwijzen',                  'fr_FR' => 'Rejeter',                       'de_DE' => 'Ablehnen',                'es_ES' => 'Rechazar' ],
            ],

            'attendance_status' => [
                'Present'  => [ 'nl_NL' => 'Aanwezig',        'fr_FR' => 'Présent',   'de_DE' => 'Anwesend',  'es_ES' => 'Presente' ],
                'Absent'   => [ 'nl_NL' => 'Afwezig',         'fr_FR' => 'Absent',    'de_DE' => 'Abwesend',  'es_ES' => 'Ausente' ],
                'Late'     => [ 'nl_NL' => 'Te laat',         'fr_FR' => 'En retard', 'de_DE' => 'Verspätet', 'es_ES' => 'Tarde' ],
                'Injured'  => [ 'nl_NL' => 'Geblesseerd',     'fr_FR' => 'Blessé',    'de_DE' => 'Verletzt',  'es_ES' => 'Lesionado' ],
                'Excused'  => [ 'nl_NL' => 'Verontschuldigd', 'fr_FR' => 'Excusé',    'de_DE' => 'Entschuldigt','es_ES' => 'Justificado' ],
            ],

            // #3117 — re-keyed to `JourneyEventType::ALL`, the keys
            // migration 0037 has seeded since this vocabulary existed. The
            // Title Case entries this map carried ('Trial', 'Signing',
            // 'Release', …) matched no row, so 11 of 11 were dead. The
            // Dutch labels below are the ones 0037 itself seeded, kept
            // verbatim so the curated value and the stored value agree.
            'journey_event_type' => [
                'joined_academy'       => [ 'nl_NL' => 'Bij de academie gekomen',        'fr_FR' => "Arrivée à l'académie",              'de_DE' => 'Aufnahme in die Akademie',         'es_ES' => 'Incorporación a la academia' ],
                'trial_started'        => [ 'nl_NL' => 'Stage gestart',                  'fr_FR' => 'Essai commencé',                    'de_DE' => 'Probetraining begonnen',           'es_ES' => 'Prueba iniciada' ],
                'trial_ended'          => [ 'nl_NL' => 'Stage afgerond',                 'fr_FR' => 'Essai terminé',                     'de_DE' => 'Probetraining beendet',            'es_ES' => 'Prueba finalizada' ],
                'signed'               => [ 'nl_NL' => 'Vastgelegd',                     'fr_FR' => 'Signature',                         'de_DE' => 'Vertrag unterschrieben',           'es_ES' => 'Fichado' ],
                'released'             => [ 'nl_NL' => 'Afscheid genomen',               'fr_FR' => 'Départ',                            'de_DE' => 'Abschied',                         'es_ES' => 'Baja' ],
                'graduated'            => [ 'nl_NL' => 'Doorgestroomd',                  'fr_FR' => 'Fin de formation',                  'de_DE' => 'Abschluss',                        'es_ES' => 'Graduación' ],
                'team_changed'         => [ 'nl_NL' => 'Team gewisseld',                 'fr_FR' => "Changement d'équipe",               'de_DE' => 'Teamwechsel',                      'es_ES' => 'Cambio de equipo' ],
                'age_group_promoted'   => [ 'nl_NL' => 'Naar volgende leeftijdscategorie','fr_FR' => 'Passage à la catégorie supérieure', 'de_DE' => 'Aufstieg in die nächste Altersklasse','es_ES' => 'Ascenso de categoría' ],
                'position_changed'     => [ 'nl_NL' => 'Positie gewijzigd',              'fr_FR' => 'Changement de poste',               'de_DE' => 'Positionswechsel',                 'es_ES' => 'Cambio de posición' ],
                'injury_started'       => [ 'nl_NL' => 'Blessure ingetreden',            'fr_FR' => 'Début de blessure',                 'de_DE' => 'Verletzung eingetreten',           'es_ES' => 'Inicio de lesión' ],
                'injury_ended'         => [ 'nl_NL' => 'Blessure hersteld',              'fr_FR' => 'Fin de blessure',                   'de_DE' => 'Verletzung ausgeheilt',            'es_ES' => 'Fin de lesión' ],
                'evaluation_completed' => [ 'nl_NL' => 'Evaluatie ingevoerd',            'fr_FR' => 'Évaluation terminée',               'de_DE' => 'Bewertung abgeschlossen',          'es_ES' => 'Evaluación completada' ],
                'pdp_verdict_recorded' => [ 'nl_NL' => 'POP-eindbeoordeling vastgelegd', 'fr_FR' => 'Verdict PDP enregistré',            'de_DE' => 'PDP-Urteil erfasst',               'es_ES' => 'Veredicto del PDP registrado' ],
                'note_added'           => [ 'nl_NL' => 'Notitie toegevoegd',             'fr_FR' => 'Note ajoutée',                      'de_DE' => 'Notiz hinzugefügt',                'es_ES' => 'Nota añadida' ],
                // #3082 — the two observation types added after this map was
                // written (migrations 0224 / 0230, epics #2493 and #2704).
                'training_observed'    => [ 'nl_NL' => 'Waargenomen tijdens training',   'fr_FR' => "Observé à l'entraînement",           'de_DE' => 'Im Training beobachtet',           'es_ES' => 'Observado en entrenamiento' ],
                'match_observed'       => [ 'nl_NL' => 'Waargenomen in een wedstrijd',   'fr_FR' => 'Observé en match',                  'de_DE' => 'Im Spiel beobachtet',              'es_ES' => 'Observado en partido' ],
            ],

            // #3117 — re-keyed to the eight values migration 0031 seeds.
            // `Respect`, `Teamwork`, `Discipline` and `Effort` were a
            // different draft of this vocabulary and never reached the
            // table; the labels below are migration 0142's, which had them
            // right in all four locales while this map did not.
            'player_value' => [
                'Commitment'    => [ 'nl_NL' => 'Inzet',         'fr_FR' => 'Engagement',         'de_DE' => 'Einsatz',          'es_ES' => 'Compromiso' ],
                'Coachability'  => [ 'nl_NL' => 'Coachbaarheid', 'fr_FR' => 'Réceptivité',        'de_DE' => 'Coachbarkeit',     'es_ES' => 'Receptividad' ],
                'Leadership'    => [ 'nl_NL' => 'Leiderschap',   'fr_FR' => 'Leadership',         'de_DE' => 'Führung',          'es_ES' => 'Liderazgo' ],
                'Resilience'    => [ 'nl_NL' => 'Veerkracht',    'fr_FR' => 'Résilience',         'de_DE' => 'Widerstandskraft', 'es_ES' => 'Resiliencia' ],
                'Communication' => [ 'nl_NL' => 'Communicatie',  'fr_FR' => 'Communication',      'de_DE' => 'Kommunikation',    'es_ES' => 'Comunicación' ],
                'Work ethic'    => [ 'nl_NL' => 'Werkethiek',    'fr_FR' => 'Éthique de travail', 'de_DE' => 'Arbeitsmoral',     'es_ES' => 'Ética de trabajo' ],
                'Fair play'     => [ 'nl_NL' => 'Fair play',     'fr_FR' => 'Fair-play',          'de_DE' => 'Fairplay',         'es_ES' => 'Juego limpio' ],
                'Ambition'      => [ 'nl_NL' => 'Ambitie',       'fr_FR' => 'Ambition',           'de_DE' => 'Ambition',         'es_ES' => 'Ambición' ],
            ],

            // #3117 — re-keyed. Migration 0153 rewrote this vocabulary to
            // the numeric scale `1`–`5` (`BehaviourRating::ALL`); the
            // sentence-shaped entries this map carried belong to the
            // version before that. The numeral is the stored key, the label
            // is what a coach reads.
            'behaviour_rating_label' => [
                '1' => [ 'nl_NL' => 'Zorgwekkend',       'fr_FR' => 'Préoccupant',            'de_DE' => 'Bedenklich',              'es_ES' => 'Preocupante' ],
                '2' => [ 'nl_NL' => 'Onder verwachting', 'fr_FR' => 'En deçà des attentes',   'de_DE' => 'Unter den Erwartungen',   'es_ES' => 'Por debajo de lo esperado' ],
                '3' => [ 'nl_NL' => 'Acceptabel',        'fr_FR' => 'Acceptable',             'de_DE' => 'Akzeptabel',              'es_ES' => 'Aceptable' ],
                '4' => [ 'nl_NL' => 'Sterk',             'fr_FR' => 'Solide',                 'de_DE' => 'Stark',                   'es_ES' => 'Sólido' ],
                '5' => [ 'nl_NL' => 'Voorbeeldig',       'fr_FR' => 'Exemplaire',             'de_DE' => 'Vorbildlich',             'es_ES' => 'Ejemplar' ],
            ],

            // #3117 — re-keyed. Migration 0153 replaced the "club level"
            // ladder with the destination a player is projected to reach
            // (`PotentialBand::ALL`), which is a claim about a career
            // rather than a comparison to the club's own first team.
            'potential_band' => [
                'first_team'             => [ 'nl_NL' => 'Eerste elftal',       'fr_FR' => 'Équipe première',        'de_DE' => 'Erste Mannschaft',  'es_ES' => 'Primer equipo' ],
                'professional_elsewhere' => [ 'nl_NL' => 'Profvoetbal elders',  'fr_FR' => 'Professionnel ailleurs', 'de_DE' => 'Profi anderswo',    'es_ES' => 'Profesional en otro club' ],
                'semi_pro'               => [ 'nl_NL' => 'Semi-professional',   'fr_FR' => 'Semi-professionnel',     'de_DE' => 'Halbprofi',         'es_ES' => 'Semiprofesional' ],
                'top_amateur'            => [ 'nl_NL' => 'Hoog amateur',        'fr_FR' => 'Haut niveau amateur',    'de_DE' => 'Top-Amateur',       'es_ES' => 'Amateur de alto nivel' ],
                'recreational'           => [ 'nl_NL' => 'Recreatief',          'fr_FR' => 'Loisir',                 'de_DE' => 'Freizeit',          'es_ES' => 'Recreativo' ],
            ],

            // #3117 — re-keyed. This is the report-audience vocabulary
            // (`ReportAudienceType::ALL`, seeded by migration 0114), not a
            // list of roles: it says who a generated report is written for,
            // which is why "parent (monthly summary)" and "player (personal
            // keepsake)" are distinct entries rather than one "Parents".
            'audience_type' => [
                'standard'                   => [ 'nl_NL' => 'Standaard',                     'fr_FR' => 'Standard',                          'de_DE' => 'Standard',                              'es_ES' => 'Estándar' ],
                'parent_monthly'             => [ 'nl_NL' => 'Ouder (maandsamenvatting)',     'fr_FR' => 'Parent (résumé mensuel)',           'de_DE' => 'Eltern (Monatsbericht)',                'es_ES' => 'Padre/madre (resumen mensual)' ],
                'internal_detailed'          => [ 'nl_NL' => 'Interne coaches (gedetailleerd)','fr_FR' => 'Coachs internes (détaillé)',        'de_DE' => 'Interne Trainer (detailliert)',         'es_ES' => 'Entrenadores internos (detallado)' ],
                'player_personal'            => [ 'nl_NL' => 'Speler (persoonlijke herinnering)','fr_FR' => 'Joueur (souvenir personnel)',     'de_DE' => 'Spieler (persönliche Erinnerung)',      'es_ES' => 'Jugador (recuerdo personal)' ],
                'scout'                      => [ 'nl_NL' => 'Scout',                         'fr_FR' => 'Recruteur',                         'de_DE' => 'Scout',                                 'es_ES' => 'Ojeador' ],
                'trial_admittance'           => [ 'nl_NL' => 'Toelatingsbrief proefperiode',  'fr_FR' => "Lettre d'admission après essai",     'de_DE' => 'Aufnahmeschreiben nach Probetraining',  'es_ES' => 'Carta de admisión tras prueba' ],
                'trial_denial_final'         => [ 'nl_NL' => 'Afwijsbrief proefperiode (definitief)', 'fr_FR' => 'Lettre de refus après essai (définitive)', 'de_DE' => 'Absageschreiben nach Probetraining (endgültig)', 'es_ES' => 'Carta de rechazo tras prueba (definitiva)' ],
                'trial_denial_encouragement' => [ 'nl_NL' => 'Afwijsbrief proefperiode (met aanmoediging)', 'fr_FR' => 'Lettre de refus après essai (avec encouragement)', 'de_DE' => 'Absageschreiben nach Probetraining (mit Ermutigung)', 'es_ES' => 'Carta de rechazo tras prueba (con aliento)' ],
            ],

            // `tournament_format` and `vct_theme_status` are deliberately
            // absent. Neither has ever had a live row, a vocabulary class,
            // or a reader anywhere in the codebase — they were speculative
            // entries, not retired vocabularies. The tournament vocabulary
            // that does exist is `tournament_formation` (4-3-3, 3-5-2, …),
            // whose values are locale-invariant (#3117).

            'cert_type' => [
                // UEFA grades are locale-invariant; only the generic certs translate.
                'First aid'              => [ 'nl_NL' => 'EHBO',              'fr_FR' => 'Premiers secours',       'de_DE' => 'Erste Hilfe',        'es_ES' => 'Primeros auxilios' ],
                'GDPR awareness'         => [ 'nl_NL' => 'AVG-bewustzijn',    'fr_FR' => 'Sensibilisation RGPD',   'de_DE' => 'DSGVO-Schulung',     'es_ES' => 'Concienciación RGPD' ],
                'Child safeguarding'     => [ 'nl_NL' => 'Kinderbescherming', 'fr_FR' => "Protection de l'enfance",'de_DE' => 'Kinderschutz',       'es_ES' => 'Protección infantil' ],
                // #3117 — live since migration 0217, curated in Dutch only.
                'Football periodisation' => [ 'nl_NL' => 'Voetbalperiodisering', 'fr_FR' => 'Périodisation du football', 'de_DE' => 'Fußballperiodisierung', 'es_ES' => 'Periodización del fútbol' ],
            ],
        ];
    }
}
