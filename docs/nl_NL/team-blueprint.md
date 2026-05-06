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

## Selectie-plan-variant

Bij het aanmaken van een blauwdruk vraagt de wizard naar het type:

- **Wedstrijdopstelling** — één basiself voor een aankomende wedstrijd. Eén speler per positie. (Standaard; alles hierboven beschrijft deze variant.)
- **Selectieplan** — planning richting volgend seizoen of beslissingen over proefspelers. Elke positie heeft drie tiers (primair / secundair / tertiair) en de selectiebalk krijgt een *Proefspelers*-sectie.

De variant ligt vast bij aanmaken.

### Diepteschema met tiers

Op een selectieplan-blauwdruk verschijnt een diepteschema-tabel onder het veld:

| Positie | Primair | Secundair | Tertiair |
| --- | --- | --- | --- |
| GK | Lucas | Jonas | — |
| LB | Eve | Mira | Jamal |
| LCB | Sam | — | — |

Elke cel is een drop-doel. Sleep een chip uit de selectiebalk naar een cel om die tier te vullen. Sleep een chip uit het diepteschema terug naar de selectiebalk om te verwijderen. De veldposities boven blijven ook drops accepteren — die richten op de **primaire** tier.

Dezelfde speler kan niet op twee posities of tiers tegelijk staan in één blauwdruk. Sleep je Lucas van `GK / Primair` naar `LB / Secundair`, dan komt zijn GK-positie automatisch leeg.

### Proefspeler-overlay

De selectiebalk krijgt een *Proefspelers*-divider met proefspelers die aan dit team zijn toegewezen — `tt_players`-rijen op de selectie van dit team met `status = 'trial'`. Proefspeler-chips hebben een gele rand en een kleine `PROEF`-badge. Drag-drop werkt identiek aan reguliere chips, dus je kunt een proefspeler op tier 2 / 3 van een positie zetten om de "moeten we deze speler tekenen?"-discussie zichtbaar te maken tegen het diepteschema.

### Dekkingsheatmap

Een *Toon dekkingsheatmap*-knop op selectieplan-blauwdrukken zet het veld in dekkings-modus:

- **Rood** — 0 tiers gevuld (niet gedekt)
- **Oranje** — 1 (alleen primair, geen backup)
- **Geel** — 2 (primair + secundair, geen derde)
- **Groen** — 3 (volledige diepte)

Elke positie toont `N/3` zodat je in één oogopslag ziet waar de gaten zitten. `← Terug naar opstellingsweergave` brengt je terug naar de editor.

### Chemie op een selectieplan-blauwdruk

Chemie scoort alleen de **basiself** — de primaire tier. Tier 2 en 3 zijn dieptesignalen, geen opstellingssignalen. De kop weerspiegelt de primaire opstelling; lijnen lopen tussen de primaire spelers.

## Beperkingen Fase 2

- **Geen reacties** — overleg met de staf gebeurt voorlopig buiten de blauwdruk om; Fase 3 voegt een reactiethread per blauwdruk toe via de Threads-module.
- **Drag-drop op mobiel is onhandig**. HTML5 drag-and-drop op touch-toestellen werkt maar is niet ideaal. Een long-press-pickup-fallback staat op de poetslijst.
- **Geen deellink**. Een publieke URL voor ouders / externe coaches komt in Fase 4.

## REST

De list / show / create / update endpoints staan in `docs/rest-api.md` onder `talenttrack/v1/teams/{id}/blueprints` en `talenttrack/v1/blueprints/{id}`. Het per-drop toewijzingsendpoint is `PUT /blueprints/{id}/assignment` met body `{ slot_label, player_id? }` — de editor roept het bij elke drop aan en gebruikt de herrekende `blueprint_chemistry` uit het antwoord om de pagina te verversen.
