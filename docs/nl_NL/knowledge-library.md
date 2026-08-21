---
title: Kennisbank
group: development
summary: Cursussen voor trainersontwikkeling, geleverd als markdown en gelezen in de app.
audience: [admin, dev]
module: TT\Modules\Knowledge\KnowledgeModule
feature: knowledge_courses
tier: standard
order: 75
---

# Kennisbank

De kennisbank bevat cursussen voor trainersontwikkeling. Een cursus wordt met
de plug-in meegeleverd als markdown, wordt in de app gelezen en houdt — zodra
de latere fases van
[epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
zijn opgeleverd — voortgang bij, vergrendelt lessen en telt afronding per team
op.

Deze pagina beschrijft het corpus: wat een cursus op schijf is, en waar de
CI-gate op controleert. De lezer, het schema en de statistieken worden
gedocumenteerd zodra ze worden opgeleverd.

## Wat er nu is

Alleen de inhoudelijke basis: het corpusformaat, de parsers en het register. Er
is nog geen leesweergave, geen voortgangsregistratie en geen vergrendeling. De
eerste cursus — *Periodiseren in voetbaltaal*, een Nederlandstalige
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
| `methodology_principles` | nee | Methodiekprincipes die de cursus behandelt |
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
- een vertaalde les zonder canonieke tegenhanger

Lokaal draaien: `php tools/check-courses.php`. De gate heeft geen WordPress
nodig en gebruikt de echte parsers, zodat hij niet uit de pas kan lopen met wat
hij bewaakt.

## Uitzetten

De module staat in `config/modules.php` en kan volledig uit. De cursussen zelf
zitten achter de deelfunctie `knowledge_courses`, zodat een academie die haar
trainersopleiding elders doet het lesmateriaal kan uitzetten terwijl de module
beschikbaar blijft voor ander materiaal.

## Zie ook

- [Epic #2641](https://github.com/caspernieuwenhuizen/talenttrack/issues/2641)
  — het volledige plan en de fasering
- `docs/contributing.md` — doelgroepmarkeringen en de vertaalregel
