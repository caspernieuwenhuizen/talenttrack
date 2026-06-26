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

Herbouwd in v4.6.0 (#972) — schone port van het in-tree prototype `.local-mockups/blueprint-editor/index.html`. Vier regio's:

- **Actiebalk** boven de lay-out — één compacte balk die elke actie op topniveau samenbrengt (#1527). De **Formatie**-dropdown blijft links een gelabelde besturing (elke actieve sjabloon uit `tt_formation_templates`). Daarna een rij icoon-knoppen voor de veelgebruikte acties — **Opslaan** (primair), **Wis alle posities**, de **dekkingsheatmap**-schakelaar (alleen selectieplannen) en de **chemie tonen/verbergen**-schakelaar — elk met een tooltip en schermlezer-label. Een opslag-hint toont "Opslaan…" tussen schrijfacties. Alles wat zelden of destructief is zit achter het **⋯ Meer**-menu aan het einde van de balk (Opslaan als…, Deelbare link openen / vernieuwen, de statusovergangen, Blauwdruk verwijderen).
- **Selectiebalk** — titel `<Team> — selectie (<N>)`, daarna elke actieve speler als versleepbare rij: avatar met initialen + naam + meta-regel (positie, leeftijd, soort voor niet-team-entries). Een `×N`-badge verschijnt naast de naam zodra die speler op één of meer posities in de huidige formatie staat. Onder de lijst opent de knop **+ Voeg gast / aangepaste naam toe** een inline 3-tab-formulier (Ander team / Gast / Aangepast — hieronder beschreven).
- **Veld** — de posities van de formatie. Elke positie toont een genummerde cirkel (bv. `9` / `ST`) en een drielagige stack er direct onder: **primair / secundair / tertiair** diepte. De tier wordt twee keer gecodeerd — door het cijfer links van elke rij EN door de randkleur (turquoise / amber / grijs) — zodat het diepteschema leesbaar blijft zonder kleur.
- **Lijnchemie-kop** — `— / 100` totdat je spelers begint te plaatsen, daarna ververst hij na elke wijziging.

### Het ⋯ Meer-menu

Het overflow-menu is een native disclosure: klik (of focus + Enter / Spatie) op de **⋯**-knop om het te openen, klik ernaast of druk Escape om het te sluiten. Het bevat, met volledige tekstlabels:

- **Opslaan als…** — kloon de huidige blauwdruk naar een nieuw concept.
- **Deelbare link openen** / **Deelbare link vernieuwen** — de publieke alleen-lezen link-besturingen (zie *Publieke deellink* hieronder).
- De toegestane **statusovergangen** voor de huidige status (*Delen met staf*, *Terug naar concept*, *Vergrendel*, *Heropenen*).
- **Blauwdruk verwijderen** — verborgen zodra de blauwdruk vergrendeld is (eerst heropenen).

Elk van deze behoudt zijn eigen bevestiging waar die er was (Vernieuwen, Verwijderen, Wis alles). Aan wat de acties doen is niets veranderd — #1527 heeft ze alleen in één balk samengebracht.

### Speler kiezen

Twee manieren om een positie te vullen:

- **Klik op een tier-rij** → er verschijnt een dropdown-picker naast de rij met een zoekveld en de volledige selectie. Filter op naam / positie; klik een rij om te plaatsen. Als de positie al iemand heeft, verschijnt onderin de picker een *Maak deze positie leeg*-regel.
- **Sleep een selectierij** op een willekeurige tier-positie. De positie accepteert de drop; de vorige bezetter van die tier wordt vervangen (de verdrongen speler blijft op elke andere positie waarop hij staat — een drop trekt hem nergens anders weg). Drag-drop en de picker gebruiken hetzelfde save-endpoint.

Dezelfde speler kan op een willekeurig aantal posities én tiers staan — er is geen automatische dedupe. De `×N`-badge in de selectie laat zien hoeveel plekken ze innemen op de **huidige** formatie (stale toewijzingen uit een andere formatie tellen niet mee voor de badge, maar overleven wel in de database). Tier-1-plekken voeden de chemiescore; tier-2 en tier-3 zijn pure diepteschema-signalen en tellen niet mee voor chemie.

De **`×`-wisknop** op elke gevulde rij maakt alleen die tier in die positie leeg.

Na elke succesvolle opslag herlaadt de editor zodat de chemie-kop, namen op het veld en eventuele server-side state gezaghebbend terugkomen.

### + Voeg gast / aangepaste naam toe

Drie tabs op het inline-toevoegformulier:

- **Ander team** — kies een ander team in de club, dan een speler uit dat team. Voegt ze toe aan de selectie als ander-team-pick (het thuisteam verschijnt in de meta-regel). Ander-team-spelers worden precies opgeslagen als thuisteam-spelers (`ref_kind=player`) — wat ze "ander-team" maakt is alleen het verschil in thuisteam.
- **Gast** — typ een naam (bv. *"proefspeler op bezoek"*) en optioneel een positie. Voegt een gastrij toe aan de selectie.
- **Aangepast** — typ een vrij label (bv. *"Scoutdoel #4"*). Voegt een aangepaste placeholder toe aan de selectie.

Gast- en aangepaste toevoegingen zijn **alleen sessie-gebonden tot ze geplaatst worden**. Ze leven in de in-memory selectie van de editor en worden pas opgeslagen wanneer ze daadwerkelijk in een tier-positie worden gezet — sluit je de editor zonder ze te plaatsen, dan zijn ze effectief weg. Eenmaal geplaatst draagt de toewijzing de ref (`ref_kind=guest` of `ref_kind=custom`) en blijft de entry een reload overleven.

### Formatie wisselen

Een andere formatie kiezen uit de **Formatie**-dropdown werkt de sjabloon van de blauwdruk bij (via `PUT /blueprints/{id}` met de nieuwe `formation_template_id`) en herlaadt de editor. Toewijzingen overleven op **positie-label** — elke positie waarvan het label in zowel de oude als de nieuwe formatie bestaat behoudt zijn tier-1/2/3-keuzes. Nieuwe posities komen leeg binnen. Verdwenen posities blijven stil in de database, dus een heen-en-weer-wissel brengt ze terug. (De `×N`-badge telt alleen wat zichtbaar is op de huidige formatie.)

### Wis alle posities

De knop **Wis alle posities** in de actiebalk verwijdert alle toewijzingen van de huidige blauwdruk na een bevestiging. Er is geen undo — gebruik 'm bij een verse start vanaf een bestaande blauwdruk via *Opslaan als*.

### Opslaan

Elke keuze / leegmaak / formatie-wisseling slaat direct op. Er is geen batch-opslag. De **Opslaan**-knop in de actiebalk betekent "klaar met bewerken, breng me terug naar de lijst" — hij navigeert naar de blauwdrukkenlijst van het team met een bevestigings-toast. **Opslaan als…**, in het **⋯ Meer**-menu, vraagt om een nieuwe naam en kloont de huidige blauwdruk (inclusief elke toewijzingsrij) naar een nieuw concept en opent dat concept.

### Chemie verbergen

De **chemie tonen/verbergen**-schakelaar in de actiebalk verbergt de chemie-koptekst EN elke chemie-lijn op het veld. Het oog-icoon toont de huidige staat en de tooltip / het label wisselt tussen *Chemie verbergen* en *Chemie tonen*. De staat blijft staan in `sessionStorage` per blauwdruk-id, dus een refresh onthoudt de voorkeur. Handig wanneer je het diepteschema rustig wilt lezen zonder chemie-ruis.

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

Een status-pil onder de actiebalk toont waar je zit. De toegestane vervolgacties zitten in het **⋯ Meer**-menu van de actiebalk:

- *Delen met staf* (concept → gedeeld)
- *Terug naar concept* (gedeeld → concept) of *Vergrendel* (gedeeld → vergrendeld)
- *Heropenen* (vergrendeld → gedeeld)

Heropenen vereist hetzelfde rechtenniveau als het aanmaken van een blauwdruk, dus een hoofdcoach kan een vergrendelde blauwdruk heropenen bij een late wijziging.

## Rechten

- **Bekijken** — hoofdcoaches zien blauwdrukken voor teams waarvan ze hoofdcoach zijn; hoofd-academie / academie-admin zien alle teams. Dezelfde scope als het Teamchemie-bord. Toegang volgt de `team_chemistry`-autorisatiematrix (#1922): assistent-coaches en alleen-lezen-waarnemers hebben **geen** toegang.
- **Aanmaken / bewerken / vergrendelen / verwijderen** — afgeschermd op `team_chemistry` **beheer**-autoriteit in de matrix (hoofdcoach op teamscope; hoofd-academie / admin globaal), opgelost via `TeamChemistryAccess` zodat de frontend en REST overeenkomen.

## Selectie-plan-variant

Bij het aanmaken van een blauwdruk vraagt de wizard naar het type:

- **Wedstrijdopstelling** — één basiself voor een aankomende wedstrijd. Eén speler per positie. (Standaard; alles hierboven beschrijft deze variant.)
- **Selectieplan** — planning richting volgend seizoen of beslissingen over proefspelers. Elke positie heeft drie tiers (primair / secundair / tertiair) en de selectiebalk krijgt een *Proefspelers*-sectie.

De variant ligt vast bij aanmaken.

### Diepteschema met tiers

Wedstrijdopstelling en selectieplan **delen nu hetzelfde editor-oppervlak** (#953) — elke positie op het veld draagt de primaire / secundaire / tertiaire stack inline. Selectieplan-blauwdrukken leunen zwaarder op het diepteschema, maar een wedstrijdcoach mag prima tier 2 / 3 invullen (handig voor "als A geblesseerd raakt, komt B").

Dezelfde speler KAN op twee posities of tiers tegelijk staan — handig voor een veelzijdige speler die primair op één positie staat en secundaire cover op een andere. De `×N`-badge in de selectie houdt het beeld eerlijk.

### Proefspeler-overlay

De selectiebalk krijgt een *Proefspelers*-divider met proefspelers die aan dit team zijn toegewezen — `tt_players`-rijen op de selectie van dit team met `status = 'trial'`. Proefspeler-chips hebben een gele rand en een kleine `PROEF`-badge. Drag-drop werkt identiek aan reguliere chips, dus je kunt een proefspeler op tier 2 / 3 van een positie zetten om de "moeten we deze speler tekenen?"-discussie zichtbaar te maken tegen het diepteschema.

### Dekkingsheatmap

De **dekkingsheatmap**-schakelaar in de actiebalk (alleen selectieplan-blauwdrukken) zet het veld in dekkings-modus:

- **Rood** — 0 tiers gevuld (niet gedekt)
- **Oranje** — 1 (alleen primair, geen backup)
- **Geel** — 2 (primair + secundair, geen derde)
- **Groen** — 3 (volledige diepte)

Elke positie toont `N/3` zodat je in één oogopslag ziet waar de gaten zitten. `← Terug naar opstellingsweergave` brengt je terug naar de editor.

### Chemie op een selectieplan-blauwdruk

Chemie scoort alleen de **basiself** — de primaire tier. Tier 2 en 3 zijn dieptesignalen, geen opstellingssignalen. De kop weerspiegelt de primaire opstelling; lijnen lopen tussen de primaire spelers. Cellen met gast- of aangepaste invulling worden door de engine overgeslagen omdat er geen `tt_players.id` is om coach-duo's of voorkeursbenen tegen op te zoeken.

Als een positie tier-2 of tier-3 entries heeft maar **geen** tier-1 bezetting, verschijnt boven het veld een waarschuwingsstrip met de betreffende posities — chemie negeert die cellen stilletjes, dus de strip maakt het scoreverlies zichtbaar. Vul tier-1 om ze weer mee te laten tellen.

## Reacties (#0068 Fase 3)

Elke blauwdruk heeft een eigen discussiethread, bereikbaar via de **Reacties**-tab op de editor. Staf-only — ouders op de deelbare link zien nooit reacties:

- **Lezen** = `tt_view_team_chemistry` — elke coach die de editor mag openen.
- **Plaatsen** = `tt_manage_team_chemistry` — elke coach die de blauwdruk mag vergrendelen.

Systeemberichten worden automatisch gepost bij elke statusovergang (`Status gewijzigd naar: shared` / `locked` / `draft`). Speler-toewijzingen blijven stil — die zie je terug bij de chemie-refresh.

## Publieke deellink (#0068 Fase 4)

Het **Deelbare link openen**-item in het **⋯ Meer**-menu van de actiebalk genereert een URL van de vorm:

```
?tt_view=team-blueprint-share&id=<uuid>&token=<hmac>
```

Iedereen met de URL ziet een alleen-lezen weergave: status-pil + chemie-kop + veld + opstellingstabel. Geen reacties, geen bewerkingen, geen login nodig. Ouders en externe coaches kun je de link rechtstreeks sturen.

**Deelbare link vernieuwen** zet een verse seed. Elke vorige URL faalt direct. Gebruik dit als een link te ruim is gedeeld, of na een selectiewissel waarvan je niet wilt dat eerdere kijkers verder volgen.

Het token is een HMAC-SHA256 over `(blueprint_id, uuid, share_token_seed)` met sleutel `wp_salt('auth')` van de installatie. De seed is per blauwdruk en wordt lazy geïnitialiseerd op de uuid van de blauwdruk (cryptografisch willekeurig); vernieuwen vervangt de seed door een verse `wp_generate_password(16)`-waarde.

## Drag-drop op mobiel (#0068 Fase 4)

iPads werken prima met HTML5 drag-and-drop; iPhones niet. v3.109.8 levert een touch-fallback:

- **Long-press 300 ms** op een chip in de spelerslijst om hem op te pakken.
- Sleep de chip op een slot of terug naar de spelerslijst.
- Een korte tap-en-scroll blijft scrollen — de long-press-drempel maakt het verschil.
- Oppakken + neerzetten triggert een trilling van 50 ms op apparaten die `navigator.vibrate()` ondersteunen.

Muis + trackpad blijven de bestaande HTML5-flow gebruiken.

## REST

De list / show / create / update endpoints staan in `docs/rest-api.md` onder `talenttrack/v1/teams/{id}/blueprints` en `talenttrack/v1/blueprints/{id}`. Het per-drop toewijzingsendpoint is `PUT /blueprints/{id}/assignment` met body `{ slot_label, player_id? }` — de editor roept het bij elke drop aan en gebruikt de herrekende `blueprint_chemistry` uit het antwoord om de pagina te verversen.
