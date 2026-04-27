<!-- audience: user -->

# Coach-dashboard (frontend)

Coaches en beheerders die inloggen op de frontend-shortcode zien een tegelgebaseerd dashboard met de sectie Coaching zichtbaar. (v3.0.0 — volledige rebuild van de tab-gebaseerde UI uit v2.x.)

## Hoe kom je er

De shortcode `[talenttrack_dashboard]`. Als een coach (iemand met `tt_edit_evaluations`) of een beheerder (iemand met `tt_edit_settings`) de pagina bezoekt, landt hij/zij op het tegelraster met de sectie **Coaching** zichtbaar. Coaches die ook aan een spelersrecord zijn gekoppeld, zien daarnaast de sectie **Mij**.

## De Coaching-tegels

### Teams
Elk team waarvoor de coach rechten heeft, weergegeven met zijn top-3 podium en de volledige roster in FIFA-achtige mini-kaarten. Tik op een kaart om in te duiken op het spelerdetail.

### Mijn spelers
Spelers van de teams die jij coacht. Beheerders zien deze tegel als **Spelers** met de volledige spelerslijst van de academie. Tik op een kaart om in te duiken op de spelerdetailweergave — FIFA-kaart, spelergegevens, aangepaste veldwaarden en de recent-historie radar. Heeft een eigen link "← Terug naar spelers" die terugkeert naar de lijst, los van de Terug-knop op de tegellanding. De lijstpagina heeft naast "Nieuwe speler" ook een **Importeer uit CSV**-knop voor beheerders en iedereen met `tt_edit_players`.

### Evaluaties
Formulier om evaluaties in te dienen met een wedstrijddetail-sectie die alleen verschijnt als een evaluatietype dat vereist. Alle beoordelingscategorieën uit jouw clubconfiguratie met min/max/stap uit je beoordelingsschaal. Verzonden via AJAX — succesmelding verschijnt inline, geen pagina-herlaad.

### Sessies
Registratie van trainingssessies. Titel, datum, team, locatie, notities. Aanwezigheidsmatrix toont elke speler in de teams van de coach met een statusdropdown (Aanwezig / Afwezig / Laat / Afgemeld) en een notitieveld. Verzonden via AJAX.

### Doelen
Dubbel formulier: bovenaan een nieuw doel toevoegen (speler-kiezer, titel, omschrijving, prioriteit, streefdatum), onderaan een tabel met huidige doelen met inline statusdropdowns en verwijderknoppen.

### Podium
Geaggregeerde podiumweergave — top-3 van elk team waarvoor de coach rechten heeft. Visueel gericht, geen formulieren.

## Naslag

Naslagmateriaal dat de coach raadpleegt tijdens het dagelijkse werk — los gehouden van de Performance-groep omdat het read-only kennis is, geen transactie.

### Methodologie
Principes, formaties, posities en standaardsituaties die de trainingsfilosofie van de academie sturen.

## Voor beheerders

Beheerders zien elk team, elke speler en elke evaluatie. De formulieren tonen bovendien een "alle spelers"-kiezer in plaats van beperkt te zijn tot de teams van de coach.

## Voor de Alleen-lezen Waarnemer

Waarnemers zien pagina's aan de beheerderzijde, maar geen van de Coaching-tegels op de frontend (zij hebben geen `tt_edit_*`-rechten). De tegels uit de Analyse-groep (Rate cards, Spelervergelijking) zijn hun frontend-ingang — dat is slice 5.

## Mobiel

Het tegelraster valt responsief terug. Alle formulieren en tabellen gebruiken `frontend-mobile.css` voor een redelijke schaling op telefoons. Langere lijsten (spelers, evaluaties) scrollen horizontaal waar nodig in plaats van zichzelf in smalle kolommen te persen.

## Terugnavigatie

Elke tegelbestemming toont bovenin een link "← Terug naar dashboard" die terugkeert naar de tegellandingspagina. De spelerdetailweergave binnen Spelers heeft in plaats daarvan een eigen link "← Terug naar spelers", zodat doorklikken van kaart naar kaart binnen het roster je niet helemaal naar buiten terugbrengt.
