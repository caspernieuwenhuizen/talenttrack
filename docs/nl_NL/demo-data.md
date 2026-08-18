<!-- audience: admin, dev -->

# Demodata

De demodatagenerator vult een club met een geloofwaardige academie: teams,
een spelersgroep, staf, een trainingskalender, evaluaties en
ontwikkeldoelen. Handig om TalentTrack te verkennen, te demonstreren, of om
een nieuwe installatie iets te laten zien voordat er echte data is.

Te vinden onder **TalentTrack → Demodata** in wp-admin.

Elke gegenereerde rij krijgt een label van de batch die hem heeft
aangemaakt, zodat een wisbeurt precies weghaalt wat is gegenereerd en echte
records nooit raakt.

## Wat wordt er gegenereerd

| Categorie | Wat het vult |
| --- | --- |
| Mensen | Stafrecords plus de demo-WP-accounts per persona |
| Teams | Teams per leeftijdsgroep met hoofdtrainer, inclusief de trainer/team-koppelingen |
| Spelers | Een selectie per team, elk met een archetype dat de beoordelingen stuurt |
| Activiteiten | Trainingen en wedstrijden over de periode van de preset, met aanwezigheid |
| Evaluaties | Evaluatierondes met beoordelingen per categorie volgens het archetype van de speler |
| Doelen | Eén of twee ontwikkeldoelen per speler |
| Tijdlijngebeurtenissen | Tijdlijnregels die ontstaan bij het aanmaken van spelers, evaluaties en doelen |
| Verzorgers | Koppelingen naar de demo-ouderaccounts, plus de zichtbaarheidsinstellingen per speler |
| Blessures | Blessuredossiers met hersteldatums en de tijdlijngebeurtenissen die daaruit volgen |
| Spelersprofiel | Historie per leeftijdsgroep, attribuutwaarden, eigen velden van de club met waarden, en koppelingen tussen doelen en evaluaties |
| Spelersrapporten | Gegenereerde rapporten voor de doelgroepen die een academie gebruikt |
| Metingen | Een testbatterij, streefwaarden per leeftijdsgroep, testsessies per team en één resultaat per speler |
| POP-cyclus | Het seizoen, een ontwikkeldossier per speler, de gesprekscyclus, agendakoppelingen en eindoordelen |
| Trainingsinhoud | Oefeningen en principes per training, oefeningsuitzonderingen per team, vakantieperiodes |
| Wedstrijddag | Wedstrijdvoorbereiding voor elke wedstrijd, plus uitslagen, doelpunten en wissels voor de gespeelde wedstrijden |
| Testtrainingen | Open trainingen voor uitgenodigde spelers, één in het verleden en één komend per leeftijdsgroep |
| Teamontwikkeling | Een formatie en speelstijlverdeling per team, een wedstrijdblauwdruk, door de trainer gemarkeerde koppels en een chemiereeks |
| Proefdossiers | Geïmporteerd uit een Excel-werkboek wanneer dat wordt geüpload |

Presets bepalen de omvang: **tiny** (1 team, 4 weken), **small** (3 teams,
8 weken), **medium** (6 teams, 16 weken), **large** (12 teams, 36 weken).
Elke preset genereert 12 spelers per team.

Genereren is reproduceerbaar: dezelfde seed, preset en inhoudstaal leveren
elke keer dezelfde academie op.

## Kiezen wat je genereert

Het generatieformulier bestaat uit twee groepen.

**Stamgegevens** (teams, mensen, spelers) — vink deze uit om voort te bouwen
op rijen die al in je club staan in plaats van nieuwe te genereren. Vink je
Teams uit, dan moeten er al teams in de club zijn; anders weigert het
formulier de run in plaats van stilletjes niets op te leveren.

**Afhankelijke onderdelen** (activiteiten, evaluaties, doelen, …) — vink uit
om die categorie over te slaan bovenop de aanwezige stamgegevens.

## Wissen

Het wisformulier verwijdert gelabelde demorijen per categorie. Elke
categorie neemt ook zijn onderliggende rijen mee: Teams wissen verwijdert de
activiteiten, aanwezigheid, evaluaties en beoordelingen van die teams, en
Spelers wissen verwijdert de evaluaties, doelen, tijdlijngebeurtenissen en
proefdossiers van die speler.

Spelers wissen verwijdert **geen** teams, en Teams wissen verwijdert
**geen** spelers — wie het één opnieuw opbouwt, wil het ander meestal
behouden.

Beperk een wisbeurt tot één batch met het keuzemenu Batch, of laat het op
**Alle batches** staan.

De demo-WP-accounts blijven bestaan na het wissen van data. Ze verwijderen
is een aparte actie ("Demogebruikers wissen"), met waarborgen: hij weigert
een account waarvan het e-mailadres buiten het ingestelde demodomein valt,
het account waarmee je bent ingelogd, en de laatst overgebleven beheerder.

## Dekking

`src/Modules/DemoData/DemoCoverage.php` is de enige bron van waarheid voor
wat de generator dekt. Elke `tt_*`-tabel die het schema aanmaakt staat daar
precies één keer, in één van drie toestanden:

- **generated** — er is een producent die hem vult. De regel benoemt het
  `entity_type` voor de demolabels, de `category` die de beheerder aan- en
  uitzet, de producent (`written_by`) en `depends_on` voor de
  verwijdervolgorde.
- **planned** — hoort erbij, maar is nog niet gebouwd; de waarde is het
  issue dat hem gaat schrijven (epic #2461).
- **exempt** — wordt nooit gegenereerd, met de reden erbij. Configuratie,
  vocabulaires, referentiedata uit migraties, systeemlogs, en alles waarvan
  verzinnen misleidend zou zijn of een neveneffect heeft (een geplande
  rapportage zou echte e-mail versturen; een Strava-koppeling vereist echte
  OAuth-tokens).

`tools/check-demo-coverage.php` faalt zodra een tabel in geen van de drie
toestanden staat, zodat een migratie die een tabel toevoegt de keuze
afdwingt. `bin/demo-coverage-selfcheck.php` bewijst dat de afgeleide
verwijdervolgorde veilig is en dat geen enkel gegenereerd `entity_type`
buiten een wiscascade valt. Beide draaien in CI bij elke PR.

### Een generator toevoegen

1. Implementeer `DependentGeneratorInterface` — `category()`,
   `fromContext()` en `generate()` met het aantal rijen. Label elke
   ingevoegde rij via `DemoBatchRegistry::tag()`; een ongelabelde rij kan de
   wisfunctie nooit bereiken en blijft permanent achter op de installatie
   van de beheerder.
2. Zet de regel van de tabel om van `planned` naar een generated-regel, en
   voeg het `entity_type` toe aan de cascade van de bijbehorende categorie.
3. Geef de categorie een `tier`, een `run_order` en, als het Excel-werkboek
   een bijpassend tabblad heeft, een `excel_sheet`.
4. Voeg een label en toelichting toe in `categoryLabel()` /
   `categoryHint()`. De formulieren voor genereren en wissen pikken de
   categorie daar vanzelf op — die hoef je niet aan te passen.

`run_order` is belangrijker dan het lijkt. Alle afhankelijke generators
putten uit één MT-stroom die één keer per run wordt geseed, dus een
generator vóór een bestaande zetten verandert elke willekeurige waarde
daarna en dezelfde seed levert niet langer dezelfde academie op. Zet hem
achteraan, tenzij je de uitvoer bewust wilt wijzigen.

Inhoudsteksten horen in een array per taal op de generator zelf (zie
`GoalGenerator::TITLES_BY_LANGUAGE`), niet achter `__()`. Gegenereerde rijen
zijn opgeslagen data; ze via gettext laten lopen zou de opgeslagen inhoud
laten afhangen van de vraag of de `.mo`-bestanden toevallig gecompileerd
zijn.

Waar een module rijen schrijft via een hook (tijdlijngebeurtenissen, via
`JourneyEventSubscriber`), vuur je dezelfde action af als de echte functie in
plaats van de rijen zelf te schrijven — zo blijven demotijdlijnen identiek
van vorm aan die in productie. Die rijen moeten alsnog gelabeld worden; zie
`DemoGenerator::tagUntaggedJourneyEvents()`.
