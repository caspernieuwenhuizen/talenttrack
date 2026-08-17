<!-- audience: dev -->

# Terug-navigatie

URL-gedragen "← Terug naar waar je vandaan kwam" navigatie, geleverd
in v3.110.0.

## Het contract — twee budgetten, apart geteld

Navigatie wordt in **twee aparte budgetten** geteld:

- **Per view** — exact twee affordances, beschreven in deze sectie.
  Ongewijzigd sinds v3.110.0.
- **Globale chrome** — exact één primaire navigatie, gerenderd door
  de applicatie-shell, beschreven onder
  [Globale navigatie is geen view-affordance](#globale-navigatie-is-geen-view-affordance).

Een view bezit het eerste budget. Wisselen tussen modules is geen
taak van een view.

## Per view — twee navigatie-affordances, niet meer en niet minder

**Elke routeerbare frontend-view (alles bereikbaar via
`?tt_view=<slug>`) emitteert exact TWEE navigatie-affordances en
niets anders:**

1. **Broodkruimketen** — de canonieke hiërarchie eindigend bij
   `Dashboard`. Wordt gerenderd via
   `\TT\Shared\Frontend\Components\FrontendBreadcrumbs::fromDashboard()`
   (of `::render([...])` voor ad-hoc-ketens). De eerste kruimel is
   altijd `Dashboard` en linkt terug naar de persona-dashboard-root.
2. **Contextuele "← Terug naar …" pill** — `tt_back`-gedragen,
   automatisch gerenderd door `FrontendBreadcrumbs::render()` BOVEN
   de keten wanneer het bezoek een terug-doel heeft vastgelegd.
   Het label is contextueel via `BackLabelResolver::labelFor()`
   (bijv. `← Terug naar Ajax O17`, `← Terug naar Jan de Vries`,
   `← Terug naar Proefcase: Lucas Smit`). Wanneer er geen terug-doel
   in de URL zit, rendert de pill simpelweg niet — dat is bewust:
   de broodkruimketen is dan het enige pad naar huis en dat is
   genoeg.

**Een derde affordance op view-niveau is nooit toegestaan.** Specifiek:

- ❌ Geen "← Terug naar dashboard"-knop.
- ❌ Geen "← Terug naar <lijst>"-knop (bijv. "Terug naar Spelers",
  "Terug naar Doelen"). De broodkruimketen heeft de ouder-kruimel;
  klik daarop.
- ❌ Geen "← Annuleren"-link die als terug-affordance fungeert.
  Annuleer-knoppen in formulieren zijn prima, maar dat zijn
  formulier-acties, geen navigatie.
- ❌ Geen `FrontendBackButton`-klasse (verwijderd in v3.110.41) of
  enig analoog.
- ❌ Geen per-view terug-link die `tt_back` reset, een doel-URL
  hardcodeert, of de keten + pill anderszins omzeilt.

Als een eigen-label-terug-link nodig voelt, is het juiste antwoord
om te zorgen dat de broodkruimketen de juiste tussenliggende kruimel
heeft. De ouder-kruimel ÍS de terug-naar-lijst-affordance.

### Waarom precies twee

Pilot-operator legde de duplicatie bloot: views die zowel een
expliciete "Terug naar dashboard"-knop als de broodkruimketen
emitteerden, stapelden vier overbodige nav-rijen boven de paginatitel.
Twee affordances zijn voldoende — de pill beantwoordt "waar kwam ik
vandaan?", de broodkruim beantwoordt "waar zit ik in de hiërarchie?".
Een derde toevoegen is ruis.

### Bewust zonder keten (uitzonderingen)

Dit zijn de enige views die zonder broodkruimketen mogen:

- De dashboard-root zelf (`PersonaLandingRenderer`) — dat IS de
  bestemming waar de "Dashboard"-kruimel naar verwijst.
- Pre-login-flows (`AcceptanceView`, login-formulier) — er is nog
  geen ingelogd dashboard om naartoe te ketenen.
- Component-renderers, sub-views die in andere views worden
  samengesteld, interne containers (`FrontendThreadView`,
  `FrontendTeammateView`, `FrontendMyProfileView`,
  `CoachDashboardView`, `PlayerDashboardView`).

Voeg je een nieuwe view toe en is het er geen van deze, dan MOET
hij de keten + pill emitteren.

## Globale navigatie is geen view-affordance

De regel hierboven telt wat een **view** emitteert. Ze telt de
applicatie-shell niet mee.

De shell rendert **één** primaire navigatie met de bestemmingen die
de gebruiker mag bereiken, afgeleid uit `TileRegistry` — slug, groep,
volgorde, icoon, per-persona-labels, capability. De presentatie
verschilt per viewport:

| Viewport | Presentatie |
| --- | --- |
| ≥1280px | Gegroepeerde zijbalk, uitgeklapt |
| ≥1024px | Dezelfde zijbalk, inklapbaar tot een icoonrail |
| <1024px | Uitschuiflade achter een hamburger |
| <768px | Lade plus een duimzone-balk onderaan |

Dit zijn presentaties van **één** affordance met één databron, één
keer gerenderd, door de shell — geen vier affordances, en zeker geen
vier databronnen.

**Een view emitteert nooit navigatie op moduleniveau.** Een view die
zijn eigen lijst met links naar andere modules rendert, is precies de
overtreding waar deze regel op doelt: hij dupliceert de shell, loopt
er vervolgens uit de pas mee, en kan niet consistent op capabilities
worden gefilterd. Dat is een ander probleem dan de per-view
terug-link hieronder, maar het heeft dezelfde oorzaak — navigatie die
per view wordt geschreven in plaats van één keer.

De shell is instelbaar via `tt_frontend_shell` (zie
[`frontend-shell.md`](frontend-shell.md)). Bij de waarde `classic` is
er geen globale navigatie en geldt alleen het per-view-budget.

### Record-gebonden tabs zijn inhoud, geen navigatie

Tabs die **binnen** één record bewegen — Overzicht / Reis /
Evaluaties / Doelen & POP / Speelminuten van een speler — verlaten
het onderwerp van de view niet. Ze zijn dus geen navigatie wég van de
view en tellen niet mee voor het per-view-budget.

Ze mogen uitsluitend worden gerenderd door de gedeelde
spine-component (`\TT\Shared\Frontend\Components\RecordSpine`), die
ook de broodkruimketen en de terug-pill huisvest. Een view die zijn
eigen tabstrip schrijft **is** een overtreding — zo lopen
tab-styling, toetsenbordvolgorde en actief-status uit de pas tussen
oppervlakken.

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
