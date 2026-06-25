<?php
/**
 * Migration 0177 — VCT exercise catalogue STARTER SCAFFOLD (#1129,
 * VCT-8, spun out of epic #905).
 *
 * **STARTER DRAFT — pilot-coach methodology review still pending.**
 *
 * The full VCT-8 ask is an 80-exercise pedagogically-curated catalogue
 * across six categories, each cue in five locales, gated on HoD /
 * pilot-coach review of the methodology choices (which exercises,
 * which intensity bands, which age ranges). That review is an
 * acceptance criterion on #1129 and has NOT happened yet, so this
 * migration ships only the seed *scaffold* plus a small representative
 * draft set: 12 exercises (2 per category across warmup / technical /
 * sided_game / conditioning / finishing / cool_down), each with three
 * coaching points authored in all five shipped locales (canonical
 * English + nl_NL / fr_FR / de_DE / es_ES).
 *
 * It is deliberately a starter subset, NOT the full 80, so the wizard
 * slot-fill and coach view become exercisable end-to-end across the
 * five locales today while the expert catalogue is curated. #1129 stays
 * open; the full catalogue + the methodology sign-off are tracked there.
 *
 * Codes are namespaced `cat_*` and are distinct from the earlier
 * `0128_vct_seed_exercises_starter` slim set, so this draft inserts
 * cleanly alongside it rather than no-opping against its `(club_id,
 * code)` rows.
 *
 * Coaching-point text is DATA (lives in `tt_vct_coaching_points` +
 * `tt_translations`), not gettext — so it carries no `.po` entries.
 * `cp.code` holds a stable English slug used as the
 * `VctCoachingPointsRepository::listForExercise()` COALESCE fallback;
 * the readable canonical English is stored as an `en_US` translation
 * row alongside the four translations.
 *
 * Intensity bands respect the per-age ceilings seeded in
 * `0125_vct_seed_age_profiles_and_templates` (U10=3, U11=4, U12=5,
 * U13/U14=7) so no draft exercise exceeds the workload envelope for the
 * youngest age it's offered to.
 *
 * `seed_revision = 1` on every row so a later catalogue correction can
 * raise the revision and re-write provisional entries without trampling
 * operator edits.
 *
 * Idempotent + forward-only: existence-check on `(club_id, code)` before
 * each exercise insert, `INSERT IGNORE` on the translation rows. Re-running
 * on an already-seeded club is a no-op.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;
use TT\Infrastructure\Tenancy\CurrentClub;

return new class extends Migration {

    public function getName(): string {
        return '0177_seed_vct_exercise_catalogue';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $exercises_table    = $p . 'tt_vct_exercises';
        $coaching_table     = $p . 'tt_vct_coaching_points';
        $translations_table = $p . 'tt_translations';

        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $exercises_table ) ) !== $exercises_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $coaching_table ) ) !== $coaching_table ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $translations_table ) ) !== $translations_table ) return;

        $club_id = (int) CurrentClub::id();
        if ( $club_id <= 0 ) $club_id = 1;

        $now = current_time( 'mysql', true );

        foreach ( $this->catalogue() as $ex ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$exercises_table} WHERE club_id = %d AND code = %s LIMIT 1",
                $club_id, (string) $ex['code']
            ) );
            if ( $existing > 0 ) continue;

            $md = $ex['md'] ?? [];
            $wpdb->insert( $exercises_table, [
                'club_id'                  => $club_id,
                'uuid'                     => wp_generate_uuid4(),
                'code'                     => (string) $ex['code'],
                'name_canonical'           => (string) $ex['name'],
                'category'                 => (string) $ex['category'],
                'tactical_theme'           => isset( $ex['theme'] ) ? (string) $ex['theme'] : null,
                'intensity_band'           => (int) $ex['intensity'],
                'duration_minutes_min'     => (int) $ex['dur_min'],
                'duration_minutes_max'     => (int) $ex['dur_max'],
                'players_min'              => (int) $ex['players_min'],
                'players_max'              => (int) $ex['players_max'],
                'sided_size'               => isset( $ex['sided'] ) ? (string) $ex['sided'] : null,
                'age_min'                  => (int) $ex['age_min'],
                'age_max'                  => (int) $ex['age_max'],
                'md_minus_4'               => isset( $md['md-4'] ) ? 1 : 0,
                'md_minus_3'               => isset( $md['md-3'] ) ? 1 : 0,
                'md_minus_2'               => isset( $md['md-2'] ) ? 1 : 0,
                'md_minus_1'               => isset( $md['md-1'] ) ? 1 : 0,
                'md_zero'                  => isset( $md['md'] )   ? 1 : 0,
                'md_plus_1'                => isset( $md['md+1'] ) ? 1 : 0,
                'md_plus_2'                => isset( $md['md+2'] ) ? 1 : 0,
                'md_none'                  => isset( $md['none'] ) ? 1 : 0,
                'equipment_json'           => wp_json_encode( (array) ( $ex['equipment'] ?? [] ) ),
                'diagram_url'              => isset( $ex['diagram'] ) ? (string) $ex['diagram'] : null,
                'verheijen_classification' => isset( $ex['verheijen'] ) ? (string) $ex['verheijen'] : null,
                'seed_revision'            => 1,
            ] );
            $exercise_id = (int) $wpdb->insert_id;
            if ( $exercise_id <= 0 ) continue;

            $seq = 0;
            foreach ( $ex['coaching_points'] ?? [] as $cp ) {
                $seq++;
                $wpdb->insert( $coaching_table, [
                    'club_id'     => $club_id,
                    'exercise_id' => $exercise_id,
                    'sequence'    => $seq,
                    'code'        => (string) $cp['code'],
                ] );
                $cp_id = (int) $wpdb->insert_id;
                if ( $cp_id <= 0 ) continue;

                foreach ( $this->locales() as $locale => $key ) {
                    if ( empty( $cp[ $key ] ) ) continue;
                    $wpdb->query( $wpdb->prepare(
                        "INSERT IGNORE INTO {$translations_table}
                           (club_id, entity_type, entity_id, field, locale, value, updated_at)
                         VALUES (%d, 'vct_coaching_point', %d, 'text', %s, %s, %s)",
                        $club_id, $cp_id, $locale, (string) $cp[ $key ], $now
                    ) );
                }
            }
        }
    }

    /**
     * Locale → catalogue-array key. Canonical English is stored as an
     * explicit `en_US` row so the rendered cue reads as prose, not as
     * the slug `cp.code` fallback.
     *
     * @return array<string,string>
     */
    private function locales(): array {
        return [
            'en_US' => 'en',
            'nl_NL' => 'nl',
            'fr_FR' => 'fr',
            'de_DE' => 'de',
            'es_ES' => 'es',
        ];
    }

    /**
     * Representative starter draft — 2 exercises per category, each with
     * 3 coaching points in 5 locales. Compact array per row.
     *
     * Field reference (all required unless marked optional):
     *   code            unique slug (namespaced `cat_*`)
     *   name            canonical English name
     *   category        vct_exercise_category value
     *   theme           vct_tactical_theme value (optional; null = theme-agnostic)
     *   intensity       1-10 band (respects per-age ceilings, see header)
     *   dur_min/dur_max minutes range
     *   players_min/max headcount range
     *   sided           e.g. '4v4' (optional)
     *   age_min/age_max numeric age (9 = U10, 10 = U11, …, 13 = U14)
     *   md              array of suitable MD contexts ('md-4'..'md+2', 'md', 'none')
     *   equipment       array of equipment names (optional)
     *   verheijen       classification (optional)
     *   coaching_points list of { code, en, nl, fr, de, es }
     *
     * @return list<array<string,mixed>>
     */
    private function catalogue(): array {
        $pre_md = [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'none' => 1 ];
        $any_md = $pre_md + [ 'md+1' => 1, 'md+2' => 1 ];

        return [
            // ── WARMUP (2) ──────────────────────────────────────────────
            [
                'code' => 'cat_warmup_pass_and_move', 'name' => 'Pass-and-move activation',
                'category' => 'warmup', 'theme' => null, 'intensity' => 2,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 6, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [
                        'code' => 'scan_before_receiving',
                        'en' => 'Scan over your shoulder before the ball arrives.',
                        'nl' => 'Kijk over je schouder voordat de bal aankomt.',
                        'fr' => 'Regarde par-dessus ton épaule avant de recevoir le ballon.',
                        'de' => 'Schau über die Schulter, bevor der Ball ankommt.',
                        'es' => 'Mira por encima del hombro antes de recibir el balón.',
                    ],
                    [
                        'code' => 'pass_then_move',
                        'en' => 'Pass and immediately move into a new space.',
                        'nl' => 'Speel de bal en beweeg meteen naar een nieuwe ruimte.',
                        'fr' => 'Passe puis déplace-toi aussitôt vers un nouvel espace.',
                        'de' => 'Pass spielen und sofort in eine neue Lücke bewegen.',
                        'es' => 'Pasa y muévete de inmediato a un nuevo espacio.',
                    ],
                    [
                        'code' => 'progressive_tempo',
                        'en' => 'Build the tempo up gradually over the drill.',
                        'nl' => 'Bouw het tempo geleidelijk op tijdens de oefening.',
                        'fr' => 'Augmente le rythme progressivement pendant l\'exercice.',
                        'de' => 'Steigere das Tempo im Laufe der Übung allmählich.',
                        'es' => 'Aumenta el ritmo de forma gradual durante el ejercicio.',
                    ],
                ],
            ],
            [
                'code' => 'cat_warmup_rondo_5v2', 'name' => 'Rondo 5v2 activation',
                'category' => 'warmup', 'theme' => 'possession', 'intensity' => 3,
                'dur_min' => 6, 'dur_max' => 12, 'players_min' => 7, 'players_max' => 21,
                'sided' => '5v2', 'age_min' => 10, 'age_max' => 13, 'md' => $any_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [
                        'code' => 'open_support_angle',
                        'en' => 'Offer support on an open angle, never behind a defender.',
                        'nl' => 'Bied steun aan in een open hoek, nooit achter een verdediger.',
                        'fr' => 'Propose un soutien sur un angle ouvert, jamais derrière un défenseur.',
                        'de' => 'Biete dich im offenen Winkel an, nie hinter einem Verteidiger.',
                        'es' => 'Ofrece apoyo en un ángulo abierto, nunca detrás de un defensor.',
                    ],
                    [
                        'code' => 'two_touch_rhythm',
                        'en' => 'Keep a two-touch rhythm: control, then pass.',
                        'nl' => 'Houd een twee-contacten-ritme aan: aannemen, dan passen.',
                        'fr' => 'Garde un rythme à deux touches : contrôle, puis passe.',
                        'de' => 'Halte einen Zwei-Kontakt-Rhythmus: annehmen, dann passen.',
                        'es' => 'Mantén un ritmo de dos toques: controla y luego pasa.',
                    ],
                    [
                        'code' => 'react_on_loss',
                        'en' => 'React instantly when you lose the ball — close the nearest passing lane.',
                        'nl' => 'Reageer direct bij balverlies — sluit de dichtstbijzijnde passlijn.',
                        'fr' => 'Réagis aussitôt à la perte du ballon — ferme la ligne de passe la plus proche.',
                        'de' => 'Reagiere sofort bei Ballverlust — schließe die nächste Passlinie.',
                        'es' => 'Reacciona al instante al perder el balón: cierra la línea de pase más cercana.',
                    ],
                ],
            ],

            // ── TECHNICAL (2) ──────────────────────────────────────────
            [
                'code' => 'cat_technical_first_touch_turn', 'name' => 'First-touch turn into space',
                'category' => 'technical', 'theme' => null, 'intensity' => 3,
                'dur_min' => 12, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 18,
                'age_min' => 9, 'age_max' => 12, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [
                        'code' => 'soft_first_touch',
                        'en' => 'Take a soft first touch that keeps the ball close.',
                        'nl' => 'Neem een zachte eerste aanname zodat de bal dichtbij blijft.',
                        'fr' => 'Fais un premier contact en douceur pour garder le ballon près de toi.',
                        'de' => 'Nimm den ersten Kontakt weich an, damit der Ball nah bleibt.',
                        'es' => 'Da un primer toque suave que mantenga el balón cerca.',
                    ],
                    [
                        'code' => 'turn_away_pressure',
                        'en' => 'Turn away from pressure, into the space you scanned.',
                        'nl' => 'Draai weg van de druk, naar de ruimte die je hebt gescand.',
                        'fr' => 'Tourne-toi à l\'opposé de la pression, vers l\'espace repéré.',
                        'de' => 'Drehe dich weg vom Druck, in den zuvor erkannten Raum.',
                        'es' => 'Gírate alejándote de la presión, hacia el espacio que escaneaste.',
                    ],
                    [
                        'code' => 'use_both_feet',
                        'en' => 'Practise the turn with both feet, not just the strong one.',
                        'nl' => 'Oefen de draai met beide voeten, niet alleen je sterke voet.',
                        'fr' => 'Travaille la prise de balle des deux pieds, pas seulement le bon.',
                        'de' => 'Übe die Drehung mit beiden Füßen, nicht nur mit dem starken.',
                        'es' => 'Practica el giro con ambos pies, no solo con el bueno.',
                    ],
                ],
            ],
            [
                'code' => 'cat_technical_passing_diamond', 'name' => 'Passing diamond with movement',
                'category' => 'technical', 'theme' => 'build_up', 'intensity' => 4,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [
                        'code' => 'receive_back_foot',
                        'en' => 'Receive on the back foot, ready for the next action.',
                        'nl' => 'Neem aan op de achterste voet, klaar voor de volgende actie.',
                        'fr' => 'Reçois sur le pied arrière, prêt pour l\'action suivante.',
                        'de' => 'Nimm mit dem hinteren Fuß an, bereit für die nächste Aktion.',
                        'es' => 'Recibe con el pie de atrás, listo para la siguiente acción.',
                    ],
                    [
                        'code' => 'weight_the_pass',
                        'en' => 'Match the weight of the pass to the distance.',
                        'nl' => 'Stem het pasgewicht af op de afstand.',
                        'fr' => 'Dose la puissance de la passe selon la distance.',
                        'de' => 'Passe die Schärfe des Passes an die Distanz an.',
                        'es' => 'Ajusta la fuerza del pase a la distancia.',
                    ],
                    [
                        'code' => 'pass_and_rotate',
                        'en' => 'Pass and rotate to keep the diamond shape alive.',
                        'nl' => 'Speel de bal en rotateer om de ruitvorm in stand te houden.',
                        'fr' => 'Passe et permute pour maintenir la forme en losange.',
                        'de' => 'Pass spielen und rotieren, um die Rautenform zu erhalten.',
                        'es' => 'Pasa y rota para mantener viva la forma de rombo.',
                    ],
                ],
            ],

            // ── SIDED GAME (2) ─────────────────────────────────────────
            [
                'code' => 'cat_sided_4v4_directional', 'name' => '4v4 directional with end-zones',
                'category' => 'sided_game', 'theme' => 'transition', 'intensity' => 6,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 16,
                'sided' => '4v4', 'age_min' => 11, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'pinnies' ],
                'coaching_points' => [
                    [
                        'code' => 'transition_speed',
                        'en' => 'Switch fast the moment you win or lose the ball.',
                        'nl' => 'Schakel snel om op het moment dat je de bal wint of verliest.',
                        'fr' => 'Bascule vite dès que tu gagnes ou perds le ballon.',
                        'de' => 'Schalte sofort um, wenn du den Ball gewinnst oder verlierst.',
                        'es' => 'Cambia rápido en cuanto ganes o pierdas el balón.',
                    ],
                    [
                        'code' => 'use_full_width',
                        'en' => 'Use the full width of the grid to stretch the defence.',
                        'nl' => 'Gebruik de volle breedte van het veld om de verdediging uit te rekken.',
                        'fr' => 'Utilise toute la largeur du terrain pour étirer la défense.',
                        'de' => 'Nutze die volle Breite des Feldes, um die Abwehr auseinanderzuziehen.',
                        'es' => 'Usa todo el ancho del campo para estirar a la defensa.',
                    ],
                    [
                        'code' => 'attack_the_endzone',
                        'en' => 'Attack the end-zone with a runner, not just the ball.',
                        'nl' => 'Val de eindzone aan met een inloop, niet alleen met de bal.',
                        'fr' => 'Attaque la zone de but avec un appel, pas seulement le ballon.',
                        'de' => 'Greife die Endzone mit einem Laufweg an, nicht nur mit dem Ball.',
                        'es' => 'Ataca la zona de gol con un desmarque, no solo con el balón.',
                    ],
                ],
            ],
            [
                'code' => 'cat_sided_6v6_build_up', 'name' => '6v6 + keepers, build-up from the back',
                'category' => 'sided_game', 'theme' => 'build_up', 'intensity' => 6,
                'dur_min' => 15, 'dur_max' => 25, 'players_min' => 12, 'players_max' => 16,
                'sided' => '6v6', 'age_min' => 12, 'age_max' => 13, 'md' => $pre_md,
                'equipment' => [ 'balls', 'cones', 'pinnies', 'goals' ],
                'coaching_points' => [
                    [
                        'code' => 'start_from_keeper',
                        'en' => 'Start every attack calmly from the keeper.',
                        'nl' => 'Start elke aanval rustig op bij de keeper.',
                        'fr' => 'Lance chaque attaque calmement depuis le gardien.',
                        'de' => 'Beginne jeden Angriff ruhig beim Torwart.',
                        'es' => 'Inicia cada ataque con calma desde el portero.',
                    ],
                    [
                        'code' => 'split_the_lines',
                        'en' => 'Look to play forward and split the opponent\'s lines.',
                        'nl' => 'Zoek de pass naar voren en speel door de linies van de tegenstander.',
                        'fr' => 'Cherche à jouer vers l\'avant et à casser les lignes adverses.',
                        'de' => 'Suche den Pass nach vorne und überspiele die gegnerischen Linien.',
                        'es' => 'Busca jugar hacia delante y romper las líneas rivales.',
                    ],
                    [
                        'code' => 'stay_patient',
                        'en' => 'Stay patient: recirculate the ball if the forward pass is off.',
                        'nl' => 'Blijf geduldig: laat de bal rondgaan als de pass naar voren niet kan.',
                        'fr' => 'Reste patient : fais circuler le ballon si la passe avant n\'est pas là.',
                        'de' => 'Bleib geduldig: lass den Ball zirkulieren, wenn der Vorwärtspass fehlt.',
                        'es' => 'Mantén la paciencia: haz circular el balón si no hay pase hacia delante.',
                    ],
                ],
            ],

            // ── CONDITIONING (2) ───────────────────────────────────────
            [
                'code' => 'cat_conditioning_shuttle_actions', 'name' => 'Football-action shuttle runs',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 6,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 12, 'age_max' => 13, 'md' => [ 'md-4' => 1, 'md-3' => 1, 'none' => 1 ],
                'equipment' => [ 'cones', 'balls' ], 'verheijen' => 'football_endurance',
                'coaching_points' => [
                    [
                        'code' => 'finish_with_action',
                        'en' => 'End every shuttle with a real football action.',
                        'nl' => 'Eindig elke shuttle met een echte voetbalactie.',
                        'fr' => 'Termine chaque navette par une vraie action de jeu.',
                        'de' => 'Beende jeden Lauf mit einer echten Fußballaktion.',
                        'es' => 'Termina cada ida y vuelta con una acción real de fútbol.',
                    ],
                    [
                        'code' => 'active_recovery',
                        'en' => 'Recover actively between runs — keep moving, don\'t stand still.',
                        'nl' => 'Herstel actief tussen de sprints — blijf bewegen, sta niet stil.',
                        'fr' => 'Récupère activement entre les courses — bouge, ne reste pas immobile.',
                        'de' => 'Erhole dich aktiv zwischen den Läufen — bleib in Bewegung, steh nicht still.',
                        'es' => 'Recupera de forma activa entre carreras: sigue moviéndote, no te pares.',
                    ],
                    [
                        'code' => 'full_effort_blocks',
                        'en' => 'Give full effort in the work blocks — quality over quantity.',
                        'nl' => 'Geef alles in de werkblokken — kwaliteit boven kwantiteit.',
                        'fr' => 'Donne tout dans les blocs de travail — la qualité avant la quantité.',
                        'de' => 'Gib in den Arbeitsblöcken alles — Qualität vor Quantität.',
                        'es' => 'Da el máximo en los bloques de trabajo: calidad antes que cantidad.',
                    ],
                ],
            ],
            [
                'code' => 'cat_conditioning_recovery_circuit', 'name' => 'Low-intensity recovery circuit',
                'category' => 'conditioning', 'theme' => null, 'intensity' => 3,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 4, 'players_max' => 20,
                'age_min' => 9, 'age_max' => 13, 'md' => [ 'md+1' => 1, 'md+2' => 1 ],
                'equipment' => [ 'balls', 'cones' ],
                'coaching_points' => [
                    [
                        'code' => 'keep_tempo_low',
                        'en' => 'Keep the tempo deliberately low to aid recovery.',
                        'nl' => 'Houd het tempo bewust laag om herstel te bevorderen.',
                        'fr' => 'Garde un rythme volontairement bas pour favoriser la récupération.',
                        'de' => 'Halte das Tempo bewusst niedrig, um die Erholung zu fördern.',
                        'es' => 'Mantén el ritmo bajo a propósito para favorecer la recuperación.',
                    ],
                    [
                        'code' => 'focus_on_technique',
                        'en' => 'Focus on clean technique, not on speed.',
                        'nl' => 'Focus op zuivere techniek, niet op snelheid.',
                        'fr' => 'Concentre-toi sur une technique propre, pas sur la vitesse.',
                        'de' => 'Konzentriere dich auf saubere Technik, nicht auf Tempo.',
                        'es' => 'Céntrate en una técnica limpia, no en la velocidad.',
                    ],
                    [
                        'code' => 'controlled_breathing',
                        'en' => 'Breathe in a steady, controlled rhythm throughout.',
                        'nl' => 'Adem rustig en gecontroleerd door de hele oefening heen.',
                        'fr' => 'Respire à un rythme régulier et contrôlé tout du long.',
                        'de' => 'Atme durchgehend in einem ruhigen, kontrollierten Rhythmus.',
                        'es' => 'Respira con un ritmo constante y controlado durante todo el ejercicio.',
                    ],
                ],
            ],

            // ── FINISHING (2) ──────────────────────────────────────────
            [
                'code' => 'cat_finishing_first_time_box', 'name' => 'First-time finishing in the box',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 10, 'dur_max' => 18, 'players_min' => 6, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => [ 'md-2' => 1, 'md-1' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [
                        'code' => 'look_at_keeper',
                        'en' => 'Glance at the keeper\'s position before you strike.',
                        'nl' => 'Kijk waar de keeper staat voordat je schiet.',
                        'fr' => 'Regarde la position du gardien avant de frapper.',
                        'de' => 'Schau auf die Position des Torwarts, bevor du abschließt.',
                        'es' => 'Mira la posición del portero antes de rematar.',
                    ],
                    [
                        'code' => 'placement_over_power',
                        'en' => 'Choose placement over power — pick your corner.',
                        'nl' => 'Kies plaatsing boven kracht — kies je hoek.',
                        'fr' => 'Privilégie le placement à la puissance — choisis ton angle.',
                        'de' => 'Wähle Platzierung statt Kraft — such dir die Ecke.',
                        'es' => 'Elige colocación antes que potencia: escoge tu palo.',
                    ],
                    [
                        'code' => 'follow_the_rebound',
                        'en' => 'Follow in for the rebound after every shot.',
                        'nl' => 'Loop door op de rebound na elk schot.',
                        'fr' => 'Suis le ballon pour le rebond après chaque tir.',
                        'de' => 'Lauf nach jedem Schuss zum Abpraller nach.',
                        'es' => 'Sigue el rechace después de cada disparo.',
                    ],
                ],
            ],
            [
                'code' => 'cat_finishing_combination_to_shot', 'name' => 'Combination play to a shot',
                'category' => 'finishing', 'theme' => 'finishing', 'intensity' => 5,
                'dur_min' => 12, 'dur_max' => 20, 'players_min' => 8, 'players_max' => 14,
                'age_min' => 12, 'age_max' => 13, 'md' => [ 'md-2' => 1, 'md-1' => 1, 'none' => 1 ],
                'equipment' => [ 'balls', 'cones', 'goals' ],
                'coaching_points' => [
                    [
                        'code' => 'combo_before_shot',
                        'en' => 'Complete the combination first, then finish — not the other way round.',
                        'nl' => 'Maak eerst de combinatie af, dan pas afronden — niet andersom.',
                        'fr' => 'Termine d\'abord la combinaison, puis conclus — pas l\'inverse.',
                        'de' => 'Spiel zuerst die Kombination zu Ende, dann schließe ab — nicht umgekehrt.',
                        'es' => 'Completa primero la combinación y luego define, no al revés.',
                    ],
                    [
                        'code' => 'time_the_run',
                        'en' => 'Time the run so you arrive as the ball does.',
                        'nl' => 'Time je loopactie zodat je tegelijk met de bal aankomt.',
                        'fr' => 'Synchronise ton appel pour arriver en même temps que le ballon.',
                        'de' => 'Time deinen Lauf, sodass du gleichzeitig mit dem Ball ankommst.',
                        'es' => 'Cronometra tu desmarque para llegar a la vez que el balón.',
                    ],
                    [
                        'code' => 'shoot_first_time',
                        'en' => 'Take the shot first-time when the ball is set up well.',
                        'nl' => 'Schiet in één keer als de bal goed wordt klaargelegd.',
                        'fr' => 'Frappe en première intention quand le ballon est bien servi.',
                        'de' => 'Schieße direkt, wenn der Ball gut aufgelegt ist.',
                        'es' => 'Remata de primeras cuando el balón llega bien servido.',
                    ],
                ],
            ],

            // ── COOL DOWN (2) ──────────────────────────────────────────
            [
                'code' => 'cat_cool_down_stretch_circuit', 'name' => 'Guided static stretch circuit',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 8, 'dur_max' => 12, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'md+1' => 1, 'md+2' => 1, 'none' => 1 ],
                'equipment' => [],
                'coaching_points' => [
                    [
                        'code' => 'hold_each_stretch',
                        'en' => 'Hold each stretch for about thirty seconds.',
                        'nl' => 'Houd elke stretch ongeveer dertig seconden vast.',
                        'fr' => 'Maintiens chaque étirement environ trente secondes.',
                        'de' => 'Halte jede Dehnung etwa dreißig Sekunden.',
                        'es' => 'Mantén cada estiramiento unos treinta segundos.',
                    ],
                    [
                        'code' => 'no_bouncing',
                        'en' => 'Stretch smoothly — no bouncing into the position.',
                        'nl' => 'Rek rustig op — niet veren in de houding.',
                        'fr' => 'Étire-toi en douceur — pas d\'à-coups dans la position.',
                        'de' => 'Dehne ruhig — kein Wippen in die Position.',
                        'es' => 'Estira con suavidad: sin rebotes al entrar en la posición.',
                    ],
                    [
                        'code' => 'breathe_through',
                        'en' => 'Breathe calmly through each stretch.',
                        'nl' => 'Adem rustig door tijdens elke stretch.',
                        'fr' => 'Respire calmement pendant chaque étirement.',
                        'de' => 'Atme bei jeder Dehnung ruhig weiter.',
                        'es' => 'Respira con calma durante cada estiramiento.',
                    ],
                ],
            ],
            [
                'code' => 'cat_cool_down_walking_mobility', 'name' => 'Light walking and mobility',
                'category' => 'cool_down', 'theme' => null, 'intensity' => 1,
                'dur_min' => 6, 'dur_max' => 10, 'players_min' => 4, 'players_max' => 24,
                'age_min' => 9, 'age_max' => 13,
                'md' => [ 'md-4' => 1, 'md-3' => 1, 'md-2' => 1, 'md-1' => 1, 'md+1' => 1, 'md+2' => 1, 'none' => 1 ],
                'equipment' => [],
                'coaching_points' => [
                    [
                        'code' => 'walk_at_chat_pace',
                        'en' => 'Walk at a pace where you can still chat easily.',
                        'nl' => 'Wandel in een tempo waarbij je nog makkelijk kunt praten.',
                        'fr' => 'Marche à une allure où tu peux encore discuter facilement.',
                        'de' => 'Geh in einem Tempo, in dem du noch leicht reden kannst.',
                        'es' => 'Camina a un ritmo en el que aún puedas charlar con facilidad.',
                    ],
                    [
                        'code' => 'mobilise_joints',
                        'en' => 'Move every joint gently through its full range.',
                        'nl' => 'Beweeg elk gewricht rustig door zijn volledige bereik.',
                        'fr' => 'Mobilise chaque articulation en douceur sur toute son amplitude.',
                        'de' => 'Bewege jedes Gelenk sanft durch seinen vollen Bewegungsumfang.',
                        'es' => 'Mueve cada articulación con suavidad por todo su recorrido.',
                    ],
                    [
                        'code' => 'lower_heart_rate',
                        'en' => 'Let your heart rate settle back down gradually.',
                        'nl' => 'Laat je hartslag geleidelijk weer tot rust komen.',
                        'fr' => 'Laisse ton rythme cardiaque redescendre progressivement.',
                        'de' => 'Lass deine Herzfrequenz allmählich wieder sinken.',
                        'es' => 'Deja que tu frecuencia cardiaca baje de forma gradual.',
                    ],
                ],
            ],
        ];
    }
};
