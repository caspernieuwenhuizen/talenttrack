<!-- audience: dev -->

# Terug-navigatie

URL-gedragen "← Terug naar waar je vandaan kwam" navigatie, geleverd
in v3.110.0.

## Waarom

Broodkruimels tonen waar een record in de canonieke hiërarchie staat
(`Dashboard / Spelers / Jan de Vries`). Ze tonen **niet** waar de
gebruiker vandaan kwam. Pilotfeedback: wanneer een coach navigeert
van Teams → Teamdetail → Speler vanuit het selectieoverzicht, vermeldt
de broodkruim "Dashboard / Spelers / Jan de Vries" — er is geen
duidelijke knop op de pagina die terug brengt naar het team.

De terug-knop van de browser werkt, maar is klein op mobiel en
onbetrouwbaar na formulierverzendingen. Op de HTTP-referer gebaseerde
terugkoppelingen (de aanpak van v3.108.2) verliezen het doel bij een
herlaadbeurt en bij gedeelde deep-links. v3.110.0 vervangt beide door
een URL-gedragen mechanisme: het terug-doel staat in een `tt_back`
query-parameter die herladen, ontbrekende referers en deellinks
overleeft.

## Hoe het werkt

Elke kruisentiteits-link in een frontend-view voegt
`tt_back=<urlencoded huidige pagina-URL>` toe. De ontvangende view
leest `tt_back` uit `$_GET`, valideert het en rendert een pill:

```
← Terug naar Team Ajax O17
```

De pill wordt automatisch gerenderd door
`FrontendBreadcrumbs::render()` boven de broodkruimketen. Views die de
broodkruimcomponent al gebruiken krijgen de pill gratis.

## 5 stappen ver terug

De huidige pagina-URL bevat zelf al een geërfde `tt_back`, dus elke
voorwaartse navigatie **nest** de vorige keten via URL-encoding. Een
gebruiker die loopt: Teams → Team A → Speler Bob → Activiteit 12
komt uit op een URL als:

```
/?tt_view=activities&id=12&tt_back=<urlencoded /?tt_view=players&id=42&tt_back=<urlencoded /?tt_view=teams&id=5>>
```

Klikken op "← Terug naar Bob Smith" gaat één niveau terug. De volgende
pagina draagt nog steeds de resterende keten, dus de eigen pill toont
"← Terug naar Team A" — de keten loopt stap voor stap terug.

De keten is begrensd op **5 stappen**. Een zesde stap laat de diepste
(oudste) inzending vallen, zodat de URL-lengte begrensd blijft.

## Entiteit-bewuste labels

`BackLabelResolver::labelFor($url)` parseert `tt_view` en `id` uit de
terug-URL, zoekt de entiteitsnaam op (speler, team, activiteitstitel,
…) en geeft "Terug naar <naam>" terug. Wanneer de entiteit niet
gevonden kan worden (verwijderd, andere club, ontbrekende id) valt
het terug op het lijstniveau "Terug naar Spelers". Wanneer `tt_view`
helemaal ontbreekt geeft het "Terug naar Dashboard" terug.

## Bedrading

PHP-frontend-views emitteren kruisentiteits-links via:

```php
$url = RecordLink::detailUrlForWithBack( 'players', $player_id );
```

Drop-in vervanging van `RecordLink::detailUrlFor()` — dezelfde URL
plus de `tt_back` query-parameter.

Ruwe URL-bouwers die `RecordLink` niet gebruiken pakken in met
`BackLink::appendTo()`. REST-controllers die detail-URL's emitteren
(bijv. `name_link_html` in de spelerslijst) gebruiken eveneens
`RecordLink::detailUrlForWithBack()`. In een REST-context leest
`BackLink::captureCurrent()` de pagina-URL uit de HTTP-`Referer`-
header (de pagina die de AJAX-call startte) in plaats van
`REQUEST_URI` (dat naar het REST-eindpunt wijst).

## Wat NIET wordt meegenomen

- **Admin-pagina's** (`wp-admin/admin.php?page=…`). Bij klikken op een
  recordnaam in een wp-admin-tabel landt de gebruiker op de
  frontend-detail. Terugnavigatie naar wp-admin laten we aan de
  browser-terugknop.
- **Formulier-save-redirects** (`wp_safe_redirect( $detail_url )` na
  POST). Dat zijn voorwaartse navigaties na een succesvolle opslag;
  het terug-doel hoort de referer van het formulier te zijn, niet de
  redirect-URL.

## Validatie

`tt_back`-waarden worden gevalideerd voor het renderen:

- Alleen same-origin — cross-origin URL's worden afgewezen.
- Alleen parseerbare URL's — kapotte strings worden weggegooid.
- Geëscaped via `esc_url()` bij rendering, zodat de terug-link geen
  HTML of JavaScript via de query-parameter kan injecteren.
