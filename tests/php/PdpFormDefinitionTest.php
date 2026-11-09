<?php
namespace TT\Tests\Php;

use WP_UnitTestCase;
use TT\Modules\Pdp\Forms\PdpFormRegistry;

/**
 * #4976 — the JG4IT conversation forms are data, and the data says exactly
 * what the academy's Word forms say.
 *
 * The expected texts below are typed from the two Word forms
 * (`pop_formulier_elite_jg4it.docx`, `POP_Gesprek_Keepers.docx`), as listed in
 * the issue. They are the source of truth: a definition that drifts from them
 * fails here, never on a page.
 */
final class PdpFormDefinitionTest extends WP_UnitTestCase {

    private const OUTFIELD = [
        'technical' => [
            'Technisch rechts' => 'Traptechniek, passing, aanname en dribbelen met het rechterbeen.',
            'Technisch links' => 'Traptechniek, passing, aanname en dribbelen met het linkerbeen (tweebenigheid).',
            'Aannames' => 'Eerste balcontact onder controle krijgen, zowel over de grond als door de lucht.',
            'Individuele Actie Aanvallend' => 'In staat zijn een tegenstander te passeren of ruimtes te creëren (1v1).',
            'Individuele Actie Verdedigend' => 'Directe duels winnen, de tegenstander dwingen of de bal correct veroveren.',
        ],
        'tactical' => [
            'Lezen wedstrijd' => 'Situaties herkennen, anticiperen op de bal en keuzes maken op basis van het spel.',
            'Kwaliteit tegenstander' => 'Aanpassen aan de directe tegenstander, sterktes uitschakelen/zwaktes benutten.',
            'Juiste Loopactie Balbezit' => 'Vrijlopen, diepgaan op het juiste moment en aanspeelbaar zijn voor teamgenoten.',
            'Juiste Loopactie Balverlies' => 'Dichtlopen van passlijnen, compact verdedigen en positie innemen bij omschakeling.',
        ],
        'physical' => [
            'Duels' => 'Fysieke kracht gebruiken in de 1v1 situaties om de bal af te schermen of te veroveren.',
            'Conditioneel' => 'In staat zijn om de intensiteit van de elite-wedstrijd de volle 90 minuten vol te houden.',
            'Wendbaarheid' => 'Snel van richting kunnen veranderen en soepel bewegen in kleine ruimtes.',
            'Startsnelheid' => 'De eerste meters explosief versnellen om los te komen of te herstellen.',
        ],
        'mental' => [
            'Inzet / Motivatie' => 'Maximale inzet tonen tijdens elke training en wedstrijd, de wil om te winnen.',
            'Zelfvertrouwen' => 'Geloof in eigen kunnen, ook na een fout of tijdens een moeilijke fase in de wedstrijd.',
            'Coaching' => 'Verbaal sturen van teamgenoten, positieve communicatie en coaching accepteren.',
            'Onverstoorbaar' => 'Focus behouden bij tegenslag, beslissingen van de scheidsrechter of provocaties.',
        ],
    ];

    private const GOALKEEPER = [
        'technical' => [
            'Doelverdediging' => 'Basishouding, voetenwerk (shuffling/cross-step), vangen, klemmen en stompen van de bal.',
            '1v1 & Uitkomen' => 'Timing bij het verkleinen van de hoek, block-zetting (glijdend of staand) en durf in de duels.',
            'Hoge ballen' => 'Beoordelen van de baan van de bal, timing van de sprong en het hoogste punt kiezen (heffen knie).',
            'Meevoetballen & Passing' => 'Passing over de grond (links/rechts), aannames onder druk en passing over middellange afstand.',
            'Hervattingen' => 'Opbouw via de keeper. Kwaliteit en nauwkeurigheid van de uittrap (volley/dropkick) en gerichte verre uitworpen.',
        ],
        'tactical' => [
            'Positionering' => 'Juiste positie ten opzichte van de bal en de goal (straal- en hoekbepaling) bij schoten.',
            'Ruimte achter de defensie' => "Fungeren als 'sweeper-keeper': verdedigen van de diepe ruimte achter de achterste linie.",
            'Coaching & Organisatie' => 'Neerzetten van de defensie bij standaardsituaties en het verbaal sturen van ploeggenoten tijdens spel.',
            'Omschakeling' => 'Snelheid van handelen na een redding om direct een counter/aanval op te zetten.',
        ],
        'physical' => [
            'Explosiviteit & Afzet' => 'Kracht in de benen voor een snelle, verre duik of sprong naar de kruising.',
            'Reactiesnelheid' => 'Reflexen op ballen van korte afstand, van richting veranderde schoten of situaties in de scrimmage.',
            'Wendbaarheid & Voetenwerk' => 'Snel kunnen herstellen na een redding (second save) en rap verplaatsen tussen de palen.',
            'Fysieke aanwezigheid' => 'Kracht in de luchtduels (binnen de 5-meter) en fysiek durven staan in contact met spitsen.',
        ],
        'mental' => [
            'Concentratie' => 'Focus behouden gedurende 90 minuten, ook als er lange tijd weinig te doen is geweest.',
            'Onverstoorbaarheid' => 'Mentale veerkracht om direct door te gaan na een tegendoelpunt of een persoonlijke fout.',
            'Leiderschap & Uitstraling' => 'Rust en zelfvertrouwen uitstralen naar het team, durven opeisen van je leidersrol.',
            'Inzet / Motivatie' => 'Maximale inzet tonen tijdens specifieke keeperstrainingen en groepstrainingen.',
        ],
    ];

    private const SCALE = [
        1 => [ 'Ondermaats', 'groot verbeterpunt' ],
        2 => [ 'Matig', 'potentie, niet constant' ],
        3 => [ 'Voldoende', 'stabiel basisniveau' ],
        4 => [ 'Goed', 'bovengemiddeld, wapen' ],
        5 => [ 'Uitstekend', 'elite niveau, onderscheidend' ],
    ];

    public function set_up(): void {
        parent::set_up();
        PdpFormRegistry::reset();
    }

    public function test_both_forms_load_and_validate(): void {
        foreach ( [ 'jg4it_elite_outfield' => 'outfield', 'jg4it_elite_goalkeeper' => 'goalkeeper' ] as $form_key => $group ) {
            $definition = PdpFormRegistry::definition( $form_key, 1 );
            $this->assertIsArray( $definition, $form_key );
            $this->assertSame( $group, $definition['position_group'] );
            $this->assertSame( [], PdpFormRegistry::validate( $definition, $form_key, 1 ), $form_key );
            $this->assertCount( 17, PdpFormRegistry::items( $definition ), "{$form_key} has 17 items" );
        }
        $this->assertSame( [ 'jg4it_elite' ], array_keys( PdpFormRegistry::sets() ) );
        $this->assertSame(
            [ 'outfield' => 'jg4it_elite_outfield', 'goalkeeper' => 'jg4it_elite_goalkeeper' ],
            PdpFormRegistry::sets()['jg4it_elite']['forms']
        );
    }

    public function test_every_item_reads_as_the_word_form(): void {
        foreach ( [ 'jg4it_elite_outfield' => self::OUTFIELD, 'jg4it_elite_goalkeeper' => self::GOALKEEPER ] as $form_key => $expected ) {
            $definition = (array) PdpFormRegistry::definition( $form_key, 1 );
            $actual     = [];
            foreach ( PdpFormRegistry::items( $definition ) as $item ) {
                $actual[ $item['main'] ][ $item['label'] ] = $item['definition'];
            }
            $this->assertSame( $expected, $actual, "{$form_key}: the items and their definitions, in order" );

            $scale = [];
            foreach ( (array) $definition['scale'] as $step => $row ) $scale[ $step ] = [ $row['label'], $row['hint'] ];
            $this->assertSame( self::SCALE, $scale, "{$form_key}: the scale" );
            $this->assertSame( [ 'player' => 'Score Speler', 'coach' => 'Score Trainer' ], $definition['score_columns'] );
        }
    }

    public function test_the_prompts_read_as_the_word_forms(): void {
        $outfield   = $this->prompts( 'jg4it_elite_outfield' );
        $goalkeeper = $this->prompts( 'jg4it_elite_goalkeeper' );

        $this->assertSame( 'Mijn Grootste Kwaliteit / Wapen', $outfield['strongest_quality']['label'] );
        $this->assertSame( 'Wat gaat de speler de komende tijd nóg intensiever inzetten in wedstrijden?', $outfield['strongest_quality']['question'] );
        $this->assertSame( 'Wat gaat de keeper de komende tijd nóg intensiever inzetten in wedstrijden?', $goalkeeper['strongest_quality']['question'] );
        $this->assertSame( 'both', $outfield['strongest_quality']['author'] );

        $this->assertSame( 'Specifiek, Meetbaar, Acceptabel, Realistisch, Tijdgebonden doelstelling.', $outfield['smart_1']['question'] );
        $this->assertSame( 'Welke actie gaat de speler ondernemen en hoe helpt de trainer hierbij?', $outfield['smart_2']['question'] );
        $this->assertSame( 'Welke actie gaat de speler ondernemen en hoe helpt de keeper-/hoofdtrainer hierbij?', $goalkeeper['smart_2']['question'] );
        $this->assertSame( 'Binnen 2 maanden onderschep ik bij hoekschoppen minimaal 3 actieve ballen in de 5-meter in plaats van op de lijn te blijven.', $goalkeeper['smart_1']['example'] );
        $this->assertSame( 'Verbeteren van de linksbenige passing over de grond onder druk door hier elke dinsdag 10 minuten extra op te trainen.', $goalkeeper['smart_2']['example'] );

        foreach ( [ 'technical', 'tactical', 'physical', 'mental' ] as $section ) {
            $this->assertSame( 'coach', $outfield[ 'notes.' . $section ]['author'], "notes.{$section} is the coach's" );
        }

        $this->assertSame(
            [ 'player' => 'Handtekening Speler', 'coach' => 'Handtekening Trainer', 'parent' => 'Handtekening Ouder/Verzorger' ],
            ( (array) PdpFormRegistry::definition( 'jg4it_elite_outfield', 1 ) )['sign_off']
        );
        $this->assertSame( 'Persoonlijk Ontwikkel Plan (POP) Keepers Elite Elftal JG4IT', ( (array) PdpFormRegistry::definition( 'jg4it_elite_goalkeeper', 1 ) )['header']['title'] );
    }

    /** @dataProvider brokenDefinitions */
    public function test_a_broken_definition_fails_validation( callable $break, string $expected ): void {
        $definition = (array) PdpFormRegistry::definition( 'jg4it_elite_outfield', 1 );
        $broken     = $break( $definition );
        $errors     = PdpFormRegistry::validate( $broken, 'jg4it_elite_outfield', 1 );
        $this->assertNotSame( [], $errors );
        $this->assertStringContainsString( $expected, implode( '; ', $errors ) );
    }

    /** @return array<string, array{0:callable, 1:string}> */
    public function brokenDefinitions(): array {
        return [
            'an unknown key' => [ static function ( array $d ): array { $d['colour'] = 'red'; return $d; }, 'unknown key `colour`' ],
            'a duplicate item_key' => [ static function ( array $d ): array { $d['sections'][1]['items'][0]['key'] = 'aannames'; return $d; }, 'duplicate item_key `aannames`' ],
            'a missing main' => [ static function ( array $d ): array { array_pop( $d['sections'] ); unset( $d['prompts'][6] ); return $d; }, 'no section for the main `mental`' ],
            'a main that is not one of the four' => [ static function ( array $d ): array { $d['sections'][0]['main'] = 'speed'; return $d; }, 'main must be one of' ],
            'a prompt without an author' => [ static function ( array $d ): array { unset( $d['prompts'][0]['author'] ); return $d; }, 'needs an author' ],
            'a scale without five steps' => [ static function ( array $d ): array { unset( $d['scale'][5] ); return $d; }, 'exactly the steps 1 to 5' ],
            'a version that is not its file' => [ static function ( array $d ): array { $d['version'] = 2; return $d; }, 'version does not match' ],
        ];
    }

    public function test_an_academy_without_forms_reads_none(): void {
        \TT\Infrastructure\Query\QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '' );
        $this->assertSame( [ 'outfield' => null, 'goalkeeper' => null ], PdpFormRegistry::formsForClub() );
        $this->assertSame( '', PdpFormRegistry::setForClub() );

        \TT\Infrastructure\Query\QueryHelpers::set_config( PdpFormRegistry::CONFIG_KEY, '{"outfield":"no_such_form","goalkeeper":"jg4it_elite_outfield"}' );
        $this->assertSame( [ 'outfield' => null, 'goalkeeper' => null ], PdpFormRegistry::formsForClub(), 'an unknown key, or a form of the other group, reads as unset' );
    }

    /** @return array<string, array<string,mixed>> */
    private function prompts( string $form_key ): array {
        $out = [];
        foreach ( (array) ( (array) PdpFormRegistry::definition( $form_key, 1 ) )['prompts'] as $prompt ) $out[ (string) $prompt['key'] ] = (array) $prompt;
        return $out;
    }
}
