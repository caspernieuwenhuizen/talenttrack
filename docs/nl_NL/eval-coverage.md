<!-- audience: admin -->

# Evaluatiedekking

Het rapport **Evaluatiedekking** is een scherm voor het Hoofd Opleiding
dat één vraag beantwoordt: *welke spelers zijn deze periode niet
geëvalueerd, en welke coach is verantwoordelijk voor het gat?* Het staat
onder **Analyse → Evaluatiedekking** (`?tt_view=eval-coverage`) en
vereist het recht om analyses te bekijken (`tt_view_analytics`).

## Evaluatieperiodes

Een **periode** is een benoemd tijdvak in het seizoen — bijvoorbeeld
"Najaarsbeoordeling" van 1 september tot 15 oktober — waarin elke speler
minstens één evaluatie hoort te krijgen. Je definieert de periodes in de
instellingen-editor boven aan het rapport:

1. Geef de periode een **naam**.
2. Kies de **start**- en **einddatum**.
3. Voeg zoveel rijen toe als nodig (er staat altijd één lege rij klaar
   voor de volgende periode). Maak de velden van een rij leeg om die
   periode te verwijderen.
4. Klik op **Periodes opslaan**.

Periodes worden per academie in de configuratie opgeslagen — er is geen
apart "periode"-record om te beheren, en het rapport stuurt nooit
herinneringen. Een speler telt als **gedekt** voor een periode zodra er
minstens één evaluatie is met een datum binnen die periode.

## De dekkingsmatrix

Onder de editor toont de matrix elke actieve speler, gegroepeerd per
team, met één kolom per periode. Elke cel toont:

- **✓ Geëvalueerd** — er viel minstens één evaluatie in die periode.
  Beweeg over het vinkje om te zien welke coach de meest recente
  vastlegde.
- **• Niet geëvalueerd** — een gat. De cel is gemarkeerd met een punt en
  het label "Niet geëvalueerd" (de status blijkt uit pictogram en tekst,
  nooit uit kleur alleen).

Een KPI-strook boven aan toont het totaal aantal spelers, periodes, het
totale dekkingspercentage en het aantal gaten.

## Gaten per coach

De strook **Gaten per coach** telt hoeveel niet-gedekte cellen onder de
hoofdcoach van elk team vallen, slechtste eerst. Spelers wier team geen
hoofdcoach heeft, vallen onder **Niet toegewezen**. Dit is in één
oogopslag het antwoord op "wie is verantwoordelijk voor het gat".

## Evaluaties openen per coach

Elke coach die evaluaties heeft vastgelegd, verschijnt als knop onder
**Evaluaties openen per coach**. Een klik opent de evaluatielijst
gefilterd op die coach (`?filter[coach_id]=…`), zodat je vanuit een gat
direct ziet wat die coach wel — en niet — heeft vastgelegd.

## Naleving aanwezigheidsregistratie

De laatste strook toont per team en per periode het aandeel **afgeronde**
activiteiten waarvoor **enige** aanwezigheid is geregistreerd. Dit
scheidt twee heel verschillende situaties:

- Een team met een laag percentage heeft afgeronde activiteiten, maar de
  coach registreert niet wie aanwezig was.
- Een team met **Geen activiteit** had simpelweg geen afgeronde
  activiteiten in die periode — er valt niets te registreren.

## Waar dit past

Dit rapport is alleen-lezen analyse op basis van bestaande evaluatie- en
aanwezigheidsgegevens. Het wijzigt geen enkel speler-, evaluatie- of
activiteitsrecord — het maakt alleen zichtbaar waar evaluatiedekking
ontbreekt, zodat een Hoofd Opleiding kan handelen. Dezelfde gegevens zijn
beschikbaar via de REST-API op `/wp-json/talenttrack/v1/eval-coverage`.

## Uitschakelen

Evaluatiedekking is een functie per tegel, die **standaard uit** staat. Een
academiebeheerder zet hem aan (of uit) onder **Modules → Analyse →
Evaluatiedekking**. Zolang hij uit staat is de tegel verborgen en geeft de
URL `?tt_view=eval-coverage` de gebruikelijke melding "niet beschikbaar". Het
centrale Analyse-scherm, de standaardrapporten en de analyse-engine blijven
werken.
