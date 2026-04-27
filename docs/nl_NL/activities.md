<!-- audience: user -->

# Activiteiten

Een **activiteit** is alles wat je in de agenda zet — een **wedstrijd** (oefen / beker / competitie), een **training**, of **anders** (team-building dag, clubvergadering, alles wat niet in de eerste twee past). Elke activiteit heeft:

- Een type — `Wedstrijd`, `Training`, of `Anders` (uitbreidbaar via Configuratie → Lookups → activity_type).
- Een subtype als type `Wedstrijd` is — `Oefen`, `Beker`, of `Competitie` (uitbreidbaar via Configuratie → Lookups → game_subtype).
- Een vrije-tekstlabel als type `Anders` is — bv. "Team-buildingdag", "Clubvergadering".
- Een team waaraan zij behoort.
- Een datum en tijd.
- Optionele locatie + notities.
- Een lijst met aanwezige spelers met een aanwezigheidsstatus (Aanwezig, Afwezig, Laat, Afgemeld — configureerbaar via [Configuratie](?page=tt-docs&topic=configuration-branding)).

## Een activiteit aanmaken

Via de beheerpagina **Activiteiten** of de frontend voor coaches:

1. Kies het **Type** — Wedstrijd / Training / Anders (Training is de standaard).
2. Bij Wedstrijd: kies een **Subtype** (Oefen / Beker / Competitie). Optioneel.
3. Bij Anders: vul een vrij **Andere label** in (verplicht).
4. Kies het team.
5. Stel datum + tijd in.
6. Voeg eventueel locatie + notities toe.
7. Opslaan — de spelerslijst wordt automatisch gevuld met het team.
8. Markeer de aanwezigheid per speler.

## Waarom typering ertoe doet

Twee downstream-functies gebruiken `activity_type_key`:

- **Workflow voor coachevaluatie na wedstrijd** — een activiteit opslaan met `Type = Wedstrijd` spawnt automatisch een coachevaluatietaak per actieve speler op het team (elk subtype — oefen / beker / competitie spawnt). Trainingen + andere activiteiten spawnen deze taak nooit. Cadens is configureerbaar via Configuratie → Workflow-sjablonen.
- **HoD-kwartaalreview** — het live-data formulier splitst de 90-daagse rollup uit in "Wedstrijden / Trainingen / Anders" zodat het Hoofd Opleidingen het activiteitsvolume per type in één oogopslag ziet.

## Aanwezigheidsregistratie

Aanwezigheidsstatussen zijn lookups — voeg nieuwe toe via Configuratie → Aanwezigheidsstatus. Elke status is puur een label; er zit geen speciale bedrijfslogica achter, buiten het label en de telling.

## Archiveren

Net als andere entiteiten kunnen activiteiten worden gearchiveerd om oude seizoenen op te ruimen zonder de historie te verliezen.

## Gastaanwezigheid

Een activiteit kan ook spelers registreren die niet op de selectie staan — een JO13 die invalt bij JO14, een speler van een andere club op proef, een gast bij een oefenwedstrijd. Gasten staan naast de reguliere selectie op het aanwezigheidsformulier maar tellen niet mee in teamstatistieken.

### Twee varianten

- **Gekoppelde gast** — een echt `tt_players`-record (meestal van een ander team). Kies de speler uit de cross-team picker; de regel verwijst naar zijn/haar profiel en een eventuele evaluatie wordt normaal aan die speler gekoppeld.
- **Anonieme gast** — naam + optioneel leeftijd + optioneel positie, geen `tt_players`-record. Vrije-tekst notities van de coach zijn de enige gestructureerde observatie. Een anonieme gast kan later worden gepromoveerd naar een echte speler via "Promoveer naar speler" — de aanwezigheidsgeschiedenis blijft bewaard.

### Een gast toevoegen

1. Open de bewerkpagina van de activiteit.
2. Sla de activiteit eerst op als die nieuw is (gasten hebben een activiteits-id nodig).
3. Scroll naar het kopje **Gasten** onder de reguliere aanwezigheidstabel.
4. Klik **+ Gast toevoegen**, kies tabblad **Gekoppelde speler** of **Anonieme gast**, vul de velden in, klik **Toevoegen**.

De nieuwe regel verschijnt direct in de Gasten-tabel.

### Een anonieme gast promoveren

Bij anonieme gastregels zit een actie **Promoveer naar speler**. Daarmee opent het spelersformulier voorgevuld met de naam, geboortejaar (huidig jaar minus leeftijd) en positie. Na opslaan wordt de oorspronkelijke aanwezigheidsregel bijgewerkt om naar de nieuwe speler te verwijzen; de anonieme velden worden gewist maar `is_guest` blijft 1 staan (de historische gastvisite blijft bewaard).

### Stats-isolatie

Teamaggregaten (aanwezigheid %, podium, rolling stats, teamchemie wanneer die arriveert) sluiten gastregels automatisch uit — ze worden gefilterd op `is_guest = 0`. De kolom "Att. %" in de activiteitenlijst toont alleen selectie-aanwezigheid. Gasten verschijnen wél op:

- De activiteit-bewerkpagina van de hostcoach (Gasten-sectie).
- Het profiel van de gekoppelde gastspeler zelf (Aanwezigheid-tab — gemarkeerd met "(als gast)").
- De evaluatielijst van de hostclub als de hostcoach een gekoppelde gast evalueert.

Anonieme gasten hebben geen eigen profiel totdat ze gepromoveerd worden.

## Migratie vanaf "Sessies"

In v3.x heette deze entiteit "Sessie". v3.24.0 (#0035) hernoemt naar "Activiteit" met de typeringslaag hierboven beschreven. Bestaande sessies migreren automatisch naar `activity_type_key = 'training'` — een eenmalige beheerderswaarschuwing markeert de migratie zodat historische wedstrijden geherkwalificeerd kunnen worden via het bewerkformulier.
