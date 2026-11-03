<!-- audience: dev -->

# Mobile-first front-end auteuren

Hoe TalentTrack de frontend-pagina's stijlt. Dit is de auteursregel die nieuwe componenten MOETEN volgen; oudere stylesheets dateren van vóór de regel en worden één view per release gemigreerd totdat de oude desktop-first stylesheets weg zijn.

Het principe staat in [`CLAUDE.md`](../../CLAUDE.md) § 2; dit document is de praktische "hoe ziet het er in de codebase uit"-aanvulling.

## De regel

Elke nieuwe component-stylesheet wordt **mobile-first** geschreven:

- De basis-CSS richt zich op de kleinste viewport (~360px breed). Eén kolom, ruime taprichten, geen horizontaal scrollen.
- Grotere viewports worden bereikt via `@media (min-width: …)`-blokken. **Nooit** desktop als basis nemen en met `max-width` naar beneden bijwerken.
- Breakpoints: 480px (grote telefoon), 768px (tablet), 1024px (desktop). Geen nieuwe breakpoints zonder reden.

Waarom mobile-first in plaats van desktop-first:

1. **Defaults wijzen naar de kleinste viewport.** De meeste coaches en ouders raken deze app als eerste op hun telefoon aan. De basisstijl moet daar goed zijn voordat we iets anders doen.
2. **`min-width`-queries stapelen optellend.** Een desktop leest base + 480 + 768 + 1024 in bronvolgorde; elke regel voegt iets toe wat de kleinere viewport niet nodig heeft (meer kolommen, dichtere padding). Een breakpoint verwijderen breekt de kleinere viewport nooit.
3. **`max-width`-queries breken bij compositie.** Twee `max-width`-regels kunnen allebei op een 360px-viewport vuren en elkaar overschrijven afhankelijk van bronvolgorde — precies het soort bug dat je om 23:00 niet wilt debuggen.

## De ondergrens voor tapdoelen

`assets/css/public.css` bevat één `@media (pointer: coarse)`-blok dat het minimum van 48 px uit `CLAUDE.md` § 2 afdwingt. Die stylesheet laadt op elk dashboardscherm, dus een componentstylesheet herhaalt de ondergrens niet — hij erft hem. Omdat het blok op de pointer stuurt en niet op de viewport, krijgt een tablet de tapmaten en houdt een desktop met muis zijn dichtheid, zonder breakpoint.

Knoppen hebben twee ondergrenzen, en beide staan naast het canonieke knopblok in `assets/css/frontend-admin.css`. Ze lezen de tokens `--tt-tap-min` (48 px) en `--tt-tap-min-fine` (2,25 rem, 36 px) uit `tokens.css`:

- **Grove pointer: 48 px.** Elke `.tt-btn`-variant (`-primary`, `-secondary`, `-danger`), binnen of buiten `.tt-dashboard`. Dit is wat een duim nodig heeft en wat de mobiele gate meet, op 360 px met touch-emulatie.
- **Fijne pointer: 36 px.** Het canonieke blok laat een knop nooit onder 2,25 rem zakken, zodat werkbalken en tabelacties op desktop compact blijven zonder onleesbaar klein te worden.

Een componentstylesheet herhaalt geen van beide ondergrenzen op zijn eigen knoppen. `tests/php/ButtonTapFloorTest.php` faalt op een stylesheet die dat wel doet, tenzij de regel daar met reden staat, bijvoorbeeld een knop die in één rij staat met een invoerveld van 48 px.

Wat het `public.css`-blok maatvoert: `.tt-btn-sm`, pagineerlinks, rij-acties, `.tt-record-link`, `summary`, checkboxes en radio's (24 px vakje, 48 px rij vanuit het label) en — sinds #3598 — elke `select`, elke `.tt-input` en elk text-, search-, email-, tel-, number-, date-, time-, url- en password-invoerveld.

Twee regels als je een besturingselement toevoegt:

- **Een checkbox of radio krijgt een `<label>`, en het label is het tapdoel.** Het vakje blijft 24 px; het label draagt de rij van 48 px. Een kale `<input>` in een `<div>` heeft bovendien geen toegankelijke naam — dezelfde fout, twee keer.
- **Versla de ondergrens niet op specificiteit.** `.tt-dashboard .tt-thing { min-height: 32px }` (0,2,0) wint van `.tt-btn { min-height: 48px }` (0,1,0) en zet stilletjes een klein tapdoel terug. Heeft een scherm op desktop echt een compacter element nodig, houd die regel dan als basis en til hem terug naar 48 px in het eigen `@media (pointer: coarse)`-blok van die stylesheet — `frontend-exports.css` en `components/team-planner.css` zijn de uitgewerkte voorbeelden.

## De pilot — `frontend-activities-manage.css`

[`assets/css/frontend-activities-manage.css`](../../assets/css/frontend-activities-manage.css) is de eerste stylesheet die volgens de nieuwe regel is geschreven. Hij bezit de responsive layout van het Activiteiten-scherm:

- De kolomindeling van het activiteitenformulier (`.tt-grid-2`).
- De aanwezigheidstabel (`.tt-attendance-table` + `.tt-attendance-row`).
- De werkbalk / samenvatting / "alles tonen"-link.

Vóór v3.56.0 zat de responsive behandeling van `.tt-attendance-table` in een `@media (max-width: 639px)`-blok in `frontend-admin.css`. De telefoonlezer kreeg de volledige desktop-stijl binnen en overschreef die vervolgens. Het eindbeeld klopte; de bronvolgorde stond verkeerd.

Vanaf v3.56.0:

```css
/* Basis = 360px-viewport — tabel valt uiteen in gestapelde kaarten. */
.tt-dashboard .tt-attendance-table,
.tt-dashboard .tt-attendance-table tbody,
.tt-dashboard .tt-attendance-table tr,
.tt-dashboard .tt-attendance-table td { display: block; width: 100%; }

/* Tablet+ (768px) — terug naar een echte rij-tabel. */
@media (min-width: 768px) {
    .tt-dashboard .tt-attendance-table { display: table; }
    .tt-dashboard .tt-attendance-table thead { display: table-header-group; }
    /* …rij- en cel-modus weer aan. */
}
```

Telefoons zien standaard de gestapelde kaarten; tablets en desktops krijgen via `min-width: 768px` de rij-tabel-behandeling erbij. De desktoplaag verwijderen schaadt het telefoonbeeld niet.

## Hoe je een view migreert

Wanneer je een frontend-view aanpast die nog van een `max-width: …`-blok in de oude stylesheets afhangt:

1. **Maak een per-view-partial** op `assets/css/frontend-<view>.css`. Schrijf hem mobile-first zoals hierboven. Hergebruik de bestaande class-namen — niet hernoemen.
2. **Overschrijf `enqueueAssets()` op de view** om de nieuwe partial te enqueuen na de aanroep van de parent. Gebruik `[ 'tt-frontend-mobile' ]` als dependency zodat de bronvolgorde stabiel blijft.
3. **Verwijder het bijbehorende `max-width: …`-blok** uit `frontend-admin.css` (of waar het ook leeft). Vervang het door één commentaarregel die naar de nieuwe stylesheet verwijst.
4. **Werk de SEQUENCE.md-rij voor #0056 bij** zodat het migratieoverzicht één view minder aangeeft.

De pilot doet dit voor het Activiteiten-scherm. Goals, Players, Trial cases en PDP-cycli zijn voor de hand liggende volgende migraties; elk wordt een eigen kleine PR.

De gedeelde lijsttabel (`FrontendListTable`) is op dezelfde manier gemigreerd. De telefoonrijen zijn de basis van `assets/css/components/list-table.css` en de tabel komt terug bij `min-width: 768px`; `DashboardShortcode` laadt de stylesheet op elke view, omdat elke view een lijst kan tonen. Zie [Mobiele patronen](mobile-patterns.md) voor de `mobile`-kolomrollen.

## Gestapelde tabellen

Een tabel die op een telefoon in kaarten met labels verandert, gebruikt de gedeelde stapeltabel, `assets/css/components/stack-table.css`. Dat is de enige stylesheet die `content: attr(data-label)` mag schrijven; de stack-table-lint in CI keurt elke andere af.

**Markup.** Bouw de tabel met `\TT\Shared\Frontend\Components\StackTable`:

- `StackTable::classes( $to, $cards )` geeft de classes. `$to` is `StackTable::TO_480`, `StackTable::TO_768` of `StackTable::ALWAYS`; `$cards` voegt de kaartopmaak toe.
- `StackTable::attrs( 'table' | 'rowgroup' | 'row' | 'columnheader' | 'rowheader' | 'cell', $label )` zet de expliciete rol op elk onderdeel, en de `data-label` op een cel. Als `display` op tabelelementen verandert, verliezen sommige engines hun impliciete rollen; een renderer zonder de rollen is voor een schermlezer geen tabel meer.
- `rowheader` is een `<th scope="row">` in de body die zijn rij benoemt, zoals een team. Op een telefoon maakt de stapeltabel er een eigen blok bovenaan de kaart van, en de tabel maakt er weer een cel van. De stylesheet van het scherm geeft hem de opmaak van een titel.
- Een cel met meerdere delen (een naam met een badge, een status met een toelichting) zet ze samen in één `StackTable::VALUE`-blok, zodat ze naast het label onder elkaar staan.
- Een tabel die JavaScript opbouwt, schrijft dezelfde attributen als de helper (zie `frontend-list-table.js`).

**Wat de stapeltabel doet.**

| Class | Op een telefoon | Weer een tabel |
| --- | --- | --- |
| `tt-stack-table` | één kaart per rij, één regel met label en waarde per cel | nooit |
| `tt-stack-table--to-480` | hetzelfde | vanaf 480px |
| `tt-stack-table--to-768` | hetzelfde | vanaf 768px |
| `tt-stack-table--cards` | elke rij krijgt papier, rand en ronding | de opmaak verdwijnt met het stapelen |

- **Kopregel:** alleen visueel verborgen, nooit met `display: none`, zodat de kolomkoppen in de toegankelijkheidsboom blijven. Hij verschijnt zolang een bedieningselement erin de toetsenbordfocus heeft.
- **Label:** verborgen voor een schermlezer (`content: attr(data-label) / ""`), omdat de kolomkop de cel al benoemt.
- **Cellen zonder regel:** een lege cel neemt geen regel in, een cel met `data-label=""` is alleen zijn waarde, en een cel over de hele rij is één blok.

Andere breekpunten en container queries zijn er niet.

**Stylesheets van een scherm.** De eigen stylesheet van een scherm gaat over padding, randen en de opmaak van het label, nooit over het stapelen. De selectors van de stapeltabel zijn `> tbody > tr > td`-ketens (0,1,3, en 0,2,3 voor lege, overspannende en ongelabelde cellen). Een regel van een scherm die moet winnen, gebruikt dus dezelfde ketenvorm of staat onder een scope-class zoals `.tt-dashboard`.

**Een renderer die zijn eigen brede opmaak heeft**, gebruikt `StackTable::ALWAYS` en maakt de stapeltabel in zijn eigen brede blok weer ongedaan. Het maandrapport van een team is het voorbeeld. Zijn vellen zijn vanaf 1024px het papier, en 1024 is geen breekpunt van de stapeltabel. Daarom haalt `TeamMonthlyReportDocument::screenCss()` de stapeltabel in zijn eigen `min-width: 1024px`-blok weer van zijn tabellen af. Dat geldt voor de kopregel, de weergave van elk onderdeel (lege, overspannende en ongelabelde cellen inbegrepen), de regeleigenschappen en het label. Dat ongedaan maken geldt alleen voor het scherm: de stylesheet die DomPDF afdrukt, noemt de stapeltabel nergens. De printvariant van het bewijspaneel (`.tt-evidence--print`) maakt hem op dezelfde manier ongedaan voor de afgedrukte pagina en de pdf. Elke regel die dat doet, moet zwaarder wegen dan de zwaarste selectors van de stapeltabel en alleen zetten wat de stapeltabel zette, zodat de eigen regels van de renderer de tabel nog opmaken.

**De oude stapelweergave.** Tot en met 480px stapelt `frontend-mobile.css` nog elke `.tt-table` die niet op de stapeltabel staat, met labels die `tt-table-tools.js` uit de kopregel overneemt. Een tabel op de stapeltabel laat hij met rust. Het is de enige benoemde uitzondering van de stack-table-lint, behouden door het [besluit in #4810](https://github.com/caspernieuwenhuizen/talenttrack/issues/4810#issuecomment-6599880029) tot epic [#4908](https://github.com/caspernieuwenhuizen/talenttrack/issues/4908) die tabellen per module naar de stapeltabel heeft overgezet. Een nieuwe tabel gaat via `StackTable`, nooit op de oude stapelweergave. De lint telt de PHP- en JS-regels van de frontend die een `tt-table`-class zonder de stapeltabel schrijven, tegen de exacte baseline in `.github/stack-table-legacy-baseline.txt`. Een nieuwe tabel op de oude weergave laat de build dus falen, en een tabel die overgaat verlaagt het getal in dezelfde PR.

## Wat is nog desktop-first

`public.css`, `frontend-admin.css`, `frontend-mobile.css` en `admin.css` zijn van vóór de regel. Ze blijven staan voor views die nog niet gemigreerd zijn. De weg naar "geen oude desktop-first stylesheets meer" is één gemigreerde view per release, bijgehouden in [`SEQUENCE.md`](../../SEQUENCE.md) onder #0056.
