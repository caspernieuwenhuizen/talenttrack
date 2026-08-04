<?php
/**
 * Migration 0206 — Complete the JO13-1 Hedel conversion (#2369, epic #2316).
 *
 * Two gaps from seed migration 0202:
 *
 *   1. The `1-4-3-3-jo13` formation never had `diagram_data_json`, so
 *      FormationDiagram fell back to a generic 1-4-2-3-1 on both the
 *      Formaties and Visie tabs. This sets the real 1-4-3-3 coordinates
 *      (derived from PPT slide 7, mapped into the diagram's 0–100 × 0–140
 *      coordinate system: top = opponent goal, bottom = own goal, GK ≈ y128).
 *
 *   2. The per-line sub-principes were folded into principle line_guidance
 *      text. This seeds them as first-class `tt_methodology_sub_principles`
 *      rows (table from migration 0205), scoped to the `jo13-1-hedel` set,
 *      grouped by phase (matching the phases 0202 seeded) and line.
 *
 * Idempotent: the formation update is by slug; each sub-principle is
 * upserted by its natural key (methodology_id + phase_side + phase_number
 * + line_key + sort_order) scoped to the set, so a re-run corrects rather
 * than duplicates.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    private const CLUB_ID = 1;

    public function getName(): string {
        return '0206_methodology_jo13_complete';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $this->fixFormationDiagram( $p );

        $set_id = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodologies WHERE club_id = %d AND slug = %s LIMIT 1",
            self::CLUB_ID, 'jo13-1-hedel'
        ) );
        if ( $set_id <= 0 ) return;

        foreach ( $this->subPrinciplesData() as $row ) {
            $this->upsertSubPrinciple( $p, $set_id, $row );
        }
    }

    /* ─────────────────────── Formation diagram ─────────────────────── */

    private function fixFormationDiagram( string $p ): void {
        global $wpdb;
        $diagram = [
            'positions' => [
                '1'  => [ 'x' => 50, 'y' => 128, 'label' => 'K' ],
                '5'  => [ 'x' => 14, 'y' => 104, 'label' => 'LB' ],
                '4'  => [ 'x' => 38, 'y' => 110, 'label' => 'CV' ],
                '3'  => [ 'x' => 62, 'y' => 110, 'label' => 'CV' ],
                '2'  => [ 'x' => 86, 'y' => 104, 'label' => 'RB' ],
                '6'  => [ 'x' => 50, 'y' => 86,  'label' => 'VH' ],
                '8'  => [ 'x' => 30, 'y' => 70,  'label' => 'CM' ],
                '10' => [ 'x' => 70, 'y' => 70,  'label' => 'CM' ],
                '11' => [ 'x' => 16, 'y' => 42,  'label' => 'LA' ],
                '9'  => [ 'x' => 50, 'y' => 26,  'label' => 'SP' ],
                '7'  => [ 'x' => 84, 'y' => 42,  'label' => 'RA' ],
            ],
        ];
        $wpdb->update(
            "{$p}tt_formations",
            [ 'diagram_data_json' => wp_json_encode( $diagram ) ],
            [ 'slug' => '1-4-3-3-jo13' ]
        );
    }

    /* ─────────────────────── Sub-principles ─────────────────────── */

    private function upsertSubPrinciple( string $p, int $set_id, array $row ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_methodology_sub_principles
             WHERE methodology_id = %d AND is_shipped = 1
               AND phase_side = %s AND phase_number = %d AND line_key = %s AND sort_order = %d
             LIMIT 1",
            $set_id, $row['phase_side'], (int) $row['phase_number'], $row['line_key'], (int) $row['sort_order']
        ) );
        $payload = [
            'methodology_id' => $set_id,
            'club_id'        => self::CLUB_ID,
            'phase_side'     => $row['phase_side'],
            'phase_number'   => (int) $row['phase_number'],
            'line_key'       => $row['line_key'],
            'title_json'     => wp_json_encode( $row['title'] ),
            'sort_order'     => (int) $row['sort_order'],
        ];
        if ( $existing > 0 ) {
            $wpdb->update( "{$p}tt_methodology_sub_principles", $payload, [ 'id' => $existing ] );
        } else {
            $payload['uuid']       = wp_generate_uuid4();
            $payload['is_shipped'] = 1;
            $wpdb->insert( "{$p}tt_methodology_sub_principles", $payload );
        }
    }

    /**
     * The JO13-1 sub-principles, one row per bullet from the PPT
     * (slides 13–31). Phase mapping matches the phases migration 0202
     * seeded: defending 1/2/3, transition 2 (V→A) and 3 (A→V), attacking
     * 1/2. The Dutch bullet is the `title`; EN is a light translation.
     *
     * @return array<int, array<string,mixed>>
     */
    private function subPrinciplesData(): array {
        $out = [];

        /* ── Verdedigen (1) — storen opbouw ── */
        $out = array_merge( $out, $this->rows( 'defending', 1, 'aanvallers', [
            [ 'Afschermen passlijnen richting middenvelders/aanvallers TP', 'Screen passing lanes toward the opponent midfielders/forwards' ],
            [ 'Bal naar bv nr 2 → contra-buitenspeler kantelt naar binnen richting de as', 'Ball to e.g. the 2 → the far winger tilts inside toward the axis' ],
            [ 'Druk zetten vol overgave, maximaal', 'Press with full commitment, maximal' ],
            [ 'Door je loopactie dwing je de TS een richting/zone op', 'Your run forces the opponent into a direction/zone' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'defending', 1, 'middenvelders', [
            [ 'Passlijnen afschermen richting aanvallers TP', 'Screen passing lanes toward the opponent forwards' ],
            [ 'Balgericht verdedigen zonder de zone om je heen te verliezen', 'Defend ball-oriented without losing the zone around you' ],
            [ 'Druk op de bal, weinig tijd en ruimte', 'Pressure the ball, little time and space' ],
            [ 'Voorkom kantwissel', 'Prevent the switch of play' ],
            [ 'nr 6+8 voor elkaar, nr 10 naast nr 9', 'The 6 and 8 cover for each other, the 10 beside the 9' ],
            [ 'Coachen/aanjagen van de aanvallers', 'Coach and drive the forwards' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'defending', 1, 'verdedigers', [
            [ 'Directe TS ingespeeld → doordekken', 'Direct opponent is fed → follow tight' ],
            [ 'Lange bal TP → altijd 1 verdediger in de rugdekking', 'Opponent long ball → always one defender providing cover' ],
            [ 'Ingedraaid staan (diepgaande spelers + medespelers, afstanden bewaken)', 'Stand turned in (mind runners + teammates, keep distances)' ],
            [ 'Meekantelen met de bal', 'Tilt with the ball' ],
        ] ) );

        /* ── Verdedigen (2) — rond de middenlijn ── */
        $out = array_merge( $out, $this->rows( 'defending', 2, 'aanvallers', [
            [ 'Passlijnen dicht naar middenvelders/aanvallers TP', 'Close passing lanes to the opponent midfielders/forwards' ],
            [ 'Via een looplijn een keuze afdwingen', 'Force a choice via your running line' ],
            [ 'Buitenspelers kantelen als de bal contra is', 'Wingers tilt across when the ball is on the far side' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'defending', 2, 'middenvelders', [
            [ 'Passlijnen dicht naar de aanvallers TP', 'Close passing lanes to the opponent forwards' ],
            [ 'Balgericht verdedigen', 'Defend ball-oriented' ],
            [ 'Druk op de bal', 'Pressure the ball' ],
            [ 'Coachen met/naar de aanvallers', 'Coach with/toward the forwards' ],
            [ 'Voorkom kantwissel', 'Prevent the switch of play' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'defending', 2, 'verdedigers', [
            [ 'Doordekken bij inspelen', 'Follow tight on receiving' ],
            [ 'Rugdekking bij lange bal', 'Provide cover on a long ball' ],
            [ 'Ingedraaid staan', 'Stand turned in' ],
            [ 'Meekantelen', 'Tilt with the ball' ],
            [ 'Coachen/aansturen van de middenvelders voor je', 'Coach and steer the midfielders in front of you' ],
        ] ) );

        /* ── Verdedigen (3) — eigen helft ── */
        $out = array_merge( $out, $this->rows( 'defending', 3, 'algemeen', [
            [ 'Op eigen helft altijd druk op de TS aan de bal', 'In our own half, always pressure the opponent on the ball' ],
            [ 'Passlijnen naar middenvelders/aanvallers afschermen', 'Screen passing lanes to midfielders/forwards' ],
            [ 'Bal in beweging = ploeg in beweging', 'Ball in motion = team in motion' ],
            [ 'Geen druk op de bal → NOOIT op buitenspel stappen', 'No pressure on the ball → NEVER step up for offside' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'defending', 3, 'verdedigers', [
            [ 'Mandekking rondom het 16-metergebied (verantwoordelijk voor je eigen man)', 'Man-marking around the box (responsible for your own man)' ],
            [ 'Backs/buitenspelers halen de voorzetten eruit', 'Full-backs/wingers cut out the crosses' ],
            [ 'In/rond het 16-metergebied geen domme overtredingen', 'No silly fouls in/around the box' ],
        ] ) );

        /* ── Omschakelen Verdedigen→Aanvallen (transition/2) ── */
        $out = array_merge( $out, $this->rows( 'transition', 2, 'algemeen', [
            [ 'Diepte (vooruit) vóór terug; breedte alleen als de bal 100% veilig is', 'Depth (forward) before backward; width only when the ball is 100% safe' ],
            [ 'Spelers vóór de bal tonen direct intentie richting doel TP (1-2 pakken diepte)', 'Players ahead of the ball show intent toward the opponent goal immediately (1-2 take depth)' ],
            [ 'In hoogste tempo vrijlopen om aanspeelbaar te zijn', 'Get free at top pace to be an option' ],
            [ 'Laatste linie aansluiten en restverdediging bewaken', 'The last line pushes up and guards rest-defence' ],
        ] ) );

        /* ── Aanvallen — opbouw eigen helft (attacking/1) ── */
        $out = array_merge( $out, $this->rows( 'attacking', 1, 'verdedigers', [
            [ 'Bal in de as houden, drukmoment bij de TP neerleggen', 'Keep the ball in the axis, place the pressing moment on the opponent' ],
            [ 'Hoog baltempo + hoge handelingssnelheid', 'High ball tempo + high speed of action' ],
            [ 'Nauwkeurige passing met een boodschap', 'Accurate passing with a message' ],
            [ 'Schuine ballen', 'Slanted passes' ],
            [ 'Tussen de linies spelen of een linie overslaan', 'Play between the lines or skip a line' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'attacking', 1, 'middenvelders', [
            [ 'Continu in beweging', 'Continuously moving' ],
            [ 'Schuin opengedraaid, bal meenemen van de plek, liefst vooruit', 'Open up at an angle, take the ball away from the spot, preferably forward' ],
            [ 'Hoge handelingssnelheid', 'High speed of action' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'attacking', 1, 'aanvallers', [
            [ 'Aanspeelbaar zijn (in de voet met TS dichtbij / tussen de linies / in de diepte)', 'Be an option (to feet with a marker close / between the lines / in behind)' ],
            [ 'Buitenspelers opengedraaid met gezicht naar je TS', 'Wingers open up facing your marker' ],
        ] ) );

        /* ── Aanvallen — eindfase / scoren (attacking/2) ── */
        $out = array_merge( $out, $this->rows( 'attacking', 2, 'algemeen', [
            [ 'Hoog baltempo, juiste been inspelen', 'High ball tempo, feed the right foot' ],
            [ 'Vind de vrije speler tussen de linies', 'Find the free player between the lines' ],
            [ 'Aan één kant voorbereiden om aan de andere kant uit te komen', 'Build on one side to come out on the other' ],
            [ 'Loopacties met én zonder bal achter de laatste linie', 'Runs with and without the ball behind the last line' ],
        ] ) );
        $out = array_merge( $out, $this->rows( 'attacking', 2, 'aanvallers', [
            [ 'Lage/halfhoge/hoge strakke voorzetten', 'Low/half-high/high driven crosses' ],
            [ 'Teruggetrokken voorzetten richting 11m/16m', 'Pulled-back crosses toward the penalty spot/box edge' ],
            [ 'Altijd minimaal 3 spelers in het 16-metergebied', 'Always at least 3 players in the box' ],
            [ '16 meter bespelen met 1 speler', 'Occupy the box with one player' ],
            [ 'In scoringspositie snel handelen en afronden', 'In a scoring position, act fast and finish' ],
            [ 'Restverdediging bewaken', 'Guard rest-defence' ],
        ] ) );

        /* ── Omschakelen Aanvallen→Verdedigen (transition/3) ── */
        $out = array_merge( $out, $this->rows( 'transition', 3, 'algemeen', [
            [ 'Na balverlies directe druk op de speler aan de bal (dichtstbijzijnde spelers)', 'On loss, immediate pressure on the player on the ball (the nearest players)' ],
            [ 'Overige spelers maken het veld klein (lengte + breedte, as dicht)', 'The other players make the field small (length + width, axis closed)' ],
            [ 'Speelt de TS eruit → veld klein richting eigen keeper, TS ophouden', 'If the opponent beats it → shrink the field toward our keeper, delay the opponent' ],
        ] ) );

        return $out;
    }

    /**
     * Expand a per-line bullet list into upsert rows, numbering sort_order
     * from 1 within the (phase, line) group.
     *
     * @param array<int, array{0:string,1:string}> $bullets [nl, en] pairs
     * @return array<int, array<string,mixed>>
     */
    private function rows( string $side, int $number, string $line, array $bullets ): array {
        $rows = [];
        $i = 0;
        foreach ( $bullets as $b ) {
            $i++;
            $rows[] = [
                'phase_side'   => $side,
                'phase_number' => $number,
                'line_key'     => $line,
                'sort_order'   => $i,
                'title'        => [ 'nl' => $b[0], 'en' => $b[1] ],
            ];
        }
        return $rows;
    }
};
