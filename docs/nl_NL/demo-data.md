---
title: Demogegevens
group: configuration
summary: Vul een club met een geloofwaardige academie om TalentTrack te verkennen of te demonstreren, en wis hem daarna weer netjes.
audience: [admin, dev]
module: TT\Modules\DemoData\DemoDataModule
order: 150
---

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
| POP-cyclus | Eén seizoen per jaar dat het venster beslaat, een ontwikkeldossier per speler per seizoen, de gesprekscyclus, agendakoppelingen en eindoordelen |
| Trainingsinhoud | Oefeningen en principes per training, oefeningsuitzonderingen per team, vakantieperiodes |
| Wedstrijddag | Wedstrijdvoorbereiding voor elke wedstrijd, plus uitslagen, doelpunten en wissels voor de gespeelde wedstrijden |
| Testtrainingen | Open trainingen voor uitgenodigde spelers, één in het verleden en één komend per leeftijdsgroep |
| Teamontwikkeling | Een formatie en speelstijlverdeling per team, een wedstrijdblauwdruk, door de trainer gemarkeerde koppels en een chemiereeks |
| Scoutingpijplijn | Scoutingbezoeken over de periode en de prospects die daar zijn gevonden |
| Proefdossiers | Historische proefperiodes van bestaande spelers plus lopende dossiers, elk met beoordelingspanel, beoordelingen en verlengingen |
| Toernooien | Een toernooi per team met selectie, streefminuten, wedstrijden en opstellingen per periode |
| Stafontwikkeling | Trainersdiploma's, ontwikkelplannen en -doelen, beoordelingen met scores, mentorkoppels |
| Berichten en beheerdersgegevens | Gesprekken met leesstatus, opgeslagen filters, rapportsjablonen, workflowtaken, uitnodigingen |
| Gedrag en potentieel | Gedragsbeoordelingen over de periode, en gedateerde potentieelhistories voor teams die oud genoeg zijn om ernaar gevraagd te worden |

Presets bepalen de omvang: **tiny** (1 team, 4 weken), **small** (3 teams,
8 weken), **medium** (6 teams, 16 weken), **large** (12 teams, 36 weken).
Elke preset genereert 12 spelers per team.

De teams worden **gespreid over je leeftijdsladder** in plaats van vanaf de jongste kant genomen, dus een academie van drie teams krijgt een jong team, een ouder team en iets ertussenin. Dat is meer dan variatie: potentieelklassen, POP-cycli en beoordelingen met ontwikkelplannen laat je niet zien op zevenjarigen, en vóór dit was de oudste demospeler zeven. Leeftijdsgroepen zonder leeftijd in de naam — een verzamelgroep **Senior** bijvoorbeeld — worden overgeslagen, omdat de generator het geboortejaar van een speler uit de groepsnaam afleidt en zo'n team anders met kinderen zou vullen.

**Evaluaties komen in twee ritmes**, omdat er twee dingen worden vastgelegd.
**Ronde-evaluaties** worden vier keer per seizoen geschreven — een nulmeting
aan het begin, twee rondes halverwege en een eindevaluatie — een paar dagen
vóór het POP-gesprek dat ze bespreekt, zodat het onderbouwingspaneel bij elk
gegenereerd gesprek de ronde erachter heeft staan. **Wedstrijdevaluaties**
worden geschreven bij de wedstrijden die de run genereert, voor ruwweg een
derde van de wedstrijden die een speler **gespeeld** heeft — met de
tegenstander van die wedstrijd, de uitslag en de minuten die de speler
daadwerkelijk op het veld stond. Een speler die op de bank bleef of niet
inzetbaar was, krijgt geen verslag van die zaterdag: er valt niets te
verslaan. Een venster van drie jaar levert een speler daarmee zo'n twaalf
ronde-evaluaties op in plaats van driehonderd — het verschil tussen een lijst
die een trainer overziet en een lijst waar hij doorheen scrolt.

Beoordelingen worden geschreven **op de schaal die de installatie gebruikt** en
landen op waarden die die schaal kan uitdrukken — geen 6,4 bij een stap van 1.
Een archetype dat vooruitgaat schuift per seizoen minstens een hele stap op,
zodat het ontwikkelverhaal uit de lijst te lezen is zonder een grafiek te
openen.

**Gedrag en potentieel** worden mét hun gaten gevuld. Ongeveer één op de vijf spelers die oud genoeg is voor een potentieelklasse heeft er geen, per team blijft er één te lang onaangeroerd, en bij één wordt de klasse naar **beneden** bijgesteld in plaats van omhoog. Dat is bewust: het stoplicht, de melding *Potentieel niet herzien* en het potentieelverloop bestaan juist om ontbrekende en bewegende gegevens zichtbaar te maken, en een demo waarin nooit iets ontbreekt of te laat is, laat ze lijken op functies die nooit afgaan. Onder de 13 jaar wordt helemaal geen potentieel gevuld — het product vraagt er daar niet naar, dus de demo ook niet.

Het aantal weken is hoe ver het activiteitenvenster **terug** loopt. Daar
bovenop genereert elke preset ook **vier weken vooruit**, zodat een demo-
installatie een volgende wedstrijd en aankomende trainingen heeft — de
weekplanner, wedstrijdvoorbereiding en de meldingen over aankomende
activiteiten hebben dan allemaal iets te tonen. Toekomstige activiteiten staan
op gepland en dragen geen **uitslag**: geen presentielijst, geen minuten, geen
beoordelingen en geen wedstrijduitvoering. Wedstrijdvoorbereiding wordt er wel
voor geschreven — precies zoals het scherm van een trainer er midden in de week
uitziet.

Elk team houdt hetzelfde **weekritme**, op welke dag je ook genereert:
trainingen op **dinsdag en donderdag** van 18:30 tot 20:00, en elke derde week
maakt de donderdagtraining plaats voor een **wedstrijd op zaterdag**, met de
aftrap om 10:00 en verzamelen om 09:15. Een zaterdag in een wedstrijdweek heeft
dus altijd een wedstrijd om minuten, de presentielijst en
wedstrijdbeoordelingen aan op te hangen.

Elke wedstrijd heeft een **tegenstander** en wordt afwisselend **thuis en uit**
gespeeld; bij een uitwedstrijd staat de club van de tegenstander als locatie.
Een gespeelde wedstrijd staat op **Afgerond** in de lijst met
wedstrijduitvoeringen, zoals een wedstrijd die de trainer na afloop heeft
nagekeken en vastgezet.

Een **geplande selectie** hebben ze wél, en elke activiteit uit het verleden
ook. Aanwezigheid bestaat namelijk uit twee dingen — de selectie die de trainer
vooraf opgaf en de presentielijst die hij achteraf afvinkte — en een
gegenereerde academie bevatte alleen dat tweede. Daardoor was de helft van het
aanwezigheidsmodel lokaal onzichtbaar, inclusief elke situatie waarin de twee
van elkaar afwijken: precies waar de meeste aanwezigheidsbugs van het afgelopen
jaar om draaiden.

Een run levert nu alle drie de gevallen op, zodat de schermen die ze afhandelen
iets hebben om op te staan:

- **gepland en afgevinkt** — een activiteit uit het verleden waar allebei
  bestaan voor dezelfde speler; het gewone geval, en het makkelijkst verkeerd
  te lezen;
- **gepland, nog niet gespeeld** — een toekomstige activiteit met een selectie
  en zonder presentielijst;
- **gepland, nooit afgevinkt** — een minderheid van activiteiten uit het
  verleden waarvan niemand de presentielijst heeft ingevuld, zodat de
  bevestiging bij een lege lijst, de volledigheidstellers en de melding
  *aanwezigheid niet vastgelegd* een geval hebben.

Geplande selecties gebruiken de echte planwoordenschat — meestal *Verwacht*,
met wat *Komt niet* en *Misschien* — in plaats van iedereen op aanwezig te
zetten.

## Seizoenen

Het venster bepaalt hoeveel **seizoenen** een run bouwt: één per seizoensjaar
dat hij raakt, volgens de clubconventie van augustus tot juni. Een kort venster
geeft één seizoen; drie jaar historie geeft er drie of vier, afhankelijk van
waar in de kalender de run begint. Een seizoen dat de club al heeft, wordt
hergebruikt in plaats van gedupliceerd, en wordt nooit door een wisbeurt
verwijderd.

Elk seizoen draagt zijn **eigen POP-cyclus** — een dossier per speler, vier
gesprekken, en de voorbereiding van de trainer op elk daarvan. Afgeronde
seizoenen worden **afgesloten met een eindoordeel**; het huidige seizoen blijft
open, op het punt waar het venster het achterlaat.

Een dossier wordt pas afgesloten als **alle gesprekken in de cyclus gevoerd
zijn**. Bij een afgerond seizoen zijn dat ze allemaal, dus sluiten alle
dossiers. Bij een venster dat alleen het huidige seizoen beslaat, sluit een
minderheid van de dossiers waarvan de cyclus al gelopen heeft — en vroeg in
een seizoen, als alle gesprekken nog in de agenda staan, sluit er geen enkel.
Een open dossier met vier gesprekken die nog moeten komen is hoe dat seizoen
er eerlijk uitziet; een afgerond dossier met een ondertekend eindoordeel over
gesprekken die niemand gevoerd heeft, zou zeggen dat het jaar van de speler
klaar was voordat het begon.

Eerdere seizoenen worden **niet gearchiveerd** — gearchiveerde rijen
vallen uit de meeste lijsten, en het grootste deel verbergen van wat er is
gegenereerd is precies het tegenovergestelde van waarom het is gegenereerd.

De selectie **schuift door tussen seizoenen**. Een speler die dit seizoen O13
is, zat vorig seizoen in O12 en het seizoen daarvoor in O11, en zijn
`tt_player_team_history` zegt dat ook — net als het werk zelf: de trainingen
waar hij bij was, de evaluaties die over hem zijn geschreven en de testsessies
waar hij in een eerder seizoen aan meedeed, horen bij het team waar hij **toen**
in zat, niet bij het team van nu. Een team dat de lichtingen van de academie in
een vroeg seizoen nog niet hadden bereikt, krijgt daarin geen trainingen, in
plaats van een kalender vol sessies waar niemand bij was.

Spelers **komen en gaan**. Sommigen sluiten halverwege het venster aan, en een
paar per team zijn aan het eind van een eerder seizoen vertrokken — uitgestroomd,
van elke huidige selectie af, maar met de historie die ze achterlaten intact,
tot en met het dossier waarvan het eindoordeel het vertrek zelf is. In een run
waarvan het venster één seizoen beslaat vertrekt niemand: een selectie kan niet
tussen seizoenen zijn veranderd die er niet twee zijn.

**Eigen aantallen instellen** onder de preset opent drie velden — teams, spelers
per team, weken historie — voorgevuld vanuit de gekozen preset en per run aan te
passen. Dit is de enige manier om het aantal spelers te wijzigen, en daarmee het
aantal demo-accounts: elke preset levert 12 spelers per team, wat past bij een
O15-selectie en niet bij een O8 die zes-tegen-zes speelt. Laat je een veld leeg,
dan wordt de waarde van de preset gebruikt, dus als je niets aanraakt krijg je
precies wat de preset altijd al genereerde. De regel onder de velden toont
tijdens het typen hoeveel spelers en accounts dat oplevert.

Waarden worden begrensd tot wat een run kan afmaken — maximaal **40 teams**,
**40 spelers per team** en **156 weken** (drie jaar) historie. Een getal boven
een maximum wordt niet geweigerd: de run gaat door met het maximum en **zegt
dat ook**, in een melding op deze pagina en als waarschuwing op de
commandoregel, met het gevraagde getal en het gebruikte getal erbij.

Een verzorgerskoppeling heeft een WordPress-account nodig, en de demoset levert
twee ouderaccounts. Niet elke speler krijgt dus een verzorger: elk ouderaccount
krijgt een klein gezin en de rest van de selectie blijft zonder koppeling —
precies zoals het er in een echte academie uitziet, waar ook niet iedere ouder
zich heeft aangemeld.

De twee gezinnen hebben met opzet een verschillende omvang, en die omvang ligt
vast in plaats van dat hij geloot wordt. **Demo Parent** heeft één kind en komt
dus meteen op het dossier van dat kind uit. **Demo Parent Of Two** heeft er
twee, en dat is wat de kindkiezer en de kindwisselaar op het dashboard
bereikbaar maakt. Elke gegenereerde academie laat allebei zien, in plaats van
dat de ouder met meerdere kinderen in ongeveer twee van de drie runs opdook.

Genereren is reproduceerbaar: dezelfde seed, preset en inhoudstaal leveren
elke keer dezelfde academie op — en dezelfde academie of je hem nu in één keer
of stap voor stap laat maken.

Gespeelde wedstrijden krijgen **speelminuten**, afgeleid uit de wissels: een
basisspeler die niet gewisseld is krijgt de hele wedstrijd, wie eruit gaat de
minuut van de wissel, en een invaller wat er nog restte. Een speler die op de
bank bleef krijgt géén minuten in plaats van nul — "niet in actie gekomen" en
"nul minuten gespeeld" zijn verschillende feiten, en juist daarvoor bestaan de
minutenschermen. Wedstrijden in de toekomst leveren geen minuten op; die zijn
nog niet gespeeld.

## Hoe een run verloopt

Een run is een reeks stappen, geen lange wachttijd. De overlay noemt de stap
waar hij mee bezig is — *Stap 7 van 24 — Beoordelingen* — en elke stap is een
eigen korte aanvraag aan de server. Daardoor loopt de grote preset niet meer
vast op de tijdslimiet die je hosting op één aanvraag zet. Precies dat was de
**Proxy Error** bij de grote preset.

Laat het tabblad open tot de overlay klaar is. Sluit je het, of valt de
verbinding weg, dan stopt de run waar hij was en blijven de al weggeschreven
rijen staan — gelabeld, dus een wipe haalt ze alsnog weg. De volgende keer dat
je de pagina opent, staat dat er ook:

> **Een demogeneratie is niet afgemaakt.** 14 van 24 stappen klaar, batch
> `large-20260504`.

**Deze generatie hervatten** pakt de draad op bij de volgende stap.
**Verwerpen** vergeet de run; de rijen die hij schreef blijven in de club tot
je ze wist. Je kunt geen tweede run starten zolang er één openstaat — twee
generaties die tegelijk schrijven, botsen op elke tabel die ze raken.

Staat JavaScript uit in je browser, dan gebeurt de hele run in die ene aanvraag
zoals voorheen. Voor de kleinere presets werkt dat prima; de grote preset is
degene die de stappen nodig heeft.

## Twee keer genereren in dezelfde club

Een tweede run vult aan bij wat er al staat in plaats van het te vervangen. Elke
run bouwt nu zijn eigen academie: de wedstrijden die hij analyseert en de
trainingen die hij observeert zijn de wedstrijden en trainingen die diezelfde
run heeft aangemaakt. Een tweede run in een gevulde club levert dus een
volledige academie op in plaats van een magere. Voorheen rapporteerde de tweede
run lagere aantallen, wat als een fout las terwijl de categorieën in werkelijkheid
de hele club bekeken en oversloegen wat de eerste run al had gedaan.

Twee categorieën werken nog steeds vanuit de hele club, en met opzet:
**stafontwikkeling** en **kenniscursussen** gaan over de mensen die je academie
in dienst heeft, en die bestaan mogelijk al in plaats van dat ze zijn
gegenereerd. Twee keer draaien geeft een trainer geen tweede
stafontwikkelingsdossier en geen tweede inschrijving; die slaan over wat er al
staat, dus hun aantallen kunnen bij de tweede run lager liggen.

Wil je een verse academie in plaats van meer van dezelfde, **wis dan eerst en
genereer daarna**.

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

Elke run krijgt een eigen batch, ook twee runs die in dezelfde seconde
starten. Voorheen konden die er één delen — de batchnaam werd opgebouwd uit
de preset, de seed en de tijd tot op de seconde. Een wisbeurt die op "die
batch" was beperkt nam dan beide runs mee, en de tweede run beschouwde de
spelers en trainingen van de eerste run als de zijne en probeerde hun
gegevens een tweede keer weg te schrijven.

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
