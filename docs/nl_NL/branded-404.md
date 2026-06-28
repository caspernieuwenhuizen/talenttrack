<!-- audience: dev -->

# Merkgerichte 404

Hoe TalentTrack de niet-merkgerichte "pagina niet gevonden"-schermen vervangt
door één consistente, themavrije merkgerichte 404. Geleverd in #2035.

## Wat het dekt

Twee schermen haalden de gebruiker voorheen uit de TalentTrack-ervaring:

1. **De echte WordPress-404** — een URL zonder bijbehorende pagina/bericht
   toonde de `404.php` van het actieve thema, met de chrome die dat thema
   meelevert.
2. **De interne "Unknown section"-terugval** — een onbekende
   `?tt_view=<slug>` toonde een kale regel `Unknown section.`.

Beide komen nu uit op dezelfde merkgerichte TalentTrack-404: een speels,
voetbalgetint paneel "Buitenspel! Deze pagina staat niet meer in het veld"
met een duidelijke weg terug naar het dashboard.

## Hoe de WP-404-overname werkt

`Tt404Handler` kaapt hetzelfde enkele `template_include`-knooppunt dat
`CanvasShell` voor het dashboard gebruikt. Bij een echte front-end-404:

- zet de handler `status_header( 404 )` + `nocache_headers()` zodat crawlers
  en proxies een correcte not-found blijven zien,
- vervangt hij het thema-sjabloon door `templates/canvas-404.php` — een
  minimaal themavrij document (geen thema-`header.php` / `footer.php` /
  zijbalken),
- verwijdert hij elke niet-TalentTrack-stylesheet zodat geen thema-CSS
  doorlekt (dezelfde isolatie als het dashboard-canvas).

De merkgerichte inhoud zelf is `Tt404Page`, een zuivere presentatiecomponent
die `.tt-404-*`-markup uitsluitend met design tokens uitstuurt — geen
themacalls, geen inline-stijlen — zodat hij ongewijzigd meeverhuist naar de
toekomstige SaaS-frontend.

## Uitschakelen door de beheerder

De overname staat **standaard aan**. Een academie die TalentTrack naast
andere WordPress-inhoud draait, kan hem uitschakelen en de eigen thema-404
behouden:

- zet de clubgebonden config-vlag `tt_handle_wp_404` op `0` (opgeslagen in
  `tt_config`, nooit `wp_options`), of
- onderschep het `tt_handle_wp_404`-filter:

  ```php
  add_filter( 'tt_handle_wp_404', '__return_false' );
  ```

De interne `?tt_view=<onbekend>`-terugval toont altijd de merkgerichte
inhoud — die hoort bij de app-shell, niet bij het thema.

## Navigatie

De 404 is een eindscherm en volgt het twee-affordances-contract
(zie `back-navigation.md`):

- Binnen de dashboard-shell (de `?tt_view=<onbekend>`-terugval) is de
  broodkruimelketen naar Dashboard de terug-affordance; de inhoud zelf draagt
  geen extra knop.
- De zelfstandige WP-404-overname is een pre-app-scherm (vergelijkbaar met de
  uitzondering vóór inloggen) en biedt daarom één primaire knop **Terug naar
  dashboard** en niets meer.
