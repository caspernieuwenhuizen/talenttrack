<!-- audience: user -->

# Sessies

Een **sessie** is een trainingsmoment. Elke sessie heeft:

- Een team waartoe zij behoort
- Een datum en tijd
- Een optionele locatie
- Een lijst met aanwezige spelers met een aanwezigheidsstatus (Aanwezig, Afwezig, Laat, Afgemeld — configureerbaar via [Configuratie](?page=tt-docs&topic=configuration-branding))

## Een sessie aanmaken

Via de beheerpagina **Sessies** of de frontend voor coaches:

1. Kies het team.
2. Stel datum en tijd in.
3. Voeg eventueel locatie en notities toe.
4. Opslaan — de spelerslijst wordt automatisch gevuld met het team.
5. Markeer de aanwezigheid per speler.

## Aanwezigheidsregistratie

Aanwezigheidsstatussen zijn lookups — voeg nieuwe toe via Configuratie → Aanwezigheidsstatus. Elke status is puur een label; er zit geen speciale bedrijfslogica achter, buiten het label en de telling.

## Rapportage over sessies

Sessieaantallen vloeien momenteel niet in de hoofdanalytics (Rate Card, Vergelijking). Ze verschijnen wel op het spelersprofiel onder "Bijgewoonde sessies" en voeden de aanwezigheidsweergave op het frontend-dashboard.

## Archiveren

Net als andere entiteiten kunnen sessies worden gearchiveerd om oude seizoenen op te ruimen zonder de historie te verliezen.

## Gastaanwezigheid (v3.22.0)

Een sessie kan ook spelers registreren die niet op de selectie staan — een JO13 die invalt bij JO14, een speler van een andere club op proef, een gast bij een oefenwedstrijd. Gasten staan naast de reguliere selectie op het aanwezigheidsformulier maar tellen niet mee in teamstatistieken.

### Twee varianten

- **Gekoppelde gast** — een echt `tt_players`-record (meestal van een ander team). Kies de speler uit de cross-team picker; de regel verwijst naar zijn/haar profiel en een eventuele evaluatie wordt normaal aan die speler gekoppeld.
- **Anonieme gast** — naam + optioneel leeftijd + optioneel positie, geen `tt_players`-record. Vrije-tekst notities van de coach zijn de enige gestructureerde observatie. Een anonieme gast kan later worden gepromoveerd naar een echte speler via "Promoveer naar speler" — de aanwezigheidsgeschiedenis blijft bewaard.

### Een gast toevoegen

1. Open de sessie-bewerkpagina.
2. Sla de sessie eerst op als die nieuw is (gasten hebben een sessie-id nodig).
3. Scroll naar het kopje **Gasten** onder de reguliere aanwezigheidstabel.
4. Klik **+ Gast toevoegen**, kies tabblad **Gekoppelde speler** of **Anonieme gast**, vul de velden in, klik **Toevoegen**.

De nieuwe regel verschijnt direct in de Gasten-tabel.

### Een anonieme gast promoveren

Bij anonieme gastregels zit een actie **Promoveer naar speler**. Daarmee opent het spelersformulier voorgevuld met de naam, geboortejaar (huidig jaar minus leeftijd) en positie. Na opslaan wordt de oorspronkelijke aanwezigheidsregel bijgewerkt om naar de nieuwe speler te verwijzen; de anonieme velden worden gewist maar `is_guest` blijft 1 staan (de historische gastvisite blijft bewaard).

### Stats-isolatie

Teamaggregaten (aanwezigheid %, podium, rolling stats, teamchemie wanneer die arriveert) sluiten gastregels automatisch uit — ze worden gefilterd op `is_guest = 0`. De kolom "Att. %" in de sessielijst toont alleen selectie-aanwezigheid. Gasten verschijnen wél op:

- De sessie-bewerkpagina van de hostcoach (Gasten-sectie).
- Het profiel van de gekoppelde gastspeler zelf (Aanwezigheid-tab — gemarkeerd met "(als gast)").
- De evaluatielijst van de hostclub als de hostcoach een gekoppelde gast evalueert.

Anonieme gasten hebben geen eigen profiel totdat ze gepromoveerd worden.
