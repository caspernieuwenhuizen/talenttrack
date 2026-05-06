<!-- audience: user -->

# Teamblauwdruk

Een **blauwdruk** is een opgeslagen opstelling. Bouw er één van tevoren voor een aankomende wedstrijd, deel hem met de staf, vergrendel hem zodra je besloten bent. Elke blauwdruk zit op een formatiesjabloon en laat je spelers op posities slepen — met dezelfde chemielijnen en chemiescore als op het *Teamchemie*-bord, live berekend terwijl je bouwt.

## Waar te vinden

Coaches en hoofd-academie zien een **Teamblauwdruk**-tegel in de *Performance*-groep op het dashboard, naast *Teamchemie*. Kies een team en je komt op de lijst van opgeslagen blauwdrukken voor dat team.

## Een blauwdruk aanmaken

Klik op **+ Nieuwe blauwdruk**. Een wizard vraagt:

1. **Team** — meestal al ingevuld als je vanuit de team-blauwdruklijst komt.
2. **Formatie** — kies uit de zeven meegeleverde sjablonen (4-3-3 in vier speelstijl-varianten, plus 4-4-2 / 3-5-2 / 4-2-3-1 neutraal).
3. **Naam blauwdruk** — alles wat je later helpt herkennen (bv. "Bekerfinale basiself").

Klik op **Aanmaken** en je komt direct in de editor met lege posities, klaar om in te vullen.

## De editor

Drie regio's:

- **Selectiebalk** — elke actieve speler in het team, als versleepbare chip. Spelers die al in de opstelling staan worden uitgegrijsd.
- **Veld** — de posities van de formatie. Lege posities tonen een streepje `—`.
- **Lijnchemie-kop** — `0 / 100` totdat je spelers begint te plaatsen, daarna ververst hij na elke drop.

### Drag-drop-regels

- Sleep een chip op een positie om die speler daar te plaatsen.
- Sleep een chip van de ene positie naar de andere om te wisselen.
- Sleep een chip terug naar de selectiebalk om uit de opstelling te halen.
- Elke drop slaat direct op. Er is geen "Opslaan"-knop — de editor is de bron van waarheid.

### Chemiescore

Dezelfde groen / oranje / rood lijnen als op het *Teamchemie*-bord verschijnen tussen aangrenzende posities. Beweeg over een lijn voor de uitsplitsing:

- **Groen** (2,0–3,0) — sterke fit
- **Oranje** (1,0–2,0) — werkbaar
- **Rood** (0–1,0) — zwak

De duo-score combineert door de coach gemarkeerde duo's (+2), zelfde linie (+1) en voorkeursbeen-fit (+1, of −1 bij een zijwisselfout). De 0–100 kop is het gemiddelde van alle gescoorde aangrenzende duo's, geschaald naar 100.

Lijnen renderen ook op selecties zonder evaluaties, want de invoeren zijn door coach of selectie ingesteld — coach-duo's, posities in de formatie, voorkeursbenen. De score is dus vanaf dag 1 bruikbaar.

## Statusflow

Elke blauwdruk gaat door drie statussen:

- **Concept** — je private werkversie. Andere coaches zien hem niet.
- **Gedeeld** — zichtbaar voor iedereen met leesrechten op teamchemie. Gebruik dit als je feedback van de staf wilt.
- **Vergrendeld** — alleen-lezen. Drag-drop is uit; de toewijzingsendpoints weigeren elke schrijfactie. Gebruik dit als de blauwdruk definitief is en je niet wilt dat iemand (inclusief jezelf) per ongeluk vlak voor de wedstrijd nog een speler verschuift.

De statusbalk boven het veld toont waar je zit. Knoppen verschijnen voor de toegestane vervolgacties:

- *Delen met staf* (concept → gedeeld)
- *Terug naar concept* (gedeeld → concept) of *Vergrendel* (gedeeld → vergrendeld)
- *Heropenen* (vergrendeld → gedeeld)

Heropenen vereist hetzelfde rechtenniveau als het aanmaken van een blauwdruk, dus een hoofdcoach kan een vergrendelde blauwdruk heropenen bij een late wijziging.

## Rechten

- **Bekijken** — coaches zien blauwdrukken voor teams waarvan ze hoofdcoach zijn; hoofd-academie / academie-admin zien alle teams. Dezelfde scope als het Teamchemie-bord.
- **Aanmaken / bewerken / vergrendelen / verwijderen** — gated op `tt_manage_team_chemistry` (standaard hoofdcoach; hoofd-academie / admin globaal).

## Beperkingen Fase 1

- **Alleen wedstrijdopstelling**. Selectie-plan-variant (meerlaagse positie-fits, primair / secundair / tertiair) komt in Fase 2.
- **Nog geen proefspelers op het veld** — proefspeler-overlay komt met de selectie-plan-variant in Fase 2.
- **Geen reacties** — overleg met de staf gebeurt voorlopig buiten de blauwdruk om; Fase 3 voegt een reactiethread per blauwdruk toe via de Threads-module.
- **Drag-drop op mobiel is onhandig**. HTML5 drag-and-drop op touch-toestellen werkt maar is niet ideaal. Een long-press-pickup-fallback staat op de poetslijst.
- **Geen deellink**. Een publieke URL voor ouders / externe coaches komt in Fase 4.

## REST

De list / show / create / update endpoints staan in `docs/rest-api.md` onder `talenttrack/v1/teams/{id}/blueprints` en `talenttrack/v1/blueprints/{id}`. Het per-drop toewijzingsendpoint is `PUT /blueprints/{id}/assignment` met body `{ slot_label, player_id? }` — de editor roept het bij elke drop aan en gebruikt de herrekende `blueprint_chemistry` uit het antwoord om de pagina te verversen.
