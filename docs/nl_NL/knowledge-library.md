---
title: Kennisbank
group: development
summary: Cursussen voor trainersontwikkeling, geleverd als markdown en gelezen in de app.
audience: [admin, dev]
views: [knowledge, course, lesson, my-learning, submission-review]
module: TT\Modules\Knowledge\KnowledgeModule
feature: knowledge_courses
tier: standard
order: 75
---

# Kennisbank

De kennisbank bevat cursussen voor trainersontwikkeling. Een cursus wordt met
de plug-in meegeleverd als markdown, wordt in de app gelezen, houdt voortgang
bij, vergrendelt lessen en telt — zodra de laatste fase van
[epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
is opgeleverd — afronding per team op.

Deze pagina beschrijft het geheel: wat een cursus op schijf is, hoe de lezer
hem vergrendelt, hoe een les wordt getoetst, en hoe een praktijkopdracht wordt
ingeleverd en beoordeeld.

## Wat er nu is

Alles behalve de statistiekrapportage (#2650):

- het corpusformaat, de parsers en het register
- de tien interactieve blokken
- inschrijving, voortgang en afronding
- de vier gates — module, functie, licentieniveau, rechten — plus opeenvolgende
  ontgrendeling en cursusvereisten
- de lezer: bibliotheek, cursus, les en *Mijn leerlijn*
- toetsen per les, nagekeken op de server
- praktijkopdrachten, met een inleverstroom en een beoordelingswachtrij

De eerste cursus — *Periodiseren in voetbaltaal*, een Nederlandstalige
trainerscursus over voetbalperiodisering — staat in de repository en
registreert zich.

## Het corpus

```
courses/
  voetbalperiodisering/
    course.md                     manifest — alleen front matter
    01-voetbaltaal.md             les
    02-vier-kenmerken.md
    quizzes/
      01-voetbaltaal.json         quizinhoud bij die les
  nl_NL/
    voetbalperiodisering/         vertaling, zelfde structuur
      course.md
      01-voetbaltaal.md
```

Een cursus registreert zich door te bestaan. `CourseRegistry` is een projectie
van de map, geen lijst ernaast — zet er een map met een geldige `course.md` in
en het is een cursus; verwijder de map en hij is weg. Er is geen tweede plek om
te bewerken, en dus ook geen tweede plek om te vergeten.

Een map die het register niet kan lezen wordt overgeslagen in plaats van fataal,
zodat een half afgeschreven cursus in een branch nooit de pagina van een lezer
breekt. `check-courses.php` maakt van die stilte een CI-fout.

## Manifestsleutels

Front matter wordt gelezen door `DocFrontMatter`, dat scalars en inline lijsten
aankan en verder niets. Het is bewust geen YAML-implementatie; heeft een cursus
een rijkere structuur nodig, dan is het antwoord minder sleutels, geen grotere
parser.

| Sleutel | Verplicht | Betekenis |
| --- | --- | --- |
| `title` | ja | Titel in de bibliotheek en boven de cursus |
| `lessons` | ja | Lesslugs **in lesvolgorde** |
| `summary` | lint | Eén zin onder de titel in de bibliotheek |
| `source_lang` | nee | Taal waarin de canonieke bestanden geschreven zijn |
| `audience` | nee | Persona's waarvoor de cursus geschreven is |
| `capability` | nee | Benodigd recht. Leeg betekent niet afgeschermd |
| `feature` | nee | Deelfunctie waarmee de cursus uitgezet wordt |
| `tier` | nee | Licentieniveau. Standaard `standard` |
| `requires` | nee | Cursussen die eerst afgerond moeten zijn |
| `methodology_principles` | nee | Onderwerpen die de cursus behandelt. Alleen beschrijvend — zie hieronder |
| `certification_name` | nee | Naam van het certificaat dat afronding oplevert. Standaard de titel |
| `valid_for_months` | nee | Hoe lang dat certificaat geldig blijft. Afwezig betekent: verloopt niet |
| `estimated_hours` | nee | Studielast |
| `sequential` | nee | Of lessen op volgorde vrijkomen. Standaard `true` |

Twee daarvan zijn minder vanzelfsprekend dan ze lijken.

**`lessons` bepaalt de volgorde.** Niet de bestandsnamen. Een genummerde
bestandsnaam is handig voor wie de map opent; les 4 van tien intrekken zou
anders betekenen dat je zes bestanden hernoemt en elke opgeslagen
`lesson_slug` in de voortgangstabel ongeldig maakt.

**`source_lang` benoemt de taal van de canonieke bestanden.** Het documentatie-
corpus is Engels-eerst met vertalingen onder `docs/<locale>/`. De eerste cursus
is Nederlands-eerst. In plaats van een Engelse huls die niemand geschreven
heeft, benoemt een cursus zijn eigen brontaal en valt de lezer daarop terug —
wie een taal gebruikt zonder vertaling krijgt de cursus in de taal waarin hij
geschreven is, en de interface kan dat zeggen.

## Lessleutels

| Sleutel | Betekenis |
| --- | --- |
| `title` | Verplicht. Een les zonder titel registreert niet |
| `objectives` | Leerdoelen, bovenaan de les getoond |
| `assignment` | `true` als afronden een goedgekeurde opdracht vereist |
| `quiz` | `true` als afronden een behaalde quiz vereist |
| `estimated_minutes` | Leestijd |

`assignment` en `quiz` zijn declaraties, geen inhoud. De opdrachttekst staat in
de body als `tt-assignment`-blok; de quizinhoud staat in
`quizzes/<les>.json`. Door de declaratie in front matter te houden is de
structuur van een cursus te beantwoorden zonder tien lesbodies te lezen — en
dat is precies wat de bibliotheekpagina nodig heeft.

Beide staan standaard op `false`, zodat een les zich alleen aanmeldt voor een
eis.

## Interactieve blokken

Markdown is het opslagformaat, niet de weergave. Een les wordt via PHP naar
HTML gerenderd, en interactieve elementen zijn gemarkeerde secties waarvan de
inforegel een renderer noemt:

````markdown
```tt-zeropoint method="extensive_endurance"
```

```tt-callout type="warning"
Bij 7v7 is de berekende breedte te smal.
```
````

`BlockRegistry` koppelt de inforegel aan een klasse die `BlockRenderer`
implementeert. Elke renderer levert `.tt-*`-markup en geeft aan of hij het
blokscript nodig heeft, zodat een les met alleen tekst en callouts geen
JavaScript laadt.

Een inforegel die niemand opeist, wordt als codeblok weergegeven. Een cursus
die voor een nieuwere versie is geschreven en op een oudere wordt geopend,
verliest één element in plaats van de hele les.

| Blok | Attributen | Interactief |
| --- | --- | --- |
| `tt-callout` | `type` — `objectives`, `key`, `warning`, `note` | nee |
| `tt-reveal` | `question` | nee |
| `tt-check` | `prompt`, `answer` | ja |
| `tt-actionline` | regels: `label \| kwaliteit% \| seconden` | nee |
| `tt-model` | — | nee |
| `tt-pitchsize` | `format` | ja |
| `tt-zeropoint` | `method` | ja |
| `tt-weekplanner` | — | ja |
| `tt-loadmatrix` | `cycle`, `cycles` | ja |
| `tt-quiz` | — | ja |
| `tt-assignment` | `id` | ja |

Elk blok rendert server-side een bruikbare weergave. Het script verbetert die
weergave; het maakt hem nooit. Een lezer met geblokkeerde JavaScript krijgt
nog steeds de veldmatentabel, het model en de standaardbelastingmatrix.

### De tussentijdse check (#2738)

```
```tt-check answer="B" prompt="Ajax speelde zaterdag. Kan 4v4 op dinsdag?"
- A. Ja, twee dagen is genoeg
- B. Nee, 4v4 vraagt 72 uur herstel
- C. Alleen als de wedstrijd kort was
> 4v4 is de meest intensieve vorm en vraagt drie dagen.
```
```

De antwoorden zijn `- A. tekst`-lijstregels; de uitleg is het blockquote dat
erop volgt. Allebei gewone markdown, zodat een check ook in een ruwe diff
leest als een vraag met een antwoord — precies waarvoor het corpus in markdown
staat.

**Waarom hij naast `tt-quiz` en `tt-reveal` bestaat.** De toets staat aan het
eind van de les, en dat is de verkeerde plek om te ontdekken dat je alinea twee
verkeerd las. `tt-reveal` staat op de goede plek maar vraagt niets — een
`<details>` gaat open of je nu nagedacht hebt of niet. Je antwoord vastleggen
vóórdat je het juiste ziet is het mechanisme waar ophaaloefening op draait, en
alleen `tt-check` dwingt dat af.

**Nagekeken in de browser, en dat is geen gat in de regel van de toets.**
`tt-quiz` houdt zijn antwoordsleutel op de server omdat er een score op het
spel staat: die ontgrendelt de volgende les en belandt in het dossier van een
trainer. Een check legt niets vast, ontgrendelt niets en komt in geen enkel
rapport, dus er is geen resultaat om te beschermen — en wat hij wél nodig heeft
is een oordeel zonder netwerkverkeer. Wie het antwoord uit de DOM vist, slaat
een oefening over die hij ook had kunnen overslaan door door te scrollen.

**Zonder JavaScript** zijn de opties radioknoppen in een `<details>`: kies er
een, klap open, lees het antwoord. Dat is het gedrag van `tt-reveal`, en dat is
de juiste terugval. Het script maakt er een direct oordeel van en zet de opties
vast — antwoorden kan één keer, want net zo lang proberen tot het groen wordt
maakt van ophalen gokken.

`course-lint` faalt op een check zonder prompt, met minder dan twee opties,
zonder antwoord, met een antwoord dat geen bestaande optie noemt, of zonder
uitleg. De gate leest de body via `CheckBlock::inspect()` en niet met een eigen
parser, zodat lint en renderer niet uit elkaar kunnen lopen.

### Eén bron voor de getallen

Supercompensatietijden, de overloadstaptabellen, de veldmaatregel en de
sessietypes staan in `Periodisation`. `tt-zeropoint`, `tt-weekplanner` en
`tt-pitchsize` lezen ze, en het script krijgt dezelfde waarden via
`wp_localize_script`.

Dat is belangrijker dan het lijkt: een cursus die leert dat 4v4 72 uur vraagt
naast een planner die bij 48 waarschuwt, is slechter dan elk van de twee
afzonderlijk. Zodra de Training-module deze getallen nodig heeft (#2493),
leest hij ze hier.

De staptabellen zijn uitgeschreven, niet gegenereerd. Ze vormen geen
rechthoekig raster — na 2 × 15 volgt 3 × 11, niet 3 × 10 — en een gegenereerd
raster verschuift elk stapnummer vanaf de zevende.

### Een blok toevoegen

Implementeer `BlockRenderer` en voeg de klasse toe aan
`BlockRegistry::all()`, of haak in op `tt_knowledge_blocks`. Escape alles wat
je invoegt: het corpus wordt meegeleverd, maar vertaalde cursussen worden
bewerkt door mensen die geen PHP nakijken.

## Quizinhoud

```json
{
  "pass_mark": 4,
  "questions": [
    {
      "id": "q1",
      "type": "single",
      "prompt": "Hoeveel uur supercompensatie vraagt 4v4/3v3?",
      "options": ["24", "48", "72", "96"],
      "answer": 2,
      "explanation": "..."
    }
  ]
}
```

Vraagtypes: `single` (één index), `multiple` (reeks indexen), `order` (alle
optie-indexen in de juiste volgorde), `match` (een `pairs`-reeks, plus één
optie-index per paar, in paarvolgorde).

Antwoorden worden opgeslagen als optie-indexen. Het nakijken gebeurt
server-side zodra de lezer in
[#2647](https://github.com/caspernieuwenhuizen/talenttrack/issues/2647) landt —
de antwoordsleutel mag nooit in de browser terechtkomen.

## Inschrijving en voortgang

Cursussen zijn bestanden; de relatie van een persoon tot een cursus is data.
Migratie 0225 voegt vier tabellen toe.

| Tabel | Bevat |
| --- | --- |
| `tt_course_enrolments` | één persoon op één cursus — status, deadline, gestart, afgerond. Hoofdentiteit, met `uuid` |
| `tt_course_progress` | één rij per les: gelezen, quiz behaald, opdracht goedgekeurd, plus `tool_state` |
| `tt_course_quiz_attempts` | elke poging, niet alleen de laatste |
| `tt_course_submissions` | een opdracht en het oordeel erover. Hoofdentiteit, met `uuid` |

`course_slug` en `lesson_slug` zijn tekstwaarden zonder tabel erachter. Een
slug die niet meer oplost is een cursus die in een latere release is
teruggetrokken; die rijen worden getoond als **teruggetrokken**, nooit
verwijderd — de afrondingsgeschiedenis van een trainer moet de cursus
overleven.

Bijlagen bij opdrachten staan niet in `tt_course_submissions`. Ze lopen via
`tt_media_links` met `entity_type = 'course_submission'`, zodat een foto van
een whiteboard door dezelfde afgeschermde opslag en levenscyclus gaat als elk
ander bestand.

### Wanneer is iets afgerond

`CourseCompletionService` bezit die regel, en is de enige plek waar hij staat —
de lezer, de vergrendeling (#2645) en het overzichtsrapport (#2650) vragen het
allemaal daar op in plaats van het zelf te bepalen.

Een **les** is afgerond als aan elke eis uit de front matter is voldaan: altijd
lezen, de quiz behalen bij `quiz: true`, de opdracht goedgekeurd krijgen bij
`assignment: true`. Een **cursus** is afgerond als elke les uit het manifest
afgerond is.

De eisen worden bij elke aanroep uit het corpus gelezen, nooit vastgelegd op de
inschrijving. Een cursusherziening die een les toevoegt heropent daarmee de
mensen die de oude versie hadden afgerond, in plaats van ze gecertificeerd te
laten voor iets wat ze niet gedaan hebben. `percent` rondt naar beneden af, dus
negen van de tien lessen leest als 90%.

Twee hooks vuren op de overgang, elk één keer:

| Hook | Vuurt wanneer |
| --- | --- |
| `tt_knowledge_course_completed` | een inschrijving wordt afgerond |
| `tt_knowledge_course_reopened` | een afgeronde inschrijving dat niet meer is |

De certificeringsbrug en de methodiekkoppeling (#2649) hangen aan de eerste,
zodat de completion-service niet hoeft te weten wat er daarna gebeurt.

## De lezer

Vier routes, alle vier eigendom van de deelfunctie `knowledge_courses` — de
functie uitzetten haalt dus ook de routes weg, niet alleen de tegels, en een
opgeslagen les-URL houdt op te werken in plaats van een scherm te tonen dat de
academie heeft uitgezet.

| Slug | Scherm |
| --- | --- |
| `knowledge` | Bibliotheek. Tegel in de groep **Leren** |
| `course` | Eén cursus: status, voortgang, lessenlijst. `?slug=` |
| `lesson` | De lezer. `?slug=&lesson=` |
| `my-learning` | Het eigen dossier. Tegel in de groep **Mij** |

### Navigatie (CLAUDE.md §5)

De keten is `Dashboard › Kennisbank › Cursus › Les`. De cursuskruimel is de weg
terug naar de cursus en de kennisbankkruimel de weg terug naar de bibliotheek —
er is geen aparte terugknop, want dat zou de derde affordance zijn die §5a
verbiedt.

`RecordSpine` zet de cursusnaam vast op het cursus- en lesscherm, zodat een
trainer elf lessen ver niet omhoog hoeft te scrollen om te zien waar hij zit.
**Geen tabbladen**: een cursus heeft één weergave, geen alternatieve
weergaven van hetzelfde record, en de tabsleuf van de spine gebruiken om hem
te gebruiken zou decoratie zijn.

Elke link tussen deze schermen loopt via `CrossViewLink::render()` met een
poort geregistreerd in `CoreSurfaceRegistration`, zodat een link naar een
scherm dat de lezer niet kan openen helemaal niet wordt getoond (§7 / #2304).
`KnowledgeLinks` bouwt de URL's en hangt er altijd de `tt_back`-hint aan; het
bepaalt bewust niet de zichtbaarheid.

### Verdergaan, en een les als gelezen markeren

Een cursus openen gaat naar de **eerste onafgeronde les**, niet naar les één.
Een les openen schrijft de lezer bij de eerste aanraking in — een aparte
inschrijfstap voordat je les één kunt lezen is een stap die niemand zou
begrijpen.

Een les als gelezen markeren is een **expliciete handeling**, nooit een
scrollmeting. Wie doorbladert en klikt, doet een uitspraak; een scrollmeting
meet alleen een duim. Het is een echte formulierpost, dus de les kan ook
zonder JavaScript worden afgerond; `knowledge-reader.js` maakt er een
opslag-zonder-herladen van.

Het slotblok noemt ook wat de les nog vraagt — een behaalde toets, een
goedgekeurde opdracht — want wie een les als gelezen markeert en het
cursuspercentage niet ziet bewegen, moet weten dat het de quiz is en geen
storing.

### Status van de interactieve blokken

`tt-zeropoint` en `tt-weekplanner` bewaren hun status in
`tt_course_progress.tool_state` via
`PATCH /courses/{slug}/progress/{lesson}`, met vertraging gebundeld. De
nulpuntmeting uit module 4 staat er in module 11 nog, waar de eindopdracht
erom vraagt.

Daarom is `knowledge-reader.js` als *afhankelijke* van
`knowledge-blocks.js` ingeladen: het zet `window.TTKnowledge.savedState` bij
het parsen, dus vóór beide scripts opstarten op `DOMContentLoaded`, zodat de
blokken hun bewaarde status tijdens hun eigen init lezen in plaats van er
achteraf opnieuw mee te worden opgestart. De eerste weergave slaat bewust
niets op — tonen wat er al stond is geen wijziging, en terugschrijven zou het
dossier bij elke paginaweergave aanraken.

### Toetsen

Een les met `quiz: true` moet ook een `tt-quiz`-blok bevatten, anders worden de
vragen door niets nagekeken en verschijnen ze nergens. De corpus-lint faalt als
een van beide helften ontbreekt — een regel die is toegevoegd nadat #2647 tien
lessen vond met geldige vragen en geen blok.

**Nakijken gebeurt server-side, en dat is geen voorkeur.** De vragenlijst bevat
de antwoordsleutel, dus een nakijker in de browser zou die sleutel moeten
krijgen om zijn werk te doen. `QuizScorer` kijkt na; de browser stuurt alleen
wat is ingevuld en toont wat terugkomt.

**Opties worden per weergave geschud.** Elk `order`- en `match`-antwoord in het
meegeleverde corpus is de identieke volgorde — de opgeslagen volgorde *is* het
juiste antwoord — dus de opties tonen zoals ze zijn opgeslagen zou het antwoord
op elke volgordevraag weggeven. `single` en `multiple` worden ook geschud,
zodat een auteur die het juiste antwoord gewoontegetrouw vooraan zet geen
patroon door de cursus heen lekt.

Omdat er per weergave geschud wordt, kan de browser geen indexen insturen: die
zouden posities benoemen die de server alweer vergeten is. Hij stuurt
**optielabels**, die de lezer toch al ziet, en de server zoekt ze terug. Geen
index en geen antwoordsleutel gaat over de lijn, en er hoeft geen status per
weergave bewaard te worden. De corpus-lint garandeert dat geen twee opties in
één vraag hetzelfde label hebben; dat maakt het terugzoeken eenduidig.

| Type | Bediening | Verstuurt |
| --- | --- | --- |
| `single` | radiogroep | één label |
| `multiple` | selectievakjes | labels, volgorde doet er niet toe |
| `order` | een nummerveld per optie | `label => positie`, server-side gesorteerd |
| `match` | een keuzelijst per linkeritem | één label per paar, in paarvolgorde |

Volgordevragen werken met nummervelden, niet met slepen. Slepen is prettiger
met een muis en onbruikbaar met een toetsenbord, en §2 vereist sowieso een
niet-gebaarpad — een positie typen *is* dat pad, geen noodoplossing ernaast.

**Geen deelpunten.** Een meerdelig antwoord is het antwoord of niet; een halve
volgorde is geen half begrip van de reeks. Een overgeslagen vraag is fout en
niet genegeerd, want een toets waarbij overslaan niets kost, haal je door
alleen te beantwoorden waar je zeker van bent.

**Elke poging wordt bewaard**, geslaagd of niet, in
`tt_course_quiz_attempts`. Opnieuw proberen mag onbeperkt. Slagen schrijft
`quiz_passed_at` op de voortgangsrij van de les; dat is wat de
volgordevergrendeling leest.

Toelichtingen komen terug bij goede én foute antwoorden: wie goed gokte leert
evenveel van de reden als wie het fout had.

De toets is een echt `<form>`. Zonder JavaScript wordt hij gepost, door
dezelfde `QuizScorer` nagekeken en met het resultaat boven de les opnieuw
weergegeven; `knowledge-quiz.js` maakt daar een verzending zonder herladen van
met dezelfde veldnamen, zodat beide paden per constructie dezelfde inhoud
versturen.

### Vergrendeling

Zes poorten, in één doorloop opgelost. De eerste vier zijn gedeeld met het
helpcorpus en staan in `TT\Shared\Content\ContentGate`; de laatste twee gaan
over wat de cursist gedaan heeft en staan in `CourseAccessResolver`.

| Poort | Bron | Soort oordeel |
| --- | --- | --- |
| `module` | `ModuleRegistry::isEnabled()` | niet beschikbaar |
| `feature` | `FeatureRegistry::isEnabled()` | niet beschikbaar |
| `tier` | `LicenseGate::effectiveTier()` | niet beschikbaar |
| `capability` | `current_user_can()` / `user_can()` | geweigerd |
| `requires:` | vereiste cursus niet afgerond | vergrendeld |
| `sequential:` | vorige les niet afgerond | vergrendeld |

De drie soorten zijn niet uitwisselbaar. **Niet beschikbaar** betekent dat
deze installatie het niet heeft, en geen enkel recht verandert daar iets aan.
**Geweigerd** betekent dat het er wel is en dat iemand anders het wel ziet.
**Vergrendeld** betekent dat je het straks kunt zien, zodra je eerst iets
gedaan hebt. Voor alle drie dezelfde melding tonen is hoe een product een
hoofd opleiding naar de beheerder stuurt voor een functie die niet in hun
licentie zit.

Gevolgen:

- Niet-beschikbare en geweigerde cursussen zijn **afwezig** in de kennisbank
  en geven **404**, geen 403 — een 403 bevestigt dat de cursus hier bestaat,
  en dat is precies wat verbergen moest voorkomen.
- Vergrendelde cursussen en lessen blijven **zichtbaar**, met hun oordeel. Een
  vergrendelde les verbergen laat een cursus korter lijken dan hij is.
- De vergrendeling wordt op het **schrijfpad** afgedwongen, niet alleen in de
  lezer. Een les verbergen heeft geen zin als
  `PATCH …/progress/{lesson}` hem alsnog als gelezen markeert; die route geeft
  403 met het oordeel erbij.

Twee conventies bewust overgenomen van de registers:

**Een ontbrekende sleutel is geen poort.** Inhoud zonder `module:` wordt nooit
op module afgeschermd.

**Een onbekende waarde laat de inhoud zichtbaar.** Een typefout in
`feature: knowlege_courses` mag een cursus niet stilletjes verbergen — dat is
een fout die je maanden later ontdekt, of nooit. De corpus-lint vangt de
typefout.

Niets in de vergrendeling wordt gecachet: module- en functiestatus zijn tijdens
runtime aanpasbaar en rechten zijn per gebruiker, dus een gecachet oordeel zou
betekenen dat een moduleschakelaar pas werkt na de volgende plug-in-update.

### Rechten

| Recht | Geeft toegang tot |
| --- | --- |
| `tt_view_knowledge` | de kennisbank zien, een cursus doorlopen, **je eigen** dossier zien |
| `tt_view_knowledge_statistics` | de voortgang van iedereen zien |
| `tt_manage_knowledge` | toewijzen, deadlines zetten, uitschrijven, opdrachten beoordelen |

Drie niveaus in plaats van het gebruikelijke view/manage-paar, omdat een
trainer zijn eigen voortgang moet kunnen zien zonder die van zijn collega's.
Het overzicht onder `tt_view_knowledge` schuiven zou een verborgen kolom het
enige maken tussen een trainer en de cijfers van zijn collega's.

### REST

```
GET    /talenttrack/v1/courses                              catalogus + jouw status
GET    /talenttrack/v1/courses/{slug}                       manifest + status per les
POST   /talenttrack/v1/courses/{slug}/enrolments            zelf inschrijven, of toewijzen
PATCH  /talenttrack/v1/courses/{slug}/progress/{lesson}     gelezen markeren, tool-state bewaren
POST   /talenttrack/v1/courses/{slug}/submissions/{lesson}  een opdracht inleveren
GET    /talenttrack/v1/submissions                          jouw beoordelingswachtrij
PATCH  /talenttrack/v1/submissions/{id}                     een oordeel vastleggen
DELETE /talenttrack/v1/enrolments/{id}                      uitschrijven
GET    /talenttrack/v1/people/{id}/learning                 het dossier van één persoon
```

Een les als gelezen markeren schrijft de lezer bij de eerste aanraking in — een
aparte inschrijfstap voordat je les één kunt openen is een stap die niemand zou
begrijpen.

Een oordeel is een `PATCH` op de inzending en geen `/approve`-werkwoord: de
uitkomst is een veld op een record, en het als actie modelleren zou een tweede
endpoint vragen zodra iemand een goedkeuring wil intrekken.

## Praktijkopdrachten en beoordeling

Elke module van de periodiseringscursus eindigt in een praktijkopdracht die de
trainer met zijn eigen team uitvoert. Een toets kan vaststellen dat een trainer
wéét dat 4v4 tweeënzeventig uur nodig heeft; alleen een mentor die een
ingeleverd twaalfwekenplan leest, kan vaststellen dat hij er één gemaakt heeft.
Daarom heeft afronding een mens nodig, en daarom bestaat de wachtrij.

### Inleveren

Het `tt-assignment`-blok in de les toont de opdracht en daaronder de stand van
het eigen werk — niets, in beoordeling, wijzigingen gevraagd, of het eindoordeel.
Het is een gewoon `<form method="post">`, dus inleveren hangt nooit van
JavaScript af.

Er is ook een begeleide route op
`?tt_view=wizard&tt_wizard=submit-assignment&slug={cursus}&lesson={les}`:
schrijven → bijlagen → bevestigen. Beide eindigen in dezelfde aanroep van
`SubmissionService`.

Opnieuw inleveren maakt altijd een **nieuwe rij**. De eerdere poging en de
feedback erop blijven staan — die geschiedenis ís het verslag van de
begeleiding, en overschrijven zou de kant van de beoordelaar wissen.

### De vier toestanden

| Toestand | Wat de trainer ziet | Kan hij inleveren? |
| --- | --- | --- |
| niets ingeleverd | de opdracht en een formulier | ja |
| in beoordeling | wat hij schreef, en dat het bij een beoordelaar ligt | nee |
| wijzigingen gevraagd | de feedback, en opnieuw het formulier | ja |
| goedgekeurd / afgewezen | het oordeel | nee |

Een afwijzing opent het formulier niet opnieuw. Wijzigingen vragen is hoe een
beoordelaar om een nieuwe poging vraagt; die twee samenvoegen zou geen manier
overlaten om nee te zeggen en het te menen.

### Wie beoordeelt

Eerst mentorschap, dan rechten:

1. de mentor van de leerling uit `tt_staff_mentorships`, als die er is
2. anders niemand — de inzending blijft ongerouteerd en is zichtbaar voor
   **iedere** houder van `tt_manage_knowledge`

Ongerouteerd is een toestand, geen fout. Bij het inleveren willekeurig een
rechthebbende aanwijzen ziet er in de kolom netter uit en maakt stilzwijgend
iemand verantwoordelijk voor een wachtrij waarover niemand hem heeft
ingelicht.

De beoordelaar wordt bepaald **op het moment van inleveren** en op de rij
vastgelegd, nooit opnieuw berekend bij het lezen. Een mentorschap dat halverwege
eindigt mag een inzending niet laten verdwijnen uit de wachtrij van degene die
hem al aan het lezen is.

Een houder van `tt_manage_knowledge` mag over alles oordelen, ook over
gerouteerd werk — iemand moet de wachtrij kunnen leegmaken als een mentor
afwezig is, en de rij legt vast wie feitelijk besliste.

Let op: een mentor heeft geen beheerrecht. De wachtrij op zo'n recht
vergrendelen zou hem verbergen voor precies de mensen naar wie werk gerouteerd
wordt, dus alle beoordelingsoppervlakken vergrendelen op
`ReviewerResolver::isReviewer()` — tegel, doorverwijzing, weergave en
REST-route.

### Feedback is verplicht, behalve bij goedkeuren

Goedkeuren hoeft niet onderbouwd. Wijzigingen vragen of werk afwijzen wél, en
een lege onderbouwing wordt geweigerd in plaats van opgeslagen — een oordeel
zonder reden is geen beoordeling. Afgedwongen in `SubmissionService`, zodat het
formulier en de API hetzelfde zeggen.

### Wat goedkeuren doet

Goedkeuren zet `tt_course_progress.assignment_approved_at` en draait
`CourseCompletionService::recalculate()` opnieuw. Een goedkeuring intrekken wist
het veld en herberekent opnieuw, wat de cursus kan terugzetten naar onafgerond.
Het veld laten staan zou een certificaat overeind laten op werk dat een
beoordelaar inmiddels heeft ingetrokken.

### Bijlagen

Alleen documenten — PDF, Word, spreadsheet, OpenDocument of platte tekst. Geen
foto's en geen video.

Dat is een veiligheidsgrens en geen voorkeur. Bijlagen liften mee op de
mediabibliotheek (`tt_media_links` met `entity_type = 'course_submission'`), en
een inzending hangt aan geen enkele speler en geen enkel team — een afbeelding
zou daardoor buiten de toestemmings-, zichtbaarheids- en bewaarregels vallen die
spelermedia beheersen, en op een trainingsfoto kunnen minderjarigen staan. De
opdrachten vragen om geschreven plannen, dus de smalle route kost niets.

`MediaAttachmentPolicy` neemt die beslissing. De uploader leest hem — daarom
biedt hij hier geen camera — en `create_media` controleert de vastgestelde soort
opnieuw voordat er iets wordt weggeschreven, want `accept` is een hint aan een
bestandskiezer en geen slot.

Wie een bijlage mag openen: de trainer die hem inleverde, de beoordelaar naar
wie hij gerouteerd is, en houders van `tt_manage_knowledge`. Dat is de enige tak
in `MediaVisibilityService` die geen matrixbereik is, omdat een inzending geen
team heeft om op te scopen.

### De beoordelingswachtrij

`?tt_view=submission-review`, oudste eerst — een wachtrij met de nieuwste
bovenaan laat wie in een drukke week inleverde verhongeren. Elke kaart toont de
opdrachttekst uit het corpus boven de inzending, zodat een beoordelaar die "ik
mat 4v4 op elf minuten" leest weet wat er gevraagd was.

### De melding

`knowledge.submission_awaiting_review` is een toestandsmelding, geen
gebeurtenismail. De wachtrij ís een *toestand*: een melding vertelt wat er nú
ligt en lost zichzelf op zodra de wachtrij leeg is, waar een "er is iets
ingeleverd"-mail één bericht per inzending geeft en geen beeld van wat er nog
open staat.

Eén melding per inzending, zodat een wachtrij van vijf ook als vijf dingen
leest. `info` bij binnenkomst, oplopend naar `attention` na een week.

## De CI-gate

`tools/check-courses.php` draait op elke PR die `courses/`, de Knowledge-module
of `DocFrontMatter` raakt. De gate faalt bij:

- een cursusmap zonder `course.md`, of een manifest dat niet leest
- een ontbrekende `summary`
- een `tier` die de License-module niet kent — die lijst wordt uit `FeatureMap`
  gelezen, niet hier herhaald
- een les in `lessons:` zonder bestand, of een les die niet leest
- een lesbestand op schijf dat `lessons:` niet noemt
- een `requires:`-slug die geen cursus is, of een cursus die zichzelf vereist
- een les met `quiz: true` zonder inhoud, ongeldige JSON, geen vragen, een
  ontbrekende of onhaalbare `pass_mark`, een dubbel vraag-id, een onbekend
  vraagtype, minder dan twee opties, of een antwoordindex buiten bereik
- een `tt-check` zonder prompt, met minder dan twee opties, zonder antwoord,
  met een antwoord dat geen bestaande optie noemt, of zonder uitleg
- een vertaalde les zonder canonieke tegenhanger

Lokaal draaien: `php tools/check-courses.php`. De gate heeft geen WordPress
nodig en gebruikt de echte parsers, zodat hij niet uit de pas kan lopen met wat
hij bewaakt.

## Afronding wordt een certificaat

Een cursus afronden schrijft een rij in `tt_staff_certifications` — de tabel
die StaffDevelopment al beheert (#2649). Die ene rij is wat de kennisbank
uit haar isolement haalt: de afronding verschijnt op het stafdossier en in het
PDP van de trainer, de clubbrede vervalrapportage pikt hem op, en de melding
over verlopende certificaten kan om een opfrisser vragen — zonder dat de
kennisbank van die schermen af hoeft te weten.

Hier wordt CLAUDE.md §1 ook echt ingevuld in plaats van uitgezonderd. De
spelersvraag is dezelfde die `StaffCertificateExpiringAlert` van de andere kant
beantwoordt: *elke speler in de selectie heeft er belang bij dat degene die de
training geeft daarvoor gekwalificeerd is.*

**Beide overgangen.** `tt_knowledge_course_completed` verstrekt;
`tt_knowledge_course_reopened` archiveert. Dat tweede is belangrijker dan het
klinkt, want #2648 maakte goedkeuring terugdraaibaar — een beoordelaar die een
goedkeuring intrekt maakt de cursus onafgerond, en een certificaat dat dan
blijft staan is een kwalificatie op ingetrokken werk, middenin de
vervalrapportage van de club. Archiveren en niet verwijderen: een verstrekt en
weer ingetrokken certificaat is een feit over de begeleiding.

**Idempotent.** `tt_course_enrolments.certification_id` onthoudt welke rij een
afronding opleverde, dus twee keer afronden ververst in plaats van te
verdubbelen, en een cyclus afronden → heropenen → afronden hergebruikt één rij
in plaats van een spoor van gearchiveerde duplicaten achter te laten.

**De lookup-rij.** Migratie 0231 seedt het `cert_type` voor de meegeleverde
cursus, met het Nederlandse label in **`tt_translations`** —
`tt_lookups.translations` is in migratie 0087 verwijderd, dus een seed die
daarheen schrijft verliest het label geruisloos. Elke andere cursus zoekt of
maakt zijn type bij afronding, zodat een nieuwe cursus nooit een migratie
nodig heeft. `KnowledgeCertificationTest` bewaakt dat de geseedde naam en de
`certification_name` uit het manifest gelijk blijven: lopen ze uiteen, dan
ontstaat er een tweede, onvertaalde rij en raakt het geseedde label wees —
zonder dat er iets faalt.

### Is de staf rond deze selectie opgeleid?

`TeamCourseCoverage::forTeam( $team_id, $course_slug )` beantwoordt het in één
query, met `tt_user_role_scopes` (staf gekoppeld aan het team) tegen
`tt_course_enrolments`. Bewust een `LEFT JOIN`: wie nooit begonnen is, ís het
antwoord op de vraag en geen rij om weg te laten.

**Dit koppelt niet op methodiek, en het epic zei van wel.** Bij het bouwen
bleek waarom dat niet kan. `tt_principles` bevat tactische spelprincipes met
codes als `AO-01`; `tt_methodologies` bevat *speel*methodieken — formaties,
principes, standaardsituaties — en de meegeleverde standaard is
`jo14-1-hedel`. De periodiseringscursus gaat over fysieke periodisering, een
trainingsmethodiek, en hoort bij geen van beide. Hem aan een speelmethodiek
koppelen zou een verband claimen dat er niet is.

`methodology_principles` blijft daarom beschrijvend: een aanduiding van wat de
cursus behandelt, gelezen uit het corpus wanneer iets het wil tonen. Het wordt
niet in de database gekopieerd, want het corpus is met de plug-in
meegeversioneerd en een kopie kan alleen maar verouderen.

## Een cursus toewijzen

`?tt_view=wizard&tt_wizard=assign-course` — cursus → personen → deadline →
bevestigen. Vereist `tt_manage_knowledge`.

De personenstap filtert de staf op de persona's uit de `audience` van de
cursus, en valt terug op de volledige staflijst **met zichtbare uitleg** als
niemand matcht — wat gebeurt op elke installatie waar stafdossiers wel
bestaan maar accounts nog niet gekoppeld zijn. Een beheerder die ziet waaróm
de lijst ongefilterd is kan daar iets aan doen; wie naar een lege stap staart
kan alleen gissen.

Opnieuw toewijzen doet niets. `EnrolmentRepository::enrol()` is gesleuteld op
`(club_id, course_slug, person_id)`, en de bevestigingsstap meldt hoeveel
personen echt nieuw zijn, zodat de wizard over een selectie draaien "3 nieuw,
12 al ingeschreven" toont in plaats van een stille succesmelding. Een
bestaande inschrijving blijft precies zoals hij was, deadline inbegrepen —
iemands deadline wijzigen is een andere beslissing dan iemand een cursus
toewijzen.

## De statistiekrapportage

Drie invalshoeken in de Reports-module (#2650), bereikbaar via de
rapportagelauncher en `?tt_view=standard-report&slug=learning-…`:

| Slug | Beantwoordt |
| --- | --- |
| `learning-courses` | Per cursus: ingeschreven, niet begonnen, bezig, afgerond, te laat, mediaan aantal dagen, en de les waar lezers stoppen |
| `learning-people` | Per persoon: cursussen, percentage afgerond, te laat, in beoordeling, laatste activiteit |
| `learning-teams` | Per team, per cursus: hoeveel van de staf rond die selectie klaar is |

Gebouwd als standaardrapportages en niet als eigen pagina, zodat ze de
launcher, de aan/uit-schakelaar per rapportage, de paginakop en de
`.tt-rep-*`-stijlen erven. De chrome zit in `FrontendStandardReportsView`; de
tabellen in `Knowledge\Frontend\LearningReports`, wat de opmaak van een module
buiten een gedeeld bestand van 1.500 regels houdt zonder dat het oppervlak
afwijkt.

Alle aggregatie zit in `LearningStatisticsService` (§4) — één gegroepeerde
query per vraag, nooit één per rij. Rapportage en REST kunnen het daardoor
niet oneens zijn over wat "afgerond" betekent.

### Waar lezers vastlopen

`dropOffFor()` zoekt de les met de grootste terugval in lezers ten opzichte van
de vorige. Het is het enige getal op de rapportage dat iets zegt over de
**cursus** in plaats van over de mensen die hem volgen: een les waar de helft
van de groep stopt is meestal slecht geschreven, verkeerd geplaatst, of vraagt
iets wat de lezer nog niet kan. Afrondingspercentages laten dat nooit zien, en
daarom heeft het een eigen kolom met uitleg eronder.

### Drie niveaus van zichtbaarheid

| Ziet | Recht |
| --- | --- |
| Alleen het eigen dossier | `tt_view_knowledge` |
| Dat van iedereen | `tt_view_knowledge_statistics` |
| Toewijzen en achter mensen aan | `tt_manage_knowledge` |

Afgedwongen in de REST-`permission_callback` en niet door een kolom te
verbergen — een trainer die zijn eigen voortgang mag zien, mag de
afrondingscijfers van de academie niet kunnen ophalen via hetzelfde endpoint
dat de rapportage gebruikt. De CSV-export hangt op hetzelfde recht.

Een trainer zonder het statistiekrecht krijgt **zijn eigen dossier te zien**,
geen weigering. Eigen inzage is een niveau, geen gebrek eraan.

### REST

```
GET /talenttrack/v1/knowledge/statistics         alle cursussen + de tabel per persoon
GET /talenttrack/v1/courses/{slug}/statistics    één cursus, terugval en teamdekking
GET /talenttrack/v1/teams/{id}/learning          de staf van één team, per cursus
```

`/teams/{id}/learning` accepteert een optionele `course` om tot één cursus te
beperken; zonder die parameter worden alle meegeleverde cursussen
gerapporteerd, want "is mijn staf opgeleid" gaat zelden over één cursus zodra
een club er meerdere draait.

### Presentatie

Status is een chip met een **woord** naast een kleur — "3 te laat", "Allemaal
opgeleid", "nog 2" — zodat de tabel leesbaar blijft voor wie rood en groen niet
uit elkaar houdt. Alleen semantische kleuren, los van het moduleaccent. Een
schone rij toont een gedempte nul in plaats van een groene chip, zodat de rij
die aandacht vraagt ook de rij is die opvalt. Getalkolommen staan op
`tabular-nums`; tabellen scrollen binnen `.tt-table-wrap`, zodat de pagina zelf
nooit zijwaarts schuift.

De export vertaalt waarden naar mensentaal (#2012): "Nog niemand afgerond" in
plaats van een lege cel, lestitels in plaats van slugs.

## Paginabreedte

De lezer is een grid van drie kolommen (#2737). Lopende tekst staat in de
middelste baan op een leesbare maat (`--tt-lesson-measure`, 76ch); alles wat
eerder een figuur dan een zin is, beslaat alle drie:

- de vier rekenhulpen, via hun gedeelde klasse `.tt-lesson-tool`
- tabellen, via `.tt-lesson-table-scroll`
- `.tt-lesson-actionline`, `.tt-lesson-model`, de toets en de opdracht
- al het andere dat zich met `.tt-lesson-wide` aanmeldt

Een cursus wordt aan een bureau doorgewerkt, en de rekenhulpen zijn waarvoor de
lezer komt. Een weekplanner van zeven dagen in een pocketkolom persen terwijl er
700 pixels venster naast ongebruikt bleef, was het probleem dat dit oplost; ook
de tekst losmaken zou het hebben ingeruild voor regels van 140 tekens. Onder het
breekpunt klapt `min()` de middelste baan naar 100%, worden de buitenste banen
nul, en rendert mobiel precies zoals eerst.

De doelstellingen- en afrondingspanelen die de les omlijsten hebben hun maximum
helemaal laten vallen — een smal paneel boven en onder een breed lesblok leest
als een inspringing waar niemand om vroeg.

## Uitzetten

De module staat in `config/modules.php` en kan volledig uit. De cursussen zelf
zitten achter de deelfunctie `knowledge_courses`, zodat een academie die haar
trainersopleiding elders doet het lesmateriaal kan uitzetten terwijl de module
beschikbaar blijft voor ander materiaal.

## Zie ook

- [Epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
  — het volledige plan en de fasering
- `docs/contributing.md` — doelgroepmarkeringen en de vertaalregel
