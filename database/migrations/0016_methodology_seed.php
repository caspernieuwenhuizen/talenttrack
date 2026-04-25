<?php
/**
 * Migration 0016 — Methodology seed (#0027 sample template).
 *
 * Inserts one shipped row of each catalogue type as a worked example
 * Casper can use as a template before authoring the full content from
 * `07. Voetbalmethode.pdf`. Idempotent: each insert checks for an
 * existing row by slug or code first.
 *
 * What gets seeded:
 *   - 1 formation: 1:4:2:3:1, with `diagram_data_json` populated.
 *   - 1 position card: keeper (#1) on that formation.
 *   - 1 principle: AO-01 sample (Aanvallen / Opbouwen — sample copy
 *     drawn loosely from the methodology PDF; Casper revises in
 *     wp-admin via Clone & Edit if he wants to hand-craft).
 *   - 1 set piece: aanvallende corner sample.
 *   - 1 vision: shipped sample reflecting the PDF's articulation.
 *
 * Casper's Sprint C work (~12h authoring, separate PR) replaces the
 * placeholder content with the full PDF translation + adds the
 * remaining 10 position cards, 17-19 more principles, 6 more set
 * pieces.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

use TT\Infrastructure\Database\Migration;

return new class extends Migration {

    public function getName(): string {
        return '0016_methodology_seed';
    }

    public function up(): void {
        global $wpdb;
        $p = $wpdb->prefix;

        $formation_id = $this->seedFormation( $p );
        if ( $formation_id <= 0 ) return; // formation table missing — bail; the migration will rerun

        $this->seedPosition( $p, $formation_id );
        $this->seedPrinciple( $p, $formation_id );
        $this->seedSetPiece( $p, $formation_id );
        $this->seedVision( $p, $formation_id );
    }

    private function seedFormation( string $p ): int {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formations WHERE slug = %s LIMIT 1", '1-4-2-3-1'
        ) );
        if ( $existing > 0 ) return $existing;

        $diagram = [
            'positions' => [
                '1'  => [ 'x' => 50, 'y' => 124, 'label' => 'K' ],
                '2'  => [ 'x' => 82, 'y' => 102, 'label' => 'RB' ],
                '3'  => [ 'x' => 60, 'y' => 108, 'label' => 'CV' ],
                '4'  => [ 'x' => 40, 'y' => 108, 'label' => 'CV' ],
                '5'  => [ 'x' => 18, 'y' => 102, 'label' => 'LB' ],
                '6'  => [ 'x' => 60, 'y' => 78,  'label' => 'CDM' ],
                '8'  => [ 'x' => 40, 'y' => 78,  'label' => 'CDM' ],
                '7'  => [ 'x' => 80, 'y' => 50,  'label' => 'RW' ],
                '10' => [ 'x' => 50, 'y' => 50,  'label' => 'CAM' ],
                '11' => [ 'x' => 20, 'y' => 50,  'label' => 'LW' ],
                '9'  => [ 'x' => 50, 'y' => 24,  'label' => 'ST' ],
            ],
        ];
        $wpdb->insert( "{$p}tt_formations", [
            'slug'              => '1-4-2-3-1',
            'name_json'         => wp_json_encode( [ 'nl' => '1:4:2:3:1', 'en' => '4-2-3-1' ] ),
            'description_json'  => wp_json_encode( [
                'nl' => 'De grondformatie waarmee dit methodologiekader is uitgewerkt.',
                'en' => 'The base formation this methodology framework is built around.',
            ] ),
            'diagram_data_json' => wp_json_encode( $diagram ),
            'is_shipped'        => 1,
        ] );
        return (int) $wpdb->insert_id;
    }

    private function seedPosition( string $p, int $formation_id ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_formation_positions
             WHERE formation_id = %d AND jersey_number = %d AND is_shipped = 1 LIMIT 1",
            $formation_id, 1
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( "{$p}tt_formation_positions", [
            'formation_id'         => $formation_id,
            'jersey_number'        => 1,
            'short_name_json'      => wp_json_encode( [ 'nl' => 'Keeper',     'en' => 'Goalkeeper' ] ),
            'long_name_json'       => wp_json_encode( [ 'nl' => 'Keeper',     'en' => 'Goalkeeper' ] ),
            'attacking_tasks_json' => wp_json_encode( [
                'nl' => [
                    'Bouwt op via korte pass naar centrale verdediger of vleugelverdediger',
                    'Speelt diepteballen achter de verdediging als opbouw vastloopt',
                ],
                'en' => [
                    'Builds up with short passes to centre-backs or wing-backs',
                    'Plays balls in behind when build-up stalls',
                ],
            ] ),
            'defending_tasks_json' => wp_json_encode( [
                'nl' => [
                    'Coacht de organisatie van de laatste linie',
                    'Komt vroeg uit op ballen achter de verdediging (sweeper-keeper)',
                ],
                'en' => [
                    'Coaches the back line\'s organisation',
                    'Sweeps high to clear balls in behind',
                ],
            ] ),
            'sort_order'           => 0,
            'is_shipped'           => 1,
        ] );
    }

    private function seedPrinciple( string $p, int $formation_id ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_principles WHERE code = %s LIMIT 1", 'AO-01'
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( "{$p}tt_principles", [
            'code'                 => 'AO-01',
            'team_function_key'    => 'aanvallen',
            'team_task_key'        => 'opbouwen',
            'title_json'           => wp_json_encode( [
                'nl' => 'Verzorgde opbouw van achteruit',
                'en' => 'Considered build-up from the back',
            ] ),
            'explanation_json'     => wp_json_encode( [
                'nl' => 'We starten de opbouw vanuit de keeper of centrale verdedigers en zoeken eerst de korte oplossing voordat we lange ballen spelen.',
                'en' => 'We start the build-up from the goalkeeper or centre-backs, looking for the short option before going long.',
            ] ),
            'team_guidance_json'   => wp_json_encode( [
                'nl' => 'Houd breedte en diepte. Maak driehoeken. Verplaats het spel actief.',
                'en' => 'Keep width and depth. Form triangles. Actively switch play.',
            ] ),
            'line_guidance_json'   => wp_json_encode( [
                'aanvallers'    => [ 'nl' => 'Bied diepte en open ruimte tussen de linies.', 'en' => 'Offer depth and open space between lines.' ],
                'middenvelders' => [ 'nl' => 'Maak vrijloop-bewegingen om aanspeelbaar te blijven.', 'en' => 'Make supporting runs to stay available.' ],
                'verdedigers'   => [ 'nl' => 'Splits breed en bied korte aansluiting aan de keeper.', 'en' => 'Split wide and offer short support for the keeper.' ],
                'keeper'        => [ 'nl' => 'Wees actief beschikbaar. Korte oplossing eerst.', 'en' => 'Stay actively available. Short option first.' ],
            ] ),
            'default_formation_id' => $formation_id,
            'is_shipped'           => 1,
        ] );
    }

    private function seedSetPiece( string $p, int $formation_id ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT id FROM {$p}tt_set_pieces WHERE slug = %s LIMIT 1", 'corner-attacking-far-post'
        ) );
        if ( $existing > 0 ) return;

        $wpdb->insert( "{$p}tt_set_pieces", [
            'slug'                 => 'corner-attacking-far-post',
            'kind_key'             => 'corner',
            'side'                 => 'attacking',
            'title_json'           => wp_json_encode( [
                'nl' => 'Corner aanvallend — verre paal',
                'en' => 'Attacking corner — far post',
            ] ),
            'bullets_json'         => wp_json_encode( [
                'nl' => [
                    'Inswinger op de verre paal door rechtspoot vanaf links',
                    'Twee koppers op de verre paal, één korte hoek, één penaltystip',
                    'Eerste paal blokkeert keeper',
                    'Twee spelers achterin als rest-defense bij counter',
                ],
                'en' => [
                    'In-swinger to the far post from a right-footer on the left',
                    'Two headers at far post, one near, one penalty spot',
                    'Near-post player screens keeper',
                    'Two stay back as rest-defence',
                ],
            ] ),
            'default_formation_id' => $formation_id,
            'is_shipped'           => 1,
        ] );
    }

    private function seedVision( string $p, int $formation_id ): void {
        global $wpdb;
        $existing = (int) $wpdb->get_var(
            "SELECT id FROM {$p}tt_methodology_visions WHERE is_shipped = 1 LIMIT 1"
        );
        if ( (int) $existing > 0 ) return;

        $wpdb->insert( "{$p}tt_methodology_visions", [
            'club_scope'            => null,
            'formation_id'          => $formation_id,
            'style_of_play_key'     => 'aanvallend_positiespel',
            'way_of_playing_json'   => wp_json_encode( [
                'nl' => 'Aanvallend, verzorgd positiespel met diepte zoekend via de zijkanten.',
                'en' => 'Attacking, considered positional play seeking depth through the wings.',
            ] ),
            'important_traits_json' => wp_json_encode( [
                'nl' => [ 'Speelintelligentie', 'Werkethos', 'Technische vaardigheid onder druk' ],
                'en' => [ 'Game intelligence', 'Work ethic', 'Technical skill under pressure' ],
            ] ),
            'notes_json'            => wp_json_encode( [
                'nl' => 'Voorbeeldvisie afgeleid van het methodologiedocument. Zie wp-admin → Methodology → Visie om je eigen versie aan te maken.',
                'en' => 'Sample vision extracted from the methodology document. See wp-admin → Methodology → Vision to author your own.',
            ] ),
            'is_shipped'            => 1,
        ] );
    }
};
