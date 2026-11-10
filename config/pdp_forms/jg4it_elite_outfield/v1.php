<?php
/**
 * PDP conversation form — JG4IT, elite teams, outfield players. Version 1.
 *
 * The academy's own text, word for word from its Word form
 * (`pop_formulier_elite_jg4it.docx`). It is not a msgid: it is shown as it
 * stands in every locale.
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
    'form_key'        => 'jg4it_elite_outfield',
    'version'         => 1,
    'set'             => [ 'key' => 'jg4it_elite', 'label' => 'JG4IT Elite' ],
    'position_group'  => 'outfield',
    'category_prefix' => 'jg4it_of',

    'header' => [
        'kicker' => 'ELITE ELFTALLEN JG4IT',
        'title'  => 'Persoonlijk Ontwikkel Plan (POP) Elite Elftal JG4IT',
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
                [ 'key' => 'technisch_rechts', 'label' => 'Technisch rechts', 'definition' => 'Traptechniek, passing, aanname en dribbelen met het rechterbeen.' ],
                [ 'key' => 'technisch_links', 'label' => 'Technisch links', 'definition' => 'Traptechniek, passing, aanname en dribbelen met het linkerbeen (tweebenigheid).' ],
                [ 'key' => 'aannames', 'label' => 'Aannames', 'definition' => 'Eerste balcontact onder controle krijgen, zowel over de grond als door de lucht.' ],
                [ 'key' => 'actie_aanvallend', 'label' => 'Individuele Actie Aanvallend', 'definition' => 'In staat zijn een tegenstander te passeren of ruimtes te creëren (1v1).' ],
                [ 'key' => 'actie_verdedigend', 'label' => 'Individuele Actie Verdedigend', 'definition' => 'Directe duels winnen, de tegenstander dwingen of de bal correct veroveren.' ],
            ],
        ],
        [
            'key'   => 'tactical',
            'main'  => 'tactical',
            'title' => 'Tactisch inzicht & wedstrijdlezen',
            'items' => [
                [ 'key' => 'lezen_wedstrijd', 'label' => 'Lezen wedstrijd', 'definition' => 'Situaties herkennen, anticiperen op de bal en keuzes maken op basis van het spel.' ],
                [ 'key' => 'kwaliteit_tegenstander', 'label' => 'Kwaliteit tegenstander', 'definition' => 'Aanpassen aan de directe tegenstander, sterktes uitschakelen/zwaktes benutten.' ],
                [ 'key' => 'loopactie_balbezit', 'label' => 'Juiste Loopactie Balbezit', 'definition' => 'Vrijlopen, diepgaan op het juiste moment en aanspeelbaar zijn voor teamgenoten.' ],
                [ 'key' => 'loopactie_balverlies', 'label' => 'Juiste Loopactie Balverlies', 'definition' => 'Dichtlopen van passlijnen, compact verdedigen en positie innemen bij omschakeling.' ],
            ],
        ],
        [
            'key'   => 'physical',
            'main'  => 'physical',
            'title' => 'Fysieke elementen',
            'items' => [
                [ 'key' => 'duels', 'label' => 'Duels', 'definition' => 'Fysieke kracht gebruiken in de 1v1 situaties om de bal af te schermen of te veroveren.' ],
                [ 'key' => 'conditioneel', 'label' => 'Conditioneel', 'definition' => 'In staat zijn om de intensiteit van de elite-wedstrijd de volle 90 minuten vol te houden.' ],
                [ 'key' => 'wendbaarheid', 'label' => 'Wendbaarheid', 'definition' => 'Snel van richting kunnen veranderen en soepel bewegen in kleine ruimtes.' ],
                [ 'key' => 'startsnelheid', 'label' => 'Startsnelheid', 'definition' => 'De eerste meters explosief versnellen om los te komen of te herstellen.' ],
            ],
        ],
        [
            'key'   => 'mental',
            'main'  => 'mental',
            'title' => 'Mentale veerkracht & begeleiding',
            'items' => [
                [ 'key' => 'inzet_motivatie', 'label' => 'Inzet / Motivatie', 'definition' => 'Maximale inzet tonen tijdens elke training en wedstrijd, de wil om te winnen.' ],
                [ 'key' => 'zelfvertrouwen', 'label' => 'Zelfvertrouwen', 'definition' => 'Geloof in eigen kunnen, ook na een fout of tijdens een moeilijke fase in de wedstrijd.' ],
                [ 'key' => 'coaching', 'label' => 'Coaching', 'definition' => 'Verbaal sturen van teamgenoten, positieve communicatie en coaching accepteren.' ],
                [ 'key' => 'onverstoorbaar', 'label' => 'Onverstoorbaar', 'definition' => 'Focus behouden bij tegenslag, beslissingen van de scheidsrechter of provocaties.' ],
            ],
        ],
    ],

    'prompts' => [
        [ 'key' => 'strongest_quality', 'author' => 'both', 'kind' => 'text', 'label' => 'Mijn Grootste Kwaliteit / Wapen', 'question' => 'Wat gaat de speler de komende tijd nóg intensiever inzetten in wedstrijden?' ],
        [ 'key' => 'smart_1', 'author' => 'coach', 'kind' => 'smart_goal', 'label' => 'Ontwikkelpunt 1 (SMART)', 'question' => 'Specifiek, Meetbaar, Acceptabel, Realistisch, Tijdgebonden doelstelling.' ],
        [ 'key' => 'smart_2', 'author' => 'coach', 'kind' => 'smart_goal', 'label' => 'Ontwikkelpunt 2 (SMART)', 'question' => 'Welke actie gaat de speler ondernemen en hoe helpt de trainer hierbij?' ],
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
