<!-- audience: admin, developer -->

# Navigatie-indeling (de frontend-shell)

TalentTrack rendert zijn frontend in één van twee applicatie-shells. De keuze is
een instelling, geen release: de academie kiest een standaard en iedereen kan
daarna zijn eigen keuze maken.

## De twee indelingen

**Klassiek** — de chrome die TalentTrack altijd heeft gehad. Een merkbalk, een
broodkruimel en de pagina zelf. Er is geen navigatiebalk; je gaat terug naar het
tegeloverzicht om van sectie te wisselen. Dit is de standaard en blijft
onbeperkt beschikbaar.

**App-shell** — navigatie staat altijd in beeld:

| Schermbreedte | Wat je ziet |
| --- | --- |
| 1280px en breder | Een gegroepeerde zijbalk aan de linkerkant |
| 1024px en breder | Dezelfde zijbalk, inklapbaar tot een strook iconen |
| Smaller dan 1024px | Een uitschuifmenu achter de ☰-knop in de balk |
| Smaller dan 768px | Het uitschuifmenu, **plus** een balk onderaan |

Dat is één navigatie in verschillende jassen, geen vier verschillende menu's —
dezelfde ingangen, dezelfde volgorde, dezelfde rechten.

### De onderbalk op telefoons

Vier bestemmingen plus **Meer**, dat het volledige tegeloverzicht opent. De balk
staat in de duimzone en houdt de home-indicator van iOS vrij, zodat wat je langs
de lijn nodig hebt één tik ver is.

Welke vier je krijgt, wordt afgeleid uit je rol: de eerste vier *dagelijkse*
secties waar je bij mag, in de vaste groepsvolgorde. Instellings- en
configuratiesecties komen er nooit in — dat is niet wat iemand met één hand
opzoekt.

Het uitschuifmenu blijft bestaan en bevat nog steeds alles, dus de balk verbergt
niets. Het is een snelkoppeling, geen filter.

## Een indeling kiezen

**Als academiebeheerder** stel je de standaard in onder *Configuratie →
Algemeen → Navigatie-indeling*. Die geldt voor iedereen die niet zelf iets heeft
gekozen.

**Als gebruiker** stel je je eigen indeling in onder *Mijn instellingen →
Indeling*. De opties zijn:

- *Gebruik de standaard van de academie* — volg wat de academie heeft ingesteld,
  ook wanneer de beheerder dat later wijzigt. Dit is de standaard.
- *Klassiek* of *App-shell* — zet die indeling voor jezelf vast, ongeacht de
  standaard van de academie.

Een wijziging is bij de volgende paginalading actief.

### Waarom er twee niveaus zijn

Een indelingswijziging midden in het seizoen is storend als ze wordt opgelegd.
Met twee niveaus kan een academie de standaard omzetten wanneer zij eraan toe
is, kan een trainer tot het einde van het seizoen op Klassiek blijven, en hoeft
iemand die de zijbalk nú wil niet te wachten tot de academie omschakelt.

## Wat er in de navigatie staat

Wat je toch al kon bereiken. De ingangen komen uit hetzelfde register dat het
tegeloverzicht bouwt, dus:

- Je ziet alleen de secties waar jouw rol toegang toe heeft.
- Sectienamen volgen je rol — dezelfde bestemming kan voor een trainer anders
  heten dan voor een ouder, precies zoals op de tegels.
- Groepen staan in de vaste volgorde: Prestatie, Mensen, Planning & tactiek,
  Ontwikkeling, Naslag.

Er is niets via de navigatie bereikbaar dat dat eerder niet was, en er is niets
verborgen dat eerder wel bereikbaar was.

## Zoeken — spring naar alles

Het zoekveld in de bovenbalk, of **⌘K** / **Ctrl+K**, opent een spring-naar-
venster. Het vindt secties, spelers, teams en activiteiten, en opent met de
secties die je kunt bereiken — het werkt dus al als startpunt vóórdat je iets
typt.

Pijltjestoetsen verplaatsen, Enter opent, Escape sluit. De sneltoets is alleen
een versnelling: het zoekveld doet hetzelfde, dus niets hangt ervan af of je hem
kent.

Je ziet alleen records waar je al toegang toe hebt. Zoeken verbreedt niet wat je
mag bereiken; het maakt bereiken alleen sneller.

## Voorbeeld — kijken zonder weg te gaan

Op een laptop opent een link naar een speler, team of activiteit vanuit een
andere pagina nu een **voorbeeldpaneel** naast wat je aan het lezen was, in
plaats van weg te navigeren. Bekijk het detail en kies dan **Openen** of
**Sluiten** — je gaat verder waar je was, met je scrollpositie intact.

Voorbeelden zijn alleen-lezen. Op telefoons en tablets navigeert de link gewoon,
zoals altijd: een paneel dat het grootste deel van een telefoonscherm vult, is
niet meer dan een pagina met extra stappen.

## Aandachtspunten

- Of de zijbalk in- of uitgeklapt staat, onthoudt je browser per apparaat.
- Met JavaScript uit staat de navigatie er nog steeds en is elke ingang een
  gewone link; alleen de uitschuifknop en de inklapknop doen niets.

---

## Voor ontwikkelaars

### Resolutie

`\TT\Shared\Frontend\ShellPreference` is de enige plek waar de shell wordt
bepaald:

```php
ShellPreference::resolve( $user_id );   // 'classic' | 'app'
ShellPreference::isApp( $user_id );     // bool
ShellPreference::rootClass( $user_id ); // 'tt-shell-classic' | 'tt-shell-app'
```

De volgorde is **gebruikersvoorkeur → clubstandaard → `classic`**. Een opgeslagen
waarde die geen bekende shell is, valt door naar de volgende stap in plaats van
niets te renderen, zodat een handmatig aangepaste config nooit een pagina zonder
chrome kan opleveren.

| Niveau | Waar | Waarden |
| --- | --- | --- |
| Clubstandaard | `tt_config`-sleutel `tt_frontend_shell`, club-scoped | `classic`, `app` |
| Gebruikersvoorkeur | user meta `tt_frontend_shell` | `classic`, `app`, of afwezig (= overerven) |

De clubstandaard staat in `tt_config` en niet in `wp_options` (CLAUDE.md §4) —
`wp_options` is globaal voor de WP-installatie en zou over tenants heen lekken.
De gebruikersvoorkeur is een persoonlijke instelling en geen tenant-config, dus
user meta is de juiste plek; `inherit` verwijdert de meta in plaats van een
opgeloste waarde op te slaan, en dát is wat een latere clubwijziging bij de
gebruiker laat aankomen.

### Gebruiken

- **PHP** — roep `ShellPreference::isApp()` aan. Lees de config-sleutel nooit
  rechtstreeks; één resolver is wat de SaaS-migratie tot één vervanging maakt in
  plaats van een zoektocht door de views.
- **CSS** — de opgeloste waarde staat als `.tt-shell-classic` /
  `.tt-shell-app` op de dashboard-wrapper. Scope shell-specifieke regels
  daaronder.
- **JS** — lees `window.TT.shell`. Volgens CLAUDE.md §4 leest de front-end
  configuratie uit `window.TT.*`, nooit uit door PHP gerenderde HTML.
- **REST** — `tt_frontend_shell` is schrijfbaar via
  `POST /talenttrack/v1/config`, net als elke andere toegestane config-sleutel,
  achter dezelfde capability-controle.

### De navigatie

`\TT\Shared\Frontend\Components\FrontendAppNav` rendert haar en leest
`TileRegistry::tilesForUserGrouped()` — dat past al de capability-controle toe,
de per-persona labelmap (inclusief de `__hidden__`-markering), module- en
feature-gating, en de `groupOrder()`-volgorde. **Er is geen tweede
navigatieregister.** Een tegel toevoegen voegt een navigatie-ingang toe.

`FrontendAppNav::groups()` is bewust publiek en losgetrokken van `render()`,
zodat een tweede presentatie dezelfde opgeloste lijst ongewijzigd kan gebruiken.
`FrontendAppBottomBar` ís die tweede presentatie.

### `RecordSpine` — vastgezette record-identiteit

`\TT\Shared\Frontend\Components\RecordSpine` rendert de smalle strook die
bovenaan een recordpagina blijft staan terwijl de volledige header wegscrollt.
Overgenomen door team-, activiteit- en stafdetail; spelerdetail heeft zijn eigen
equivalent uit #2457.

```php
RecordSpine::render( [
    'name'      => 'Ajax JO15-1',   // verplicht; leeg rendert niets
    'meta'      => 'JO15',          // één regel context
    'status'    => 'active',        // bepaalt de ring om de avatar
    'photo_url' => '',              // valt terug op initialen
    'tabs'      => [],              // optioneel; zie hieronder
] );
```

**De component stelt samen, hij beslist niet** (§4). Welke chips een kijker mag
zien, afgeleide status, filteren op rechten — dat blijft in de aanroepende view
en de domeinlaag. Heeft deze klasse ooit een repository nodig, dan klopt het
ontwerp niet.

Onder `classic` rendert hij niets, dus hem overnemen kan die shell niet wijzigen.

**Over tabs.** De sleutel `tabs` wordt ondersteund en is bewust ongebruikt bij de
eerste overnemers. De secties van teamdetail zijn per gebruiker aan en uit te
zetten (`TeamDetailSections::forUser()`); ze naar tabs omzetten zou een functie
overrulen waar mensen al op vertrouwen. Tabs passen bij oppervlakken waar de
secties echt alternatieve blikken op één record zijn — een keuze per oppervlak,
niet iets om vanuit een gedeelde component op te leggen.

### De slots van de onderbalk

`\TT\Shared\Frontend\Components\FrontendAppBottomBar::slots()` levert de vier
bestemmingen, eerst uit configuratie en daarna uit de afgeleide standaard:

1. **Geconfigureerd** — club-scoped `tt_config`-sleutel `tt_shell_mobile_slots`,
   een JSON-object van `persona-sleutel => [ slug, … ]`. Een `*`-sleutel geldt
   voor elke persona zonder eigen ingang. Afwezig of leeg betekent "afleiden", en
   dat is de opleverstand.
2. **Afgeleid** — de eerste vier `kind: 'work'`-tegels uit
   `FrontendAppNav::groups()`, dus al gefilterd op capability, voorzien van
   persona-labels en in `groupOrder()`-volgorde. Setup-tegels vallen af.

Een geconfigureerde slug die niet meer bestaat, voor de persona verborgen is of
de capability-controle niet haalt, wordt **overgeslagen**; de afgeleide standaard
vult het gat. Een verouderde configuratie degradeert dus naar een verstandige
balk in plaats van een kapotte of lege. Er is bewust nog geen beheerderskeuze-UI:
de sleutel is via de config-laag te lezen en te schrijven, en de standaard is
goed genoeg om zonder te leveren.

**De slots bepalen uit echt gebruik.** `tt_usage_events` (migratie 0011) legt al
`event_type = 'frontend_view'` vast met de view-slug in `event_target`, per
`user_id` en `club_id`-scoped, met 90 dagen bewaartermijn — er hoeft niets extra
gemeten te worden. Lees na een paar weken de meest bezochte slugs per persona en
schrijf `tt_shell_mobile_slots`. De viewport wordt niet vastgelegd; blijkt de
persona-splitsing te grof, dan is een viewport-bucket toevoegen aan het event een
aparte kleine wijziging.

De actieve status matcht de eigen view van het slot **én** de bijbehorende
record-views — `players` licht op bij `player` — omdat een balk die leeg wordt
zodra je een record opent, je niet meer oriënteert.

Volgens CLAUDE.md §5b is dit dé ene primaire navigatie en rendert de shell haar
één keer. Een view mag nooit zelf navigatie op moduleniveau emitteren — zie
[`back-navigation.md`](back-navigation.md).

### `classic` echt terugdraaibaar houden

Onder `classic` zijn de shell-wrapper, de navigatie, de stylesheet en het
gedrags-script allemaal afwezig — niet verborgen. Er staat niets in de DOM
waarvan een view afhankelijk kan raken, en dat is wat het terugzetten van de
instelling een echte rollback maakt in plaats van een visuele benadering ervan.
**Schrijf geen view die de DOM van de app-shell nodig heeft.**

### Layout-contract voor views

De app-shell geeft de contentkolom `min-width: 0` binnen een CSS-grid, zodat
brede inhoud zich net zo gedraagt als nu: een tabel die breder is dan zijn
container moet scrollen binnen een eigen `overflow-x: auto`-wrapper in plaats van
de pagina te verbreden. Dat was al de regel; de zijbalk maakt overtredingen
alleen eerder zichtbaar.

Elementen met `position: fixed` — modals, bottom sheets, sleeplagen — zijn niet
geraakt: die resolven tegen de viewport en horen die te blijven vullen, ook over
de zijbalk heen. `position: sticky` binnen de contentkolom werkt eveneens als
voorheen, omdat die kolom een gewoon blok in de scroll-context van de pagina is.
