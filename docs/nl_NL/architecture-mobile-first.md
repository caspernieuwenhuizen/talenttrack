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

## Wat is nog desktop-first

`public.css`, `frontend-admin.css`, `frontend-mobile.css` en `admin.css` zijn van vóór de regel. Ze blijven staan voor views die nog niet gemigreerd zijn. De weg naar "geen oude desktop-first stylesheets meer" is één gemigreerde view per release, bijgehouden in [`SEQUENCE.md`](../../SEQUENCE.md) onder #0056.
