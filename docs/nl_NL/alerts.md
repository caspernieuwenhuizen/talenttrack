---
title: Meldingen
group: performance
summary: Situaties in je gegevens die aandacht vragen — niet afgeronde trainingen, ontbrekende aanwezigheid, sessies zonder trainer — automatisch zichtbaar en opgelost zodra je ze aanpakt.
audience: [user, admin]
order: 35
---

# Meldingen

Een **melding** wijst je op iets in je gegevens dat op dit moment waar is en aandacht vraagt. Een training van vorige dinsdag die nog op gepland staat. Een afgeronde training waarvan niemand de aanwezigheid heeft ingevuld. Een sessie donderdag zonder trainer.

Je vinkt een melding nooit af. Je lost op waar de melding over gaat, en de melding verdwijnt vanzelf.

## Meldingen zijn geen taken

TalentTrack kent twee dingen die op je kunnen wachten, en ze gedragen zich bewust verschillend:

| | Taak | Melding |
| --- | --- | --- |
| Wat het is | werk dat aan jou is toegewezen | een situatie die nu waar is |
| Hoe het eindigt | jij rondt het af | het is niet langer waar |
| Wie het krijgt | één persoon | iedereen die het kan oplossen |
| Waar je het oplost | in de taak zelf | in het gewone scherm van dat record |

Een taak is "schrijf de wedstrijdevaluatie voor Daan". Een melding is "deze training staat nog op gepland". Als meldingen taken waren, zou je de training in het activiteitenoverzicht afronden en daarna nog steeds een taak in je inbox hebben die zegt dat je dat moet doen.

## Waar je meldingen ziet

- **De bel**, rechtsboven. Het getal telt nu zowel je open taken als je open meldingen.
- **Een balk** bovenaan het dashboard met de meldingen die het meest aandacht vragen.

Elke melding linkt rechtstreeks naar het record waar het over gaat, dus oplossen is één klik.

## Waar TalentTrack nu op meldt

| Melding | Wat het betekent |
| --- | --- |
| **Activiteit uit het verleden staat nog op gepland** | De datum is voorbij, maar niemand heeft de activiteit afgerond of geannuleerd. Zolang dat niet gebeurt, ontbreken de aanwezigheid en de speelminuten in elk rapport. |
| **Aanwezigheid niet geregistreerd** | De activiteit staat op afgerond, maar niemand heeft ingevuld wie erbij was. Overal lijkt het klaar, behalve in de rapportages. |
| **Aankomende sessie zonder trainer** | Voor een sessie binnen een week is niemand ingedeeld. Sessies zonder trainer worden vaak laat afgezegd, en dat kost elke speler in de selectie een trainingsmoment. |

Meer meldingen — over evaluaties, doelen, PDP-cycli en spelersdossiers — volgen in latere releases.

## Wie een melding krijgt

De mensen die het daadwerkelijk kunnen oplossen: de trainer die aan de activiteit is gekoppeld, en de hoofdtrainer van het team.

Hoofden Opleiding en beheerders krijgen **niet** voor elk team een melding. Meldingen van twintig teams bij de persoon met de minste tijd om ze te lezen is precies hoe een systeem genegeerd raakt. Een overzicht voor die rol volgt in een latere release.

Je krijgt bovendien alleen meldingen over records die je mag zien. Dat wordt elk uur opnieuw gecontroleerd, dus een trainer die van team wisselt krijgt de meldingen van dat team vanzelf niet meer.

## Waarom een melding soms een uur blijft staan

TalentTrack controleert elke situatie één keer per uur, op de achtergrond. Dat is een bewuste keuze: alles controleren tijdens het laden van je dashboard zou inloggen voor iedereen trager maken, en bij elk nieuw meldingstype nog trager.

Rond je om 10:15 een training af, dan kan de melding er tot de volgende controle nog staan. Hij verdwijnt vanzelf. Je hoeft niets te doen, en je kunt het ook niet versnellen.

## Meldingen uitzetten

Nog niet. Instellingen per persoon en per club — kiezen welke meldingen je ziet en waar — komen in een latere release. Tot die tijd krijgt iedereen die iets kan oplossen daar bericht over.

## Voor beheerders

- Meldingen worden bijgewerkt door een achtergrondtaak die elk uur draait. Verschijnt er nooit iets, controleer dan of de geplande taken van WordPress op deze site werken.
- Bij een nieuwe installatie draait de controle direct bij activering, zodat het dashboard meteen een kloppend beeld toont.
- Alle meldingen zijn ook beschikbaar via de REST API op `/wp-json/talenttrack/v1/alerts`.
