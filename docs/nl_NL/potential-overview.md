---
title: Potentieeloverzicht
group: analytics
summary: Elke speler in een team of leeftijdscategorie met zijn huidige potentieelklasse, hoe die is verschoven en wie hem heeft vastgelegd — ter plekke aan te passen.
audience: [admin]
views: [potential-overview]
module: TT\Modules\Analytics\AnalyticsModule
feature: analytics_potential_overview
capability: tt_view_analytics
order: 31
---

# Potentieeloverzicht

Het **Potentieeloverzicht** beantwoordt een vraag die de rest van het product niet aankon: *laat me elke speler in deze leeftijdscategorie zien met zijn potentieelklasse, gesorteerd.* Tot dit scherm er was, kwam de klasse — Eerste elftal, Profvoetbal elders, Semiprof, Topamateur, Fundament — nergens voor waar meer dan één speler tegelijk te zien was. Je kon hem lezen op het dossier van een speler, één speler tegelijk, en dat was het.

Het scherm staat onder **Rapportages**, naast het Cohortbeslissingsbord en Evaluatiedekking, en is gemaakt voor periodieke review in plaats van dagelijks gebruik.

## Welke spelersvraag beantwoordt dit?

*Waar gaat deze speler heen?* — en voor het eerst voor meer dan één speler tegelijk. Het werk van een hoofd opleidingen is vergelijkend; het product maakte het tot nu toe serieel.

## Een scope kiezen

Eén keuzeveld, twee soorten antwoord:

- **Een team** — één selectie, de operationele eenheid.
- **Een leeftijdscategorie** — alle teams met hetzelfde leeftijdslabel. "De O15" is bij de ene academie één selectie en bij de andere er twee of drie; kies de leeftijdscategorie en je krijgt ze allemaal in één lijst, met een kolom **Team** zodat je ze uit elkaar houdt.

De lijst biedt alleen de teams en leeftijdscategorieën die je mag inzien. Een coach ziet zijn eigen selecties; academiebrede rollen zien alles. Dat is geen weergavefilter — de scope die je kiest kan nooit verbreden wat je mag zien.

## Wat er op elke rij staat

| Kolom | Wat het is |
| --- | --- |
| Speler | Naam, met een link naar het dossier |
| Team | Alleen bij een leeftijdscategorie, zodat je twee selecties uit elkaar houdt |
| Potentieel | De huidige klasse — een keuzelijst als je potentieel mag vastleggen, anders het label |
| Beweging | Of de klasse omhoog of omlaag ging, is herbevestigd of de eerste is, en van welke klasse hij kwam |
| Vastgelegd | Wanneer de huidige klasse is gezet, door wie, en hoeveel vermeldingen er zijn |

Sorteer door op **Speler**, **Team**, **Potentieel** of **Vastgelegd** te klikken. Klik je op de kolom waarop al gesorteerd is, dan draait de richting om. Spelers zonder vastgelegde klasse blijven onderaan, welke kant de potentieelkolom ook op staat — "laagste klasse eerst" gaat over vastgelegde klassen, en de nog niet beoordeelde spelers bovenaan zetten beantwoordt een andere vraag.

**Toon klassen** beperkt de lijst tot de klassen die je aanvinkt, inclusief **Niet vastgelegd**. Laat alles leeg om de hele selectie te zien.

## Spelers zonder klasse zijn rijen, geen weglatingen

Dit is het stuk dat het herlezen waard is. Een lijst die de spelers weglaat die nog niemand beoordeeld heeft, vertelt je het omgekeerde van de waarheid — en juist die spelers zijn meestal de reden dat je het scherm opende. Ze staan er, met een tint, gemarkeerd als **Niet vastgelegd**, en ze tellen mee in het cijfer **Nog geen klasse** bovenaan.

**Dekking** is het aandeel spelers waarover de academie daadwerkelijk een oordeel heeft gevormd. Dat cijfer leest de scope, niet je klassenfilter — een vinkje beperkt de lijst, niet de selectie, en een dekkingscijfer dat meebeweegt met een filter zegt niets.

Spelers onder de leeftijd waarop de klasse gevraagd wordt zijn een derde geval en worden apart geteld. TalentTrack vraagt geen profplafond over een twaalfjarige (zie [Spelerstatus](player-status.md)), dus die rijen lezen **Niet gevraagd onder 13** en tellen niet als hiaat. Een voetnoot onder de samenvatting zegt om hoeveel spelers het gaat.

## Hier een klasse vastleggen

Mag je potentieel vastleggen, dan is de potentieelcel een keuzelijst. Pas er zoveel aan als je wilt en druk één keer op **Klassen opslaan** — één vastlegmoment voor de hele selectie, en **Annuleren** brengt je terug naar de lijst zoals hij was, zonder iets te schrijven. Dat is bewust zo: een coach die langs een veld een selectie doorwerkt krijgt één vastlegmoment in plaats van twintig stille opslagacties op een verbinding die er misschien niet is.

Wat het wegschrijft is precies wat de popover **Potentieel instellen** op een spelersdossier wegschrijft, via hetzelfde pad en dezelfde regels:

- Een speler op **Niet vastgelegd** laten staan legt niets vast. Het is geen opdracht om iets te wissen.
- De klasse kiezen die een speler al heeft legt ook niets vast — een staand oordeel herhalen is geen verandering van inzicht, en het zou de historie vullen met vermeldingen die op herzieningen lijken.
- Een speler onder de leeftijdsgrens wordt overgeslagen, en de bevestiging zegt hoeveel dat er waren.

Elke opslag voegt toe aan de potentieelhistorie van de speler, zodat het verloop op zijn dossier klopt, en zijn stoplichtstatus neemt de nieuwe klasse meteen mee.

## Wie het mag zien

Potentieel is een stafoordeel over een kind, en een gerangschikte lijst van die oordelen over een hele selectie is blootgevender dan de enkele kleur op een teampagina. Spelers en ouders bereiken hier niets — ook hun eigen rij niet — ongeacht of je academie hun de statusstip laat zien.

Verder volgt het scherm de analytics-bevoegdheid en je teamscope, net als elke andere rapportage.

## Voor integraties

`GET /wp-json/talenttrack/v1/reports/potential-overview` geeft dezelfde rijen terug die het scherm je toont, voor hetzelfde account: `scope` (`team` of `age_group`), `team_id` of `age_group`, optioneel `bands`, `sort` en `dir`. Het antwoord bevat de rijen, de teams in scope en dezelfde samenvattingscijfers. Schrijven gaat naar `POST /players/{id}/potential`, hetzelfde endpoint dat het spelersdossier gebruikt.
