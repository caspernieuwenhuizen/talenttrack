<?php
/**
 * Migration 0202 — Seed the JO13-1 Hedel methodology set (#2321, epic #2316).
 *
 * Converts the "Speelwijze Jeugd 13-1 Hedel" playing-style document into
 * structured methodology content, scoped to a second shipped methodology
 * set (1-4-3-3) that clubs can select alongside the default JO14-1 Hedel
 * (1-4-2-3-1) set from migration 0018 / 0200.
 *
 * It:
 *
 *   - Ensures a `tt_methodologies` row (slug `jo13-1-hedel`, shipped,
 *     not default), reused on re-run.
 *   - Seeds a `1-4-3-3` formation (slug `1-4-3-3-jo13`) with 11 position
 *     cards, a vision, 16 principles (5 defending, 3 transition V→A,
 *     6 attacking, 2 transition A→V), a framework primer with its phases
 *     and a handful of learning goals — every row stamped with the set's
 *     `methodology_id` (and `club_id = 1`, `is_shipped = 1`).
 *
 * Idempotent: every row is upserted by its natural key (slug / code /
 * unique pair) scoped to the set, so a fresh install lands with the set
 * in place and a re-run corrects rather than duplicates.
 *
 * Full-fidelity periodisation and per-phase tactical scenes are #2322 /
 * #2323 — this issue seeds the convertible structural content only.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const CLUB_ID = 1;

    public function getName(): string {
        return '0202_methodology_seed_jo13_hedel';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $set_id = $this->ensureSet( $p );
        if ( $set_id <= 0 ) return;

        $formation_id = $this->ensureFormation( $p, $set_id );
        if ( $formation_id <= 0 ) return;

        $this->seedVision( $p, $set_id, $formation_id );
        $this->seedPositions( $p, $formation_id );
        $this->seedPrinciples( $p, $set_id, $formation_id );

        $primer_id = $this->seedFrameworkPrimer( $p, $set_id );
        if ( $primer_id > 0 ) {
            $this->seedPhases( $p, $set_id, $primer_id );
            $this->seedLearningGoals( $p, $set_id, $primer_id );
        }
    }

    /* ─────────────────────── Set ─────────────────────── */

    private function ensureSet( string $p ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodologies WHERE club_id = %d AND slug = %s LIMIT 1",
            self::CLUB_ID, 'jo13-1-hedel'
        ) );
        if ( $existing > 0 ) return $existing;

        $wpdb->insert( "{$p}tt_methodologies", [
            'club_id'          => self::CLUB_ID,
            'uuid'             => wp_generate_uuid4(),
            'slug'             => 'jo13-1-hedel',
            'name_json'        => wp_json_encode( [ 'nl' => 'JO13-1 Hedel', 'en' => 'JO13-1 Hedel' ] ),
            'description_json' => wp_json_encode( [
                'nl' => 'Speelwijze JO13-1 (1-4-3-3).',
                'en' => 'JO13-1 playing style (1-4-3-3).',
            ] ),
            'is_default'       => 0,
            'is_shipped'       => 1,
            'sort_order'       => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    /* ─────────────────────── Formation ─────────────────────── */

    private function ensureFormation( string $p, int $set_id ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formations WHERE slug = %s LIMIT 1", '1-4-3-3-jo13'
        ) );
        $payload = [
            'club_id'          => self::CLUB_ID,
            'methodology_id'   => $set_id,
            'name_json'        => wp_json_encode( [ 'nl' => '1-4-3-3', 'en' => '1-4-3-3' ] ),
            'description_json' => wp_json_encode( [
                'nl' => 'De basisformatie van JO13-1 Hedel — dynamisch verzorgd voetbal via de flanken.',
                'en' => 'The JO13-1 Hedel base formation — dynamic considered football down the flanks.',
            ] ),
            'is_shipped'       => 1,
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_formations", $payload, [ 'id' => $existing ] );
            return $existing;
        }
        $payload['slug'] = '1-4-3-3-jo13';
        $wpdb->insert( "{$p}tt_formations", $payload );
        return (int) $wpdb->insert_id;
    }

    /* ─────────────────────── Vision ─────────────────────── */

    private function seedVision( string $p, int $set_id, int $formation_id ): void {
        global $wpdb;
        $row = [
            'club_scope'            => null,
            'methodology_id'        => $set_id,
            'club_id'               => self::CLUB_ID,
            'formation_id'          => $formation_id,
            'style_of_play_key'     => 'aanvallend_positiespel',
            'way_of_playing_json'   => wp_json_encode( [
                'nl' => 'Dynamisch verzorgd voetbal vanuit een nooit-opgeven-mentaliteit. Bal in beweging betekent ploeg in beweging. Zuiver in de passing, herkenbaar voetbal via een aantal spelaanvallen. Veel diepgang zonder bal, aanvalsopzetten via de flanken.',
                'en' => 'Dynamic considered football from a never-give-up mentality. Ball in motion means team in motion. Clean passing, recognisable football through a set of build-up patterns. Lots of depth off the ball, attacks set up down the flanks.',
            ] ),
            'important_traits_json' => wp_json_encode( [
                'nl' => [ 'Nooit-opgeven-mentaliteit', 'Ploeg in beweging met de bal', 'Zuiver in de passing', 'Diepgang zonder bal' ],
                'en' => [ 'Never-give-up mentality', 'Team moving with the ball', 'Clean passing', 'Depth off the ball' ],
            ] ),
            'notes_json'            => wp_json_encode( [
                'nl' => 'Vanuit een gesloten organisatie druk zetten, altijd balgericht (mee bewegen met de bal) maar de zone in de gaten houden. De 1-4-3-3 ("PNA") geeft de driehoekjes en positiewisselingen om vanuit de flanken diepgang te zoeken.',
                'en' => 'Press from a compact organisation, always ball-oriented (moving with the ball) while keeping an eye on the zone. The 1-4-3-3 ("PNA") provides the triangles and rotations to seek depth from the flanks.',
            ] ),
            'is_shipped'            => 1,
        ];

        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_visions
             WHERE is_shipped = 1 AND club_scope IS NULL AND methodology_id = %d LIMIT 1",
            $set_id
        ) );
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_visions", $row, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( "{$p}tt_methodology_visions", $row );
        }
    }

    /* ─────────────────────── Positions ─────────────────────── */

    private function seedPositions( string $p, int $formation_id ): void {
        global $wpdb;
        foreach ( $this->positionsData() as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_formation_positions
                 WHERE formation_id = %d AND jersey_number = %d AND is_shipped = 1 LIMIT 1",
                $formation_id, (int) $row['jersey_number']
            ) );
            $payload = [
                'club_id'              => self::CLUB_ID,
                'short_name_json'      => wp_json_encode( $row['short'] ),
                'long_name_json'       => wp_json_encode( $row['long'] ),
                'attacking_tasks_json' => wp_json_encode( $row['attacking'] ),
                'defending_tasks_json' => wp_json_encode( $row['defending'] ),
                'sort_order'           => (int) $row['jersey_number'],
            ];
            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_formation_positions", $payload, [ 'id' => $existing ] );
            } else {
                $payload['formation_id']  = $formation_id;
                $payload['jersey_number'] = (int) $row['jersey_number'];
                $payload['is_shipped']    = 1;
                $wpdb->insert( "{$p}tt_formation_positions", $payload );
            }
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function positionsData(): array {
        return [
            [
                'jersey_number' => 1,
                'short' => [ 'nl' => 'K', 'en' => 'GK' ],
                'long'  => [ 'nl' => 'Keeper', 'en' => 'Goalkeeper' ],
                'attacking' => [
                    'nl' => [ 'Opbouw starten vanuit achterin; korte oplossing eerst', 'Beschikbaar als afspeeloptie bij het verzorgd uitvoetballen', 'Aansluiten en het veld naar achteren groot maken' ],
                    'en' => [ 'Start the build-up from the back; short solution first', 'Be available as an option in the considered build-up', 'Push up and stretch the field backward' ],
                ],
                'defending' => [
                    'nl' => [ 'Verdediging leiden en organiseren met coaching', 'Vroeg uitkomen op de bal achter de verdediging', 'De hoge lijn coachen bij druk op de helft van de tegenstander' ],
                    'en' => [ 'Lead and organise the defence through coaching', 'Come off the line early on balls in behind', 'Coach the high line when pressing in the opponent half' ],
                ],
            ],
            [
                'jersey_number' => 2,
                'short' => [ 'nl' => 'RB', 'en' => 'RB' ],
                'long'  => [ 'nl' => 'Rechtsback', 'en' => 'Right back' ],
                'attacking' => [
                    'nl' => [ 'Overtal creëren op de flank samen met de rechtsbuiten', 'Opkomen en overlappen langs de lijn', 'Vroege of gerichte voorzet geven' ],
                    'en' => [ 'Create an overload on the flank with the right winger', 'Push up and overlap down the line', 'Deliver an early or targeted cross' ],
                ],
                'defending' => [
                    'nl' => [ 'Directe tegenstander scherp en kort dekken bij inspelen', 'Rugdekking geven en meekantelen richting de bal', 'Ingedraaid staan tegen diepgaande spelers' ],
                    'en' => [ 'Mark the direct opponent tight on receiving', 'Provide cover and tilt toward the ball', 'Stand turned in against runners in behind' ],
                ],
            ],
            [
                'jersey_number' => 3,
                'short' => [ 'nl' => 'CV', 'en' => 'CB' ],
                'long'  => [ 'nl' => 'Centrale verdediger', 'en' => 'Centre-back' ],
                'attacking' => [
                    'nl' => [ 'Verzorgd uitvoetballen, diepte voor breedte', 'Indribbelen om overtal in de opbouw te creëren', 'Durven een linie over te slaan naar het middenveld of de spits' ],
                    'en' => [ 'Considered build-up, depth before width', 'Dribble in to create a build-up overload', 'Dare to skip a line to midfield or the striker' ],
                ],
                'defending' => [
                    'nl' => [ 'Doordekken bij inspelen van de directe tegenstander', 'Rugdekking bij een lange bal', 'De achterste lijn organiseren en buitenspel afstemmen' ],
                    'en' => [ 'Follow tight when the direct opponent is fed', 'Provide cover on a long ball', 'Organise the back line and coordinate offside' ],
                ],
            ],
            [
                'jersey_number' => 4,
                'short' => [ 'nl' => 'CV', 'en' => 'CB' ],
                'long'  => [ 'nl' => 'Centrale verdediger', 'en' => 'Centre-back' ],
                'attacking' => [
                    'nl' => [ 'Verzorgd uitvoetballen, diepte voor breedte', 'Aansluiten naar het middenveld bij een doorzettende aanval', 'Restverdediging bewaken — altijd +1 achterin' ],
                    'en' => [ 'Considered build-up, depth before width', 'Close in to midfield when the attack progresses', 'Protect rest-defence — always +1 at the back' ],
                ],
                'defending' => [
                    'nl' => [ 'Doordekken bij inspelen van de directe tegenstander', 'Rugdekking bij een lange bal', 'De achterste lijn organiseren en buitenspel afstemmen' ],
                    'en' => [ 'Follow tight when the direct opponent is fed', 'Provide cover on a long ball', 'Organise the back line and coordinate offside' ],
                ],
            ],
            [
                'jersey_number' => 5,
                'short' => [ 'nl' => 'LB', 'en' => 'LB' ],
                'long'  => [ 'nl' => 'Linksback', 'en' => 'Left back' ],
                'attacking' => [
                    'nl' => [ 'Overtal creëren op de flank samen met de linksbuiten', 'Opkomen en overlappen langs de lijn', 'Vroege of gerichte voorzet geven' ],
                    'en' => [ 'Create an overload on the flank with the left winger', 'Push up and overlap down the line', 'Deliver an early or targeted cross' ],
                ],
                'defending' => [
                    'nl' => [ 'Directe tegenstander scherp en kort dekken bij inspelen', 'Rugdekking geven en meekantelen richting de bal', 'Ingedraaid staan tegen diepgaande spelers' ],
                    'en' => [ 'Mark the direct opponent tight on receiving', 'Provide cover and tilt toward the ball', 'Stand turned in against runners in behind' ],
                ],
            ],
            [
                'jersey_number' => 6,
                'short' => [ 'nl' => '#6', 'en' => '#6' ],
                'long'  => [ 'nl' => 'Controleur (verdedigende middenvelder)', 'en' => 'Holding midfielder' ],
                'attacking' => [
                    'nl' => [ 'Verbindingsspeler in de opbouw — bal uit de drukte halen', 'Nooit samen met de nr 8 "weg" — controle bewaren', 'Kantwissel spelen om het speelveld groot te maken' ],
                    'en' => [ 'Connector in the build-up — take the ball out of pressure', 'Never "away" together with the 8 — keep control', 'Switch play to make the field big' ],
                ],
                'defending' => [
                    'nl' => [ 'Passlijnen dicht naar de aanvallers van de tegenstander', 'Balgericht verdedigen zonder ruimte te verliezen', 'Nr 6 en nr 8 dekken voor elkaar' ],
                    'en' => [ 'Close passing lanes to the opponent forwards', 'Defend ball-oriented without losing space', 'The 6 and 8 cover for each other' ],
                ],
            ],
            [
                'jersey_number' => 8,
                'short' => [ 'nl' => '#8', 'en' => '#8' ],
                'long'  => [ 'nl' => 'Middenvelder', 'en' => 'Central midfielder' ],
                'attacking' => [
                    'nl' => [ 'Derde man zoeken via combinaties en driehoekjes', 'Diepteloopacties achter de laatste linie', 'Aansluiten voor de goal bij voorzetten' ],
                    'en' => [ 'Look for the third man via combinations and triangles', 'Runs in behind the last line', 'Arrive in the box on crosses' ],
                ],
                'defending' => [
                    'nl' => [ 'Druk op de bal — de tegenstander weinig tijd en ruimte geven', 'Kantwissel van de tegenstander voorkomen', 'Nr 6 en nr 8 dekken voor elkaar' ],
                    'en' => [ 'Pressure the ball — deny time and space', 'Prevent the opponent switching play', 'The 6 and 8 cover for each other' ],
                ],
            ],
            [
                'jersey_number' => 10,
                'short' => [ 'nl' => '#10', 'en' => '#10' ],
                'long'  => [ 'nl' => 'Aanvallende middenvelder', 'en' => 'Attacking midfielder' ],
                'attacking' => [
                    'nl' => [ '3e-mansituaties creëren met het gezicht naar het doel', 'Ruimte tussen de linies benutten', 'Aansluiten voor de goal bij voorzetten' ],
                    'en' => [ 'Create third-man situations facing goal', 'Exploit space between the lines', 'Arrive in the box on crosses' ],
                ],
                'defending' => [
                    'nl' => [ 'Naast de nr 9 staan bij het storen', 'Passlijnen naar het middenveld van de tegenstander afschermen', 'De indribbelende verdediger op tijd onder druk zetten' ],
                    'en' => [ 'Sit beside the 9 when pressing', 'Screen passing lanes into the opponent midfield', 'Time the press on the dribbling defender' ],
                ],
            ],
            [
                'jersey_number' => 7,
                'short' => [ 'nl' => 'RB', 'en' => 'RW' ],
                'long'  => [ 'nl' => 'Rechtsbuiten', 'en' => 'Right winger' ],
                'attacking' => [
                    'nl' => [ 'Breedte houden en de flank bespelen', 'Naar binnen komen om de overlap van de back mogelijk te maken', '1:1-actie zoeken om tot een voorzet te komen' ],
                    'en' => [ 'Hold width and work the flank', 'Come inside to enable the full-back overlap', 'Look for the 1v1 to create a cross' ],
                ],
                'defending' => [
                    'nl' => [ 'Passlijnen afschermen richting middenveld/aanval', 'Kantelen bij een contra-bal', 'Druk zetten met volle overgave' ],
                    'en' => [ 'Screen passing lanes toward midfield/attack', 'Tilt across on a switch', 'Press with full commitment' ],
                ],
            ],
            [
                'jersey_number' => 9,
                'short' => [ 'nl' => 'SP', 'en' => 'ST' ],
                'long'  => [ 'nl' => 'Spits', 'en' => 'Striker' ],
                'attacking' => [
                    'nl' => [ 'Diepteloopacties achter de laatste linie', 'Bezetting eerste paal bij voorzetten', 'Laag in de hoek afronden met de binnenkant' ],
                    'en' => [ 'Runs in behind the last line', 'Near-post presence on crosses', 'Finish low in the corner with the inside foot' ],
                ],
                'defending' => [
                    'nl' => [ 'De opbouw storen en richting de zijkant dwingen', 'De minst voetballende verdediger laten aanspelen en onder druk zetten', 'Loopactie dwingt de tegenstander naar een zone' ],
                    'en' => [ 'Disrupt the build-up and force it wide', 'Bait the weaker-on-the-ball defender and press', 'Runs force the opponent into a zone' ],
                ],
            ],
            [
                'jersey_number' => 11,
                'short' => [ 'nl' => 'LB', 'en' => 'LW' ],
                'long'  => [ 'nl' => 'Linksbuiten', 'en' => 'Left winger' ],
                'attacking' => [
                    'nl' => [ 'Breedte houden en de flank bespelen', 'Naar binnen komen om de overlap van de back mogelijk te maken', '1:1-actie zoeken om tot een voorzet te komen' ],
                    'en' => [ 'Hold width and work the flank', 'Come inside to enable the full-back overlap', 'Look for the 1v1 to create a cross' ],
                ],
                'defending' => [
                    'nl' => [ 'Passlijnen afschermen richting middenveld/aanval', 'Kantelen bij een contra-bal', 'Druk zetten met volle overgave' ],
                    'en' => [ 'Screen passing lanes toward midfield/attack', 'Tilt across on a switch', 'Press with full commitment' ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Principles ─────────────────────── */

    private function seedPrinciples( string $p, int $set_id, int $formation_id ): void {
        foreach ( $this->principlesData() as $row ) {
            $row['methodology_id']       = $set_id;
            $row['default_formation_id'] = $formation_id;
            $this->upsertPrinciple( $p, $set_id, $row );
        }
    }

    private function upsertPrinciple( string $p, int $set_id, array $row ): void {
        global $wpdb;
        $code = (string) $row['code'];
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_principles WHERE code = %s AND methodology_id = %d LIMIT 1",
            $code, $set_id
        ) );
        $payload = [
            'team_function_key'    => $row['team_function_key'],
            'team_task_key'        => $row['team_task_key'],
            'title_json'           => wp_json_encode( $row['title'] ),
            'explanation_json'     => wp_json_encode( $row['explanation'] ),
            'team_guidance_json'   => wp_json_encode( $row['team_guidance'] ),
            'line_guidance_json'   => wp_json_encode( $row['line_guidance'] ),
            'default_formation_id' => $row['default_formation_id'],
            'methodology_id'       => $set_id,
            'club_id'              => self::CLUB_ID,
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_principles", $payload, [ 'id' => $existing ] );
        } else {
            $payload['code']       = $code;
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_principles", $payload );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function principlesData(): array {
        return [
            // ── Verdedigen (storen) ────────────────────────────────
            [
                'code'              => 'VD-01',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Verdedigen met het hele team vanuit de beweging',
                    'en' => 'Defend with the whole team from movement',
                ],
                'explanation' => [
                    'nl' => 'We verdedigen met z\'n allen en vanuit de beweging: iedereen doet mee en beweegt mee met de bal. Alleen dan houden we het speelveld en de onderlinge ruimtes klein genoeg om de tegenstander vast te zetten.',
                    'en' => 'We defend with everyone and from movement: all eleven join in and move with the ball. Only then do we keep the field and the gaps small enough to lock the opponent in.',
                ],
                'team_guidance' => [
                    'nl' => 'Beweeg als team mee met de bal en houd de organisatie gesloten. Wie niet meedoet, laat een gat vallen dat de tegenstander uitspeelt.',
                    'en' => 'Move as a team with the ball and keep the organisation compact. Anyone who opts out leaves a gap the opponent will play through.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Druk zetten met volle overgave; de loopactie dwingt de tegenstander in de gewenste richting of zone.',
                        'en' => 'Press with full commitment; the run forces the opponent into the desired direction or zone.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Balgericht verdedigen zonder ruimte te verliezen; druk op de bal zodat de tegenstander weinig tijd en ruimte heeft.',
                        'en' => 'Defend ball-oriented without losing space; pressure the ball so the opponent has little time and room.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Meekantelen met de bal en ingedraaid staan tegen diepgaande spelers.',
                        'en' => 'Tilt with the ball and stand turned in against runners in behind.',
                    ],
                    'keeper' => [
                        'nl' => 'Luid coachen en de aansluiting van het team naar voren aanjagen.',
                        'en' => 'Coach loud and drive the team\'s forward compaction.',
                    ],
                ],
            ],
            [
                'code'              => 'VD-02',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'As dicht — tegenstander via de flanken dwingen (de langste weg naar doel)',
                    'en' => 'Centre closed — force the opponent down the flanks (the longest route to goal)',
                ],
                'explanation' => [
                    'nl' => 'We houden de as van het veld dicht en dwingen de tegenstander naar de flanken. Via de zijkant is de weg naar ons doel het langst en zijn de opties het kleinst.',
                    'en' => 'We keep the central axis closed and force the opponent wide. Down the flank the route to our goal is longest and the options are smallest.',
                ],
                'team_guidance' => [
                    'nl' => 'Sluit het centrum en stuur de bal naar buiten; op de flank zetten we vervolgens vast.',
                    'en' => 'Close the centre and steer the ball wide; on the flank we then lock in.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Knijp zodat geen middenvelder door de as kan worden ingespeeld; stuur de opbouw naar de zijkant.',
                        'en' => 'Pinch so no midfielder can be fed through the axis; steer the build-up wide.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Passlijnen door het midden dichthouden; de kantwissel van de tegenstander voorkomen.',
                        'en' => 'Keep central passing lanes shut; prevent the opponent switching play.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Kantelen naar de balkant en de flankaanval samen met de back afknijpen.',
                        'en' => 'Tilt to the ball side and choke the flank attack together with the full-back.',
                    ],
                    'keeper' => [
                        'nl' => 'De lijn coachen en de balkant aangeven.',
                        'en' => 'Coach the line and call the ball side.',
                    ],
                ],
            ],
            [
                'code'              => 'VD-03',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Verdedigen richting de bal maar ruimte-georiënteerd — geen balwatchers',
                    'en' => 'Defend toward the ball but zone-oriented — no ball-watchers',
                ],
                'explanation' => [
                    'nl' => 'We verdedigen richting de bal, maar houden tegelijk de zone in de gaten. Balwatchers laten diepgaande spelers en ruimtes vallen; wij zien bal én tegenstander.',
                    'en' => 'We defend toward the ball while watching the zone. Ball-watchers lose runners and space; we see both ball and opponent.',
                ],
                'team_guidance' => [
                    'nl' => 'Combineer druk op de bal met bewaking van de ruimte — nooit alleen naar de bal kijken.',
                    'en' => 'Combine pressure on the ball with cover of the space — never watch the ball alone.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Passlijnen afschermen terwijl je de druk op de baldrager houdt.',
                        'en' => 'Screen passing lanes while keeping pressure on the ball-carrier.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Balgericht verdedigen zonder de ruimte tussen de linies prijs te geven.',
                        'en' => 'Defend ball-oriented without surrendering the space between the lines.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Ingedraaid staan zodat je bal en diepgaande speler tegelijk ziet.',
                        'en' => 'Stand turned in so you see both the ball and the runner.',
                    ],
                    'keeper' => [
                        'nl' => 'Ruimtes achter de verdediging vroeg lezen en aangeven.',
                        'en' => 'Read and call the space behind the defence early.',
                    ],
                ],
            ],
            [
                'code'              => 'VD-04',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Elkaar ondersteunen via overtalsituaties',
                    'en' => 'Support each other through overloads',
                ],
                'explanation' => [
                    'nl' => 'In het verdedigen zoeken we overtal rond de bal. Buitenspelers en backs schuiven aan zodat we op de balkant altijd met meer man staan dan de tegenstander.',
                    'en' => 'When defending we seek an overload around the ball. Wingers and full-backs step in so on the ball side we always outnumber the opponent.',
                ],
                'team_guidance' => [
                    'nl' => 'Sluit aan naar de balkant en creëer overtal; laat de baldrager nooit in een gelijk of ondertal duel los.',
                    'en' => 'Compact to the ball side and create an overload; never leave the ball-carrier in an even or under-numbered duel.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Kantelen bij een contra-bal en meehelpen de balkant af te knijpen.',
                        'en' => 'Tilt on a switch and help choke the ball side.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Nr 6 en nr 8 dekken voor elkaar; zorg dat er altijd één controleert.',
                        'en' => 'The 6 and 8 cover for each other; make sure one always screens.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Backs schuiven aan om overtal te maken en geven rugdekking.',
                        'en' => 'Full-backs step in to make the overload and give cover.',
                    ],
                    'keeper' => [
                        'nl' => 'De overtalsituaties coachen en de restruimte bewaken.',
                        'en' => 'Coach the overloads and mind the space behind.',
                    ],
                ],
            ],
            [
                'code'              => 'VD-05',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Spelen vanuit eigen kracht — sterke en zwakke punten van de tegenstander meenemen',
                    'en' => 'Play from our own strength — factor in the opponent\'s strengths and weaknesses',
                ],
                'explanation' => [
                    'nl' => 'We verdedigen vanuit onze eigen kracht en manier van spelen, maar nemen de sterke en zwakke punten van de tegenstander mee in de keuze van drukzetten.',
                    'en' => 'We defend from our own strength and style, but factor the opponent\'s strengths and weaknesses into how we press.',
                ],
                'team_guidance' => [
                    'nl' => 'Spreek per wedstrijd af hoe we storen — afhankelijk van tegenstander, stand en fase in de wedstrijd.',
                    'en' => 'Agree per match how we press — based on opponent, score and game phase.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Laat de minst voetballende verdediger aanspelen en zet hem onder druk.',
                        'en' => 'Bait the weaker-on-the-ball defender and press him.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Zet vast op de spelmakers van de tegenstander en dicht hun passlijnen.',
                        'en' => 'Pin the opponent\'s playmakers and shut their lanes.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Anticipeer op de sterkste aanvaller van de tegenstander en stem de dekking af.',
                        'en' => 'Anticipate the opponent\'s strongest attacker and coordinate the marking.',
                    ],
                    'keeper' => [
                        'nl' => 'De afspraken coachen en de lijn hierop bijsturen.',
                        'en' => 'Coach the agreements and adjust the line accordingly.',
                    ],
                ],
            ],

            // ── Omschakelen Verdedigen → Aanvallen (overgang balwinst) ──
            [
                'code'              => 'OV-01',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'De eerste bal na balwinst moet balbezit zijn',
                    'en' => 'The first ball after winning it must be possession',
                ],
                'explanation' => [
                    'nl' => 'Direct na balwinst kiezen we voor zekerheid: de eerste bal moet balbezit opleveren. Balverlies vlak na de omschakeling geeft de tegenstander meteen weer een kans.',
                    'en' => 'Right after winning the ball we choose security: the first ball must keep possession. Losing it just after the turnover immediately hands the opponent another chance.',
                ],
                'team_guidance' => [
                    'nl' => 'Bied direct na balwinst zekere afspeelmogelijkheden aan; forceer niets in de eerste seconde.',
                    'en' => 'Offer safe options immediately after the turnover; force nothing in the first second.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Kom kort vragen om een zekere aansluiting te geven vóór de diepte.',
                        'en' => 'Show short to give a safe outlet before going deep.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Onder de man komen om de eerste bal veilig te verwerken.',
                        'en' => 'Drop under the man to secure the first ball.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Aanspeelbaar zijn schuin naar achter als veilige uitweg.',
                        'en' => 'Be available diagonally backward as the safe outlet.',
                    ],
                    'keeper' => [
                        'nl' => 'Beschikbaar zijn als zekere terugspeeloptie.',
                        'en' => 'Be available as the safe back-pass option.',
                    ],
                ],
            ],
            [
                'code'              => 'OV-02',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'Bal uit de drukte halen — diepte of terug, geen breedte',
                    'en' => 'Take the ball out of pressure — depth or back, not width',
                ],
                'explanation' => [
                    'nl' => 'Na balwinst halen we de bal uit de drukte. We kiezen diepte of terug — nooit breedte, want een breedtebal in de omschakeling is het gevoeligst voor onderschepping.',
                    'en' => 'After winning the ball we take it out of pressure. We choose depth or backward — never sideways, since a square ball in transition is the most vulnerable to interception.',
                ],
                'team_guidance' => [
                    'nl' => 'Bied een diepte- en een terugoptie aan zodat de baldrager de drukte kan ontvluchten.',
                    'en' => 'Offer a forward and a backward option so the carrier can escape the pressure.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Maak een diepteloopactie zodat de bal snel uit de drukte kan.',
                        'en' => 'Make a run in behind so the ball can leave the congestion fast.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Kies diepte of terug; vermijd de breedtebal in de omschakeling.',
                        'en' => 'Pick depth or backward; avoid the square ball in transition.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Bied de veilige teruguitweg aan en start daarna de opbouw opnieuw.',
                        'en' => 'Offer the safe backward outlet, then restart the build-up.',
                    ],
                    'keeper' => [
                        'nl' => 'Beschikbaar als teruguitweg en de omschakeling coachen.',
                        'en' => 'Available as the backward outlet and coach the transition.',
                    ],
                ],
            ],
            [
                'code'              => 'OV-03',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'Speelveld groot maken — lengte, breedte en diepteloopacties',
                    'en' => 'Make the field big — length, width and runs in behind',
                ],
                'explanation' => [
                    'nl' => 'Zodra de bal veilig is maken we het speelveld groot in lengte en breedte, met diepteloopacties. Zo trekken we de tegenstander uit elkaar en creëren we ruimte voor de aanval.',
                    'en' => 'Once the ball is safe we make the field big in length and width, with runs in behind. That pulls the opponent apart and creates space for the attack.',
                ],
                'team_guidance' => [
                    'nl' => 'Maak het veld direct groot na een veilige eerste bal; dwing de tegenstander uit hun organisatie.',
                    'en' => 'Stretch the field immediately after a safe first ball; force the opponent out of shape.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Diepteloopacties maken en breedte houden om het veld te openen.',
                        'en' => 'Make runs in behind and hold width to open the field.',
                    ],
                    'middenvelders' => [
                        'nl' => 'De ruimte tussen de linies innemen en het spel verplaatsen.',
                        'en' => 'Occupy the space between the lines and switch the play.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Het veld naar achter groot maken zodat de opbouw ruimte krijgt.',
                        'en' => 'Stretch the field backward so the build-up gets room.',
                    ],
                    'keeper' => [
                        'nl' => 'Aansluiten en de breedte in de opbouw coachen.',
                        'en' => 'Push up and coach the width in the build-up.',
                    ],
                ],
            ],

            // ── Aanvallen ─────────────────────────────────────────
            [
                'code'              => 'AV-01',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Overtalsituaties creëren via beweging',
                    'en' => 'Create overloads through movement',
                ],
                'explanation' => [
                    'nl' => 'In de aanval creëren we overtal via beweging. Door te bewegen en van positie te wisselen ontstaan er steeds situaties waarin we met meer man rond de bal staan dan de tegenstander.',
                    'en' => 'In attack we create overloads through movement. By moving and rotating we keep producing situations where we outnumber the opponent around the ball.',
                ],
                'team_guidance' => [
                    'nl' => 'Beweeg en wissel van positie om overtal te maken; sta nooit statisch.',
                    'en' => 'Move and rotate to build overloads; never stand static.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Naar binnen komen om de overlap van de back mogelijk te maken.',
                        'en' => 'Come inside to enable the full-back overlap.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Aansluiten op de flank om lokaal overtal te creëren.',
                        'en' => 'Join the flank to create a local overload.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Backs komen op en overlappen om overtal op de flank te maken.',
                        'en' => 'Full-backs push up and overlap to make the flank overload.',
                    ],
                    'keeper' => [
                        'nl' => 'De restverdediging coachen terwijl de backs opkomen.',
                        'en' => 'Coach rest-defence as the full-backs push up.',
                    ],
                ],
            ],
            [
                'code'              => 'AV-02',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Schuine ballen vóór breedteballen — breedte alleen als het 100% veilig is',
                    'en' => 'Slanted passes before square passes — width only when 100% safe',
                ],
                'explanation' => [
                    'nl' => 'We kiezen schuine ballen vóór breedteballen. Een breedtebal spelen we alleen als die 100% veilig is, omdat onderschepping direct tot een gevaarlijke tegenaanval leidt.',
                    'en' => 'We prefer slanted passes over square passes. We only play a square ball when it is 100% safe, because an interception leads straight to a dangerous counter.',
                ],
                'team_guidance' => [
                    'nl' => 'Zoek de schuine bal vooruit; speel breedte alleen zonder enig risico.',
                    'en' => 'Look for the slanted forward pass; play square only at zero risk.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Loop diagonaal vrij zodat je een schuine bal vooruit kunt ontvangen.',
                        'en' => 'Make diagonal runs so you can receive a slanted forward pass.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Dynamisch bewegen zodat schuine passlijnen ontstaan.',
                        'en' => 'Move dynamically so diagonal lanes open.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Aanspeelbaar zijn schuin naar achter; alleen 100% veilige breedte.',
                        'en' => 'Be available diagonally backward; only 100% safe square balls.',
                    ],
                    'keeper' => [
                        'nl' => 'Als uitlaatklep de veilige teruguitweg bieden.',
                        'en' => 'Offer the safe backward relief as the outlet.',
                    ],
                ],
            ],
            [
                'code'              => 'AV-03',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => '3e-mansituaties creëren met het gezicht naar het doel van de tegenstander',
                    'en' => 'Create third-man situations facing the opponent goal',
                ],
                'explanation' => [
                    'nl' => 'We zoeken voortdurend de derde man die met het gezicht naar het doel van de tegenstander vrij komt. Zo spelen we door de linies heen richting het doel.',
                    'en' => 'We constantly look for the third man arriving free and facing the opponent goal. That plays us through the lines toward goal.',
                ],
                'team_guidance' => [
                    'nl' => 'Speel op de vragende speler zodat via een kaats of combinatie de derde man vrij komt.',
                    'en' => 'Feed the demanding player so a layoff or combination frees the third man.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Kom kort vragen zodat een middenvelder als derde man kan doorstoten.',
                        'en' => 'Show short so a midfielder can burst through as the third man.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Als derde man vrij komen met het gezicht naar het doel.',
                        'en' => 'Arrive as the third man facing goal.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Een driehoekje maken met de controleur om de derde man te vinden.',
                        'en' => 'Form a triangle with the holding midfielder to find the third man.',
                    ],
                    'keeper' => [
                        'nl' => 'Het overzicht coachen zodat de derdemansoplossing ontstaat.',
                        'en' => 'Coach the overview so the third-man solution appears.',
                    ],
                ],
            ],
            [
                'code'              => 'AV-04',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'scoren',
                'title'             => [
                    'nl' => 'Diepteloopacties achter de laatste linie',
                    'en' => 'Runs in behind the last line',
                ],
                'explanation' => [
                    'nl' => 'We zoeken diepgang met loopacties achter de laatste linie van de tegenstander. Die zijn lastig te verdedigen en leveren de gevaarlijkste kansen op.',
                    'en' => 'We seek depth with runs behind the opponent\'s last line. They are hard to defend and produce the most dangerous chances.',
                ],
                'team_guidance' => [
                    'nl' => 'Zorg dat er altijd een diepteloopactie beschikbaar is achter de laatste linie.',
                    'en' => 'Make sure a run in behind the last line is always on.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Timing van de diepteloopactie afstemmen op de baldrager.',
                        'en' => 'Time the run in behind with the ball-carrier.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Doorstoten achter de linie wanneer de spits inzakt.',
                        'en' => 'Break beyond the line when the striker drops.',
                    ],
                    'verdedigers' => [
                        'nl' => 'De diepteloopactie op tijd inspelen met een schuine bal.',
                        'en' => 'Feed the run in behind on time with a slanted ball.',
                    ],
                    'keeper' => [
                        'nl' => 'De restverdediging coachen bij het opengaan van de linie.',
                        'en' => 'Coach rest-defence as the line opens up.',
                    ],
                ],
            ],
            [
                'code'              => 'AV-05',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'scoren',
                'title'             => [
                    'nl' => 'Hoog baltempo, afgewisseld met individuele acties',
                    'en' => 'High ball tempo, alternated with individual actions',
                ],
                'explanation' => [
                    'nl' => 'We spelen met een hoog baltempo en wisselen dat af met individuele acties. Het tempo houdt de tegenstander in beweging; de 1:1-actie breekt een gesloten organisatie open.',
                    'en' => 'We play at a high ball tempo and alternate it with individual actions. The tempo keeps the opponent moving; the 1v1 breaks open a compact block.',
                ],
                'team_guidance' => [
                    'nl' => 'Houd het baltempo hoog en kies het juiste moment voor een individuele actie.',
                    'en' => 'Keep the ball tempo high and pick the right moment for an individual action.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Een 1:1-actie zoeken om tot een voorzet of een kans te komen.',
                        'en' => 'Look for the 1v1 to create a cross or a chance.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Het tempo hooghouden met snelle, gerichte passing.',
                        'en' => 'Keep the tempo high with quick, accurate passing.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Het tempo starten met een snelle, zuivere eerste pass.',
                        'en' => 'Set the tempo with a quick, clean first pass.',
                    ],
                    'keeper' => [
                        'nl' => 'Snel het spel hervatten om het tempo hoog te houden.',
                        'en' => 'Restart quickly to keep the tempo high.',
                    ],
                ],
            ],
            [
                'code'              => 'AV-06',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'scoren',
                'title'             => [
                    'nl' => 'Restverdediging bewaken (+1)',
                    'en' => 'Guard rest-defence (+1)',
                ],
                'explanation' => [
                    'nl' => 'Terwijl we aanvallen bewaken we de restverdediging: we staan altijd met één man meer achterin dan de tegenstander aanvallers heeft. Zo vangen we een tegenaanval veilig op.',
                    'en' => 'While we attack we guard rest-defence: we always keep one more defender than the opponent has attackers. That way a counter is safely covered.',
                ],
                'team_guidance' => [
                    'nl' => 'Bewaak de balans — altijd +1 achterin bij een tegenaanval.',
                    'en' => 'Protect the balance — always +1 at the back against a counter.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Bij balverlies direct meehelpen zodat de restverdediging niet alleen staat.',
                        'en' => 'On loss, help immediately so rest-defence isn\'t left alone.',
                    ],
                    'middenvelders' => [
                        'nl' => 'De controleur blijft achter de bal om de balans te bewaken.',
                        'en' => 'The holding midfielder stays behind the ball to protect balance.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Restverdediging organiseren; nooit in ondertal achterblijven.',
                        'en' => 'Organise rest-defence; never sit under-numbered at the back.',
                    ],
                    'keeper' => [
                        'nl' => 'De restverdediging coachen en klaarstaan voor de lange counterbal.',
                        'en' => 'Coach rest-defence and be ready for the long counter ball.',
                    ],
                ],
            ],

            // ── Omschakelen Aanvallen → Verdedigen (overgang balverlies) ──
            [
                'code'              => 'OA-01',
                'team_function_key' => 'omschakelen_naar_verdedigen',
                'team_task_key'     => 'overgang_balverlies',
                'title'             => [
                    'nl' => 'Bij balverlies directe druk — de 3-5-secondenregel, het hele team mee',
                    'en' => 'On loss, immediate pressure — the 3-5 second rule, whole team in',
                ],
                'explanation' => [
                    'nl' => 'Direct na balverlies zetten we in de eerste 3 tot 5 seconden volle druk om de bal snel te heroveren. Het hele team doet mee — counterpressing werkt alleen collectief.',
                    'en' => 'Right after losing the ball we press hard in the first 3 to 5 seconds to win it back fast. The whole team joins — counterpressing only works collectively.',
                ],
                'team_guidance' => [
                    'nl' => 'Zet direct na balverlies collectief druk; als niet iedereen meedoet voetbalt de tegenstander eruit.',
                    'en' => 'Press collectively right after losing it; if anyone opts out the opponent plays through.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Meteen jagen op de nieuwe baldrager en de dichtstbijzijnde passlijn dichten.',
                        'en' => 'Immediately hunt the new ball-carrier and cut the nearest lane.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Aansluiten in de druk en korte driehoekjes van de tegenstander voorkomen.',
                        'en' => 'Join the press and prevent the opponent\'s short triangles.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Hoog knijpen en klaarstaan om de tweede bal te veroveren.',
                        'en' => 'Pinch high and be ready to win the second ball.',
                    ],
                    'keeper' => [
                        'nl' => 'Hoog staan en de lange bal achter de druk wegwerken.',
                        'en' => 'Stand high and clear the long ball behind the press.',
                    ],
                ],
            ],
            [
                'code'              => 'OA-02',
                'team_function_key' => 'omschakelen_naar_verdedigen',
                'team_task_key'     => 'overgang_balverlies',
                'title'             => [
                    'nl' => 'Speelt de tegenstander de druk uit → speelveld klein richting eigen doel, ophouden',
                    'en' => 'If the opponent beats the press → shrink the field toward our goal, delay',
                ],
                'explanation' => [
                    'nl' => 'Speelt de tegenstander onze directe druk uit, dan maken we het speelveld klein richting het eigen doel en houden we de aanval op. We herstellen de organisatie voordat we opnieuw druk zetten.',
                    'en' => 'If the opponent beats our immediate press, we shrink the field toward our own goal and delay the attack. We restore the organisation before pressing again.',
                ],
                'team_guidance' => [
                    'nl' => 'Zak gecontroleerd terug en maak het veld klein; ophouden gaat vóór instappen.',
                    'en' => 'Drop back in control and compact the field; delaying beats diving in.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Meezakken en de opbouw van de tegenstander alsnog vertragen.',
                        'en' => 'Drop back and still slow the opponent\'s build-up.',
                    ],
                    'middenvelders' => [
                        'nl' => 'De ruimte tussen de linies dichthouden tijdens het terugzakken.',
                        'en' => 'Keep the space between the lines shut while recovering.',
                    ],
                    'verdedigers' => [
                        'nl' => 'De aanval ophouden in ondertal en niet blind instappen.',
                        'en' => 'Delay the attack when under-numbered and don\'t dive in.',
                    ],
                    'keeper' => [
                        'nl' => 'De terugzakkende lijn coachen en klaarstaan voor de diepe bal.',
                        'en' => 'Coach the recovering line and be ready for the ball in behind.',
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Framework primer ─────────────────────── */

    private function seedFrameworkPrimer( string $p, int $set_id ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_framework_primers
             WHERE is_shipped = 1 AND club_scope IS NULL AND methodology_id = %d LIMIT 1",
            $set_id
        ) );
        $payload = [
            'club_scope'          => null,
            'methodology_id'      => $set_id,
            'club_id'             => self::CLUB_ID,
            'title_json'          => wp_json_encode( [
                'nl' => 'Speelwijze JO13-1',
                'en' => 'JO13-1 playing style',
            ] ),
            'tagline_json'        => wp_json_encode( [
                'nl' => 'Dynamisch verzorgd voetbal in de 1-4-3-3.',
                'en' => 'Dynamic considered football in the 1-4-3-3.',
            ] ),
            'intro_json'          => wp_json_encode( [
                'nl' => 'Dit raamwerk periodiseert de speelwijze van JO13-1 Hedel: de spelopvatting, de hoofdprincipes per teamfunctie en de fasen van aanvallen, verdedigen en omschakelen. Het is een denkkader om per week gericht te trainen, geen kant-en-klaar recept.',
                'en' => 'This framework periodises the JO13-1 Hedel playing style: the game concept, the main principles per team function, and the phases of attacking, defending and transition. It is a thinking framework to train purposefully week by week, not a ready-made recipe.',
            ] ),
            'phases_intro_json'   => wp_json_encode( [
                'nl' => 'De speelwijze kent fasen in aanvallen, verdedigen en beide omschakelingen. Elke fase heeft een eigen hoofddoelstelling die de keuze van handelingen, posities en intensiteit kleurt.',
                'en' => 'The playing style has phases in attacking, defending and both transitions. Each phase has its own objective that shapes the choice of actions, positions and intensity.',
            ] ),
            'learning_goals_intro_json' => wp_json_encode( [
                'nl' => 'Per teamtaak formuleren we leerdoelen met observeerbare handelingen, afgeleid van de sub-principes per linie.',
                'en' => 'For each team task we formulate learning goals with observable actions, derived from the per-line sub-principles.',
            ] ),
            'is_shipped'          => 1,
        ];
        if ( $existing > 0 ) {
            unset( $payload['is_shipped'] );
            $wpdb->update( "{$p}tt_methodology_framework_primers", $payload, [ 'id' => $existing ] );
            return $existing;
        }
        $wpdb->insert( "{$p}tt_methodology_framework_primers", $payload );
        return (int) $wpdb->insert_id;
    }

    /* ─────────────────────── Phases ─────────────────────── */

    private function seedPhases( string $p, int $set_id, int $primer_id ): void {
        foreach ( $this->phasesData() as $row ) {
            $this->upsertPhase( $p, $set_id, $primer_id, $row );
        }
    }

    private function upsertPhase( string $p, int $set_id, int $primer_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_phases
             WHERE primer_id = %d AND side = %s AND phase_number = %d AND is_shipped = 1 LIMIT 1",
            $primer_id, $row['side'], (int) $row['phase_number']
        ) );
        $payload = [
            'methodology_id' => $set_id,
            'club_id'        => self::CLUB_ID,
            'title_json'     => wp_json_encode( $row['title'] ),
            'goal_json'      => wp_json_encode( $row['goal'] ),
            'sort_order'     => (int) $row['sort_order'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_phases", $payload, [ 'id' => $existing ] );
        } else {
            $payload['primer_id']    = $primer_id;
            $payload['side']         = $row['side'];
            $payload['phase_number'] = (int) $row['phase_number'];
            $payload['is_shipped']   = 1;
            $wpdb->insert( "{$p}tt_methodology_phases", $payload );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function phasesData(): array {
        return [
            // Aanvallen
            [ 'side' => 'attacking', 'phase_number' => 1, 'sort_order' => 1,
              'title' => [ 'nl' => 'Aanvallen — opbouw eigen helft', 'en' => 'Attacking — build-up in our half' ],
              'goal'  => [
                  'nl' => 'Vanuit een verzorgde opbouw op eigen helft een speler vrij spelen met het gezicht naar voren.',
                  'en' => 'From a considered build-up in our half, free a player facing forward.',
              ],
            ],
            [ 'side' => 'attacking', 'phase_number' => 2, 'sort_order' => 2,
              'title' => [ 'nl' => 'Aanvallen — eindfase en scoren', 'en' => 'Attacking — final phase and scoring' ],
              'goal'  => [
                  'nl' => 'Via de flanken en diepteloopacties tot een kans komen en scoren.',
                  'en' => 'Create a chance via the flanks and runs in behind, and score.',
              ],
            ],
            // Verdedigen
            [ 'side' => 'defending', 'phase_number' => 1, 'sort_order' => 3,
              'title' => [ 'nl' => 'Verdedigen 1 — storen van de opbouw', 'en' => 'Defending 1 — disrupting the build-up' ],
              'goal'  => [
                  'nl' => 'De opbouw van de tegenstander storen en naar de flank dwingen.',
                  'en' => 'Disrupt the opponent build-up and force it wide.',
              ],
            ],
            [ 'side' => 'defending', 'phase_number' => 2, 'sort_order' => 4,
              'title' => [ 'nl' => 'Verdedigen 2 — rond de middenlijn', 'en' => 'Defending 2 — around the halfway line' ],
              'goal'  => [
                  'nl' => 'Rond de middenlijn de as dicht houden en de tegenstander de langste weg opdringen.',
                  'en' => 'Keep the axis closed around the halfway line and impose the longest route.',
              ],
            ],
            [ 'side' => 'defending', 'phase_number' => 3, 'sort_order' => 5,
              'title' => [ 'nl' => 'Verdedigen 3 — eigen helft', 'en' => 'Defending 3 — our own half' ],
              'goal'  => [
                  'nl' => 'Op eigen helft compact blijven, overtal rond de bal maken en kansen voorkomen.',
                  'en' => 'Stay compact in our half, overload around the ball and prevent chances.',
              ],
            ],
            // Omschakelen Verdedigen → Aanvallen
            [ 'side' => 'transition', 'phase_number' => 1, 'sort_order' => 6,
              'title' => [ 'nl' => 'Omschakelen V→A — bal veiligstellen', 'en' => 'Transition D→A — secure the ball' ],
              'goal'  => [
                  'nl' => 'De eerste bal na balwinst balbezit laten zijn en uit de drukte halen.',
                  'en' => 'Keep the first ball after winning it in possession and take it out of pressure.',
              ],
            ],
            [ 'side' => 'transition', 'phase_number' => 2, 'sort_order' => 7,
              'title' => [ 'nl' => 'Omschakelen V→A — veld groot maken', 'en' => 'Transition D→A — make the field big' ],
              'goal'  => [
                  'nl' => 'Het speelveld groot maken in lengte en breedte met diepteloopacties.',
                  'en' => 'Make the field big in length and width with runs in behind.',
              ],
            ],
            // Omschakelen Aanvallen → Verdedigen
            [ 'side' => 'transition', 'phase_number' => 3, 'sort_order' => 8,
              'title' => [ 'nl' => 'Omschakelen A→V — counterpressing helft tegenstander', 'en' => 'Transition A→D — counterpressing in the opponent half' ],
              'goal'  => [
                  'nl' => 'Direct na balverlies op de helft van de tegenstander in 3-5 seconden collectief druk zetten.',
                  'en' => 'Right after losing the ball in the opponent half, press collectively within 3-5 seconds.',
              ],
            ],
            [ 'side' => 'transition', 'phase_number' => 4, 'sort_order' => 9,
              'title' => [ 'nl' => 'Omschakelen A→V — eigen helft', 'en' => 'Transition A→D — our own half' ],
              'goal'  => [
                  'nl' => 'Bij uitgespeelde druk het veld klein maken richting eigen doel en ophouden.',
                  'en' => 'When the press is beaten, shrink the field toward our goal and delay.',
              ],
            ],
        ];
    }

    /* ─────────────────────── Learning goals ─────────────────────── */

    private function seedLearningGoals( string $p, int $set_id, int $primer_id ): void {
        foreach ( $this->learningGoalsData() as $row ) {
            $this->upsertLearningGoal( $p, $set_id, $primer_id, $row );
        }
    }

    private function upsertLearningGoal( string $p, int $set_id, int $primer_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_learning_goals
             WHERE primer_id = %d AND slug = %s LIMIT 1",
            $primer_id, $row['slug']
        ) );
        $payload = [
            'methodology_id' => $set_id,
            'club_id'        => self::CLUB_ID,
            'side'           => $row['side'],
            'team_task_key'  => $row['team_task_key'] ?? null,
            'title_json'     => wp_json_encode( $row['title'] ),
            'bullets_json'   => wp_json_encode( $row['bullets'] ),
            'sort_order'     => (int) $row['sort_order'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_learning_goals", $payload, [ 'id' => $existing ] );
        } else {
            $payload['primer_id']  = $primer_id;
            $payload['slug']       = $row['slug'];
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_methodology_learning_goals", $payload );
        }
    }

    /** @return array<int, array<string,mixed>> */
    private function learningGoalsData(): array {
        return [
            [
                'slug' => 'jo13-overtal-via-beweging', 'side' => 'attacking', 'team_task_key' => 'opbouwen', 'sort_order' => 1,
                'title' => [ 'nl' => 'Overtal creëren via beweging', 'en' => 'Create overloads through movement' ],
                'bullets' => [
                    'nl' => [ 'Positie kiezen en van positie wisselen', 'Overlap van de back mogelijk maken', 'Lokaal overtal op de flank herkennen', 'Schuine bal vóór breedtebal spelen' ],
                    'en' => [ 'Pick and rotate positions', 'Enable the full-back overlap', 'Recognise a local flank overload', 'Play the slanted pass before the square pass' ],
                ],
            ],
            [
                'slug' => 'jo13-derde-man-diepte', 'side' => 'attacking', 'team_task_key' => 'scoren', 'sort_order' => 2,
                'title' => [ 'nl' => 'Derde man en diepgang benutten', 'en' => 'Exploit the third man and depth' ],
                'bullets' => [
                    'nl' => [ '3e-mansituaties herkennen met gezicht naar doel', 'Diepteloopactie timen achter de laatste linie', 'Hoog baltempo afwisselen met een 1:1-actie', 'Laag in de hoek afronden met de binnenkant' ],
                    'en' => [ 'Recognise third-man situations facing goal', 'Time the run behind the last line', 'Alternate high tempo with a 1v1', 'Finish low in the corner with the inside foot' ],
                ],
            ],
            [
                'slug' => 'jo13-storen-team', 'side' => 'defending', 'team_task_key' => 'storen', 'sort_order' => 3,
                'title' => [ 'nl' => 'Storen met het hele team', 'en' => 'Disrupt with the whole team' ],
                'bullets' => [
                    'nl' => [ 'Meebewegen met de bal vanuit een gesloten organisatie', 'De as dicht houden en naar de flank dwingen', 'Balgericht verdedigen zonder balwatchen', 'Overtal rond de bal maken' ],
                    'en' => [ 'Move with the ball from a compact block', 'Keep the axis closed and force wide', 'Defend ball-oriented without ball-watching', 'Make an overload around the ball' ],
                ],
            ],
            [
                'slug' => 'jo13-omschakelen-balverlies', 'side' => 'transition', 'team_task_key' => 'overgang_balverlies', 'sort_order' => 4,
                'title' => [ 'nl' => 'Omschakelen na balverlies', 'en' => 'Transition after losing the ball' ],
                'bullets' => [
                    'nl' => [ 'Binnen 3-5 seconden collectief druk zetten', 'De dichtstbijzijnde passlijn dichten', 'Bij uitgespeelde druk gecontroleerd terugzakken', 'Het veld klein maken richting eigen doel' ],
                    'en' => [ 'Press collectively within 3-5 seconds', 'Cut the nearest passing lane', 'Recover in control when the press is beaten', 'Shrink the field toward our own goal' ],
                ],
            ],
        ];
    }
};
