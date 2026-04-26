<?php
/**
 * Migration 0018 — Methodology full content (#0027 follow-up).
 *
 * Replaces the placeholder seed with the complete methodology
 * framework content drawn from the methodology document
 * `07. Voetbalmethode.pdf`:
 *
 *   - Vision row (single shipped record).
 *   - 18 principles: AO-01..AO-05 (opbouwen), AS-01..AS-02 (scoren),
 *     OV-01..OV-03 (overgang balverlies), VS-01..VS-05 (storen),
 *     VV-01..VV-03 (doelpunten voorkomen), OA-01..OA-03 (overgang
 *     balwinst).
 *   - 8 set pieces: 4 attacking (corner, vrije trap direct, vrije
 *     trap voorzet, penalty) + 4 defending equivalents.
 *   - 11 position cards on the 1:4:2:3:1 formation.
 *   - Framework primer (intro / voetbalmodel / phases / learning
 *     goals / influence factors / reflection / future).
 *   - 8 phases (4 attacking + 4 defending).
 *   - 10 learning goals (5 attacking + 5 defending).
 *   - 7 influence factors.
 *   - 11 football actions (5 with-ball + 4 without-ball + 2 support).
 *
 * Idempotent: every row is upserted by natural key (slug or code or
 * unique pair). Existing placeholder rows from migration 0016 are
 * UPDATEd to the full content; this means the migration is safe to
 * re-run and fresh installs land with everything in place.
 *
 * Image asset attachments are seeded by a sibling Sprint B migration
 * once the seed PNGs have been extracted from the source PDF and
 * committed under `assets/methodology/seed/`.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0018_methodology_full_content';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $formation_id = $this->ensureFormation( $p );
        if ( $formation_id <= 0 ) return;

        $this->seedVision( $p, $formation_id );
        $this->seedPrinciples( $p, $formation_id );
        $this->seedSetPieces( $p, $formation_id );
        $this->seedPositions( $p, $formation_id );
        $primer_id = $this->seedFrameworkPrimer( $p );
        if ( $primer_id > 0 ) {
            $this->seedPhases( $p, $primer_id );
            $this->seedLearningGoals( $p, $primer_id );
            $this->seedInfluenceFactors( $p, $primer_id );
        }
        $this->seedFootballActions( $p );
    }

    /* ─────────────────────── Formation lookup ─────────────────────── */

    private function ensureFormation( string $p ): int {
        global $wpdb;
        return (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formations WHERE slug = %s LIMIT 1", '1-4-2-3-1'
        ) );
    }

    /* ─────────────────────── Vision ─────────────────────── */

    private function seedVision( string $p, int $formation_id ): void {
        global $wpdb;
        $row = [
            'club_scope'            => null,
            'formation_id'          => $formation_id,
            'style_of_play_key'     => 'aanvallend_positiespel',
            'way_of_playing_json'   => wp_json_encode( [
                'nl' => 'Verzorgd positiespel, diepte zoekend via de zijkanten. Aanvallend voetbal dat vooruit is gericht; de aanval is de beste verdediging.',
                'en' => 'Considered positional play, seeking depth through the wings. Forward-oriented attacking football; attack as the best form of defence.',
            ] ),
            'important_traits_json' => wp_json_encode( [
                'nl' => [ 'Afspreken, uitvoeren en initiatief tonen', 'Coachen en accepteren', 'Inzet en motivatie' ],
                'en' => [ 'Agree, execute and take initiative', 'Coach and accept', 'Effort and motivation' ],
            ] ),
            'notes_json'            => wp_json_encode( [
                'nl' => "De basisformatie 1:4:2:3:1 biedt afwisselen, positiewisselingen, driehoekjes en gelegenheid tot het zoeken van de ruimte achter en tussen de linies van de tegenstander. Spelers tonen initiatief in het onderling afstemmen van acties met overtuiging van eigen kunnen. Discipline en inzet zijn onderdeel van wat de speler meebrengt het veld op: fouten maak je zelf en los je op voor een ander.",
                'en' => "The 1:4:2:3:1 base formation enables rotation, position swaps, triangles and access to space behind and between the opponent's lines. Players take initiative in coordinating actions with self-belief. Discipline and effort are part of what each player brings: own your mistakes, fix them for a teammate.",
            ] ),
            'is_shipped'            => 1,
        ];

        $existing = (int) $wpdb->get_var(
            "SELECT id FROM {$p}tt_methodology_visions WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
        );
        if ( $existing > 0 ) {
            unset( $row['is_shipped'] );
            $wpdb->update( "{$p}tt_methodology_visions", $row, [ 'id' => $existing ] );
        } else {
            $wpdb->insert( "{$p}tt_methodology_visions", $row );
        }
    }

    /* ─────────────────────── Principles ─────────────────────── */

    private function seedPrinciples( string $p, int $formation_id ): void {
        foreach ( $this->principlesData() as $row ) {
            $row['default_formation_id'] = $formation_id;
            $row['is_shipped']           = 1;
            $this->upsertPrinciple( $p, $row );
        }
    }

    private function upsertPrinciple( string $p, array $row ): void {
        global $wpdb;
        $code = (string) $row['code'];
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_principles WHERE code = %s LIMIT 1", $code
        ) );
        $payload = [
            'team_function_key'    => $row['team_function_key'],
            'team_task_key'        => $row['team_task_key'],
            'title_json'           => wp_json_encode( $row['title'] ),
            'explanation_json'     => wp_json_encode( $row['explanation'] ),
            'team_guidance_json'   => wp_json_encode( $row['team_guidance'] ),
            'line_guidance_json'   => wp_json_encode( $row['line_guidance'] ),
            'default_formation_id' => $row['default_formation_id'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_principles", $payload, [ 'id' => $existing ] );
        } else {
            $payload['code']       = $code;
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_principles", $payload );
        }
    }

    /**
     * @return array<int, array<string,mixed>>
     */
    private function principlesData(): array {
        return [
            // ── Aanvallen / Opbouwen ───────────────────────────────
            [
                'code'              => 'AO-01',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Ons balbezit is gericht op kansen en doelpunten — we spelen zoveel mogelijk vooruit',
                    'en' => 'Possession serves chances and goals — we play forward as much as possible',
                ],
                'explanation' => [
                    'nl' => 'Het maken van doelpunten is het leukste (en een zeer belangrijk deel) van het voetballen, daarom willen wij graag en vaak aanvallen. Dit doen wij door daar maar enigszins mogelijk vooruit te spelen; we accepteren dat dit af en toe tot balverlies kan leiden. Op eigen helft nemen we minder risico dan op de helft van de tegenstander, en de verdedigers spelen met minder risico vooruit dan de middenvelders en aanvallers.',
                    'en' => 'Scoring is the best (and a crucial) part of football, so we attack often. We play forward whenever possible and accept that this occasionally costs the ball. We take less risk on our own half than the opponent half; defenders play forward more conservatively than midfielders and attackers.',
                ],
                'team_guidance' => [
                    'nl' => 'Als team zorgen we dat de speler aan de bal altijd minimaal twee afspeelmogelijkheden vooruit heeft. Een korte pass naar de volgende linie kan, maar het overslaan van een linie is vaak een nog betere keuze. Vooruit gaat voor achteruit; achteruit alleen als er vooruit echt niets te spelen valt.',
                    'en' => 'As a team we make sure the player on the ball always has at least two forward options. A short pass to the next line is fine, but skipping a line is often even better. Forward beats backward; only play backward if nothing forward is on.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Sta klaar om een bal te ontvangen afhankelijk van de druk van de tegenstander. Bij directe opbouw vanuit achterin is het belangrijkst dat we de bal niet verliezen en dat het team aansluit.',
                        'en' => 'Be ready to receive based on the opponent\'s pressure. When the build-up goes direct from the back, holding on to the ball and the team closing in matter most.',
                    ],
                    'middenvelders' => [
                        'nl' => 'In de opbouw zijn we verbindingsspelers. De verdedigende middenvelder(s) is de belangrijkste schakel naar de creatieve aanvallende middenvelder(s) en de aanvallers.',
                        'en' => 'In the build-up we are connectors. The defensive midfielder(s) is the key link to the creative attacking midfielder(s) and the forwards.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Zorg dat de opbouw verplaatst kan worden naar de helft van de tegenstander. Centrale verdedigers dribbelen in waar mogelijk; vleugelverdedigers wisselen hoog/laag; ruimte vrijmaken voor een inzakkende verdedigende middenvelder.',
                        'en' => 'Make sure the build-up can be advanced to the opponent half. Centre-backs dribble in when possible; full-backs alternate high/low; create room for a dropping defensive midfielder.',
                    ],
                    'keeper' => [
                        'nl' => 'Wees actief beschikbaar als afspeeloptie. Korte oplossing eerst, lange bal als uitzondering.',
                        'en' => 'Stay actively available as an option. Short solution first, long ball as an exception.',
                    ],
                ],
            ],
            [
                'code'              => 'AO-02',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Backs doen mee in de aanval — we vallen vaak aan via de zijkanten',
                    'en' => 'Full-backs join the attack — we often attack down the wings',
                ],
                'explanation' => [
                    'nl' => 'Om aan de zijkanten een overtal te creëren willen we dat de backs regelmatig actief meedoen in de aanval. De samenwerking tussen back en buitenspeler is daarom cruciaal. Twee basismanieren: (1) de buitenspeler komt naar binnen om de bal te vragen en de back loopt buitenom in de ruimte, of (2) de buitenspeler komt aan de zijlijn aan de bal en de back komt eroverheen wanneer de buitenspeler naar binnen dribbelt.',
                    'en' => 'To create overloads on the flanks we want the full-backs to join the attack regularly. Cooperation between full-back and winger is therefore critical. Two main patterns: (1) the winger comes inside to receive while the full-back overlaps wide, or (2) the winger receives on the touchline and the full-back overlaps when the winger drives inside.',
                ],
                'team_guidance' => [
                    'nl' => 'We weten dat we via de zijkanten willen spelen en houden rekening met een snelle kantwissel — over de grond of door de lucht. We bewaken de balans in de restverdediging: we staan altijd in overtal tegenover de aanvallers van de tegenstander.',
                    'en' => 'We know we attack down the wings; we anticipate quick switches of play, on the ground or through the air. We protect rest-defence balance: always one more defender than the opponent has attackers.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Buitenspelers zijn voortdurend bezig met het zoeken van de ruimte aan de zijkant. Keuzes tussen erkomen of erstaan, ballen in de voeten of diep, en het afstemmen van handelingen met de vleugelverdediger zijn de aandachtspunten.',
                        'en' => 'Wingers constantly hunt the wide spaces. Showing or holding, ball-to-feet or in behind, and timing with the full-back are the key choices.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Houd rekening met de loopacties en de samenwerking tussen buitenspeler en vleugelverdediger. Heb contact over hoe en wie je aanspeelt. Verdedigende middenvelders helpen mee de balans en restverdediging te bewaken.',
                        'en' => 'Anticipate the runs and cooperation between winger and full-back. Communicate about who and how to feed. Defensive midfielders help shield balance and rest-defence.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Naast de aanvallende intentie van de vleugelverdedigers is restverdediging cruciaal. Twee gangbare oplossingen: (a) als 5 meedoet komt 2 naar binnen, we spelen 4/3/2 achterop; (b) als 2 ook meegaat stapt 6 of 8 uit, we spelen 4/(6 of 8)/3 achterop. Keuze hangt af van de situatie.',
                        'en' => 'Beyond the attacking intent of the full-backs, rest-defence is critical. Two common solutions: (a) when 5 pushes up, 2 tucks inside and we sit in a 4/3/2 shape; (b) if 2 also pushes up, 6 or 8 drops out and we sit in 4/(6 or 8)/3. Pick based on the situation.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach actief over de restverdediging en wees beschikbaar voor een terugspeelbal als de aanval vastloopt.',
                        'en' => 'Actively coach rest-defence and be available as the back-pass option when the attack stalls.',
                    ],
                ],
            ],
            [
                'code'              => 'AO-03',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Geen horizontale ballen bij kantwissel — kies achteruit of vooruit',
                    'en' => 'No horizontal passes when switching play — go backward or forward instead',
                ],
                'explanation' => [
                    'nl' => 'Tijdens de opbouw kan een vlakke, horizontale pass verleidelijk zijn. Maar een onderschepping leidt direct tot snelle tegenaanvallen en een mogelijk ondertal. We spelen dit soort ballen niet. Bij een kantwissel kiezen we zo vaak mogelijk een diagonale bal om gevaar van en na onderschepping te voorkomen. Dit dwingt medespelers ook tot vrijloop-bewegingen die de juiste passlijnen openen.',
                    'en' => 'A flat, horizontal pass is tempting in the build-up. But an interception immediately invites a fast counter and possibly a numerical disadvantage. We don\'t play those passes. When switching play we prefer a diagonal ball to remove the risk on and after interception. It also forces teammates into runs that open the right passing lanes.',
                ],
                'team_guidance' => [
                    'nl' => 'Iedere balbezitter heeft minimaal twee afspeelmogelijkheden vooruit. Een korte pass naar de volgende linie kan, maar overslaan van een linie is vaak nog beter.',
                    'en' => 'Every player on the ball has at least two forward options. A short pass to the next line works, but skipping a line is often better.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Loop diagonaal vrij in de ruimte tussen de linies en bied jezelf zo aan voor schuine ballen vooruit.',
                        'en' => 'Make diagonal runs between the lines so you offer yourself as a slanted forward option.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Beweeg dynamisch zodat schuine passlijnen ontstaan. Vermijd statisch in dezelfde lijn als de balbezitter te staan.',
                        'en' => 'Move dynamically so diagonal passing lanes open up. Avoid standing static on the same line as the ball-carrier.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Zorg voor voldoende ruimte om zelf aanspeelbaar te zijn schuin naar achter, en zo dat middenvelders tussen de linies vrij kunnen lopen.',
                        'en' => 'Provide enough room to be available diagonally backward, and so that midfielders can move freely between the lines.',
                    ],
                    'keeper' => [
                        'nl' => 'Bij een kantwissel via achteruit ben je het uitlaatklep — sta open en speel snel.',
                        'en' => 'On a switch via the back, you are the relief valve — open up and release quickly.',
                    ],
                ],
            ],
            [
                'code'              => 'AO-04',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Onze opbouw begint bij de keeper — lang alleen bij uitzondering',
                    'en' => 'Our build-up starts with the keeper — long only as an exception',
                ],
                'explanation' => [
                    'nl' => 'Bij het starten van een aanval vanuit de keeper proberen we zo min mogelijk een lange bal te spelen. Wij willen alle spelers beter leren voetballen en starten ons positiespel daarom in principe bij de keeper. Achterballen worden waar mogelijk ingespeeld op een verdediger of uitzakkende middenvelder, ook ballen die in de handen van de keeper eindigen leiden tot het inspelen van een speler. Bij uitzondering kan op basis van omstandigheden voor een lange bal worden gekozen.',
                    'en' => 'When starting an attack from the keeper we try to avoid the long ball. We want all players to become better footballers and so start our positional play at the keeper. Back-passes are played to a defender or dropping midfielder where possible; balls finishing in the keeper\'s hands also become a built distribution. The long ball is reserved for exceptions when the situation calls for it.',
                ],
                'team_guidance' => [
                    'nl' => 'Zorg voor voldoende afspeelmogelijkheden zodat de keeper altijd voetballend een oplossing kan vinden. Dat kan een uitdaging zijn als de tegenstander goed en hoog druk zet of vastzet bij achterballen.',
                    'en' => 'Provide enough options so the keeper can always find a footballing solution. That gets harder when the opponent presses high or sets traps on back-passes.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Sta klaar om een bal te ontvangen afhankelijk van de druk. Als er direct vooruit wordt gespeeld is het belangrijkst de bal niet te verliezen en het team te laten aansluiten.',
                        'en' => 'Be ready to receive based on opponent pressure. If we go direct, holding on and letting the team close in matter most.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Verdedigende middenvelders zijn creatief in hun vrijloop wanneer onze verdedigers worden vastgezet of opgejaagd. Driehoekjes maken of in vrije ruimte komen tussen of naast de centrale verdedigers is een optie.',
                        'en' => 'Defensive midfielders move creatively when our defenders are pinned or hunted. Form triangles or drop into free space between or beside the centre-backs.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Ga er altijd vanuit dat een korte bal komt; benut of verlaat ruimtes zo dat een middenvelder kan komen. Doe direct weer mee na het vooruit inspelen om de tegenstander te lokken en alsnog een andere kant op te kunnen voetballen.',
                        'en' => 'Always assume a short ball is coming; use or vacate spaces so a midfielder can join. Re-engage immediately after a forward pass to bait the opponent and unlock the other side.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de verdediging in opbouwsituaties; wees beschikbaar voor de korte oplossing en kies de lange bal alleen wanneer de situatie dat afdwingt.',
                        'en' => 'Coach the defence in build-up situations; be available short and only pick the long ball when forced.',
                    ],
                ],
            ],
            [
                'code'              => 'AO-05',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'opbouwen',
                'title'             => [
                    'nl' => 'Het derdemansprincipe — bij elke combinatie zoeken we de derde man',
                    'en' => 'Third-man principle — every combination looks for the third man',
                ],
                'explanation' => [
                    'nl' => 'Omdat we vooruit willen voetballen en tot doelpunten willen komen is het belangrijk dat zich altijd één of meer spelers aanbieden als derde man. Het liefst zien we dat in diepteloop-acties achter de verdediging van de tegenstander, maar ook vooruit tussen de linies kan een optie zijn.',
                    'en' => 'Because we want to play forward and score, there must always be one or more players offering as the third man. Ideally that means runs in behind the opponent defence, but a forward run between the lines is equally good.',
                ],
                'team_guidance' => [
                    'nl' => 'We willen aanvallend spelen: ruimtes die de tegenstander achter de verdediging of tussen de linies laat, benutten we met loopacties en snelle passing. Loopacties van een derde man zijn lastig te verdedigen — de tegenstander moet snel keuzes maken en dat geeft ons opties.',
                    'en' => 'We attack: spaces the opponent leaves behind the defence or between the lines we exploit with runs and quick passing. Third-man runs are hard to defend because the opponent must choose quickly, which opens options for us.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Om een derde man in de diepte te creëren komen buitenspelers regelmatig naar binnen of zakken iets in. Dat geldt ook voor de spits, zodat een aanvallende middenvelder de vrijgekomen ruimte kan benutten voor een loopactie.',
                        'en' => 'To free a third-man run in behind, wingers come inside or drop a touch. The striker does the same so an attacking midfielder can attack the space.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Verdedigende middenvelders zijn samen met de buitenspelers de motor van dit principe. Speel de bal op de vragende speler zodat uit een kaats of combinatie de derde man kan worden gevonden. Zelf wachten of proberen de derde man te bereiken lukt zelden.',
                        'en' => 'Defensive midfielders together with the wingers drive this principle. Feed the demanding player so a layoff or combination opens the third man. Waiting or trying to be the third yourself rarely works.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Vind de derde man tussen de linies door eerst een driehoekje te maken met een verdedigende middenvelder. Vleugelverdedigers kunnen zelf de derde man zijn en lopen dus in vertrouwen. Centrale verdedigers geven het startsein door in te dribbelen.',
                        'en' => 'Find the third man between the lines by first making a triangle with a defensive midfielder. Full-backs can be the third man and run on trust. Centre-backs trigger the move by dribbling in.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach het overzicht; herken vroeg waar de derdemansoplossing kan ontstaan zodat je de juiste aansluiting voorbereidt.',
                        'en' => 'Coach the overview; recognise where the third-man solution can appear so you can prepare the right backup.',
                    ],
                ],
            ],

            // ── Aanvallen / Scoren ─────────────────────────────────
            [
                'code'              => 'AS-01',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'scoren',
                'title'             => [
                    'nl' => 'Bezetting voor de goal bij voorzetten — sluiten met voldoende mensen aan',
                    'en' => 'Box presence on crosses — arrive with enough bodies to score',
                ],
                'explanation' => [
                    'nl' => 'Omdat wij veel via de zijkanten willen aanvallen, is een goede bezetting voor het doel essentieel op het moment dat er een voorzet komt. Verwachte posities: eerste paal vaak 9, tweede paal vaak 10, zestienmeter vaak 6 of 8, punt zestienmeter contrakant vaak een buitenspeler.',
                    'en' => 'Because we attack down the wings, box presence on a cross is essential. Typical positions: near post often 9, far post often 10, edge of the 16 often 6 or 8, far edge often the opposite winger.',
                ],
                'team_guidance' => [
                    'nl' => 'Twee zaken zijn belangrijk bij een voorzet: bezetting voor de goal en restverdediging. Beide moeten op orde zijn om de kans op succes (zelf scoren of tegenkansen voorkomen) te vergroten. De technische uitvoering van de voorzet is essentieel.',
                    'en' => 'Two things matter on a cross: box presence and rest-defence. Both must be set so we maximise the chance to score and to prevent counters if we don\'t. The cross technique itself is essential.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Loop diagonaal de zestien in zodat je makkelijker scoort. Bij voorzetten moeten er minimaal twee aanvallers in de zestien zijn of komen, anders is voetballen verstandiger dan voorzet geven.',
                        'en' => 'Make diagonal runs into the box so finishing is easier. At least two attackers must be (or be arriving) in the box, otherwise keep the ball.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Twee van de drie centrale middenvelders sluiten in het beste geval aan bij een voorzet. De meest aanvallende middenvelder kiest positie in de zestien, de andere bijsluitende middenvelder neemt de zestienmeter voor zijn rekening; de overgebleven middenvelder zorgt voor balans.',
                        'en' => 'Ideally two of the three central midfielders join the cross. The most attacking sits in the box, the second covers the edge of the 16; the remaining midfielder protects balance.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Organiseer de restverdediging — geen of minimale ondertal-situatie bij een tegenaanval na een afgeslagen voorzet.',
                        'en' => 'Organise rest-defence — no (or minimal) numerical disadvantage on a counter after a cleared cross.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de restverdediging en wees klaar om hoog uit te komen op een eventuele lange counterbal.',
                        'en' => 'Coach rest-defence and be ready to come off your line on any long counter ball.',
                    ],
                ],
            ],
            [
                'code'              => 'AS-02',
                'team_function_key' => 'aanvallen',
                'team_task_key'     => 'scoren',
                'title'             => [
                    'nl' => 'Mik laag in de hoek — binnenkant binnen de 16, koppen via een stuit',
                    'en' => 'Aim low in the corner — inside foot inside the 16, headers via a bounce',
                ],
                'explanation' => [
                    'nl' => 'Bij het afwerken op het doel willen we dat de keeper de bal in ieder geval moet tegenhouden. Daarom gebruiken we binnen de zestien onze binnenkant en mikken laag in de hoek. Bij koppen proberen we via een stuit op de grond te koppen. Schoten van buiten de zestien kunnen met de wreef worden genomen.',
                    'en' => 'On a finish we want to force the keeper to make a save. So inside the 16 we use the inside of the foot and aim low in the corner. On headers we try to bounce the ball down. Outside the 16 we can shoot with the laces.',
                ],
                'team_guidance' => [
                    'nl' => '"Nooit geschoten is altijd mis" — we stimuleren elkaar in het afwerken. Soms kiezen we daardoor voor een schot waar een pass ook had gekund; dat nemen we voor lief.',
                    'en' => '"You miss every shot you don\'t take" — we encourage each other to finish. Sometimes that means we shoot where a pass was on; that\'s a price we accept.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Vooral aanvallers schieten en koppen op doel (los van spelhervattingen). Rust in de afwerking is belangrijk — kijk om je heen voor je tijd. Aanvallers die vanaf de zijkant naar binnen komen kunnen vaak de lange hoek zoeken.',
                        'en' => 'Attackers do most of the shooting and heading (set-pieces aside). Calm finishing matters — scan for time before you shoot. Attackers cutting in from the wing often pick the far corner.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Middenvelders die in schietpositie komen, mogen niet te wild en hard willen schieten. Laag in de contrahoek is vaak een goede keuze.',
                        'en' => 'Midfielders arriving in shooting positions must avoid wild, overpowered shots. Low in the far corner is often the right pick.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Vleugelverdedigers die naar binnen snijden zoeken het best laag de lange hoek als er niemand voor het doel is om de bal breed op te leggen.',
                        'en' => 'Full-backs cutting inside should pick the far corner low when there is no square option to lay it off.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach het afwerken en de afronding op trainingen — herhaling is hier de sleutel.',
                        'en' => 'Coach finishing and follow-through at training — repetition is the key here.',
                    ],
                ],
            ],

            // ── Omschakelen / Aanvallen → Verdedigen ──────────────
            [
                'code'              => 'OV-01',
                'team_function_key' => 'omschakelen_naar_verdedigen',
                'team_task_key'     => 'overgang_balverlies',
                'title'             => [
                    'nl' => 'Balverlies eigen helft — dichtstbijzijnde direct druk, rest kort dekken',
                    'en' => 'Loss of possession on our half — nearest player presses, others mark tight',
                ],
                'explanation' => [
                    'nl' => 'Bij balverlies op eigen helft moet de dichtstbijzijnde speler direct druk geven op de bal. De overige spelers maken het veld kleiner en dekken hun directe tegenstander kort, zonder de diepte-loopactie te vergeten. We proberen de tegenstander in balbezit op te sluiten.',
                    'en' => 'On loss of possession in our half, the nearest player presses immediately. The others compress the field and mark their direct opponent tight while watching the run in behind. We try to lock the opponent in.',
                ],
                'team_guidance' => [
                    'nl' => 'Maak het veld compact (kort en smal) zodra we de bal verliezen op eigen helft. Eerste doel: niet uitgespeeld worden. Tweede doel: voorkomen dat de tegenstander naar voren speelt. Derde doel: bal veroveren.',
                    'en' => 'Compact the field (short and narrow) the moment we lose possession on our half. First goal: don\'t get played through. Second: prevent forward play. Third: win the ball back.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Sluit tegenstanders van achteren op zodra de middenvelders of verdedigers van de tegenstander aan de bal komen.',
                        'en' => 'Close down opponents from behind once their midfielders or defenders take possession.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Dek kort, maar laat je niet uitspelen. De aanval ophouden is belangrijker dan de bal veroveren — verdediging komt in positie en de aanvallers kunnen meedoen met druk geven.',
                        'en' => 'Mark tight but don\'t get played past. Slowing the attack matters more than winning it — the defence sets up and attackers help press.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Communiceer goed zodat een diepe bal niet direct tot gevaar leidt. Wees scherp om kort te dekken zodra je tegenstander in de voeten wordt aangespeeld.',
                        'en' => 'Communicate so a ball in behind doesn\'t become dangerous straight away. Stay sharp to mark tight as soon as your opponent receives.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach luid; kom indien nodig vroeg uit op ballen achter de verdediging.',
                        'en' => 'Coach loud; come off your line early on balls in behind when needed.',
                    ],
                ],
            ],
            [
                'code'              => 'OV-02',
                'team_function_key' => 'omschakelen_naar_verdedigen',
                'team_task_key'     => 'overgang_balverlies',
                'title'             => [
                    'nl' => 'Balverlies helft tegenstander — bij coaching direct druk, hoog aansluiten',
                    'en' => 'Loss of possession in opponent half — on cue press immediately, team pushes up',
                ],
                'explanation' => [
                    'nl' => 'Bij balverlies op de helft van de tegenstander geven we waar mogelijk direct hoog druk op de speler in balbezit. Als team sluiten we aan tot de middenlijn en proberen we de tegenstander te dwingen tot een ongerichte lange bal.',
                    'en' => 'When we lose possession in the opponent half, we press the ball immediately whenever possible. The team pushes up to the halfway line and forces the opponent into an unstructured long ball.',
                ],
                'team_guidance' => [
                    'nl' => 'Wees scherp op momenten van balverlies. Veel en duidelijke coaching kan paniek bij de tegenstander uitlokken. Sta wel zo dat een in paniek weggetrapte bal niet alsnog gevaarlijk wordt.',
                    'en' => 'Be sharp at the moment of loss. Loud coaching can trigger panic at the other team. But stay positioned so a panic clearance can\'t become dangerous.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'NIET uit laten spelen en GEEN overtreding maken zijn het belangrijkst. Bal naar achter laten we vrijer dan bal naar voren. Voorkom inspelen volgende linie en het gericht zoeken van de voorste linie.',
                        'en' => 'Don\'t get played past and don\'t commit a foul. We allow the ball backward more freely than forward. Cut the pass to the next line and the targeted ball to the front line.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Half-half staan: direct kort kunnen zetten als de tegenstander wordt ingespeeld, maar bij een lange bal naar voren ook de tweede bal kunnen veroveren.',
                        'en' => 'Take a half-and-half stance: tight to press if the opponent is fed, but ready to win the second ball on a long clearance.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Wees scherp op de lange bal — niet tot 1:1 of ondertal laten leiden. Keeper goed meedoen, ook in de coaching. Inspeelpass direct kort verdedigen; centrale verdediger kan timen om voor de man te komen / in te stappen.',
                        'en' => 'Stay sharp on the long ball — never let it become 1v1 or numerical inferiority. Keeper actively involved, including coaching. Defend the receiving pass tight; the centre-back times stepping in front of the man.',
                    ],
                    'keeper' => [
                        'nl' => 'Sta hoog en coach de lijn; wees klaar om uit te komen op de lange bal.',
                        'en' => 'Stand high and coach the line; be ready to come off your line on the long ball.',
                    ],
                ],
            ],
            [
                'code'              => 'OV-03',
                'team_function_key' => 'omschakelen_naar_verdedigen',
                'team_task_key'     => 'overgang_balverlies',
                'title'             => [
                    'nl' => 'Druk zetten — dwing naar de zijkant, laat bal naar achter in principe vrij',
                    'en' => 'When pressing — force the wing, leave the ball backward in principle',
                ],
                'explanation' => [
                    'nl' => 'Tijdens het druk zetten anticiperen we op de tegenstander. Volle bak druk en alle wegen afsluiten zorgt vaak voor een lange bal naar voren — prima mits de verdedigers goed staan. Soms is het beter de tegenstander te dwingen tot een keuze waarbij wij geen kans weggeven: dwingen naar de zijkant of de bal naar achter in eerste instantie vrij laten.',
                    'en' => 'When pressing we anticipate. Full press with all paths cut usually triggers a long forward ball — fine if our defenders are set. Sometimes it\'s better to force a choice that doesn\'t give a chance away: push toward the wing or initially leave the backward ball free.',
                ],
                'team_guidance' => [
                    'nl' => 'Bij druk zetten geven we de tegenstander de mogelijkheid de bal naar achter of zijkant uit te halen. Volg de initiële druk op met aansluiten en opsluiten zodat we de bal alsnog kunnen veroveren en er vooruit geen aanspeelmogelijkheden zijn.',
                    'en' => 'When pressing we give the opponent the option to play backward or wide. Follow up the initial press with stepping up and locking in so we still win the ball and no forward option exists.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Kies tussen druk geven (waarbij niet duidelijk is welke bal de tegenstander gaat spelen) en gecontroleerd uit laten halen naar achter / zijkant — dat tweede heeft de voorkeur.',
                        'en' => 'Pick between full press (with the next ball uncertain) and controlled allowance to play backward or wide — the latter is preferred.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Belangrijkste taak: niet uitgespeeld worden. Het dwingen van de tegenstander naar één kant of naar achter is daarbij ideaal. Communiceer veel en sluit aan bij de druk zettende aanvallers.',
                        'en' => 'Key task: don\'t get played past. Forcing the opponent to one side or backward is ideal. Communicate plenty and link up with the pressing forwards.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Reken in eerste instantie op het ergste — een ongerichte lange bal die op een gevaarlijke positie valt. Maar coach erop dat we de tegenstander terugdringen, en sluit aan richting de middenlijn.',
                        'en' => 'Plan for the worst — an unstructured long ball into a dangerous spot. But coach that we are pushing the opponent back, and step up toward the halfway line.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de aansluiting en sta hoog wanneer we het team naar voren duwen.',
                        'en' => 'Coach the line\'s push-up and stand high when the team advances.',
                    ],
                ],
            ],

            // ── Verdedigen / Storen ──────────────────────────────
            [
                'code'              => 'VS-01',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Lokken door het midden óf dwingen naar de zijkant met dicht midden',
                    'en' => 'Bait through the middle or force to the wing with the centre closed',
                ],
                'explanation' => [
                    'nl' => 'Bij het storen op helft tegenstander hebben we twee hoofdmanieren. (1) De minst voetballende centrale verdediger laten indribbelen door het midden, hem onder druk zetten en de rest vastzetten. (2) Het midden juist dichthouden en een van de twee vleugelverdedigers (de minst voetballende) laten aanspelen, hem onder druk zetten en hem dwingen tot balverlies.',
                    'en' => 'Pressing in the opponent half has two main modes. (1) Bait the weaker-on-the-ball centre-back through the middle, pressure him there and pin the rest. (2) Keep the middle closed and bait one of the two full-backs (the weaker one), pressure him and force a turnover.',
                ],
                'team_guidance' => [
                    'nl' => 'Spreek goed af welke manier van druk geven wordt uitgevoerd. Dit hangt af van de tegenstander, de stand, de fase in de wedstrijd en andere omstandigheden.',
                    'en' => 'Agree clearly on which press to run. It depends on opponent, score, game phase and the situation.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Buitenspelers spelen de hoofdrol. Ze stellen zich zo op dat een back kan worden aangespeeld bij de tweede manier, en knijpen goed bij de eerste manier zodat geen middenvelder vrij komt om door de centrale verdediger te worden ingespeeld. Spits en 10 werken samen in de eerste manier.',
                        'en' => 'Wingers play the main role. They sit so a full-back can be fed in mode 2, and pinch in mode 1 so no midfielder can be reached by the centre-back. Striker and 10 cooperate in mode 1.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Met name de aanvallende middenvelder heeft een belangrijke rol: hij zet op het juiste moment en met de juiste intensiteit de indribbelende speler onder druk. De overige middenvelders zorgen voor het vast- en dichtzetten van tegenstanders en passlijnen.',
                        'en' => 'Especially the attacking midfielder is central: he times the press on the dribbling defender. The other midfielders pin opponents and lanes.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Kantel richting balkant, loop iets op op het moment van drukzetten, en coach de middenvelders. Let op diepgaande middenvelders!',
                        'en' => 'Tilt to the ball side, step up at the press moment, and coach the midfielders. Watch midfield runners!',
                    ],
                    'keeper' => [
                        'nl' => 'Sta hoog en lees diepe ballen vroeg.',
                        'en' => 'Stand high and read balls in behind early.',
                    ],
                ],
            ],
            [
                'code'              => 'VS-02',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Bewegen mee met de bal — iedereen heeft zijn functie',
                    'en' => 'Move with the ball — everyone has their role',
                ],
                'explanation' => [
                    'nl' => 'Bij het storen van de opbouw, waar dan ook op het veld, willen we het speelveld én de onderlinge ruimtes klein houden. Daarom bewegen we mee met de bal — voor alle spelers.',
                    'en' => 'When disrupting the build-up, anywhere on the field, we keep both the field and the gaps small. So we move with the ball — every player.',
                ],
                'team_guidance' => [
                    'nl' => 'Wanneer de tegenstander aan de bal is maken we het veld klein in de richting waarin de bal wordt gespeeld. Naar links → rechterkant knijpt en omgekeerd. Bal naar achter → we sluiten als team naar voren aan. Tegenstander ver op onze helft → de voorste linie zakt mee in.',
                    'en' => 'When the opponent has the ball we shrink the field toward the side the ball is on. Ball left → right side pinches, and vice versa. Ball back → team pushes up. Opponent deep in our half → front line drops in.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Buitenspelers knijpen vooral. De spits zakt in en sluit zijn directe tegenstander van achter op. Bij een terugspeelbal naar de keeper kunnen we volle druk zetten — aanvallers spelen daarbij de hoofdrol.',
                        'en' => 'Wingers pinch. The striker drops and closes his direct opponent from behind. On a back-pass to the keeper we can full press — attackers play the lead.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Schuif zowel links-rechts als voor-achter om de ruimtes tussen de linies te bewaken. Belangrijke coachende rol.',
                        'en' => 'Shift left-right and front-back to protect the gaps between lines. Important coaching role.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Verdedigers (en keeper) zorgen vooral voor het aansluiten naar voren als de tegenstander de bal naar achter verplaatst. Houd de lijn in de gaten en duw het elftal op.',
                        'en' => 'Defenders (and keeper) primarily push up when the opponent moves the ball backward. Watch the line and push the team forward.',
                    ],
                    'keeper' => [
                        'nl' => 'Sta hoog en houd de lijn naar voren bewegend zonder gaten te laten.',
                        'en' => 'Stay high and keep the line moving forward without leaving gaps.',
                    ],
                ],
            ],
            [
                'code'              => 'VS-03',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Balverlies helft tegenstander → het liefst direct druk',
                    'en' => 'Loss in opponent half → ideally press straight away',
                ],
                'explanation' => [
                    'nl' => 'Bij balverlies op de helft van de tegenstander kan het interessant zijn om direct druk op de bal te hebben. Bij herovering zijn we dichtbij het doel van de tegenstander en kunnen we sneller tot kansen komen. Daarnaast staat de tegenstander direct na het omschakelen vaak nog niet helemaal in positie.',
                    'en' => 'After a loss in the opponent half, immediate pressure on the ball can pay off. If we win it back we\'re close to goal. Just after the turnover the opponent often isn\'t set yet.',
                ],
                'team_guidance' => [
                    'nl' => 'Als het team goed in de wedstrijd zit, fit is en omstandigheden het toelaten, is direct storen vaak de beste manier. Doe het wél als team — als niet iedereen meedoet voetbalt de tegenstander er makkelijk doorheen.',
                    'en' => 'When the team is sharp, fit and conditions permit, pressing now is often best. Do it as a team — if anyone opts out the opponent plays straight through.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Laat je niet uitspelen en zorg dat de tegenstander geen kans krijgt op een gerichte diepe bal of het inspelen van de volgende linie.',
                        'en' => 'Don\'t get played past and deny any targeted ball in behind or pass to the next line.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Coach de aanvallers zodat zij naast druk op de bal ook de juiste passlijnen eruit halen. Voorkom korte driehoekjes.',
                        'en' => 'Coach the attackers so along with pressure on the ball, the right lanes get cut. Prevent short triangles.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Sta klaar om diepe ballen te verdedigen, houd een hoge en gelijke lijn; de keeper coacht hierin.',
                        'en' => 'Be ready to defend balls in behind, hold a high level line; keeper coaches.',
                    ],
                    'keeper' => [
                        'nl' => 'Sta hoog buiten de zestien om de lange bal weg te koppen of -trappen.',
                        'en' => 'Stand high off your line to head/clear the long ball.',
                    ],
                ],
            ],
            [
                'code'              => 'VS-04',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Onze helft is ónze helft — compact spelen, altijd druk op de bal',
                    'en' => 'Our half is OUR half — compact, always pressure on the ball',
                ],
                'explanation' => [
                    'nl' => 'Komt de tegenstander op onze helft te voetballen, dan zorgen we dat er weinig tijd en ruimte is om aanvallende keuzes te maken. We geven te allen tijde druk op de bal. Het doel is niet altijd de bal veroveren maar de tegenstander dwingen tot achteruit spelen — dan sluiten wij als team weer aan.',
                    'en' => 'When the opponent reaches our half, we deny time and space for attacking choices. There is always pressure on the ball. The goal isn\'t always to win it but to force backward play — and then we step up as a team.',
                ],
                'team_guidance' => [
                    'nl' => 'Communicatie en discipline zijn de succesfactoren. Iedereen draagt zijn steentje bij. Elke keer dat de directe tegenstander wordt aangespeeld geven we druk op zijn balbezit; zo krijgt hij geen tijd voor loopacties of het zien daarvan.',
                    'en' => 'Communication and discipline drive success. Everyone contributes. Each time a direct opponent receives we press his possession; he gets no time for runs or to spot them.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Geef druk vooruit zonder teveel uit positie te lopen. Niet uit laten spelen; geef een tegenstander die diep gaat goed over aan een ploeggenoot — je krijgt vaak een uitstappende speler ervoor terug.',
                        'en' => 'Press forward without losing your position. Don\'t get played past; hand off a deep runner cleanly to a teammate — you usually get a stepping-up opponent in return.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Coach en snij passlijnen af zodat de tegenstander geen linie kan overslaan. Houd de centrale positie bezet zodat het midden dicht blijft.',
                        'en' => 'Coach and cut passing lanes so the opponent can\'t skip a line. Keep the central position occupied so the middle stays closed.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Verdedigers kantelen continu mee met de bal; zijkanten knijpen goed naar binnen.',
                        'en' => 'Defenders constantly tilt with the ball; the wide players pinch in.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de lijn en wees klaar om uit te komen.',
                        'en' => 'Coach the line and be ready to come off it.',
                    ],
                ],
            ],
            [
                'code'              => 'VS-05',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'storen',
                'title'             => [
                    'nl' => 'Verdedig in zone 16m–middenlijn — opsluiten van achter door 7/10/11/9',
                    'en' => 'Zonal defending 16m–halfway line — close down from behind via 7/10/11/9',
                ],
                'explanation' => [
                    'nl' => 'Op onze eigen helft verdedigen we in principe in zone tot aan de zestien meter. Daarbinnen mandekking. Buiten de zestien zorgen we door goede communicatie dat uitzakkende of kruisende spelers in de zone worden opgevangen — horizontaal én verticaal. De zones bewegen mee met de bal over het veld.',
                    'en' => 'On our own half we defend in zones up to the 16. Inside the 16 it\'s man-marking. Outside the 16 communication ensures that dropping or crossing opponents are picked up in the zone — both horizontally and vertically. The zones move with the ball.',
                ],
                'team_guidance' => [
                    'nl' => 'Laat jezelf niet zomaar of zonder coaching uit de zone lokken — essentieel bij deze manier van verdedigen. Communiceren met ploeggenoten is belangrijk; nog belangrijker is luisteren naar de coaching van anderen.',
                    'en' => 'Don\'t let yourself get pulled out of the zone without coaching — essential in this style. Communicate with teammates; even more important, listen to teammates\' coaching.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => '11/9/10/7 letten goed op wat de tegenstander doet om vrij te komen. Ondersteun verdedigers of middenvelders door van achter druk te geven nadat een tegenstander is ingespeeld.',
                        'en' => '11/9/10/7 watch how opponents free themselves. Support defenders or midfielders by pressing from behind once an opponent receives.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Communiceer en zorg dat er altijd iemand in de zone(s) voor de verdediging staat. Hiermee halen we inspeelpasses richting spits of inkomende buitenspelers eruit.',
                        'en' => 'Communicate and ensure someone always occupies the zone(s) in front of the defence. That snuffs out passes to the striker or arriving wingers.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Zorg voor minimaal 2, liefst 3, spelers in de zones direct voor de zestien. Eventueel geassisteerd door verdedigende middenvelders.',
                        'en' => 'Make sure 2, ideally 3, players occupy the zones just outside the 16. Defensive midfielders can assist.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach scherp en wees klaar voor de bal in behind als de lijn doorschuift.',
                        'en' => 'Coach sharply and be ready for balls in behind as the line steps up.',
                    ],
                ],
            ],

            // ── Verdedigen / Doelpunten voorkomen ─────────────────
            [
                'code'              => 'VV-01',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'doelpunten_voorkomen',
                'title'             => [
                    'nl' => 'In en rond de 16: spelers worden niet meer overgegeven',
                    'en' => 'In and around the 16: no more handing off opponents',
                ],
                'explanation' => [
                    'nl' => 'Zonedekking is op het meeste van het veld de voorkeur, maar bij het voorkomen van doelpunten in en rond de zestien is er geen ruimte voor mismatches in overnemen of zwakke communicatie. Daarom geldt binnen en rond de zestien mandekking en geven we spelers in principe niet over.',
                    'en' => 'Zonal defending is preferred on most of the field, but in and around the 16 there is no room for mismatches on hand-offs or weak communication. So inside and around the 16 we mark man-to-man and we don\'t hand off opponents.',
                ],
                'team_guidance' => [
                    'nl' => 'Het team is voorbereid: in/rond de zestien volgen we tegenstanders in hun (loop)acties. Een tegenstander die slim vrij komt tussen overgeven en overnemen kan in vrijheid afronden — dat moeten we voorkomen.',
                    'en' => 'The team is prepared: in/around the 16 we follow opponents on their runs. An opponent who slips through during a hand-off can finish unopposed — we must prevent that.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'In principe kom je hier niet vaak; eventueel als een buitenspeler een vleugelverdediger helpt of een spits inzakt bij een spelhervatting. Meelopen met de tegenstander is dan cruciaal.',
                        'en' => 'You rarely arrive here; sometimes when a winger helps a full-back or the striker drops on a set-piece. Then tracking the opponent is critical.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Vooral de verdedigende middenvelders lopen mee in/rond de zestien als een aanvallende middenvelder voor het doel komt.',
                        'en' => 'Especially the defensive midfielders track in/around the 16 when an attacking midfielder arrives in front of goal.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Verdedigers in mandekking volgen hun tegenstander rond/binnen de zestien — zeker bij voorzetten na een 1:1 actie of na een kantwissel.',
                        'en' => 'Defenders in man-marking follow their opponent around/inside the 16 — especially on crosses after a 1v1 or after a switch.',
                    ],
                    'keeper' => [
                        'nl' => 'Communiceer beslissingen snel en duidelijk; coach mandekking versus zone bij spelhervattingen.',
                        'en' => 'Communicate decisions quickly and clearly; coach man-marking versus zone on set-pieces.',
                    ],
                ],
            ],
            [
                'code'              => 'VV-02',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'doelpunten_voorkomen',
                'title'             => [
                    'nl' => 'Verdedigers maken in/rond de 16 fysiek contact met tegenstanders',
                    'en' => 'Defenders maintain physical contact in/around the 16',
                ],
                'explanation' => [
                    'nl' => 'Niet weten waar de tegenstander zich bevindt is de grootste reden voor tegendoelpunten in de zestien. Probeer (legaal) fysiek contact te hebben met de tegenstander zodat het direct duidelijk is wanneer hij beweegt.',
                    'en' => 'Losing track of an opponent is the biggest cause of conceded goals inside the 16. Use legal physical contact so it\'s instantly clear when he moves.',
                ],
                'team_guidance' => [
                    'nl' => 'Vooral bij spelhervattingen of voorzetten is het belangrijk de tegenstander op te zoeken en (ook) fysiek te hinderen waar mogelijk.',
                    'en' => 'Especially on set-pieces or crosses, it matters to track and (also) physically hinder where possible.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Aanvallers staan bekend om strafschoppen veroorzaken bij meeverdedigen. Het fysieke contact moet slim, niet opzichtig.',
                        'en' => 'Attackers tend to cause penalties when defending. Use physical contact smartly, never showily.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Tijdens loopacties van tegenstanders in de diepte of bij kruisen tussen aanvallers: oog hebben voor je tegenstander en voorkomen dat hij zich te makkelijk vrijloopt.',
                        'en' => 'During deep runs or attacker crosses: keep eyes on your opponent and stop him from peeling off too easily.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Centrale verdedigers vangen de spits(en) en diepgaande middenvelders fysiek op om te voorkomen dat ze te eenvoudig tot een doelpoging komen.',
                        'en' => 'Centre-backs absorb the striker(s) and deep midfield runners physically so they don\'t get clean attempts.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de fysieke duels en let op overtredingen.',
                        'en' => 'Coach the physical duels and watch for fouls.',
                    ],
                ],
            ],
            [
                'code'              => 'VV-03',
                'team_function_key' => 'verdedigen',
                'team_task_key'     => 'doelpunten_voorkomen',
                'title'             => [
                    'nl' => 'Voorkom 1:1 aan de zijkant — hulp van 3/4, 7/11 of 6/8',
                    'en' => 'Prevent 1v1 on the wing — help from 3/4, 7/11 or 6/8',
                ],
                'explanation' => [
                    'nl' => 'Indien mogelijk voorkomen we dat de buitenspelers van de tegenstander 1:1 komen te staan tegen onze vleugelverdedigers. Worden ze uitgespeeld en is de tegenstander vrij om een vervolg te kiezen, dan stijgt het risico op tegendoelpunten. We laten de tegenstander andere keuzes maken — bijvoorbeeld een voorzet die te blokken is, of de bal naar achter uithalen.',
                    'en' => 'When possible we prevent the opponent\'s wingers from getting 1v1 with our full-backs. If they win the duel and pick a follow-up freely, the risk of conceding rises. We force a different choice — a blockable cross or playing back.',
                ],
                'team_guidance' => [
                    'nl' => 'We werken samen om te voorkomen dat een tegenstander te makkelijk via de zijkant gevaarlijk wordt. Geef op tijd rugdekking of laat in overtal de tegenstander een andere keuze maken.',
                    'en' => 'We cooperate to prevent the opponent from threatening easily down the wing. Provide cover early or, with a numerical advantage, force a different choice.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Als de buitenspeler de vleugelverdediger zo bijstaat dat de tegenstander het gevoel heeft tegen 2 te staan, kiest hij vaak voor een andere oplossing — bijvoorbeeld de bal uithalen. Doel bereikt; de tegenstander wordt teruggedrongen.',
                        'en' => 'When the winger backs up the full-back so the opponent feels facing two, he often picks another option — playing back. Goal achieved; the opponent is pushed back.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Een verdedigende middenvelder kan de vleugelverdediger assisteren zodat de tegenstander tegen 2 staat — meestal kiest hij dan iets anders dan een actie.',
                        'en' => 'A defensive midfielder can support the full-back so the opponent faces two — usually he picks something other than a take-on.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Een centrale verdediger geeft rugdekking zodat de buitenspeler van de tegenstander geen actie maakt maar de bal uithaalt of een blokbare voorzet probeert.',
                        'en' => 'A centre-back covers so the opponent\'s winger drops the take-on for a clearance or a blockable cross.',
                    ],
                    'keeper' => [
                        'nl' => 'Coach de rugdekking en de positie van de vleugelverdediger duidelijk.',
                        'en' => 'Coach the cover and the full-back\'s position clearly.',
                    ],
                ],
            ],

            // ── Omschakelen / Verdedigen → Aanvallen ──────────────
            [
                'code'              => 'OA-01',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'Verovering helft tegenstander — direct de diepte zoeken indien mogelijk',
                    'en' => 'Win in opponent half — go in behind immediately if possible',
                ],
                'explanation' => [
                    'nl' => 'Bij verovering op de helft van de tegenstander willen we direct een kans creëren door diepte te zoeken — als het kan door het midden, vaak via de zijkanten waar meestal meer ruimte is. Anders houden we de bal in de ploeg.',
                    'en' => 'On a win in the opponent half we look to create a chance immediately by going in behind — through the middle if possible, more often via the wings where space lives. Otherwise keep possession.',
                ],
                'team_guidance' => [
                    'nl' => 'Sluit direct naar voren aan zodra je ziet dat er diep gespeeld wordt na omschakelen. Voldoende aansluiting naar de goal, met restverdediging in overtal.',
                    'en' => 'Push up the moment you see the depth being played after a turnover. Enough bodies into the box, rest-defence with a numerical advantage.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Spelers aan de zijkant zoeken in principe direct de breedte — voorzet voorbereiden — niet de bal aan de binnenkant en richting doel vragen. De spits loopt breed weg en rekent op een steekbal door het midden.',
                        'en' => 'Wide players seek width to set up a cross — not the inside-and-goalward request. The striker pulls wide and expects a through-ball through the middle.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Vaak zijn middenvelders de onderscheppers. Het liefst zoek je diepte aan de zijkanten, geen verticale diepe bal — er is meestal te weinig ruimte achter de defensie van de tegenstander (vaak onderschept de keeper deze ballen).',
                        'en' => 'Midfielders are often the interceptors. Prefer depth on the wings over a vertical ball in behind — usually too little room behind the back-line (and the keeper sweeps it).',
                    ],
                    'verdedigers' => [
                        'nl' => 'Staan onze verdedigers hoog en veroveren ze de bal op helft tegenstander, dan zoeken ook zij de zijkanten. Een onderschepte bal door het midden geeft een te grote counter-kans aan de tegenstander.',
                        'en' => 'If our defenders win the ball high in the opponent half, they too seek the wings. A ball through the middle that gets intercepted gives the opponent too easy a counter.',
                    ],
                    'keeper' => [
                        'nl' => 'Wees klaar om hoge ballen achter de verdediging in te grijpen.',
                        'en' => 'Be ready to intervene on long balls behind the defence.',
                    ],
                ],
            ],
            [
                'code'              => 'OA-02',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'Verovering eigen helft — bal in de ploeg houden, diepte alleen voor grote kans',
                    'en' => 'Win on our half — keep possession, depth only for a clear chance',
                ],
                'explanation' => [
                    'nl' => 'Bij een verovering op eigen helft maken we de keuze tussen balbezit houden en diepte zoeken; balbezit is hier belangrijker. Als we de bal direct weer verliezen na een diepe bal en het team aansluit naar voren, kan een gevaarlijke counter ontstaan. Balbezit kan ook rust brengen om daarna een betere aanval op te zetten.',
                    'en' => 'On a win on our half we choose between keeping possession and going long; possession matters more. Losing the ball immediately after a long ball while pushing up invites a dangerous counter. Possession also brings calm to set up a better attack.',
                ],
                'team_guidance' => [
                    'nl' => 'Bij balverovering op eigen helft maakt het team zich snel groot. Omdat naar voren lastig kan zijn (buitenspel), gebeurt dat ook naar achter — vooral verdedigers spelen daar een rol.',
                    'en' => 'On a win in our half the team quickly stretches. Because forward can be tricky (offside) it also happens backward — defenders play a key role.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'De aanvaller aan de balzijde maakt het veld breed en is beschikbaar in de voeten. De aanvaller aan de contrakant is naar binnen gekomen en is vaak vrij — die moet juist NIET bewegen. De spits maakt het veld groot maar is beschikbaar in de voeten.',
                        'en' => 'The attacker on the ball side stretches wide and shows for feet. The opposite-side attacker has tucked inside and is often free — he must NOT move. The striker stretches the field but stays available to feet.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Houd rekening met de bal die naar achter wordt uitgehaald; een verdedigende middenvelder kan tussen de centrale verdedigers komen. Ballen naar achter worden in de ruimte gespeeld waar naartoe gevoetbald moet worden.',
                        'en' => 'Anticipate the ball coming back; a defensive midfielder can drop between the centre-backs. Backward balls are played into the space we then attack toward.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Maak het veld breed en lang; centrale verdedigers zoeken veel ruimte zodat de verdedigende middenvelders aanspeelbaar zijn tussen de linies.',
                        'en' => 'Stretch the field wide and long; centre-backs seek lots of room so the defensive midfielders are available between the lines.',
                    ],
                    'keeper' => [
                        'nl' => 'Sta beschikbaar als terugspeel-uitlaatklep; coach de rust.',
                        'en' => 'Be available as a back-pass relief; coach the calm.',
                    ],
                ],
            ],
            [
                'code'              => 'OA-03',
                'team_function_key' => 'omschakelen_naar_aanvallen',
                'team_task_key'     => 'overgang_balwinst',
                'title'             => [
                    'nl' => 'Bij directe druk: bal zo snel mogelijk verplaatsen naar de andere kant',
                    'en' => 'Under immediate pressure: switch the ball to the other side fast',
                ],
                'explanation' => [
                    'nl' => 'Een voetbalveld is groot, ook voor de tegenstander. Kiest hij voor veel druk met veel spelers, dan kan hij niet het hele veld bestrijken. We profiteren door de bal zo snel mogelijk te verplaatsen naar de kant met ruimte. Liefst met verzorgd positiespel; eventueel met een (minder geplaatste) lange bal.',
                    'en' => 'A football pitch is big, even for the opponent. If they press with a lot of players, they can\'t cover the entire field. We profit by switching to the side that has space. Ideally with positional play; otherwise a (less precise) long ball.',
                ],
                'team_guidance' => [
                    'nl' => 'Niet in paniek raken bij druk. Er is ruimte beschikbaar — die ligt waarschijnlijk aan de andere kant. Spelers ver van de bal houden rekening met een lange bal naar de andere kant.',
                    'en' => 'Don\'t panic under pressure. Space is available — likely on the other side. Players away from the ball anticipate the long ball to the other side.',
                ],
                'line_guidance' => [
                    'aanvallers' => [
                        'nl' => 'Zijspelers houden rekening met een snelle kantwissel als er diep op eigen helft balbezit ontstaat. Vraag niet te diep — daar staat een tegenstander. Benut de ruimte die ontstaat doordat de middenvelder en aanvaller van de tegenstander naar binnen trekken.',
                        'en' => 'Wide players anticipate a quick switch when possession starts deep in our half. Don\'t demand too deep — an opponent is there. Use the space created when their midfielder and attacker pinch in.',
                    ],
                    'middenvelders' => [
                        'nl' => 'Probeer betrokken te raken in het positiespel om van kant te wisselen. Daar zal weinig ruimte zijn — coach dat er voor de lange bal kan worden gekozen.',
                        'en' => 'Try to engage in the positional play to switch sides. Space will be tight — coach the option of the long ball.',
                    ],
                    'verdedigers' => [
                        'nl' => 'Keuze tussen inspelen op een druk middenveld, overslaan via een lange bal, of via de keeper verplaatsen — keeper en spelers aan de andere kant moeten dat mogelijk maken.',
                        'en' => 'Choose between feeding a busy midfield, skipping via a long ball, or switching through the keeper — keeper and far-side players must make it possible.',
                    ],
                    'keeper' => [
                        'nl' => 'Wees beschikbaar voor de switch via achteruit; speel rustig en gericht door.',
                        'en' => 'Be available for the switch through the back; play calmly and accurately.',
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Set pieces ─────────────────────── */

    private function seedSetPieces( string $p, int $formation_id ): void {
        global $wpdb;
        $rows = $this->setPiecesData();
        foreach ( $rows as $row ) {
            $row['default_formation_id'] = $formation_id;
            $row['is_shipped']           = 1;
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_set_pieces WHERE slug = %s LIMIT 1", $row['slug']
            ) );
            $payload = [
                'kind_key'             => $row['kind_key'],
                'side'                 => $row['side'],
                'title_json'           => wp_json_encode( $row['title'] ),
                'bullets_json'         => wp_json_encode( $row['bullets'] ),
                'default_formation_id' => $formation_id,
            ];
            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_set_pieces", $payload, [ 'id' => $existing ] );
            } else {
                $payload['slug']       = $row['slug'];
                $payload['is_shipped'] = 1;
                $wpdb->insert( "{$p}tt_set_pieces", $payload );
            }
        }
    }

    private function setPiecesData(): array {
        return [
            [
                'slug'     => 'corner-attacking-far-post',
                'kind_key' => 'corner', 'side' => 'attacking',
                'title' => [ 'nl' => 'Corner aanvallend', 'en' => 'Attacking corner' ],
                'bullets' => [
                    'nl' => [
                        'Waar mogelijk indraaiend en strak in zone, afgewisseld met bal bij de tweede paal',
                        'Restverdediging +1 met 1 speler voor de spits(en)',
                        'Bezetting voor het doel: iemand voor/bij de keeper (geen overtreding)',
                        'Inlopen vanaf de 5 meter tot eerste paal',
                        'Inlopen vanaf de 11 meter tot 5 meter',
                        'Omloopactie naar de tweede paal',
                        'Minimaal 1 speler op de zestien — nooit balverlies daar',
                        'Variant: bij vrije speler met goed schot kan de bal op de zestien worden gegeven',
                    ],
                    'en' => [
                        'In-swinging and crisp into a zone where possible, mixed with a ball to the far post',
                        'Rest-defence +1, with one player ahead of the striker(s)',
                        'Box presence: someone in front of/near the keeper (no foul)',
                        'Runners from the 5m to the near post',
                        'Runners from the penalty spot into the 5m',
                        'Loop to the far post',
                        'At least one player at the edge of the 16 — never lose the ball there',
                        'Variant: if a free shooter is open at the 16, deliver the ball there',
                    ],
                ],
            ],
            [
                'slug'     => 'free-kick-direct-attacking',
                'kind_key' => 'free_kick_direct', 'side' => 'attacking',
                'title' => [ 'nl' => 'Vrije trap aanvallend — direct', 'en' => 'Attacking free kick — direct' ],
                'bullets' => [
                    'nl' => [
                        'Vrije trap binnen ~25 meter in principe direct op het doel',
                        'Linkerkant: rechtsbenige speler; rechterkant: linksbenige speler',
                        '2 tot 3 spelers inlopen, beide kanten van de muur (let op het juiste been)',
                        '1 speler maakt het veld breed om de tegenstander te lokken en eventueel voor een steekbal',
                        '2 spelers bij de bal om de tegenstander te laten twijfelen',
                        'Restverdediging +1 minimaal, met een speler voor de spits(en)',
                    ],
                    'en' => [
                        'Free kick within ~25m: a direct attempt by default',
                        'Left side: right-footer; right side: left-footer',
                        '2–3 runners on both sides of the wall (mind the right foot)',
                        '1 player stretches the field to bait the opponent and offer a through-ball',
                        '2 players over the ball to keep the opponent guessing',
                        'Rest-defence +1 minimum, with a player ahead of the striker(s)',
                    ],
                ],
            ],
            [
                'slug'     => 'free-kick-pass-attacking',
                'kind_key' => 'free_kick_pass', 'side' => 'attacking',
                'title' => [ 'nl' => 'Vrije trap aanvallend — voorzet', 'en' => 'Attacking free kick — pass / cross' ],
                'bullets' => [
                    'nl' => [
                        'Vrije trap indraaiend richting de tweede paal',
                        'Als niemand de bal raakt moet hij in het doel belanden',
                        'Toon initiatief: kijk/dreig met kort nemen',
                        'Zorg voor 2 spelers bij de bal — dan moet de tegenstander ook een speler opofferen',
                        'Loopacties in de ballijn en gestaffeld; over de eerste heen, bij de tweede raak',
                        'Eén speler ver bij de tweede paal',
                        'Restverdediging +1 minimaal, met een speler voor de spits(en)',
                    ],
                    'en' => [
                        'In-swinger toward the far post',
                        'If nobody touches it, the ball should end up in the net',
                        'Show initiative: look/threaten to take it short',
                        'Two players over the ball — the opponent must commit one too',
                        'Staggered runs in the ball\'s line; over the first, met by the second',
                        'One player camped at the far post',
                        'Rest-defence +1 minimum, with one ahead of the striker(s)',
                    ],
                ],
            ],
            [
                'slug'     => 'penalty-attacking',
                'kind_key' => 'penalty', 'side' => 'attacking',
                'title' => [ 'nl' => 'Penalty aanvallend', 'en' => 'Attacking penalty' ],
                'bullets' => [
                    'nl' => [
                        'Inlopen, rekening houden met een redding of de bal op de paal',
                        'Bij inlopen meedoen voor het doel om bij rebound breed te leggen',
                        'Linkerkant inlopen = linksbenig',
                        'Rechterkant inlopen = rechtsbenig',
                        'Restverdediging +1 minimaal, met een speler voor de spits(en)',
                    ],
                    'en' => [
                        'Run in, anticipating a save or a ball off the post',
                        'On the run-in, arrive in front of goal to lay off the rebound',
                        'Left run-in = left foot',
                        'Right run-in = right foot',
                        'Rest-defence +1 minimum, with a player ahead of the striker(s)',
                    ],
                ],
            ],
            [
                'slug'     => 'corner-defending',
                'kind_key' => 'corner', 'side' => 'defending',
                'title' => [ 'nl' => 'Corner verdedigend', 'en' => 'Defending corner' ],
                'bullets' => [
                    'nl' => [
                        'Keeper organiseert',
                        '1 (lange) speler op 5 meter',
                        '1 speler diep weg, aan de kant van de corner',
                        '1 speler voor de keeper als er een tegenstander bij staat',
                        'Palen bezet — bepaalt de keeper',
                        'Verdedigers op de beste kopper(s) van de tegenstander',
                        'Spelers die een tegenstander dekken lopen mee — niet afwachten in de zone',
                        'Altijd 1 speler in zone op de 5 meterlijn voor de keeper',
                    ],
                    'en' => [
                        'Keeper organises',
                        'One (tall) player at the 5m line',
                        'One player far from goal on the corner side',
                        'One player in front of the keeper if an opponent stands there',
                        'Posts occupied — keeper decides',
                        'Defenders on the opponent\'s best header(s)',
                        'Players marking opponents track them — don\'t wait in the zone',
                        'Always one player in a zone on the 5m line in front of the keeper',
                    ],
                ],
            ],
            [
                'slug'     => 'free-kick-direct-defending',
                'kind_key' => 'free_kick_direct', 'side' => 'defending',
                'title' => [ 'nl' => 'Vrije trap verdedigend — direct', 'en' => 'Defending free kick — direct' ],
                'bullets' => [
                    'nl' => [
                        'Keeper organiseert',
                        '1 speler voor de bal om snel/kort nemen te voorkomen (let op: geen geel)',
                        'Lange spelers in de muur — eerst aanvallers, dan middenvelders, dan verdedigers',
                        'Muur blijft staan, op tenen — niet springen',
                        'Iedereen mee terug in principe; voorkomen van een doelpunt is belangrijker dan een snelle counter',
                        'Meelopen voor de rebound',
                    ],
                    'en' => [
                        'Keeper organises',
                        'One player in front of the ball to prevent a quick/short take (mind the yellow)',
                        'Tall players in the wall — attackers first, then midfielders, then defenders',
                        'Wall stays standing, on toes — no jumping',
                        'Everyone tracks back by default; preventing the goal beats the quick counter',
                        'Track for the rebound',
                    ],
                ],
            ],
            [
                'slug'     => 'free-kick-pass-defending',
                'kind_key' => 'free_kick_pass', 'side' => 'defending',
                'title' => [ 'nl' => 'Vrije trap verdedigend — voorzet', 'en' => 'Defending free kick — pass / cross' ],
                'bullets' => [
                    'nl' => [
                        'Keeper organiseert',
                        '1 speler voor de bal om snel/kort nemen te voorkomen',
                        'Lange spelers in de muur — eerst aanvallers, dan middenvelders, dan verdedigers',
                        'Muur blijft staan, op tenen — niet springen',
                        'Iedereen mee terug in principe; voorkomen van een doelpunt is belangrijker dan een snelle counter',
                        'Meelopen voor de rebound',
                    ],
                    'en' => [
                        'Keeper organises',
                        'One player in front of the ball to prevent a quick take',
                        'Tall players in the wall — attackers, then midfielders, then defenders',
                        'Wall stays standing, on toes — no jumping',
                        'Everyone tracks back; preventing the goal beats the counter',
                        'Track for the rebound',
                    ],
                ],
            ],
            [
                'slug'     => 'penalty-defending',
                'kind_key' => 'penalty', 'side' => 'defending',
                'title' => [ 'nl' => 'Penalty verdedigend', 'en' => 'Defending penalty' ],
                'bullets' => [
                    'nl' => [
                        'Meelopen voor de rebound, groot gebied',
                        'Tegenstander blokken maar niet ten koste van zelf inlopen',
                        'Rebound tot een corner of naar de zijkant verwerken — niet naar voren in verband met blok-risico of tegen iemand aanschieten',
                    ],
                    'en' => [
                        'Track for the rebound across a big area',
                        'Block the opponent but never at the cost of your own run-in',
                        'Clear the rebound out for a corner or to the wing — not forward, where blocking or hitting a teammate is too likely',
                    ],
                ],
            ],
        ];
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

    private function positionsData(): array {
        return [
            [
                'jersey_number' => 1,
                'short' => [ 'nl' => 'K',     'en' => 'GK' ],
                'long'  => [ 'nl' => 'Keeper', 'en' => 'Goalkeeper' ],
                'attacking' => [
                    'nl' => [
                        'Doeltrap nemen — kort of lang; bij voorkeur korte spelhervatting om op te bouwen',
                        'Indien lang, duidelijk coachen van de verdediging om aan te sluiten (2 en 5 naar binnen, 3 en 4 aansluiten) vóór de doeltrap',
                        'Keuze: tempo maken of niet bij spelhervattingen',
                        'Opschuiven naar de laatste linie om ruimte achter de defensie te verkleinen',
                        'Juiste voortzetting bij balbezit: rollen, werpen, trappen',
                        'Verdediging leiden door coaching',
                    ],
                    'en' => [
                        'Take the goal kick — short or long; short build-up preferred',
                        'If long, clearly coach the defence to close in (2 and 5 inside, 3 and 4 stepping up) before the kick',
                        'Choice: tempo or no tempo at restarts',
                        'Push up to the last line to reduce space behind the defence',
                        'Correct restart with possession: roll, throw, kick',
                        'Lead the defence through coaching',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Organiseer en leid de verdediging tijdens opbouw of een dode spelsituatie (corner / vrije trap / inworp)',
                        'Snel reageren op een dieptepass van de tegenstander',
                        'Positie kiezen ten opzichte van de bal — meedoen met de aanval, links-rechts mee bewegen',
                        'Technisch goed verwerken (naar de zijkant) na een doelpoging',
                        'Duidelijk en tijdig ingrijpen en aangeven (coachen) bij uitkomen',
                    ],
                    'en' => [
                        'Organise and lead the defence during build-up or a dead-ball (corner / free kick / throw-in)',
                        'React quickly to opponent through-balls',
                        'Pick position relative to the ball — engage with the attack, slide left-right',
                        'Technically clean handling (to the wing) after a shot',
                        'Clear, timely intervention and call when coming off the line',
                    ],
                ],
            ],
            [
                'jersey_number' => 2,
                'short' => [ 'nl' => 'RB',  'en' => 'RB' ],
                'long'  => [ 'nl' => 'Rechtsback (vleugelverdediger)', 'en' => 'Right back (full-back)' ],
                'attacking' => [
                    'nl' => [
                        'Altijd denken: "wat als we de bal verliezen?"',
                        'Mogelijk maken van een goede opbouw bij balbezit van de keeper',
                        'Veld breed/diep houden of maken om aanspeelbaar te zijn — buitenspeler wegtrekken in de opbouw',
                        '"Open" opgesteld staan en de bal "open" aannemen om direct vooruit te kijken en spelen',
                        'Geen balverlies door breedtepasses naar de andere kant; geen vooruit-optie → spel verplaatsen via keeper of centrale verdedigers',
                        'Op het juiste moment aanbieden en opkomen langs de lijn — overtal op het middenveld of de actie doortrekken en de buitenspeler "overlappen"',
                        'Vroege voorzet kunnen geven om de aanvaller 1:1 te zetten',
                        'Zodra 4 in balbezit komt: iets inschuiven (driehoek met de half)',
                    ],
                    'en' => [
                        'Always think: "what if we lose the ball?"',
                        'Enable a good build-up when the keeper has possession',
                        'Stretch the field wide/deep to stay available — pull the winger away in the build-up',
                        'Stand "open" and receive "open" to look and play forward instantly',
                        'No loss of possession on horizontal switches across the goal; no forward option → switch via keeper or centre-backs',
                        'Show and overlap at the right moment — overload the midfield or carry the action and overlap the winger',
                        'Early cross to set the striker up 1v1',
                        'When 4 takes possession: tuck inside (triangle with the 6/8)',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Goede keuze tussen kort/scherp of positioneel dekken van de vleugelaanvaller — afhankelijk van: snelheid van de directe tegenstander, kwaliteit van de dieptepass van de tegenstander, vrijheid van de tegenstander om te passen',
                        'Geven van rugdekking aan het centrum: kantelen/knijpen naar de balkant',
                        'Bij 1:1 van de centrale verdediger rugdekking geven',
                        'Duel spelen in 1:1 — niet uitgespeeld worden is het belangrijkst; bal veroveren is bonus',
                        'Directe tegenstander scherp en kort dekken bij inspelen',
                    ],
                    'en' => [
                        'Pick between tight/sharp and positional marking on the wing attacker — based on: their speed, the quality of their through-ball, their freedom on the ball',
                        'Provide cover to the centre: tilt/pinch to the ball side',
                        'On a 1v1 by the centre-back, provide cover',
                        'Win the 1v1 — not getting played past is the priority; the win is a bonus',
                        'Mark the direct opponent tight on receiving',
                    ],
                ],
            ],
            [
                'jersey_number' => 3,
                'short' => [ 'nl' => 'CV',  'en' => 'CB' ],
                'long'  => [ 'nl' => 'Centrale verdediger', 'en' => 'Centre-back' ],
                'attacking' => [
                    'nl' => [
                        'Altijd denken: "wat als we de bal verliezen?"',
                        'Altijd proberen in voorwaartse richting passen — diepte voor breedte',
                        'Geen risicovolle passes; balverlies levert bijna altijd een tegenkans',
                        'Veld groot maken naar achter bij balbezit 6/8/10 en soms bij balbezit 2/5',
                        'Indien aanval doorzet (7/11/10/9 aan de bal): aansluiten naar middenveld; veld kort houden',
                        'Mogelijk maken om een goede opbouw te spelen bij balbezit keeper',
                        'Initiatief nemen om in te schuiven op middenveld — overtal in de opbouw creëren',
                        'Vooral in voorwaartse richting spelen — durven een linie over te slaan (spits aanspelen i.p.v. middenveld)',
                        'Coachen van verdedigers en middenveld',
                    ],
                    'en' => [
                        'Always think: "what if we lose the ball?"',
                        'Always try to pass forward — depth before width',
                        'No risky passes; losing the ball almost always concedes a chance',
                        'Stretch the field backward when 6/8/10 are on the ball, and sometimes when 2/5 are',
                        'If the attack progresses (7/11/10/9 on the ball), close in to midfield — keep the field short',
                        'Enable a good build-up when the keeper has possession',
                        'Take initiative to step into midfield — create a build-up overload',
                        'Mainly play forward — dare to skip a line (feed the striker rather than midfield)',
                        'Coach the defenders and midfield',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Keuze tussen zone- of mandekking op de spits van de tegenstander',
                        'Overnemen van de diepgaande middenvelder afhankelijk van situatie',
                        'Rugdekking aan 2/5 bij 1:1 in de achterste linie',
                        'Aanval ophouden in ondertal-situaties tegen aanvallers',
                        'Voorkomen van doelpunten: scherp dekken, duels winnen',
                        'Achterste lijn organiseren — buitenspel',
                    ],
                    'en' => [
                        'Pick zonal or man-marking on the opponent striker',
                        'Pick up the deep-running midfielder situationally',
                        'Cover for 2/5 on a 1v1 in the back line',
                        'Slow the attack in numerical-disadvantage situations against the attackers',
                        'Prevent goals: sharp marking, win duels',
                        'Organise the back line — offside',
                    ],
                ],
            ],
            [
                'jersey_number' => 4,
                'short' => [ 'nl' => 'CV',  'en' => 'CB' ],
                'long'  => [ 'nl' => 'Centrale verdediger', 'en' => 'Centre-back' ],
                'attacking' => [
                    'nl' => [
                        'Altijd denken: "wat als we de bal verliezen?"',
                        'Voorwaarts passen — diepte voor breedte',
                        'Geen risicovolle passes; balverlies hier kost vaak een tegenkans',
                        'Veld groot naar achter bij balbezit 6/8/10 en soms bij 2/5',
                        'Aansluiten naar middenveld bij doorzettende aanval — veld kort houden',
                        'Goede opbouw mogelijk maken bij balbezit keeper',
                        'Initiatief om in te schuiven op middenveld — overtal in de opbouw',
                        'Linie durven overslaan (spits inspelen i.p.v. middenveld)',
                        'Verdedigers en middenveld coachen',
                    ],
                    'en' => [
                        'Always think: "what if we lose the ball?"',
                        'Pass forward — depth before width',
                        'No risky passes; losses here usually concede',
                        'Stretch backward on 6/8/10 possession, sometimes on 2/5',
                        'Close in to midfield when the attack progresses — keep the field short',
                        'Enable a good build-up on keeper possession',
                        'Take initiative to step into midfield — build-up overload',
                        'Dare to skip a line (feed striker rather than midfield)',
                        'Coach defenders and midfield',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Zone- of mandekking op de spits',
                        'Diepgaande middenvelder overnemen afhankelijk van situatie',
                        'Rugdekking aan 2/5 bij 1:1 achterin',
                        'Tegenaanval ophouden bij ondertal',
                        'Doelpunten voorkomen — scherp dekken, duels winnen',
                        'Achterste lijn organiseren — buitenspel',
                    ],
                    'en' => [
                        'Zonal or man-marking on the striker',
                        'Pick up the deep-running midfielder situationally',
                        'Cover 2/5 on a 1v1 at the back',
                        'Slow the counter on numerical disadvantage',
                        'Prevent goals — sharp marking, win duels',
                        'Organise the back line — offside',
                    ],
                ],
            ],
            [
                'jersey_number' => 5,
                'short' => [ 'nl' => 'LB',  'en' => 'LB' ],
                'long'  => [ 'nl' => 'Linksback (vleugelverdediger)', 'en' => 'Left back (full-back)' ],
                'attacking' => [
                    'nl' => [
                        'Altijd denken: "wat als we de bal verliezen?"',
                        'Mogelijk maken van een goede opbouw bij balbezit keeper',
                        'Veld breed/diep houden of maken — buitenspeler wegtrekken in opbouw',
                        '"Open" opgesteld staan en bal "open" aannemen — direct naar voren kijken/spelen',
                        'Geen balverlies door breedtepasses; geen vooruit-optie → spel verplaatsen via keeper of centrale verdedigers',
                        'Op het juiste moment aanbieden en opkomen langs de lijn — overtal of overlap',
                        'Vroege voorzet kunnen geven om aanvaller 1:1 te zetten',
                        'Zodra 3 in balbezit komt: iets inschuiven (driehoek met de half)',
                    ],
                    'en' => [
                        'Always think: "what if we lose the ball?"',
                        'Enable a good build-up on keeper possession',
                        'Stretch the field wide/deep — pull the winger away in build-up',
                        'Stand "open" and receive "open" — instant forward look and play',
                        'No loss on horizontal switches; no forward option → switch via keeper or centre-backs',
                        'Show and overlap at the right moment — overload or overlap',
                        'Early cross to set up the striker 1v1',
                        'When 3 takes possession: tuck inside (triangle with the 6/8)',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Goede keuze tussen kort/scherp en positioneel dekken — afhankelijk van snelheid, dieptepass-kwaliteit en vrijheid van de tegenstander',
                        'Rugdekking aan het centrum: kantelen/knijpen naar de balkant',
                        'Rugdekking bij 1:1 van de centrale verdediger',
                        '1:1 spelen — niet uitgespeeld worden is het belangrijkst',
                        'Directe tegenstander scherp en kort dekken bij inspelen',
                    ],
                    'en' => [
                        'Pick tight/sharp or positional marking — based on speed, through-ball quality and opponent freedom',
                        'Cover to the centre: tilt/pinch to the ball side',
                        'Cover on the centre-back\'s 1v1',
                        'Play the 1v1 — don\'t get played past is the priority',
                        'Mark the direct opponent tight on receiving',
                    ],
                ],
            ],
            [
                'jersey_number' => 6,
                'short' => [ 'nl' => 'CDM', 'en' => 'CDM' ],
                'long'  => [ 'nl' => 'Verdedigende middenvelder', 'en' => 'Defensive midfielder' ],
                'attacking' => [
                    'nl' => [
                        'Derde man benutten in driehoek: met 2 en 7 / 5 en 11 / 4 en 10',
                        'Crosspass spelen naar de andere zijkant — inschakelen in de aanval; ondersteunend aan 7, 11, 10 en 9',
                        'Aanspeelbaar (onder de man komen) voor terugpas van 7 en 11',
                        'Bij eventuele diepgang of aansluiting van de andere middenvelder: zelf controlerend spelen — nooit allebei "weg"',
                        'Controle als 6/8 en 10 voor het doel komen of diep gaan',
                        'Assisteren bij opbouw vanuit de keeper — uitzakken op backpositie of tussen 3 en 4 in',
                    ],
                    'en' => [
                        'Use the third man in a triangle: with 2 and 7 / 5 and 11 / 4 and 10',
                        'Cross-pass to the other wing — engage in the attack; support 7, 11, 10 and 9',
                        'Available (under the man) for the back-pass from 7 and 11',
                        'When the other midfielder runs or joins, play the controlling role — never both "away"',
                        'Control when 6/8 and 10 arrive in front of goal or run deep',
                        'Assist build-up from the keeper — drop into a full-back spot or between 3 and 4',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Bij aanval over eigen kant: zone- of 1:1-duel spelen',
                        'Tegenstander voor je houden, dieptepass voorkomen',
                        'Vooruit verdedigen op de bal — positioneel verdedigen bij ondertal; tegenaanval ophouden, niet blind instappen',
                        'Na uitgespeeld te zijn, herstellen richting centrum',
                        'Directe tegenstander scherp en kort dekken bij inspelen',
                    ],
                    'en' => [
                        'On an attack down your side: play zonal or 1v1',
                        'Keep the opponent in front of you, deny the through-ball',
                        'Press forward on the ball — positional defending on numerical disadvantage; slow the counter, don\'t step in blindly',
                        'After being played past, recover toward the centre',
                        'Mark the direct opponent tight on receiving',
                    ],
                ],
            ],
            [
                'jersey_number' => 7,
                'short' => [ 'nl' => 'RW',  'en' => 'RW' ],
                'long'  => [ 'nl' => 'Aanvallende middenvelder rechts (buitenspeler)', 'en' => 'Right attacking midfielder (winger)' ],
                'attacking' => [
                    'nl' => [
                        'Zo breed (en soms) diep mogelijk spelen om de aanvalsopbouw ruimte te geven',
                        'Naar binnen komen (met of zonder bal) om de overlap van 2/5 mogelijk te maken',
                        'Vooractie en passeeractie maken om tot een voorzet te komen',
                        'Bij voorzetten van de andere kant in de zestien komen',
                        'Ballen in de voeten vragen voor een 1:1-actie of een kaats — altijd vooractie en goed coachen/afspraken maken',
                    ],
                    'en' => [
                        'Stretch the field wide (and sometimes deep) to give the build-up room',
                        'Come inside (with or without the ball) to free the 2/5 overlap',
                        'Pre-action and take-on to set up a cross',
                        'On a cross from the other side, arrive in the 16',
                        'Demand the ball to feet for a 1v1 or a layoff — always with pre-action and clear communication',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Inzakken; in eerste instantie vanuit middenveld spelen',
                        'Ruimtes klein maken — knijpen naar binnen als de bal aan de andere kant ligt',
                        'Afhankelijk van de manier van druk zetten: tegenstander vrij laten of jagen bij een fout inspelen',
                        'Bij opbouw aan eigen kant: diepte voorkomen en dwingen tot breedtepass of bal terug naar keeper',
                        'Bij opbouw via de andere kant: rugdekking voor 9 of knijpen naar het middenveld',
                        'In 1:1 de directe tegenstander onder druk zetten en dwingen tot een fout',
                    ],
                    'en' => [
                        'Drop in; play from midfield first',
                        'Compress the spaces — pinch inside when the ball is on the other side',
                        'Depending on the press style: leave the opponent free, or hunt on a misplaced pass',
                        'On opponent build-up on your side: deny depth and force a switch or back-pass to the keeper',
                        'On their build-up via the other side: cover for 9 or pinch into midfield',
                        'In 1v1 pressure the direct opponent and force a mistake',
                    ],
                ],
            ],
            [
                'jersey_number' => 8,
                'short' => [ 'nl' => 'CDM', 'en' => 'CDM' ],
                'long'  => [ 'nl' => 'Verdedigende middenvelder', 'en' => 'Defensive midfielder' ],
                'attacking' => [
                    'nl' => [
                        'Derde man benutten in driehoek: met 2 en 7 / 5 en 11 / 3 en 10',
                        'Crosspass naar de andere zijkant — ondersteunend aan 7, 11, 10 en 9',
                        'Aanspeelbaar voor terugpas van 7 en 11',
                        'Bij diepgang of aansluiting van de andere middenvelder: zelf controlerend spelen — nooit allebei "weg"',
                        'Controle bij voor-doel-actie of diepgang van 6/10',
                        'Assisteren bij opbouw vanuit keeper — uitzakken of tussen 3 en 4 in',
                    ],
                    'en' => [
                        'Use the third man: with 2 and 7 / 5 and 11 / 3 and 10',
                        'Cross-pass to the other wing — support 7, 11, 10 and 9',
                        'Available for the back-pass from 7 and 11',
                        'When the other midfielder runs or joins, play controlling — never both "away"',
                        'Control when 6/10 arrive in front of goal or run deep',
                        'Assist build-up — drop or sit between 3 and 4',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Bij aanval over eigen kant: zone- of 1:1-duel spelen',
                        'Tegenstander voor je houden, dieptepass voorkomen',
                        'Vooruit verdedigen op de bal — positioneel verdedigen bij ondertal',
                        'Na uitgespeeld te zijn, herstellen richting centrum',
                        'Directe tegenstander scherp en kort dekken bij inspelen',
                    ],
                    'en' => [
                        'On an attack down your side: zonal or 1v1',
                        'Keep the opponent in front, deny depth',
                        'Press the ball — positional on numerical disadvantage',
                        'After being played past, recover toward the centre',
                        'Mark the direct opponent tight on receiving',
                    ],
                ],
            ],
            [
                'jersey_number' => 9,
                'short' => [ 'nl' => 'ST',  'en' => 'ST' ],
                'long'  => [ 'nl' => 'Centrale spits', 'en' => 'Centre forward' ],
                'attacking' => [
                    'nl' => [
                        'Voortdurend in beweging — aanspeelbaar over de grond of hoog',
                        'Bij opbouw onder druk: vraag de bal in de voeten',
                        'Nooit recht richting de keeper diepte zoeken — schuin weglopen uit de rug van de centrale verdediger; alleen recht als de speler met de bal tijd en ruimte heeft',
                        'Agressief en scherp voor de goal — doelgericht',
                        'In de dekking aanspeelbaar zijn en de bal goed afschermen',
                        'Ruimte maken voor de overlappende 10',
                        'Kaatsen op de derde man bij dekking in de rug',
                        'Eerste of tweede paal kiezen bij voorzet — bij voorzetten altijd in de zestien',
                        'Beide centrale verdedigers binden door continu de laatste verdediger op te zoeken',
                    ],
                    'en' => [
                        'Keep moving — available on the ground or in the air',
                        'In build-up under pressure: demand the ball to feet',
                        'Never run directly toward the keeper — peel off the centre-back\'s back; only direct if the carrier has time and space',
                        'Aggressive and sharp in front of goal — direct',
                        'When marked, stay available and shield the ball well',
                        'Create space for the 10\'s overlap',
                        'Lay it off to the third man on back-cover',
                        'Pick the near or far post on a cross — always be in the 16',
                        'Tie up both centre-backs by constantly looking for the last defender',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Samen met 7/11 en 10 de opbouw verstoren — diepte voorkomen',
                        'Voorkom kantwissel als de opbouw bij de 2/5 van de tegenstander ligt',
                        'Niet te makkelijk in 1:1 uit laten spelen — opbouw vertragen en ophouden',
                        'Opbouw verstoren (vooral op 3 en 4) — tegenstander dwingen tot fouten',
                        'Gerichte lange pass eruit halen — dwingen tot een breedtepass',
                        'Bij fout inspelen tegenstander — jagen',
                    ],
                    'en' => [
                        'With 7/11 and 10 disrupt the build-up — deny depth',
                        'Prevent the switch when the opponent build-up is on their 2/5',
                        'Don\'t get played past easily in 1v1 — slow and contain the build-up',
                        'Disrupt the build-up (especially on 3 and 4) — force mistakes',
                        'Cut the targeted long ball — force a sideways switch',
                        'On a misplaced pass — hunt',
                    ],
                ],
            ],
            [
                'jersey_number' => 10,
                'short' => [ 'nl' => 'CAM', 'en' => 'CAM' ],
                'long'  => [ 'nl' => 'Aanvallende middenvelder centraal', 'en' => 'Central attacking midfielder' ],
                'attacking' => [
                    'nl' => [
                        'Spel verdelen door in balbezit te komen',
                        'Afvallende bal of tweede bal veroveren',
                        'Als 2e spits opkomen (er niet al staan)',
                        'Schieten vanuit de tweede lijn',
                        'In de opbouw zuinig op balbezit (vooral op eigen helft, geen acties maken)',
                        'Bij aanvalsopbouw op de helft van de tegenstander: creativiteit en durf — steekpass etc. Risico mag in balbezit hier groot zijn',
                        'Aanspeelbaar zijn voor 2/5 en 3/4',
                        'Niet in de speellijn van 9 staan — altijd schuin t.o.v. 9',
                        'Bijsluiten als 9 wordt ingespeeld zodat hij de bal kan laten vallen',
                        'Overlappen van 9 wanneer hij inzakt op het middenveld',
                        'Derde man bij 9 of 7 en 11',
                        'Bij voorzet positie kiezen op de eerste of tweede paal (samenwerking met 9)',
                    ],
                    'en' => [
                        'Distribute by getting on the ball',
                        'Win the second ball or the deflected ball',
                        'Arrive as the second striker (not starting there)',
                        'Shoot from the second line',
                        'Conservative with possession in build-up (especially in our half — no take-ons)',
                        'In opponent-half build-up: creativity and courage — through-balls etc. Risk on possession is allowed to be high here',
                        'Available for 2/5 and 3/4',
                        'Don\'t stand in 9\'s passing line — always diagonal to 9',
                        'Provide support so 9 can lay the ball off',
                        'Overlap 9 when he drops to midfield',
                        'Be the third man at 9 or 7 and 11',
                        'On a cross, pick the near or far post (cooperation with 9)',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Centrale verdedigers op het juiste moment en met de juiste intensiteit onder druk zetten — afhankelijk van manier van druk zetten',
                        'Positioneel verdedigen bij ondertal — aanval ophouden, dwingen tot breedtepass of bal achteruit',
                        'Rugdekking aan 7 en 11 (afhankelijk van waar de bal is)',
                        'Assisteren bij 6/8 — druk van achteren waar nodig',
                    ],
                    'en' => [
                        'Press the centre-backs at the right moment and with the right intensity — depending on the press style',
                        'Positional defending on numerical disadvantage — slow the attack, force a switch or back-pass',
                        'Cover 7 and 11 (depending on where the ball is)',
                        'Support 6/8 — pressure from behind where needed',
                    ],
                ],
            ],
            [
                'jersey_number' => 11,
                'short' => [ 'nl' => 'LW',  'en' => 'LW' ],
                'long'  => [ 'nl' => 'Aanvallende middenvelder links (buitenspeler)', 'en' => 'Left attacking midfielder (winger)' ],
                'attacking' => [
                    'nl' => [
                        'Zo breed (en soms) diep mogelijk spelen om de aanvalsopbouw ruimte te geven',
                        'Naar binnen komen (met of zonder bal) om de overlap van 2/5 mogelijk te maken',
                        'Vooractie en passeeractie om tot een voorzet te komen',
                        'Bij voorzetten van de andere kant in de zestien komen',
                        'Ballen in de voeten vragen voor 1:1-actie of kaats — altijd vooractie en goed coachen/afspreken',
                    ],
                    'en' => [
                        'Stretch wide (and sometimes deep) to give the build-up room',
                        'Come inside (with or without the ball) to free the 2/5 overlap',
                        'Pre-action and take-on to set up a cross',
                        'On a cross from the other side, arrive in the 16',
                        'Demand the ball to feet for a 1v1 or a layoff — always with pre-action and clear communication',
                    ],
                ],
                'defending' => [
                    'nl' => [
                        'Inzakken; in eerste instantie vanuit middenveld spelen',
                        'Ruimtes klein maken — knijpen naar binnen als de bal aan de andere kant ligt',
                        'Tegenstander vrij laten of jagen bij fout inspelen',
                        'Bij opbouw aan eigen kant: diepte voorkomen en dwingen tot breedtepass of bal terug naar keeper',
                        'Bij opbouw via de andere kant: rugdekking voor 9 of knijpen naar het middenveld',
                        'In 1:1 de directe tegenstander onder druk zetten',
                    ],
                    'en' => [
                        'Drop in; play from midfield first',
                        'Compress the spaces — pinch inside when the ball is on the other side',
                        'Leave the opponent free or hunt on a misplaced pass',
                        'On opponent build-up on your side: deny depth and force a switch or back-pass to the keeper',
                        'On their build-up via the other side: cover for 9 or pinch into midfield',
                        'In 1v1 pressure the direct opponent',
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Framework primer ─────────────────────── */

    private function seedFrameworkPrimer( string $p ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var(
            "SELECT id FROM {$p}tt_methodology_framework_primers
             WHERE is_shipped = 1 AND club_scope IS NULL LIMIT 1"
        );
        $payload = [
            'club_scope'                  => null,
            'title_json'                  => wp_json_encode( [
                'nl' => 'Het spelen van voetbal',
                'en' => 'Playing football',
            ] ),
            'tagline_json'                => wp_json_encode( [
                'nl' => 'Voetbalmodel, leerdoelen en factoren van invloed.',
                'en' => 'Football model, learning goals and influence factors.',
            ] ),
            'intro_json'                  => wp_json_encode( [
                'nl' => 'Dit raamwerk verbindt visie, formatie, spelprincipes, basistaken per positie en spelhervattingen tot één samenhangend model. Het is geen kant-en-klaar recept maar een denkkader: een aanzet tot keuzes maken die passen bij het te coachen team, de club, de staf en andere factoren van invloed.',
                'en' => 'This framework connects vision, formation, game principles, position basics and set pieces into a coherent model. It isn\'t a ready-made recipe — it\'s a thinking framework that prompts choices fitting the team, club, staff and other influencing factors.',
            ] ),
            'voetbalmodel_intro_json'     => wp_json_encode( [
                'nl' => 'Het voetbalmodel kent één spelbedoeling (winnen), drie teamfuncties (aanvallen, omschakelen, verdedigen) en twee teamtaken per teamfunctie (opbouwen + scoren in aanvallen; storen + doelpunten voorkomen in verdedigen). Onder die teamtaken liggen de voetbalhandelingen die de speler concreet uitvoert.',
                'en' => 'The football model has one game intent (winning), three team functions (attacking, transitioning, defending) and two team tasks per function (building + scoring in attacking; disrupting + preventing goals in defending). Below those team tasks sit the concrete football actions a player executes.',
            ] ),
            'voetbalhandelingen_intro_json' => wp_json_encode( [
                'nl' => 'Voetbalhandelingen zijn ingedeeld in een harde kern met balcontact (aannemen, passen, dribbelen, schieten, koppen) en zonder balcontact (vrijlopen, knijpen, jagen, dekken), aangevuld met ondersteunende vaardigheden als spelinzicht en communicatie.',
                'en' => 'Football actions split into a core group with the ball (receive, pass, dribble, shoot, head) and without the ball (move free, pinch, hunt, mark), supported by reading the game and communication.',
            ] ),
            'phases_intro_json'           => wp_json_encode( [
                'nl' => 'Het veld kennen we vier fasen toe in zowel aanvallen als verdedigen. Elke fase heeft een eigen hoofddoelstelling die de keuze van handelingen, posities en intensiteit kleurt.',
                'en' => 'We use four phases on both the attacking and the defending side. Each phase has its own primary objective that shapes the choice of actions, positions and intensity.',
            ] ),
            'learning_goals_intro_json'   => wp_json_encode( [
                'nl' => 'Per teamtaak formuleren we leerdoelen die de individuele speler vooruit helpen. Aan elk leerdoel hangen observeerbare handelingen waarmee een coach snel kan beoordelen of een speler verbetering laat zien.',
                'en' => 'For each team task we formulate learning goals that move the individual player forward. Every goal is paired with observable actions a coach can use to gauge progress at a glance.',
            ] ),
            'influence_factors_intro_json' => wp_json_encode( [
                'nl' => 'De manier van spelen en de mate van ontwikkeling hangen af van meerdere factoren. Sommigen zijn te beïnvloeden, anderen zijn extern en kunnen gedurende het seizoen veranderen. Bewuste keuzes per factor zijn essentieel om realistisch en gericht te ontwikkelen.',
                'en' => 'Style and pace of development depend on multiple factors. Some are within our control, others external and shifting over a season. Deliberate choices per factor are essential to develop realistically and on target.',
            ] ),
            'reflection_json'             => wp_json_encode( [
                'nl' => 'Reflecteren op visie, methodologie en keuzes is een continu proces — zowel na een wedstrijd als na een seizoen. Een goede reflectie vraagt om eerlijkheid, dataonderbouwing waar mogelijk, en bereidheid om aannames bij te stellen.',
                'en' => 'Reflecting on vision, methodology and choices is continuous — after a match and at season end. Good reflection asks for honesty, data where available, and a willingness to revise assumptions.',
            ] ),
            'future_json'                 => wp_json_encode( [
                'nl' => 'De toekomst van dit raamwerk: het levend document moet voortdurend worden aangevuld op basis van ervaringen, nieuwe inzichten en nieuwe spelers. Updates zijn welkom; vasthouden aan inzichten zonder evaluatie is dat niet.',
                'en' => 'The future of this framework: a living document, to be enriched continuously with experience, new insights and new players. Updates are welcome; clinging to old conclusions without evaluation is not.',
            ] ),
            'is_shipped'                  => 1,
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

    private function seedPhases( string $p, int $primer_id ): void {
        foreach ( $this->phasesData() as $row ) {
            $this->upsertPhase( $p, $primer_id, $row );
        }
    }

    private function upsertPhase( string $p, int $primer_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_phases
             WHERE primer_id = %d AND side = %s AND phase_number = %d AND is_shipped = 1 LIMIT 1",
            $primer_id, $row['side'], (int) $row['phase_number']
        ) );
        $payload = [
            'title_json' => wp_json_encode( $row['title'] ),
            'goal_json'  => wp_json_encode( $row['goal'] ),
            'sort_order' => (int) $row['sort_order'],
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

    private function phasesData(): array {
        return [
            // Aanvallen
            [ 'side' => 'attacking', 'phase_number' => 1, 'sort_order' => 1,
              'title' => [ 'nl' => 'Fase 1 — Aanvallen', 'en' => 'Phase 1 — Attacking' ],
              'goal'  => [
                  'nl' => 'Speler vrij spelen in fase 2, 3 of 4 met het gezicht naar het doel van de tegenstander.',
                  'en' => 'Free a player in phase 2, 3 or 4 facing the opponent goal.',
              ],
            ],
            [ 'side' => 'attacking', 'phase_number' => 2, 'sort_order' => 2,
              'title' => [ 'nl' => 'Fase 2 — Aanvallen', 'en' => 'Phase 2 — Attacking' ],
              'goal'  => [
                  'nl' => 'Verplaatsen van het spel naar fase 3 of 4 om tot een kans en doelpunt te komen.',
                  'en' => 'Move the play to phase 3 or 4 to create a chance and a goal.',
              ],
            ],
            [ 'side' => 'attacking', 'phase_number' => 3, 'sort_order' => 3,
              'title' => [ 'nl' => 'Fase 3 — Aanvallen', 'en' => 'Phase 3 — Attacking' ],
              'goal'  => [
                  'nl' => 'Door een actie, combinatie of pass zelf of een medespeler vrij laten komen in fase 4.',
                  'en' => 'Through a take-on, combination or pass, get yourself or a teammate free in phase 4.',
              ],
            ],
            [ 'side' => 'attacking', 'phase_number' => 4, 'sort_order' => 4,
              'title' => [ 'nl' => 'Fase 4 — Aanvallen', 'en' => 'Phase 4 — Attacking' ],
              'goal'  => [
                  'nl' => 'Het creëren van een kans en het scoren van doelpunten.',
                  'en' => 'Create the chance and score the goal.',
              ],
            ],
            // Verdedigen
            [ 'side' => 'defending', 'phase_number' => 1, 'sort_order' => 5,
              'title' => [ 'nl' => 'Fase 1 — Verdedigen', 'en' => 'Phase 1 — Defending' ],
              'goal'  => [
                  'nl' => 'Voorkomen dat de verdedigers van de tegenstander kunnen opbouwen.',
                  'en' => 'Prevent the opponent defenders from building up.',
              ],
            ],
            [ 'side' => 'defending', 'phase_number' => 2, 'sort_order' => 6,
              'title' => [ 'nl' => 'Fase 2 — Verdedigen', 'en' => 'Phase 2 — Defending' ],
              'goal'  => [
                  'nl' => 'Voorkomen dat het spel kan worden verplaatst naar de aanval door de tegenstander.',
                  'en' => 'Prevent the opponent from moving the play into the attack.',
              ],
            ],
            [ 'side' => 'defending', 'phase_number' => 3, 'sort_order' => 7,
              'title' => [ 'nl' => 'Fase 3 — Verdedigen', 'en' => 'Phase 3 — Defending' ],
              'goal'  => [
                  'nl' => 'Voorkomen van het creëren van kansen door de tegenstander.',
                  'en' => 'Prevent the opponent from creating chances.',
              ],
            ],
            [ 'side' => 'defending', 'phase_number' => 4, 'sort_order' => 8,
              'title' => [ 'nl' => 'Fase 4 — Verdedigen', 'en' => 'Phase 4 — Defending' ],
              'goal'  => [
                  'nl' => 'Voorkomen van het creëren van een kans en het scoren door de tegenstander.',
                  'en' => 'Prevent the chance creation and the goal by the opponent.',
              ],
            ],
        ];
    }

    /* ─────────────────────── Learning goals ─────────────────────── */

    private function seedLearningGoals( string $p, int $primer_id ): void {
        foreach ( $this->learningGoalsData() as $row ) {
            $this->upsertLearningGoal( $p, $primer_id, $row );
        }
    }

    private function upsertLearningGoal( string $p, int $primer_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_learning_goals
             WHERE primer_id = %d AND slug = %s LIMIT 1",
            $primer_id, $row['slug']
        ) );
        $payload = [
            'side'          => $row['side'],
            'team_task_key' => $row['team_task_key'] ?? null,
            'title_json'    => wp_json_encode( $row['title'] ),
            'bullets_json'  => wp_json_encode( $row['bullets'] ),
            'sort_order'    => (int) $row['sort_order'],
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

    private function learningGoalsData(): array {
        return [
            // Aanvallen
            [
                'slug' => 'positiespel-verbeteren', 'side' => 'attacking', 'team_task_key' => 'opbouwen', 'sort_order' => 1,
                'title' => [ 'nl' => 'Positiespel verbeteren', 'en' => 'Improve positional play' ],
                'bullets' => [
                    'nl' => [
                        'Omgaan met ruimtes en positie kiezen',
                        'Wegdraai-bewegingen kiezen en toepassen',
                        'Passen op korte afstand',
                        'Aan- en meenemen (dribbelen) van/met de bal',
                    ],
                    'en' => [
                        'Manage space and pick a position',
                        'Pick and execute turns',
                        'Short-distance passing',
                        'First-touch and carrying (dribbling) of/with the ball',
                    ],
                ],
            ],
            [
                'slug' => 'dieptespel-verbeteren', 'side' => 'attacking', 'team_task_key' => 'opbouwen', 'sort_order' => 2,
                'title' => [ 'nl' => 'Dieptespel verbeteren', 'en' => 'Improve depth play' ],
                'bullets' => [
                    'nl' => [
                        'Omgaan met ruimtes en afspeelmogelijkheden creëren',
                        'Juiste keuzes maken in de passing',
                        'Passing op langere afstand',
                        'Momenten herkennen om diep te spelen',
                    ],
                    'en' => [
                        'Manage space and create passing options',
                        'Pick the right pass',
                        'Long-distance passing',
                        'Recognise moments to play in behind',
                    ],
                ],
            ],
            [
                'slug' => 'aanval-met-voorzet', 'side' => 'attacking', 'team_task_key' => 'scoren', 'sort_order' => 3,
                'title' => [ 'nl' => 'Aanval met voorzet', 'en' => 'Attack ending in a cross' ],
                'bullets' => [
                    'nl' => [
                        'Gerichte voorzet geven',
                        'Overzicht bewaren',
                        'Positie kiezen en afstemmen',
                        'Loopacties',
                    ],
                    'en' => [
                        'Deliver an accurate cross',
                        'Keep the overview',
                        'Pick and time positions',
                        'Runs',
                    ],
                ],
            ],
            [
                'slug' => '1-1-uitspelen', 'side' => 'attacking', 'team_task_key' => 'scoren', 'sort_order' => 4,
                'title' => [ 'nl' => '1:1 uitspelen', 'en' => '1v1 take-ons' ],
                'bullets' => [
                    'nl' => [
                        'Herkennen van een 1:1-situatie',
                        'Passeerbeweging kiezen en toepassen',
                        'Wegdraaibeweging kiezen en toepassen',
                        'Kapbeweging kiezen en toepassen',
                        'Juiste moment inzetten en snelheid van uitvoering',
                    ],
                    'en' => [
                        'Recognise a 1v1 situation',
                        'Pick and execute a take-on move',
                        'Pick and execute a turn-away',
                        'Pick and execute a chop',
                        'Time and execute at the right speed',
                    ],
                ],
            ],
            [
                'slug' => 'verbeteren-scoren', 'side' => 'attacking', 'team_task_key' => 'scoren', 'sort_order' => 5,
                'title' => [ 'nl' => 'Verbeteren scoren', 'en' => 'Improve finishing' ],
                'bullets' => [
                    'nl' => [
                        'Balaanname / controleren',
                        'Rust bewaren',
                        'Gericht schieten',
                        'Gericht koppen',
                    ],
                    'en' => [
                        'First touch / control',
                        'Stay calm',
                        'Place the shot',
                        'Place the header',
                    ],
                ],
            ],
            // Verdedigen
            [
                'slug' => 'verbeteren-storen', 'side' => 'defending', 'team_task_key' => 'storen', 'sort_order' => 6,
                'title' => [ 'nl' => 'Verbeteren storen', 'en' => 'Improve disrupting' ],
                'bullets' => [
                    'nl' => [
                        'Momenten herkennen van storen of juist niet storen',
                        'Vooruit verdedigen',
                        'Mandekking of zonedekking',
                        'Omgaan met positie kiezen',
                        'Communiceren met medespelers',
                    ],
                    'en' => [
                        'Recognise moments to press (and not to press)',
                        'Defend forward',
                        'Man-marking or zonal',
                        'Manage positioning',
                        'Communicate with teammates',
                    ],
                ],
            ],
            [
                'slug' => 'voorkomen-dieptespel', 'side' => 'defending', 'team_task_key' => 'storen', 'sort_order' => 7,
                'title' => [ 'nl' => 'Voorkomen dieptespel', 'en' => 'Prevent depth play' ],
                'bullets' => [
                    'nl' => [
                        'Vooruit verdedigen',
                        'Mandekking of zonedekking',
                        'Tackle op de bal',
                        'Linies op korte onderlinge afstand houden',
                        'Omgaan met positie kiezen',
                    ],
                    'en' => [
                        'Defend forward',
                        'Man-marking or zonal',
                        'Tackle on the ball',
                        'Keep the lines tight to each other',
                        'Manage positioning',
                    ],
                ],
            ],
            [
                'slug' => 'voorzet-verdedigen', 'side' => 'defending', 'team_task_key' => 'doelpunten_voorkomen', 'sort_order' => 8,
                'title' => [ 'nl' => 'Voorzet verdedigen', 'en' => 'Defend the cross' ],
                'bullets' => [
                    'nl' => [
                        'Overzicht bewaren',
                        'Positie kiezen',
                        'Afstemmen dekking',
                        'Bal verwerken en wegwerken',
                    ],
                    'en' => [
                        'Keep the overview',
                        'Pick a position',
                        'Coordinate marking',
                        'Process and clear the ball',
                    ],
                ],
            ],
            [
                'slug' => '1-1-verdedigen', 'side' => 'defending', 'team_task_key' => 'doelpunten_voorkomen', 'sort_order' => 9,
                'title' => [ 'nl' => '1:1 verdedigen', 'en' => 'Defend the 1v1' ],
                'bullets' => [
                    'nl' => [
                        'Vooruit verdedigen',
                        'Naar de bal kijken — verdedigende houding',
                        'Wanneer de tegenstander voor je te houden',
                        'Tackle / sliding op de bal',
                        'Tegenstander naar de kant dwingen',
                    ],
                    'en' => [
                        'Defend forward',
                        'Eyes on the ball — defensive posture',
                        'When to keep the opponent in front of you',
                        'Tackle / slide on the ball',
                        'Force the opponent to the wing',
                    ],
                ],
            ],
            [
                'slug' => 'verbeteren-doelpunten-voorkomen', 'side' => 'defending', 'team_task_key' => 'doelpunten_voorkomen', 'sort_order' => 10,
                'title' => [ 'nl' => 'Verbeteren doelpunten voorkomen', 'en' => 'Improve preventing goals' ],
                'bullets' => [
                    'nl' => [
                        'Bal en tegenstander in zicht houden',
                        'Tegenstander meelopen / volgen',
                        'Duels spelen',
                        'Onderling communiceren',
                        'Tackle / sliding',
                    ],
                    'en' => [
                        'Keep ball and opponent in sight',
                        'Track / follow the opponent',
                        'Play duels',
                        'Communicate with each other',
                        'Tackle / slide',
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Influence factors ─────────────────────── */

    private function seedInfluenceFactors( string $p, int $primer_id ): void {
        foreach ( $this->influenceFactorsData() as $row ) {
            $this->upsertInfluenceFactor( $p, $primer_id, $row );
        }
    }

    private function upsertInfluenceFactor( string $p, int $primer_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_influence_factors
             WHERE primer_id = %d AND slug = %s LIMIT 1",
            $primer_id, $row['slug']
        ) );
        $payload = [
            'title_json'       => wp_json_encode( $row['title'] ),
            'description_json' => wp_json_encode( $row['description'] ),
            'sub_factors_json' => isset( $row['sub_factors'] ) ? wp_json_encode( $row['sub_factors'] ) : null,
            'sort_order'       => (int) $row['sort_order'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_influence_factors", $payload, [ 'id' => $existing ] );
        } else {
            $payload['primer_id']  = $primer_id;
            $payload['slug']       = $row['slug'];
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_methodology_influence_factors", $payload );
        }
    }

    private function influenceFactorsData(): array {
        return [
            [
                'slug' => 'visie-club', 'sort_order' => 1,
                'title' => [ 'nl' => 'Visie club', 'en' => 'Club vision' ],
                'description' => [
                    'nl' => 'Heeft de club een eigen visie op speelwijze en speelwijze-ontwikkeling? Een expliciete clubvisie biedt richting en consistentie tussen teams; ontbrekend, dan kan de visie van de hoofdtrainer leidend zijn.',
                    'en' => 'Does the club have a stated vision on style of play and player development? An explicit vision gives direction and consistency between teams; without one, the head coach\'s vision can lead.',
                ],
            ],
            [
                'slug' => 'eigen-visie', 'sort_order' => 2,
                'title' => [ 'nl' => 'Eigen visie', 'en' => 'Own vision' ],
                'description' => [
                    'nl' => 'De expliciet geformuleerde visie van de hoofdtrainer op speelstijl, speelwijze en gewenste karaktereigenschappen — zoals vastgelegd in dit document.',
                    'en' => 'The head coach\'s articulated vision of style, way of playing and desired traits — as captured in this document.',
                ],
            ],
            [
                'slug' => 'spelers', 'sort_order' => 3,
                'title' => [ 'nl' => 'Spelers', 'en' => 'Players' ],
                'description' => [
                    'nl' => 'Vaardigheden, talenten en inzet van de spelers hebben directe invloed op het behalen van doelstellingen. Technische vaardigheid, tactisch inzicht, fysieke fitheid en motivatie bepalen de bereikbare ontwikkeling.',
                    'en' => 'Players\' skills, talents and effort directly drive what\'s achievable. Technique, tactical insight, fitness and motivation set the developmental ceiling.',
                ],
                'sub_factors' => [
                    [
                        'slug' => 'jeugdige-selectie',
                        'title' => [ 'nl' => 'Jeugdige selectie', 'en' => 'Young squad' ],
                        'description' => [
                            'nl' => 'Verschil in ontwikkelingsfase binnen de selectie — bijvoorbeeld JO17, JO18 en JO19 in één team — vraagt differentiatie in coaching.',
                            'en' => 'Different developmental stages within the squad — e.g. U17, U18 and U19 in one team — call for differentiated coaching.',
                        ],
                    ],
                    [
                        'slug' => 'talenten',
                        'title' => [ 'nl' => 'Talenten', 'en' => 'Talents' ],
                        'description' => [
                            'nl' => 'Door beperkte aantallen halen sommige spelers niet direct het gewenste niveau maar wel de selectie. Coachen op groei, niet op selectie alleen.',
                            'en' => 'Limited numbers mean some players reach the squad without yet reaching the desired level. Coach for growth, not just selection.',
                        ],
                    ],
                    [
                        'slug' => 'intrinsieke-motivatie',
                        'title' => [ 'nl' => 'Intrinsieke motivatie', 'en' => 'Intrinsic motivation' ],
                        'description' => [
                            'nl' => 'Wanneer er weinig competitiedruk is (bv. enige team in leeftijdscategorie), kan motivatie afnemen. Bewust werken aan persoonlijke doelen helpt.',
                            'en' => 'When competitive pressure is low (e.g. only team in the age group), motivation can drop. Deliberate work on personal goals helps.',
                        ],
                    ],
                    [
                        'slug' => 'fitheid',
                        'title' => [ 'nl' => 'Fitheid', 'en' => 'Fitness' ],
                        'description' => [
                            'nl' => 'Fysieke verschillen tussen leeftijden binnen één selectie zijn aanzienlijk; belastbaarheid en herstel verschillen per individu.',
                            'en' => 'Physical differences across ages within one squad are significant; load tolerance and recovery vary by individual.',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'staf', 'sort_order' => 4,
                'title' => [ 'nl' => 'Staf', 'en' => 'Coaching staff' ],
                'description' => [
                    'nl' => 'Kwaliteit en effectiviteit van coaching en begeleiding zijn essentieel. Aanwezigheid, een assistent, ondersteunende staf en samenwerking met andere teams beïnvloeden ontwikkeling.',
                    'en' => 'Quality and effectiveness of coaching and support matter greatly. Availability, an assistant, supporting staff and collaboration across teams shape development.',
                ],
                'sub_factors' => [
                    [
                        'slug' => 'aanwezigheid',
                        'title' => [ 'nl' => 'Aanwezigheid', 'en' => 'Availability' ],
                        'description' => [
                            'nl' => 'De hoofdtrainer is leidend in speelwijze-ontwikkeling. Veel afwezigheid (cursussen, werk) zet ontwikkeling onder druk.',
                            'en' => 'The head coach leads style of play development. Frequent absence (courses, work) strains progress.',
                        ],
                    ],
                    [
                        'slug' => 'assistent',
                        'title' => [ 'nl' => 'Assistent', 'en' => 'Assistant' ],
                        'description' => [
                            'nl' => 'Zonder een assistent is er minder aandacht beschikbaar voor individuele spelers of linies.',
                            'en' => 'Without an assistant, less attention is available for individuals or lines.',
                        ],
                    ],
                    [
                        'slug' => 'overige-staf',
                        'title' => [ 'nl' => 'Overige staf', 'en' => 'Other staff' ],
                        'description' => [
                            'nl' => 'Randzaken (kleding, vervoer, grensrechter) buiten de trainer leggen helpt het team beter te laten voetballen.',
                            'en' => 'Admin (kit, transport, linesmen) handled by others lets the coach focus on football.',
                        ],
                    ],
                    [
                        'slug' => 'andere-teams',
                        'title' => [ 'nl' => 'Andere teams', 'en' => 'Other teams' ],
                        'description' => [
                            'nl' => 'Spelers regelmatig lenen of uitlenen heeft niet altijd positief effect op teamontwikkeling.',
                            'en' => 'Frequent borrowing or lending of players doesn\'t always help team development.',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'teamdynamiek', 'sort_order' => 5,
                'title' => [ 'nl' => 'Teamdynamiek', 'en' => 'Team dynamics' ],
                'description' => [
                    'nl' => 'Een positieve teamdynamiek waarin spelers samenwerken, elkaar ondersteunen en durven te communiceren leidt tot betere prestaties en meer onderling vertrouwen.',
                    'en' => 'A positive team dynamic — collaboration, support and the courage to speak up — drives performance and trust.',
                ],
                'sub_factors' => [
                    [
                        'slug' => 'samenwerken',
                        'title' => [ 'nl' => 'Samenwerken', 'en' => 'Cooperation' ],
                        'description' => [
                            'nl' => 'Spelers die al jaren samen spelen kunnen beter samenwerken; nieuwe samenwerkingen vragen om bewuste opbouw.',
                            'en' => 'Players who have played together for years cooperate better; new ones need deliberate work.',
                        ],
                    ],
                    [
                        'slug' => 'teamspirit',
                        'title' => [ 'nl' => 'Teamspirit', 'en' => 'Team spirit' ],
                        'description' => [
                            'nl' => 'Teamgevoel is soms ongrijpbaar en hangt samen met andere omstandigheden zoals resultaten.',
                            'en' => 'Team spirit can be elusive and depends on context such as results.',
                        ],
                    ],
                    [
                        'slug' => 'aanwezigheid-spelers',
                        'title' => [ 'nl' => 'Aanwezigheid spelers', 'en' => 'Player attendance' ],
                        'description' => [
                            'nl' => 'In oudere jeugd moeten spelers kiezen tussen school en voetbal — regelmatige afwezigheid beïnvloedt continuïteit.',
                            'en' => 'Older youth must choose between school and football — frequent absences affect continuity.',
                        ],
                    ],
                    [
                        'slug' => 'karakters',
                        'title' => [ 'nl' => 'Karakters', 'en' => 'Personalities' ],
                        'description' => [
                            'nl' => 'Diverse karakters in een groep zijn een uitdaging om op één lijn te krijgen; ook een kans voor leiderschap.',
                            'en' => 'Diverse personalities are a challenge to align — and an opportunity for leadership.',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'speelniveau', 'sort_order' => 6,
                'title' => [ 'nl' => 'Speelniveau', 'en' => 'Competition level' ],
                'description' => [
                    'nl' => 'Niveau van de competitie en kwaliteit van de tegenstanders beïnvloeden resultaatdoelstellingen. Sterke tegenstand biedt uitdagingen én kansen voor groei.',
                    'en' => 'Competition level and opponent quality shape result targets. Strong opposition is both challenge and growth opportunity.',
                ],
                'sub_factors' => [
                    [
                        'slug' => 'competitie',
                        'title' => [ 'nl' => 'Competitie', 'en' => 'Competition' ],
                        'description' => [
                            'nl' => 'Kies de juiste balans tussen ontwikkelen en presteren. Een te laag of te hoog competitieniveau remt ontwikkeling.',
                            'en' => 'Pick the right balance between development and results. A level that\'s too low or too high stalls development.',
                        ],
                    ],
                    [
                        'slug' => 'tegenstanders',
                        'title' => [ 'nl' => 'Tegenstanders', 'en' => 'Opponents' ],
                        'description' => [
                            'nl' => 'Verschil in tegenstanders binnen de competitie kan groot zijn — pas voorbereidingen daarop aan.',
                            'en' => 'Differences between opponents in a league can be large — adjust preparation accordingly.',
                        ],
                    ],
                    [
                        'slug' => 'andere-mogelijkheden',
                        'title' => [ 'nl' => 'Andere mogelijkheden', 'en' => 'Other opportunities' ],
                        'description' => [
                            'nl' => 'Oefenwedstrijden bieden ruimte om niveau aan te passen, mits er capaciteit is om ze te organiseren.',
                            'en' => 'Friendlies allow level adjustment if there\'s capacity to organise them.',
                        ],
                    ],
                    [
                        'slug' => 'verschillende-verwachtingen',
                        'title' => [ 'nl' => 'Verschillende verwachtingen', 'en' => 'Differing expectations' ],
                        'description' => [
                            'nl' => 'Spelers met succes uit voorgaande seizoenen kunnen verwachtingen hebben die niet één-op-één waar te maken zijn.',
                            'en' => 'Players coming off success may carry expectations that won\'t simply repeat.',
                        ],
                    ],
                ],
            ],
            [
                'slug' => 'ondersteuning', 'sort_order' => 7,
                'title' => [ 'nl' => 'Ondersteuning uit club en ouders', 'en' => 'Support from club and parents' ],
                'description' => [
                    'nl' => 'Een ondersteunende omgeving versterkt ontwikkeling en prestaties. Faciliteiten en middelen vanuit de club, en betrokkenheid van ouders, spelen beide een rol.',
                    'en' => 'A supportive environment strengthens development and performance. Facilities and resources from the club, and parental involvement, both play a role.',
                ],
                'sub_factors' => [
                    [
                        'slug' => 'club',
                        'title' => [ 'nl' => 'Club', 'en' => 'Club' ],
                        'description' => [
                            'nl' => 'Doelstellingen, verwachtingen en beschikbare ondersteuning moeten in evenwicht zijn met elkaar.',
                            'en' => 'Goals, expectations and support need to balance.',
                        ],
                    ],
                    [
                        'slug' => 'middelen',
                        'title' => [ 'nl' => 'Middelen', 'en' => 'Resources' ],
                        'description' => [
                            'nl' => 'Velden, materialen, opleidingsmogelijkheden — alles wat nodig is om kwaliteit te leveren.',
                            'en' => 'Pitches, equipment, training opportunities — everything needed to deliver quality.',
                        ],
                    ],
                    [
                        'slug' => 'ouders',
                        'title' => [ 'nl' => 'Ouders', 'en' => 'Parents' ],
                        'description' => [
                            'nl' => 'Betrokken ouders die een gezonde voetbalcultuur ondersteunen, zonder de coachrol over te nemen.',
                            'en' => 'Involved parents who support a healthy football culture without taking over the coaching role.',
                        ],
                    ],
                    [
                        'slug' => 'overig',
                        'title' => [ 'nl' => 'Overig', 'en' => 'Other' ],
                        'description' => [
                            'nl' => 'Sponsoren, vrijwilligers, naburige clubs — context die ontwikkeling beïnvloedt.',
                            'en' => 'Sponsors, volunteers, neighbouring clubs — context that shapes development.',
                        ],
                    ],
                ],
            ],
        ];
    }

    /* ─────────────────────── Football actions ─────────────────────── */

    private function seedFootballActions( string $p ): void {
        global $wpdb;
        foreach ( $this->footballActionsData() as $row ) {
            $existing = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT id FROM {$p}tt_football_actions WHERE slug = %s LIMIT 1", $row['slug']
            ) );
            $payload = [
                'category_key'     => $row['category_key'],
                'name_json'        => wp_json_encode( $row['name'] ),
                'description_json' => wp_json_encode( $row['description'] ),
                'sort_order'       => (int) $row['sort_order'],
            ];
            if ( $existing > 0 ) {
                $wpdb->update( "{$p}tt_football_actions", $payload, [ 'id' => $existing ] );
            } else {
                $payload['slug']       = $row['slug'];
                $payload['is_shipped'] = 1;
                $wpdb->insert( "{$p}tt_football_actions", $payload );
            }
        }
    }

    private function footballActionsData(): array {
        return [
            // Met balcontact (harde kern)
            [ 'slug' => 'aannemen', 'category_key' => 'with_ball', 'sort_order' => 1,
              'name' => [ 'nl' => 'Aannemen', 'en' => 'Receive / first touch' ],
              'description' => [
                  'nl' => 'De bal beheerst aannemen en in één beweging klaarleggen voor de volgende handeling — open lichaamshouding, eerste aanraking richting open ruimte.',
                  'en' => 'Bring the ball under control and set it up for the next action in one motion — open body shape, first touch toward open space.',
              ],
            ],
            [ 'slug' => 'passen', 'category_key' => 'with_ball', 'sort_order' => 2,
              'name' => [ 'nl' => 'Passen', 'en' => 'Pass' ],
              'description' => [
                  'nl' => 'Korte en lange pass uitvoeren met de juiste techniek en gewicht; gericht inspelen op een medespeler in beweging.',
                  'en' => 'Short and long pass with proper technique and weight; deliver to a moving teammate.',
              ],
            ],
            [ 'slug' => 'dribbelen', 'category_key' => 'with_ball', 'sort_order' => 3,
              'name' => [ 'nl' => 'Dribbelen', 'en' => 'Dribble' ],
              'description' => [
                  'nl' => 'De bal aan de voet houden in beweging — meenemen en omspelen waar mogelijk, met afwisseling van tempo en richting.',
                  'en' => 'Keep the ball at your feet on the move — carry and beat opponents where possible, varying speed and direction.',
              ],
            ],
            [ 'slug' => 'schieten', 'category_key' => 'with_ball', 'sort_order' => 4,
              'name' => [ 'nl' => 'Schieten', 'en' => 'Shoot' ],
              'description' => [
                  'nl' => 'Gericht afronden — binnenkant binnen de zestien voor plaatsing, wreef voor afstandsschoten; rust en techniek boven kracht alleen.',
                  'en' => 'Finish purposefully — inside foot inside the 16 for placement, laces for distance; calm and technique over raw power.',
              ],
            ],
            [ 'slug' => 'koppen', 'category_key' => 'with_ball', 'sort_order' => 5,
              'name' => [ 'nl' => 'Koppen', 'en' => 'Head' ],
              'description' => [
                  'nl' => 'Aanvallend koppen via een stuit naar de grond; verdedigend hoog en ver wegkoppen.',
                  'en' => 'Attacking heads bounce down toward the ground; defensive heads go high and far.',
              ],
            ],
            // Zonder balcontact (harde kern)
            [ 'slug' => 'vrijlopen', 'category_key' => 'without_ball', 'sort_order' => 6,
              'name' => [ 'nl' => 'Vrijlopen', 'en' => 'Move free' ],
              'description' => [
                  'nl' => 'Beweging maken om aanspeelbaar te worden — schuin, in de diepte of tussen de linies — passend bij de fase en de positie.',
                  'en' => 'Move to become available — diagonal, in behind, or between the lines — fitting the phase and position.',
              ],
            ],
            [ 'slug' => 'knijpen', 'category_key' => 'without_ball', 'sort_order' => 7,
              'name' => [ 'nl' => 'Knijpen', 'en' => 'Pinch' ],
              'description' => [
                  'nl' => 'Naar binnen schuiven richting de bal-kant zodat onderlinge ruimtes klein blijven en de tegenstander geen vrijheid heeft.',
                  'en' => 'Slide inside toward the ball side so the gaps between teammates stay small and the opponent finds no freedom.',
              ],
            ],
            [ 'slug' => 'jagen', 'category_key' => 'without_ball', 'sort_order' => 8,
              'name' => [ 'nl' => 'Jagen', 'en' => 'Hunt / press' ],
              'description' => [
                  'nl' => 'Gericht druk geven op een opbouwende tegenstander; passlijnen afsnijden en de tegenstander dwingen tot een fout.',
                  'en' => 'Targeted pressure on an opponent in possession; cut passing lanes and force a mistake.',
              ],
            ],
            [ 'slug' => 'dekken', 'category_key' => 'without_ball', 'sort_order' => 9,
              'name' => [ 'nl' => 'Dekken', 'en' => 'Mark' ],
              'description' => [
                  'nl' => 'Tegenstander volgen of in de zone opvangen — kort dekken bij inspelen, ruimte beperken bij inkomende loopactie.',
                  'en' => 'Track an opponent or pick him up in the zone — mark tight on receiving, restrict space on an incoming run.',
              ],
            ],
            // Ondersteunend
            [ 'slug' => 'spelinzicht', 'category_key' => 'support', 'sort_order' => 10,
              'name' => [ 'nl' => 'Spelinzicht', 'en' => 'Reading the game' ],
              'description' => [
                  'nl' => 'Waarnemen, scannen en herkennen van patronen in het spel — koppeling aan teamtaak en teamfunctie maken op basis van wat er gebeurt.',
                  'en' => 'Observe, scan and recognise patterns — link your team task and function to what is unfolding.',
              ],
            ],
            [ 'slug' => 'communicatie', 'category_key' => 'support', 'sort_order' => 11,
              'name' => [ 'nl' => 'Communicatie', 'en' => 'Communication' ],
              'description' => [
                  'nl' => 'Verbaal en non-verbaal coachen op teamniveau — afstemmen van handelingen, het kort houden van afstand en het herkennen van risico\'s.',
                  'en' => 'Verbal and non-verbal coaching at team level — coordinate actions, keep distances tight and flag risks.',
              ],
            ],
        ];
    }
};
