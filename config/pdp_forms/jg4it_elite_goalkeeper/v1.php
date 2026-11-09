<?php
/**
 * PDP conversation form — JG4IT, elite teams, goalkeepers. Version 1.
 *
 * The academy's own text, word for word from its Word form
 * (`POP_Gesprek_Keepers.docx`). It is not a msgid: it is shown as it stands
 * in every locale.
 *
 * A shipped version is immutable (`PdpFormDefinitionChecksumTest`). A change
 * to the wording or the items becomes `v2.php`; conversations held on v1 keep
 * reading v1. An `item_key` keeps its meaning across versions, so a trainer's
 * score for an item lands on the same evaluation category in every version.
 *
 * Read and validated by `TT\Modules\Pdp\Forms\PdpFormRegistry`.
 */

if ( ! defined( 'ABSPATH' ) && PHP_SAPI !== 'cli' ) exit;

return [
    'form_key'        => 'jg4it_elite_goalkeeper',
    'version'         => 1,
    'set'             => [ 'key' => 'jg4it_elite', 'label' => 'JG4IT Elite' ],
    'position_group'  => 'goalkeeper',
    'category_prefix' => 'jg4it_gk',

    'header' => [
        'kicker' => 'ELITE ELFTALLEN JG4IT',
        'title'  => 'Persoonlijk Ontwikkel Plan (POP) Keepers Elite Elftal JG4IT',
        'fields' => [
            'player'   => 'Speler',
            'date'     => 'Datum',
            'team'     => 'Team',
            'trainers' => 'Trainer(s)',
        ],
    ],

    'scale' => [
        1 => [ 'label' => 'Ondermaats', 'hint' => 'groot verbeterpunt' ],
        2 => [ 'label' => 'Matig', 'hint' => 'potentie, niet constant' ],
        3 => [ 'label' => 'Voldoende', 'hint' => 'stabiel basisniveau' ],
        4 => [ 'label' => 'Goed', 'hint' => 'bovengemiddeld, wapen' ],
        5 => [ 'label' => 'Uitstekend', 'hint' => 'elite niveau, onderscheidend' ],
    ],

    'score_columns' => [
        'player' => 'Score Speler',
        'coach'  => 'Score Trainer',
    ],

    'sections' => [
        [
            'key'   => 'technical',
            'main'  => 'technical',
            'title' => 'Technische vaardigheden',
            'items' => [
                [ 'key' => 'doelverdediging', 'label' => 'Doelverdediging', 'definition' => 'Basishouding, voetenwerk (shuffling/cross-step), vangen, klemmen en stompen van de bal.' ],
                [ 'key' => 'een_tegen_een_uitkomen', 'label' => '1v1 & Uitkomen', 'definition' => 'Timing bij het verkleinen van de hoek, block-zetting (glijdend of staand) en durf in de duels.' ],
                [ 'key' => 'hoge_ballen', 'label' => 'Hoge ballen', 'definition' => 'Beoordelen van de baan van de bal, timing van de sprong en het hoogste punt kiezen (heffen knie).' ],
                [ 'key' => 'meevoetballen_passing', 'label' => 'Meevoetballen & Passing', 'definition' => 'Passing over de grond (links/rechts), aannames onder druk en passing over middellange afstand.' ],
                [ 'key' => 'hervattingen', 'label' => 'Hervattingen', 'definition' => 'Opbouw via de keeper. Kwaliteit en nauwkeurigheid van de uittrap (volley/dropkick) en gerichte verre uitworpen.' ],
            ],
        ],
        [
            'key'   => 'tactical',
            'main'  => 'tactical',
            'title' => 'Tactisch inzicht & positionering',
            'items' => [
                [ 'key' => 'positionering', 'label' => 'Positionering', 'definition' => 'Juiste positie ten opzichte van de bal en de goal (straal- en hoekbepaling) bij schoten.' ],
                [ 'key' => 'ruimte_achter_defensie', 'label' => 'Ruimte achter de defensie', 'definition' => "Fungeren als 'sweeper-keeper': verdedigen van de diepe ruimte achter de achterste linie." ],
                [ 'key' => 'coaching_organisatie', 'label' => 'Coaching & Organisatie', 'definition' => 'Neerzetten van de defensie bij standaardsituaties en het verbaal sturen van ploeggenoten tijdens spel.' ],
                [ 'key' => 'omschakeling', 'label' => 'Omschakeling', 'definition' => 'Snelheid van handelen na een redding om direct een counter/aanval op te zetten.' ],
            ],
        ],
        [
            'key'   => 'physical',
            'main'  => 'physical',
            'title' => 'Fysieke elementen',
            'items' => [
                [ 'key' => 'explosiviteit_afzet', 'label' => 'Explosiviteit & Afzet', 'definition' => 'Kracht in de benen voor een snelle, verre duik of sprong naar de kruising.' ],
                [ 'key' => 'reactiesnelheid', 'label' => 'Reactiesnelheid', 'definition' => 'Reflexen op ballen van korte afstand, van richting veranderde schoten of situaties in de scrimmage.' ],
                [ 'key' => 'wendbaarheid_voetenwerk', 'label' => 'Wendbaarheid & Voetenwerk', 'definition' => 'Snel kunnen herstellen na een redding (second save) en rap verplaatsen tussen de palen.' ],
                [ 'key' => 'fysieke_aanwezigheid', 'label' => 'Fysieke aanwezigheid', 'definition' => 'Kracht in de luchtduels (binnen de 5-meter) en fysiek durven staan in contact met spitsen.' ],
            ],
        ],
        [
            'key'   => 'mental',
            'main'  => 'mental',
            'title' => 'Mentale veerkracht & focus',
            'items' => [
                [ 'key' => 'concentratie', 'label' => 'Concentratie', 'definition' => 'Focus behouden gedurende 90 minuten, ook als er lange tijd weinig te doen is geweest.' ],
                [ 'key' => 'onverstoorbaarheid', 'label' => 'Onverstoorbaarheid', 'definition' => 'Mentale veerkracht om direct door te gaan na een tegendoelpunt of een persoonlijke fout.' ],
                [ 'key' => 'leiderschap_uitstraling', 'label' => 'Leiderschap & Uitstraling', 'definition' => 'Rust en zelfvertrouwen uitstralen naar het team, durven opeisen van je leidersrol.' ],
                [ 'key' => 'inzet_motivatie', 'label' => 'Inzet / Motivatie', 'definition' => 'Maximale inzet tonen tijdens specifieke keeperstrainingen en groepstrainingen.' ],
            ],
        ],
    ],

    'prompts' => [
        [ 'key' => 'strongest_quality', 'author' => 'both', 'kind' => 'text', 'label' => 'Mijn Grootste Kwaliteit / Wapen', 'question' => 'Wat gaat de keeper de komende tijd nóg intensiever inzetten in wedstrijden?' ],
        [ 'key' => 'smart_1', 'author' => 'coach', 'kind' => 'smart_goal', 'label' => 'Ontwikkelpunt 1 (SMART)', 'question' => 'Specifiek, Meetbaar, Acceptabel, Realistisch, Tijdgebonden doelstelling.', 'example' => 'Binnen 2 maanden onderschep ik bij hoekschoppen minimaal 3 actieve ballen in de 5-meter in plaats van op de lijn te blijven.' ],
        [ 'key' => 'smart_2', 'author' => 'coach', 'kind' => 'smart_goal', 'label' => 'Ontwikkelpunt 2 (SMART)', 'question' => 'Welke actie gaat de speler ondernemen en hoe helpt de keeper-/hoofdtrainer hierbij?', 'example' => 'Verbeteren van de linksbenige passing over de grond onder druk door hier elke dinsdag 10 minuten extra op te trainen.' ],
        [ 'key' => 'notes.technical', 'author' => 'coach', 'kind' => 'text', 'label' => 'Toelichting', 'question' => '' ],
        [ 'key' => 'notes.tactical', 'author' => 'coach', 'kind' => 'text', 'label' => 'Toelichting', 'question' => '' ],
        [ 'key' => 'notes.physical', 'author' => 'coach', 'kind' => 'text', 'label' => 'Toelichting', 'question' => '' ],
        [ 'key' => 'notes.mental', 'author' => 'coach', 'kind' => 'text', 'label' => 'Toelichting', 'question' => '' ],
    ],

    'sign_off' => [
        'player' => 'Handtekening Speler',
        'coach'  => 'Handtekening Trainer',
        'parent' => 'Handtekening Ouder/Verzorger',
    ],
];
