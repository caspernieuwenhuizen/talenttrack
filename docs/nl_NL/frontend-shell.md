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

Dat is één navigatie in verschillende jassen, geen vier verschillende menu's —
dezelfde ingangen, dezelfde volgorde, dezelfde rechten.

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
